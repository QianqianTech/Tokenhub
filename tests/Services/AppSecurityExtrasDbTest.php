<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Bootstrap\Kernel;
use TokenHub\Services\AppDeviceAuthService;
use TokenHub\Services\AppPasskeyService;
use TokenHub\Services\AppPushService;
use TokenHub\Services\AuditService;
use TokenHub\Services\AuthService;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

// TokenHub APP second batch against the database: passkey registration and
// step-up (recorded WebAuthn credential), audit channel/device, push rules,
// links for another administrator, device risk signals.

$db = App::db();
$devices = new AppDeviceAuthService();
$suffix = bin2hex(random_bytes(4));
$departmentId = 0;
$superId = 0;
$adminId = 0;
$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';
$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/webauthn-es256.json'), true);
$host = (string) parse_url($fixture['origin'], PHP_URL_HOST) . ':' . (string) parse_url($fixture['origin'], PHP_URL_PORT);
$request = static fn (): Request => new Request('POST', '/admin-api/app-auth/passkeys', ['HTTP_HOST' => $host, 'REMOTE_ADDR' => '127.0.0.1'], [], [], '', new RequestContext('req_pk', 'trc_pk'));

try {
    $db->execute('INSERT INTO departments (parent_id, name, path, level, sort_order, status) VALUES (NULL, ?, ?, 0, 0, "active")', ['APP Extras ' . $suffix, '/app-extras-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    foreach (['super_admin' => 'super', 'admin' => 'admin'] as $role => $name) {
        $db->execute(
            'INSERT INTO users (department_id, username, password_hash, role, status, admin_type, admin_scope) VALUES (?, ?, ?, ?, "active", ?, "global")',
            [$departmentId, 'app_x_' . $name . '_' . $suffix, password_hash('App-Extras-Password-1', PASSWORD_DEFAULT), $role, $role === 'admin' ? 'admin' : 'super_admin']
        );
        ${$name . 'Id'} = (int) $db->pdo()->lastInsertId();
    }
    $super = (new AuthService())->publicUser($db->first('SELECT * FROM users WHERE id = ?', [$superId]));
    $admin = (new AuthService())->publicUser($db->first('SELECT * FROM users WHERE id = ?', [$adminId]));

    // ------------------------------------------- link for another admin (APP-008)
    App::context()->setAdminUser($super);
    App::context()->setAdminAuth(['channel' => 'web', 'device_id' => null]);
    $link = $devices->createLink($super, ['note' => 'for admin'], '127.0.0.1', '', $adminId);
    $row = $db->first('SELECT user_id, created_by FROM admin_app_link_codes WHERE id = ?', [$link['id']]);
    assert_same([$adminId, $superId], [(int) $row['user_id'], (int) $row['created_by']], 'A super admin can create a link that signs in another administrator, recorded as created by the super admin.');
    $audit = $db->first('SELECT channel, details FROM audit_logs WHERE action = "admin.app_link.create" AND target_id = ? ORDER BY id DESC LIMIT 1', [(string) $adminId]);
    assert_same('web', $audit['channel'] ?? null, 'The audit trail records that the link came from the console (APP-009).');
    App::context()->setAdminAuth(null);

    $signedIn = $devices->exchange((string) $link['code'], ['platform' => 'ios'], 'pwa', '10.20.1.5', $ua, true);
    assert_same($adminId, (int) $signedIn['user']['id'], 'The link signs in the administrator it was created for.');
    $deviceId = (int) $signedIn['device']['id'];
    $linkAudit = $db->first('SELECT channel, device_id FROM audit_logs WHERE action = "admin.app_device.link" AND target_id = ? ORDER BY id DESC LIMIT 1', [(string) $deviceId]);
    assert_same(['app', $deviceId], [$linkAudit['channel'] ?? null, (int) ($linkAudit['device_id'] ?? 0)], 'Device events are audited with the APP channel and device.');

    // Device risk signals: later use from another network and browser.
    $refreshed = $devices->refresh((string) $signedIn['refresh_token'], '198.51.100.20', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36');
    $db->execute('UPDATE admin_app_devices SET last_ip = ? WHERE id = ?', ['198.51.100.20', $deviceId]);
    $listed = array_values(array_filter($devices->listDevices($adminId), static fn (array $item): bool => $item['id'] === $deviceId))[0] ?? [];
    assert_same(['network_changed', 'client_changed'], $listed['risk'] ?? null, 'The device list flags a new network and new client software (APP-043).');

    // Only super administrators manage other administrators' devices.
    App::context()->setAdminUser($admin);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
        (new \TokenHub\Http\Controllers\AppAuthController())->adminDevices(Request::fromGlobals(['id' => (string) $superId]));
        throw new RuntimeException('A regular administrator must not see another administrator\'s devices.');
    } catch (HttpAbortException $abort) {
        assert_same('ADMIN_PERMISSION_DENIED', $abort->errorCode, 'Regular administrators are refused.');
    }

    // ---------------------------------------------------- passkeys (APP-040)
    $passkeys = new AppPasskeyService();
    assert_true($passkeys->ready(), 'The passkey table exists (run the migrations).');
    $access = $devices->accessSession((string) $refreshed['access']['access_token']);
    $sessionId = (int) $access['session']['session_id'];
    $options = $passkeys->options($request(), $deviceId, $admin, 'register');
    assert_same('localhost', $options['rp']['id'], 'Passkeys are bound to the host name.');
    // Replay the recorded authenticator: its challenge becomes the pending one.
    $db->execute('UPDATE admin_app_devices SET webauthn_challenge = ?, webauthn_challenge_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?', [$fixture['challenge1'], $deviceId]);
    $result = $passkeys->register($request(), $deviceId, $adminId, $sessionId, $fixture['reg']);
    assert_same(1, $result['passkeys'], 'The passkey is stored for this device.');
    try {
        $passkeys->register($request(), $deviceId, $adminId, $sessionId, $fixture['reg']);
        throw new RuntimeException('A used challenge must not be accepted again.');
    } catch (HttpAbortException $abort) {
        assert_same('APP_PASSKEY_CHALLENGE', $abort->errorCode, 'Challenges are single use.');
    }

    $db->execute('UPDATE admin_sessions SET step_up_at = NULL WHERE id = ?', [$sessionId]);
    $db->execute('UPDATE admin_app_devices SET webauthn_challenge = ?, webauthn_challenge_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?', [$fixture['challenge2'], $deviceId]);
    assert_same(true, $passkeys->verify($request(), $deviceId, $sessionId, $fixture['asr'])['ok'] ?? null, 'A passkey assertion verifies the session.');
    assert_true($db->first('SELECT step_up_at FROM admin_sessions WHERE id = ?', [$sessionId])['step_up_at'] !== null, 'The session is marked as verified.');
    $db->execute('UPDATE admin_app_devices SET webauthn_challenge = ?, webauthn_challenge_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?', [$fixture['challenge2'], $deviceId]);
    try {
        $passkeys->verify($request(), $deviceId, $sessionId, $fixture['asr']);
        throw new RuntimeException('A replayed assertion must be rejected.');
    } catch (HttpAbortException $abort) {
        assert_same('APP_PASSKEY_INVALID', $abort->errorCode, 'Replayed assertions are rejected (signature counter).');
    }
    $ipRequest = new Request('POST', '/x', ['HTTP_HOST' => '10.0.0.8', 'HTTPS' => 'on'], [], [], '', new RequestContext('r', 't'));
    assert_same(false, $passkeys->available($ipRequest), 'Passkeys are not offered on https://IP.');

    // ------------------------------------------------- push rules (APP-037)
    $push = new AppPushService();
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = openssl_pkey_get_details($key)['ec'];
    $push->subscribe($adminId, $deviceId, [
        'endpoint' => 'https://push.example.test/' . $suffix,
        'keys' => ['p256dh' => AppPushService::b64("\x04" . str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT)), 'auth' => AppPushService::b64(random_bytes(16))],
    ]);
    assert_same('warning', $push->preferences($deviceId)['min_severity'], 'Push defaults to warnings and above.');
    $saved = $push->savePreferences($deviceId, ['min_severity' => 'critical', 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'time_zone' => 'Asia/Shanghai']);
    assert_same(['critical', '22:00', '07:00', 'Asia/Shanghai'], [$saved['min_severity'], $saved['quiet_start'], $saved['quiet_end'], $saved['time_zone']], 'Push rules are saved per device.');
    $db->execute('INSERT INTO alert_events (event_type, severity, message, status) VALUES (?, "warning", "x", "open")', ['app.extras.' . $suffix]);
    $warningId = (int) $db->pdo()->lastInsertId();
    assert_same(0, $push->queueAlert($warningId, 'triggered', [$adminId]), 'A warning is not pushed to a device that wants critical alerts only.');
    $db->execute('DELETE FROM alert_events WHERE id = ?', [$warningId]);

    // Revoking the device removes its passkeys and push subscription.
    $devices->revokeDevice($deviceId, 'test');
    assert_same(0, $passkeys->count($deviceId), 'Passkeys go with the device.');
    assert_same(false, $push->subscribed($deviceId), 'The push subscription goes with the device.');
} finally {
    unset($_SERVER['REQUEST_METHOD']);
    App::context()->setAdminAuth(null);
    App::context()->setAdminUser(null);
    foreach ([$adminId, $superId] as $id) {
        if ($id > 0) {
            $db->execute('DELETE FROM admin_app_passkeys WHERE user_id = ?', [$id]);
            $db->execute('DELETE FROM admin_app_push_subscriptions WHERE user_id = ?', [$id]);
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
