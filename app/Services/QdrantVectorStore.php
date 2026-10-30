<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use TokenHub\Support\App;

final class QdrantVectorStore implements RagVectorStoreInterface
{
    /** @param null|callable(string, string, array<string, mixed>|null): array{ok: bool, status: int, body: string, json: array<string, mixed>|null} $transport */
    /** Payload fields filtered on at query time; indexed so filters stay fast on large collections. */
    private const PAYLOAD_INDEXES = ['dataset_id' => 'keyword', 'kb_id' => 'keyword', 'modality' => 'keyword', 'chunk_id' => 'keyword', 'document_key' => 'keyword'];
    public const LEXICAL_SUFFIX = '__lexical';

    public function __construct(private readonly mixed $transport = null, private readonly string $distance = 'Cosine')
    {
    }

    /** Same store, using the distance metric of a vector config (RAG-030). */
    public function forConfig(?array $config): self
    {
        $distance = match (strtolower((string) ($config['distance_metric'] ?? 'cosine'))) {
            'dot' => 'Dot',
            'euclidean', 'euclid' => 'Euclid',
            default => 'Cosine',
        };

        return $distance === $this->distance ? $this : new self($this->transport, $distance);
    }

    public function upsert(string $collection, string $vectorId, array $vector, array $payload): void
    {
        $this->ensureCollection($collection, count($vector));
        $payload['vector_id'] ??= $vectorId;
        $response = $this->request('PUT', '/collections/' . rawurlencode($collection) . '/points?wait=true', [
            'points' => [[
                'id' => (new RagVectorContractService())->pointId($vectorId),
                'vector' => array_values($vector),
                'payload' => $payload,
            ]],
        ]);
        if (!$response['ok']) {
            throw new RuntimeException($this->writeErrorCode($response));
        }
    }

    public function search(string $collection, array $vector, int $topK, array $filter = []): array
    {
        $body = [
            'vector' => array_values($vector),
            'limit' => max(1, $topK),
            'with_payload' => true,
        ];
        if ($filter !== []) {
            $body['filter'] = $this->filter($filter);
        }

        $response = $this->request('POST', '/collections/' . rawurlencode($collection) . '/points/search', $body);
        if (!$response['ok']) {
            throw new RuntimeException('Qdrant search failed');
        }

        $result = $response['json']['result'] ?? [];
        if (!is_array($result)) {
            return [];
        }

        return array_values(array_map(static fn (array $item): array => [
            'vector_id' => (string) (($item['payload']['vector_id'] ?? null) ?: ($item['id'] ?? '')),
            'score' => (float) ($item['score'] ?? 0),
            'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : [],
        ], $result));
    }

    public function delete(string $collection, array $vectorIds): void
    {
        if ($vectorIds === []) {
            return;
        }
        $points = array_values(array_map(static fn (string $vectorId): string => (new RagVectorContractService())->pointId($vectorId), $vectorIds));
        foreach ([$collection, $collection . self::LEXICAL_SUFFIX] as $index => $target) {
            $response = $this->request('POST', '/collections/' . rawurlencode($target) . '/points/delete?wait=true', ['points' => $points]);
            if ($response['status'] === 404 || $this->isIdempotentDeleteMiss($response)) {
                continue;
            }
            if (!$response['ok'] && $index === 0) {
                throw new RuntimeException('Qdrant delete failed: HTTP ' . $response['status'] . ' ' . substr($response['body'], 0, 200));
            }
        }
    }

    /**
     * Sparse lexical index next to the dense collection (RAG-032): Qdrant
     * applies IDF at query time, so this is BM25-like keyword retrieval with
     * the same dataset filters as vector search.
     *
     * @param array<string, mixed> $payload
     */
    public function upsertLexical(string $collection, string $vectorId, string $text, array $payload): void
    {
        $sparse = (new RagSparseEncoder())->encodeDocument($text);
        if ($sparse['indices'] === []) {
            return;
        }
        $target = $collection . self::LEXICAL_SUFFIX;
        $this->ensureLexicalCollection($target);
        $response = $this->request('PUT', '/collections/' . rawurlencode($target) . '/points?wait=true', [
            'points' => [[
                'id' => (new RagVectorContractService())->pointId($vectorId),
                'vector' => ['lexical' => $sparse],
                'payload' => $payload,
            ]],
        ]);
        if (!$response['ok']) {
            throw new RuntimeException($this->writeErrorCode($response));
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @return list<array{vector_id: string, score: float, payload: array<string, mixed>}>
     */
    public function searchLexical(string $collection, string $query, int $limit, array $filter = []): array
    {
        $sparse = (new RagSparseEncoder())->encodeQuery($query);
        if ($sparse['indices'] === []) {
            return [];
        }
        $body = ['query' => $sparse, 'using' => 'lexical', 'limit' => max(1, $limit), 'with_payload' => true];
        if ($filter !== []) {
            $body['filter'] = $this->filter($filter);
        }
        $response = $this->request('POST', '/collections/' . rawurlencode($collection . self::LEXICAL_SUFFIX) . '/points/query', $body);
        if ($response['status'] === 404) {
            return [];
        }
        if (!$response['ok']) {
            throw new RuntimeException('Qdrant lexical search failed');
        }
        $points = $response['json']['result']['points'] ?? [];

        return array_values(array_map(static fn (array $item): array => [
            'vector_id' => (string) (($item['payload']['vector_id'] ?? null) ?: ($item['id'] ?? '')),
            'score' => (float) ($item['score'] ?? 0),
            'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : [],
        ], is_array($points) ? $points : []));
    }

    public function hasLexicalIndex(string $collection): bool
    {
        return $this->request('GET', '/collections/' . rawurlencode($collection . self::LEXICAL_SUFFIX))['ok'];
    }

    /**
     * Pages through all points of a collection (payload only).
     *
     * @return \Generator<int, array{id: string, payload: array<string, mixed>}>
     */
    public function scroll(string $collection, int $pageSize = 256, array $filter = []): \Generator
    {
        $offset = null;
        do {
            $body = ['limit' => $pageSize, 'with_payload' => true, 'with_vector' => false];
            if ($filter !== []) {
                $body['filter'] = $this->filter($filter);
            }
            if ($offset !== null) {
                $body['offset'] = $offset;
            }
            $response = $this->request('POST', '/collections/' . rawurlencode($collection) . '/points/scroll', $body);
            if ($response['status'] === 404) {
                return;
            }
            if (!$response['ok']) {
                throw new RuntimeException('Qdrant scroll failed');
            }
            foreach ($response['json']['result']['points'] ?? [] as $point) {
                yield ['id' => (string) $point['id'], 'payload' => is_array($point['payload'] ?? null) ? $point['payload'] : []];
            }
            $offset = $response['json']['result']['next_page_offset'] ?? null;
        } while ($offset !== null);
    }

    /** @param list<string> $pointIds */
    public function deletePoints(string $collection, array $pointIds): void
    {
        if ($pointIds === []) {
            return;
        }
        foreach ([$collection, $collection . self::LEXICAL_SUFFIX] as $target) {
            $this->request('POST', '/collections/' . rawurlencode($target) . '/points/delete?wait=true', ['points' => array_values($pointIds)]);
        }
    }

    /** @param array<string, mixed> $filter */
    public function deleteByFilter(string $collection, array $filter): void
    {
        if ($filter === []) {
            throw new RuntimeException('Refusing to delete a whole collection by an empty filter');
        }
        foreach ([$collection, $collection . self::LEXICAL_SUFFIX] as $target) {
            $this->request('POST', '/collections/' . rawurlencode($target) . '/points/delete?wait=true', ['filter' => $this->filter($filter)]);
        }
    }

    public function collectionExists(string $collection): bool
    {
        return $this->request('GET', '/collections/' . rawurlencode($collection))['ok'];
    }

    /** @return array<string, string> alias => collection */
    public function aliases(): array
    {
        $response = $this->request('GET', '/aliases');
        $aliases = [];
        foreach ($response['json']['result']['aliases'] ?? [] as $alias) {
            $aliases[(string) $alias['alias_name']] = (string) $alias['collection_name'];
        }

        return $aliases;
    }

    /**
     * Points alias at collection atomically (delete + create in one request).
     * When a real collection still carries the alias name (first migration to
     * aliases) it is dropped first; that is the only moment of downtime.
     */
    public function swapAlias(string $alias, string $collection): void
    {
        $this->pointAlias($alias, $collection, true);
        $lexical = $collection . self::LEXICAL_SUFFIX;
        if ($this->collectionExists($lexical)) {
            $this->pointAlias($alias . self::LEXICAL_SUFFIX, $lexical, false);
        }
    }

    private function pointAlias(string $alias, string $collection, bool $required): void
    {
        $aliases = $this->aliases();
        if (!isset($aliases[$alias]) && $this->collectionExists($alias)) {
            $this->deleteCollection($alias);
        }
        $actions = isset($aliases[$alias]) ? [['delete_alias' => ['alias_name' => $alias]]] : [];
        $actions[] = ['create_alias' => ['collection_name' => $collection, 'alias_name' => $alias]];
        $response = $this->request('POST', '/collections/aliases', ['actions' => $actions]);
        if (!$response['ok'] && $required) {
            throw new RuntimeException('Qdrant alias swap failed: ' . substr($response['body'], 0, 200));
        }
    }

    public function deleteCollection(string $collection): void
    {
        $this->request('DELETE', '/collections/' . rawurlencode($collection));
    }

    public function healthy(): bool
    {
        return $this->request('GET', '/healthz')['ok'];
    }

    public function ensureCollection(string $collection, int $dimension): void
    {
        $exists = $this->request('GET', '/collections/' . rawurlencode($collection));
        if ($exists['ok']) {
            return;
        }
        $created = $this->request('PUT', '/collections/' . rawurlencode($collection), [
            'vectors' => [
                'size' => max(1, $dimension),
                'distance' => $this->distance,
            ],
        ]);
        if (!$created['ok']) {
            throw new RuntimeException($this->writeErrorCode($created));
        }
        $this->ensurePayloadIndexes($collection);
    }

    public function ensurePayloadIndexes(string $collection): void
    {
        foreach (self::PAYLOAD_INDEXES as $field => $schema) {
            // Idempotent in Qdrant; failures only cost filter speed, never correctness.
            $this->request('PUT', '/collections/' . rawurlencode($collection) . '/index?wait=true', ['field_name' => $field, 'field_schema' => $schema]);
        }
    }

    private function ensureLexicalCollection(string $collection): void
    {
        static $known = [];
        if (isset($known[$collection]) || $this->request('GET', '/collections/' . rawurlencode($collection))['ok']) {
            $known[$collection] = true;
            return;
        }
        $created = $this->request('PUT', '/collections/' . rawurlencode($collection), [
            'vectors' => (object) [],
            'sparse_vectors' => ['lexical' => ['modifier' => 'idf']],
        ]);
        if (!$created['ok']) {
            throw new RuntimeException($this->writeErrorCode($created));
        }
        $this->ensurePayloadIndexes($collection);
        $known[$collection] = true;
    }

    /** @param array{ok: bool, status: int, body: string, json: array<string, mixed>|null} $response */
    private function writeErrorCode(array $response): string
    {
        return $response['status'] === 0 || $response['status'] >= 500
            ? 'RAG_VECTOR_STORE_UNAVAILABLE'
            : 'RAG_VECTOR_STORE_REJECTED';
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function filter(array $filter): array
    {
        $must = [];
        foreach ($filter as $key => $value) {
            $must[] = ['key' => $key, 'match' => ['value' => $value]];
        }
        return ['must' => $must];
    }

    /**
     * @param array{ok: bool, status: int, body: string, json: array<string, mixed>|null} $response
     */
    private function isIdempotentDeleteMiss(array $response): bool
    {
        if ($response['ok'] || $response['status'] !== 400) {
            return false;
        }
        $body = strtolower($response['body']);

        return str_contains($body, 'collection')
            && (str_contains($body, 'not found') || str_contains($body, 'doesn\'t exist'));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if (is_callable($this->transport)) {
            return ($this->transport)($method, $path, $body);
        }

        $url = (string) App::config()->get('rag.qdrant.url', 'http://qdrant:6333');
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $apiKey = (string) App::config()->get('rag.qdrant.api_key', '');
        if ($apiKey !== '') {
            $headers[] = 'api-key: ' . $apiKey;
        }

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => (float) App::config()->get('rag.qdrant.timeout_seconds', 5),
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $raw = @file_get_contents(rtrim($url, '/') . $path, false, stream_context_create($options));
        $status = $this->statusCode($http_response_header ?? []);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_string($raw) ? $raw : '',
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
