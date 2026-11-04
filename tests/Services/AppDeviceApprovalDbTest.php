<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// New-device approval is on for this test (must be set before the config loads).
$_ENV['APP_NEW_DEVICE_APPROVAL'] = 'true';
putenv('APP_NEW_DEVICE_APPROVAL=true');

use TokenHub\Services\AppDeviceAuthService;
use TokenHub\Services\AppPushService;
use TokenHub\Services\AuthService;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\SchemaInspector;

// TokenHub APP new-device approval (APP_NEW_DEVICE_APPROVAL) and native push
// subscriptions against the database (migration 000005).

$db = App::db();
$devices = new AppDeviceAuthService();
$suffix = bin2hex(random_bytes(4));
$departmentId = 0;
$superId = 0;
$adminId = 0;
$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';
$aborts = static function (callable $call): string {
    try {
        $call();
    } catch (HttpAbortException $abort) {
        return $abort->errorCode;
    }
    return '';
};

assert_true(SchemaInspector::hasColumn('admin_app_devices', 'approval_code'), 'Migration 000005 adds the approval columns (run the migrations).');
assert_true(SchemaInspector::hasColumn('admin_app_push_subscriptions', 'kind'), 'Migration 000005 adds the push subscription kind.');
assert_true($devices->approvalRequired(), 'APP_NEW_DEVICE_APPROVAL=true turns approval on.');

try {
    $db->execute('INSERT INTO departments (parent_id, name, path, level, sort_order, status) VALUES (NULL, ?, ?, 0, 0, "active")', ['APP Approval ' . $suffix, '/app-approval-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    foreach (['super_admin' => 'super', 'admin' => 'admin'] as $role => $name) {
        $db->execute(
            'INSERT INTO users (department_id, username, password_hash, role, status, admin_type, admin_scope) VALUES (?, ?, ?, ?, "active", ?, "global")',
            [$departmentId, 'app_ap_' . $name . '_' . $suffix, password_hash('App-Approval-Password-1', PASSWORD_DEFAULT), $role, $role === 'admin' ? 'admin' : 'super_admin']
        );
        ${$name . 'Id'} = (int) $db->pdo()->lastInsertId();
    }
    $admin = (new AuthService())->publicUser($db->first('SELECT * FROM users WHERE id = ?', [$adminId]));

    $newLink = static function () use ($devices, $admin): string {
        App::context()->setAdminUser($admin);
        App::context()->setAdminAuth(['channel' => 'web', 'device_id' => null]);
        $link = $devices->createLink($admin, [], '127.0.0.1', '');
        App::context()->setAdminAuth(null);
        App::context()->setAdminUser(null);
        return (string) $link['code'];
    };

    // ---------------------------------------------------------- pending
    $pending = $devices->exchange($newLink(), ['platform' => 'ios', 'name' => 'iPhone'], 'pwa', '10.0.0.5', $ua, true);
    assert_true(($pending['pending'] ?? false) === true, 'With approval on, the exchange returns a pending device.');
    assert_true(!isset($pending['access']), 'A pending device gets no access token.');
    assert_true(preg_match('/^\d{4}$/', (string) $pending['match_code']) === 1, 'A 4-digit match code is shown on the device.');
    $deviceId = (int) $pending['device']['id'];
    $row = $db->first('SELECT status, approval_code, TIMESTAMPDIFF(SECOND, NOW(), idle_expires_at) AS left_seconds FROM admin_app_devices WHERE id = ?', [$deviceId]);
    assert_same('pending', $row['status'], 'The device is stored as pending.');
    assert_same($pending['match_code'], $row['approval_code'], 'The match code is stored with the device.');
    assert_true((int) $row['left_seconds'] <= AppDeviceAuthService::APPROVAL_MINUTES * 60 && (int) $row['left_seconds'] > 0, 'The approval window is ' . AppDeviceAuthService::APPROVAL_MINUTES . ' minutes.');

    $waiting = $devices->refresh((string) $pending['refresh_token'], '10.0.0.5', $ua);
    assert_true(($waiting['pending'] ?? false) === true && $waiting['match_code'] === $pending['match_code'], 'Refreshing while pending reports the wait (and keeps the credential).');
    $unchanged = $db->first('SELECT refresh_hash FROM admin_app_devices WHERE id = ?', [$deviceId]);
    $waitingAgain = $devices->refresh((string) $pending['refresh_token'], '10.0.0.5', $ua);
    assert_true(($waitingAgain['pending'] ?? false) === true, 'The same credential keeps working while pending (no rotation).');
    assert_same($unchanged['refresh_hash'], $db->first('SELECT refresh_hash FROM admin_app_devices WHERE id = ?', [$deviceId])['refresh_hash'], 'A pending device credential is not rotated.');

    $listed = array_values(array_filter($devices->listDevices($adminId), static fn (array $item): bool => $item['id'] === $deviceId))[0] ?? [];
    assert_true(($listed['pending'] ?? false) === true && ($listed['approval_code'] ?? '') === $pending['match_code'], 'The console lists the pending device with its match code.');

    // Only the owner (or a super admin for that owner) can approve.
    assert_same('ADMIN_NOT_FOUND', $aborts(fn () => $devices->approveDevice($superId, $deviceId, $superId)), 'Another administrator\'s device cannot be approved as one\'s own.');

    App::context()->setAdminUser($admin);
    App::context()->setAdminAuth(['channel' => 'web', 'device_id' => null]);
    $approved = $devices->approveDevice($adminId, $deviceId, $adminId);
    assert_true($approved['approved'], 'The owner approves the device in the console.');
    $row = $db->first('SELECT status, approval_code, approved_by, TIMESTAMPDIFF(DAY, NOW(), idle_expires_at) AS idle_days FROM admin_app_devices WHERE id = ?', [$deviceId]);
    assert_same(['active', null, $adminId], [$row['status'], $row['approval_code'], (int) $row['approved_by']], 'The approved device is active, its match code gone, the approver recorded.');
    assert_true((int) $row['idle_days'] >= 28, 'After approval the device gets its normal idle lifetime.');
    assert_true($db->first('SELECT id FROM audit_logs WHERE action = "admin.app_device.approve" AND target_id = ? LIMIT 1', [(string) $deviceId]) !== null, 'Approval is audited.');
    assert_same('APP_DEVICE_NOT_PENDING', $aborts(fn () => $devices->approveDevice($adminId, $deviceId, $adminId)), 'An approved device cannot be approved twice.');
    App::context()->setAdminAuth(null);
    App::context()->setAdminUser(null);

    $signedIn = $devices->refresh((string) $pending['refresh_token'], '10.0.0.5', $ua);
    assert_true(isset($signedIn['access']['access_token']) && is_string($signedIn['refresh_token']), 'After approval the device signs in with the credential it already holds.');

    // ---------------------------------------------------------- reject
    $second = $devices->exchange($newLink(), ['platform' => 'android'], 'native', '10.0.0.6', 'TokenHub Android', true);
    $secondId = (int) $second['device']['id'];
    App::context()->setAdminUser($admin);
    App::context()->setAdminAuth(['channel' => 'web', 'device_id' => null]);
    $rejected = $devices->rejectDevice($adminId, $secondId);
    App::context()->setAdminAuth(null);
    App::context()->setAdminUser(null);
    assert_true($rejected['rejected'], 'The owner can reject a pending device.');
    assert_same('APP_DEVICE_REJECTED', $aborts(fn () => $devices->refresh((string) $second['refresh_token'], '10.0.0.6', 'TokenHub Android')), 'A rejected device learns it was rejected.');

    // ---------------------------------------------------------- expiry
    $third = $devices->exchange($newLink(), ['platform' => 'web'], 'pwa', '10.0.0.7', $ua, true);
    $thirdId = (int) $third['device']['id'];
    $db->execute('UPDATE admin_app_devices SET idle_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$thirdId]);
    assert_same('APP_DEVICE_APPROVAL_EXPIRED', $aborts(fn () => $devices->approveDevice($adminId, $thirdId, $adminId)), 'A device past its approval window cannot be approved.');
    assert_same('APP_DEVICE_APPROVAL_EXPIRED', $aborts(fn () => $devices->refresh((string) $third['refresh_token'], '10.0.0.7', $ua)), 'The device learns its approval timed out.');
    $fourth = $devices->exchange($newLink(), ['platform' => 'web'], 'pwa', '10.0.0.8', $ua, true);
    $db->execute('UPDATE admin_app_devices SET idle_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [(int) $fourth['device']['id']]);
    $devices->cleanup();
    $row = $db->first('SELECT status, revoke_reason, approval_code FROM admin_app_devices WHERE id = ?', [(int) $fourth['device']['id']]);
    assert_same(['expired', 'approval_expired', null], [$row['status'], $row['revoke_reason'], $row['approval_code']], 'Housekeeping expires devices that were never approved.');
    assert_same('APP_DEVICE_APPROVAL_EXPIRED', $aborts(fn () => $devices->refresh((string) $fourth['refresh_token'], '10.0.0.8', $ua)), 'A device expired by housekeeping still learns that its approval timed out.');

    // Pending devices count towards the device limit.
    $before = count($devices->listDevices($adminId));
    $fifth = $devices->exchange($newLink(), ['platform' => 'web'], 'pwa', '10.0.0.9', $ua, true);
    assert_same($before + 1, count($devices->listDevices($adminId)), 'Pending devices are listed and count towards the limit.');
    $devices->revokeUserDevices($adminId, 'test');
    assert_same('revoked', $db->first('SELECT status FROM admin_app_devices WHERE id = ?', [(int) $fifth['device']['id']])['status'], 'Revoking all devices also drops pending ones.');

    // ---------------------------------------------------------- native push subscription
    $nativeDevice = $deviceId; // revoked above with the others; re-activate for the subscription check
    $db->execute('UPDATE admin_app_devices SET status = "active", revoked_at = NULL, revoke_reason = NULL WHERE id = ?', [$nativeDevice]);
    $configured = (new \TokenHub\Services\AppNativePushService())->configured('ios');
    if ($configured) {
        $result = (new AppPushService())->subscribeNative($adminId, $nativeDevice, 'ios', str_repeat('ab', 32));
        assert_same('ios', $result['kind'], 'A native token is stored as an ios subscription.');
        $sub = $db->first('SELECT kind, endpoint FROM admin_app_push_subscriptions WHERE device_id = ?', [$nativeDevice]);
        assert_same(['ios', 'ios://' . str_repeat('ab', 32)], [$sub['kind'], $sub['endpoint']], 'Native subscriptions carry their kind.');
    } else {
        assert_same('APP_PUSH_UNAVAILABLE', $aborts(fn () => (new AppPushService())->subscribeNative($adminId, $nativeDevice, 'ios', str_repeat('ab', 32))), 'Without APNs credentials native push is refused clearly.');
    }
    assert_same('ADMIN_INVALID_PAYLOAD', $aborts(fn () => (new AppPushService())->subscribeNative($adminId, $nativeDevice, 'windows', 'x')), 'Only ios and android tokens are accepted.');
} finally {
    App::context()->setAdminAuth(null);
    App::context()->setAdminUser(null);
    foreach ([$adminId, $superId] as $id) {
        if ($id > 0) {
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

echo "PASS APP 新设备确认\n";
