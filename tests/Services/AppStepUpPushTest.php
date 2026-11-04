<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Middleware\AdminAuthMiddleware;
use TokenHub\Services\AppDeviceAuthService;
use TokenHub\Services\AppPushService;
use TokenHub\Services\AuthService;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Support\Response;

// TokenHub APP M4/M5 against the database: server-enforced PIN step-up for
// sensitive operations, PIN lockout, re-linking the same browser, and Web Push
// subscription, queueing and delivery (with a fake push service).

$db = App::db();
$service = new AppDeviceAuthService();
$suffix = bin2hex(random_bytes(4));
$departmentId = 0;
$userId = 0;
$alertId = 0;
$ua = 'TokenHubAppTest/2.0 (iPhone)';

$link = static function (array $actor) use ($service): string {
    App::context()->setAdminUser($actor);
    $code = (string) $service->createLink($actor, [], '127.0.0.1')['code'];
    App::context()->setAdminUser(null);
    return $code;
};
$through = static function (string $token, string $method, string $path): Response {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $request = new Request($method, $path, $_SERVER, [], [], '', new RequestContext('req_app_m5', 'trc_app_m5'));
    return (new AdminAuthMiddleware())($request, static fn (): Response => Response::ok(['passed' => true]));
};
$session = static fn (string $token): array => $service->accessSession($token)['session'] ?? [];

try {
    $db->execute('INSERT INTO departments (parent_id, name, path, level, sort_order, status) VALUES (NULL, ?, ?, 0, 0, "active")', ['APP M5 ' . $suffix, '/app-m5-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status, admin_type, admin_scope) VALUES (?, ?, ?, "super_admin", "active", "super_admin", "global")',
        [$departmentId, 'app_m5_' . $suffix, password_hash('App-M5-Password-123', PASSWORD_DEFAULT)]
    );
    $userId = (int) $db->pdo()->lastInsertId();
    $actor = (new AuthService())->publicUser($db->first('SELECT * FROM users WHERE id = ?', [$userId]));

    // ------------------------------------------------------------- step-up
    $first = $service->exchange($link($actor), ['platform' => 'ios'], 'pwa', '10.5.5.5', $ua, true);
    $access = (string) $first['access']['access_token'];
    $deviceId = (int) $first['device']['id'];

    $blocked = $through($access, 'DELETE', '/admin-api/api-keys/999999');
    assert_same(403, $blocked->status, 'Deleting a key from the APP needs a PIN check first.');
    $payload = json_decode($blocked->body, true);
    assert_same('ADMIN_STEP_UP_REQUIRED', $payload['error']['code'] ?? null, 'The challenge has its own error code.');
    assert_same(false, $payload['error']['details']['pin_set'] ?? null, 'The challenge tells the APP that no PIN is set yet.');
    assert_same(200, $through($access, 'GET', '/admin-api/admin-users')->status, 'Reading never needs a PIN.');
    assert_same(200, $through($access, 'PUT', '/admin-api/api-keys/999999')->status, 'Editing or disabling a key never needs a PIN.');

    $s = $session($access);
    try {
        $service->setPin($deviceId, (int) $s['session_id'], '1234');
        throw new RuntimeException('A short PIN must be rejected.');
    } catch (HttpAbortException $abort) {
        assert_same('APP_PIN_FORMAT', $abort->errorCode, 'PINs are 6 to 12 digits.');
    }
    assert_same(true, $service->setPin($deviceId, (int) $s['session_id'], '135790')['ok'] ?? null, 'The first PIN can be set by the signed-in device.');
    assert_same(200, $through($access, 'DELETE', '/admin-api/api-keys/999999')->status, 'Setting the PIN counts as a fresh verification.');
    assert_true(password_verify('135790', (string) $db->first('SELECT pin_hash FROM admin_app_devices WHERE id = ?', [$deviceId])['pin_hash']), 'Only a password hash of the PIN is stored.');

    $db->execute('UPDATE admin_sessions SET step_up_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE device_id = ?', [$deviceId]);
    assert_same(403, $through($access, 'POST', '/admin-api/quota-adjustments')->status, 'The verification expires after the step-up window.');
    $s = $session($access);
    assert_same(true, $service->stepUp($deviceId, (int) $s['session_id'], '135790')['ok'] ?? null, 'The right PIN verifies the session.');
    assert_same(200, $through($access, 'POST', '/admin-api/quota-adjustments')->status, 'A verified session may perform sensitive operations.');
    assert_same('APP_PIN_INVALID', $service->setPin($deviceId, (int) $s['session_id'], '246800', '000000')['code'] ?? null, 'Changing the PIN needs the current one.');

    $max = max(1, (int) App::config()->get('app.app_client.pin_max_failures', 5));
    $db->execute('UPDATE admin_app_devices SET pin_failures = 0 WHERE id = ?', [$deviceId]);
    $last = [];
    for ($i = 0; $i < $max; $i++) {
        $last = $service->stepUp($deviceId, (int) $s['session_id'], '999999');
    }
    assert_same('APP_PIN_LOCKED', $last['code'] ?? null, 'Too many wrong PINs lock the device.');
    assert_same('pin_failures', $db->first('SELECT revoke_reason FROM admin_app_devices WHERE id = ?', [$deviceId])['revoke_reason'] ?? null, 'The locked device is signed out.');
    assert_same(null, $service->accessSession($access), 'Its access tokens stop working.');

    // ------------------------------------------- re-linking the same browser
    $old = $service->exchange($link($actor), [], 'pwa', '10.5.5.6', $ua, true);
    $new = $service->exchange($link($actor), [], 'pwa', '10.5.5.6', $ua, true);
    assert_same(true, $service->replacePreviousDevice((string) $old['refresh_token'], $userId, (int) $new['device']['id']), 'Re-linking signs the previous device of this browser out.');
    assert_same('replaced', $db->first('SELECT revoke_reason FROM admin_app_devices WHERE id = ?', [$old['device']['id']])['revoke_reason'] ?? null, 'The previous device is marked as replaced.');

    // ---------------------------------------------------------- Web Push
    $push = new AppPushService();
    assert_true($push->ready(), 'The push subscription table should exist (run the migrations).');
    $publicKey = $push->publicKey();
    assert_same(65, strlen(AppPushService::unb64($publicKey)), 'The VAPID public key is an uncompressed P-256 point.');
    assert_same($publicKey, (new AppPushService())->publicKey(), 'The VAPID key pair is generated once and reused.');

    $browserKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($browserKey);
    $p256dh = AppPushService::b64("\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT));
    $endpoint = 'https://push.example.test/send/' . $suffix;
    $deviceForPush = (int) $new['device']['id'];
    $push->subscribe($userId, $deviceForPush, ['endpoint' => $endpoint, 'keys' => ['p256dh' => $p256dh, 'auth' => AppPushService::b64(random_bytes(16))]]);
    assert_true($push->subscribed($deviceForPush), 'The device is subscribed.');

    $db->execute('INSERT INTO alert_events (event_type, severity, message, status) VALUES (?, "critical", ?, "open")', ['app.test.' . $suffix, 'secret upstream detail ' . $suffix]);
    $alertId = (int) $db->pdo()->lastInsertId();
    assert_same(1, $push->queueAlert($alertId, 'triggered', [$userId]), 'One push is queued per subscribed device.');
    $row = $db->first(
        'SELECT n.*, e.severity FROM alert_notifications n JOIN alert_events e ON e.id = n.alert_event_id WHERE n.alert_event_id = ? AND n.channel = "web_push"',
        [$alertId]
    );
    assert_true($row !== null, 'The push sits in the alert notification queue.');
    $sent = [];
    $result = $push->deliver($row, static function (string $url, string $body, array $headers) use (&$sent): array {
        $sent = ['url' => $url, 'body' => $body, 'headers' => $headers];
        return ['status' => 201];
    });
    assert_same(201, $result['status'], 'Delivery reports the push service status.');
    assert_same($endpoint, $sent['url'] ?? null, 'The push goes to the subscription endpoint.');
    assert_true(in_array('Content-Encoding: aes128gcm', $sent['headers'], true), 'Pushes are encrypted (aes128gcm).');
    assert_true((bool) preg_grep('/^Authorization: vapid t=.+, k=' . preg_quote($publicKey, '/') . '$/', $sent['headers']), 'Pushes carry a VAPID token.');
    assert_true(!str_contains($sent['body'], 'secret upstream detail'), 'Alert details are never sent in clear.');

    $gone = $push->deliver($row, static fn (): array => ['status' => 410]);
    assert_same(true, $gone['gone'] ?? null, 'A 410 from the push service removes the subscription.');
    assert_same(false, $push->subscribed($deviceForPush), 'The expired subscription is gone.');

    $push->subscribe($userId, $deviceForPush, ['endpoint' => $endpoint, 'keys' => ['p256dh' => $p256dh, 'auth' => AppPushService::b64(random_bytes(16))]]);
    $service->revokeDevice($deviceForPush, 'test');
    assert_same(false, $push->subscribed($deviceForPush), 'Signing a device out removes its push subscription.');
} finally {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    App::context()->setAdminAuth(null);
    App::context()->setAdminUser(null);
    if ($alertId > 0) {
        $db->execute('DELETE FROM alert_notifications WHERE alert_event_id = ?', [$alertId]);
        $db->execute('DELETE FROM alert_events WHERE id = ?', [$alertId]);
    }
    if ($userId > 0) {
        $db->execute('DELETE FROM admin_app_push_subscriptions WHERE user_id = ?', [$userId]);
        $db->execute('DELETE FROM admin_sessions WHERE user_id = ?', [$userId]);
        $db->execute('DELETE FROM admin_app_devices WHERE user_id = ?', [$userId]);
        $db->execute('DELETE FROM admin_app_link_codes WHERE user_id = ?', [$userId]);
        $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
    if ($departmentId > 0) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
    }
}
