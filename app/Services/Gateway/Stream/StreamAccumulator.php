<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway\Stream;

use TokenHub\Services\Gateway\UsageNormalizer;

/**
 * Protocol-aware view over a proxied event stream.
 *
 * Bytes are still forwarded to the client unchanged; this class only observes
 * them to decide the request's terminal state, usage and retained content.
 *
 * Terminal markers per protocol
 * - chat (OpenAI-compatible, OpenRouter): "data: [DONE]"; a finish_reason
 *   without [DONE] is accepted as completed_without_done.
 * - responses: response.completed (success), response.incomplete (success,
 *   truncated), response.failed / error (upstream error).
 * - anthropic: message_stop (success), event "error" (upstream error).
 * In-band errors after HTTP 200 (OpenRouter "error" objects, Anthropic
 * overloaded_error, ...) mark the stream failed even if bytes were delivered.
 */
final class StreamAccumulator
{
    private SseEventParser $parser;
    private UsageNormalizer $normalizer;

    private bool $terminal = false;
    private ?string $terminalReason = null;
    private bool $finishSeen = false;
    /** @var array<string, mixed>|null */
    private ?array $error = null;
    /** @var array<string, mixed>|null */
    private ?array $usage = null;
    private string $text = '';
    private int $outputChars = 0;
    private bool $textTruncated = false;
    /** @var array<int|string, array{id: ?string, name: ?string, arguments: string}> */
    private array $toolCalls = [];
    /** @var array<string, mixed> */
    private array $upstream = [];
    private int $events = 0;
    private bool $incompleteTail = false;

    public function __construct(
        private readonly string $kind,
        private readonly int $maxRetainedChars = 1048576,
    ) {
        $this->parser = new SseEventParser();
        $this->normalizer = new UsageNormalizer();
    }

    public function push(string $chunk): void
    {
        foreach ($this->parser->push($chunk) as $event) {
            $this->consume($event);
        }
    }

    public function finish(): void
    {
        $result = $this->parser->finish();
        $this->incompleteTail = $result['incomplete'];
        foreach ($result['events'] as $event) {
            $this->consume($event);
        }
        if (!$this->terminal && $this->finishSeen && $this->error === null && in_array($this->kind, [UsageNormalizer::KIND_CHAT, UsageNormalizer::KIND_GEMINI], true)) {
            $this->terminal = true;
            $this->terminalReason = 'completed_without_done';
        }
    }

    /**
     * success | upstream_error | incomplete
     */
    public function state(): string
    {
        if ($this->error !== null) {
            return 'upstream_error';
        }

        return $this->terminal ? 'success' : 'incomplete';
    }

    public function terminalReason(): ?string
    {
        return $this->terminalReason;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function error(): ?array
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>|null measured usage, null when upstream sent none
     */
    public function usage(): ?array
    {
        return $this->usage;
    }

    public function outputChars(): int
    {
        return $this->outputChars;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function textTruncated(): bool
    {
        return $this->textTruncated;
    }

    /**
     * @return list<array{id: ?string, name: ?string, arguments: string}>
     */
    public function toolCalls(): array
    {
        return array_values($this->toolCalls);
    }

    /**
     * Upstream identifiers useful for reconciliation (OpenRouter generation id,
     * actual model and provider).
     *
     * @return array<string, mixed>
     */
    public function upstream(): array
    {
        return $this->upstream;
    }

    public function eventCount(): int
    {
        return $this->events;
    }

    public function hadIncompleteTail(): bool
    {
        return $this->incompleteTail;
    }

    /**
     * @param array{event: string, data: string, id: ?string} $event
     */
    private function consume(array $event): void
    {
        $this->events++;
        $data = trim($event['data']);
        if ($data === '[DONE]') {
            $this->markTerminal('done');
            return;
        }
        $json = $data === '' ? null : json_decode($data, true);
        if (!is_array($json)) {
            if ($event['event'] === 'error') {
                $this->error = ['type' => 'error', 'message' => mb_substr($data, 0, 500)];
            }
            return;
        }

        $type = (string) ($json['type'] ?? $event['event']);
        if ($event['event'] === 'error' || $type === 'error' || (isset($json['error']) && $json['error'] !== null && !isset($json['choices']))) {
            $this->recordError($json['error'] ?? $json);
            return;
        }

        match ($this->kind) {
            UsageNormalizer::KIND_ANTHROPIC => $this->anthropic($type, $json),
            UsageNormalizer::KIND_RESPONSES => $this->responses($type, $json),
            UsageNormalizer::KIND_GEMINI => $this->gemini($json),
            default => $this->chat($json),
        };
    }

    /**
     * Gemini SSE (alt=sse): every chunk is a GenerateContentResponse; usage
     * snapshots are cumulative; a finishReason ends the turn (no [DONE]).
     *
     * @param array<string, mixed> $json
     */
    private function gemini(array $json): void
    {
        if (is_array($json['usageMetadata'] ?? null)) {
            $this->usage = $this->normalizer->merge($this->usage, $this->normalizer->normalize(UsageNormalizer::KIND_GEMINI, $json['usageMetadata']));
        }
        if (isset($json['responseId']) && is_string($json['responseId'])) {
            $this->upstream['upstream_request_id'] ??= $json['responseId'];
        }
        if (isset($json['modelVersion']) && is_string($json['modelVersion'])) {
            $this->upstream['upstream_model'] ??= $json['modelVersion'];
        }
        foreach ((is_array($json['candidates'] ?? null) ? $json['candidates'] : []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            foreach ((is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : []) as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $this->appendText($part['text'], ($part['thought'] ?? false) !== true);
                }
            }
            if (isset($candidate['finishReason']) && is_string($candidate['finishReason']) && $candidate['finishReason'] !== '') {
                $this->finishSeen = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $json
     */
    private function chat(array $json): void
    {
        $this->captureUpstream($json);
        if (is_array($json['usage'] ?? null)) {
            $this->usage = $this->normalizer->merge($this->usage, $this->normalizer->normalize(UsageNormalizer::KIND_CHAT, $json['usage']));
        }
        foreach (($json['choices'] ?? []) as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            if (isset($choice['error']) && is_array($choice['error'])) {
                $this->recordError($choice['error']);
            }
            if (isset($choice['text']) && is_string($choice['text'])) {
                // Legacy /v1/completions stream: choices[].text
                $this->appendText($choice['text'], true);
            }
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : (is_array($choice['message'] ?? null) ? $choice['message'] : []);
            foreach (['content', 'reasoning_content', 'reasoning'] as $field) {
                if (isset($delta[$field]) && is_string($delta[$field])) {
                    $this->appendText($delta[$field], $field === 'content');
                }
            }
            foreach (($delta['tool_calls'] ?? []) as $position => $call) {
                if (!is_array($call)) {
                    continue;
                }
                $index = $call['index'] ?? $position;
                $this->toolCalls[$index] ??= ['id' => null, 'name' => null, 'arguments' => ''];
                if (isset($call['id']) && is_string($call['id'])) {
                    $this->toolCalls[$index]['id'] = $call['id'];
                }
                if (isset($call['function']['name']) && is_string($call['function']['name'])) {
                    $this->toolCalls[$index]['name'] = $call['function']['name'];
                }
                if (isset($call['function']['arguments']) && is_string($call['function']['arguments'])) {
                    $this->toolCalls[$index]['arguments'] .= $call['function']['arguments'];
                    $this->outputChars += strlen($call['function']['arguments']);
                }
            }
            if (!empty($choice['finish_reason'])) {
                $this->finishSeen = true;
                $this->upstream['finish_reason'] = (string) $choice['finish_reason'];
            }
        }
    }

    /**
     * @param array<string, mixed> $json
     */
    private function responses(string $type, array $json): void
    {
        switch ($type) {
            case 'response.created':
            case 'response.in_progress':
                if (is_array($json['response'] ?? null)) {
                    $this->captureUpstream($json['response']);
                }
                break;
            case 'response.output_text.delta':
            case 'response.reasoning_summary_text.delta':
                if (isset($json['delta']) && is_string($json['delta'])) {
                    $this->appendText($json['delta'], $type === 'response.output_text.delta');
                }
                break;
            case 'response.function_call_arguments.delta':
                $key = (string) ($json['item_id'] ?? $json['output_index'] ?? count($this->toolCalls));
                $this->toolCalls[$key] ??= ['id' => $key, 'name' => null, 'arguments' => ''];
                if (isset($json['delta']) && is_string($json['delta'])) {
                    $this->toolCalls[$key]['arguments'] .= $json['delta'];
                    $this->outputChars += strlen($json['delta']);
                }
                break;
            case 'response.output_item.added':
            case 'response.output_item.done':
                $item = is_array($json['item'] ?? null) ? $json['item'] : [];
                if (($item['type'] ?? '') === 'function_call') {
                    $key = (string) ($item['id'] ?? $json['output_index'] ?? count($this->toolCalls));
                    $this->toolCalls[$key] ??= ['id' => $key, 'name' => null, 'arguments' => ''];
                    $this->toolCalls[$key]['id'] = (string) ($item['call_id'] ?? $item['id'] ?? $key);
                    $this->toolCalls[$key]['name'] = isset($item['name']) ? (string) $item['name'] : $this->toolCalls[$key]['name'];
                    if ($type === 'response.output_item.done' && isset($item['arguments']) && is_string($item['arguments'])) {
                        $this->toolCalls[$key]['arguments'] = $item['arguments'];
                    }
                }
                break;
            case 'response.completed':
            case 'response.incomplete':
                $response = is_array($json['response'] ?? null) ? $json['response'] : [];
                $this->captureUpstream($response);
                if (is_array($response['usage'] ?? null)) {
                    $this->usage = $this->normalizer->merge($this->usage, $this->normalizer->normalize(UsageNormalizer::KIND_RESPONSES, $response['usage']));
                }
                $this->markTerminal($type === 'response.completed' ? 'response.completed' : 'response.incomplete');
                break;
            case 'response.failed':
                $response = is_array($json['response'] ?? null) ? $json['response'] : [];
                if (is_array($response['usage'] ?? null)) {
                    $this->usage = $this->normalizer->merge($this->usage, $this->normalizer->normalize(UsageNormalizer::KIND_RESPONSES, $response['usage']));
                }
                $this->recordError(is_array($response['error'] ?? null) ? $response['error'] : ['type' => 'response.failed']);
                break;
            default:
                break;
        }
    }

    /**
     * @param array<string, mixed> $json
     */
    private function anthropic(string $type, array $json): void
    {
        switch ($type) {
            case 'message_start':
                $message = is_array($json['message'] ?? null) ? $json['message'] : [];
                $this->captureUpstream($message);
                if (is_array($message['usage'] ?? null)) {
                    $this->usage = $this->normalizer->merge($this->usage, $this->normalizer->normalize(UsageNormalizer::KIND_ANTHROPIC, $message['usage']));
                }
                break;
            case 'content_block_start':
                $block = is_array($json['content_block'] ?? null) ? $json['content_block'] : [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $index = (int) ($json['index'] ?? count($this->toolCalls));
                    $this->toolCalls[$index] = ['id' => isset($block['id']) ? (string) $block['id'] : null, 'name' => isset($block['name']) ? (string) $block['name'] : null, 'arguments' => ''];
                }
                break;
            case 'content_block_delta':
                $delta = is_array($json['delta'] ?? null) ? $json['delta'] : [];
                $deltaType = (string) ($delta['type'] ?? '');
                if ($deltaType === 'text_delta' && is_string($delta['text'] ?? null)) {
                    $this->appendText($delta['text'], true);
                } elseif ($deltaType === 'thinking_delta' && is_string($delta['thinking'] ?? null)) {
                    $this->appendText($delta['thinking'], false);
                } elseif ($deltaType === 'input_json_delta' && is_string($delta['partial_json'] ?? null)) {
                    $index = (int) ($json['index'] ?? 0);
                    $this->toolCalls[$index] ??= ['id' => null, 'name' => null, 'arguments' => ''];
                    $this->toolCalls[$index]['arguments'] .= $delta['partial_json'];
                    $this->outputChars += strlen($delta['partial_json']);
                }
                break;
            case 'message_delta':
                if (is_array($json['usage'] ?? null)) {
                    $this->usage = $this->normalizer->merge($this->usage, $this->normalizer->normalize(UsageNormalizer::KIND_ANTHROPIC, $json['usage']));
                }
                if (!empty($json['delta']['stop_reason'])) {
                    $this->finishSeen = true;
                    $this->upstream['finish_reason'] = (string) $json['delta']['stop_reason'];
                }
                break;
            case 'message_stop':
                $this->markTerminal('message_stop');
                break;
            default:
                break;
        }
    }

    /**
     * @param array<string, mixed> $json
     */
    private function captureUpstream(array $json): void
    {
        foreach (['id' => 'upstream_request_id', 'model' => 'upstream_model', 'provider' => 'upstream_provider'] as $source => $target) {
            if (!isset($this->upstream[$target]) && isset($json[$source]) && is_scalar($json[$source]) && (string) $json[$source] !== '') {
                $this->upstream[$target] = (string) $json[$source];
            }
        }
    }

    private function appendText(string $text, bool $visible): void
    {
        $this->outputChars += strlen($text);
        if (!$visible) {
            return;
        }
        $remaining = $this->maxRetainedChars - strlen($this->text);
        if ($remaining <= 0) {
            $this->textTruncated = true;
            return;
        }
        if (strlen($text) > $remaining) {
            $text = mb_strcut($text, 0, $remaining);
            $this->textTruncated = true;
        }
        $this->text .= $text;
    }

    private function markTerminal(string $reason): void
    {
        if ($this->terminal) {
            return;
        }
        $this->terminal = true;
        $this->terminalReason = $reason;
    }

    private function recordError(mixed $error): void
    {
        if ($this->error !== null) {
            return;
        }
        $error = is_array($error) ? $error : ['message' => (string) $error];
        $this->error = [
            'type' => isset($error['type']) ? (string) $error['type'] : (isset($error['code']) ? (string) $error['code'] : 'upstream_error'),
            'code' => isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : null,
            'message' => mb_substr((string) ($error['message'] ?? ''), 0, 500),
        ];
    }
}
