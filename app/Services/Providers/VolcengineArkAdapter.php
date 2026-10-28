<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

use TokenHub\Services\Gateway\GatewayRequest;

final class VolcengineArkAdapter implements ProviderAdapterInterface
{
    private OpenAIAdapter $openai;

    public function __construct()
    {
        $this->openai = new OpenAIAdapter();
    }

    public function protocol(): string
    {
        return 'openai';
    }

    public function buildRequest(GatewayRequest $request, array $route): array
    {
        if ($request->capability !== 'video_generation') {
            return $this->openai->buildRequest($request, $route);
        }

        $payload = $request->payload;
        $body = [
            'model' => (string) (($route['external_name'] ?? '') !== '' ? $route['external_name'] : ($payload['model'] ?? $request->model)),
            'content' => $this->content($payload),
        ];

        foreach (['duration', 'resolution', 'ratio', 'seed', 'fps', 'watermark', 'camera_fixed'] as $key) {
            if (array_key_exists($key, $payload)) {
                $body[$key] = $payload[$key];
            }
        }

        return [
            'endpoint' => '/contents/generations/tasks',
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];
    }

    public function normalizeResponse(GatewayRequest $request, array $route, array $providerResponse): array
    {
        if ($request->capability !== 'video_generation') {
            return $this->openai->normalizeResponse($request, $route, $providerResponse);
        }

        $status = $this->status((string) ($providerResponse['status'] ?? $providerResponse['state'] ?? 'processing'));
        $response = [
            'provider_task_id' => $this->taskId($providerResponse),
            'status' => $status,
            'usage' => [
                'input_tokens' => (int) ($request->estimatedUsage['input_tokens'] ?? 0),
                'output_tokens' => 0,
                'total_tokens' => (int) ($request->estimatedUsage['total_tokens'] ?? $request->estimatedUsage['input_tokens'] ?? 0),
                'video_seconds' => (int) ($providerResponse['usage']['video_seconds'] ?? $request->estimatedUsage['video_seconds'] ?? 0),
            ],
        ];

        $assets = $this->assets($providerResponse);
        if ($assets !== []) {
            $response['assets'] = $assets;
        }

        if (($providerResponse['error'] ?? null) !== null) {
            $response['error'] = $providerResponse['error'];
        }

        return array_filter($response, static fn (mixed $value): bool => $value !== null);
    }

    public function normalizeError(int $status, string $body): array
    {
        return ['code' => $status >= 500 ? 'PROVIDER_ERROR' : 'PROVIDER_REJECTED', 'message' => 'Provider request failed'];
    }

    public function usage(GatewayRequest $request, array $response): array
    {
        if ($request->capability !== 'video_generation') {
            return $this->openai->usage($request, $response);
        }

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        return [
            'input_tokens' => (int) ($usage['input_tokens'] ?? $request->estimatedUsage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? $request->estimatedUsage['total_tokens'] ?? 0),
            'video_seconds' => (int) ($usage['video_seconds'] ?? $request->estimatedUsage['video_seconds'] ?? 0),
        ];
    }

    public function accumulateStreamUsage(GatewayRequest $request, string $chunk, array $state): array
    {
        return $this->openai->accumulateStreamUsage($request, $chunk, $state);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function content(array $payload): array
    {
        if (isset($payload['content']) && is_array($payload['content'])) {
            return $payload['content'];
        }

        $content = [[
            'type' => 'text',
            'text' => (string) ($payload['prompt'] ?? $payload['input'] ?? ''),
        ]];

        $imageUrl = $this->imageUrl($payload['image_url'] ?? $payload['image'] ?? null);
        if ($imageUrl !== null) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]];
        }

        return $content;
    }

    private function imageUrl(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            $url = $value['url'] ?? $value['image_url']['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function taskId(array $response): ?string
    {
        foreach (['provider_task_id', 'task_id', 'id'] as $key) {
            if (isset($response[$key]) && is_scalar($response[$key]) && (string) $response[$key] !== '') {
                return (string) $response[$key];
            }
        }

        return null;
    }

    private function status(string $status): string
    {
        return match (strtolower($status)) {
            'success', 'succeeded', 'completed', 'done' => 'succeeded',
            'failed', 'error', 'canceled', 'cancelled' => 'failed',
            default => 'processing',
        };
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function assets(array $response): array
    {
        $assets = [];
        foreach ($this->assetUrls($response) as $url) {
            $assets[] = ['url' => $url, 'mime_type' => 'video/mp4'];
        }

        return $assets;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<string>
     */
    private function assetUrls(array $response): array
    {
        $urls = [];
        foreach (['video_url', 'result_url', 'url', 'asset_url'] as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && $response[$key] !== '') {
                $urls[] = $response[$key];
            }
        }

        foreach (['content', 'result', 'output'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $urls = [...$urls, ...$this->assetUrls($response[$key])];
            }
        }

        foreach (($response['data'] ?? []) as $item) {
            if (is_array($item)) {
                $urls = [...$urls, ...$this->assetUrls($item)];
            }
        }

        return array_values(array_unique($urls));
    }
}
