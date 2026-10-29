<?php

declare(strict_types=1);

namespace TokenHub\Services\Mcp\OAuth;

use TokenHub\Services\OutboundUrlPolicy;

/**
 * Fetches an OAuth Client ID Metadata Document (the client_id is an https URL
 * that serves the client's metadata, used by Claude Code and others).
 *
 * SSRF guards: https only, the host must resolve to public addresses (the
 * outbound policy), the connection is pinned to the address that was checked
 * (no second DNS lookup, so DNS rebinding cannot redirect it), redirects are
 * not followed, and the body is capped.
 */
final class ClientMetadataFetcher
{
    public const MAX_BYTES = 65536;

    /** @var (callable(string, string, int): array{status: int, body: string|false, content_type: ?string})|null */
    private $transport;

    public function __construct(
        private readonly ?OutboundUrlPolicy $policy = null,
        ?callable $transport = null,
        private readonly float $timeoutSeconds = 5.0,
    ) {
        $this->transport = $transport;
    }

    /**
     * @return array{ok: bool, document?: array<string, mixed>, error?: string}
     */
    public function fetch(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['fragment'])) {
            return ['ok' => false, 'error' => 'client_id must be an https URL without a fragment'];
        }
        if (($parts['path'] ?? '') === '' || ($parts['path'] ?? '') === '/') {
            return ['ok' => false, 'error' => 'client_id URL must have a path'];
        }
        $check = ($this->policy ?? new OutboundUrlPolicy())->check($url, OutboundUrlPolicy::PURPOSE_WEBHOOK);
        if (!($check['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'client metadata host is not allowed (' . ($check['reason'] ?? 'denied') . ')'];
        }
        $address = (string) (($check['addresses'] ?? [])[0] ?? '');
        if ($address === '') {
            return ['ok' => false, 'error' => 'client metadata host did not resolve'];
        }

        $result = ($this->transport ?? [$this, 'transport'])($url, $address, self::MAX_BYTES);
        if ($result['status'] !== 200 || !is_string($result['body'])) {
            return ['ok' => false, 'error' => 'client metadata document returned HTTP ' . $result['status']];
        }
        if (strlen($result['body']) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'client metadata document is too large'];
        }
        $document = json_decode($result['body'], true);
        if (!is_array($document) || array_is_list($document)) {
            return ['ok' => false, 'error' => 'client metadata document is not a JSON object'];
        }

        return ['ok' => true, 'document' => $document];
    }

    /**
     * @return array{status: int, body: string|false, content_type: ?string}
     */
    private function transport(string $url, string $address, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => false, 'content_type' => null];
        }
        $parts = parse_url($url);
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 443);
        $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $body = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);

        return ['status' => $ok === false && strlen($body) <= $maxBytes ? 0 : $status, 'body' => $body, 'content_type' => is_string($contentType) ? $contentType : null];
    }
}
