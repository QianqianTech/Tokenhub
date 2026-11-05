<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class AuthService
{
    public function login(string $username, string $password, string $clientIp, string $userAgent): ?array
    {
        // Serialize verification + session creation with password recovery, so
        // an in-flight sign-in cannot create a session with the old password
        // after the reset has revoked that administrator's sessions.
        $failedUserId = null;
        $result = App::db()->transaction(function () use ($username, $password, $clientIp, $userAgent, &$failedUserId): ?array {
            $user = App::db()->first('SELECT * FROM users WHERE username = ? AND status = "active" AND role IN ("admin", "super_admin") FOR UPDATE', [$username]);
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $failedUserId = $user ? (int) $user['id'] : null;
                return null;
            }

            $session = Crypto::token('adm_');
            $csrf = Crypto::token('csrf_');
            $ttlMinutes = (int) App::config()->get('app.admin_session_ttl_minutes', 120);
            App::db()->execute(
                'INSERT INTO admin_sessions (user_id, session_hash, csrf_token_hash, client_ip, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))',
                [$user['id'], Crypto::hashKey($session), Crypto::hashKey($csrf), $clientIp, substr($userAgent, 0, 255), $ttlMinutes]
            );
            App::db()->execute('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
            (new AuditService())->write('admin.login', 'users', $user['id']);

            return ['user' => $this->publicUser($user), 'session' => $session, 'csrf' => $csrf];
        });
        if ($result === null) {
            // SEC-110: failed sign-ins are audited (username and reason only, never the password).
            try {
                (new AuditService())->write('admin.login.failed', 'users', $failedUserId, [
                    'username' => mb_substr($username, 0, 120),
                    'reason' => $failedUserId === null ? 'unknown_or_inactive_account' : 'invalid_password',
                ]);
            } catch (\Throwable) {
                // Auditing must never turn a refused sign-in into a server error.
            }
        }

        return $result;
    }

    /**
     * Expiry time (server clock, Y-m-d H:i:s) of an active admin session, or null.
     */
    public function sessionExpiresAt(string $session): ?string
    {
        if ($session === '') {
            return null;
        }
        $row = App::db()->first(
            'SELECT expires_at FROM admin_sessions WHERE session_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [Crypto::hashKey($session)]
        );
        return $row ? (string) $row['expires_at'] : null;
    }

    /**
     * Slides an active session forward by the configured TTL. Expired or revoked
     * sessions cannot be renewed.
     */
    public function renew(string $session): ?string
    {
        if ($session === '') {
            return null;
        }
        $ttlMinutes = (int) App::config()->get('app.admin_session_ttl_minutes', 120);
        App::db()->execute(
            'UPDATE admin_sessions SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE session_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [$ttlMinutes, Crypto::hashKey($session)]
        );
        return $this->sessionExpiresAt($session);
    }

    public function logout(string $session): void
    {
        App::db()->execute('UPDATE admin_sessions SET revoked_at = UTC_TIMESTAMP() WHERE session_hash = ?', [Crypto::hashKey($session)]);
        (new AuditService())->write('admin.logout');
    }

    public function userFromSession(string $session): ?array
    {
        if ($session === '') {
            return null;
        }

        $row = App::db()->first(
            // Console cookie sessions only: APP access tokens (channel "app") are
            // accepted as bearer tokens by AppDeviceAuthService::accessSession().
            'SELECT u.* FROM admin_sessions s JOIN users u ON u.id = s.user_id JOIN departments d ON d.id = u.department_id WHERE s.session_hash = ? AND s.channel = "web" AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP() AND u.status = "active" AND d.status = "active"',
            [Crypto::hashKey($session)]
        );

        return $row ? $this->publicUser($row) : null;
    }

    public function validateCsrf(string $session, string $token): bool
    {
        if ($session === '' || $token === '') {
            return false;
        }

        $row = App::db()->first('SELECT csrf_token_hash FROM admin_sessions WHERE session_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()', [Crypto::hashKey($session)]);
        return $row !== null && hash_equals($row['csrf_token_hash'], Crypto::hashKey($token));
    }

    public function publicUser(array $user): array
    {
        unset($user['password_hash']);
        $user['permissions'] = (new PermissionService())->permissionsFor($user);
        if (($user['role'] ?? '') === 'super_admin') {
            // Sidebar only; the API checks the role itself.
            $user['permissions'][] = ConversationLogService::CAPABILITY;
        }
        return $user;
    }
}
