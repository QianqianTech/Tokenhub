<?php

declare(strict_types=1);

namespace TokenHub\Console\Install;

use InvalidArgumentException;

/**
 * Turns the address customers type ("tokenhub.example.com", "10.0.0.8", "https://10.0.0.8", ":80")
 * into the Caddy site address, TLS directive, public URL and cookie policy.
 *
 * deploy/docker/install.sh and install.ps1 implement the same rules; keep them in sync.
 */
final class SiteAddress
{
    private function __construct(
        public readonly string $caddyAddress,
        public readonly string $caddyTls,
        public readonly string $appUrl,
        public readonly bool $https,
        public readonly string $kind,
        public readonly string $caddyGlobal = '',
    ) {
    }

    /**
     * @param string $input  comma separated list; empty means plain HTTP on every address
     * @param int    $httpPort  host port published for HTTP (used to build APP_URL)
     * @param int    $httpsPort host port published for HTTPS
     */
    public static function parse(string $input, int $httpPort = 80, int $httpsPort = 443, string $fallbackHost = 'localhost'): self
    {
        $entries = array_values(array_filter(array_map('trim', explode(',', $input)), static fn (string $v): bool => $v !== ''));
        if ($entries === [] || $entries === [':80'] || $entries === ['http://']) {
            return new self(':80', '', self::url('http', $fallbackHost, $httpPort, 80), false, 'http');
        }

        $addresses = [];
        $first = null;
        $kinds = [];
        foreach ($entries as $entry) {
            $parsed = self::entry($entry);
            $addresses[] = $parsed['address'];
            $kinds[] = $parsed['kind'];
            $first ??= $parsed;
        }

        $https = $first['scheme'] === 'https';
        $needsInternalCa = in_array('ip-https', $kinds, true) && !in_array('domain', $kinds, true);
        $appUrl = self::url($first['scheme'], $first['host'], $https ? $httpsPort : $httpPort, $https ? 443 : 80);

        // Clients do not send SNI for IP addresses, and inside the container Caddy cannot see the host IP,
        // so name the certificate to use explicitly.
        $global = '';
        foreach ($entries as $index => $entry) {
            if ($kinds[$index] === 'ip-https') {
                $global = 'default_sni ' . self::entry($entry)['host'];
                break;
            }
        }

        $addresses = array_values(array_unique($addresses));

        return new self(
            implode(', ', $addresses),
            $needsInternalCa ? 'tls internal' : '',
            $appUrl,
            $https,
            $first['kind'],
            $global,
        );
    }

    /**
     * @return array{address: string, scheme: string, host: string, kind: string}
     */
    private static function entry(string $entry): array
    {
        $scheme = null;
        if (preg_match('#^(https?)://(.+)$#i', $entry, $m) === 1) {
            $scheme = strtolower($m[1]);
            $entry = $m[2];
        }
        $entry = rtrim($entry, '/');
        if (str_contains($entry, '/')) {
            throw new InvalidArgumentException('Site address must not contain a path: ' . $entry);
        }

        $host = $entry;
        if (preg_match('/^\[(.+)\](?::\d+)?$/', $entry, $m) === 1) {
            $host = $m[1];
        } elseif (substr_count($entry, ':') === 1) {
            $host = explode(':', $entry)[0];
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isIp && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host) !== 1) {
            throw new InvalidArgumentException('Invalid domain or IP address: ' . $host);
        }

        if ($isIp) {
            $scheme ??= 'http';
            // Plain HTTP by IP: answer on every address so the site also works through NAT or other names.
            $address = $scheme === 'http' ? ':80' : 'https://' . $entry;

            return ['address' => $address, 'scheme' => $scheme, 'host' => $host, 'kind' => $scheme === 'https' ? 'ip-https' : 'ip-http'];
        }

        $scheme ??= $host === 'localhost' ? 'http' : 'https';
        $address = $scheme === 'http' ? 'http://' . $entry : $entry;

        return ['address' => $address, 'scheme' => $scheme, 'host' => $host, 'kind' => $scheme === 'https' ? 'domain' : 'domain-http'];
    }

    private static function url(string $scheme, string $host, int $port, int $defaultPort): string
    {
        if (str_contains($host, ':') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }

        return $scheme . '://' . $host . ($port === $defaultPort ? '' : ':' . $port);
    }
}
