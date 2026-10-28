<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagRetrievalEvaluationService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_eval_' . $suffix;
$datasetId = 'ds_eval_' . $suffix;

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Eval KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, "Eval Text Config", "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_eval_' . $suffix, 'eval_text_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$configPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Eval DS ' . $suffix, 'eval_' . $suffix, $configPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_eval_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Eval Doc", "plain_text", "parsed", "indexed")', ['doc_eval_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, "Alpha", "alpha beta", "{}", ?, 1, "active")', ['chk_eval_hit_' . $suffix, $kbPk, $documentPk, str_repeat('c', 64)]);
$hitChunkPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 2, "Gamma", "gamma delta", "{}", ?, 1, "active")', ['chk_eval_miss_' . $suffix, $kbPk, $documentPk, str_repeat('d', 64)]);
$missChunkPk = (int) $db->pdo()->lastInsertId();
$queryVector = (new RagEmbeddingProviderService())->embed('text', ['text' => 'alpha beta'], [
    'dimension' => 3,
    'config_json' => json_encode(['allow_local_fallback' => true]),
]);
foreach ([[$hitChunkPk, 'chk_eval_hit_' . $suffix, $queryVector], [$missChunkPk, 'chk_eval_miss_' . $suffix, [0, 0, 0]]] as [$chunkPk, $chunkExternalId, $vector]) {
    $db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_' . $chunkExternalId, 'eval_text_' . $suffix, $kbPk, $datasetPk, $chunkPk, json_encode($vector), str_repeat('e', 64)]);
}

$db->execute('INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, query_modality, expected_chunk_ids, status, metadata) VALUES (?, ?, ?, "alpha beta", "text", ?, "active", "{}")', ['recall_hit_' . $suffix, $kbPk, $datasetPk, json_encode(['chk_eval_hit_' . $suffix])]);
$db->execute('INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, query_modality, expected_chunk_ids, status, metadata) VALUES (?, ?, ?, "alpha beta", "text", ?, "active", "{}")', ['recall_miss_' . $suffix, $kbPk, $datasetPk, json_encode(['missing_chunk_' . $suffix])]);

$run = (new RagRetrievalEvaluationService())->run(['dataset_ids' => [$datasetId], 'top_k_values' => [1, 5]]);
assert_same(2, $run['case_count'], 'Evaluation should run two active cases.');
assert_same(50.0, (float) $run['recall_at_1'], 'Recall@1 should count one hit across two cases.');
assert_same(50.0, (float) $run['recall_at_5'], 'Recall@5 should count one hit across two cases.');
assert_same(0.5, (float) $run['mrr'], 'MRR should average rank reciprocal across all cases.');
assert_same(1, $run['failed_cases'], 'Evaluation should record one failed case.');

$results = $db->select('SELECT passed, hit_rank FROM rag_eval_results WHERE run_id = (SELECT id FROM rag_eval_runs WHERE run_id = ?) ORDER BY case_id', [$run['run_id']]);
assert_same(2, count($results), 'Evaluation should persist per-case results.');
