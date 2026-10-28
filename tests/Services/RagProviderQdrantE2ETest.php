<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Services\RagUploadService;
use TokenHub\Services\WorkerService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$providerId = null;
$modelId = null;
$collection = 'rag_p0_provider_e2e_text';
$vectorId = null;

try {
    assert_true((new QdrantVectorStore())->healthy(), 'Qdrant must be healthy for the provider-backed RAG E2E test.');

    $db->execute('INSERT INTO providers (name, protocol, base_url, encrypted_secret, status) VALUES (?, "openai", "https://embedding.example/v1", "", "active")', ['RAG E2E Provider ' . $suffix]);
    $providerId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO models (provider_id, name, external_name, capability, status) VALUES (?, ?, ?, "embedding", "active")', [$providerId, 'rag-e2e-' . $suffix, 'rag-e2e-ext-' . $suffix]);
    $modelId = (int) $db->pdo()->lastInsertId();

    $kbId = 'kb_e2e_' . $suffix;
    $datasetId = 'ds_e2e_' . $suffix;
    $apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
    $db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, 1, "active")', [$kbId, 'E2E KB ' . $suffix]);
    $kbPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, provider_id, model_id, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, ?, "text", ?, ?, ?, 3, "qdrant", ?, 1)', ['cfg_e2e_' . $suffix, 'E2E provider config', $providerId, $modelId, 'rag-e2e-ext-' . $suffix, $collection]);
    $configPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'E2E DS ' . $suffix, 'e2e_' . $suffix, $configPk]);
    $datasetPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "write")', [$kbPk, $apiKey['id']]);
    $db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "write", 1)', [$datasetPk, $apiKey['id']]);

    $uploadId = 'upl_e2e_' . $suffix;
    $db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, owner_user_id, department_id, api_key_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status, confirm_required, review_status) VALUES (?, ?, ?, ?, 1, ?, "api", "text", ?, ?, "ready", "not_started", "waiting_confirm", 1, "not_required")', [$uploadId, $kbPk, $datasetPk, $apiKey['user_id'], $apiKey['id'], str_repeat('a', 64), str_repeat('b', 64)]);
    $uploadPk = (int) $db->pdo()->lastInsertId();
    $content = 'Thermal overload alarm E77 requires cooling inspection and reset.';
    $db->execute('INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors, metadata) VALUES (?, ?, ?, ?, "text", "E77 thermal alarm", ?, "valid", "[]", "{}")', ['pvi_e2e_' . $suffix, $uploadPk, $kbPk, $datasetPk, $content]);

    $requester = static fn (array $route, string $path, array $payload): array => [
        'ok' => true,
        'status' => 200,
        'body' => '',
        'json' => ['data' => [['embedding' => [1.0, 0.0, 0.0]]]],
    ];
    $provider = new RagEmbeddingProviderService(null, $requester);
    $embeddingJobs = new RagEmbeddingJobService($provider, static function (array $message): void {});
    $upload = new RagUploadService($embeddingJobs);
    $upload->confirm($apiKey, $uploadId);

    (new WorkerService(null, $embeddingJobs))->handle(['type' => 'rag.vectorize', 'upload_id' => $uploadId]);
    $dataset = $db->first('SELECT d.*, k.kb_id AS external_kb_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = ?', [$datasetPk]);
    $chunk = $db->first('SELECT c.chunk_id FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?', [$uploadPk]);
    $vectorId = 'vec_text_' . (string) ($chunk['chunk_id'] ?? '');

    $sources = (new RagRetrievalService($provider))->searchSourcesForDatasets([$dataset], 'overheat err 77', 5);
    assert_true($sources !== [], 'Provider-backed Qdrant retrieval should return indexed sources.');
    assert_same($chunk['chunk_id'], $sources[0]['chunk_id'] ?? null, 'Provider-backed Qdrant retrieval should return the indexed external chunk ID.');
    assert_same($datasetId, $sources[0]['dataset_id'] ?? null, 'Provider-backed Qdrant retrieval should preserve the external dataset ID.');
    assert_same($kbId, $sources[0]['kb_id'] ?? null, 'Production worker processing should preserve the external knowledge-base ID in Qdrant payloads.');
    assert_true(isset($sources[0]['rank_signals']['vector_rank']), 'Provider-backed Qdrant retrieval should contribute a vector rank signal.');
} finally {
    if ($vectorId !== null) {
        (new QdrantVectorStore())->delete($collection, [$vectorId]);
    }
    if ($modelId !== null) {
        $db->execute('DELETE FROM models WHERE id = ?', [$modelId]);
    }
    if ($providerId !== null) {
        $db->execute('DELETE FROM providers WHERE id = ?', [$providerId]);
    }
}
