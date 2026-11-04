<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Middleware\AdminAuthMiddleware;
use TokenHub\Middleware\CsrfMiddleware;
use TokenHub\Services\AdminUserService;
use TokenHub\Services\AppDeviceAuthService;
use TokenHub\Services\AuthService;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Support\Response;

// TokenHub APP sign-in end to end against the database: one-time link,
// exchange, bearer access, rotation with grace period, lost-response recovery,
// replay detection, revocation cascades and housekeeping.

$db = App::db();
$service = new AppDeviceAuthService();
$suffix = bin2hex(random_bytes(4));
$departmentId = 0;
$userId = 0;
$superId = 0;
$ua = 'TokenHubAppTest/1.0 (iPhone)';

$expectAbort = static function (callable $call, string $code, string $message): void {
    try {
        $call();
    } catch (HttpAbortException $abort) {
        assert_same($code, $abort->errorCode, $message);
        return;
    }
    throw new RuntimeException($message . ' (no error raised)');
};
$bearerRequest = static function (string $token, string $method = 'GET', string $path = '/admin-api/me'): Request {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    return new Request($method, $path, $_SERVER, [], [], '', new RequestContext('req_app_test', 'trc_app_test'));
};
$pass = static fn (): Response => Response::ok(['passed' => true]);
$link = static function (array $actor) use ($service): string {
    App::context()->setAdminUser($actor);
    $created = $service->createLink($actor, ['note' => 'test'], '127.0.0.1', 'https://tokenhub.test');
    App::context()->setAdminUser(null);
    return (string) $created['code'];
};

try {
    $db->execute('INSERT INTO departments (parent_id, name, path, level, sort_order, status) VALUES (NULL, ?, ?, 0, 0, "active")', ['APP Test ' . $suffix, '/app-test-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status, admin_type, admin_scope) VALUES (?, ?, ?, "admin", "active", "admin", "departments")',
        [$departmentId, 'app_admin_' . $suffix, password_hash('App-Test-Password-123', PASSWORD_DEFAULT)]
    );
    $userId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status, admin_type, admin_scope) VALUES (?, ?, ?, "super_admin", "active", "super_admin", "global")',
        [$departmentId, 'app_super_' . $suffix, password_hash('App-Test-Password-123', PASSWORD_DEFAULT)]
    );
    $superId = (int) $db->pdo()->lastInsertId();
    $actor = (new AuthService())->publicUser($db->first('SELECT * FROM users WHERE id = ?', [$userId]));
    $super = (new AuthService())->publicUser($db->first('SELECT * FROM users WHERE id = ?', [$superId]));

    // ------------------------------------------------------------ link + exchange
    App::context()->setAdminUser($actor);
    $created = $service->createLink($actor, ['note' => '我的 iPhone'], '127.0.0.1', 'https://tokenhub.test');
    App::context()->setAdminUser(null);
    assert_true(str_starts_with((string) $created['code'], 'thl_'), 'Link codes carry the thl_ prefix.');
    assert_same('https://tokenhub.test/app/link#c=' . $created['code'], $created['link'], 'The code travels in the URL fragment, never in the path or query.');
    $stored = $db->first('SELECT code_hash FROM admin_app_link_codes WHERE id = ?', [$created['id']]);
    assert_true($stored !== null && !str_contains((string) $stored['code_hash'], substr((string) $created['code'], 4)), 'Only a hash of the link code is stored.');

    $first = $service->exchange((string) $created['code'], ['platform' => 'ios'], 'pwa', '10.1.1.1', $ua, true);
    assert_true(str_starts_with((string) $first['refresh_token'], 'thr_'), 'Exchange returns a device credential.');
    assert_true(str_starts_with((string) $first['access']['access_token'], 'tha_'), 'Exchange returns a bearer access token.');
    assert_same('我的 iPhone', $first['device']['name'], 'The link note names the device when the APP sends no name.');
    assert_same(false, $first['insecure_transport'], 'HTTPS exchange is not flagged as insecure.');
    $deviceId = (int) $first['device']['id'];
    $expectAbort(fn () => $service->exchange((string) $created['code'], [], 'pwa', '10.1.1.1', $ua, true), 'APP_LINK_INVALID', 'A link code works only once.');

    $expired = $link($actor);
    $db->execute('UPDATE admin_app_link_codes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE code_hash = ?', [\TokenHub\Support\Crypto::hashKey($expired)]);
    $expectAbort(fn () => $service->exchange($expired, [], 'pwa', '10.1.1.1', $ua, true), 'APP_LINK_INVALID', 'An expired link is rejected.');
    $superseded = $link($actor);
    $newest = $link($actor);
    $expectAbort(fn () => $service->exchange($superseded, [], 'pwa', '10.1.1.1', $ua, true), 'APP_LINK_INVALID', 'Generating a new link invalidates the previous unused one.');
    $db->execute('UPDATE admin_app_link_codes SET expires_at = NOW() WHERE code_hash = ?', [\TokenHub\Support\Crypto::hashKey($newest)]);
    $expectAbort(fn () => $service->exchange('thl_not-a-code', [], 'pwa', '10.1.1.1', $ua, true), 'APP_LINK_INVALID', 'Malformed codes are rejected.');

    // ---------------------------------------------------------- bearer access
    $access = (string) $first['access']['access_token'];
    $resolved = $service->accessSession($access);
    assert_same($userId, (int) ($resolved['user']['id'] ?? 0), 'The access token resolves to its administrator.');
    assert_same($deviceId, (int) ($resolved['session']['device_id'] ?? 0), 'The access token resolves to its device.');

    $_COOKIE['tokenhub_admin_session'] = 'adm_ignored_' . $suffix;
    $request = $bearerRequest($access);
    $response = (new AdminAuthMiddleware())($request, $pass);
    assert_same(200, $response->status, 'A valid bearer token authenticates the admin API.');
    assert_same('app', App::context()->adminChannel(), 'Bearer requests run on the app channel.');
    $csrf = (new CsrfMiddleware())($bearerRequest($access, 'POST', '/admin-api/my/app-devices/revoke-all'), $pass);
    assert_same(200, $csrf->status, 'Bearer requests accepted by AdminAuthMiddleware need no CSRF token.');
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $cookieOnly = (new CsrfMiddleware())(new Request('POST', '/admin-api/my/app-devices/revoke-all', $_SERVER, [], [], '', new RequestContext('r', 't')), $pass);
    assert_same(419, $cookieOnly->status, 'Without the accepted bearer token, CSRF is enforced.');
    assert_same(null, (new AuthService())->userFromSession($access), 'An APP access token is not a console cookie session.');
    unset($_COOKIE['tokenhub_admin_session']);

    // ------------------------------------------------- rotation and recovery
    $r1 = (string) $first['refresh_token'];
    $second = $service->refresh($r1, '10.1.1.2', $ua);
    $r2 = (string) $second['refresh_token'];
    assert_true($r2 !== '' && $r2 !== $r1, 'Refresh rotates the device credential.');
    $parallel = $service->refresh($r1, '10.1.1.2', $ua);
    assert_same(null, $parallel['refresh_token'], 'Within the grace period the previous credential yields an access token but no new credential.');
    assert_true(str_starts_with((string) $parallel['access']['access_token'], 'tha_'), 'Grace-period refresh still signs the APP in.');

    // The APP was killed mid-refresh and never stored r2: the same client recovers.
    $db->execute('UPDATE admin_app_devices SET prev_valid_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$deviceId]);
    $recovered = $service->refresh($r1, '10.1.1.3', $ua);
    $r3 = (string) $recovered['refresh_token'];
    assert_true(str_starts_with($r3, 'thr_'), 'A client that lost the rotation response recovers without signing in again.');
    assert_same(true, $recovered['recovered'] ?? null, 'A recovered refresh is flagged so the APP can tell the user once.');
    assert_true(!isset($second['recovered']), 'An ordinary rotation is not flagged as recovered.');
    $expectAbort(fn () => $service->refresh($r2, '10.1.1.3', $ua), 'APP_DEVICE_INVALID', 'The orphaned credential of the lost response is no longer valid.');
    $r4 = (string) $service->refresh($r3, '10.1.1.3', $ua)['refresh_token'];

    // A copied credential replayed by different client software revokes the device.
    $db->execute('UPDATE admin_app_devices SET prev_valid_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$deviceId]);
    $latestAccess = (string) $service->refresh($r4, '10.1.1.3', $ua)['access']['access_token'];
    $db->execute('UPDATE admin_app_devices SET prev_valid_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$deviceId]);
    // Same User-Agent, but from another network than the last refresh (SEC-005).
    $expectAbort(fn () => $service->refresh($r4, '203.0.113.9', $ua), 'APP_DEVICE_INVALID', 'A replayed credential from another network is rejected even with the same User-Agent.');
    assert_same('refresh_reuse', $db->first('SELECT revoke_reason FROM admin_app_devices WHERE id = ?', [$deviceId])['revoke_reason'] ?? null, 'Replay revokes the device.');
    assert_same(null, $service->accessSession($latestAccess), 'Access tokens of a revoked device stop working.');

    // ---------------------------------------------------- revocation cascades
    $device2 = $service->exchange($link($actor), ['platform' => 'android', 'name' => 'Pixel'], 'native', '10.2.2.2', $ua, false);
    assert_same(true, $device2['insecure_transport'], 'An HTTP exchange is flagged as insecure.');
    $row2 = $db->first('SELECT bound_over_http, DATEDIFF(idle_expires_at, NOW()) AS idle_days FROM admin_app_devices WHERE id = ?', [$device2['device']['id']]);
    assert_same(1, (int) $row2['bound_over_http'], 'HTTP-linked devices are marked.');
    assert_true((int) $row2['idle_days'] <= 7, 'HTTP-linked devices sign out after the shorter idle period.');

    (new AdminUserService())->revokeSessions($userId, false);
    assert_same(null, $service->accessSession((string) $device2['access']['access_token']), 'Revoking console sessions ends current APP access tokens.');
    $refreshed = $service->refresh((string) $device2['refresh_token'], '10.2.2.2', $ua);
    assert_true(str_starts_with((string) $refreshed['access']['access_token'], 'tha_'), 'A profile update keeps the device signed in; it silently gets a fresh token.');

    App::context()->setAdminUser($super);
    (new AdminUserService())->resetPassword($userId, 'App-Test-Password-456');
    App::context()->setAdminUser(null);
    $expectAbort(fn () => $service->refresh((string) $refreshed['refresh_token'], '10.2.2.2', $ua), 'APP_DEVICE_INVALID', 'A password reset signs every APP device out.');

    $device3 = $service->exchange($link($actor), [], 'pwa', '10.3.3.3', $ua, true);
    $db->execute('UPDATE users SET status = "disabled" WHERE id = ?', [$userId]);
    assert_same(null, $service->accessSession((string) $device3['access']['access_token']), 'A disabled administrator cannot use APP access tokens.');
    $expectAbort(fn () => $service->refresh((string) $device3['refresh_token'], '10.3.3.3', $ua), 'APP_DEVICE_INVALID', 'A disabled administrator cannot refresh.');
    assert_same('revoked', $db->first('SELECT status FROM admin_app_devices WHERE id = ?', [$device3['device']['id']])['status'] ?? null, 'Refreshing for a disabled administrator revokes the device.');
    $db->execute('UPDATE users SET status = "active" WHERE id = ?', [$userId]);

    // ------------------------------------------------ limits and housekeeping
    $max = max(1, (int) App::config()->get('app.app_client.max_devices_per_user', 5));
    $devices = [];
    for ($i = 0; $i < $max; $i++) {
        $devices[] = $service->exchange($link($actor), ['name' => 'Device ' . $i], 'pwa', '10.4.4.4', $ua, true);
    }
    App::context()->setAdminUser($actor);
    $expectAbort(fn () => $service->createLink($actor, [], '127.0.0.1'), 'APP_DEVICE_LIMIT', 'No new link once the device limit is reached.');
    App::context()->setAdminUser(null);
    assert_same($max, count($service->listDevices($userId)), 'The device list shows the active devices.');
    $service->revokeOwnDevice($userId, (int) $devices[0]['device']['id']);
    assert_same($max - 1, count($service->listDevices($userId)), 'An owner can revoke one of their devices.');
    $expectAbort(fn () => $service->revokeOwnDevice($superId, (int) $devices[1]['device']['id']), 'ADMIN_NOT_FOUND', 'Administrators cannot revoke other administrators\' devices.');

    $db->execute('UPDATE admin_app_devices SET idle_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$devices[1]['device']['id']]);
    $expectAbort(fn () => $service->refresh((string) $devices[1]['refresh_token'], '10.4.4.4', $ua), 'APP_DEVICE_EXPIRED', 'An idle device must sign in again.');
    $db->execute('UPDATE admin_app_devices SET idle_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$devices[2]['device']['id']]);
    $cleanup = $service->cleanup();
    assert_true($cleanup['devices_expired'] >= 1, 'Housekeeping expires idle devices.');
    assert_same('expired', $db->first('SELECT status FROM admin_app_devices WHERE id = ?', [$devices[2]['device']['id']])['status'] ?? null, 'Idle devices are marked expired.');

    App::context()->setAdminUser($super);
    (new AdminUserService())->revokeSessions($userId);
    App::context()->setAdminUser(null);
    assert_same(0, count($service->listDevices($userId)), '"吊销全部会话" signs out every APP device.');
} finally {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_COOKIE['tokenhub_admin_session']);
    App::context()->setAdminAuth(null);
    App::context()->setAdminUser(null);
    foreach ([$userId, $superId] as $id) {
        if ($id > 0) {
            $db->execute('DELETE FROM admin_sessions WHERE user_id = ?', [$id]);
            $db->execute('DELETE FROM admin_app_devices WHERE user_id = ?', [$id]);
            $db->execute('DELETE FROM admin_app_link_codes WHERE user_id = ?', [$id]);
            $db->execute('DELETE FROM users WHERE id = ?', [$id]);
        }
    }
    if ($departmentId > 0) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
    }
}
