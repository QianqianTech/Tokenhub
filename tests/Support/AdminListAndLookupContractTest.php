<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\LookupService;
use TokenHub\Services\PermissionService;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/AdminController.php') ?: '';
$apiKeys = file_get_contents(__DIR__ . '/../../app/Services/ApiKeyService.php') ?: '';
$logs = file_get_contents(__DIR__ . '/../../app/Services/LogService.php') ?: '';
$auth = file_get_contents(__DIR__ . '/../../app/Services/AuthService.php') ?: '';

// Pagination totals for generic admin lists (UI-002).
assert_true(str_contains($controller, "(string) (\$request->query['with_total'] ?? '') === '1'"), 'Generic admin lists should report totals only when asked, to avoid an extra COUNT on lookups.');
assert_true(str_contains($controller, "withHeader('X-Total-Count', (string) \$service->count(\$table, \$request->query))"), 'Generic admin lists should expose the total row count in X-Total-Count.');
assert_true(str_contains($logs, 'public function queryPage(array $filters): array') && str_contains($logs, "'total' => count(\$rows)"), 'Log queries should support offset pagination with a total.');
assert_true(str_contains($controller, "\$page = (new LogService())->queryPage(\$filters);"), 'The log list endpoint should page through results.');

// Lookup endpoint for searchable pickers (UI-003).
assert_true(str_contains($kernel, "\$router->get('/admin-api/lookups/{type}', [\$admin, 'lookup'], [new AdminAuthMiddleware()]);"), 'Admin lookups should be routed behind admin authentication.');
$permissions = new PermissionService();
$request = static fn (string $method, string $path): Request => new Request($method, $path, [], [], [], '', new RequestContext('req_test', 'trc_test'));
foreach ([
    'users' => 'users.read',
    'departments' => 'departments.read',
    'api-keys' => 'api_keys.read',
    'models' => 'api_catalog.read',
    'providers' => 'api_catalog.read',
    'quota-policies' => 'quotas.read',
    'rag-knowledge-bases' => 'rag.read',
] as $type => $capability) {
    assert_same($capability, LookupService::capabilityFor($type), "Lookup type {$type} should require {$capability}.");
    assert_same($capability, $permissions->capabilityForAdminRequest($request('GET', "/admin-api/lookups/{$type}")), "Lookup route for {$type} should require {$capability}.");
}
assert_same('unknown', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/lookups/admin_sessions')), 'Unknown lookup types must fail closed.');
$lookupSource = file_get_contents(__DIR__ . '/../../app/Services/LookupService.php') ?: '';
assert_true(str_contains($lookupSource, 'tableScopeSql($actor, $table)'), 'Lookups must apply the same department scope as list endpoints.');
assert_true(!str_contains($lookupSource, 'password_hash') && !str_contains($lookupSource, 'key_hash'), 'Lookups must never select credential columns.');
assert_true(str_contains($lookupSource, "ESCAPE '\\\\\\\\'"), 'Lookup keyword search should escape LIKE wildcards.');

// Atomic API key update (UI-022).
assert_true(str_contains($apiKeys, "App::db()->transaction(function () use (\$id, \$updates, \$modelIds, \$scopeChange): void {"), 'API key attributes and model permissions should be saved in one transaction.');

// Session renewal (UI-026).
assert_true(str_contains($kernel, "\$router->post('/admin-api/session/renew', [\$admin, 'renewSession'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);"), 'Session renewal should require authentication and CSRF.');
assert_same(null, $permissions->capabilityForAdminRequest($request('POST', '/admin-api/session/renew')), 'Any signed-in administrator may renew their own session.');
assert_true(str_contains($auth, 'expires_at > NOW()') && str_contains($auth, 'public function renew(string $session): ?string'), 'Only active sessions can be renewed.');
assert_true(str_contains($controller, "'session_expires_at' => (new AuthService())->sessionExpiresAt("), 'The admin profile should report when the session expires.');
