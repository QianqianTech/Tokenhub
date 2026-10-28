<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\RagVectorContractService;
use TokenHub\Services\RagVectorConfigService;

$requests = [];
$businessId = 'vec_text_chk_qdrant_contract';
$pointId = (new RagVectorContractService())->pointId($businessId);
$transport = static function (string $method, string $path, ?array $body) use (&$requests, $businessId, $pointId): array {
    $requests[] = compact('method', 'path', 'body');
    if (str_ends_with($path, '/points/search')) {
        return [
            'ok' => true,
            'status' => 200,
            'body' => '',
            'json' => ['result' => [[
                'id' => $pointId,
                'score' => 0.99,
                'payload' => ['vector_id' => $businessId, 'dataset_id' => 'ds_contract'],
            ]]],
        ];
    }
    return ['ok' => true, 'status' => 200, 'body' => '', 'json' => ['result' => true]];
};

$store = new QdrantVectorStore($transport);
$store->upsert('contract_collection', $businessId, [1.0, 0.0, 0.0], ['dataset_id' => 'ds_contract']);
$hits = $store->search('contract_collection', [1.0, 0.0, 0.0], 1, ['dataset_id' => 'ds_contract']);
$store->delete('contract_collection', [$businessId]);

$upsert = array_values(array_filter($requests, static fn (array $request): bool => str_contains($request['path'], '/points?wait=true')))[0] ?? [];
assert_same($pointId, $upsert['body']['points'][0]['id'] ?? null, 'Qdrant upsert should translate business vector id to UUID point id.');
assert_same($businessId, $upsert['body']['points'][0]['payload']['vector_id'] ?? null, 'Qdrant payload should preserve business vector id.');
assert_same($businessId, $hits[0]['vector_id'] ?? null, 'Qdrant search should expose business vector id instead of UUID point id.');

$delete = array_values(array_filter($requests, static fn (array $request): bool => str_contains($request['path'], '/points/delete')))[0] ?? [];
assert_same([$pointId], $delete['body']['points'] ?? null, 'Qdrant delete should translate business vector ids to UUID point ids.');

$malformedDelete = new QdrantVectorStore(static fn (): array => [
    'ok' => false,
    'status' => 400,
    'body' => 'Invalid points selector: invalid point id',
    'json' => null,
]);
try {
    $malformedDelete->delete('contract_collection', [$businessId]);
    throw new RuntimeException('Expected malformed Qdrant delete to fail.');
} catch (RuntimeException $exception) {
    assert_true(str_starts_with($exception->getMessage(), 'Qdrant delete failed:'), 'Malformed Qdrant delete errors must not be treated as an idempotent miss.');
}

$unhealthyQdrant = new RagVectorConfigService(static fn (): QdrantVectorStore => new QdrantVectorStore(static fn (): array => [
    'ok' => false,
    'status' => 503,
    'body' => 'unavailable',
    'json' => null,
]));
try {
    $unhealthyQdrant->store(['vector_store' => 'qdrant']);
    throw new RuntimeException('Expected an explicitly configured Qdrant store to fail closed.');
} catch (RuntimeException $exception) {
    assert_same('RAG_VECTOR_STORE_UNAVAILABLE', $exception->getMessage(), 'Explicit Qdrant configuration must not silently downgrade to MySQL.');
}

$transientUpsert = new QdrantVectorStore(static function (string $method, string $path): array {
    if ($method === 'GET') {
        return ['ok' => true, 'status' => 200, 'body' => '', 'json' => ['result' => true]];
    }
    return ['ok' => false, 'status' => 503, 'body' => 'temporary outage', 'json' => null];
});
try {
    $transientUpsert->upsert('contract_collection', $businessId, [1.0, 0.0, 0.0], []);
    throw new RuntimeException('Expected transient Qdrant upsert failure.');
} catch (RuntimeException $exception) {
    assert_same('RAG_VECTOR_STORE_UNAVAILABLE', $exception->getMessage(), 'Transient Qdrant writes must map to the retryable vector-store error code.');
}
