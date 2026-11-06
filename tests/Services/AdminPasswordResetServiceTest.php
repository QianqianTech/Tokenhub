<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Http\Controllers\AdminRecoveryController;
use TokenHub\Services\AdminPasswordResetService;
use TokenHub\Services\AdminUserService;
use TokenHub\Services\AppDeviceAuthService;
use TokenHub\Services\AuthService;
use TokenHub\Services\SmtpMailer;
use TokenHub\Services\SmtpSettingsService;
use TokenHub\Services\SystemSettingsService;
use TokenHub\Support\App;
use TokenHub\Support\Config;
use TokenHub\Support\Crypto;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

// Requires a migrated MySQL + Redis, like the existing administrator tests.
// SMTP is captured in memory unless TOKENHUB_TEST_SMTP_HOST names a test sink.
$db = App::db();
$smtp = new SmtpSettingsService();
$suffix = bin2hex(random_bytes(5));
$queue = 'tokenhub:test:password-recovery:' . $suffix;
$originalSettings = $db->first('SELECT settings_value FROM admin_smtp_settings WHERE id = 1');
$originalActor = App::context()->adminUser();
$originalQueue = $_ENV['QUEUE_NAME'] ?? null;
$originalConfig = App::config();
$userIds = [];
$departmentId = 0;
$messages = [];
$smtpHost = (string) getenv('TOKENHUB_TEST_SMTP_HOST');
$service = new AdminPasswordResetService(static function (array $settings, string $to, string $subject, string $body) use (&$messages, $smtpHost): void {
    $messages[] = compact('to', 'subject', 'body');
    if ($smtpHost !== '') (new SmtpMailer())->send($settings, $to, $subject, $body);
});
$expect = static function (callable $call, string $code): void {
    try { $call(); } catch (HttpAbortException $error) {
        assert_same($code, $error->errorCode, 'Expected recovery validation error.');
        return;
    }
    throw new RuntimeException('Expected ' . $code);
};
$ipPrefix = '198.' . random_int(18, 19) . '.' . random_int(1, 250) . '.';
$request = static fn (array $body, string $ip = '198.51.100.31', string $locale = 'zh-CN'): Request => new Request('POST', '/admin-api/forgot-password', [
    'REMOTE_ADDR' => str_replace('198.51.100.', $ipPrefix, $ip),
    'HTTP_X_TOKENHUB_LOCALE' => $locale,
], [], $body, '', new RequestContext('req_recovery_test', 'trace_recovery_test'));
$job = static function (string $email): array {
    $id = bin2hex(random_bytes(16));
    App::redis()->set(AdminPasswordResetService::latestRequestKey($email), $id, 3600);
    return ['email' => $email, 'request_id' => $id, 'requested_at' => time()];
};
$tokenFromLastMail = static function () use (&$messages): string {
    preg_match('/#reset-password=(apr_[a-f0-9]{64})/', $messages[array_key_last($messages)]['body'], $match);
    assert_true(isset($match[1]), 'Mail should carry a reset link in its fragment.');
    return $match[1];
};
$addUser = static function (string $name, ?string $email, string $role = 'admin', string $status = 'active') use ($db, &$userIds, &$departmentId, $suffix): int {
    $db->execute('INSERT INTO users (department_id, username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?)', [$departmentId, $name . '_' . $suffix, $email, password_hash('Original-Password-123', PASSWORD_DEFAULT), $role, $status]);
    $id = (int) $db->pdo()->lastInsertId();
    $userIds[] = $id;
    return $id;
};

try {
    $_ENV['QUEUE_NAME'] = $queue;
    (new ReflectionProperty(App::class, 'config'))->setValue(null, Config::default());
    $db->execute('INSERT INTO departments (parent_id, name, path, level, status) VALUES (NULL, ?, ?, 0, "active")', ['Recovery test ' . $suffix, '/recovery-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $superId = $addUser('recovery_super', 'super-' . $suffix . '@example.test', 'super_admin');
    $actor = ['id' => $superId, 'role' => 'super_admin', 'department_id' => $departmentId];
    App::context()->setAdminUser($actor);

    // Configuration authorization, validation, encrypted persistence and safe reads.
    $configuration = ['enabled' => true, 'host' => $smtpHost ?: 'smtp.example.test', 'port' => $smtpHost !== '' ? 1025 : 587, 'encryption' => 'starttls', 'auth_enabled' => true, 'username' => 'test-account', 'password' => 'Synthetic-SMTP-Secret', 'from_address' => 'noreply@example.test', 'from_name' => '测试 TokenHub', 'public_url' => 'https://hub.example.test', 'timeout_seconds' => 5, 'reset_ttl_minutes' => 30];
    $saved = (new SystemSettingsService())->saveSection('smtp', $configuration);
    assert_true($saved['password_set'], 'Saved configuration should indicate a credential exists.');
    assert_true(!isset($saved['password']) && !isset($saved['encrypted_password']), 'SMTP credentials must not be returned.');
    $raw = (string) $db->first('SELECT settings_value FROM admin_smtp_settings WHERE id = 1')['settings_value'];
    assert_true(!str_contains($raw, 'Synthetic-SMTP-Secret'), 'SMTP secrets must be encrypted at rest.');
    $ciphertext = json_decode($raw, true)['encrypted_password'];
    assert_same('Synthetic-SMTP-Secret', Crypto::decrypt($ciphertext), 'Stored credential should decrypt with APP_KEY.');
    $smtp->save(['password' => '']);
    assert_same($ciphertext, json_decode($db->first('SELECT settings_value FROM admin_smtp_settings WHERE id = 1')['settings_value'], true)['encrypted_password'], 'A blank credential preserves the stored ciphertext.');
    foreach ([['port' => 0], ['port' => 65536], ['encryption' => 'invalid'], ['from_name' => "name\r\nBcc: other@example.test"], ['host' => 'smtp://example.test'], ['public_url' => 'https://user:pass@example.test'], ['public_url' => 'https://hub.example.test/#evil'], ['from_address' => 'invalid'], ['reset_ttl_minutes' => 1], ['clear_password' => true]] as $bad) {
        $expect(fn () => $smtp->save($bad), 'SMTP_INVALID_CONFIG');
    }
    App::context()->setAdminUser(['id' => $superId, 'role' => 'admin', 'admin_type' => 'full_admin', 'department_id' => $departmentId]);
    $expect(fn () => $smtp->save($configuration), 'ADMIN_PERMISSION_DENIED');
    $expect(fn () => (new AdminRecoveryController())->testSmtp($request(['recipient' => 'sink@example.test'])), 'ADMIN_PERMISSION_DENIED');
    App::context()->setAdminUser($actor);
    $smtp->save(['auth_enabled' => false, 'encryption' => 'none', 'clear_password' => true]);
    assert_same(false, $smtp->settings()['password_set'], 'Explicitly clearing a relay credential removes it.');
    $public = (new SystemSettingsService())->settingsCenter()['sections']['smtp'];
    assert_true(!isset($public['password']) && !isset($public['encrypted_password']), 'Settings center must never expose the SMTP credential.');

    // Binding and unbinding email is a super-administrator operation.
    $email = 'admin-' . $suffix . '@example.test';
    $created = (new AdminUserService())->create(['department_id' => $departmentId, 'username' => 'recovery_admin_' . $suffix, 'password' => 'Original-Password-123', 'email' => '  ' . strtoupper($email) . '  ']);
    $adminId = (int) $created['id'];
    $userIds[] = $adminId;
    assert_same($email, $created['email'], 'Email should be trimmed and normalized.');
    $expect(fn () => (new AdminUserService())->update($superId, ['email' => $email]), 'ADMIN_EMAIL_IN_USE');
    $expect(fn () => (new AdminUserService())->update($adminId, ['email' => 'invalid']), 'ADMIN_INVALID_EMAIL');
    (new AdminUserService())->update($adminId, ['email' => null]);
    assert_same(null, $db->first('SELECT email FROM users WHERE id = ?', [$adminId])['email'], 'Email can be unbound.');
    (new AdminUserService())->update($adminId, ['email' => $email]);
    $regularEmail = 'regular-' . $suffix . '@example.test';
    $disabledEmail = 'disabled-' . $suffix . '@example.test';
    $addUser('recovery_regular', $regularEmail, 'user');
    $addUser('recovery_disabled', $disabledEmail, 'admin', 'disabled');

    // Public requests have indistinguishable replies and contain no credentials.
    App::context()->setAdminUser(null);
    $controller = new AdminRecoveryController();
    $known = $controller->forgotPassword($request(['email' => $email], locale: 'en-US'));
    $unknown = $controller->forgotPassword($request(['email' => 'unknown-' . $suffix . '@example.test'], locale: 'en-US'));
    assert_same(200, $known->status, 'An unauthenticated administrator can request recovery.');
    assert_same(json_decode($known->body, true)['data'], json_decode($unknown->body, true)['data'], 'Known and unknown addresses get the same response.');
    assert_true(!str_contains($known->body, 'apr_'), 'No link is returned by the public API.');
    $queued = json_decode(App::redis()->connection()->rPop($queue), true);
    assert_same($email, $queued['email'], 'Recovery is queued for background delivery.');
    assert_same('en-US', $queued['locale'], 'The request language is captured by the job.');
    assert_true(!isset($queued['token']), 'Redis stores no reset credential.');
    $service->deliver($queued);
    $mail = $messages[array_key_last($messages)];
    assert_same('Reset your TokenHub administrator password', $mail['subject'], 'The worker sends the message in the requested language.');
    assert_true(str_contains($mail['body'], '/admin/?lang=en-US#reset-password='), 'The English link carries the locale and keeps the credential in its fragment.');
    $token = $tokenFromLastMail();
    $stored = $db->first('SELECT * FROM admin_password_resets WHERE token_hash = ?', [Crypto::hashKey($token)]);
    assert_true($stored !== null && !str_contains(json_encode($stored), $token), 'Only a hash of the reset credential is stored.');
    $count = count($messages);
    $service->deliver($queued);
    assert_same($count, count($messages), 'A retried completed queue job must not send a second mail.');
    foreach (['unknown-' . $suffix . '@example.test', $regularEmail, $disabledEmail] as $unavailable) $service->deliver($job($unavailable));
    assert_same($count, count($messages), 'Unknown, regular and disabled users receive no administrator recovery mail.');

    $delayedJob = $job($email);
    $newerJob = $job($email);
    $service->deliver($newerJob);
    assert_same('TokenHub 管理员密码重置', $messages[array_key_last($messages)]['subject'], 'Jobs from before the locale change use the Chinese default.');
    $mailCount = count($messages);
    $service->deliver($delayedJob);
    assert_same($mailCount, count($messages), 'Out-of-order workers must not send an older request or invalidate the newest link.');

    // Reissue, expiry, account state and binding changes invalidate links.
    $service->deliver($job($email));
    $expect(fn () => $service->reset($token, 'New-Password-456', 'New-Password-456'), 'ADMIN_RESET_LINK_INVALID');
    $latest = $tokenFromLastMail();
    $db->execute('UPDATE admin_password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE token_hash = ?', [Crypto::hashKey($latest)]);
    $expect(fn () => $service->reset($latest, 'New-Password-456', 'New-Password-456'), 'ADMIN_RESET_LINK_INVALID');
    foreach ([['users', 'email', 'changed-' . $suffix . '@example.test', $email], ['users', 'status', 'disabled', 'active'], ['users', 'role', 'user', 'admin'], ['departments', 'status', 'disabled', 'active']] as [$table, $column, $changed, $original]) {
        $service->deliver($job($email));
        $link = $tokenFromLastMail();
        $id = $table === 'users' ? $adminId : $departmentId;
        $db->execute("UPDATE {$table} SET {$column} = ? WHERE id = ?", [$changed, $id]);
        $expect(fn () => $service->reset($link, 'New-Password-456', 'New-Password-456'), 'ADMIN_RESET_LINK_INVALID');
        $db->execute("UPDATE {$table} SET {$column} = ? WHERE id = ?", [$original, $id]);
    }
    $service->deliver($job($email));
    $beforeEmailChange = $tokenFromLastMail();
    App::context()->setAdminUser($actor);
    (new AdminUserService())->update($adminId, ['email' => 'temporary-' . $suffix . '@example.test']);
    (new AdminUserService())->update($adminId, ['email' => $email]);
    App::context()->setAdminUser(null);
    $expect(fn () => $service->reset($beforeEmailChange, 'New-Password-456', 'New-Password-456'), 'ADMIN_RESET_LINK_INVALID');
    $service->deliver($job($email));
    $link = $tokenFromLastMail();
    $db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('Original-Password-123', PASSWORD_DEFAULT), $adminId]);
    $expect(fn () => $service->reset($link, 'New-Password-456', 'New-Password-456'), 'ADMIN_RESET_LINK_INVALID');

    // A failed SMTP attempt leaves no usable link and may be retried safely.
    $failing = new AdminPasswordResetService(static function (): void { throw new RuntimeException('SMTP_SEND_FAILED'); });
    $failedJob = $job($email);
    try { $failing->deliver($failedJob); throw new RuntimeException('Expected SMTP failure.'); } catch (RuntimeException $error) {
        assert_same('SMTP_SEND_FAILED', $error->getMessage(), 'SMTP errors should propagate as a safe code.');
    }
    assert_same(null, $db->first('SELECT id FROM admin_password_resets WHERE request_id = ?', [$failedJob['request_id']]), 'Failed delivery removes the issued credential.');
    $service->deliver($failedJob);
    $token = $tokenFromLastMail();
    $expect(fn () => $service->reset($token, 'short', 'short'), 'ADMIN_PASSWORD_WEAK');
    $expect(fn () => $service->reset($token, str_repeat('密', 25), str_repeat('密', 25)), 'ADMIN_PASSWORD_WEAK');
    $expect(fn () => $service->reset($token, 'New-Password-456', 'Different-Password-456'), 'ADMIN_PASSWORD_MISMATCH');
    $expect(fn () => $service->reset($token, 'Original-Password-123', 'Original-Password-123'), 'ADMIN_PASSWORD_UNCHANGED');

    // Password change signs out web, APP devices, pending device links and MCP grants.
    $auth = new AuthService();
    $username = 'recovery_admin_' . $suffix;
    $session = $auth->login($username, 'Original-Password-123', '198.51.100.31', 'RecoveryTest');
    assert_true($session !== null, 'Original credentials sign in before reset.');
    App::context()->setAdminUser($session['user']);
    $devices = new AppDeviceAuthService();
    $deviceLink = $devices->createLink($session['user'], [], '198.51.100.31', 'https://hub.example.test');
    $device = $devices->exchange($deviceLink['code'], [], 'pwa', '198.51.100.31', 'RecoveryTest', true);
    $pendingLink = $devices->createLink($session['user'], [], '198.51.100.31', 'https://hub.example.test');
    $grantId = 'recovery_grant_' . $suffix;
    $db->execute('INSERT INTO oauth_grants (grant_id, client_id, api_key_id, user_id, resource, login_method) VALUES (?, ?, ?, ?, ?, "password")', [$grantId, 'recovery_test', 1, $adminId, 'https://hub.example.test/mcp']);
    App::context()->setAdminUser(null);
    $service->reset($token, 'New-Password-456', 'New-Password-456');
    assert_same(null, $auth->userFromSession($session['session']), 'The old web session is revoked.');
    assert_same(null, $devices->accessSession($device['access']['access_token']), 'The old APP access session is revoked.');
    $expect(fn () => $devices->refresh($device['refresh_token'], '198.51.100.31', 'RecoveryTest'), 'APP_DEVICE_INVALID');
    $expect(fn () => $devices->exchange($pendingLink['code'], [], 'pwa', '198.51.100.31', 'RecoveryTest', true), 'APP_LINK_INVALID');
    assert_same('revoked', $db->first('SELECT status FROM oauth_grants WHERE grant_id = ?', [$grantId])['status'], 'MCP authorization is revoked.');
    assert_same(null, $auth->login($username, 'Original-Password-123', '198.51.100.31', 'RecoveryTest'), 'The original credential no longer signs in.');
    assert_true($auth->login($username, 'New-Password-456', '198.51.100.31', 'RecoveryTest') !== null, 'The new credential signs in.');
    $expect(fn () => $service->reset($token, 'Another-Password-789', 'Another-Password-789'), 'ADMIN_RESET_LINK_INVALID');

    // Two independent connections consuming the same link have one winner.
    $service->deliver($job($email));
    $raceToken = $tokenFromLastMail();
    $child = 'require ' . var_export(__DIR__ . '/../bootstrap.php', true) . '; try { (new \\TokenHub\\Services\\AdminPasswordResetService())->reset(' . var_export($raceToken, true) . ', "Concurrent-Password-789", "Concurrent-Password-789"); echo "OK"; } catch (\\TokenHub\\Support\\HttpAbortException $e) { echo $e->errorCode; }';
    $runners = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $runners[] = [$process, $pipes];
    }
    $outcomes = [];
    foreach ($runners as [$process, $pipes]) {
        $outcomes[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        assert_same(0, proc_close($process), 'Concurrent reset subprocess should finish without an internal failure: ' . $error);
    }
    sort($outcomes);
    assert_same(['ADMIN_RESET_LINK_INVALID', 'OK'], $outcomes, 'Only one concurrent reset may consume a link.');

    // Both super administrators and regular administrators may recover.
    $service->deliver($job('super-' . $suffix . '@example.test'));
    $service->reset($tokenFromLastMail(), 'Super-New-Password-456', 'Super-New-Password-456');
    assert_true(password_verify('Super-New-Password-456', $db->first('SELECT password_hash FROM users WHERE id = ?', [$superId])['password_hash']), 'Super-administrator recovery works.');

    // Email flood prevention stays generic; IP and reset throttles return 429.
    for ($i = 0; $i < 6; $i++) $response = $controller->forgotPassword($request(['email' => 'limit-' . $suffix . '@example.test'], '198.51.100.32'));
    assert_same(200, $response->status, 'An address over its delivery limit gets the same generic response.');
    $limitedJobs = array_filter(array_map(static fn (string $value): array => json_decode($value, true), App::redis()->connection()->lRange($queue, 0, -1)), static fn (array $value): bool => $value['email'] === 'limit-' . $suffix . '@example.test');
    assert_same(5, count($limitedJobs), 'Only five requests per address per hour are queued.');
    for ($i = 0; $i < 11; $i++) $response = $controller->forgotPassword($request(['email' => 'ip-' . $i . '-' . $suffix . '@example.test'], '198.51.100.33'));
    assert_same(429, $response->status, 'Excess public requests from one address are throttled.');
    for ($i = 0; $i < 11; $i++) {
        try { $response = $controller->resetPassword($request(['token' => 'invalid'], '198.51.100.34')); } catch (HttpAbortException) {}
    }
    assert_same(429, $response->status, 'Reset attempts are throttled independently.');
    App::context()->setAdminUser($actor);
    if ($smtpHost !== '') assert_same(200, $controller->testSmtp($request(['recipient' => 'smtp-test@example.test']))->status, 'Saved SMTP configuration sends a test message through the real transport.');
    $smtp->save(['enabled' => false]);
    $expect(fn () => $controller->forgotPassword($request(['email' => $email], '198.51.100.35')), 'SMTP_NOT_CONFIGURED');
    $db->execute('UPDATE admin_password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE user_id = ?', [$adminId]);
    assert_true($service->cleanup() > 0, 'Expired credentials are removed by maintenance.');
    $audit = json_encode($db->select('SELECT details FROM audit_logs WHERE target_type = "users" AND target_id = ?', [(string) $adminId]));
    assert_true(!str_contains($audit, 'apr_') && !str_contains($audit, 'Original-Password-123'), 'Audit details contain neither reset credentials nor passwords.');
} finally {
    App::context()->setAdminUser($originalActor);
    foreach ($userIds as $id) {
        $db->execute('DELETE FROM oauth_grants WHERE user_id = ?', [$id]);
        $db->execute('DELETE FROM admin_sessions WHERE user_id = ?', [$id]);
        $db->execute('DELETE FROM admin_app_link_codes WHERE user_id = ?', [$id]);
        $db->execute('DELETE FROM admin_app_devices WHERE user_id = ?', [$id]);
        $db->execute('DELETE FROM admin_password_resets WHERE user_id = ?', [$id]);
        $db->execute('DELETE FROM admin_user_permissions WHERE user_id = ?', [$id]);
        $db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }
    if ($departmentId > 0) $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
    if ($originalSettings === null) $db->execute('DELETE FROM admin_smtp_settings WHERE id = 1');
    else $db->execute('UPDATE admin_smtp_settings SET settings_value = ? WHERE id = 1', [$originalSettings['settings_value']]);
    App::redis()->connection()->del($queue);
    if ($originalQueue === null) unset($_ENV['QUEUE_NAME']); else $_ENV['QUEUE_NAME'] = $originalQueue;
    (new ReflectionProperty(App::class, 'config'))->setValue(null, $originalConfig);
}
