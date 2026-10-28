<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

use TokenHub\Services\Gateway\GatewayRequest;

final class AnthropicAdapter implements ProviderAdapterInterface
{
    public function protocol(): string
    {
        return 'anthropic';
    }

    public function buildRequest(GatewayRequest $request, array $route): array
    {
        $payload = $request->payload;
        if (($route['external_name'] ?? '') !== '') {
            $payload['model'] = $route['external_name'];
        }

        return [
            'json' => $payload,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => $request->stream ? 'text/event-stream' : 'application/json',
                'anthropic-version' => '2023-06-01',
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
        $input = (int) ($response['usage']['input_tokens'] ?? $response['input_tokens'] ?? $request->estimatedUsage['input_tokens'] ?? 0);
        $output = (int) ($response['usage']['output_tokens'] ?? $response['output_tokens'] ?? 0);
        $usage = [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'cache_read_tokens' => (int) ($response['usage']['cache_read_input_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($response['usage']['cache_creation_input_tokens'] ?? 0),
        ];

        foreach (['audio_seconds', 'tts_chars', 'image_count', 'video_seconds'] as $metric) {
            $usage[$metric] = (int) ($response['usage'][$metric] ?? $request->estimatedUsage[$metric] ?? 0);
        }

        return $usage;
    }

    public function accumulateStreamUsage(GatewayRequest $request, string $chunk, array $state): array
    {
        $state['output_chars'] = (int) ($state['output_chars'] ?? 0);
        foreach (explode("\n", $chunk) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $json = json_decode(trim(substr($line, 5)), true);
            if (!is_array($json)) {
                continue;
            }
            if (isset($json['usage']) && is_array($json['usage'])) {
                $state['usage'] = $this->usage($request, $json);
            }
            foreach (($json['delta'] ?? []) as $value) {
                if (is_string($value)) {
                    $state['output_chars'] += strlen($value);
                }
            }
        }
        return $state;
    }
}
