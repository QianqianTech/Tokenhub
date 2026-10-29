<?php

declare(strict_types=1);

namespace TokenHub\Services;

/**
 * POST to a URL that was just checked by OutboundUrlPolicy, connecting to the
 * address the check resolved (CURLOPT_RESOLVE) instead of resolving the host
 * again. Closes the DNS-rebinding gap between "check" and "connect": a host
 * that resolves to a public address during the check and to an internal one
 * a moment later still reaches only the checked address. TLS keeps verifying
 * the certificate against the real host name. Redirects are never followed.
 */
final class PinnedHttpPost
{
    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, error: string}
     */
    public static function send(string $url, string $address, string $payload, array $headers, int $timeoutSeconds = 10): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['status' => 0, 'body' => '', 'error' => 'invalid_url'];
        }
        if (!function_exists('curl_init')) {
            // Without cURL the address cannot be pinned; refuse rather than re-resolve.
            return ['status' => 0, 'body' => '', 'error' => 'curl_unavailable'];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = trim((string) $parts['host'], '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $body = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) < 4096) {
                    $body .= substr($chunk, 0, 4096 - strlen($body));
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? curl_error($handle) : '';
        curl_close($handle);

        return ['status' => $ok === false ? 0 : $status, 'body' => $body, 'error' => $error];
    }
}
