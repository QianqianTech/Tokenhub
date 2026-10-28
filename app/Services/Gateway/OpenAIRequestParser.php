<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\Request;

final class OpenAIRequestParser
{
    public function parse(Request $request): GatewayRequest
    {
        $payload = $request->json();
        $capability = $this->capability($request->path);
        if ($capability === 'text_generation' && $this->countImages($payload) > 0) {
            $capability = 'vision_understanding';
        }
        $estimate = $this->estimateUsage($payload, $capability);

        return new GatewayRequest(
            endpoint: $request->path,
            protocol: 'openai',
            capability: $capability,
            model: (string) ($payload['model'] ?? ''),
            stream: ($payload['stream'] ?? false) === true,
            payload: $payload,
            estimatedUsage: $estimate,
        );
    }

    private function capability(string $path): string
    {
        return match (true) {
            str_contains($path, '/embeddings') => 'embedding',
            str_contains($path, '/audio/transcriptions') => 'asr',
            str_contains($path, '/audio/speech') => 'tts',
            str_contains($path, '/images/generations') => 'image_generation',
            str_contains($path, '/videos/generations') => 'video_generation',
            default => 'text_generation',
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private function estimateUsage(array $payload, string $capability): array
    {
        $input = max(1, (int) ceil(strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') / 4));
        return match ($capability) {
            'asr' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'audio_seconds' => max(0, (int) ($payload['audio_seconds'] ?? $payload['duration'] ?? 0))],
            'tts' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'tts_chars' => strlen((string) ($payload['input'] ?? ''))],
            'image_generation' => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'image_count' => max(1, (int) ($payload['n'] ?? 1))],
            'video_generation' => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'video_seconds' => max(0, (int) ($payload['duration'] ?? 0))],
            'vision_understanding' => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'image_count' => $this->countImages($payload)],
            default => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'image_count' => $this->countImages($payload)],
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function countImages(array $payload): int
    {
        return $this->countImagesInValue($payload);
    }

    private function countImagesInValue(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }

        $count = 0;
        if (($value['type'] ?? null) === 'image_url' || isset($value['image_url'])) {
            $count++;
        }

        foreach ($value as $child) {
            $count += $this->countImagesInValue($child);
        }

        return $count;
    }
}
