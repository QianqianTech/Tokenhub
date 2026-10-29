<?php

declare(strict_types=1);

namespace TokenHub\Services;

/**
 * Single egress policy for provider calls, remote asset downloads and webhooks.
 *
 * Purposes
 * - provider      admin-configured upstream base URLs. Public hosts are allowed.
 *                 Loopback/private/CGNAT targets require an explicit allow rule
 *                 (provider default_headers "tokenhub_allow_private_network": true,
 *                 or OUTBOUND_PRIVATE_ALLOWLIST). Link-local and cloud metadata
 *                 addresses are always refused.
 * - remote_asset  URLs returned by an upstream (generated images, videos, RAG
 *                 sources). Only public targets unless OUTBOUND_PRIVATE_ALLOWLIST
 *                 matches; never metadata.
 * - webhook       customer webhook targets. HTTPS and public targets only.
 *
 * OUTBOUND_PRIVATE_ALLOWLIST is a comma separated list of host names
 * ("vllm.internal"), host suffixes (".corp.example") or CIDRs ("10.20.0.0/16").
 */
final class OutboundUrlPolicy
{
    public const PURPOSE_PROVIDER = 'provider';
    public const PURPOSE_REMOTE_ASSET = 'remote_asset';
    public const PURPOSE_WEBHOOK = 'webhook';

    /** @var callable(string): list<string> */
    private $resolver;

    /** @var list<string> */
    private array $allowlist;

    /**
     * @param null|callable(string): list<string> $resolver
     * @param list<string>|null $allowlist
     */
    public function __construct(?callable $resolver = null, ?array $allowlist = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return [$host];
            }
            $addresses = gethostbynamel($host) ?: [];
            if (function_exists('dns_get_record')) {
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                    if (!empty($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    }
                }
            }

            return array_values(array_unique($addresses));
        };
        $this->allowlist = $allowlist ?? array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env_value('OUTBOUND_PRIVATE_ALLOWLIST', ''))
        )));
    }

    /**
     * @param array{allow_private?: bool} $options
     * @return array{ok: bool, reason?: string, host?: string, addresses?: list<string>}
     */
    public function check(string $url, string $purpose, array $options = []): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $allowedSchemes = $purpose === self::PURPOSE_WEBHOOK ? ['https'] : ['http', 'https'];
        if (!in_array($scheme, $allowedSchemes, true)) {
            return ['ok' => false, 'reason' => 'scheme_not_allowed'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'reason' => 'credentials_in_url'];
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        $addresses = ($this->resolver)($host);
        if ($addresses === []) {
            return ['ok' => false, 'reason' => 'unresolvable_host', 'host' => $host];
        }

        $privateAllowed = $purpose !== self::PURPOSE_WEBHOOK
            && (($options['allow_private'] ?? false) === true || $this->hostAllowlisted($host));

        foreach ($addresses as $address) {
            $category = $this->category($address);
            if ($category === 'metadata' || $category === 'link_local' || $category === 'unspecified') {
                return ['ok' => false, 'reason' => 'forbidden_address', 'host' => $host, 'addresses' => $addresses];
            }
            if ($category === 'public') {
                continue;
            }
            if ($privateAllowed || $this->addressAllowlisted($address)) {
                continue;
            }

            return ['ok' => false, 'reason' => 'private_address_not_allowed', 'host' => $host, 'addresses' => $addresses];
        }

        return ['ok' => true, 'host' => $host, 'addresses' => $addresses];
    }

    public function category(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return 'invalid';
        }
        // IPv6 forms that carry an IPv4 address (mapped ::ffff:a.b.c.d,
        // compatible ::a.b.c.d, NAT64 64:ff9b::/96, 6to4 2002::/16) reach that
        // IPv4 host: judge them by the embedded address, so e.g.
        // ::ffff:169.254.169.254 is still the metadata service.
        $embedded = self::embeddedIpv4($address);
        if ($embedded !== null) {
            return $this->category($embedded);
        }
        if (in_array($address, ['0.0.0.0', '::'], true)) {
            return 'unspecified';
        }
        if (in_array($address, ['169.254.169.254', 'fd00:ec2::254', '100.100.100.200'], true)) {
            return 'metadata';
        }
        if ($this->inCidr($address, '169.254.0.0/16') || $this->inCidr($address, 'fe80::/10')) {
            return 'link_local';
        }
        if ($this->inCidr($address, '127.0.0.0/8') || $address === '::1') {
            return 'loopback';
        }
        if ($this->inCidr($address, '100.64.0.0/10')) {
            return 'private';
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false || $this->inCidr($address, 'fc00::/7')) {
            return 'private';
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'reserved';
        }

        return 'public';
    }

    private function hostAllowlisted(string $host): bool
    {
        foreach ($this->allowlist as $entry) {
            $entry = strtolower($entry);
            if ($entry === '' || str_contains($entry, '/')) {
                continue;
            }
            if ($entry === $host || (str_starts_with($entry, '.') && str_ends_with($host, $entry))) {
                return true;
            }
        }

        return false;
    }

    private function addressAllowlisted(string $address): bool
    {
        foreach ($this->allowlist as $entry) {
            if (str_contains($entry, '/') && $this->inCidr($address, $entry)) {
                return true;
            }
            if ($entry === $address) {
                return true;
            }
        }

        return false;
    }

    public function inCidr(string $address, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $address === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $addressBin = @inet_pton($address);
        $subnetBin = @inet_pton($subnet);
        if ($addressBin === false || $subnetBin === false || strlen($addressBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($addressBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($addressBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    public static function embeddedIpv4(string $address): ?string
    {
        if (!str_contains($address, ':')) {
            return null;
        }
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }
        $prefix12 = substr($packed, 0, 12);
        if ($prefix12 === str_repeat("\0", 10) . "\xff\xff" || ($prefix12 === str_repeat("\0", 12) && substr($packed, 12) !== "\0\0\0\0" && substr($packed, 12) !== "\0\0\0\1")) {
            return inet_ntop(substr($packed, 12)) ?: null;
        }
        if ($prefix12 === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return inet_ntop(substr($packed, 12)) ?: null;
        }
        if (substr($packed, 0, 2) === "\x20\x02") {
            return inet_ntop(substr($packed, 2, 4)) ?: null;
        }

        return null;
    }
}
