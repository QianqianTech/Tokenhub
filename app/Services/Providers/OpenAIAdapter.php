<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

use TokenHub\Services\Gateway\GatewayRequest;

final class OpenAIAdapter implements ProviderAdapterInterface
{
    public function protocol(): string
    {
        return 'openai';
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
        $input = (int) ($response['usage']['prompt_tokens'] ?? $request->estimatedUsage['input_tokens'] ?? 0);
        $output = (int) ($response['usage']['completion_tokens'] ?? $response['usage']['output_tokens'] ?? 0);
        $total = (int) ($response['usage']['total_tokens'] ?? ($input + $output));
        $details = is_array($response['usage']['prompt_tokens_details'] ?? null) ? $response['usage']['prompt_tokens_details'] : [];
        $usage = [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'cache_read_tokens' => (int) ($details['cached_tokens'] ?? $response['usage']['cached_tokens'] ?? 0),
            'cache_write_tokens' => 0,
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
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            $json = json_decode($data, true);
            if (!is_array($json)) {
                continue;
            }
            if (isset($json['usage']) && is_array($json['usage'])) {
                $state['usage'] = $this->usage($request, $json);
            }
            $content = $json['choices'][0]['delta']['content'] ?? $json['choices'][0]['message']['content'] ?? '';
            if (is_string($content)) {
                $state['output_chars'] += strlen($content);
            }
        }
        return $state;
    }
}
