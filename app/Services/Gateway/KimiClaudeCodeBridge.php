<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\App;

final class KimiClaudeCodeBridge
{
    /**
     * @param array<string, mixed> $route
     */
    public function isKimiCodeRoute(array $route): bool
    {
        $baseUrl = strtolower((string) ($route['base_url'] ?? ''));
        $name = strtolower((string) ($route['name'] ?? ''));
        $externalName = strtolower((string) ($route['external_name'] ?? ''));

        return str_contains($baseUrl, 'api.kimi.com/coding')
            || $externalName === 'kimi-for-coding'
            || $name === 'kimi-for-coding'
            || preg_match('/\bkimi[-_ ]code\b/', $name) === 1;
    }

    public function shouldBridge(GatewayRequest $request, array $route): bool
    {
        return $request->capability === 'text_generation'
            && $this->isKimiCodeRoute($route);
    }

    public function toOpenAIProviderRequest(GatewayRequest $request): GatewayRequest
    {
        if ($request->protocol === 'anthropic' || str_contains($request->endpoint, '/messages')) {
            $payload = $this->anthropicToOpenAIPayload($request->payload);
        } elseif (str_contains($request->endpoint, '/responses')) {
            $payload = $this->responsesToOpenAIPayload($request->payload);
        } else {
            $payload = $request->payload;
        }

        if (array_key_exists('temperature', $payload)) {
            // Kimi Code rejects non-default temperature values; its compatible
            // admin-session path uses the only accepted value, 1.
            $payload['temperature'] = 1.0;
        }

        return new GatewayRequest(
            endpoint: '/v1/chat/completions',
            protocol: 'openai',
            capability: $request->capability,
            model: $request->model,
            stream: $request->stream,
            payload: $payload,
            estimatedUsage: $request->estimatedUsage,
        );
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $openaiResponse
     * @return array<string, mixed>
     */
    public function toAnthropicResponse(GatewayRequest $request, array $route, array $openaiResponse): array
    {
        $choice = is_array($openaiResponse['choices'][0] ?? null) ? $openaiResponse['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $content = $this->openAIContentToText($message['content'] ?? '');
        $usage = is_array($openaiResponse['usage'] ?? null) ? $openaiResponse['usage'] : [];

        return [
            'id' => (string) ($openaiResponse['id'] ?? App::context()->requestId()),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $request->model !== '' ? $request->model : (string) ($route['name'] ?? 'kimi-codex'),
            'content' => [['type' => 'text', 'text' => $content]],
            'stop_reason' => $this->stopReason((string) ($choice['finish_reason'] ?? 'stop')),
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? $request->estimatedUsage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * @return array{input_tokens: int}
     */
    public function countTokensResponse(GatewayRequest $request): array
    {
        return ['input_tokens' => max(1, (int) ($request->estimatedUsage['input_tokens'] ?? 1))];
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     */
    public function transformOpenAIStreamChunk(GatewayRequest $request, array $route, string $chunk, array &$state): string
    {
        $out = '';
        foreach (explode("\n", $chunk) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));
            if ($data === '') {
                continue;
            }

            if ($data === '[DONE]') {
                $out .= $this->ensureStreamStarted($request, $route, $state);
                $out .= "event: content_block_stop\n";
                $out .= 'data: ' . json_encode(['type' => 'content_block_stop', 'index' => 0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                $out .= "event: message_delta\n";
                $out .= 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => (int) ($state['output_tokens'] ?? 0)]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                $out .= "event: message_stop\n";
                $out .= 'data: ' . json_encode(['type' => 'message_stop'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                $state['done'] = true;
                continue;
            }

            $json = json_decode($data, true);
            if (!is_array($json)) {
                continue;
            }

            $text = $json['choices'][0]['delta']['content'] ?? $json['choices'][0]['message']['content'] ?? '';
            if (!is_string($text) || $text === '') {
                continue;
            }

            $state['output_tokens'] = (int) ($state['output_tokens'] ?? 0) + max(1, (int) ceil(strlen($text) / 4));
            $out .= $this->ensureStreamStarted($request, $route, $state);
            $out .= "event: content_block_delta\n";
            $out .= 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function anthropicToOpenAIPayload(array $payload): array
    {
        $messages = [];
        if (isset($payload['system'])) {
            $systemText = $this->anthropicContentToText($payload['system']);
            if ($systemText !== '') {
                $messages[] = ['role' => 'system', 'content' => $systemText];
            }
        }

        foreach (($payload['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $messages[] = [
                'role' => $this->openAIRole((string) ($message['role'] ?? 'user')),
                'content' => $this->anthropicContentToOpenAI($message['content'] ?? ''),
            ];
        }

        $openai = [
            'model' => (string) ($payload['model'] ?? ''),
            'messages' => $messages,
            'stream' => ($payload['stream'] ?? false) === true,
        ];

        foreach (['max_tokens', 'temperature', 'top_p', 'stop'] as $key) {
            if (array_key_exists($key, $payload)) {
                $openai[$key] = $payload[$key];
            }
        }

        if (isset($payload['tools']) && is_array($payload['tools'])) {
            $openai['tools'] = $this->toolsToOpenAI($payload['tools']);
        }

        return $openai;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function responsesToOpenAIPayload(array $payload): array
    {
        $input = $payload['input'] ?? '';
        $messages = is_array($input) ? $input : [['role' => 'user', 'content' => (string) $input]];

        $openai = [
            'model' => (string) ($payload['model'] ?? ''),
            'messages' => $messages,
            'stream' => ($payload['stream'] ?? false) === true,
        ];

        foreach (['max_tokens', 'temperature', 'top_p'] as $key) {
            if (array_key_exists($key, $payload)) {
                $openai[$key] = $payload[$key];
            }
        }

        return $openai;
    }

    private function anthropicContentToOpenAI(mixed $content): mixed
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $blocks = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text') {
                $blocks[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
                continue;
            }
            if (($block['type'] ?? '') === 'image' && is_array($block['source'] ?? null)) {
                $source = $block['source'];
                $mediaType = (string) ($source['media_type'] ?? 'image/png');
                $data = (string) ($source['data'] ?? '');
                if ($data !== '') {
                    $blocks[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mediaType . ';base64,' . $data]];
                }
            }
        }

        if (count($blocks) === 1 && ($blocks[0]['type'] ?? '') === 'text') {
            return $blocks[0]['text'];
        }

        return $blocks;
    }

    private function anthropicContentToText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            } elseif (is_string($block)) {
                $parts[] = $block;
            }
        }

        return trim(implode("\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    private function openAIContentToText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && isset($part['text'])) {
                $parts[] = (string) $part['text'];
            } elseif (is_string($part)) {
                $parts[] = $part;
            }
        }

        return trim(implode("\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    private function openAIRole(string $role): string
    {
        return $role === 'assistant' ? 'assistant' : 'user';
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private function toolsToOpenAI(array $tools): array
    {
        $converted = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || empty($tool['name'])) {
                continue;
            }
            $converted[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : ['type' => 'object'],
                ],
            ];
        }

        return $converted;
    }

    private function stopReason(string $finishReason): string
    {
        return match ($finishReason) {
            'length' => 'max_tokens',
            'tool_calls', 'function_call' => 'tool_use',
            default => 'end_turn',
        };
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     */
    private function ensureStreamStarted(GatewayRequest $request, array $route, array &$state): string
    {
        if (($state['started'] ?? false) === true) {
            return '';
        }

        $state['started'] = true;
        $message = [
            'type' => 'message_start',
            'message' => [
                'id' => App::context()->requestId(),
                'type' => 'message',
                'role' => 'assistant',
                'model' => $request->model !== '' ? $request->model : (string) ($route['name'] ?? 'kimi-codex'),
                'content' => [],
                'stop_reason' => null,
                'stop_sequence' => null,
                'usage' => ['input_tokens' => (int) ($request->estimatedUsage['input_tokens'] ?? 0), 'output_tokens' => 0],
            ],
        ];
        $block = ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']];

        return "event: message_start\n"
            . 'data: ' . json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n"
            . "event: content_block_start\n"
            . 'data: ' . json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
