<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ScreenService;
use TokenHub\Support\App;

App::bootstrap(dirname(__DIR__, 2));

$db = App::db();
$service = new ScreenService();
$prefix = 'test_screen_' . bin2hex(random_bytes(4));

$cleanup = static function () use ($db, $prefix): void {
    $ids = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT id FROM dashboard_share_links WHERE share_code LIKE ?', [$prefix . '%'])
    );
    foreach ($ids as $id) {
        $db->execute('DELETE FROM dashboard_share_access_logs WHERE share_link_id = ?', [$id]);
        $db->execute('DELETE FROM dashboard_share_sessions WHERE share_link_id = ?', [$id]);
        $db->execute('DELETE FROM dashboard_share_links WHERE id = ?', [$id]);
    }
};

$events = static function (int $shareId) use ($db): array {
    return array_map(
        static fn (array $row): string => (string) $row['event_type'] . ':' . (string) ($row['reason'] ?? ''),
        $db->select('SELECT event_type, reason FROM dashboard_share_access_logs WHERE share_link_id = ? ORDER BY id ASC', [$shareId])
    );
};

$cleanup();

try {
    $created = $service->createShareLink([
        'share_code' => $prefix . '_main',
        'name' => 'Main screen',
        'username' => 'screen_user',
        'password' => 'first-secret',
        'scope_type' => 'department',
        'scope_ids' => [810001],
        'allowed_metrics' => ['realtime', 'rankings'],
        'allow_drilldown' => false,
        'allowed_ip_cidrs' => ['10.0.0.0/8'],
        'expires_at' => null,
    ]);

    assert_true(isset($created['id']), 'Created share link should return its id.');
    assert_same('first-secret', $created['password'], 'Create should return plaintext password exactly once.');
    assert_true(!array_key_exists('password_hash', $created), 'Create response must not expose password_hash.');

    $row = $db->first('SELECT * FROM dashboard_share_links WHERE id = ?', [$created['id']]);
    assert_true(is_array($row), 'Created share link should be stored.');
    assert_true($row['password_hash'] !== 'first-secret', 'Share link password must be hashed in storage.');
    assert_true(password_verify('first-secret', (string) $row['password_hash']), 'Stored hash should verify original password.');

    $shareLogin = $service->loginByShareCode($prefix . '_main', '10.1.2.3', 'ScreenTest/1.0');
    assert_true(($shareLogin['ok'] ?? false) === true, 'Share code should login without username or password.');
    assert_true(isset($shareLogin['session']), 'Share-code login should return a session token.');
    assert_true($service->session((string) $shareLogin['session'], '10.1.2.3') !== null, 'Share-code session should authenticate.');

    $credentialLogin = $service->loginByCredentials('screen_user', 'first-secret', '10.1.2.3', 'ScreenTest/1.0');
    assert_true(($credentialLogin['ok'] ?? false) === true, 'Screen username and password should login without a share code.');
    assert_true(isset($credentialLogin['session']), 'Credential login should return a session token.');

    $login = $service->login($prefix . '_main', 'screen_user', 'first-secret', '10.1.2.3', 'ScreenTest/1.0');
    assert_true(($login['ok'] ?? false) === true, 'Correct screen credentials should login successfully.');
    assert_true(isset($login['session']), 'Successful login should return a session token.');
    assert_true(!array_key_exists('password_hash', $login['share']), 'Login response must not expose password_hash.');

    $shareId = (int) $created['id'];
    assert_true($service->session((string) $login['session'], '10.1.2.3') !== null, 'Fresh session should authenticate.');
    $db->execute('UPDATE dashboard_share_links SET expires_at = "2000-01-01 00:00:00" WHERE id = ?', [$shareId]);
    assert_true($service->session((string) $login['session'], '10.1.2.3') !== null, 'Existing session should survive share login expiry.');

    $expiredLogin = $service->login($prefix . '_main', 'screen_user', 'first-secret', '10.1.2.3', 'ScreenTest/1.0');
    assert_same(false, $expiredLogin['ok'] ?? null, 'Expired link should reject new login.');
    assert_same('SCREEN_LINK_EXPIRED', $expiredLogin['code'] ?? null, 'Expired link should return screen-specific error code.');
    $db->execute('UPDATE dashboard_share_links SET expires_at = NULL WHERE id = ?', [$shareId]);

    $badPassword = $service->login($prefix . '_main', 'screen_user', 'wrong-secret', '10.1.2.3', 'ScreenTest/1.0');
    assert_same(false, $badPassword['ok'] ?? null, 'Wrong password should reject login.');
    assert_same('SCREEN_UNAUTHENTICATED', $badPassword['code'] ?? null, 'Wrong password should return screen-specific error code.');

    $badIp = $service->login($prefix . '_main', 'screen_user', 'first-secret', '192.168.1.20', 'ScreenTest/1.0');
    assert_same(false, $badIp['ok'] ?? null, 'Out-of-range IP should reject login.');
    assert_same('SCREEN_IP_FORBIDDEN', $badIp['code'] ?? null, 'Out-of-range IP should return screen-specific error code.');
    $badShareIp = $service->loginByShareCode($prefix . '_main', '192.168.1.20', 'ScreenTest/1.0');
    assert_same(false, $badShareIp['ok'] ?? null, 'Out-of-range IP should reject share-code login.');
    assert_same('SCREEN_IP_FORBIDDEN', $badShareIp['code'] ?? null, 'Out-of-range share-code login should return screen-specific error code.');
    $badCredentialIp = $service->loginByCredentials('screen_user', 'first-secret', '192.168.1.20', 'ScreenTest/1.0');
    assert_same(false, $badCredentialIp['ok'] ?? null, 'Out-of-range IP should reject credential login.');
    assert_same('SCREEN_IP_FORBIDDEN', $badCredentialIp['code'] ?? null, 'Out-of-range credential login should return screen-specific error code.');

    $allowedShare = $service->allowClientIp($shareId, '192.168.1.20');
    assert_true(in_array('192.168.1.20', json_decode((string) $allowedShare['allowed_ip_cidrs'], true), true), 'Allowed client IP should be added to share CIDRs.');
    $allowedShareAgain = $service->allowClientIp($shareId, '192.168.1.20');
    assert_same($allowedShare['allowed_ip_cidrs'], $allowedShareAgain['allowed_ip_cidrs'], 'Allowing the same IP twice should not duplicate it.');
    $allowedIpLogin = $service->loginByShareCode($prefix . '_main', '192.168.1.20', 'ScreenTest/1.0');
    assert_true(($allowedIpLogin['ok'] ?? false) === true, 'Newly allowed IP should login by share code.');

    $reset = $service->resetPassword($shareId, 'second-secret');
    assert_same('second-secret', $reset['password'], 'Password reset should return new plaintext password once.');
    assert_true(!array_key_exists('password_hash', $reset), 'Password reset response must not expose password_hash.');
    assert_true($service->session((string) $login['session'], '10.1.2.3') === null, 'Password reset should revoke old sessions.');

    $oldPassword = $service->login($prefix . '_main', 'screen_user', 'first-secret', '10.1.2.3', 'ScreenTest/1.0');
    assert_same(false, $oldPassword['ok'] ?? null, 'Old password should stop working after reset.');
    $newLogin = $service->login($prefix . '_main', 'screen_user', 'second-secret', '10.1.2.3', 'ScreenTest/1.0');
    assert_true(($newLogin['ok'] ?? false) === true, 'New password should work after reset.');

    $service->revokeSessions($shareId);
    assert_true($service->session((string) $newLogin['session'], '10.1.2.3') === null, 'Manual session revoke should invalidate existing sessions.');

    $latestLogin = $service->login($prefix . '_main', 'screen_user', 'second-secret', '10.1.2.3', 'ScreenTest/1.0');
    assert_true(($latestLogin['ok'] ?? false) === true, 'Login should work before disabling link.');
    $service->disableShareLink($shareId);
    assert_true($service->session((string) $latestLogin['session'], '10.1.2.3') === null, 'Disabled link should invalidate existing sessions.');
    $disabledLogin = $service->login($prefix . '_main', 'screen_user', 'second-secret', '10.1.2.3', 'ScreenTest/1.0');
    assert_same(false, $disabledLogin['ok'] ?? null, 'Disabled link should reject login.');
    assert_same('SCREEN_LINK_DISABLED', $disabledLogin['code'] ?? null, 'Disabled link should return screen-specific error code.');

    $eventText = implode('|', $events($shareId));
    foreach (['login_success:', 'refresh:', 'login_expired:share_expired', 'login_failed:bad_password', 'ip_denied:ip_forbidden', 'session_revoked:password_reset', 'session_revoked:admin_revoked', 'link_disabled:link_disabled'] as $expected) {
        assert_true(str_contains($eventText, $expected), "Expected access log event {$expected}.");
    }
} finally {
    $cleanup();
}
