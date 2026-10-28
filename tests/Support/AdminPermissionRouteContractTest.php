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

// Every registered /admin-api route behind the admin session must map to a
// real capability: an 'unknown' mapping denies the route to every admin,
// super admins included, so the page silently breaks.
$public = ['GET /admin-api/health', 'POST /admin-api/login'];
$unmapped = [];
foreach (\TokenHub\Bootstrap\Kernel::router()->routes() as $route) {
    $name = $route['method'] . ' ' . $route['path'];
    if (!str_starts_with($route['path'], '/admin-api/') || in_array($name, $public, true)) {
        continue;
    }
    if ($permissions->capabilityForAdminRequest($request($route['method'], $route['path'])) === 'unknown') {
        $unmapped[] = $name;
    }
}
assert_same([], $unmapped, 'Every protected admin route should map to a capability.');
assert_same('usage.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/diagnostics/requests/req_1')), 'Request diagnostics should follow usage read capability.');
assert_same('content.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/export-tasks')), 'Export task list should follow content read capability.');
assert_same('logs.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/alert-events')), 'Alert events should follow logs read capability.');
