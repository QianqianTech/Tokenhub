<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

/**
 * Maps a TokenHub canonical endpoint (/v1/chat/completions, /v1/messages, ...)
 * onto a provider base URL.
 *
 * Rules, in order:
 * 1. Explicit per-provider map in default_headers "tokenhub_endpoint_map",
 *    e.g. {"/v1/chat/completions": "/chat/completions"}. A value of null or ""
 *    marks the endpoint unsupported.
 * 2. "tokenhub_unsupported_endpoints": ["/v1/responses"] rejects endpoints.
 * 3. When the base URL already ends in a version segment (/v1, /v4, /v1beta,
 *    /api/v3, /api/paas/v4, /compatible-mode/v1) or in "/{version}/openai"
 *    (Gemini OpenAI compatibility), the canonical "/v1" prefix is dropped so
 *    the version is never duplicated.
 * 4. Otherwise the canonical path is appended unchanged
 *    (https://api.openai.com -> https://api.openai.com/v1/chat/completions).
 */
final class ProviderEndpointResolver
{
    /**
     * @param array<string, mixed> $controls decoded provider default_headers
     * @return array{ok: bool, url?: string, path?: string, reason?: string}
     */
    public function resolve(string $baseUrl, string $endpoint, array $controls = []): array
    {
        $endpoint = '/' . ltrim($endpoint, '/');
        $canonical = (string) (parse_url($endpoint, PHP_URL_PATH) ?: $endpoint);
        $query = (string) (parse_url($endpoint, PHP_URL_QUERY) ?? '');

        $map = $controls['tokenhub_endpoint_map'] ?? null;
        if (is_string($map)) {
            $map = json_decode($map, true);
        }
        if (is_array($map) && array_key_exists($canonical, $map)) {
            $mapped = $map[$canonical];
            if (!is_string($mapped) || trim($mapped) === '') {
                return ['ok' => false, 'reason' => 'endpoint_not_supported_by_provider'];
            }
            if (preg_match('#^https?://#i', $mapped) === 1) {
                return ['ok' => true, 'url' => $mapped . ($query !== '' ? '?' . $query : ''), 'path' => (string) parse_url($mapped, PHP_URL_PATH)];
            }

            return $this->join($baseUrl, '/' . ltrim($mapped, '/'), $query);
        }

        $unsupported = $controls['tokenhub_unsupported_endpoints'] ?? null;
        if (is_string($unsupported)) {
            $unsupported = json_decode($unsupported, true);
        }
        if (is_array($unsupported) && in_array($canonical, array_map('strval', $unsupported), true)) {
            return ['ok' => false, 'reason' => 'endpoint_not_supported_by_provider'];
        }

        $path = $endpoint;
        if (str_starts_with($canonical, '/v1/') && $this->baseCarriesVersion($baseUrl)) {
            $path = substr($endpoint, 3);
        }

        return ['ok' => true, 'url' => rtrim($baseUrl, '/') . $path, 'path' => (string) (parse_url($path, PHP_URL_PATH) ?: $path)];
    }

    public function baseCarriesVersion(string $baseUrl): bool
    {
        $path = trim((string) (parse_url(rtrim($baseUrl, '/'), PHP_URL_PATH) ?? ''), '/');
        if ($path === '') {
            return false;
        }
        $segments = explode('/', strtolower($path));
        $last = end($segments);
        if ($this->isVersionSegment($last)) {
            return true;
        }
        $previous = $segments[count($segments) - 2] ?? '';

        return $last === 'openai' && $this->isVersionSegment($previous);
    }

    private function isVersionSegment(string $segment): bool
    {
        return preg_match('/^v\d+(?:(?:alpha|beta)\d*)?$/', $segment) === 1;
    }

    /**
     * @return array{ok: bool, url: string, path: string}
     */
    private function join(string $baseUrl, string $path, string $query): array
    {
        return ['ok' => true, 'url' => rtrim($baseUrl, '/') . $path . ($query !== '' ? '?' . $query : ''), 'path' => $path];
    }
}
