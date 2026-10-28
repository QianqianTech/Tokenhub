<?php

declare(strict_types=1);

namespace TokenHub\Services;

/**
 * Direct knowledge-base search for API callers that build their own prompt
 * (POST /v1/rag/search) and for the MCP `search_knowledge_base` tool. Runs
 * with exactly the API key's knowledge-base and dataset bindings; nothing is
 * sent to a chat model and no model quota is consumed.
 */
final class RagSearchService
{
    public const MAX_QUERY_CHARS = 2000;
    public const MAX_KNOWLEDGE_BASE_IDS = 20;
    public const MAX_DATASET_IDS = 50;
    public const MAX_TOP_K = 20;
    public const DEFAULT_TOP_K = 5;
    public const MAX_SOURCE_CHARS = 6000;

    private const FIELDS = ['query', 'knowledge_base_ids', 'dataset_ids', 'top_k'];

    /** @var \Closure(array<string, mixed>, string, array<string, mixed>): array<string, mixed> */
    private \Closure $backend;

    /** @var \Closure(string, array<string, mixed>): string */
    private \Closure $desensitizer;

    /**
     * @param (\Closure(array<string, mixed>, string, array<string, mixed>): array<string, mixed>)|null $backend test seam
     * @param (\Closure(string, array<string, mixed>): string)|null $desensitizer test seam
     */
    public function __construct(?\Closure $backend = null, ?\Closure $desensitizer = null)
    {
        $this->backend = $backend ?? static fn (array $apiKey, string $query, array $options): array => (new RagRetrievalService())->search($apiKey, $query, $options);
        $this->desensitizer = $desensitizer ?? static fn (string $query, array $apiKey): string => (new DesensitizationService())->outboundText($query, $apiKey, 'rag_search');
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function search(array $apiKey, array $payload): array
    {
        $unknown = array_diff(array_keys($payload), self::FIELDS);
        if ($unknown !== []) {
            $this->invalid('Unknown field(s): ' . implode(', ', $unknown));
        }
        $query = $payload['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            abort_json('RAG_QUERY_REQUIRED', 'query is required', 422);
        }
        $query = trim($query);
        if (mb_strlen($query) > self::MAX_QUERY_CHARS) {
            $this->invalid('query must be at most ' . self::MAX_QUERY_CHARS . ' characters');
        }
        $topK = $payload['top_k'] ?? self::DEFAULT_TOP_K;
        if (is_float($topK) && floor($topK) === $topK) {
            $topK = (int) $topK;
        }
        if (!is_int($topK) || $topK < 1 || $topK > self::MAX_TOP_K) {
            $this->invalid('top_k must be an integer between 1 and ' . self::MAX_TOP_K);
        }

        // The query goes to the embedding provider and into retrieval logs:
        // same outbound masking as a gateway request.
        $query = trim(($this->desensitizer)($query, $apiKey));
        if ($query === '') {
            abort_json('RAG_QUERY_REQUIRED', 'query is empty after desensitization', 422);
        }

        $result = ($this->backend)($apiKey, $query, [
            'knowledge_base_ids' => $this->idList($payload, 'knowledge_base_ids', self::MAX_KNOWLEDGE_BASE_IDS),
            'dataset_ids' => $this->idList($payload, 'dataset_ids', self::MAX_DATASET_IDS),
            'top_k' => $topK,
        ]);

        return self::format($query, $result);
    }

    /**
     * Public result shape shared by REST and MCP.
     *
     * @param array<string, mixed> $result RagRetrievalService::search() result
     * @return array{query: string, retrieval_id: ?string, searched_datasets: int, hit_count: int, sources: list<array<string, mixed>>}
     */
    public static function format(string $query, array $result): array
    {
        $sources = [];
        foreach ((array) ($result['sources'] ?? []) as $source) {
            if (!is_array($source)) {
                continue;
            }
            $content = (string) ($source['content'] ?? '');
            $truncated = mb_strlen($content) > self::MAX_SOURCE_CHARS;
            $sources[] = [
                'source_id' => (string) ($source['source_id'] ?? ''),
                'title' => (string) ($source['title'] ?? ''),
                'content' => $truncated ? mb_substr($content, 0, self::MAX_SOURCE_CHARS) . '…' : $content,
                'content_truncated' => $truncated,
                'score' => round((float) ($source['score'] ?? 0), 4),
                'knowledge_base_id' => isset($source['kb_id']) ? (string) $source['kb_id'] : null,
                'dataset_id' => (string) ($source['dataset_id'] ?? ''),
                'dataset_name' => (string) ($source['dataset_name'] ?? ''),
                'source_uri' => (string) ($source['source_uri'] ?? ''),
                'modality' => (string) ($source['modality'] ?? 'text'),
                'retrieval_strategy' => isset($source['retrieval_strategy']) ? (string) $source['retrieval_strategy'] : null,
            ];
        }

        return [
            'query' => $query,
            'retrieval_id' => isset($result['retrieval_id']) ? (string) $result['retrieval_id'] : null,
            'searched_datasets' => (int) ($result['dataset_count'] ?? 0),
            'hit_count' => count($sources),
            'sources' => $sources,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function idList(array $payload, string $field, int $max): array
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return [];
        }
        if (!is_array($payload[$field]) || !array_is_list($payload[$field])) {
            $this->invalid($field . ' must be an array of strings');
        }
        $ids = [];
        foreach ($payload[$field] as $id) {
            if (!is_string($id) || trim($id) === '' || mb_strlen($id) > 191) {
                $this->invalid($field . ' must contain non-empty strings of at most 191 characters');
            }
            $ids[trim($id)] = true;
        }
        if (count($ids) > $max) {
            $this->invalid($field . ' accepts at most ' . $max . ' ids');
        }

        return array_keys($ids);
    }

    private function invalid(string $message): never
    {
        abort_json('RAG_SEARCH_INVALID', $message, 422);
    }
}
