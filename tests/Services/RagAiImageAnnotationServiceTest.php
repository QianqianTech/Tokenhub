<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagAiImageAnnotationService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_ai_' . $suffix;
$uploadId = 'upl_ai_' . $suffix;
$imageId = 'img_ai_' . $suffix;

$db->execute('DELETE FROM system_settings WHERE setting_key = "rag_ai_image_processing"');
$db->execute('INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("rag_ai_image_processing", ?, "test rag ai image processing")', [json_encode([
    'enabled' => true,
    'vision_model_id' => 123,
    'prompt_profile' => 'default',
    'max_retries' => 1,
    'timeout_ms' => 1000,
], JSON_UNESCAPED_UNICODE)]);
$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'AI KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, "api", "image", ?, ?, "ready", "not_started", "waiting_confirm")', [$uploadId, $kbPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_image_assets (image_id, kb_id, upload_id, original_filename, mime_type, width, height, size_bytes, sha256, storage_path, thumbnail_path, purpose, short_description, detailed_description, labels, warnings, metadata) VALUES (?, ?, ?, "e03.png", "image/png", 1, 1, 10, ?, ?, ?, "human purpose", "human short", "", ?, ?, ?)',
    [
        $imageId,
        $kbPk,
        $uploadPk,
        str_repeat('c', 64),
        __FILE__,
        __FILE__,
        json_encode(['human_tag'], JSON_UNESCAPED_UNICODE),
        json_encode(['ocr_not_configured', 'caption_model_not_configured'], JSON_UNESCAPED_UNICODE),
        json_encode(['source_uri' => 'manual://e03', 'human_annotation' => ['description' => 'human short']], JSON_UNESCAPED_UNICODE),
    ]
);
$asset = $db->first('SELECT * FROM rag_image_assets WHERE image_id = ?', [$imageId]);

$service = new RagAiImageAnnotationService(static fn (array $asset, array $settings): array => [
    'ocr_text' => 'E03',
    'ai_short_description' => 'AI short should not overwrite human short',
    'ai_detailed_description' => 'Detailed AI explanation',
    'ai_objects' => ['screen', 'error_code'],
    'ai_tags' => ['E03', 'device'],
    'quality_warnings' => ['low_resolution'],
]);
$updated = $service->enrichAsset($asset);

assert_same('E03', $updated['ocr_text'], 'AI annotation should write OCR text.');
assert_same('human short', $updated['short_description'], 'AI short description must not overwrite human short description.');
$metadata = json_decode((string) $updated['metadata'], true) ?: [];
assert_same('completed', $metadata['ai_annotation']['status'], 'AI annotation metadata should mark completed.');
assert_same('AI short should not overwrite human short', $metadata['ai_annotation']['short_description'], 'AI short description should be stored separately.');
assert_same(['screen', 'error_code'], $metadata['ai_annotation']['objects'], 'AI objects should be stored separately.');
$warnings = json_decode((string) $updated['warnings'], true) ?: [];
assert_true(!in_array('ocr_not_configured', $warnings, true), 'Successful AI OCR should remove ocr_not_configured warning.');
assert_true(in_array('low_resolution', $warnings, true), 'AI quality warnings should be preserved.');

$failedImageId = 'img_ai_fail_' . $suffix;
$db->execute(
    'INSERT INTO rag_image_assets (image_id, kb_id, upload_id, original_filename, mime_type, width, height, size_bytes, sha256, storage_path, thumbnail_path, purpose, short_description, detailed_description, labels, warnings, metadata) VALUES (?, ?, ?, "fail.png", "image/png", 1, 1, 10, ?, ?, ?, "human purpose", "human short", "", ?, ?, ?)',
    [
        $failedImageId,
        $kbPk,
        $uploadPk,
        str_repeat('d', 64),
        __FILE__,
        __FILE__,
        json_encode([], JSON_UNESCAPED_UNICODE),
        json_encode([], JSON_UNESCAPED_UNICODE),
        json_encode(['human_annotation' => ['description' => 'human short']], JSON_UNESCAPED_UNICODE),
    ]
);
$failedAsset = $db->first('SELECT * FROM rag_image_assets WHERE image_id = ?', [$failedImageId]);
$failedResult = (new RagAiImageAnnotationService(static fn (): array => throw new RuntimeException('vision down')))->enrichAsset($failedAsset);
$failedWarnings = json_decode((string) $failedResult['warnings'], true) ?: [];
$failedMetadata = json_decode((string) $failedResult['metadata'], true) ?: [];
assert_same('human short', $failedResult['short_description'], 'Failed AI annotation should keep human description.');
assert_true(in_array('RAG_AI_ANNOTATION_FAILED', $failedWarnings, true), 'Failed AI annotation should record warning code.');
assert_same('failed', $failedMetadata['ai_annotation']['status'], 'Failed AI annotation should mark failed status.');
