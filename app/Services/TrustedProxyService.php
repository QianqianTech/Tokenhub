<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Request;

final class TrustedProxyService
{
    /**
     * @param list<string>|null $trustedCidrs
     */
    public function __construct(private readonly ?array $trustedCidrs = null)
    {
    }

    public function clientIp(Request $request): string
    {
        $peer = trim((string) ($request->server['REMOTE_ADDR'] ?? 'unknown'));
        if (!$this->isTrusted($peer)) {
            return $peer;
        }

        // Walk X-Forwarded-For from the right: every trusted proxy appends the
        // address it received the request from, so the first hop that is not
        // one of our proxies is the real client. The left-most entries are
        // whatever the client chose to send and must never be believed.
        $hops = [];
        foreach (explode(',', (string) $request->header('x-forwarded-for', '')) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $hops[] = $candidate;
            }
        }
        foreach (array_reverse($hops) as $hop) {
            if (!$this->isTrusted($hop)) {
                return $hop;
            }
        }

        // Every hop is one of our proxies (internal traffic): the furthest
        // one is the best available origin.
        return $hops[0] ?? $peer;
    }

    private function isTrusted(string $ip): bool
    {
        foreach ($this->trustedCidrs() as $cidr) {
            if ($this->cidrMatches($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function trustedCidrs(): array
    {
        if ($this->trustedCidrs !== null) {
            return $this->trustedCidrs;
        }

        $configured = App::config()->get('app.trusted_proxy_cidrs', []);
        return is_array($configured) ? array_values(array_filter(array_map('strval', $configured))) : [];
    }

    private function cidrMatches(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = array_pad(explode('/', trim($cidr), 2), 2, null);
        if ($prefix === null) {
            return hash_equals($subnet, $ip);
        }

        $packedIp = inet_pton($ip);
        $packedSubnet = inet_pton($subnet);
        if ($packedIp === false || $packedSubnet === false || strlen($packedIp) !== strlen($packedSubnet)) {
            return false;
        }

        $bits = (int) $prefix;
        $maxBits = strlen($packedIp) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;
        if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedSubnet, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedSubnet[$fullBytes]) & $mask);
    }
}
