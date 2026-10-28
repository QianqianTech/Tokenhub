<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\Request;

final class OpenAIRequestParser
{
    public function parse(Request $request): GatewayRequest
    {
        $payload = $request->json();
        // "__files" is internal (multipart uploads parsed below); a JSON body
        // must never smuggle file paths into it.
        unset($payload[MultipartUpload::FILES_KEY]);
        $capability = $this->capability($request->path);
        $uploadError = null;
        if (MultipartUpload::isMultipart($request->header('content-type', $request->server['CONTENT_TYPE'] ?? null))) {
            $upload = MultipartUpload::fromGlobals($request->body, $_FILES, MultipartUpload::allowedMimePrefixes($capability));
            if ($upload['ok']) {
                $payload = $upload['payload'];
            } else {
                $uploadError = ['code' => $upload['code'], 'message' => $upload['message']];
            }
        }
        if ($capability === 'text_generation') {
            // Images, video and audio inputs need a model that accepts them.
            $capability = InputModalities::capability(InputModalities::count($payload));
        }
        if ($request->path === '/v1/videos') {
            $payload = \TokenHub\Services\Video\OpenAIVideoApi::canonicalPayload($payload);
        }
        $estimate = $this->estimateUsage(MultipartUpload::describe($payload), $capability);
        if ($uploadError !== null) {
            $estimate['upload_error'] = $uploadError['code'] . ':' . $uploadError['message'];
        }

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
            str_contains($path, '/rerank') => 'rerank',
            str_contains($path, '/moderations') => 'moderation',
            str_contains($path, '/audio/transcriptions'), str_contains($path, '/audio/translations') => 'asr',
            str_contains($path, '/audio/speech') => 'tts',
            str_contains($path, '/images/generations'), str_contains($path, '/images/edits') => 'image_generation',
            str_contains($path, '/videos/generations'), $path === '/v1/videos' => 'video_generation',
            str_contains($path, '/music/generations') => 'music_generation',
            default => 'text_generation',
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private function estimateUsage(array $payload, string $capability): array
    {
        $input = MediaPayload::estimate($payload)['tokens'];
        return match ($capability) {
            'asr' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'audio_seconds' => max(0, (int) ($payload['audio_seconds'] ?? $payload['duration'] ?? 0))],
            // Characters, not UTF-8 bytes: "你好" is 2 billable characters, not 6.
            'tts' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'tts_chars' => mb_strlen((string) ($payload['input'] ?? ''), 'UTF-8')],
            'image_generation' => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'image_count' => max(1, (int) ($payload['n'] ?? 1))],
            'video_generation' => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'video_seconds' => max(0, (int) ($payload['duration'] ?? $payload['seconds'] ?? 0))],
            // Every document is read once per query: bill query + documents.
            'rerank' => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input],
            'moderation' => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'image_count' => $this->countImages($payload)],
            // Music is billed per generated second (audio_seconds) or per request.
            'music_generation' => ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'audio_seconds' => max(0, (int) ($payload['duration'] ?? $payload['music_length_seconds'] ?? 0))],
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
