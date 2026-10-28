<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\LoginIpBanService;
use TokenHub\Support\App;

$service = new LoginIpBanService();
$manualIp = '203.0.113.' . random_int(1, 200);

try {
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$manualIp]);
    $manualBan = $service->ban($manualIp, 'admin_manual_ban');
    assert_same(true, $manualBan['banned'], 'Manual IP ban should take effect immediately at the application layer.');
    assert_true(
        in_array($manualIp, array_column($service->listBans()['records'], 'client_ip'), true),
        'Active IP ban list should include manually banned addresses for the WAF management page.'
    );
    $service->reset($manualIp);
    assert_same(false, $service->isBanned($manualIp), 'Manual IP ban reset should invalidate the system-level ban cache.');
} finally {
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$manualIp]);
}

$clientIp = '203.0.113.' . random_int(1, 200);

try {
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$clientIp]);
    App::db()->execute('DELETE FROM system_settings WHERE setting_key = "firewall_settings"');

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $result = $service->recordFailure($clientIp, 'admin_login_bad_password');
        assert_same(false, $result['banned'], 'IP should not be banned before the fifth failed password.');
        assert_same($attempt, $result['failure_count'], 'Failure count should increment for each bad password.');
        assert_same(false, $service->isBanned($clientIp), 'System ban should not activate before the threshold.');
    }

    $fifth = $service->recordFailure($clientIp, 'admin_login_bad_password');
    assert_same(true, $fifth['banned'], 'Fifth failed password should ban the visitor IP.');
    assert_same(5, $fifth['failure_count'], 'Fifth failed password should store the threshold count.');
    assert_same(true, $service->isBanned($clientIp), 'Banned visitor IP should be rejected at system level.');
    assert_true(in_array($clientIp, $service->activeBannedIps(), true), 'Active banned IP list should expose the IP to WAF publishing.');

    $service->reset($clientIp);
    assert_same(false, $service->isBanned($clientIp), 'Reset should clear the system IP ban after successful login or admin action.');
} finally {
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$clientIp]);
    App::db()->execute('DELETE FROM system_settings WHERE setting_key = "firewall_settings"');
}

$cachedIp = '203.0.113.' . random_int(1, 200);
try {
    App::redis()->delete($service->cacheKey($cachedIp));
    App::db()->execute(
        'INSERT INTO system_ip_bans (client_ip, failure_count, reason, banned_at, last_failed_at) VALUES (?, 5, "admin_login_bad_password", NOW(), NOW())',
        [$cachedIp]
    );
    assert_same(true, $service->isBanned($cachedIp), 'First system IP ban lookup should read the banned row.');
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$cachedIp]);
    assert_same(true, $service->isBanned($cachedIp), 'Warm system IP ban lookup should use the short read-through cache.');
    $service->reset($cachedIp);
    assert_same(false, $service->isBanned($cachedIp), 'Reset should invalidate the system IP ban cache.');
} finally {
    App::redis()->delete($service->cacheKey($cachedIp));
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$cachedIp]);
}

$configuredThresholdIp = '203.0.113.' . random_int(1, 200);
try {
    App::db()->execute(
        'INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("firewall_settings", ?, "test firewall settings") ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)',
        [json_encode(['login_failure_threshold' => 3], JSON_UNESCAPED_SLASHES)]
    );
    $service->recordFailure($configuredThresholdIp, 'admin_login_bad_password');
    $second = $service->recordFailure($configuredThresholdIp, 'admin_login_bad_password');
    assert_same(false, $second['banned'], 'Configured threshold should not ban before the third failed password.');
    $third = $service->recordFailure($configuredThresholdIp, 'admin_login_bad_password');
    assert_same(true, $third['banned'], 'Configured threshold should ban on the third failed password.');
} finally {
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$configuredThresholdIp]);
    App::db()->execute('DELETE FROM system_settings WHERE setting_key = "firewall_settings"');
}

$authIp = '198.51.100.' . random_int(1, 200);
$resetIp = '192.0.2.' . random_int(1, 200);
$username = 'login_ban_' . bin2hex(random_bytes(4));
$password = 'Correct-Password-123';
$userId = null;

try {
    App::db()->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (1, ?, ?, "admin", "active")',
        [$username, password_hash($password, PASSWORD_DEFAULT)]
    );
    $userId = (int) (App::db()->first('SELECT id FROM users WHERE username = ?', [$username])['id'] ?? 0);

    $auth = new \TokenHub\Services\AuthService();
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        assert_same(null, $auth->login($username, 'wrong-password', $authIp, 'LoginIpBanTest/1.0'), 'Wrong admin password should not authenticate.');
    }
    assert_same(true, $service->isBanned($authIp), 'AuthService should ban an IP after five wrong admin passwords.');

    assert_same(null, $auth->login($username, 'wrong-password', $resetIp, 'LoginIpBanTest/1.0'), 'Single wrong password should not authenticate.');
    assert_same(false, $service->isBanned($resetIp), 'One wrong password should not ban the IP.');
    $login = $auth->login($username, $password, $resetIp, 'LoginIpBanTest/1.0');
    assert_true(is_array($login), 'Correct admin password should authenticate.');
    assert_same(false, $service->isBanned($resetIp), 'Successful admin login should clear previous failures for that IP.');
} finally {
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip IN (?, ?)', [$authIp, $resetIp]);
    if ($userId !== null && $userId > 0) {
        App::db()->execute('DELETE FROM admin_sessions WHERE user_id = ?', [$userId]);
        App::db()->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
}
