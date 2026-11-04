<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;

/**
 * Multi-turn query rewriting for retrieval: a follow-up such as "那个怎么报？" is rewritten,
 * from the conversation, into a standalone question ("差旅住宿费怎么报销？") before searching.
 * Only the retrieval query changes; the messages sent to the answering model stay as they are.
 *
 * Enabled per knowledge base (retrieval_profile.query_rewrite) or for all through
 * RAG_QUERY_REWRITE_MODEL_ID:
 *   {"query_rewrite": {"model_id": 7, "mode": "auto", "max_turns": 3, "timeout_ms": 3000}}
 *  - mode "auto" (default): only when there is earlier conversation and the question looks
 *    dependent on it (short, or refers back with 这/那/它/上面/this/that ...); "always": every
 *    question that has earlier conversation;
 *  - without a usable model (or when the call fails, times out or returns nonsense) the
 *    previous user question is put in front of the current one for retrieval ("context").
 * Rewrites are cached for 10 minutes (Redis) and metered like other RAG model calls.
 */
final class RagQueryRewriteService
{
    private const REFERENCE = '/(这|那|它|他|她|其|该|此|上述|上面|前面|刚才|之前|以上|同样|还有|呢\s*[?？]?$)|\b(it|its|this|that|these|those|they|them|the above|same|also)\b/iu';

    /** @param null|callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed> $requester */
    public function __construct(private readonly mixed $requester = null, private readonly ?RagEmbeddingMeter $meter = null)
    {
    }

    /**
     * @param list<array<string, mixed>> $datasets
     * @return array{model_id: int, mode: string, max_turns: int, timeout_ms: int}|null
     */
    public function settings(array $datasets): ?array
    {
        foreach ($datasets as $dataset) {
            $profile = $dataset['kb_retrieval_profile'] ?? null;
            $profile = is_array($profile) ? $profile : (is_string($profile) && $profile !== '' ? (json_decode($profile, true) ?: []) : []);
            $rewrite = is_array($profile['query_rewrite'] ?? null) ? $profile['query_rewrite'] : null;
            if ($rewrite !== null && ($rewrite['enabled'] ?? true) !== false) {
                return $this->normalize($rewrite);
            }
        }
        $modelId = (int) env_value('RAG_QUERY_REWRITE_MODEL_ID', 0);
        if ($modelId <= 0) {
            return null;
        }

        return $this->normalize(['model_id' => $modelId, 'mode' => env_value('RAG_QUERY_REWRITE_MODE', 'auto')]);
    }

    /**
     * @param array<string, mixed> $payload chat request
     * @param array{model_id: int, mode: string, max_turns: int, timeout_ms: int} $settings
     * @return array{query: string, method: string, original: string}
     */
    public function rewrite(array $payload, string $query, array $settings): array
    {
        $unchanged = ['query' => $query, 'method' => 'none', 'original' => $query];
        $history = $this->history($payload, $settings['max_turns']);
        if (trim($query) === '' || $history === [] || !$this->dependent($query, $settings['mode'])) {
            return $unchanged;
        }
        if ($settings['model_id'] > 0) {
            $cacheKey = 'rag:query-rewrite:' . hash('sha256', $settings['model_id'] . "\x1f" . json_encode($history, JSON_UNESCAPED_UNICODE) . "\x1f" . $query);
            try {
                $cached = App::redis()->get($cacheKey);
                if (is_string($cached) && $cached !== '') {
                    return ['query' => $cached, 'method' => 'model', 'original' => $query];
                }
            } catch (Throwable) {
            }
            $rewritten = $this->ask($history, $query, $settings);
            if ($rewritten !== null) {
                try {
                    App::redis()->set($cacheKey, $rewritten, 600);
                } catch (Throwable) {
                }
                return ['query' => $rewritten, 'method' => 'model', 'original' => $query];
            }
        }
        // Fallback: the previous user question carries the missing subject.
        $previous = '';
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] === 'user') {
                $previous = $history[$i]['content'];
                break;
            }
        }
        if ($previous === '') {
            return $unchanged;
        }

        return ['query' => mb_substr($previous, 0, 200) . ' ' . $query, 'method' => 'context', 'original' => $query];
    }

    /**
     * Earlier user/assistant turns (text only), oldest first, without the final user message.
     *
     * @param array<string, mixed> $payload
     * @return list<array{role: string, content: string}>
     */
    public function history(array $payload, int $maxTurns): array
    {
        $messages = $payload['messages'] ?? (is_array($payload['input'] ?? null) ? $payload['input'] : []);
        if (!is_array($messages)) {
            return [];
        }
        $turns = [];
        $skippedLastUser = false;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? null;
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? 'user');
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $text = $this->text($message['content'] ?? null);
            if ($text === '') {
                continue;
            }
            if (!$skippedLastUser) {
                if ($role === 'user') {
                    $skippedLastUser = true;
                }
                continue;
            }
            array_unshift($turns, ['role' => $role, 'content' => mb_substr($text, 0, 500)]);
            if (count($turns) >= $maxTurns * 2) {
                break;
            }
        }

        return $turns;
    }

    private function dependent(string $query, string $mode): bool
    {
        if ($mode === 'always') {
            return true;
        }
        $length = mb_strlen(trim($query));

        return $length <= 12 || ($length <= 60 && preg_match(self::REFERENCE, $query) === 1);
    }

    /**
     * @param list<array{role: string, content: string}> $history
     * @param array{model_id: int, mode: string, max_turns: int, timeout_ms: int} $settings
     */
    private function ask(array $history, string $query, array $settings): ?string
    {
        $route = App::db()->first(
            'SELECT m.*, m.id AS model_id, p.id AS provider_id, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m JOIN providers p ON p.id = m.provider_id
             WHERE m.id = ? AND m.capability IN ("text_generation", "vision_understanding") AND m.status = "active" AND p.status = "active" LIMIT 1',
            [$settings['model_id']]
        );
        if ($route === null) {
            App::logger()->warning('rag', 'rag query rewrite model not available', ['model_id' => $settings['model_id']]);
            return null;
        }
        // Keep retrieval latency bounded: one attempt, short timeout.
        $route['timeout_ms'] = min((int) ($route['timeout_ms'] ?? 60000) ?: 60000, $settings['timeout_ms']);
        $route['retry_count'] = 1;
        $conversation = implode("\n", array_map(static fn (array $turn): string => ($turn['role'] === 'user' ? '用户：' : '助手：') . $turn['content'], $history));
        $system = '你负责为知识库检索改写问题。根据对话历史，把用户的最后一个问题改写成一个不依赖上下文、可以单独检索的完整问题：补全“这个、那个、它、上面说的”等指代，补全省略的主语和对象。只输出改写后的一个问题，不要回答问题，不要解释；如果最后一个问题本身已经完整，原样输出。保持原问题的语言。';
        $user = "对话历史：\n" . $conversation . "\n\n最后一个问题：" . $query;
        $model = (string) (($route['external_name'] ?? '') ?: ($route['name'] ?? ''));
        if ((string) ($route['protocol'] ?? 'openai') === 'anthropic') {
            $path = '/v1/messages';
            $payload = ['model' => $model, 'max_tokens' => 128, 'temperature' => 0, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]];
        } else {
            $path = '/v1/chat/completions';
            $payload = ['model' => $model, 'max_tokens' => 128, 'temperature' => 0, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]];
        }
        $started = microtime(true);
        try {
            $response = is_callable($this->requester)
                ? ($this->requester)($route, $path, $payload)
                : (new ProviderHttpClient())->request($route, $path, $payload);
        } catch (Throwable $throwable) {
            App::logger()->warning('rag', 'rag query rewrite failed', ['error' => $throwable->getMessage()]);
            return null;
        }
        ($this->meter ?? new RagEmbeddingMeter())->record($route, $payload, $response, $started, 'text_generation');
        if (($response['ok'] ?? false) !== true) {
            App::logger()->warning('rag', 'rag query rewrite rejected', ['error' => $response['error_code'] ?? null]);
            return null;
        }
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $content = $json['choices'][0]['message']['content'] ?? $json['content'][0]['text'] ?? null;
        if (is_array($content)) {
            $content = implode('', array_map(static fn ($part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part, $content));
        }

        return is_string($content) ? $this->clean($content, $query) : null;
    }

    /** A single question line, or null when the model answered/explained instead. */
    private function clean(string $content, string $query): ?string
    {
        $content = trim((string) preg_replace('/<think>.*?<\/think>/s', '', $content));
        $content = (string) preg_replace('/^(改写后的问题|改写|问题|Rewritten question|Question)\s*[:：]\s*/iu', '', $content);
        $content = trim($content, " \t\n\r\0\x0B\"'“”‘’「」");
        if ($content === '' || str_contains($content, "\n") || mb_strlen($content) > max(300, mb_strlen($query) * 6)) {
            return null;
        }

        return $content;
    }

    private function text(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && in_array((string) ($part['type'] ?? 'text'), ['text', 'input_text', 'output_text'], true) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{model_id: int, mode: string, max_turns: int, timeout_ms: int}
     */
    private function normalize(array $settings): array
    {
        return [
            'model_id' => max(0, (int) ($settings['model_id'] ?? 0)),
            'mode' => ($settings['mode'] ?? 'auto') === 'always' ? 'always' : 'auto',
            'max_turns' => max(1, min(10, (int) ($settings['max_turns'] ?? 3))),
            'timeout_ms' => max(500, min(15000, (int) ($settings['timeout_ms'] ?? 3000))),
        ];
    }
}
