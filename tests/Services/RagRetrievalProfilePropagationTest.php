<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagAdminTrialService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagRetrievalEvaluationService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$query = 'calibration alpha';
$queryVector = (new RagEmbeddingProviderService())->embed('text', ['text' => $query, 'modality' => 'text'], [
    'dimension' => 3,
    'config_json' => json_encode(['allow_local_fallback' => true]),
]);
$fixtures = [];
foreach ([['low', 0.10], ['high', 0.90]] as [$label, $threshold]) {
    $kbId = 'kb_hybrid_profile_' . $label . '_' . $suffix;
    $datasetId = 'ds_hybrid_profile_' . $label . '_' . $suffix;
    $profile = json_encode(['min_hybrid_confidence' => $threshold]);
    $db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status, retrieval_profile) VALUES (?, ?, "active", ?)', [$kbId, 'Profile ' . $label . ' KB ' . $suffix, $profile]);
    $kbPk = (int) $db->pdo()->lastInsertId();
    $collection = 'profile_' . $label . '_' . $suffix;
    $db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_hybrid_profile_' . $label . '_' . $suffix, 'Profile ' . $label . ' Config', $collection, json_encode(['allow_local_fallback' => true])]);
    $configPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Profile ' . $label . ' Dataset', 'profile_' . $label . '_' . $suffix, $configPk]);
    $datasetPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_hybrid_profile_' . $label . '_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
    $uploadPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, ?, "plain_text", "parsed", "indexed")', ['doc_hybrid_profile_' . $label . '_' . $suffix, $kbPk, $uploadPk, 'Profile ' . $label . ' document']);
    $documentPk = (int) $db->pdo()->lastInsertId();
    $chunkId = 'chk_hybrid_profile_' . $label . '_' . $suffix;
    $db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, ?, ?, "{}", ?, 1, "active")', [$chunkId, $kbPk, $documentPk, 'calibration alpha ' . $label, 'calibration alpha instructions ' . $label, hash('sha256', $chunkId)]);
    $chunkPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_' . $chunkId, $collection, $kbPk, $datasetPk, $chunkPk, json_encode($queryVector), hash('sha256', 'vec_' . $chunkId)]);
    $db->execute('INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, query_modality, expected_chunk_ids, status, metadata) VALUES (?, ?, ?, ?, "text", ?, "active", "{}")', ['eval_hybrid_profile_' . $label . '_' . $suffix, $kbPk, $datasetPk, $query, json_encode([$chunkId])]);
    $fixtures[] = ['dataset_id' => $datasetId, 'threshold' => $threshold];
}

$trial = new RagAdminTrialService();
foreach ($fixtures as $fixture) {
    $result = $trial->retrieve(['role' => 'super_admin'], ['dataset_id' => $fixture['dataset_id'], 'query' => $query, 'top_k' => 1]);
    assert_same($fixture['threshold'], (float) ($result['sources'][0]['rank_signals']['min_hybrid_confidence'] ?? -1), 'Admin trial must use its dataset KB retrieval profile.');
    assert_same('kb_override', $result['sources'][0]['rank_signals']['threshold_profile'] ?? null, 'Admin trial must label the KB profile override.');
}

$run = (new RagRetrievalEvaluationService())->run(['dataset_ids' => array_column($fixtures, 'dataset_id'), 'top_k_values' => [1]]);
assert_same(2, $run['case_count'], 'Evaluation must run cases from both profile datasets.');
$rows = $db->select('SELECT sources FROM rag_eval_results WHERE run_id = (SELECT id FROM rag_eval_runs WHERE run_id = ?) ORDER BY id', [$run['run_id']]);
$observedThresholds = [];
foreach ($rows as $row) {
    $sources = json_decode((string) $row['sources'], true) ?: [];
    $observedThresholds[] = (float) ($sources[0]['rank_signals']['min_hybrid_confidence'] ?? -1);
}
sort($observedThresholds);
assert_same([0.1, 0.9], $observedThresholds, 'Evaluation must carry each dataset KB retrieval profile into shared retrieval.');
