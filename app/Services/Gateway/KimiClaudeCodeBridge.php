<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\App;

/**
 * Kimi Code (subscription) compatibility bridge.
 *
 * Kimi Code channels configured with the OpenAI protocol only expose Chat
 * Completions upstream. Claude Code (Anthropic Messages) and Codex (OpenAI
 * Responses) clients are translated to Chat Completions on the way out and
 * back to their own wire format on the way in:
 *
 *   Anthropic                        Chat Completions
 *   system                       ->  system message
 *   text / image blocks          ->  user/assistant content parts
 *   thinking blocks (assistant)  ->  reasoning_content (Preserved Thinking)
 *   tool_use blocks              ->  assistant tool_calls
 *   tool_result blocks           ->  role "tool" messages
 *   tools / tool_choice          ->  function tools / tool_choice
 *   thinking + output_config     ->  reasoning_effort (low | high | max)
 *
 * Channels configured with the Anthropic protocol (Kimi Code's native
 * Messages endpoint) are never bridged: they are proxied unchanged.
 * Chat Completions clients are proxied unchanged as well, except for the
 * temperature normalization Kimi Code requires.
 */
final class KimiClaudeCodeBridge
{
    public const FORMAT_ANTHROPIC = 'anthropic';
    public const FORMAT_RESPONSES = 'responses';
    public const FORMAT_CHAT = 'chat';

    private const KIMI_CODE_HOSTS = ['api.kimi.com/coding', 'api.kimi.ai/coding'];

    /**
     * @param array<string, mixed> $route
     */
    public function isKimiCodeRoute(array $route): bool
    {
        $baseUrl = strtolower((string) ($route['base_url'] ?? ''));
        $name = strtolower((string) ($route['name'] ?? ''));
        $externalName = strtolower((string) ($route['external_name'] ?? ''));

        foreach (self::KIMI_CODE_HOSTS as $host) {
            if (str_contains($baseUrl, $host)) {
                return true;
            }
        }

        return $externalName === 'kimi-for-coding'
            || $name === 'kimi-for-coding'
            || preg_match('/\bkimi[-_ ]code\b/', $name) === 1;
    }

    /**
     * Only OpenAI-protocol Kimi Code channels are bridged; an Anthropic
     * protocol channel speaks Messages natively.
     *
     * @param array<string, mixed> $route
     */
    public function shouldBridge(GatewayRequest $request, array $route): bool
    {
        return $request->capability === 'text_generation'
            && strtolower((string) ($route['protocol'] ?? 'openai')) !== 'anthropic'
            && $this->isKimiCodeRoute($route);
    }

    public function clientFormat(GatewayRequest $request): string
    {
        if ($request->protocol === 'anthropic' || str_contains($request->endpoint, '/messages')) {
            return self::FORMAT_ANTHROPIC;
        }
        if (str_contains($request->endpoint, '/responses')) {
            return self::FORMAT_RESPONSES;
        }

        return self::FORMAT_CHAT;
    }

    /**
     * True when responses must be translated back (Anthropic or Responses client).
     */
    public function translatesResponse(GatewayRequest $request): bool
    {
        return $this->clientFormat($request) !== self::FORMAT_CHAT;
    }

    public function toOpenAIProviderRequest(GatewayRequest $request): GatewayRequest
    {
        $payload = match ($this->clientFormat($request)) {
            self::FORMAT_ANTHROPIC => $this->anthropicToOpenAIPayload($request->payload),
            self::FORMAT_RESPONSES => $this->responsesToOpenAIPayload($request->payload),
            default => $request->payload,
        };

        if (array_key_exists('temperature', $payload)) {
            // Kimi Code rejects non-default temperature values; its compatible
            // admin-session path uses the only accepted value, 1.
            $payload['temperature'] = 1.0;
        }
        if (($payload['stream'] ?? false) === true) {
            // Usage only arrives in the final chunk when asked for.
            $payload['stream_options'] = [...(is_array($payload['stream_options'] ?? null) ? $payload['stream_options'] : []), 'include_usage' => true];
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
     * Translates a Chat Completions response into the client's wire format.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $openaiResponse
     * @return array<string, mixed>
     */
    public function toClientResponse(GatewayRequest $request, array $route, array $openaiResponse): array
    {
        return match ($this->clientFormat($request)) {
            self::FORMAT_ANTHROPIC => $this->toAnthropicResponse($request, $route, $openaiResponse),
            self::FORMAT_RESPONSES => $this->toResponsesResponse($request, $route, $openaiResponse),
            default => $openaiResponse,
        };
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
        $usage = is_array($openaiResponse['usage'] ?? null) ? $openaiResponse['usage'] : [];

        $content = [];
        $reasoning = $this->reasoningText($message);
        if ($reasoning !== '') {
            $content[] = ['type' => 'thinking', 'thinking' => $reasoning, 'signature' => ''];
        }
        $text = $this->openAIContentToText($message['content'] ?? '');
        $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        if ($text !== '' || $toolCalls === []) {
            $content[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($toolCalls as $index => $call) {
            if (!is_array($call)) {
                continue;
            }
            $content[] = [
                'type' => 'tool_use',
                'id' => $this->toolUseId((string) ($call['id'] ?? ''), (int) $index),
                'name' => (string) ($call['function']['name'] ?? ''),
                'input' => $this->decodeArguments($call['function']['arguments'] ?? '{}'),
            ];
        }

        $finish = (string) ($choice['finish_reason'] ?? 'stop');
        if ($toolCalls !== [] && $finish === 'stop') {
            $finish = 'tool_calls';
        }

        return [
            'id' => (string) ($openaiResponse['id'] ?? App::context()->requestId()),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $this->publicModel($request, $route),
            'content' => $content,
            'stop_reason' => $this->stopReason($finish),
            'stop_sequence' => null,
            'usage' => $this->anthropicUsage($usage, $request),
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $openaiResponse
     * @return array<string, mixed>
     */
    public function toResponsesResponse(GatewayRequest $request, array $route, array $openaiResponse): array
    {
        $choice = is_array($openaiResponse['choices'][0] ?? null) ? $openaiResponse['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $usage = is_array($openaiResponse['usage'] ?? null) ? $openaiResponse['usage'] : [];
        $id = (string) ($openaiResponse['id'] ?? App::context()->requestId());

        $output = [];
        $reasoning = $this->reasoningText($message);
        if ($reasoning !== '') {
            $output[] = ['type' => 'reasoning', 'id' => 'rs_' . $id, 'summary' => [['type' => 'summary_text', 'text' => $reasoning]]];
        }
        $text = $this->openAIContentToText($message['content'] ?? '');
        if ($text !== '') {
            $output[] = $this->responsesMessageItem('msg_' . $id, $text);
        }
        foreach ((is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : []) as $index => $call) {
            if (!is_array($call)) {
                continue;
            }
            $callId = (string) ($call['id'] ?? ('call_' . $index));
            $output[] = [
                'type' => 'function_call',
                'id' => 'fc_' . $callId,
                'call_id' => $callId,
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => is_string($call['function']['arguments'] ?? null) ? $call['function']['arguments'] : json_encode($call['function']['arguments'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'status' => 'completed',
            ];
        }

        $finish = (string) ($choice['finish_reason'] ?? 'stop');

        return [
            'id' => 'resp_' . $id,
            'object' => 'response',
            'created_at' => (int) ($openaiResponse['created'] ?? time()),
            'status' => $finish === 'length' ? 'incomplete' : 'completed',
            'model' => $this->publicModel($request, $route),
            'output' => $output,
            'usage' => $this->responsesUsage($usage),
            ...($finish === 'length' ? ['incomplete_details' => ['reason' => 'max_output_tokens']] : []),
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
     * Translates Chat Completions SSE chunks into the client's stream format.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     */
    public function transformOpenAIStreamChunk(GatewayRequest $request, array $route, string $chunk, array &$state): string
    {
        $format = $this->clientFormat($request);
        if ($format === self::FORMAT_CHAT) {
            return $chunk;
        }

        // Network chunks can end mid-line; only complete lines are translated
        // and the remainder waits for the next chunk.
        $chunk = (string) ($state['__line_buffer'] ?? '') . $chunk;
        $lastNewline = strrpos($chunk, "\n");
        if ($lastNewline === false) {
            $state['__line_buffer'] = $chunk;
            return '';
        }
        $state['__line_buffer'] = (string) substr($chunk, $lastNewline + 1);
        $chunk = substr($chunk, 0, $lastNewline + 1);
        $out = '';
        foreach (explode("\n", $chunk) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:') || ($state['done'] ?? false) === true) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '') {
                continue;
            }
            if ($data === '[DONE]') {
                $out .= $this->finishStream($request, $route, $state);
                continue;
            }
            $json = json_decode($data, true);
            if (!is_array($json)) {
                continue;
            }
            $out .= $format === self::FORMAT_ANTHROPIC
                ? $this->anthropicStreamEvent($request, $route, $json, $state)
                : $this->responsesStreamEvent($request, $route, $json, $state);
        }

        return $out;
    }

    /**
     * Closes the translated stream. Called on [DONE] and again by the gateway
     * when the upstream closes without [DONE]; the second call is a no-op.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     */
    public function finishStream(GatewayRequest $request, array $route, array &$state): string
    {
        if (($state['done'] ?? false) === true || $this->clientFormat($request) === self::FORMAT_CHAT) {
            return '';
        }
        $state['done'] = true;

        return $this->clientFormat($request) === self::FORMAT_ANTHROPIC
            ? $this->finishAnthropicStream($request, $route, $state)
            : $this->finishResponsesStream($request, $route, $state);
    }

    // ---------------------------------------------------------------- Anthropic stream

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $json
     * @param array<string, mixed> $state
     */
    private function anthropicStreamEvent(GatewayRequest $request, array $route, array $json, array &$state): string
    {
        $out = '';
        if (is_array($json['error'] ?? null)) {
            $state['done'] = true;
            return $this->ensureAnthropicStarted($request, $route, $state)
                . $this->sse('error', ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => (string) ($json['error']['message'] ?? 'Upstream error')]]);
        }
        if (is_array($json['usage'] ?? null)) {
            $state['usage'] = $json['usage'];
        }
        foreach (($json['choices'] ?? []) as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;
            if (is_string($reasoning) && $reasoning !== '') {
                $out .= $this->ensureAnthropicStarted($request, $route, $state);
                $out .= $this->openAnthropicBlock('thinking', $state);
                $out .= $this->sse('content_block_delta', ['type' => 'content_block_delta', 'index' => $state['block_index'], 'delta' => ['type' => 'thinking_delta', 'thinking' => $reasoning]]);
                $state['output_chars'] = (int) ($state['output_chars'] ?? 0) + strlen($reasoning);
            }
            $text = $delta['content'] ?? null;
            if (is_string($text) && $text !== '') {
                $out .= $this->ensureAnthropicStarted($request, $route, $state);
                $out .= $this->openAnthropicBlock('text', $state);
                $out .= $this->sse('content_block_delta', ['type' => 'content_block_delta', 'index' => $state['block_index'], 'delta' => ['type' => 'text_delta', 'text' => $text]]);
                $state['output_chars'] = (int) ($state['output_chars'] ?? 0) + strlen($text);
            }
            $this->collectToolCallDeltas($delta, $state);
            if (isset($choice['finish_reason']) && is_string($choice['finish_reason']) && $choice['finish_reason'] !== '') {
                $state['finish_reason'] = $choice['finish_reason'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     */
    private function finishAnthropicStream(GatewayRequest $request, array $route, array &$state): string
    {
        $out = $this->ensureAnthropicStarted($request, $route, $state);
        $out .= $this->closeAnthropicBlock($state);
        $tools = $this->collectedToolCalls($state);
        foreach ($tools as $position => $call) {
            $index = (int) ($state['next_index'] ?? 0);
            $state['next_index'] = $index + 1;
            $out .= $this->sse('content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => [
                'type' => 'tool_use',
                'id' => $this->toolUseId((string) $call['id'], $position),
                'name' => (string) $call['name'],
                'input' => new \stdClass(),
            ]]);
            $arguments = trim((string) $call['arguments']) === '' ? '{}' : (string) $call['arguments'];
            $out .= $this->sse('content_block_delta', ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $arguments]]);
            $out .= $this->sse('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
        }
        if ((int) ($state['next_index'] ?? 0) === 0) {
            // Anthropic clients expect at least one content block.
            $out .= $this->sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
            $out .= $this->sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
        }
        $finish = (string) ($state['finish_reason'] ?? 'stop');
        if ($tools !== [] && $finish === 'stop') {
            $finish = 'tool_calls';
        }
        $usage = is_array($state['usage'] ?? null) ? $this->anthropicUsage($state['usage'], $request) : ['output_tokens' => max(0, (int) ceil(((int) ($state['output_chars'] ?? 0)) / 4))];
        $out .= $this->sse('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => $this->stopReason($finish), 'stop_sequence' => null], 'usage' => $usage]);
        $out .= $this->sse('message_stop', ['type' => 'message_stop']);

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function openAnthropicBlock(string $type, array &$state): string
    {
        if (($state['block_type'] ?? null) === $type) {
            return '';
        }
        $out = $this->closeAnthropicBlock($state);
        $index = (int) ($state['next_index'] ?? 0);
        $state['next_index'] = $index + 1;
        $state['block_index'] = $index;
        $state['block_type'] = $type;
        $block = $type === 'thinking' ? ['type' => 'thinking', 'thinking' => '', 'signature' => ''] : ['type' => 'text', 'text' => ''];

        return $out . $this->sse('content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => $block]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function closeAnthropicBlock(array &$state): string
    {
        if (!isset($state['block_type'])) {
            return '';
        }
        $out = '';
        if ($state['block_type'] === 'thinking') {
            $out .= $this->sse('content_block_delta', ['type' => 'content_block_delta', 'index' => $state['block_index'], 'delta' => ['type' => 'signature_delta', 'signature' => '']]);
        }
        $out .= $this->sse('content_block_stop', ['type' => 'content_block_stop', 'index' => $state['block_index']]);
        unset($state['block_type'], $state['block_index']);

        return $out;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     */
    private function ensureAnthropicStarted(GatewayRequest $request, array $route, array &$state): string
    {
        if (($state['started'] ?? false) === true) {
            return '';
        }
        $state['started'] = true;

        return $this->sse('message_start', ['type' => 'message_start', 'message' => [
            'id' => App::context()->requestId(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $this->publicModel($request, $route),
            'content' => [],
            'stop_reason' => null,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => (int) ($request->estimatedUsage['input_tokens'] ?? 0), 'output_tokens' => 0],
        ]]);
    }

    // ---------------------------------------------------------------- Responses stream

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $json
     * @param array<string, mixed> $state
     */
    private function responsesStreamEvent(GatewayRequest $request, array $route, array $json, array &$state): string
    {
        $out = $this->ensureResponsesStarted($request, $route, $state);
        if (is_array($json['error'] ?? null)) {
            $state['done'] = true;
            return $out . $this->sse('response.failed', ['type' => 'response.failed', 'response' => [...$this->responsesEnvelope($request, $route, $state, 'failed'), 'error' => ['code' => 'upstream_error', 'message' => (string) ($json['error']['message'] ?? 'Upstream error')]]]);
        }
        if (is_array($json['usage'] ?? null)) {
            $state['usage'] = $json['usage'];
        }
        foreach (($json['choices'] ?? []) as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;
            if (is_string($reasoning) && $reasoning !== '') {
                $state['reasoning'] = (string) ($state['reasoning'] ?? '') . $reasoning;
            }
            $text = $delta['content'] ?? null;
            if (is_string($text) && $text !== '') {
                if (!isset($state['message_index'])) {
                    $state['message_index'] = (int) ($state['next_index'] ?? 0);
                    $state['next_index'] = $state['message_index'] + 1;
                    $out .= $this->sse('response.output_item.added', ['type' => 'response.output_item.added', 'output_index' => $state['message_index'], 'item' => ['type' => 'message', 'id' => $this->responsesMessageId($state), 'role' => 'assistant', 'status' => 'in_progress', 'content' => []]]);
                    $out .= $this->sse('response.content_part.added', ['type' => 'response.content_part.added', 'item_id' => $this->responsesMessageId($state), 'output_index' => $state['message_index'], 'content_index' => 0, 'part' => ['type' => 'output_text', 'text' => '', 'annotations' => []]]);
                }
                $state['text'] = (string) ($state['text'] ?? '') . $text;
                $out .= $this->sse('response.output_text.delta', ['type' => 'response.output_text.delta', 'item_id' => $this->responsesMessageId($state), 'output_index' => $state['message_index'], 'content_index' => 0, 'delta' => $text]);
            }
            $this->collectToolCallDeltas($delta, $state);
            if (isset($choice['finish_reason']) && is_string($choice['finish_reason']) && $choice['finish_reason'] !== '') {
                $state['finish_reason'] = $choice['finish_reason'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     */
    private function finishResponsesStream(GatewayRequest $request, array $route, array &$state): string
    {
        $out = $this->ensureResponsesStarted($request, $route, $state);
        $items = [];
        if (isset($state['message_index'])) {
            $text = (string) ($state['text'] ?? '');
            $item = $this->responsesMessageItem(substr($this->responsesMessageId($state), 4), $text);
            $out .= $this->sse('response.output_text.done', ['type' => 'response.output_text.done', 'item_id' => $item['id'], 'output_index' => $state['message_index'], 'content_index' => 0, 'text' => $text]);
            $out .= $this->sse('response.content_part.done', ['type' => 'response.content_part.done', 'item_id' => $item['id'], 'output_index' => $state['message_index'], 'content_index' => 0, 'part' => $item['content'][0]]);
            $out .= $this->sse('response.output_item.done', ['type' => 'response.output_item.done', 'output_index' => $state['message_index'], 'item' => $item]);
            $items[] = $item;
        }
        foreach ($this->collectedToolCalls($state) as $position => $call) {
            $index = (int) ($state['next_index'] ?? 0);
            $state['next_index'] = $index + 1;
            $callId = (string) ($call['id'] !== '' ? $call['id'] : 'call_' . $position);
            $arguments = trim((string) $call['arguments']) === '' ? '{}' : (string) $call['arguments'];
            $item = ['type' => 'function_call', 'id' => 'fc_' . $callId, 'call_id' => $callId, 'name' => (string) $call['name'], 'arguments' => $arguments, 'status' => 'completed'];
            $out .= $this->sse('response.output_item.added', ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => [...$item, 'arguments' => '', 'status' => 'in_progress']]);
            $out .= $this->sse('response.function_call_arguments.delta', ['type' => 'response.function_call_arguments.delta', 'item_id' => $item['id'], 'output_index' => $index, 'delta' => $arguments]);
            $out .= $this->sse('response.function_call_arguments.done', ['type' => 'response.function_call_arguments.done', 'item_id' => $item['id'], 'output_index' => $index, 'arguments' => $arguments]);
            $out .= $this->sse('response.output_item.done', ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => $item]);
            $items[] = $item;
        }
        $finish = (string) ($state['finish_reason'] ?? 'stop');
        $status = $finish === 'length' ? 'incomplete' : 'completed';
        $response = [...$this->responsesEnvelope($request, $route, $state, $status), 'output' => $items, 'usage' => $this->responsesUsage(is_array($state['usage'] ?? null) ? $state['usage'] : [])];
        if ($status === 'incomplete') {
            $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
        }

        return $out . $this->sse('response.' . $status, ['type' => 'response.' . $status, 'response' => $response]);
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     */
    private function ensureResponsesStarted(GatewayRequest $request, array $route, array &$state): string
    {
        if (($state['started'] ?? false) === true) {
            return '';
        }
        $state['started'] = true;
        $state['created_at'] = time();
        $envelope = $this->responsesEnvelope($request, $route, $state, 'in_progress');

        return $this->sse('response.created', ['type' => 'response.created', 'response' => $envelope])
            . $this->sse('response.in_progress', ['type' => 'response.in_progress', 'response' => $envelope]);
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function responsesEnvelope(GatewayRequest $request, array $route, array $state, string $status): array
    {
        return [
            'id' => 'resp_' . App::context()->requestId(),
            'object' => 'response',
            'created_at' => (int) ($state['created_at'] ?? time()),
            'status' => $status,
            'model' => $this->publicModel($request, $route),
            'output' => [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function responsesMessageId(array $state): string
    {
        return 'msg_' . App::context()->requestId();
    }

    /**
     * @return array<string, mixed>
     */
    private function responsesMessageItem(string $id, string $text): array
    {
        return [
            'type' => 'message',
            'id' => str_starts_with($id, 'msg_') ? $id : 'msg_' . $id,
            'role' => 'assistant',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
        ];
    }

    // ---------------------------------------------------------------- shared stream helpers

    /**
     * Tool call fragments are collected and emitted as complete blocks when
     * the stream finishes: OpenAI may interleave fragments of several calls,
     * which an Anthropic/Responses stream cannot express once a block closed.
     *
     * @param array<string, mixed> $delta
     * @param array<string, mixed> $state
     */
    private function collectToolCallDeltas(array $delta, array &$state): void
    {
        foreach ((is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : []) as $position => $call) {
            if (!is_array($call)) {
                continue;
            }
            $index = (int) ($call['index'] ?? $position);
            $state['tools'] ??= [];
            $state['tools'][$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];
            if (isset($call['id']) && is_string($call['id']) && $call['id'] !== '') {
                $state['tools'][$index]['id'] = $call['id'];
            }
            if (isset($call['function']['name']) && is_string($call['function']['name']) && $state['tools'][$index]['name'] === '') {
                $state['tools'][$index]['name'] = $call['function']['name'];
            }
            if (isset($call['function']['arguments'])) {
                $arguments = $call['function']['arguments'];
                $state['tools'][$index]['arguments'] .= is_string($arguments) ? $arguments : (string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return list<array{id: string, name: string, arguments: string}>
     */
    private function collectedToolCalls(array $state): array
    {
        $tools = is_array($state['tools'] ?? null) ? $state['tools'] : [];
        ksort($tools);

        return array_values($tools);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sse(string $event, array $payload): string
    {
        return 'event: ' . $event . "\n"
            . 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    // ---------------------------------------------------------------- request translation

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
            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';
            if ($role === 'assistant') {
                $messages[] = $this->anthropicAssistantMessage($content);
                continue;
            }
            foreach ($this->anthropicUserMessages($content) as $converted) {
                $messages[] = $converted;
            }
        }

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
        if (isset($payload['stop_sequences']) && is_array($payload['stop_sequences']) && $payload['stop_sequences'] !== []) {
            $openai['stop'] = array_values($payload['stop_sequences']);
        }

        if (isset($payload['tools']) && is_array($payload['tools'])) {
            $tools = $this->toolsToOpenAI($payload['tools']);
            if ($tools !== []) {
                $openai['tools'] = $tools;
            }
        }
        $toolChoice = $this->anthropicToolChoice($payload['tool_choice'] ?? null);
        if ($toolChoice !== null && isset($openai['tools'])) {
            $openai['tool_choice'] = $toolChoice;
            if (is_array($payload['tool_choice'] ?? null) && ($payload['tool_choice']['disable_parallel_tool_use'] ?? false) === true) {
                $openai['parallel_tool_calls'] = false;
            }
        }

        $effort = $this->reasoningEffort($payload);
        if ($effort !== null) {
            $openai['reasoning_effort'] = $effort;
        }

        return $openai;
    }

    /**
     * Assistant turn: text -> content, thinking -> reasoning_content (Kimi
     * Preserved Thinking needs it back), tool_use -> tool_calls.
     *
     * @return array<string, mixed>
     */
    private function anthropicAssistantMessage(mixed $content): array
    {
        if (is_string($content)) {
            return ['role' => 'assistant', 'content' => $content];
        }
        $text = [];
        $reasoning = [];
        $toolCalls = [];
        foreach ((is_array($content) ? $content : []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            switch ((string) ($block['type'] ?? '')) {
                case 'text':
                    $text[] = (string) ($block['text'] ?? '');
                    break;
                case 'thinking':
                    $reasoning[] = (string) ($block['thinking'] ?? '');
                    break;
                case 'tool_use':
                case 'server_tool_use':
                    $toolCalls[] = [
                        'id' => (string) ($block['id'] ?? ''),
                        'type' => 'function',
                        'function' => [
                            'name' => (string) ($block['name'] ?? ''),
                            'arguments' => json_encode(is_array($block['input'] ?? null) && $block['input'] !== [] ? $block['input'] : new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ];
                    break;
            }
        }

        $message = ['role' => 'assistant', 'content' => implode('', $text)];
        $reasoningText = implode("\n", array_filter($reasoning, static fn (string $part): bool => $part !== ''));
        if ($reasoningText !== '') {
            $message['reasoning_content'] = $reasoningText;
        }
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        return $message;
    }

    /**
     * User turn: tool_result blocks become "tool" messages placed first (they
     * must directly follow the assistant tool_calls), remaining text/images
     * become one user message.
     *
     * @return list<array<string, mixed>>
     */
    private function anthropicUserMessages(mixed $content): array
    {
        if (is_string($content)) {
            return [['role' => 'user', 'content' => $content]];
        }
        $toolMessages = [];
        $parts = [];
        foreach ((is_array($content) ? $content : []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'tool_result') {
                [$resultText, $resultImages] = $this->toolResultContent($block['content'] ?? '');
                if (($block['is_error'] ?? false) === true) {
                    $resultText = '[tool error] ' . $resultText;
                }
                $toolMessages[] = ['role' => 'tool', 'tool_call_id' => (string) ($block['tool_use_id'] ?? ''), 'content' => $resultText];
                foreach ($resultImages as $image) {
                    $parts[] = $image;
                }
                continue;
            }
            $part = $this->anthropicBlockToOpenAIPart($block);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        $messages = $toolMessages;
        if ($parts !== []) {
            $messages[] = ['role' => 'user', 'content' => count($parts) === 1 && $parts[0]['type'] === 'text' ? $parts[0]['text'] : $parts];
        }

        return $messages;
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function toolResultContent(mixed $content): array
    {
        if (is_string($content)) {
            return [$content, []];
        }
        $texts = [];
        $images = [];
        foreach ((is_array($content) ? $content : []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text') {
                $texts[] = (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'image') {
                $part = $this->anthropicBlockToOpenAIPart($block);
                if ($part !== null) {
                    $images[] = $part;
                    $texts[] = '[image attached below]';
                }
            }
        }

        return [implode("\n", $texts), $images];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function anthropicBlockToOpenAIPart(array $block): ?array
    {
        $type = (string) ($block['type'] ?? '');
        if ($type === 'text') {
            return ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
        }
        if ($type === 'image' && is_array($block['source'] ?? null)) {
            $source = $block['source'];
            if (($source['type'] ?? 'base64') === 'url' && is_string($source['url'] ?? null)) {
                return ['type' => 'image_url', 'image_url' => ['url' => $source['url']]];
            }
            $data = (string) ($source['data'] ?? '');
            if ($data !== '') {
                return ['type' => 'image_url', 'image_url' => ['url' => 'data:' . (string) ($source['media_type'] ?? 'image/png') . ';base64,' . $data]];
            }
        }

        return null;
    }

    private function anthropicToolChoice(mixed $choice): mixed
    {
        if (!is_array($choice)) {
            return null;
        }

        return match ((string) ($choice['type'] ?? '')) {
            'auto' => 'auto',
            'any' => 'required',
            'none' => 'none',
            'tool' => ['type' => 'function', 'function' => ['name' => (string) ($choice['name'] ?? '')]],
            default => null,
        };
    }

    /**
     * Kimi accepts reasoning_effort low | high | max (no medium). Claude Code
     * sends output_config.effort, older clients thinking.budget_tokens.
     *
     * @param array<string, mixed> $payload
     */
    private function reasoningEffort(array $payload): ?string
    {
        if (isset($payload['reasoning_effort']) && is_string($payload['reasoning_effort'])) {
            return $this->kimiEffort($payload['reasoning_effort']);
        }
        $effort = $payload['output_config']['effort'] ?? $payload['reasoning']['effort'] ?? null;
        if (is_string($effort) && $effort !== '') {
            return $this->kimiEffort($effort);
        }
        $thinking = is_array($payload['thinking'] ?? null) ? $payload['thinking'] : null;
        if ($thinking !== null && in_array((string) ($thinking['type'] ?? ''), ['enabled', 'adaptive'], true) && isset($thinking['budget_tokens'])) {
            $budget = (int) $thinking['budget_tokens'];
            return $budget <= 4096 ? 'low' : ($budget <= 32000 ? 'high' : 'max');
        }

        return null;
    }

    private function kimiEffort(string $effort): ?string
    {
        return match (strtolower($effort)) {
            'none', 'minimal', 'low' => 'low',
            'medium', 'high' => 'high',
            'xhigh', 'max' => 'max',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function responsesToOpenAIPayload(array $payload): array
    {
        $messages = [];
        $instructions = $payload['instructions'] ?? null;
        if (is_string($instructions) && $instructions !== '') {
            $messages[] = ['role' => 'system', 'content' => $instructions];
        }

        $input = $payload['input'] ?? '';
        if (is_string($input)) {
            $messages[] = ['role' => 'user', 'content' => $input];
        } else {
            $pendingReasoning = null;
            foreach ((is_array($input) ? $input : []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = (string) ($item['type'] ?? (isset($item['role']) ? 'message' : ''));
                if ($type === 'reasoning') {
                    $summary = [];
                    foreach ((is_array($item['summary'] ?? null) ? $item['summary'] : []) as $part) {
                        if (is_array($part) && isset($part['text'])) {
                            $summary[] = (string) $part['text'];
                        }
                    }
                    $pendingReasoning = implode("\n", $summary);
                    continue;
                }
                if ($type === 'function_call') {
                    $last = count($messages) - 1;
                    $call = ['id' => (string) ($item['call_id'] ?? $item['id'] ?? ''), 'type' => 'function', 'function' => ['name' => (string) ($item['name'] ?? ''), 'arguments' => is_string($item['arguments'] ?? null) ? $item['arguments'] : '{}']];
                    if ($last >= 0 && ($messages[$last]['role'] ?? '') === 'assistant') {
                        $messages[$last]['tool_calls'][] = $call;
                    } else {
                        $messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [$call]];
                    }
                    if ($pendingReasoning !== null && $pendingReasoning !== '') {
                        $messages[count($messages) - 1]['reasoning_content'] = $pendingReasoning;
                        $pendingReasoning = null;
                    }
                    continue;
                }
                if ($type === 'function_call_output') {
                    $output = $item['output'] ?? '';
                    $messages[] = ['role' => 'tool', 'tool_call_id' => (string) ($item['call_id'] ?? ''), 'content' => is_string($output) ? $output : (string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
                    continue;
                }
                if ($type === 'message') {
                    $role = (string) ($item['role'] ?? 'user');
                    $role = in_array($role, ['system', 'developer'], true) ? 'system' : ($role === 'assistant' ? 'assistant' : 'user');
                    $converted = ['role' => $role, 'content' => $this->responsesContent($item['content'] ?? '', $role)];
                    if ($role === 'assistant' && $pendingReasoning !== null && $pendingReasoning !== '') {
                        $converted['reasoning_content'] = $pendingReasoning;
                        $pendingReasoning = null;
                    }
                    $messages[] = $converted;
                }
            }
        }

        $openai = [
            'model' => (string) ($payload['model'] ?? ''),
            'messages' => $messages,
            'stream' => ($payload['stream'] ?? false) === true,
        ];
        if (isset($payload['max_output_tokens'])) {
            $openai['max_tokens'] = (int) $payload['max_output_tokens'];
        }
        foreach (['max_tokens', 'temperature', 'top_p', 'parallel_tool_calls'] as $key) {
            if (array_key_exists($key, $payload)) {
                $openai[$key] = $payload[$key];
            }
        }
        $tools = [];
        foreach ((is_array($payload['tools'] ?? null) ? $payload['tools'] : []) as $tool) {
            if (is_array($tool) && ($tool['type'] ?? '') === 'function' && !empty($tool['name'])) {
                $tools[] = ['type' => 'function', 'function' => [
                    'name' => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => is_array($tool['parameters'] ?? null) ? $tool['parameters'] : ['type' => 'object'],
                ]];
            }
        }
        if ($tools !== []) {
            $openai['tools'] = $tools;
            $choice = $payload['tool_choice'] ?? null;
            if (is_string($choice) && in_array($choice, ['auto', 'required', 'none'], true)) {
                $openai['tool_choice'] = $choice;
            } elseif (is_array($choice) && ($choice['type'] ?? '') === 'function' && isset($choice['name'])) {
                $openai['tool_choice'] = ['type' => 'function', 'function' => ['name' => (string) $choice['name']]];
            }
        }
        $effort = $this->reasoningEffort($payload);
        if ($effort !== null) {
            $openai['reasoning_effort'] = $effort;
        }

        return $openai;
    }

    private function responsesContent(mixed $content, string $role): mixed
    {
        if (is_string($content)) {
            return $content;
        }
        $parts = [];
        foreach ((is_array($content) ? $content : []) as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = (string) ($part['type'] ?? '');
            if (in_array($type, ['input_text', 'output_text', 'text'], true)) {
                $parts[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];
            } elseif ($type === 'input_image') {
                $url = $part['image_url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
                }
            }
        }
        if ($role !== 'user' || array_filter($parts, static fn (array $part): bool => $part['type'] !== 'text') === []) {
            return implode('', array_map(static fn (array $part): string => (string) ($part['text'] ?? ''), $parts));
        }

        return $parts;
    }

    // ---------------------------------------------------------------- response helpers

    /**
     * Anthropic input_tokens exclude cache reads/writes; UsageNormalizer adds
     * them back, so totals and billing match the upstream prompt_tokens.
     *
     * @param array<string, mixed> $usage
     * @return array<string, int>
     */
    private function anthropicUsage(array $usage, GatewayRequest $request): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? $request->estimatedUsage['input_tokens'] ?? 0);
        $details = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];
        $cacheRead = min($prompt, (int) ($details['cached_tokens'] ?? $usage['cached_tokens'] ?? 0));
        $cacheWrite = min($prompt - $cacheRead, (int) ($details['cache_write_tokens'] ?? $usage['cache_write_tokens'] ?? 0));

        return [
            'input_tokens' => max(0, $prompt - $cacheRead - $cacheWrite),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
            'cache_read_input_tokens' => $cacheRead,
            'cache_creation_input_tokens' => $cacheWrite,
        ];
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, mixed>
     */
    private function responsesUsage(array $usage): array
    {
        $input = (int) ($usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? 0);

        return [
            'input_tokens' => $input,
            'input_tokens_details' => ['cached_tokens' => (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0)],
            'output_tokens' => $output,
            'output_tokens_details' => ['reasoning_tokens' => (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0)],
            'total_tokens' => (int) ($usage['total_tokens'] ?? ($input + $output)),
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function reasoningText(array $message): string
    {
        $reasoning = $message['reasoning_content'] ?? $message['reasoning'] ?? '';

        return is_string($reasoning) ? $reasoning : '';
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private function decodeArguments(mixed $arguments): array|\stdClass
    {
        if (is_array($arguments)) {
            return $arguments === [] ? new \stdClass() : $arguments;
        }
        $decoded = json_decode((string) $arguments, true);
        if (!is_array($decoded) || $decoded === []) {
            return new \stdClass();
        }

        return $decoded;
    }

    private function toolUseId(string $id, int $position): string
    {
        return $id !== '' ? $id : 'toolu_' . App::context()->requestId() . '_' . $position;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function publicModel(GatewayRequest $request, array $route): string
    {
        return $request->model !== '' ? $request->model : (string) ($route['name'] ?? 'kimi-codex');
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
            // Anthropic server tools (web_search_20250305, ...) have no
            // function schema and cannot run on Kimi; they are dropped.
            if (isset($tool['type']) && $tool['type'] !== 'custom' && !isset($tool['input_schema'])) {
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
            'content_filter' => 'refusal',
            default => 'end_turn',
        };
    }
}
