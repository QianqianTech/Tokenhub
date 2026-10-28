<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\Request;

final class AnthropicRequestParser
{
    public function parse(Request $request): GatewayRequest
    {
        $payload = $request->json();
        unset($payload[MultipartUpload::FILES_KEY]);
        $input = MediaPayload::estimate($payload)['tokens'];
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
        // Image blocks only: document (PDF/text) blocks also carry
        // source.media_type but are neither images nor billed as images.
        if (($value['type'] ?? null) === 'image') {
            $count++;
        }

        foreach ($value as $child) {
            $count += $this->countImagesInValue($child);
        }

        return $count;
    }
}
