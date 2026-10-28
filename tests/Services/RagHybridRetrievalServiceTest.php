<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_hybrid_' . $suffix;
$datasetId = 'ds_hybrid_' . $suffix;

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Hybrid KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, "Hybrid Text Config", "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_hybrid_' . $suffix, 'hybrid_text_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$configPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Hybrid DS ' . $suffix, 'hybrid_' . $suffix, $configPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_hybrid_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Hybrid Doc", "plain_text", "parsed", "indexed")', ['doc_hybrid_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();

$query = 'reset thermal overload e77';
$queryVector = (new RagEmbeddingProviderService())->embed('text', ['text' => $query], [
    'dimension' => 3,
    'config_json' => json_encode(['allow_local_fallback' => true]),
]);
$chunks = [
    ['chk_hybrid_vector_' . $suffix, 'Vector only', 'unrelated warranty registration text', $queryVector],
    ['chk_hybrid_exact_' . $suffix, 'Exact E77 reset', 'Reset thermal overload E77 by holding the red breaker for ten seconds.', [0, 0, 0]],
    ['chk_hybrid_zdup_' . $suffix, 'Duplicate E77 reset', 'Reset thermal overload E77 by holding the red breaker for ten seconds.', [0, 0, 0]],
    ['chk_hybrid_distinct_' . $suffix, 'E77 inspection', 'After reset, inspect the thermal overload wiring harness and cooling fan.', [0, 0, 0]],
];

foreach ($chunks as [$chunkId, $title, $content, $vector]) {
    $db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, ?, ?, "{}", ?, 1, "active")', [$chunkId, $kbPk, $documentPk, $title, $content, hash('sha256', $chunkId)]);
    $chunkPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_' . $chunkId, 'hybrid_text_' . $suffix, $kbPk, $datasetPk, $chunkPk, json_encode($vector), hash('sha256', 'vec_' . $chunkId)]);
}

$sources = (new RagRetrievalService())->searchSourcesForDatasets([
    [
        'dataset_id' => $datasetId,
        'name' => 'Hybrid DS ' . $suffix,
        'external_kb_id' => $kbId,
        'text_vector_config_id' => $configPk,
    ],
], $query, 2, 'text');

assert_same('chk_hybrid_exact_' . $suffix, $sources[0]['chunk_id'], 'Hybrid retrieval should rank lexical exact matches above vector-only false positives.');
assert_true(($sources[0]['rank_signals']['lexical_rank'] ?? null) !== null, 'Hybrid source should expose lexical rank signal.');
$chunkIds = array_map(static fn (array $source): string => (string) $source['chunk_id'], $sources);
assert_true(in_array('chk_hybrid_distinct_' . $suffix, $chunkIds, true), 'MMR should keep a distinct supporting source in topK.');
assert_true(!in_array('chk_hybrid_zdup_' . $suffix, $chunkIds, true), 'MMR should suppress near-duplicate chunks when a distinct source is available.');
