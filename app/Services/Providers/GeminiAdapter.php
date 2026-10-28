<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

use TokenHub\Services\Gateway\GatewayRequest;

/**
 * Gemini API native protocol (MM-022): generateContent / streamGenerateContent
 * proxied unchanged to https://generativelanguage.googleapis.com (or any base
 * URL of a "gemini" protocol channel); x-goog-api-key carries the channel key.
 */
final class GeminiAdapter implements ProviderAdapterInterface
{
    public function protocol(): string
    {
        return 'gemini';
    }

    public function buildRequest(GatewayRequest $request, array $route): array
    {
        $payload = $request->payload;
        unset($payload['model'], $payload['rag']);
        $model = (string) (($route['external_name'] ?? '') !== '' ? $route['external_name'] : $request->model);
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;
        $version = str_contains($request->endpoint, '/v1/models/') ? 'v1' : 'v1beta';
        $action = $request->stream ? ':streamGenerateContent?alt=sse' : ':generateContent';
        $parts = parse_url(rtrim((string) ($route['base_url'] ?? ''), '/'));
        $root = (string) ($parts['scheme'] ?? 'https') . '://' . (string) ($parts['host'] ?? 'generativelanguage.googleapis.com') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return [
            'endpoint' => $root . '/' . $version . '/models/' . rawurlencode($model) . $action,
            'json' => $payload === [] ? new \stdClass() : $payload,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => $request->stream ? 'text/event-stream' : 'application/json',
            ],
        ];
    }

    public function normalizeResponse(GatewayRequest $request, array $route, array $providerResponse): array
    {
        return $providerResponse;
    }

    public function normalizeError(int $status, string $body): array
    {
        return ['code' => $status >= 500 ? 'PROVIDER_ERROR' : 'PROVIDER_REJECTED', 'message' => 'Provider request failed'];
    }

    public function usage(GatewayRequest $request, array $response): array
    {
        $meta = is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];
        $input = (int) ($meta['promptTokenCount'] ?? $request->estimatedUsage['input_tokens'] ?? 0);
        $output = (int) ($meta['candidatesTokenCount'] ?? 0) + (int) ($meta['thoughtsTokenCount'] ?? 0);

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => (int) ($meta['totalTokenCount'] ?? ($input + $output)),
            'cache_read_tokens' => (int) ($meta['cachedContentTokenCount'] ?? 0),
            'cache_write_tokens' => 0,
            'image_count' => (int) ($request->estimatedUsage['image_count'] ?? 0),
        ];
    }

    public function accumulateStreamUsage(GatewayRequest $request, string $chunk, array $state): array
    {
        return $state;
    }
}
