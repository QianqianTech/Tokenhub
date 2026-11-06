<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\UiLocale;
use Throwable;

final class AdminPasswordResetService
{
    public const REQUEST_MESSAGE = '如果该邮箱已绑定启用的管理员账号，您将收到密码重置邮件，请检查收件箱和垃圾邮件。';

    public static function requestMessage(string $locale): string
    {
        return $locale === 'en-US'
            ? 'If this email is linked to an active administrator account, we will send a password reset link. Check your inbox and spam folder.'
            : self::REQUEST_MESSAGE;
    }

    /** @param null|callable(array, string, string, string): void $mailSender */
    public function __construct(private readonly mixed $mailSender = null)
    {
    }

    public static function normalizeEmail(mixed $email): string
    {
        if (!is_string($email)) {
            abort_json('ADMIN_INVALID_EMAIL', '请输入有效的邮箱地址', 422);
        }
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            abort_json('ADMIN_INVALID_EMAIL', '请输入有效的邮箱地址', 422);
        }
        return $email;
    }

    public static function latestRequestKey(string $email): string
    {
        return 'admin_recovery_latest:' . hash('sha256', $email);
    }

    /**
     * Only the worker looks up the address. HTTP responses and queue work are
     * identical for known and unknown addresses; no token is placed in Redis.
     */
    public function deliver(array $job): void
    {
        $requestId = (string) ($job['request_id'] ?? '');
        $requestedAt = (int) ($job['requested_at'] ?? 0);
        if (preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1 || $requestedAt < time() - 3600) {
            return;
        }
        $email = self::normalizeEmail($job['email'] ?? null);
        $settings = (new SmtpSettingsService())->transportSettings();
        $issued = App::db()->transaction(function () use ($email, $requestId, $settings): ?array {
            // All issuance and consumption lock the user first, serializing
            // multiple workers and simultaneous requests for the same account.
            $user = App::db()->first(
                'SELECT u.* FROM users u JOIN departments d ON d.id = u.department_id WHERE u.email = ? AND u.role IN ("admin", "super_admin") AND u.status = "active" AND d.status = "active" FOR UPDATE',
                [$email]
            );
            if (!$user || App::redis()->get(self::latestRequestKey($email)) !== $requestId
                || App::db()->first('SELECT id FROM admin_password_resets WHERE request_id = ?', [$requestId])) {
                return null;
            }
            $token = Crypto::token('apr_');
            App::db()->execute('UPDATE admin_password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
            App::db()->execute(
                'INSERT INTO admin_password_resets (request_id, user_id, email, token_hash, password_fingerprint, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))',
                [$requestId, $user['id'], $email, Crypto::hashKey($token), hash('sha256', $user['password_hash']), $settings['reset_ttl_minutes']]
            );
            return ['token' => $token, 'user_id' => (int) $user['id']];
        });
        if ($issued === null) {
            return;
        }
        // A fragment keeps the credential out of server access logs and referrers.
        $english = UiLocale::normalize($job['locale'] ?? null) === 'en-US';
        $link = $settings['public_url'] . '/admin/' . ($english ? '?lang=en-US' : '') . '#reset-password=' . $issued['token'];
        $minutes = (int) $settings['reset_ttl_minutes'];
        $subject = $english ? 'Reset your TokenHub administrator password' : 'TokenHub 管理员密码重置';
        $body = $english
            ? "Open the following link to set a new TokenHub administrator password:\n{$link}\n\n"
                . "This link expires in {$minutes} minutes and works once. Requesting another link invalidates this one.\n"
                . "If you did not request a reset, you can ignore this email. Your password will stay the same.\n"
            : "您正在申请重置 TokenHub 管理员密码。\n\n请打开以下链接并设置新密码：\n{$link}"
                . "\n\n链接在 {$minutes} 分钟内有效，仅可使用一次。再次申请后，之前的链接将失效。\n"
                . "如果您没有申请重置，请忽略此邮件，您的密码不会改变。\n";
        try {
            if (is_callable($this->mailSender)) {
                ($this->mailSender)($settings, $email, $subject, $body);
            } else {
                (new SmtpMailer())->send($settings, $email, $subject, $body);
            }
        } catch (Throwable $exception) {
            // Retrying an unsent job may issue a fresh link, but the failed
            // delivery must not leave a usable credential behind.
            App::db()->execute('DELETE FROM admin_password_resets WHERE token_hash = ?', [Crypto::hashKey($issued['token'])]);
            throw $exception;
        }
        (new AuditService())->write('admin_password_reset.email_sent', 'users', $issued['user_id']);
    }

    public function reset(mixed $token, mixed $password, mixed $confirmation): void
    {
        if (!is_string($token) || preg_match('/^apr_[a-f0-9]{64}$/D', $token) !== 1) {
            $this->invalidLink();
        }
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0") || preg_match('/^.{12,}$/usD', $password) !== 1) {
            abort_json('ADMIN_PASSWORD_WEAK', '新密码至少 12 个字符，最多 72 字节', 422);
        }
        if (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
            abort_json('ADMIN_PASSWORD_MISMATCH', '两次输入的新密码不一致', 422);
        }
        $hash = Crypto::hashKey($token);
        $reset = App::db()->first('SELECT user_id FROM admin_password_resets WHERE token_hash = ?', [$hash]);
        if (!$reset) {
            $this->invalidLink();
        }
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        App::db()->transaction(function () use ($reset, $hash, $password, $newHash): void {
            $user = App::db()->first('SELECT u.*, d.status AS department_status FROM users u JOIN departments d ON d.id = u.department_id WHERE u.id = ? FOR UPDATE', [$reset['user_id']]);
            $row = App::db()->first('SELECT * FROM admin_password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()', [$hash]);
            if (!$user || !$row || !in_array($user['role'], ['admin', 'super_admin'], true) || $user['status'] !== 'active' || $user['department_status'] !== 'active'
                || $row['email'] !== $user['email'] || !hash_equals($row['password_fingerprint'], hash('sha256', $user['password_hash']))) {
                $this->invalidLink();
            }
            if (password_verify($password, $user['password_hash'])) {
                abort_json('ADMIN_PASSWORD_UNCHANGED', '新密码不能与原密码相同', 422);
            }
            $claimed = App::db()->execute('UPDATE admin_password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()', [$row['id']]);
            if ($claimed !== 1) {
                $this->invalidLink();
            }
            $id = (int) $user['id'];
            App::db()->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$newHash, $id]);
            App::db()->execute('UPDATE admin_password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$id]);
            (new AdminUserService())->revokeSessions($id, false);
            (new AppDeviceAuthService())->revokeUserDevices($id, 'password_reset');
            \TokenHub\Services\Mcp\OAuth\OAuthServer::revokeUserGrantsAfterPasswordChange($id);
            (new AuditService())->write('admin_password_reset.completed', 'users', $id);
        });
    }

    public function cleanup(): int
    {
        return App::db()->execute('DELETE FROM admin_password_resets WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)');
    }

    private function invalidLink(): never
    {
        abort_json('ADMIN_RESET_LINK_INVALID', '重置链接无效或已过期，请重新申请', 422);
    }
}
