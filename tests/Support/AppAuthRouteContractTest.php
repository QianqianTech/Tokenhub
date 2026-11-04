<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Bootstrap\Kernel;
use TokenHub\Middleware\AdminAuthMiddleware;
use TokenHub\Services\PermissionService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Support\Response;

// TokenHub APP sign-in: routes, capability mapping and the cookie/bearer split
// (static and database-free; the flow itself is covered by AppDeviceAuthServiceTest).
$root = dirname(__DIR__, 2);

$routes = array_map(static fn (array $route): string => $route['method'] . ' ' . $route['path'], Kernel::router()->routes());
foreach ([
    'POST /admin-api/app-auth/exchange',
    'POST /admin-api/app-auth/refresh',
    'POST /admin-api/app-auth/logout',
    'GET /admin-api/my/app-devices',
    'POST /admin-api/my/app-devices/links',
    'POST /admin-api/my/app-devices/revoke-all',
    'DELETE /admin-api/my/app-devices/{id}',
    'GET /admin-api/app-auth/status',
    'POST /admin-api/app-auth/pin',
    'POST /admin-api/app-auth/step-up',
    'GET /admin-api/app-auth/push/key',
    'POST /admin-api/app-auth/push/subscription',
    'DELETE /admin-api/app-auth/push/subscription',
    'GET /admin-api/app-auth/ca.crt',
    'GET /admin-api/app-auth/ca.mobileconfig',
    'GET /admin-api/app-auth/stream',
    'POST /admin-api/app-auth/step-up/passkey',
    'POST /admin-api/app-auth/passkeys/options',
    'POST /admin-api/app-auth/passkeys',
    'DELETE /admin-api/app-auth/passkeys',
    'PUT /admin-api/app-auth/push/preferences',
    'POST /admin-api/app-auth/push/native',
    'POST /admin-api/my/app-devices/{id}/approve',
    'POST /admin-api/my/app-devices/{id}/reject',
    'POST /admin-api/admin-users/{id}/app-devices/{device}/approve',
    'POST /admin-api/admin-users/{id}/app-devices/{device}/reject',
    'GET /admin-api/admin-users/{id}/app-devices',
    'POST /admin-api/admin-users/{id}/app-devices/links',
    'POST /admin-api/admin-users/{id}/app-devices/revoke-all',
    'DELETE /admin-api/admin-users/{id}/app-devices/{device}',
] as $route) {
    assert_true(in_array($route, $routes, true), 'APP sign-in route should be registered: ' . $route);
}

$kernel = (string) file_get_contents($root . '/app/Bootstrap/Kernel.php');
assert_true(str_contains($kernel, "\$router->post('/admin-api/app-auth/exchange', [\$appAuth, 'exchange']);"), 'Link exchange is public (the link code is the credential).');
assert_true(str_contains($kernel, "\$router->post('/admin-api/app-auth/refresh', [\$appAuth, 'refresh']);"), 'Refresh is public (the device credential is the credential).');
foreach (['logout', 'createMyLink', 'revokeAllMyDevices', 'revokeMyDevice', 'setPin', 'stepUp', 'pushSubscribe', 'pushUnsubscribe', 'pushNative', 'approveMyDevice', 'rejectMyDevice', 'approveAdminDevice', 'rejectAdminDevice'] as $handler) {
    assert_true(preg_match('/\[\$appAuth, \'' . $handler . '\'\], \[new AdminAuthMiddleware\(\), new CsrfMiddleware\(\)\]/', $kernel) === 1, 'APP write route ' . $handler . ' should require admin authentication and CSRF (bearer requests are exempt inside CsrfMiddleware).');
}

$controller = (string) file_get_contents($root . '/app/Http/Controllers/AppAuthController.php');
foreach (['approveMyDevice', 'rejectMyDevice', 'approveAdminDevice', 'rejectAdminDevice'] as $handler) {
    $body = substr($controller, (int) strpos($controller, 'public function ' . $handler . '('), 600);
    assert_true(str_contains($body, "adminChannel() !== 'web'") && str_contains($body, 'APP_APPROVAL_CONSOLE_ONLY'), 'New devices are approved in the web console only, never from another APP device: ' . $handler);
}
assert_true(str_contains($controller, 'function withoutApprovalCodes'), 'Match codes are only listed in the web console.');
$service = (string) file_get_contents($root . '/app/Services/AppDeviceAuthService.php');
assert_true(str_contains($service, "'APP_DEVICE_REJECTED'") && str_contains($service, "'APP_DEVICE_APPROVAL_EXPIRED'"), 'A pending device learns whether it was rejected or timed out.');
assert_true(preg_match("/APP_DEVICE_PENDING', 'This device is waiting for approval in the web console', 409/", $controller) === 1, 'Refresh of a pending device answers 409 APP_DEVICE_PENDING without clearing the credential.');

$permissions = new PermissionService();
$request = static fn (string $method, string $path): Request => new Request($method, $path, [], [], [], '', new RequestContext('req_test', 'trc_test'));
foreach (['/admin-api/app-auth/logout', '/admin-api/my/app-devices', '/admin-api/my/app-devices/links', '/admin-api/my/app-devices/7'] as $path) {
    assert_same(null, $permissions->capabilityForAdminRequest($request('POST', $path)), 'Every administrator may manage their own APP devices: ' . $path);
}

// Cookie sessions and APP bearer tokens never stand in for each other.
$auth = (string) file_get_contents($root . '/app/Services/AuthService.php');
assert_true(str_contains($auth, 's.channel = "web"'), 'Console cookie sessions must only resolve web-channel sessions, never APP access tokens.');
$appService = (string) file_get_contents($root . '/app/Services/AppDeviceAuthService.php');
assert_true(str_contains($appService, 's.channel = "app"'), 'Bearer access tokens must only resolve APP-channel sessions.');
assert_true(str_contains($appService, 'dv.status = "active"'), 'Bearer access tokens must die with their device.');
assert_true(str_contains($appService, 'Crypto::hashKey($code)') && str_contains($appService, 'Crypto::hashKey($refresh)') && str_contains($appService, 'Crypto::hashKey($token)'), 'Only HMAC hashes of link codes, device credentials and access tokens are stored.');
$csrf = (string) file_get_contents($root . '/app/Middleware/CsrfMiddleware.php');
assert_true(str_contains($csrf, 'hash_equals') && str_contains($csrf, "'app'"), 'CSRF is skipped only for the exact bearer token AdminAuthMiddleware accepted.');

// A malformed or unknown bearer token is rejected before any cookie is considered.
$_COOKIE['tokenhub_admin_session'] = 'adm_should_not_be_used';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-an-app-token';
$bearerRequest = new Request('GET', '/admin-api/me', $_SERVER, [], [], '', new RequestContext('req_test', 'trc_test'));
$called = false;
$response = (new AdminAuthMiddleware())($bearerRequest, static function () use (&$called): Response {
    $called = true;
    return Response::ok();
});
assert_same(401, $response->status, 'An invalid bearer token must be rejected.');
assert_true(!$called, 'An invalid bearer token must not fall back to the console cookie.');
unset($_SERVER['HTTP_AUTHORIZATION'], $_COOKIE['tokenhub_admin_session']);
App::context()->setAdminAuth(null);
App::context()->setAdminUser(null);

// Schema and configuration.
$migration = (string) file_get_contents($root . '/database/migrations/2026_10_31_000001_admin_app_devices.sql');
foreach (['CREATE TABLE IF NOT EXISTS admin_app_link_codes', 'CREATE TABLE IF NOT EXISTS admin_app_devices', "column_name = 'channel'", "column_name = 'device_id'", "column_name = 'step_up_at'", 'prev_refresh_hash', 'bound_over_http'] as $needle) {
    assert_true(str_contains($migration, $needle), 'APP device migration should contain ' . $needle . '.');
}
$config = require $root . '/config/app.php';
foreach (['link_ttl_minutes' => 10, 'access_ttl_minutes' => 60, 'device_idle_days' => 30, 'http_device_idle_days' => 7, 'device_max_days' => 180, 'max_devices_per_user' => 5] as $key => $default) {
    assert_true(array_key_exists($key, $config['app_client'] ?? []), 'APP client setting should exist: ' . $key);
}
$scheduler = (string) file_get_contents($root . '/bin/scheduler.php');
$worker = (string) file_get_contents($root . '/app/Services/WorkerService.php');
assert_true(str_contains($scheduler, "'cleanup.app-devices'") && str_contains($worker, "'cleanup.app-devices'"), 'Expired APP devices should be cleaned up by the scheduler.');

// Console: "APP 设备" entry, local QR code, no innerHTML.
$index = (string) file_get_contents($root . '/public/admin/index.html');
assert_true(str_contains($index, 'id="app-devices"'), 'The console sidebar should offer the APP devices entry.');
$page = (string) file_get_contents($root . '/public/admin/assets/js/pages/app-devices.js');
$qr = (string) file_get_contents($root . '/public/admin/assets/js/lib/qrcode.js');
assert_true(str_contains($page, 'qrSvgElement') && str_contains($page, '"/admin-api/my/app-devices"') && str_contains($page, '${base}/links'), 'The APP devices dialog should generate links and draw their QR code locally.');
assert_true(preg_match('#https?://(?!www\.w3\.org/2000/svg)#', $qr) !== 1, 'The QR code generator must not call any external service.');
assert_true(!str_contains($page . $qr, 'innerHTML'), 'The APP devices dialog must build its DOM without innerHTML.');
