<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagRetrievalEvaluationService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_hybrid_' . $suffix;
$datasetId = 'ds_hybrid_' . $suffix;
$query = 'pump cavitation alarm c19';

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Hybrid Eval KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, "Hybrid Eval Config", "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_hybrid_eval_' . $suffix, 'hybrid_eval_text_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$configPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Hybrid Eval DS ' . $suffix, 'hybrid_eval_' . $suffix, $configPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_hybrid_eval_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Hybrid Eval Doc", "plain_text", "parsed", "indexed")', ['doc_hybrid_eval_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();
$queryVector = (new RagEmbeddingProviderService())->embed('text', ['text' => $query], [
    'dimension' => 3,
    'config_json' => json_encode(['allow_local_fallback' => true]),
]);

foreach ([
    ['chk_hybrid_eval_vector_' . $suffix, 'Vector distraction', 'unrelated spare parts catalog', $queryVector],
    ['chk_hybrid_eval_expected_' . $suffix, 'Pump C19 fix', 'Pump cavitation alarm C19 means the inlet filter must be cleaned before restart.', [0, 0, 0]],
] as [$chunkId, $title, $content, $vector]) {
    $db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, ?, ?, "{}", ?, 1, "active")', [$chunkId, $kbPk, $documentPk, $title, $content, hash('sha256', $chunkId)]);
    $chunkPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_' . $chunkId, 'hybrid_eval_text_' . $suffix, $kbPk, $datasetPk, $chunkPk, json_encode($vector), hash('sha256', 'vec_' . $chunkId)]);
}

$db->execute('INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, query_modality, expected_chunk_ids, status, metadata) VALUES (?, ?, ?, ?, "text", ?, "active", "{}")', ['hybrid_eval_' . $suffix, $kbPk, $datasetPk, $query, json_encode(['chk_hybrid_eval_expected_' . $suffix])]);

$run = (new RagRetrievalEvaluationService())->run(['dataset_ids' => [$datasetId], 'top_k_values' => [1, 5]]);
assert_same(100.0, (float) $run['recall_at_1'], 'Eval Recall@1 should reflect hybrid lexical rescue.');
assert_same(1.0, (float) $run['mrr'], 'Eval MRR should reflect the hybrid hit at rank 1.');

$result = $db->first('SELECT sources FROM rag_eval_results WHERE run_id = (SELECT id FROM rag_eval_runs WHERE run_id = ?) LIMIT 1', [$run['run_id']]);
$sources = json_decode((string) ($result['sources'] ?? '[]'), true) ?: [];
assert_same('hybrid_rrf', $sources[0]['retrieval_strategy'] ?? null, 'Eval result should persist hybrid RRF retrieval strategy.');
