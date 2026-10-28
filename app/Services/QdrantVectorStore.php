<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use TokenHub\Support\App;

final class QdrantVectorStore implements RagVectorStoreInterface
{
    /** @param null|callable(string, string, array<string, mixed>|null): array{ok: bool, status: int, body: string, json: array<string, mixed>|null} $transport */
    public function __construct(private readonly mixed $transport = null)
    {
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
        $response = $this->request('POST', '/collections/' . rawurlencode($collection) . '/points/delete?wait=true', [
            'points' => array_values(array_map(static fn (string $vectorId): string => (new RagVectorContractService())->pointId($vectorId), $vectorIds)),
        ]);
        if ($response['status'] === 404 || $this->isIdempotentDeleteMiss($response)) {
            return;
        }
        if (!$response['ok']) {
            throw new RuntimeException('Qdrant delete failed: HTTP ' . $response['status'] . ' ' . substr($response['body'], 0, 200));
        }
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
                'distance' => 'Cosine',
            ],
        ]);
        if (!$created['ok']) {
            throw new RuntimeException($this->writeErrorCode($created));
        }
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
