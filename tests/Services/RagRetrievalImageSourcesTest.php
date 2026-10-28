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
$kbId = 'kb_ret_' . $suffix;
$datasetId = 'ds_ret_' . $suffix;

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Retrieval KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, "Text Config", "text", "text-embedding", 3, "mysql_fallback", ?, 1, ?)', ['cfg_ret_text_' . $suffix, 'ret_text_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$textConfigPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, "MM Config", "multimodal", "mm-embedding", 3, "mysql_fallback", ?, 1, ?)', ['cfg_ret_mm_' . $suffix, 'ret_mm_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$mmConfigPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, multimodal_vector_config_id, status) VALUES (?, ?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Retrieval DS ' . $suffix, 'ret_' . $suffix, $textConfigPk, $mmConfigPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "read")', [$kbPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "read", 1)', [$datasetPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "image", ?, ?, "confirmed", "indexed", "indexed")', ['upl_ret_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Image Doc", "image", "parsed", "indexed")', ['doc_ret_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_image_assets (image_id, kb_id, upload_id, original_filename, mime_type, width, height, size_bytes, sha256, storage_path, thumbnail_path, purpose, short_description, detailed_description, ocr_text, labels, warnings, metadata) VALUES (?, ?, ?, "e03.png", "image/png", 1, 1, 10, ?, ?, ?, "fault", "screen shows E03", "red indicator", "E03", ?, ?, ?)', ['img_ret_' . $suffix, $kbPk, $uploadPk, str_repeat('c', 64), __FILE__, __FILE__, json_encode(['device'], JSON_UNESCAPED_UNICODE), json_encode([], JSON_UNESCAPED_UNICODE), json_encode(['source_uri' => 'manual://e03', 'human_annotation' => ['description' => 'human desc'], 'ai_annotation' => ['short_description' => 'ai desc']], JSON_UNESCAPED_UNICODE)]);
$imagePk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, image_asset_id, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "image", 1, "E03 image", "E03 fault screen", ?, ?, ?, 1, "active")', ['chk_ret_' . $suffix, $kbPk, $documentPk, $imagePk, json_encode(['source_uri' => 'manual://e03'], JSON_UNESCAPED_UNICODE), str_repeat('d', 64)]);
$chunkPk = (int) $db->pdo()->lastInsertId();
$query = 'E03 fault';
$multimodalQueryVector = (new RagEmbeddingProviderService())->embed('multimodal', ['text' => $query, 'modality' => 'multimodal'], [
    'dimension' => 3,
    'config_json' => json_encode(['allow_local_fallback' => true]),
]);
foreach ([['text', [1, 0, 0]], ['multimodal', $multimodalQueryVector]] as [$modality, $vector]) {
    $db->execute(
        'INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, image_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 3, ?, ?, "indexed", "indexed", NOW())',
        ['vec_ret_' . $modality . '_' . $suffix, 'ret_' . $modality . '_' . $suffix, $kbPk, $datasetPk, $chunkPk, $imagePk, $modality, json_encode($vector), str_repeat('e', 64)]
    );
}
$db->execute('INSERT INTO rag_image_assets (image_id, kb_id, upload_id, original_filename, mime_type, width, height, size_bytes, sha256, storage_path, thumbnail_path, purpose, short_description, detailed_description, ocr_text, labels, warnings, metadata) VALUES (?, ?, ?, "noise.png", "image/png", 1, 1, 10, ?, ?, ?, "office", "stationery", "unrelated supplies", "", ?, ?, ?)', ['img_ret_noise_' . $suffix, $kbPk, $uploadPk, str_repeat('f', 64), __FILE__, __FILE__, json_encode([], JSON_UNESCAPED_UNICODE), json_encode([], JSON_UNESCAPED_UNICODE), json_encode([], JSON_UNESCAPED_UNICODE)]);
$noiseImagePk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, image_asset_id, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "image", 2, "office image", "stationery list", ?, "{}", ?, 1, "active")', ['chk_ret_noise_' . $suffix, $kbPk, $documentPk, $noiseImagePk, str_repeat('f', 64)]);
$noiseChunkPk = (int) $db->pdo()->lastInsertId();
foreach (['text', 'multimodal'] as $modality) {
    $db->execute(
        'INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, image_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 3, ?, ?, "indexed", "indexed", NOW())',
        ['vec_ret_noise_' . $modality . '_' . $suffix, 'ret_' . $modality . '_' . $suffix, $kbPk, $datasetPk, $noiseChunkPk, $noiseImagePk, $modality, json_encode([0, 0, 0]), str_repeat('f', 64)]
    );
}

$result = (new RagRetrievalService())->retrieve($apiKey, [
    'model' => 'vision-model',
    'messages' => [['role' => 'user', 'content' => $query]],
    'rag' => ['enabled' => true, 'dataset_ids' => [$datasetId], 'top_k' => 2, 'query_modality' => 'multimodal'],
]);

assert_same(false, $result['fallback_used'], 'Retrieval should not fall back for valid indexed data.');
assert_true(count($result['sources']) >= 1, 'Retrieval should return at least one source.');
$source = $result['sources'][0];
assert_same('multimodal', $source['modality'], 'Multimodal retrieval should prefer multimodal image sources.');
assert_same('img_ret_' . $suffix, $source['image_id'], 'Image source should include public image id.');
assert_same('manual://e03', $source['source_uri'], 'Image source should include source URI.');
assert_same('human desc', $source['human_annotation']['description'], 'Image source should include human annotation.');
assert_same('ai desc', $source['ai_annotation']['short_description'], 'Image source should include AI annotation.');
assert_true(!str_contains(json_encode($source, JSON_UNESCAPED_SLASHES), __FILE__), 'RAG sources must not leak local storage paths.');
assert_true((float) ($source['rank_signals']['vector_score'] ?? 99) <= 1.000001, 'Confidence evidence must use the raw vector score, not a multimodal ranking boost.');
$chunkIds = array_map(static fn (array $item): string => (string) $item['chunk_id'], $result['sources']);
assert_true(!in_array('chk_ret_noise_' . $suffix, $chunkIds, true), 'A zero-score image candidate must not bypass confidence thresholds.');
