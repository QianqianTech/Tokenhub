<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagVectorStoreInterface;
use TokenHub\Services\RagVectorConfigService;
use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\WorkerService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$providerId = null;
$modelId = null;

try {
    $db->execute('INSERT INTO providers (name, protocol, base_url, encrypted_secret, status) VALUES (?, "openai", "https://embedding.example/v1", "", "active")', ['RAG Route Provider ' . $suffix]);
    $providerId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO models (provider_id, name, external_name, capability, status) VALUES (?, ?, ?, "embedding", "active")', [$providerId, 'rag-route-' . $suffix, 'rag-route-ext-' . $suffix]);
    $modelId = (int) $db->pdo()->lastInsertId();

    $kbId = 'kb_route_' . $suffix;
    $db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Route KB ' . $suffix]);
    $kbPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, provider_id, model_id, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, ?, "text", ?, ?, ?, 3, "mysql_fallback", ?, 1)', ['cfg_route_provider_' . $suffix, 'Provider route config', $providerId, $modelId, 'rag-route-ext-' . $suffix, 'route_provider_' . $suffix]);
    $providerConfigPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_route_local_' . $suffix, 'Local route config', 'route_local_' . $suffix, json_encode(['allow_local_fallback' => true])]);
    $localConfigPk = (int) $db->pdo()->lastInsertId();

    $datasets = [];
    foreach ([['provider', $providerConfigPk], ['local', $localConfigPk]] as [$kind, $configPk]) {
        $datasetId = 'ds_route_' . $kind . '_' . $suffix;
        $db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, ucfirst($kind) . ' route dataset', 'route_' . $kind . '_' . $suffix, $configPk]);
        $dataset = $db->first('SELECT *, ? AS external_kb_id FROM rag_vector_datasets WHERE dataset_id = ?', [$kbId, $datasetId]);
        $uploadId = 'upl_route_' . $kind . '_' . $suffix;
        $db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "queued", "vectorizing")', [$uploadId, $kbPk, $dataset['id'], hash('sha256', $uploadId), hash('sha256', 'file-' . $uploadId)]);
        $uploadPk = (int) $db->pdo()->lastInsertId();
        $db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, ?, "plain_text", "parsed", "indexing")', ['doc_route_' . $kind . '_' . $suffix, $kbPk, $uploadPk, ucfirst($kind) . ' doc']);
        $documentPk = (int) $db->pdo()->lastInsertId();
        $db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, ?, ?, "{}", ?, 1, "active")', ['chk_route_' . $kind . '_' . $suffix, $kbPk, $documentPk, ucfirst($kind) . ' chunk', $kind . ' route content', hash('sha256', $kind . ' route content')]);
        $datasets[$kind] = ['upload_pk' => $uploadPk, 'dataset' => $dataset];
    }

    $published = [];
    $publisher = static function (array $job) use (&$published): void {
        $published[] = $job;
    };
    $service = new RagEmbeddingJobService(null, $publisher);
    $service->queueUpload($datasets['provider']['upload_pk'], $datasets['provider']['dataset']);
    $service->queueUpload($datasets['local']['upload_pk'], $datasets['local']['dataset']);

    $providerJob = $db->first('SELECT vector_config_id, execution_backend FROM rag_embedding_jobs WHERE upload_id = ?', [$datasets['provider']['upload_pk']]);
    $localJob = $db->first('SELECT vector_config_id, execution_backend FROM rag_embedding_jobs WHERE upload_id = ?', [$datasets['local']['upload_pk']]);
    assert_same($providerConfigPk, (int) ($providerJob['vector_config_id'] ?? 0), 'Provider job should persist its vector config.');
    assert_same('php_provider', $providerJob['execution_backend'] ?? null, 'Provider-backed configs should route to PHP.');
    assert_same($localConfigPk, (int) ($localJob['vector_config_id'] ?? 0), 'Local job should persist its vector config.');
    assert_same('python_local', $localJob['execution_backend'] ?? null, 'Local fallback configs should route to Python.');
    assert_same(1, count($published), 'Only provider-backed uploads should publish a Redis worker job.');
    assert_same('rag.vectorize', $published[0]['type'] ?? null, 'Provider-backed queue message should use rag.vectorize.');
    assert_same('upl_route_provider_' . $suffix, $published[0]['upload_id'] ?? null, 'Provider-backed queue message should identify the upload.');

    $db->execute('UPDATE rag_embedding_jobs SET status = "running", locked_at = DATE_SUB(NOW(), INTERVAL 2 HOUR), lock_token = ? WHERE upload_id = ?', ['expired-owner', $datasets['provider']['upload_pk']]);
    $recovered = [];
    $recoveredCount = (new WorkerService(static function (array $job) use (&$recovered): void {
        $recovered[] = $job;
    }))->recoverRagProviderJobs();
    assert_true($recoveredCount >= 1, 'Idle worker recovery should discover durable provider jobs even when their Redis message was lost.');
    assert_true(in_array('upl_route_provider_' . $suffix, array_column($recovered, 'upload_id'), true), 'Idle worker recovery should republish the provider upload id.');
    assert_same('retrying', $db->first('SELECT status FROM rag_embedding_jobs WHERE upload_id = ?', [$datasets['provider']['upload_pk']])['status'] ?? null, 'Idle worker recovery should release stale provider job leases.');

    $failingRequester = static fn (array $route, string $path, array $payload): array => [
        'ok' => false,
        'status' => 503,
        'body' => 'temporary outage',
        'json' => null,
        'error_code' => 'PROVIDER_TIMEOUT',
    ];
    $failedProcessor = new RagEmbeddingJobService(new RagEmbeddingProviderService(null, $failingRequester), $publisher);
    $failedProcessor->processUpload($datasets['provider']['upload_pk'], $datasets['provider']['dataset']);
    $retryingJob = $db->first('SELECT status, attempts, error_code, next_run_at FROM rag_embedding_jobs WHERE upload_id = ?', [$datasets['provider']['upload_pk']]);
    assert_same('retrying', $retryingJob['status'] ?? null, 'Transient provider failures should remain retryable.');
    assert_same(1, (int) ($retryingJob['attempts'] ?? 0), 'A transient provider failure should consume one durable job attempt.');
    assert_same('PROVIDER_TIMEOUT', $retryingJob['error_code'] ?? null, 'Provider error codes should be preserved on the durable job.');
    assert_true(!empty($retryingJob['next_run_at']), 'A retryable provider job should persist a future backoff time.');
    assert_same(1, count($published), 'A retryable provider job should wait for its durable backoff instead of immediately republishing.');

    $db->execute('UPDATE rag_embedding_jobs SET next_run_at = DATE_SUB(NOW(), INTERVAL 1 SECOND), dispatched_at = NULL WHERE upload_id = ?', [$datasets['provider']['upload_pk']]);
    $dueRecoveries = [];
    (new WorkerService(static function (array $job) use (&$dueRecoveries): void {
        $dueRecoveries[] = $job;
    }))->recoverRagProviderJobs();
    assert_true(in_array('upl_route_provider_' . $suffix, array_column($dueRecoveries, 'upload_id'), true), 'Due provider retries should be republished by the periodic DB recovery scan.');

    $requester = static fn (array $route, string $path, array $payload): array => [
        'ok' => true,
        'status' => 200,
        'body' => '',
        'json' => ['data' => [['embedding' => [1.0, 0.0, 0.0]]]],
    ];
    $processor = new RagEmbeddingJobService(new RagEmbeddingProviderService(null, $requester), $publisher);
    $processor->processUpload($datasets['provider']['upload_pk'], $datasets['provider']['dataset']);

    $providerJobs = $db->select('SELECT status, execution_backend FROM rag_embedding_jobs WHERE upload_id = ? ORDER BY id', [$datasets['provider']['upload_pk']]);
    assert_same(1, count($providerJobs), 'PHP processing should update the queued provider job instead of inserting a duplicate.');
    assert_same('succeeded', $providerJobs[0]['status'], 'PHP provider job should finish on the existing row.');
    assert_same('php_provider', $providerJobs[0]['execution_backend'], 'PHP provider job should retain worker ownership.');
    $providerVectors = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_vector_records WHERE dataset_id = ?', [$datasets['provider']['dataset']['id']])['c'] ?? 0);
    assert_same(1, $providerVectors, 'PHP provider processing should persist one indexed vector.');
    $providerChunk = $db->first(
        'SELECT c.id FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?',
        [$datasets['provider']['upload_pk']]
    );
    $providerVector = $db->first('SELECT chunk_id FROM rag_vector_records WHERE dataset_id = ?', [$datasets['provider']['dataset']['id']]);
    assert_same((int) ($providerChunk['id'] ?? 0), (int) ($providerVector['chunk_id'] ?? 0), 'Vector records must reference the internal rag_chunks primary key.');

    $processor->processUpload($datasets['provider']['upload_pk'], $datasets['provider']['dataset']);
    $providerJobsAfterRedelivery = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$datasets['provider']['upload_pk']])['c'] ?? 0);
    assert_same(1, $providerJobsAfterRedelivery, 'Redelivering a completed upload must not create another embedding job.');

    $db->execute('UPDATE rag_vector_configs SET vector_store = "qdrant" WHERE id = ?', [$providerConfigPk]);
    $db->execute('UPDATE rag_embedding_jobs SET status = "queued", attempts = 0, next_run_at = NULL, dispatched_at = NULL WHERE upload_id = ?', [$datasets['provider']['upload_pk']]);
    $db->execute('DELETE FROM rag_vector_records WHERE dataset_id = ?', [$datasets['provider']['dataset']['id']]);
    $failingQdrantConfig = new RagVectorConfigService(static fn (): QdrantVectorStore => new QdrantVectorStore(static function (string $method): array {
        return $method === 'GET'
            ? ['ok' => true, 'status' => 200, 'body' => '', 'json' => ['result' => true]]
            : ['ok' => false, 'status' => 503, 'body' => 'temporary qdrant outage', 'json' => null];
    }));
    (new RagEmbeddingJobService(new RagEmbeddingProviderService(null, $requester), $publisher, $failingQdrantConfig))->processUpload($datasets['provider']['upload_pk'], $datasets['provider']['dataset']);
    $qdrantRetry = $db->first('SELECT status, error_code, next_run_at FROM rag_embedding_jobs WHERE upload_id = ?', [$datasets['provider']['upload_pk']]);
    assert_same('retrying', $qdrantRetry['status'] ?? null, 'Transient Qdrant write failures should keep provider jobs retryable.');
    assert_same('RAG_VECTOR_STORE_UNAVAILABLE', $qdrantRetry['error_code'] ?? null, 'Transient Qdrant writes should preserve their retryable error code.');
    assert_true(!empty($qdrantRetry['next_run_at']), 'Transient Qdrant retries should use durable backoff.');

    $localChunk = $db->first(
        'SELECT c.* FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?',
        [$datasets['local']['upload_pk']]
    );
    $localConfig = $db->first('SELECT * FROM rag_vector_configs WHERE id = ?', [$localConfigPk]);
    $failingStore = new class implements RagVectorStoreInterface {
        public function upsert(string $collection, string $vectorId, array $vector, array $payload): void
        {
            throw new RuntimeException('remote write failed');
        }

        public function search(string $collection, array $vector, int $topK, array $filter = []): array
        {
            return [];
        }

        public function delete(string $collection, array $vectorIds): void
        {
        }

        public function healthy(): bool
        {
            return false;
        }

        public function ensureCollection(string $collection, int $dimension): void
        {
        }
    };
    try {
        $processor->indexChunk($failingStore, (string) $localConfig['collection_name'], $localConfig, $datasets['local']['dataset'], $localChunk, 'text');
        throw new RuntimeException('Expected remote vector write to fail.');
    } catch (RuntimeException $exception) {
        assert_same('remote write failed', $exception->getMessage(), 'Remote vector failure should be propagated.');
    }
    $falseIndexed = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_vector_records WHERE dataset_id = ? AND index_status = "indexed"', [$datasets['local']['dataset']['id']])['c'] ?? 0);
    assert_same(0, $falseIndexed, 'A failed remote vector write must not leave an indexed database record.');
} finally {
    if ($modelId !== null) {
        $db->execute('DELETE FROM models WHERE id = ?', [$modelId]);
    }
    if ($providerId !== null) {
        $db->execute('DELETE FROM providers WHERE id = ?', [$providerId]);
    }
}
