<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;

/**
 * Model reranking of fused candidates (RAG-033), through a model with the
 * "rerank" capability (Cohere/Jina/bge-reranker style /v1/rerank).
 *
 * Configuration, most specific first:
 *   knowledge base retrieval_profile.rerank = {"model_id": 12, "top_n": 30, "min_score": 0.2}
 *   env RAG_RERANK_MODEL_ID (+ RAG_RERANK_TOP_N, RAG_RERANK_MIN_SCORE)
 * No configuration means the rule-based local rerank stays in effect.
 * Any provider failure falls back to the incoming order: reranking can make
 * results better, never make retrieval fail.
 */
final class RagRerankService
{
    /** @param null|callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed> $requester */
    public function __construct(
        private readonly mixed $requester = null,
        private readonly ?RagEmbeddingMeter $meter = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $datasets
     * @return array{model_id: int, top_n: int, min_score: float}|null
     */
    public function settings(array $datasets): ?array
    {
        foreach ($datasets as $dataset) {
            $profile = $dataset['kb_retrieval_profile'] ?? null;
            $profile = is_array($profile) ? $profile : (is_string($profile) && $profile !== '' ? (json_decode($profile, true) ?: []) : []);
            $rerank = is_array($profile['rerank'] ?? null) ? $profile['rerank'] : null;
            if ($rerank !== null && ($rerank['enabled'] ?? true) !== false && (int) ($rerank['model_id'] ?? 0) > 0) {
                return $this->normalize($rerank);
            }
        }
        $modelId = (int) env_value('RAG_RERANK_MODEL_ID', 0);
        if ($modelId <= 0) {
            return null;
        }

        return $this->normalize([
            'model_id' => $modelId,
            'top_n' => env_value('RAG_RERANK_TOP_N', 30),
            'min_score' => env_value('RAG_RERANK_MIN_SCORE', 0),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $sources fused, rule-ranked candidates
     * @param array{model_id: int, top_n: int, min_score: float} $settings
     * @return list<array<string, mixed>>|null reranked sources, or null when reranking did not run
     */
    public function rerank(string $query, array $sources, array $settings): ?array
    {
        if ($sources === [] || trim($query) === '') {
            return null;
        }
        $route = App::db()->first(
            'SELECT m.*, m.id AS model_id, p.id AS provider_id, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m JOIN providers p ON p.id = m.provider_id
             WHERE m.id = ? AND m.capability = "rerank" AND m.status = "active" AND p.status = "active" LIMIT 1',
            [$settings['model_id']]
        );
        if ($route === null) {
            App::logger()->warning('rag', 'rag rerank model not available', ['model_id' => $settings['model_id']]);
            return null;
        }
        $candidates = array_slice($sources, 0, $settings['top_n']);
        $documents = array_map(static function (array $source): string {
            $title = trim((string) ($source['title'] ?? ''));
            $content = trim((string) ($source['content'] ?? ''));
            return mb_substr(($title !== '' && !str_contains($content, $title) ? $title . "\n" : '') . $content, 0, 4000);
        }, $candidates);
        $payload = [
            'model' => (string) (($route['external_name'] ?? '') ?: ($route['name'] ?? '')),
            'query' => mb_substr($query, 0, 2000),
            'documents' => $documents,
            'top_n' => count($documents),
        ];
        $started = microtime(true);
        try {
            $response = is_callable($this->requester)
                ? ($this->requester)($route, '/v1/rerank', $payload)
                : (new ProviderHttpClient())->request($route, '/v1/rerank', $payload);
        } catch (Throwable $throwable) {
            App::logger()->warning('rag', 'rag rerank failed', ['error' => $throwable->getMessage()]);
            return null;
        }
        ($this->meter ?? new RagEmbeddingMeter())->record($route, $payload, $response, $started, 'rerank');
        if (($response['ok'] ?? false) !== true) {
            App::logger()->warning('rag', 'rag rerank rejected', ['error' => $response['error_code'] ?? null]);
            return null;
        }
        $results = $response['json']['results'] ?? $response['json']['data'] ?? null;
        if (!is_array($results)) {
            return null;
        }
        $scores = [];
        foreach ($results as $result) {
            if (!is_array($result) || !isset($result['index'])) {
                continue;
            }
            $score = $result['relevance_score'] ?? $result['score'] ?? null;
            if (is_numeric($score)) {
                $scores[(int) $result['index']] = (float) $score;
            }
        }
        if ($scores === []) {
            return null;
        }
        $reranked = [];
        foreach ($candidates as $index => $source) {
            if (!isset($scores[$index])) {
                continue;
            }
            $source['rank_signals']['model_rerank_score'] = round($scores[$index], 6);
            $source['rank_signals']['pre_rerank_rank'] = $index + 1;
            $source['rank_signals']['rerank_model_id'] = $settings['model_id'];
            if ($scores[$index] < $settings['min_score']) {
                continue;
            }
            $source['score'] = $scores[$index];
            $source['confidence_score'] = round($scores[$index], 6);
            $reranked[] = $source;
        }
        usort($reranked, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string) ($a['chunk_id'] ?? ''), (string) ($b['chunk_id'] ?? '')));

        return $reranked;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{model_id: int, top_n: int, min_score: float}
     */
    private function normalize(array $settings): array
    {
        return [
            'model_id' => (int) $settings['model_id'],
            'top_n' => max(2, min(100, (int) ($settings['top_n'] ?? 30))),
            'min_score' => max(0.0, min(1.0, (float) ($settings['min_score'] ?? 0))),
        ];
    }
}
