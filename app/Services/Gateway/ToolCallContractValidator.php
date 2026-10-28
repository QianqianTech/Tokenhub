<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Structural checks for tool calling and structured output requests.
 *
 * Catches the multi-turn mistakes that otherwise surface as opaque upstream
 * 400s (or, worse, silently wrong conversations on lenient upstreams):
 * - every tool result references a tool call id issued earlier in the
 *   conversation (OpenAI role=tool / tool_call_id, Anthropic tool_result /
 *   tool_use_id, Responses function_call_output / call_id);
 * - a forced tool_choice names a declared tool;
 * - json_schema response formats carry a schema.
 */
final class ToolCallContractValidator
{
    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function validate(string $kind, array $payload): array
    {
        return match ($kind) {
            UsageNormalizer::KIND_ANTHROPIC => $this->anthropic($payload),
            UsageNormalizer::KIND_RESPONSES => $this->responses($payload),
            UsageNormalizer::KIND_CHAT => $this->chat($payload),
            default => ['ok' => true],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, code?: string, message?: string}
     */
    private function chat(array $payload): array
    {
        $declared = [];
        foreach (($payload['tools'] ?? []) as $tool) {
            if (is_array($tool) && isset($tool['function']['name'])) {
                $declared[] = (string) $tool['function']['name'];
            }
        }
        $choice = $payload['tool_choice'] ?? null;
        if (is_array($choice) && isset($choice['function']['name']) && !in_array((string) $choice['function']['name'], $declared, true)) {
            return $this->fail('TOOL_CHOICE_UNKNOWN', 'tool_choice names a tool that is not declared in tools');
        }
        if (in_array($choice, ['required'], true) && $declared === []) {
            return $this->fail('TOOL_CHOICE_UNKNOWN', 'tool_choice=required needs at least one tool');
        }
        $format = $payload['response_format'] ?? null;
        if (is_array($format) && ($format['type'] ?? null) === 'json_schema' && !is_array($format['json_schema']['schema'] ?? null)) {
            return $this->fail('RESPONSE_FORMAT_INVALID', 'response_format json_schema requires json_schema.schema');
        }

        $issued = [];
        foreach (($payload['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            foreach (($message['tool_calls'] ?? []) as $call) {
                if (is_array($call) && isset($call['id'])) {
                    $issued[(string) $call['id']] = true;
                }
            }
            if (($message['role'] ?? null) === 'tool') {
                $id = (string) ($message['tool_call_id'] ?? '');
                if ($id === '' || !isset($issued[$id])) {
                    return $this->fail('TOOL_CALL_MISMATCH', 'A tool message references a tool_call_id that no earlier assistant message issued');
                }
            }
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, code?: string, message?: string}
     */
    private function anthropic(array $payload): array
    {
        $declared = array_values(array_filter(array_map(static fn (mixed $tool): ?string => is_array($tool) && isset($tool['name']) ? (string) $tool['name'] : null, $payload['tools'] ?? [])));
        $choice = $payload['tool_choice'] ?? null;
        if (is_array($choice) && ($choice['type'] ?? null) === 'tool' && !in_array((string) ($choice['name'] ?? ''), $declared, true)) {
            return $this->fail('TOOL_CHOICE_UNKNOWN', 'tool_choice names a tool that is not declared in tools');
        }
        $issued = [];
        foreach (($payload['messages'] ?? []) as $message) {
            if (!is_array($message) || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? null) === 'tool_use' && isset($block['id'])) {
                    $issued[(string) $block['id']] = true;
                }
                if (($block['type'] ?? null) === 'tool_result') {
                    $id = (string) ($block['tool_use_id'] ?? '');
                    if ($id === '' || !isset($issued[$id])) {
                        return $this->fail('TOOL_CALL_MISMATCH', 'A tool_result references a tool_use_id that no earlier assistant turn issued');
                    }
                }
            }
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, code?: string, message?: string}
     */
    private function responses(array $payload): array
    {
        if (!is_array($payload['input'] ?? null) || isset($payload['previous_response_id'])) {
            // With previous_response_id the earlier calls live upstream.
            return ['ok' => true];
        }
        $issued = [];
        foreach ($payload['input'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) === 'function_call' && isset($item['call_id'])) {
                $issued[(string) $item['call_id']] = true;
            }
            if (($item['type'] ?? null) === 'function_call_output') {
                $id = (string) ($item['call_id'] ?? '');
                if ($id === '' || !isset($issued[$id])) {
                    return $this->fail('TOOL_CALL_MISMATCH', 'function_call_output references a call_id that is not in the input');
                }
            }
        }
        $format = $payload['text']['format'] ?? null;
        if (is_array($format) && ($format['type'] ?? null) === 'json_schema' && !is_array($format['schema'] ?? null)) {
            return $this->fail('RESPONSE_FORMAT_INVALID', 'text.format json_schema requires a schema');
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: false, code: string, message: string}
     */
    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
