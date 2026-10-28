<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\MultipartUpload;

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
        unset($payload['rag'], $payload['__tokenhub_callback_url']);

        if (isset($payload[MultipartUpload::FILES_KEY]) && str_contains($request->endpoint, '/images/edits') && $this->editsAsJson($route)) {
            // JSON-only edit APIs (xAI): uploaded files become data URIs.
            return [
                'json' => $this->jsonImageEdit($payload),
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ];
        }

        if (isset($payload[MultipartUpload::FILES_KEY])) {
            $multipart = MultipartUpload::encode($payload);

            return [
                'body' => $multipart['body'],
                'headers' => [
                    'Content-Type' => $multipart['content_type'],
                    'Accept' => 'application/json',
                ],
            ];
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
        // Image APIs that answer "images" instead of "data" (SiliconFlow).
        if ($request->capability === 'image_generation' && !isset($providerResponse['data']) && is_array($providerResponse['images'] ?? null)) {
            $providerResponse['data'] = array_values(array_filter(array_map(static function (mixed $item): ?array {
                if (is_string($item)) {
                    return ['url' => $item];
                }
                return is_array($item) ? $item : null;
            }, $providerResponse['images'])));
            unset($providerResponse['images']);
        }

        return $providerResponse;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function editsAsJson(array $route): bool
    {
        $controls = (new \TokenHub\Services\ProviderHeaderVault())->decode(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
        $mode = strtolower((string) ($controls['tokenhub_image_edits'] ?? ''));
        if ($mode !== '') {
            return $mode === 'json';
        }

        return str_contains(strtolower((string) ($route['base_url'] ?? '')), 'api.x.ai');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function jsonImageEdit(array $payload): array
    {
        $images = [];
        $mask = null;
        foreach ((array) $payload[MultipartUpload::FILES_KEY] as $name => $file) {
            $bytes = is_file((string) ($file['path'] ?? '')) ? (string) file_get_contents((string) $file['path']) : (string) ($file['contents'] ?? '');
            $uri = 'data:' . (string) ($file['mime'] ?? 'image/png') . ';base64,' . base64_encode($bytes);
            if (str_starts_with((string) $name, 'mask')) {
                $mask = $uri;
            } else {
                $images[] = $uri;
            }
        }
        unset($payload[MultipartUpload::FILES_KEY]);
        foreach (['n'] as $integer) {
            if (isset($payload[$integer]) && is_numeric($payload[$integer])) {
                $payload[$integer] = (int) $payload[$integer];
            }
        }
        if (count($images) === 1) {
            $payload['image'] = ['url' => $images[0], 'type' => 'image_url'];
        } elseif ($images !== []) {
            $payload['images'] = array_map(static fn (string $uri): array => ['url' => $uri, 'type' => 'image_url'], $images);
        }
        if ($mask !== null) {
            $payload['mask'] = ['url' => $mask, 'type' => 'image_url'];
        }

        return $payload;
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
        if ($request->capability === 'asr') {
            $seconds = $this->transcriptionSeconds($response);
            if ($seconds !== null) {
                $usage['audio_seconds'] = $seconds;
            }
        }

        return $usage;
    }

    /**
     * Billable audio length of a transcription/translation response:
     * usage {"type":"duration","seconds":N} (whisper-1), verbose_json
     * "duration", or the subtitle length the gateway derived for srt/vtt.
     * Rounded up to whole seconds.
     *
     * @param array<string, mixed> $response
     */
    private function transcriptionSeconds(array $response): ?int
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        foreach ([$usage['audio_seconds'] ?? null, ($usage['type'] ?? null) === 'duration' ? ($usage['seconds'] ?? null) : null, $response['duration'] ?? null] as $value) {
            if (is_numeric($value) && (float) $value > 0) {
                return (int) ceil((float) $value);
            }
        }

        return null;
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
