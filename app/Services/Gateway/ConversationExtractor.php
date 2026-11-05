<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Turns a gateway request and its result into one readable conversation turn
 * for the conversation log (会话记录).
 *
 * Clients resend the whole history on every call, so only the "new turn" is
 * kept: the messages after the last assistant message (the user's question,
 * or tool results in an agent loop) plus the model's reply. Stitched together
 * in order, the turns of a session give back the full transcript without
 * storing the history again on every request.
 *
 * Handles OpenAI Chat Completions, Responses and legacy Completions,
 * Anthropic Messages and Gemini generateContent payloads. Media are kept as
 * short placeholders (never base64) and long texts are cut.
 */
final class ConversationExtractor
{
    public const CAPABILITIES = ['text_generation', 'vision_understanding', 'audio_understanding', 'video_understanding'];

    private const MAX_TEXT = 20000;
    private const MAX_TOOL_TEXT = 4000;
    private const MAX_SYSTEM = 20000;
    private const MAX_PARTS = 60;

    /**
     * @param array<string, mixed> $payload request body as the client sent it
     * @param array<string, mixed> $output provider result, or the stream summary (stream/text/tool_calls)
     * @param array<string, string> $hints client session ids taken from request headers
     * @return array<string, mixed>|null null when the request is not a conversation
     */
    public static function extract(string $protocol, string $capability, array $payload, array $output, array $hints = []): ?array
    {
        if (!in_array($capability, self::CAPABILITIES, true)) {
            return null;
        }

        [$format, $system, $messages] = match (true) {
            $protocol === 'anthropic' => ['anthropic', ...self::anthropicMessages($payload)],
            $protocol === 'gemini' => ['gemini', ...self::geminiMessages($payload)],
            isset($payload['messages']) && is_array($payload['messages']) => ['openai_chat', ...self::chatMessages($payload['messages'])],
            array_key_exists('input', $payload) => ['openai_responses', ...self::responsesMessages($payload)],
            array_key_exists('prompt', $payload) => ['openai_completions', null, [self::message('user', [self::text(self::scalarText($payload['prompt']))])]],
            default => [null, null, []],
        };
        if ($format === null || $messages === []) {
            return null;
        }

        $lastAssistant = -1;
        foreach ($messages as $index => $message) {
            if ($message['role'] === 'assistant') {
                $lastAssistant = $index;
            }
        }
        $tail = array_slice($messages, $lastAssistant + 1);
        if ($tail === []) {
            $tail = [$messages[count($messages) - 1]];
        }
        $tail = self::capParts($tail);

        $system = $system !== null && trim($system) !== '' ? self::cut($system, self::MAX_SYSTEM) : null;
        $firstUser = self::firstUserText($messages);
        [$sessionHint, $sessionSource] = self::sessionHint($payload, $hints);
        $title = self::firstUserText($tail);
        if ($title === '') {
            $title = $firstUser;
        }

        return [
            'v' => 1,
            'format' => $format,
            'system' => $system,
            'messages' => array_values($tail),
            'prior_count' => count($messages) - count($tail),
            'reply' => self::reply($format, $output),
            'title' => self::cut(trim((string) preg_replace('/\s+/u', ' ', $title)), 80),
            'session_hint' => $sessionHint,
            'session_source' => $sessionSource,
            // Sessions the client does not name: same system prompt and same first
            // user message => same conversation (every turn resends both).
            'session_seed' => hash('sha256', ($system ?? '') . "\n\x1e" . mb_substr($firstUser, 0, 2000, 'UTF-8')),
            'previous_response_id' => is_string($payload['previous_response_id'] ?? null) ? substr($payload['previous_response_id'], 0, 190) : null,
            'response_id' => self::responseId($output),
        ];
    }

    /**
     * Client-provided conversation id, if any.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $hints
     * @return array{0: ?string, 1: string}
     */
    public static function sessionHint(array $payload, array $hints = []): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $conversation = $payload['conversation'] ?? null;
        $candidates = [];
        // Claude Code: metadata.user_id = "user_<hash>_account_<uuid>_session_<uuid>".
        $metaUser = $metadata['user_id'] ?? null;
        if (is_string($metaUser) && preg_match('/session_([0-9a-fA-F-]{16,64})/', $metaUser, $match) === 1) {
            $candidates[] = 'claude-code:' . $match[1];
        }
        array_push(
            $candidates,
            $payload['conversation_id'] ?? null,
            $payload['session_id'] ?? null,
            $metadata['conversation_id'] ?? null,
            $metadata['session_id'] ?? null,
            is_array($conversation) ? ($conversation['id'] ?? null) : $conversation,
            ...array_values($hints),
        );
        // Codex CLI uses the conversation id as prompt cache key.
        $candidates[] = $payload['prompt_cache_key'] ?? null;
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return [substr(trim((string) $candidate), 0, 200), 'client'];
            }
        }

        return [null, 'derived'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: ?string, 1: list<array<string, mixed>>}
     */
    private static function anthropicMessages(array $payload): array
    {
        $system = self::blocksText($payload['system'] ?? null);
        $messages = [];
        foreach ((array) ($payload['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $parts = self::contentParts($message['content'] ?? '');
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = self::message($role === 'user' && self::onlyToolResults($parts) ? 'tool' : $role, $parts);
        }

        return [$system, $messages];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: ?string, 1: list<array<string, mixed>>}
     */
    private static function geminiMessages(array $payload): array
    {
        $instruction = $payload['systemInstruction'] ?? $payload['system_instruction'] ?? null;
        $system = is_array($instruction) ? self::geminiPartsText($instruction['parts'] ?? []) : (is_string($instruction) ? $instruction : null);
        $messages = [];
        foreach ((array) ($payload['contents'] ?? []) as $content) {
            if (!is_array($content)) {
                continue;
            }
            $parts = self::geminiParts($content['parts'] ?? []);
            $role = ($content['role'] ?? 'user') === 'model' ? 'assistant' : 'user';
            $messages[] = self::message($role === 'user' && self::onlyToolResults($parts) ? 'tool' : $role, $parts);
        }

        return [$system, $messages];
    }

    /**
     * @param array<mixed> $rawMessages
     * @return array{0: ?string, 1: list<array<string, mixed>>}
     */
    private static function chatMessages(array $rawMessages): array
    {
        $system = [];
        $messages = [];
        foreach ($rawMessages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? 'user');
            if ($role === 'system' || $role === 'developer') {
                $system[] = (string) self::blocksText($message['content'] ?? '');
                continue;
            }
            if ($role === 'tool' || $role === 'function') {
                $messages[] = self::message('tool', [self::toolResult((string) ($message['name'] ?? $message['tool_call_id'] ?? ''), (string) self::blocksText($message['content'] ?? ''))]);
                continue;
            }
            $parts = self::contentParts($message['content'] ?? '');
            if ($role === 'assistant') {
                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    if (is_array($call)) {
                        $parts[] = self::toolCall((string) ($call['function']['name'] ?? $call['name'] ?? 'tool'), $call['function']['arguments'] ?? $call['arguments'] ?? '');
                    }
                }
            }
            $messages[] = self::message($role === 'assistant' ? 'assistant' : 'user', $parts);
        }
        $system = array_filter($system, static fn (string $text): bool => trim($text) !== '');

        return [$system === [] ? null : implode("\n\n", $system), $messages];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: ?string, 1: list<array<string, mixed>>}
     */
    private static function responsesMessages(array $payload): array
    {
        $system = is_string($payload['instructions'] ?? null) ? $payload['instructions'] : null;
        $input = $payload['input'];
        if (is_string($input)) {
            return [$system, [self::message('user', [self::text($input)])]];
        }
        $messages = [];
        foreach ((array) $input as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? 'message');
            if (str_ends_with($type, '_call_output')) {
                $messages[] = self::message('tool', [self::toolResult((string) ($item['call_id'] ?? ''), (string) self::blocksText($item['output'] ?? ''))]);
                continue;
            }
            if (str_ends_with($type, '_call')) {
                $messages[] = self::message('assistant', [self::toolCall((string) ($item['name'] ?? $type), $item['arguments'] ?? $item['input'] ?? $item['action'] ?? '')]);
                continue;
            }
            if ($type !== 'message') {
                continue;
            }
            $role = (string) ($item['role'] ?? 'user');
            if ($role === 'system' || $role === 'developer') {
                $system = trim(($system ?? '') . "\n\n" . self::blocksText($item['content'] ?? ''));
                continue;
            }
            $messages[] = self::message($role === 'assistant' ? 'assistant' : 'user', self::contentParts($item['content'] ?? ''));
        }

        return [$system, $messages];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function contentParts(mixed $content): array
    {
        if (is_string($content)) {
            return $content === '' ? [] : [self::text($content)];
        }
        if (!is_array($content)) {
            return is_scalar($content) ? [self::text((string) $content)] : [];
        }
        if (isset($content['type']) || isset($content['text'])) {
            $content = [$content];
        }
        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = self::text($block);
                continue;
            }
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? (isset($block['text']) ? 'text' : ''));
            $part = match ($type) {
                'text', 'input_text', 'output_text' => self::text((string) ($block['text'] ?? '')),
                'image_url', 'input_image', 'image' => ['type' => 'image', 'ref' => self::mediaRef($block['image_url'] ?? $block['source'] ?? $block['url'] ?? null)],
                'input_audio', 'audio_url', 'audio' => ['type' => 'audio', 'ref' => self::mediaRef($block['audio_url'] ?? $block['source'] ?? null)],
                'video_url', 'input_video', 'video' => ['type' => 'video', 'ref' => self::mediaRef($block['video_url'] ?? $block['source'] ?? null)],
                'input_file', 'file', 'document' => ['type' => 'file', 'ref' => self::fileRef($block)],
                'tool_use', 'server_tool_use' => self::toolCall((string) ($block['name'] ?? 'tool'), $block['input'] ?? []),
                'tool_result', 'web_search_tool_result' => self::toolResult((string) ($block['tool_use_id'] ?? ''), (string) self::blocksText($block['content'] ?? '')),
                'refusal' => self::text((string) ($block['refusal'] ?? '')),
                default => null,
            };
            if ($part !== null && !($part['type'] === 'text' && $part['text'] === '')) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function geminiParts(mixed $parts): array
    {
        $out = [];
        foreach ((array) $parts as $part) {
            if (!is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }
            $call = $part['functionCall'] ?? $part['function_call'] ?? null;
            $response = $part['functionResponse'] ?? $part['function_response'] ?? null;
            $data = $part['inlineData'] ?? $part['inline_data'] ?? $part['fileData'] ?? $part['file_data'] ?? null;
            if (isset($part['text']) && is_string($part['text'])) {
                $out[] = self::text($part['text']);
            } elseif (is_array($call)) {
                $out[] = self::toolCall((string) ($call['name'] ?? 'tool'), $call['args'] ?? []);
            } elseif (is_array($response)) {
                $out[] = self::toolResult((string) ($response['name'] ?? ''), self::encode($response['response'] ?? []));
            } elseif (is_array($data)) {
                $mime = (string) ($data['mimeType'] ?? $data['mime_type'] ?? '');
                $type = match (true) {
                    str_starts_with($mime, 'image/') => 'image',
                    str_starts_with($mime, 'audio/') => 'audio',
                    str_starts_with($mime, 'video/') => 'video',
                    default => 'file',
                };
                $uri = $data['fileUri'] ?? $data['file_uri'] ?? null;
                $out[] = ['type' => $type, 'ref' => is_string($uri) ? self::mediaRef($uri) : ($mime !== '' ? $mime : '内联数据')];
            }
        }

        return $out;
    }

    private static function geminiPartsText(mixed $parts): ?string
    {
        $texts = [];
        foreach (self::geminiParts($parts) as $part) {
            if ($part['type'] === 'text') {
                $texts[] = $part['text'];
            }
        }

        return $texts === [] ? null : implode("\n", $texts);
    }

    /**
     * Plain text of a string or block list (system prompts, tool outputs).
     */
    private static function blocksText(mixed $content): ?string
    {
        if ($content === null) {
            return null;
        }
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return is_scalar($content) ? (string) $content : null;
        }
        $texts = [];
        foreach (self::contentParts($content) as $part) {
            $texts[] = match ($part['type']) {
                'text', 'tool_result' => $part['text'],
                default => '[' . $part['type'] . ']',
            };
        }

        return implode("\n", $texts);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private static function reply(string $format, array $output): array
    {
        $text = [];
        $reasoning = [];
        $calls = [];
        $media = [];
        $error = null;

        if (($output['stream'] ?? false) === true) {
            $text[] = (string) ($output['text'] ?? '');
            foreach ((array) ($output['tool_calls'] ?? []) as $call) {
                if (is_array($call)) {
                    $calls[] = self::toolCall((string) ($call['name'] ?? 'tool'), $call['arguments'] ?? '');
                }
            }
            if (isset($output['upstream_error'])) {
                $error = is_string($output['upstream_error']) ? $output['upstream_error'] : self::encode($output['upstream_error']);
            }
            if (($output['text_truncated'] ?? false) === true) {
                $text[] = '…（流式回复过长，已截断）';
            }
        } elseif (isset($output['audio_bytes'])) {
            $media[] = ['type' => 'audio', 'ref' => '音频 ' . (int) $output['audio_bytes'] . ' 字节'];
        } elseif ($format === 'anthropic' || (($output['type'] ?? '') === 'message' && is_array($output['content'] ?? null))) {
            foreach ((array) ($output['content'] ?? []) as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $type = (string) ($block['type'] ?? '');
                if ($type === 'text') {
                    $text[] = (string) ($block['text'] ?? '');
                } elseif ($type === 'thinking') {
                    $reasoning[] = (string) ($block['thinking'] ?? '');
                } elseif ($type === 'tool_use' || $type === 'server_tool_use') {
                    $calls[] = self::toolCall((string) ($block['name'] ?? 'tool'), $block['input'] ?? []);
                }
            }
        } elseif (isset($output['candidates']) && is_array($output['candidates'])) {
            $candidate = is_array($output['candidates'][0] ?? null) ? $output['candidates'][0] : [];
            foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (isset($part['text']) && ($part['thought'] ?? false) === true) {
                    $reasoning[] = (string) $part['text'];
                } elseif (isset($part['text'])) {
                    $text[] = (string) $part['text'];
                } elseif (is_array($part['functionCall'] ?? null)) {
                    $calls[] = self::toolCall((string) ($part['functionCall']['name'] ?? 'tool'), $part['functionCall']['args'] ?? []);
                } elseif (is_array($part['inlineData'] ?? null)) {
                    $media[] = ['type' => 'image', 'ref' => (string) ($part['inlineData']['mimeType'] ?? '生成的媒体')];
                }
            }
        } elseif (isset($output['output']) && is_array($output['output'])) {
            foreach ($output['output'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = (string) ($item['type'] ?? '');
                if ($type === 'message') {
                    foreach (self::contentParts($item['content'] ?? []) as $part) {
                        if ($part['type'] === 'text') {
                            $text[] = $part['text'];
                        }
                    }
                } elseif ($type === 'reasoning') {
                    foreach ((array) ($item['summary'] ?? []) as $summary) {
                        if (is_array($summary) && isset($summary['text'])) {
                            $reasoning[] = (string) $summary['text'];
                        }
                    }
                } elseif (str_ends_with($type, '_call')) {
                    $calls[] = self::toolCall((string) ($item['name'] ?? $type), $item['arguments'] ?? $item['input'] ?? $item['action'] ?? '');
                }
            }
        } elseif (isset($output['choices']) && is_array($output['choices'])) {
            $choice = is_array($output['choices'][0] ?? null) ? $output['choices'][0] : [];
            if (isset($choice['text']) && is_scalar($choice['text'])) {
                $text[] = (string) $choice['text'];
            }
            $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
            foreach (self::contentParts($message['content'] ?? '') as $part) {
                if ($part['type'] === 'text') {
                    $text[] = $part['text'];
                } else {
                    $media[] = $part;
                }
            }
            foreach (['reasoning_content', 'reasoning'] as $key) {
                if (is_string($message[$key] ?? null) && $message[$key] !== '') {
                    $reasoning[] = $message[$key];
                }
            }
            foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                if (is_array($call)) {
                    $calls[] = self::toolCall((string) ($call['function']['name'] ?? 'tool'), $call['function']['arguments'] ?? '');
                }
            }
        }
        if ($error === null && is_array($output['error'] ?? null)) {
            $error = (string) ($output['error']['message'] ?? self::encode($output['error']));
        }

        return array_filter([
            'text' => self::cut(implode('', $text), self::MAX_TEXT),
            'reasoning' => $reasoning === [] ? null : self::cut(implode("\n", $reasoning), self::MAX_TEXT),
            'tool_calls' => array_slice($calls, 0, 20),
            'media' => $media,
            'error' => $error !== null ? self::cut($error, 2000) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param array<string, mixed> $output
     */
    private static function responseId(array $output): ?string
    {
        $id = $output['id'] ?? null;
        return is_string($id) && $id !== '' ? substr($id, 0, 190) : null;
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    private static function onlyToolResults(array $parts): bool
    {
        if ($parts === []) {
            return false;
        }
        foreach ($parts as $part) {
            if ($part['type'] !== 'tool_result') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private static function firstUserText(array $messages): string
    {
        foreach ($messages as $message) {
            if ($message['role'] !== 'user') {
                continue;
            }
            foreach ($message['parts'] as $part) {
                if ($part['type'] === 'text' && trim($part['text']) !== '') {
                    return $part['text'];
                }
            }
        }

        return '';
    }

    /**
     * Keeps the newest parts when an agent sends a large batch of tool results.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private static function capParts(array $messages): array
    {
        $budget = self::MAX_PARTS;
        $out = [];
        foreach (array_reverse($messages) as $message) {
            if ($budget <= 0) {
                break;
            }
            $message['parts'] = array_slice($message['parts'], -$budget);
            $budget -= count($message['parts']);
            $out[] = $message;
        }

        return array_reverse($out);
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return array{role: string, parts: list<array<string, mixed>>}
     */
    private static function message(string $role, array $parts): array
    {
        return ['role' => $role, 'parts' => array_values($parts)];
    }

    /**
     * @return array{type: string, text: string}
     */
    private static function text(string $text): array
    {
        return ['type' => 'text', 'text' => self::cut($text, self::MAX_TEXT)];
    }

    /**
     * @return array<string, string>
     */
    private static function toolCall(string $name, mixed $arguments): array
    {
        return ['type' => 'tool_call', 'name' => self::cut($name, 120), 'arguments' => self::cut(is_string($arguments) ? $arguments : self::encode($arguments), self::MAX_TOOL_TEXT)];
    }

    /**
     * @return array<string, string>
     */
    private static function toolResult(string $name, string $text): array
    {
        return ['type' => 'tool_result', 'name' => self::cut($name, 120), 'text' => self::cut($text, self::MAX_TOOL_TEXT)];
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function fileRef(array $block): string
    {
        foreach ([$block['filename'] ?? null, $block['title'] ?? null, is_array($block['file'] ?? null) ? ($block['file']['filename'] ?? null) : null] as $name) {
            if (is_string($name) && $name !== '') {
                return self::cut($name, 200);
            }
        }

        return self::mediaRef($block['source'] ?? $block['file_url'] ?? $block['file_id'] ?? null);
    }

    private static function mediaRef(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['url'] ?? $value['data'] ?? $value['file_id'] ?? $value['media_type'] ?? null;
        }
        if (!is_string($value) || $value === '') {
            return '';
        }
        if (str_starts_with($value, 'data:')) {
            $semicolon = strpos($value, ';');
            $mime = $semicolon !== false ? substr($value, 5, $semicolon - 5) : '';
            return ($mime !== '' ? $mime : '内联数据') . '，约 ' . max(1, (int) (strlen($value) * 3 / 4 / 1024)) . ' KB';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            return self::cut($value, 500);
        }
        // Raw base64 or an upload id: never keep the bytes.
        return strlen($value) > 120 ? '内联数据，约 ' . max(1, (int) (strlen($value) * 3 / 4 / 1024)) . ' KB' : $value;
    }

    private static function scalarText(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function cut(string $text, int $max): string
    {
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max, 'UTF-8') . '…（已截断，原文 ' . $length . ' 字）';
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
