<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagRetrievalService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_hybrid_' . $suffix;
$datasetId = 'ds_hybrid_' . $suffix;

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Rewrite KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, "Rewrite Text Config", "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_rewrite_' . $suffix, 'rewrite_text_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$configPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Rewrite DS ' . $suffix, 'rewrite_' . $suffix, $configPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_rewrite_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Rewrite Doc", "plain_text", "parsed", "indexed")', ['doc_rewrite_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();

foreach ([
    ['chk_rewrite_expected_' . $suffix, 'E77 thermal overload alarm', 'Thermal overload alarm E77 requires cooling the motor and checking the fan harness.'],
    ['chk_rewrite_noise_' . $suffix, 'Overheat prevention policy', 'General overheating prevention requires monthly cleaning and warranty registration.'],
] as [$chunkId, $title, $content]) {
    $db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, ?, ?, "{}", ?, 1, "active")', [$chunkId, $kbPk, $documentPk, $title, $content, hash('sha256', $chunkId)]);
    $chunkPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_' . $chunkId, 'rewrite_text_' . $suffix, $kbPk, $datasetPk, $chunkPk, json_encode([0, 0, 0]), hash('sha256', 'vec_' . $chunkId)]);
}

$sources = (new RagRetrievalService())->searchSourcesForDatasets([
    [
        'dataset_id' => $datasetId,
        'name' => 'Rewrite DS ' . $suffix,
        'external_kb_id' => $kbId,
        'text_vector_config_id' => $configPk,
    ],
], 'overheat err 77', 1, 'text');

assert_same('chk_rewrite_expected_' . $suffix, $sources[0]['chunk_id'], 'Query rewrite should map user wording to canonical fault terms.');
assert_true(($sources[0]['rank_signals']['rewrite_terms'] ?? []) !== [], 'Retrieved source should expose rewrite terms used for ranking.');
assert_true((float) ($sources[0]['rank_signals']['rerank_score'] ?? 0) > 0, 'Retrieved source should expose local rerank score.');
