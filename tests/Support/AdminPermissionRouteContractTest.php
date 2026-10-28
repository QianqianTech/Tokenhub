<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\PermissionService;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

$middleware = file_get_contents(__DIR__ . '/../../app/Middleware/AdminAuthMiddleware.php') ?: '';
assert_true(str_contains($middleware, 'capabilityForAdminRequest'), 'Admin authentication middleware should enforce request capabilities after session authentication.');

$permissions = new PermissionService();
$request = static fn (string $method, string $path): Request => new Request($method, $path, [], [], [], '', new RequestContext('req_test', 'trc_test'));

assert_same('admins.manage', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/admin-users')), 'Administrator account APIs should require administrator management capability.');
assert_same('rag.manage', $permissions->capabilityForAdminRequest($request('POST', '/admin-api/rag/uploads/upload_1/review')), 'RAG write APIs should require RAG management capability.');
assert_same('rag.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/rag-knowledge-bases')), 'Knowledge-base CRUD reads should require RAG read capability.');
assert_same('rag.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/rag-vector-datasets')), 'Dataset CRUD reads should require RAG read capability.');
assert_same('rag.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/rag-uploads')), 'Import-task CRUD reads should require RAG read capability.');
assert_same('rag.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/rag-embedding-jobs')), 'Task-record CRUD reads should require RAG read capability.');
assert_same('rag.manage', $permissions->capabilityForAdminRequest($request('POST', '/admin-api/rag-vector-datasets')), 'Generic RAG CRUD writes should require RAG management capability.');
assert_same('api_catalog.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/providers')), 'Provider reads should require API catalog read capability.');
assert_same('system.manage', $permissions->capabilityForAdminRequest($request('PUT', '/admin-api/system/settings-center/entry_security')), 'System settings writes should require system management capability.');
assert_same('unknown', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/unmapped')), 'Unmapped protected APIs must fail closed.');
