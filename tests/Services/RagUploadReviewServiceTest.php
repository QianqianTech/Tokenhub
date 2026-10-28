<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagUploadService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$kbId = 'kb_review_' . $suffix;
$datasetId = 'ds_review_' . $suffix;

$db->execute(
    'INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("rag_upload_review", ?, "test rag upload review") ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)',
    [json_encode([
        'enabled' => true,
        'reviewer_roles' => ['admin', 'super_admin'],
        'allow_reviewer_own_upload' => false,
        'reject_blocks_vectorization' => true,
        'instructions' => 'test review',
    ], JSON_UNESCAPED_UNICODE)]
);

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, 1, "active")', [$kbId, 'Review KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Review DS ' . $suffix, 'review_' . $suffix]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "write")', [$kbPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "write", 1)', [$datasetPk, $apiKey['id']]);

$upload = (new RagUploadService())->upload($apiKey, $kbId, [
    'filename' => 'review.csv',
    'content_type' => 'text',
    'content_base64' => base64_encode("title,content\nReview title,Review content\n"),
    'processing' => [
        'template' => 'text',
        'target_dataset_id' => $datasetId,
        'auto_vectorize' => true,
        'confirm_required' => false,
    ],
]);

assert_same('pending', $upload['review_status'], 'Upload should be pending review when review setting is enabled.');
assert_same('waiting_review', $upload['status'], 'Upload should wait for review before confirmation/vectorization.');
assert_same('waiting_review', $upload['stage'], 'Upload stage should show waiting_review.');

$reviewed = (new RagUploadService())->reviewUpload(['id' => $apiKey['user_id'] + 1, 'role' => 'admin', 'department_id' => 1, 'managed_department_ids' => '[1]'], (string) $upload['upload_id'], [
    'decision' => 'approve',
    'comment' => 'ok',
]);

assert_same('approved', $reviewed['review_status'], 'Approved upload should record review status.');
assert_same('waiting_confirm', $reviewed['status'], 'Approved upload should return to waiting confirmation.');

$db->execute('DELETE FROM system_settings WHERE setting_key = "rag_upload_review"');
