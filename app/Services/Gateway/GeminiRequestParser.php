<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\Request;

/**
 * Gemini-native requests (MM-022):
 *   POST /v1beta/models/{model}:generateContent
 *   POST /v1beta/models/{model}:streamGenerateContent[?alt=sse]
 * The model comes from the path; inline images/audio make the request a
 * vision request for routing.
 */
final class GeminiRequestParser
{
    public function parse(Request $request): GatewayRequest
    {
        $payload = $request->json();
        unset($payload[MultipartUpload::FILES_KEY]);
        $model = (string) ($request->params['model'] ?? '');
        $payload['model'] = $model;
        $input = MediaPayload::estimate($payload)['tokens'];
        $images = $this->countInline($payload, 'image/');

        return new GatewayRequest(
            endpoint: $request->path,
            protocol: 'gemini',
            capability: InputModalities::capability(InputModalities::count($payload)),
            model: $model,
            stream: str_contains($request->path, ':streamGenerateContent'),
            payload: $payload,
            estimatedUsage: ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'image_count' => $images],
        );
    }

    private function countInline(mixed $value, string $prefix): int
    {
        if (!is_array($value)) {
            return 0;
        }
        $count = 0;
        foreach (['inline_data', 'inlineData', 'file_data', 'fileData'] as $key) {
            $mime = $value[$key]['mime_type'] ?? $value[$key]['mimeType'] ?? null;
            if (is_string($mime) && str_starts_with(strtolower($mime), $prefix)) {
                $count++;
            }
        }
        foreach ($value as $child) {
            $count += $this->countInline($child, $prefix);
        }

        return $count;
    }
}
