<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

use TokenHub\Services\Gateway\GatewayRequest;

final class NativeMultimodalAdapter implements ProviderAdapterInterface
{
    public function protocol(): string
    {
        return 'native';
    }

    public function buildRequest(GatewayRequest $request, array $route): array
    {
        return ['json' => $request->payload, 'headers' => ['Content-Type' => 'application/json']];
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
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        return [
            'input_tokens' => (int) ($usage['input_tokens'] ?? $request->estimatedUsage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? $request->estimatedUsage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? $request->estimatedUsage['total_tokens'] ?? 0),
            'audio_seconds' => (int) ($usage['audio_seconds'] ?? $request->estimatedUsage['audio_seconds'] ?? 0),
            'tts_chars' => (int) ($usage['tts_chars'] ?? $request->estimatedUsage['tts_chars'] ?? 0),
            'image_count' => (int) ($usage['image_count'] ?? $request->estimatedUsage['image_count'] ?? 0),
            'video_seconds' => (int) ($usage['video_seconds'] ?? $request->estimatedUsage['video_seconds'] ?? 0),
        ];
    }

    public function accumulateStreamUsage(GatewayRequest $request, string $chunk, array $state): array
    {
        return $state;
    }
}
