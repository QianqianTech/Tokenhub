<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TokenHub\Support\App;

final class CertificateStatusService
{
    /**
     * @param list<string>|null $paths
     */
    public function __construct(private readonly ?array $paths = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $thresholdDays = 14): array
    {
        $certificates = $this->certificates($thresholdDays);

        return [
            'checked_at' => gmdate('Y-m-d H:i:s'),
            'summary' => [
                'total' => count($certificates),
                'domain_certificates' => count(array_filter($certificates, static fn (array $item): bool => $item['certificate_type'] === 'domain')),
                'ip_certificates' => count(array_filter($certificates, static fn (array $item): bool => $item['certificate_type'] === 'ip')),
                'expired' => count(array_filter($certificates, static fn (array $item): bool => $item['status'] === 'expired')),
                'expiring_soon' => count(array_filter($certificates, static fn (array $item): bool => $item['status'] === 'expiring_soon')),
                'auto_renewal_managed' => count(array_filter($certificates, static fn (array $item): bool => $item['auto_renewal'] === true)),
            ],
            'certificates' => $certificates,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function certificates(int $thresholdDays = 14): array
    {
        $certificates = [];
        foreach ($this->certificateFiles() as $file) {
            $certificate = $this->parseFile($file, $thresholdDays);
            if ($certificate !== null) {
                $certificates[] = $certificate;
            }
        }

        usort($certificates, static fn (array $a, array $b): int => strcmp((string) $a['expires_at'], (string) $b['expires_at']));

        return $certificates;
    }

    /**
     * @return list<string>
     */
    private function defaultPaths(): array
    {
        $configured = env('TOKENHUB_CERTIFICATE_PATHS', '');
        $paths = is_string($configured) && trim($configured) !== ''
            ? preg_split('/[\r\n,;]+/', $configured) ?: []
            : [];

        try {
            $caddyData = App::config()->get('paths.caddy_data');
            if (is_string($caddyData) && $caddyData !== '') {
                $paths[] = $caddyData . DIRECTORY_SEPARATOR . 'caddy' . DIRECTORY_SEPARATOR . 'certificates';
                $paths[] = $caddyData . DIRECTORY_SEPARATOR . 'certificates';
            }
        } catch (\Throwable) {
        }

        foreach ([
            '/data/caddy/certificates',
            '/var/lib/caddy/certificates',
            base_path('storage/caddy/certificates'),
            base_path('storage/certs'),
        ] as $path) {
            $paths[] = $path;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $path): string => rtrim(trim((string) $path), "\\/"),
            $paths
        ))));
    }

    /**
     * @return list<string>
     */
    private function certificateFiles(): array
    {
        $paths = $this->paths ?? $this->defaultPaths();
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path) && $this->isCertificateFile($path)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $this->isCertificateFile($file->getPathname())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    private function isCertificateFile(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['crt', 'pem', 'cer'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseFile(string $path, int $thresholdDays): ?array
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $match)) {
            return null;
        }

        $parsed = openssl_x509_parse($match[0], false);
        if (!is_array($parsed)) {
            return null;
        }

        $domains = $this->subjectAlternativeNames((string) ($parsed['extensions']['subjectAltName'] ?? ''), 'DNS');
        $ipAddresses = $this->subjectAlternativeNames((string) ($parsed['extensions']['subjectAltName'] ?? ''), 'IP');
        $subjectCn = trim((string) ($parsed['subject']['CN'] ?? ''));
        if ($domains === [] && $subjectCn !== '' && filter_var($subjectCn, FILTER_VALIDATE_IP) === false) {
            $domains[] = $subjectCn;
        }
        if ($ipAddresses === [] && $subjectCn !== '' && filter_var($subjectCn, FILTER_VALIDATE_IP) !== false) {
            $ipAddresses[] = $subjectCn;
        }

        $expiresAt = (int) ($parsed['validTo_time_t'] ?? 0);
        $daysRemaining = $expiresAt > 0 ? (int) floor(($expiresAt - time()) / 86400) : null;

        $sourceType = $this->sourceType($path);

        return [
            'name' => basename($path),
            'source_path' => $path,
            'source_type' => $sourceType,
            'certificate_type' => $ipAddresses !== [] ? 'ip' : 'domain',
            'domains' => array_values(array_unique($domains)),
            'ip_addresses' => array_values(array_unique($ipAddresses)),
            'subject' => $this->nameParts($parsed['subject'] ?? []),
            'issuer' => implode(', ', $this->nameParts($parsed['issuer'] ?? [])),
            'serial_number' => strtoupper((string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? '')),
            'valid_from' => $this->dateTime((int) ($parsed['validFrom_time_t'] ?? 0)),
            'expires_at' => $this->dateTime($expiresAt),
            'days_remaining' => $daysRemaining,
            'status' => $this->statusName($daysRemaining, $thresholdDays),
            'auto_renewal' => $sourceType === 'caddy',
            'renewal_source' => $sourceType === 'caddy' ? 'caddy' : 'manual_file',
            'fingerprint_sha256' => openssl_x509_fingerprint($match[0], 'sha256') ?: '',
        ];
    }

    /**
     * @return list<string>
     */
    private function subjectAlternativeNames(string $text, string $type): array
    {
        if ($text === '') {
            return [];
        }

        $names = [];
        foreach (preg_split('/,\s*/', $text) ?: [] as $part) {
            if ($type === 'DNS' && preg_match('/^DNS:(.+)$/i', $part, $match)) {
                $names[] = trim($match[1]);
            }
            if ($type === 'IP' && preg_match('/^(?:IP Address|IP):(.+)$/i', $part, $match)) {
                $names[] = trim($match[1]);
            }
        }

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }

    /**
     * @param mixed $parts
     * @return list<string>
     */
    private function nameParts(mixed $parts): array
    {
        if (!is_array($parts)) {
            return [];
        }

        $out = [];
        foreach ($parts as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $out[] = $key . '=' . (string) $value;
            }
        }

        return $out;
    }

    private function dateTime(int $timestamp): ?string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    private function statusName(?int $daysRemaining, int $thresholdDays): string
    {
        if ($daysRemaining === null || $daysRemaining < 0) {
            return 'expired';
        }
        if ($daysRemaining <= $thresholdDays) {
            return 'expiring_soon';
        }

        return 'valid';
    }

    private function sourceType(string $path): string
    {
        return str_contains(str_replace('\\', '/', strtolower($path)), '/caddy/') ? 'caddy' : 'file';
    }
}
