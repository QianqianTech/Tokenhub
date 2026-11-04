<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * Vector hardening (2026-11-03 analysis, V-01 .. V-15) on SQLite + fake Qdrant:
 *  - rebuilding one dataset in a collection shared with other datasets keeps theirs (V-01);
 *  - configs that already indexed vectors cannot silently change model / dimension /
 *    distance / store / collection / document prefix, be disabled, or be swapped under a
 *    dataset; collections are not shared across models; Milvus/pgvector are refused (V-02, V-06);
 *  - an existing collection of another size fails once with a clear code (V-02);
 *  - several vectors of one chunk count once in RRF with their best score (V-03);
 *  - a failing embedding model is reported as vector_status instead of silently ignored (V-04);
 *  - Euclid distances become similarities (V-05);
 *  - reindex writes points in batches (V-08);
 *  - the probe reports the model's real dimension; the document prefix is part of reuse (V-15).
 */

use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagIndexMaintenanceService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Services\RagUploadService;
use TokenHub\Services\RagVectorConfigService;

$pdo = rag_sqlite_fixture();
$qdrant = new RagFakeQdrant();
$requests = [];
$transport = static function (string $method, string $path, ?array $body = null) use ($qdrant, &$requests): array {
    $requests[] = $method . ' ' . $path;
    return $qdrant($method, $path, $body);
};
RagVectorConfigService::useQdrantFactory(static fn (): QdrantVectorStore => new QdrantVectorStore($transport));
$storage = sys_get_temp_dir() . '/rag-hardening-' . bin2hex(random_bytes(4));
putenv('STORAGE_PATH=' . $storage);
$_ENV['STORAGE_PATH'] = $storage;

$pdo->exec("INSERT INTO departments (id, name, path) VALUES (1, 'D', '/1/')");
$pdo->exec("INSERT INTO users (id, department_id, username) VALUES (10, 1, 'u')");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (100, 10, 1, 'k')");
$pdo->exec("INSERT INTO providers (id, name, protocol, base_url, status) VALUES (1, 'p', 'openai', 'http://x', 'active')");
$pdo->exec("INSERT INTO models (id, provider_id, name, external_name, capability, status) VALUES (1, 1, 'emb-a', 'emb-a', 'embedding', 'active'), (2, 1, 'emb-b', 'emb-b', 'embedding', 'active')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility) VALUES (1, 'kb_1', 'KB', 1, 'private')");
$pdo->exec("INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (1, 'user', 10, 'write')");
$pdo->exec("INSERT INTO rag_vector_configs (id, config_id, name, modality, provider_id, model_id, model_name, dimension, distance_metric, vector_store, collection_name, batch_size, enabled) VALUES (1, 'cfg', 'text', 'text', 1, 1, 'emb-a', 4, 'cosine', 'qdrant', 'col_text', 16, 1)");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name, text_vector_config_id) VALUES (1, 'ds_1', 1, 'DS1', 1), (2, 'ds_2', 1, 'DS2', 1)");

$failEmbedding = false;
$embedder = new RagEmbeddingProviderService(static function (string $modality, array $input) use (&$failEmbedding): array {
    if ($failEmbedding) {
        throw new RuntimeException('PROVIDER_TIMEOUT');
    }
    $hash = crc32((string) $input['text']);
    return [1.0, ($hash % 97) / 97, ($hash % 89) / 89, 0.5];
});
$jobs = new RagEmbeddingJobService($embedder, static function (array $message): void {
});
$uploads = new RagUploadService($jobs);
$apiKey = ['id' => 100, 'user_id' => 10, 'department_id' => 1];
$dataset = static fn (string $id): array => $pdo->query("SELECT d.*, k.kb_id AS external_kb_id, k.retrieval_profile AS kb_retrieval_profile FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.dataset_id = '{$id}'")->fetch();
$index = static function (string $datasetId, string $file, string $markdown) use ($uploads, $jobs, $apiKey, $dataset, $pdo): void {
    $upload = $uploads->upload($apiKey, 'kb_1', ['filename' => $file, 'content_base64' => base64_encode($markdown), 'processing' => ['target_dataset_id' => $datasetId]]);
    $uploads->confirm($apiKey, (string) $upload['upload_id'], ['dataset_id' => $datasetId]);
    $jobs->processUpload((int) $pdo->query("SELECT id FROM rag_uploads WHERE upload_id = '{$upload['upload_id']}'")->fetchColumn(), $dataset($datasetId));
};
$index('ds_1', 'cooling.md', "# 冷却手册\n\n## 冷却\n\n温度超过 80 度时停机检查冷却单元。\n");
$index('ds_2', 'travel.md', "# 差旅制度\n\n## 报销\n\n差旅报销须在返回后 15 日内完成。\n");
$retrieval = new RagRetrievalService($embedder);
$chunksOf = static fn (string $id, string $query): array => array_column($retrieval->searchSourcesForDatasets([$dataset($id)], $query, 5), 'chunk_id');
assert_true($chunksOf('ds_2', '差旅报销 15 日') !== [], 'ds_2 is searchable before the rebuild.');
assert_same(1, (int) $pdo->query("SELECT chunk_count FROM rag_vector_datasets WHERE id = 2")->fetchColumn(), 'chunk_count counts the dataset, not the whole knowledge base (V-15).');

// ------------------------------------------------------------------ V-01
$report = (new RagIndexMaintenanceService($jobs))->reindex(1, null, true);
assert_same('in_place', $report['mode'], 'A collection shared with ds_2 is rebuilt in place.');
assert_same(['ds_2'], $report['shared_with'], 'The report names the datasets sharing the collection.');
assert_true($report['switched'], 'The rebuild completes.');
assert_same([], $qdrant->aliases, 'No alias swap on a shared collection.');
assert_true($chunksOf('ds_2', '差旅报销 15 日') !== [], 'ds_2 keeps its vectors after ds_1 is rebuilt (V-01).');
assert_true($chunksOf('ds_1', '冷却单元 停机') !== [], 'ds_1 is searchable after its rebuild.');
assert_same(2, count($qdrant->collections['col_text']['points']), 'The shared collection still holds both datasets, without the temporary copy.');
assert_same(['col_text', 'col_text__lexical'], array_keys($qdrant->collections), 'The temporary rebuild collections were removed.');
// A stale point of ds_1 (no longer produced by the rebuild) is removed; ds_2's points are untouched.
(new QdrantVectorStore($transport))->upsert('col_text', 'vec_text_chk_gone', [1.0, 0.0, 0.0, 0.0], ['dataset_id' => 'ds_1', 'chunk_id' => 'chk_gone', 'modality' => 'text']);
$report = (new RagIndexMaintenanceService($jobs))->reindex(1, null, true);
assert_same(1, $report['stale_deleted'], 'Points of the rebuilt dataset that no longer exist are deleted.');
assert_same(2, count($qdrant->collections['col_text']['points']), 'Only the stale point was removed.');
// Rebuild into a collection that already holds another model's vectors is refused.
$pdo->exec("INSERT INTO rag_vector_configs (id, config_id, name, modality, provider_id, model_id, model_name, dimension, distance_metric, vector_store, collection_name, enabled) VALUES (3, 'cfg_b_same_col', 'b', 'text', 1, 2, 'emb-b', 4, 'cosine', 'qdrant', 'col_text', 1)");
try {
    (new RagIndexMaintenanceService($jobs))->reindex(1, 3, true);
    throw new RuntimeException('Reindex into a collection used by another model must fail.');
} catch (RuntimeException $exception) {
    assert_true(str_starts_with($exception->getMessage(), 'RAG_VECTOR_COLLECTION_IN_USE'), 'Mixing models in one collection is refused (V-01).');
}
$pdo->exec('DELETE FROM rag_vector_configs WHERE id = 3');

// ------------------------------------------------------------------ V-02 / V-06
$configs = new RagVectorConfigService();
$renamed = $configs->normalizeForWrite(['name' => 'renamed'], false, 1);
assert_same('emb-a', $renamed['model_name'], 'A harmless edit of a config in use is allowed.');
foreach ([['model_id' => 2], ['dimension' => 8], ['distance_metric' => 'dot'], ['collection_name' => 'col_other'], ['vector_store' => 'mysql_fallback'], ['config_json' => '{"document_prefix": "passage: "}'], ['enabled' => 0]] as $change) {
    rag_expect_abort(static fn () => $configs->normalizeForWrite($change, false, 1), 'RAG_VECTOR_CONFIG_IN_USE', 'Changing ' . array_key_first($change) . ' on a config with vectors is refused (V-02).');
}
rag_expect_abort(static fn () => $configs->normalizeForWrite(['config_id' => 'x', 'name' => 'x', 'modality' => 'text', 'provider_id' => 1, 'model_id' => 2, 'dimension' => 8, 'distance_metric' => 'cosine', 'vector_store' => 'qdrant', 'collection_name' => 'col_text']), 'RAG_VECTOR_COLLECTION_IN_USE', 'A new config cannot reuse a collection of another model (V-02).');
$created = $configs->normalizeForWrite(['config_id' => 'y', 'name' => 'y', 'modality' => 'text', 'provider_id' => 1, 'model_id' => 1, 'dimension' => 4, 'distance_metric' => 'cosine', 'vector_store' => 'qdrant', 'collection_name' => 'col_text']);
assert_same(1, $created['model_id'], 'A second config of the same model may share the collection.');
rag_expect_abort(static fn () => $configs->normalizeForWrite(['config_id' => 'z', 'name' => 'z', 'modality' => 'text', 'provider_id' => 1, 'model_id' => 2, 'dimension' => 4, 'distance_metric' => 'cosine', 'vector_store' => 'milvus', 'collection_name' => 'col_m']), 'RAG_VECTOR_STORE_UNSUPPORTED', 'Milvus is refused instead of silently using MySQL (V-06).');
$pdo->exec("INSERT INTO rag_vector_configs (id, config_id, name, modality, provider_id, model_id, model_name, dimension, distance_metric, vector_store, collection_name, enabled) VALUES (2, 'cfg_b', 'b', 'text', 1, 2, 'emb-b', 4, 'cosine', 'qdrant', 'col_text_b', 1)");
rag_expect_abort(static fn () => $configs->assertBindingReferences('rag_vector_datasets', ['text_vector_config_id' => 2], 1), 'RAG_VECTOR_CONFIG_IN_USE', 'Re-binding a dataset with vectors to another model goes through reindex (V-02).');
$configs->assertBindingReferences('rag_vector_datasets', ['text_vector_config_id' => 1], 1);
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name) VALUES (3, 'ds_empty', 1, 'empty')");
$configs->assertBindingReferences('rag_vector_datasets', ['text_vector_config_id' => 2], 3);

// A collection of another size fails once with a clear code.
$qdrant->collections['col_small'] = ['config' => ['vectors' => ['size' => 3, 'distance' => 'Cosine']], 'points' => [], 'indexes' => []];
try {
    (new QdrantVectorStore($transport))->upsert('col_small', 'vec_x', [1.0, 0.0, 0.0, 0.0], []);
    throw new RuntimeException('A size mismatch must fail.');
} catch (RuntimeException $exception) {
    assert_same('RAG_VECTOR_COLLECTION_MISMATCH', $exception->getMessage(), 'Writing 4-d vectors into a 3-d collection is reported (V-02).');
}

// ------------------------------------------------------------------ V-03
$rrf = new ReflectionMethod(RagRetrievalService::class, 'rrfFuse');
$fused = $rrf->invoke(new RagRetrievalService(), ['vector' => [
    ['chunk_id' => 'qa', 'modality' => 'text', 'score' => 0.91, 'raw_score' => 0.91],
    ['chunk_id' => 'doc', 'modality' => 'text', 'score' => 0.80, 'raw_score' => 0.80],
    ['chunk_id' => 'qa', 'modality' => 'text', 'score' => 0.30, 'raw_score' => 0.30],
], 'lexical' => []]);
$byChunk = array_column($fused, null, 'chunk_id');
assert_same(0.91, $byChunk['qa']['rank_signals']['vector_score'], 'A chunk keeps its best vector score (V-03).');
assert_same(1, $byChunk['qa']['rank_signals']['vector_rank'], 'A chunk keeps its best rank.');
assert_same(2, $byChunk['doc']['rank_signals']['vector_rank'], 'Ranks count distinct chunks.');
assert_true(abs($byChunk['qa']['score'] - 1 / 61) < 1e-9, 'A chunk is counted once in RRF.');

// ------------------------------------------------------------------ V-04
$retrieval->searchSourcesForDatasets([$dataset('ds_1')], '冷却单元', 5);
assert_same('ok', $retrieval->lastVectorStatus()['status'], 'Healthy vector search reports ok.');
$failEmbedding = true;
$sources = $retrieval->searchSourcesForDatasets([$dataset('ds_1')], '冷却单元', 5);
$status = $retrieval->lastVectorStatus();
assert_same('error', $status['status'], 'A failing embedding model is reported, not ignored (V-04).');
assert_same(['PROVIDER_TIMEOUT'], $status['errors'], 'The error is kept for the admin trial page.');
assert_true($sources !== [] && $sources[0]['retrieval_strategy'] === 'lexical', 'Keyword retrieval still answers.');
$failEmbedding = false;

// ------------------------------------------------------------------ V-05
$euclid = (new QdrantVectorStore(static fn (string $method, string $path): array => ['ok' => true, 'status' => 200, 'body' => '', 'json' => ['result' => [
    ['id' => 'a', 'score' => 0.0, 'payload' => ['vector_id' => 'near']],
    ['id' => 'b', 'score' => 3.0, 'payload' => ['vector_id' => 'far']],
]]]))->forConfig(['distance_metric' => 'euclidean'])->search('c', [1.0], 2);
assert_same([1.0, 0.25], array_column($euclid, 'score'), 'Euclid distances become similarities: closer is higher (V-05).');

// ------------------------------------------------------------------ V-08
for ($i = 0; $i < 6; $i++) {
    $index('ds_2', "doc{$i}.md", "# 文档{$i}\n\n## 第{$i}节\n\n内容编号 {$i}，用于批量写入测试。\n");
}
$requests = [];
(new RagIndexMaintenanceService($jobs))->reindex(2, null, true);
$pointWrites = count(array_filter($requests, static fn (string $r): bool => str_starts_with($r, 'PUT ') && str_contains($r, '/points')));
assert_same(4, $pointWrites, 'Reindex writes the candidate and the live copy in one dense + one sparse batch each (V-08).');

// ------------------------------------------------------------------ V-15
$probe = (new RagVectorConfigService(null, $embedder))->probe(['config_id' => 'p', 'name' => 'p', 'modality' => 'text', 'provider_id' => 1, 'model_id' => 1, 'dimension' => 1024, 'distance_metric' => 'cosine', 'vector_store' => 'qdrant', 'collection_name' => 'col_probe']);
assert_same(4, $probe['dimension'], 'The probe reports the real dimension.');
assert_same(false, $probe['dimension_matches'], 'A wrong configured dimension is flagged.');
$hash = new ReflectionMethod(RagEmbeddingJobService::class, 'inputHash');
$plain = $hash->invoke($jobs, ['model_id' => 1, 'dimension' => 4], ['text' => 'x']);
assert_same($plain, $hash->invoke($jobs, ['model_id' => 1, 'dimension' => 4, 'config_json' => '{}'], ['text' => 'x']), 'Without a prefix the reuse hash is unchanged (existing vectors stay reusable).');
assert_true($plain !== $hash->invoke($jobs, ['model_id' => 1, 'dimension' => 4, 'config_json' => '{"document_prefix": "passage: "}'], ['text' => 'x']), 'The document prefix is part of the reuse hash.');

echo "PASS\n";
