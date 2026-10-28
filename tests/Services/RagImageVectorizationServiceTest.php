<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\MysqlFallbackVectorStore;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_vec_' . $suffix;
$uploadId = 'upl_vec_' . $suffix;
$datasetId = 'ds_vec_' . $suffix;

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Vector KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, "Text Config", "text", "text-embedding", 3, "mysql_fallback", ?, 1)', ['cfg_text_' . $suffix, 'vec_text_' . $suffix]);
$textConfigPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, "Image Config", "image", "image-embedding", 3, "mysql_fallback", ?, 1)', ['cfg_image_' . $suffix, 'vec_image_' . $suffix]);
$imageConfigPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, "Multimodal Config", "multimodal", "mm-embedding", 3, "mysql_fallback", ?, 1)', ['cfg_mm_' . $suffix, 'vec_mm_' . $suffix]);
$mmConfigPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, image_vector_config_id, multimodal_vector_config_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Vector DS ' . $suffix, 'vec_' . $suffix, $textConfigPk, $imageConfigPk, $mmConfigPk]);
$dataset = $db->first('SELECT d.*, k.kb_id AS external_kb_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.dataset_id = ?', [$datasetId]);
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "image", ?, ?, "confirmed", "queued", "vectorizing")', [$uploadId, $kbPk, $dataset['id'], str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Image Doc", "image", "parsed", "indexing")', ['doc_vec_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_image_assets (image_id, kb_id, upload_id, original_filename, mime_type, width, height, size_bytes, sha256, storage_path, thumbnail_path, purpose, short_description, detailed_description, ocr_text, labels, warnings, metadata) VALUES (?, ?, ?, "e03.png", "image/png", 1, 1, 10, ?, ?, ?, "fault", "screen shows E03", "red indicator", "E03", ?, ?, ?)', ['img_vec_' . $suffix, $kbPk, $uploadPk, str_repeat('c', 64), __FILE__, __FILE__, json_encode(['device', 'E03'], JSON_UNESCAPED_UNICODE), json_encode([], JSON_UNESCAPED_UNICODE), json_encode(['ai_annotation' => ['short_description' => 'AI caption']], JSON_UNESCAPED_UNICODE)]);
$imagePk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, image_asset_id, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "image", 1, "Image Chunk", "human text", ?, ?, ?, 1, "active")', ['chk_vec_' . $suffix, $kbPk, $documentPk, $imagePk, json_encode(['source_uri' => 'manual://e03'], JSON_UNESCAPED_UNICODE), str_repeat('d', 64)]);

$provider = new RagEmbeddingProviderService(static function (string $modality, array $input, array $config): array {
    return match ($modality) {
        'image' => [0.0, 1.0, 0.0],
        'multimodal' => [0.0, 0.0, 1.0],
        default => [1.0, 0.0, 0.0],
    };
});
$service = new RagEmbeddingJobService($provider);
$service->queueUpload($uploadPk, $dataset);
$service->processUpload($uploadPk, $dataset);

$queuedJobs = $db->select('SELECT job_type, execution_backend FROM rag_embedding_jobs WHERE upload_id = ? ORDER BY job_type', [$uploadPk]);
$queuedJobTypes = array_column($queuedJobs, 'job_type');
sort($queuedJobTypes);
assert_same(['image_embedding', 'multimodal_embedding', 'text_embedding'], $queuedJobTypes, 'Image vectorization should create per-modality embedding jobs.');
assert_same(['python_local', 'python_local', 'python_local'], array_column($queuedJobs, 'execution_backend'), 'Unconfigured image, multimodal, and text embeddings should remain Python-local owned jobs.');
assert_same(0, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_vector_records WHERE dataset_id = ?', [$dataset['id']])['c'] ?? 0), 'PHP provider worker must not consume Python-local jobs.');

$chunk = $db->first('SELECT * FROM rag_chunks WHERE document_id = ?', [$documentPk]);
$configs = [
    'text' => $db->first('SELECT * FROM rag_vector_configs WHERE id = ?', [$textConfigPk]),
    'image' => $db->first('SELECT * FROM rag_vector_configs WHERE id = ?', [$imageConfigPk]),
    'multimodal' => $db->first('SELECT * FROM rag_vector_configs WHERE id = ?', [$mmConfigPk]),
];
foreach ($configs as $modality => $config) {
    $service->indexChunk(new MysqlFallbackVectorStore(), (string) $config['collection_name'], $config, $dataset, $chunk, $modality);
}

$modalities = array_column($db->select('SELECT modality FROM rag_vector_records WHERE dataset_id = ? ORDER BY modality', [$dataset['id']]), 'modality');
sort($modalities);
assert_same(['image', 'multimodal', 'text'], $modalities, 'Image chunks should create text, image, and multimodal vector records.');
