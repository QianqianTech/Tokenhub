<?php

declare(strict_types=1);

namespace TokenHub\Services;

/**
 * Bounded HTTP GET for URLs that came from an upstream response.
 *
 * Every redirect hop is re-validated against OutboundUrlPolicy and the
 * connection is pinned to the address that check resolved (no second DNS
 * lookup), the body is abandoned once it exceeds the byte limit, and the
 * whole transfer is time bounded.
 */
final class SafeRemoteFetcher
{
    /** @var null|callable(string, array<string, mixed>): array{status: int, headers: list<string>, body: string|false, truncated: bool} */
    private $transport;

    public function __construct(
        private readonly ?OutboundUrlPolicy $policy = null,
        ?callable $transport = null,
        private readonly int $maxBytes = 0,
        private readonly float $timeoutSeconds = 0.0,
        private readonly int $maxRedirects = 3,
    ) {
        $this->transport = $transport;
    }

    /**
     * @return array{ok: bool, status?: int, body?: string, content_type?: string|null, error?: string}
     */
    public function get(string $url, string $purpose = OutboundUrlPolicy::PURPOSE_REMOTE_ASSET): array
    {
        $policy = $this->policy ?? new OutboundUrlPolicy();
        $maxBytes = $this->maxBytes > 0 ? $this->maxBytes : (int) env_value('OUTBOUND_DOWNLOAD_MAX_BYTES', 104857600);
        $timeout = $this->timeoutSeconds > 0 ? $this->timeoutSeconds : (float) env_value('OUTBOUND_DOWNLOAD_TIMEOUT_SECONDS', 30);

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            $check = $policy->check($url, $purpose);
            if (!$check['ok']) {
                return ['ok' => false, 'error' => 'OUTBOUND_BLOCKED:' . ($check['reason'] ?? 'denied')];
            }

            // Connect to the address that was just checked, not a fresh DNS answer (SEC-108).
            $address = (string) (($check['addresses'] ?? [])[0] ?? '');
            $result = ($this->transport ?? [$this, 'transport'])($url, ['max_bytes' => $maxBytes, 'timeout' => $timeout, 'address' => $address]);
            if ($result['truncated']) {
                return ['ok' => false, 'error' => 'OUTBOUND_TOO_LARGE'];
            }
            $status = $result['status'];
            if ($status >= 300 && $status < 400) {
                $location = $this->header($result['headers'], 'location');
                if ($location === null) {
                    return ['ok' => false, 'status' => $status, 'error' => 'OUTBOUND_REDIRECT_WITHOUT_LOCATION'];
                }
                $url = $this->resolveLocation($url, $location);
                continue;
            }
            if ($status === 0 || $result['body'] === false) {
                return ['ok' => false, 'status' => $status, 'error' => 'OUTBOUND_UNAVAILABLE'];
            }
            if ($status >= 400) {
                return ['ok' => false, 'status' => $status, 'error' => 'OUTBOUND_HTTP_ERROR'];
            }

            return ['ok' => true, 'status' => $status, 'body' => $result['body'], 'content_type' => $this->header($result['headers'], 'content-type')];
        }

        return ['ok' => false, 'error' => 'OUTBOUND_TOO_MANY_REDIRECTS'];
    }

    /**
     * GET over cURL, connected to the address OutboundUrlPolicy just checked
     * (CURLOPT_RESOLVE), so a DNS answer that changes between the check and
     * the connect (DNS rebinding) cannot reach an internal service such as
     * Qdrant (SEC-108). TLS still verifies the certificate against the real
     * host name; redirects are never followed here (get() re-checks each hop);
     * proxies from the environment are ignored, as the pin would not apply.
     *
     * @param array{max_bytes: int, timeout: float, address?: string|null} $options
     * @return array{status: int, headers: list<string>, body: string|false, truncated: bool}
     */
    private function transport(string $url, array $options): array
    {
        $failed = ['status' => 0, 'headers' => [], 'body' => false, 'truncated' => false];
        $parts = parse_url($url);
        $address = (string) ($options['address'] ?? '');
        if (!is_array($parts) || empty($parts['host']) || $address === '' || !function_exists('curl_init')) {
            // Without cURL or a checked address the connection cannot be pinned; refuse rather than re-resolve.
            return $failed;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = trim((string) $parts['host'], '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $resolve = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? []
            : [$host . ':' . $port . ':' . (str_contains($address, ':') ? '[' . $address . ']' : $address)];

        $headers = [];
        $body = '';
        $truncated = false;
        $maxBytes = $options['max_bytes'];
        $timeout = max(1, (int) ceil($options['timeout']));
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $trimmed = rtrim($line, "\r\n");
                if ($trimmed !== '') {
                    $headers[] = $trimmed;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    $truncated = true;
                    return 0; // aborts the transfer
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($truncated) {
            return ['status' => $status, 'headers' => $headers, 'body' => false, 'truncated' => true];
        }
        if ($ok === false) {
            return ['status' => 0, 'headers' => $headers, 'body' => false, 'truncated' => false];
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => false];
    }

    /**
     * @param list<string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $value = null;
        foreach ($headers as $line) {
            if (stripos($line, $name . ':') === 0) {
                $value = trim(substr($line, strlen($name) + 1));
            }
        }

        return $value;
    }

    private function resolveLocation(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($current);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname((string) ($parts['path'] ?? '/')), '/');

        return $origin . $dir . '/' . $location;
    }
}
