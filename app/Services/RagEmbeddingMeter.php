<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\MediaPayload;
use TokenHub\Services\Gateway\UsageNormalizer;
use TokenHub\Support\App;

/**
 * Bills query-time embedding calls made on behalf of an API key
 * (POST /v1/rag/search, MCP search_knowledge_base, the gateway `rag` option)
 * to that key: one usage record per provider call, written through the same
 * usage.write queue as gateway requests, so it is priced with the embedding
 * model's price and shows up in usage summaries, trends and dashboards.
 *
 * Calls without an API key in the request context (vectorization workers,
 * admin trial retrieval) are not metered here.
 */
final class RagEmbeddingMeter
{
    public const ENDPOINT = 'rag:query-embedding';

    private static int $sequence = 0;

    /**
     * @param array<string, mixed> $route models + providers row
     * @param array<string, mixed> $payload request body sent upstream
     * @param array<string, mixed> $response ProviderHttpClient::request() result
     */
    public function record(array $route, array $payload, array $response, float $started, string $capability = 'embedding'): void
    {
        $rerank = $capability === 'rerank';
        $apiKey = App::context()->apiKey();
        if ($apiKey === null || (int) ($apiKey['id'] ?? 0) <= 0) {
            return;
        }

        $ok = ($response['ok'] ?? false) === true;
        $usage = is_array($response['json'] ?? null)
            ? (new UsageNormalizer())->fromResponse($rerank ? UsageNormalizer::KIND_RERANK : UsageNormalizer::KIND_EMBEDDING, $response['json'])
            : null;
        if ($usage === null) {
            $input = $ok ? MediaPayload::estimate(['input' => $rerank ? implode("\n", array_map('strval', [$payload['query'] ?? '', ...($payload['documents'] ?? [])])) : ($payload['input'] ?? '')])['tokens'] : 0;
            $usage = ['input_tokens' => $input, 'output_tokens' => 0, 'total_tokens' => $input, 'usage_source' => $ok ? 'estimated' : 'none'];
        }

        self::$sequence++;
        $record = [
            // Distinct from the caller's own record (a chat request with `rag`
            // has its own usage row under the plain request id).
            'request_id' => substr(App::context()->requestId(), 0, 64) . ($rerank ? '-rr' : '-emb') . self::$sequence,
            'trace_id' => App::context()->traceId(),
            'user_id' => $apiKey['user_id'] ?? null,
            'department_id' => $apiKey['department_id'] ?? null,
            'api_key_id' => $apiKey['id'],
            'provider_id' => $route['provider_id'] ?? null,
            'model_id' => $route['model_id'] ?? $route['id'] ?? null,
            'model_name' => $route['name'] ?? null,
            'protocol' => (string) ($route['protocol'] ?? 'openai'),
            'endpoint' => $rerank ? 'rag:rerank' : self::ENDPOINT,
            'capability' => $rerank ? 'rerank' : 'embedding',
            'status' => $ok ? 'success' : 'failed',
            'http_status' => (int) ($response['status'] ?? 0),
            'error_code' => $ok ? null : (string) ($response['error_code'] ?? 'PROVIDER_ERROR'),
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'audio_seconds' => 0,
            'tts_chars' => 0,
            'image_count' => 0,
            'video_seconds' => 0,
            ...$usage,
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => gmdate('Y-m-d H:i:s', (int) floor($started)),
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ];

        try {
            App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), ['type' => 'usage.write', 'record' => $record]);
        } catch (\Throwable $throwable) {
            // Metering must never break retrieval.
            App::logger()->warning('rag', 'rag embedding usage not queued', ['error' => $throwable->getMessage(), 'request_id' => $record['request_id']]);
        }
    }
}
