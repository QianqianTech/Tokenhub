<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/RagController.php') ?: '';
$uploads = file_get_contents(__DIR__ . '/../../app/Services/RagUploadService.php') ?: '';
$permissions = (file_get_contents(__DIR__ . '/../../app/Services/RagPermissionService.php') ?: '') . (file_get_contents(__DIR__ . '/../../app/Services/RagAccessResolver.php') ?: '');

$adminUploadStart = strpos($controller, 'public function adminUpload');
$adminUploadEnd = strpos($controller, 'public function upload', $adminUploadStart);
assert_true($adminUploadStart !== false && $adminUploadEnd !== false, 'RAG controller adminUpload method should be inspectable.');
$adminUploadSource = substr($controller, $adminUploadStart, $adminUploadEnd - $adminUploadStart);

assert_true(str_contains($adminUploadSource, 'department_id'), 'Admin RAG upload should require a department_id.');
assert_true(!str_contains($adminUploadSource, 'api_key_id is required for admin RAG upload'), 'Admin RAG upload should not require api_key_id.');
assert_true(!str_contains($adminUploadSource, 'SELECT * FROM api_keys'), 'Admin RAG upload should not look up an API key to impersonate.');
assert_true(str_contains($adminUploadSource, 'adminUploadForDepartment'), 'Admin RAG upload should delegate to a department-scoped upload path.');
foreach (['adminUploadStatus', 'reviewUpload', 'adminPreview', 'adminConfirmVectorization', 'reprocessImage'] as $method) {
    assert_true(str_contains($controller, 'assertAdminUploadScope') || str_contains($controller, 'assertAdminImageScope'), 'Admin RAG management methods should enforce department scope.');
}

assert_true(str_contains($uploads, 'adminUploadForDepartment'), 'RAG upload service should expose a department-scoped admin upload method.');
assert_true(str_contains($uploads, 'RAG_DEPARTMENT_REQUIRED'), 'RAG upload service should validate admin upload department_id.');
assert_true(str_contains($uploads, 'api_key_id IS NULL'), 'RAG upload service should support admin uploads without api_key_id.');
assert_true(str_contains($uploads, 'adminFindById'), 'RAG upload service should expose an admin-scoped upload lookup path.');
assert_true(str_contains($uploads, 'assertAdminCanAccessUpload'), 'RAG upload service should enforce admin upload department scope.');

assert_true(str_contains($permissions, "'department'"), 'RAG permission subjects should support department bindings.');
assert_true(str_contains($permissions, '$apiKey[\'department_id\']'), 'RAG permission resolution should include department_id from the caller subject.');
