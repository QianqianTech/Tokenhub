<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$kbId = 'kb_hybrid_' . $suffix;
$datasetId = 'ds_hybrid_' . $suffix;
$query = '设备温度过高 E77 如何复位';

$db->execute(
    'INSERT INTO rag_knowledge_bases (kb_id, name, status, retrieval_profile) VALUES (?, ?, "active", ?)',
    [$kbId, 'Chinese Retrieval KB ' . $suffix, json_encode(['min_vector_score' => 0.90])]
);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, "Chinese Text Config", "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_hybrid_cn_' . $suffix, 'hybrid_cn_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$configPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Chinese Retrieval DS ' . $suffix, 'hybrid_cn_' . $suffix, $configPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "read")', [$kbPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "read", 1)', [$datasetPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_hybrid_cn_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Chinese Retrieval Doc", "plain_text", "parsed", "indexed")', ['doc_hybrid_cn_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();

$queryVector = (new RagEmbeddingProviderService())->embed('text', ['text' => $query, 'modality' => 'text'], [
    'dimension' => 3,
    'config_json' => json_encode(['allow_local_fallback' => true]),
]);
$chunks = [
    ['chk_hybrid_cn_expected_' . $suffix, 'E77 温度过高复位', '设备温度过高触发 E77 报警时，先等待冷却，再按住复位键十秒。', $queryVector],
    ['chk_hybrid_cn_vector_noise_' . $suffix, '向量干扰', '采购保修登记和办公用品清单。', [0, 0, 0]],
];
foreach ($chunks as [$chunkId, $title, $content, $vector]) {
    $db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, ?, ?, "{}", ?, 1, "active")', [$chunkId, $kbPk, $documentPk, $title, $content, hash('sha256', $chunkId)]);
    $chunkPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_' . $chunkId, 'hybrid_cn_' . $suffix, $kbPk, $datasetPk, $chunkPk, json_encode($vector), hash('sha256', 'vec_' . $chunkId)]);
}

$result = (new RagRetrievalService())->retrieve($apiKey, [
    'messages' => [['role' => 'user', 'content' => $query]],
    'rag' => ['enabled' => true, 'dataset_ids' => [$datasetId], 'top_k' => 2],
]);

assert_same(false, $result['fallback_used'], 'Authorized Chinese dataset should retrieve normally.');
assert_same('chk_hybrid_cn_expected_' . $suffix, $result['sources'][0]['chunk_id'], 'Chinese and code evidence should rank the correct chunk first.');
assert_true(isset($result['sources'][0]['confidence_score']), 'Retrieved source should expose confidence score.');
assert_same(true, $result['sources'][0]['rank_signals']['exact_phrase_evidence'], 'Analyzer phrase overlap should be recorded as exact phrase evidence.');
$signals = $result['sources'][0]['rank_signals'];
assert_same(true, $signals['exact_phrase_match'] ?? null, 'Stable exact phrase signal should be recorded.');
assert_same(true, $signals['code_match'] ?? null, 'Stable code signal should be recorded.');
assert_true(isset($signals['threshold']), 'Stable threshold signal should be recorded.');
assert_same('kb_override', $signals['threshold_profile'] ?? null, 'Stable threshold profile should identify the KB override.');
assert_same(true, $signals['accepted'] ?? null, 'Returned candidates must record their acceptance result.');
$chunkIds = array_map(static fn (array $source): string => (string) $source['chunk_id'], $result['sources']);
assert_true(!in_array('chk_hybrid_cn_vector_noise_' . $suffix, $chunkIds, true), 'Vector-only distractor below the dataset threshold must be filtered.');
