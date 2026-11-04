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

    /** @var array<string, true> collections verified or created by this process */
    private static array $knownCollections = [];

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
        $this->upsertMany($collection, [['vector_id' => $vectorId, 'vector' => $vector, 'payload' => $payload]]);
    }

    /**
     * Writes many points with one request per batch (V-08) instead of one
     * request per point.
     *
     * @param list<array{vector_id: string, vector: list<float>, payload: array<string, mixed>}> $points
     */
    public function upsertMany(string $collection, array $points, int $batchSize = 128): void
    {
        if ($points === []) {
            return;
        }
        $this->ensureCollection($collection, count($points[0]['vector']));
        $contract = new RagVectorContractService();
        foreach (array_chunk($points, max(1, $batchSize)) as $batch) {
            $body = [];
            foreach ($batch as $point) {
                $payload = $point['payload'];
                $payload['vector_id'] ??= $point['vector_id'];
                $body[] = ['id' => $contract->pointId($point['vector_id']), 'vector' => array_values($point['vector']), 'payload' => $payload];
            }
            $response = $this->request('PUT', '/collections/' . rawurlencode($collection) . '/points?wait=true', ['points' => $body]);
            if ($response['status'] === 404) {
                // Collection dropped outside this process since it was cached: recreate once.
                $this->forget($collection);
                $this->ensureCollection($collection, count($points[0]['vector']));
                $response = $this->request('PUT', '/collections/' . rawurlencode($collection) . '/points?wait=true', ['points' => $body]);
            }
            if (!$response['ok']) {
                throw new RuntimeException($this->writeErrorCode($response));
            }
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

        // Qdrant returns the distance itself for Euclid (smaller = closer). Callers rank
        // and threshold by "higher = more similar", so convert to a (0, 1] similarity (V-05).
        $euclid = $this->distance === 'Euclid';

        return array_values(array_map(static fn (array $item): array => [
            'vector_id' => (string) (($item['payload']['vector_id'] ?? null) ?: ($item['id'] ?? '')),
            'score' => $euclid ? 1.0 / (1.0 + max(0.0, (float) ($item['score'] ?? 0))) : (float) ($item['score'] ?? 0),
            'raw_distance' => $euclid ? (float) ($item['score'] ?? 0) : null,
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
        if (trim($text) === '') {
            return;
        }
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
     * @param list<array{vector_id: string, text: string, payload: array<string, mixed>}> $points
     */
    public function upsertLexicalMany(string $collection, array $points, int $batchSize = 128): void
    {
        $encoder = new RagSparseEncoder();
        $contract = new RagVectorContractService();
        $target = $collection . self::LEXICAL_SUFFIX;
        $body = [];
        foreach ($points as $point) {
            if (trim($point['text']) === '') {
                continue;
            }
            $sparse = $encoder->encodeDocument($point['text']);
            if ($sparse['indices'] !== []) {
                $body[] = ['id' => $contract->pointId($point['vector_id']), 'vector' => ['lexical' => $sparse], 'payload' => $point['payload']];
            }
        }
        if ($body === []) {
            return;
        }
        $this->ensureLexicalCollection($target);
        foreach (array_chunk($body, max(1, $batchSize)) as $batch) {
            $response = $this->request('PUT', '/collections/' . rawurlencode($target) . '/points?wait=true', ['points' => $batch]);
            if (!$response['ok']) {
                throw new RuntimeException($this->writeErrorCode($response));
            }
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
        $this->forget($collection);
        $this->request('DELETE', '/collections/' . rawurlencode($collection));
    }

    public function healthy(): bool
    {
        // One probe every few seconds per process, not one per indexed chunk (V-08).
        static $cache = [];
        if (is_callable($this->transport)) {
            return $this->request('GET', '/healthz')['ok'];
        }
        $key = (string) App::config()->get('rag.qdrant.url', 'http://qdrant:6333');
        if (isset($cache[$key]) && $cache[$key][1] > microtime(true)) {
            return $cache[$key][0];
        }
        $ok = $this->request('GET', '/healthz')['ok'];
        $cache[$key] = [$ok, microtime(true) + ($ok ? 10.0 : 2.0)];

        return $ok;
    }

    /**
     * Vector size and distance of an existing collection (aliases resolve), or null.
     *
     * @return array{size: int, distance: string}|null
     */
    public function collectionVectors(string $collection): ?array
    {
        $response = $this->request('GET', '/collections/' . rawurlencode($collection));
        if (!$response['ok']) {
            return null;
        }
        $config = $response['json']['result']['config'] ?? [];
        $vectors = $config['params']['vectors'] ?? $config['vectors'] ?? null;
        if (!is_array($vectors) || !isset($vectors['size'])) {
            return ['size' => 0, 'distance' => ''];
        }

        return ['size' => (int) $vectors['size'], 'distance' => (string) ($vectors['distance'] ?? '')];
    }

    public function ensureCollection(string $collection, int $dimension): void
    {
        $cacheKey = $this->cacheKey($collection) . '|' . $dimension . '|' . $this->distance;
        if (isset(self::$knownCollections[$cacheKey])) {
            return;
        }
        $existing = $this->collectionVectors($collection);
        if ($existing !== null) {
            // V-02: a collection built for a model of another size would reject every point;
            // fail once with a clear code instead (distance is fixed per collection by Qdrant).
            if ($existing['size'] > 0 && $existing['size'] !== $dimension) {
                throw new RuntimeException('RAG_VECTOR_COLLECTION_MISMATCH');
            }
            self::$knownCollections[$cacheKey] = true;
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
        self::$knownCollections[$cacheKey] = true;
    }

    private function cacheKey(string $collection): string
    {
        return (is_object($this->transport) ? (string) spl_object_id($this->transport) : 'http') . '|' . $collection;
    }

    private function forget(string $collection): void
    {
        $prefix = $this->cacheKey($collection) . '|';
        foreach (array_keys(self::$knownCollections) as $key) {
            if (str_starts_with($key, $prefix) || $key === $this->cacheKey($collection)) {
                unset(self::$knownCollections[$key]);
            }
        }
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
        $key = $this->cacheKey($collection);
        if (isset(self::$knownCollections[$key]) || $this->request('GET', '/collections/' . rawurlencode($collection))['ok']) {
            self::$knownCollections[$key] = true;
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
        self::$knownCollections[$key] = true;
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
