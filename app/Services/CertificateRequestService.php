<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class CertificateRequestService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function build(array $payload): array
    {
        $requestType = $this->requestType($payload['request_type'] ?? $payload['certificate_request_type'] ?? 'domain');
        $domains = $this->domainList($payload['domain_names'] ?? $payload['request_domain_names'] ?? []);
        $ips = $this->ipList($payload['ip_addresses'] ?? $payload['request_ip_addresses'] ?? []);

        if ($requestType === 'internal_ip') {
            $selectedIp = $this->singleInternalIp($ips);
            if ($selectedIp === null) {
                abort_json('CERTIFICATE_REQUEST_INVALID', 'Select exactly one internal IP address', 422);
            }
            $ips = [$selectedIp];
        }

        if ($requestType === 'domain' && $domains === []) {
            abort_json('CERTIFICATE_REQUEST_INVALID', 'At least one domain is required', 422);
        }
        if (in_array($requestType, ['public_ip', 'internal_ip'], true) && $ips === []) {
            abort_json('CERTIFICATE_REQUEST_INVALID', 'At least one IP address is required', 422);
        }
        if ($requestType === 'public_ip' && array_filter($ips, fn (string $ip): bool => !$this->isPublicIp($ip)) !== []) {
            abort_json('CERTIFICATE_REQUEST_INVALID', 'Public IP certificate requests require publicly routable IP addresses', 422);
        }

        $isIpRequest = in_array($requestType, ['public_ip', 'internal_ip'], true);
        $renewalProvider = match ($requestType) {
            'public_ip' => 'letsencrypt_ip',
            'internal_ip' => 'caddy_internal',
            default => 'caddy_acme',
        };
        $issuerMode = match ($requestType) {
            'public_ip' => 'letsencrypt_shortlived',
            'internal_ip' => 'internal_ca',
            default => 'caddy_acme',
        };
        $settings = [
            'certificate_mode' => $requestType,
            'domain_names' => $requestType === 'domain' ? $domains : [],
            'ip_addresses' => $isIpRequest ? $ips : [],
            'auto_renewal' => true,
            'renewal_provider' => $renewalProvider,
            'renewal_check_interval_hours' => $requestType === 'public_ip' ? 12 : 24,
            'certificate_request' => [
                'status' => 'requested',
                'request_type' => $requestType,
                'targets' => $isIpRequest ? $ips : $domains,
                'requested_at' => gmdate('Y-m-d H:i:s'),
                'issuer_mode' => $issuerMode,
                'renewal_check_interval_hours' => $requestType === 'public_ip' ? 12 : 24,
            ],
        ];

        return [
            'request_type' => $requestType,
            'targets' => $settings['certificate_request']['targets'],
            'settings' => $settings,
            'caddy_tls_fragment' => $this->caddyTlsFragment($requestType, $settings['certificate_request']['targets']),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function request(array $payload): array
    {
        $built = $this->build($payload);
        if ($built['request_type'] === 'public_ip') {
            $readiness = $this->publicIpReadiness();
            if (!$readiness['ready']) {
                abort_json('CERTIFICATE_PUBLIC_IP_NOT_READY', $readiness['message'], 422);
            }
        }
        $fragmentPath = (string) App::config()->get('app.caddy_tls_fragment_path', storage_path('caddy/tls-current.caddy'));
        if (!is_dir(dirname($fragmentPath))) {
            mkdir(dirname($fragmentPath), 0775, true);
        }
        file_put_contents($fragmentPath, $built['caddy_tls_fragment'], LOCK_EX);

        $settingsService = new SystemSettingsService();
        $current = $settingsService->loadSection('tls_certificates');
        $saved = $settingsService->saveSection('tls_certificates', [
            ...$current,
            ...$built['settings'],
        ]);
        (new AuditService())->write('certificate.request', 'system_settings', 'tls_certificates', [
            'request_type' => $built['request_type'],
            'targets' => $built['targets'],
            'fragment_file' => $fragmentPath,
        ]);

        return [
            ...$built,
            'settings' => $saved,
            'fragment_file' => $fragmentPath,
        ];
    }

    /**
     * @return array{ready: bool, message: string}
     */
    public function publicIpReadiness(?string $certbotCommand = null): array
    {
        $command = trim($certbotCommand ?? (string) getenv('TOKENHUB_PUBLIC_IP_CERTBOT_COMMAND'));
        if ($command === '') {
            return [
                'ready' => false,
                'message' => 'Public IP certificates require a configured Certbot command before issuance can start.',
            ];
        }

        return [
            'ready' => true,
            'message' => 'Public IP certificate issuance prerequisites are configured.',
        ];
    }

    private function requestType(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === 'ip') {
            return 'internal_ip';
        }

        return in_array($text, ['domain', 'public_ip', 'internal_ip'], true) ? $text : 'domain';
    }

    /**
     * @return list<string>
     */
    private function domainList(mixed $value): array
    {
        return array_values(array_filter(
            $this->stringList($value),
            static fn (string $item): bool => (bool) preg_match('/^(?=.{1,253}$)([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', $item)
        ));
    }

    /**
     * @return list<string>
     */
    private function ipList(mixed $value): array
    {
        return array_values(array_filter(
            $this->stringList($value),
            static fn (string $item): bool => filter_var($item, FILTER_VALIDATE_IP) !== false
        ));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function singleInternalIp(array $ips): ?string
    {
        return count($ips) === 1 ? $ips[0] : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,;]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        )));
    }

    /**
     * @param list<string> $targets
     */
    private function caddyTlsFragment(string $requestType, array $targets): string
    {
        $lines = [
            '# Generated TokenHub TLS certificate request',
            '# Targets: ' . implode(', ', $targets),
        ];

        if ($requestType === 'internal_ip') {
            $lines[] = '# Caddy internal CA issues and renews certificates for internal IP access.';
            $lines[] = 'tls internal';
        } elseif ($requestType === 'public_ip') {
            $lines[] = '# PUBLIC IP CERTIFICATE: Let\'s Encrypt short-lived IP profile.';
            $lines[] = '# Complete HTTP-01 or TLS-ALPN-01 validation before deploying the issued certificate files.';
        } else {
            $lines[] = '# Caddy ACME issues and renews public domain certificates automatically.';
            $lines[] = '# Ensure TOKENHUB_DOMAIN points to: ' . implode(', ', $targets);
        }

        return implode("\n", $lines) . "\n";
    }
}
