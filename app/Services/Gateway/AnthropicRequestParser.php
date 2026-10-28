<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\Request;

final class AnthropicRequestParser
{
    public function parse(Request $request): GatewayRequest
    {
        $payload = $request->json();
        $input = max(1, (int) ceil(strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') / 4));
        $imageCount = $this->countImages($payload);

        return new GatewayRequest(
            endpoint: $request->path,
            protocol: 'anthropic',
            capability: $imageCount > 0 ? 'vision_understanding' : 'text_generation',
            model: (string) ($payload['model'] ?? ''),
            stream: ($payload['stream'] ?? false) === true,
            payload: $payload,
            estimatedUsage: ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'image_count' => $imageCount],
        );
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
        if (($value['type'] ?? null) === 'image' || isset($value['source']['media_type'])) {
            $count++;
        }

        foreach ($value as $child) {
            $count += $this->countImagesInValue($child);
        }

        return $count;
    }
}
