<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$settings = file_get_contents(__DIR__ . '/../../app/Services/SystemSettingsService.php') ?: '';
$uploads = file_get_contents(__DIR__ . '/../../app/Services/RagUploadService.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/RagController.php') ?: '';
$migration = file_get_contents(__DIR__ . '/../../database/migrations/2026_07_09_000008_rag_upload_review.sql') ?: '';
$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$table = admin_js_bundle() ?: '';

assert_true(str_contains($settings, "'rag_upload_review' => 'rag_upload_review'"), 'Settings center should expose rag_upload_review section.');
assert_true(str_contains($settings, "'enabled' => false"), 'RAG upload review should default to disabled.');
assert_true(str_contains($settings, 'reviewer_roles'), 'RAG upload review settings should normalize reviewer roles.');

foreach (['review_status', 'reviewed_by_user_id', 'reviewed_at', 'review_comment'] as $column) {
    assert_true(str_contains($migration, $column), 'RAG upload review migration should include ' . $column . '.');
}

assert_true(str_contains($uploads, 'review_status = "pending"'), 'Upload service should move uploads into pending review when enabled.');
assert_true(str_contains($uploads, 'RAG_UPLOAD_REVIEW_REQUIRED'), 'Upload confirm should reject vectorization until review approval.');
assert_true(str_contains($uploads, 'reviewUpload'), 'Upload service should expose admin review action.');
assert_true(str_contains($controller, 'reviewUpload'), 'RAG controller should expose reviewUpload.');
assert_true(str_contains($kernel, "post('/admin-api/rag/uploads/{upload_id}/review'"), 'Kernel should register admin upload review route.');
assert_true(str_contains($kernel, 'new AdminAuthMiddleware()'), 'Admin review route should use admin middleware.');

assert_true(str_contains($resources, '"settings-rag-upload-review"'), 'Admin resources should expose RAG upload review settings page.');
assert_true(str_contains($table, 'rag_upload_review'), 'Admin settings renderer should include RAG upload review section.');
assert_true(str_contains($table, 'reviewer_roles'), 'Admin settings page should expose reviewer roles.');
