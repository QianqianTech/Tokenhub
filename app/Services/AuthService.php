<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class AuthService
{
    public function login(string $username, string $password, string $clientIp, string $userAgent): ?array
    {
        $user = App::db()->first('SELECT * FROM users WHERE username = ? AND status = "active" AND role IN ("admin", "super_admin")', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $session = Crypto::token('adm_');
        $csrf = Crypto::token('csrf_');
        $ttlMinutes = (int) App::config()->get('app.admin_session_ttl_minutes', 120);
        App::db()->execute(
            'INSERT INTO admin_sessions (user_id, session_hash, csrf_token_hash, client_ip, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [$user['id'], Crypto::hashKey($session), Crypto::hashKey($csrf), $clientIp, substr($userAgent, 0, 255), $ttlMinutes]
        );
        App::db()->execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        (new AuditService())->write('admin.login', 'users', $user['id']);

        return ['user' => $this->publicUser($user), 'session' => $session, 'csrf' => $csrf];
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
            'SELECT expires_at FROM admin_sessions WHERE session_hash = ? AND revoked_at IS NULL AND expires_at > NOW()',
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
            'UPDATE admin_sessions SET expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE session_hash = ? AND revoked_at IS NULL AND expires_at > NOW()',
            [$ttlMinutes, Crypto::hashKey($session)]
        );
        return $this->sessionExpiresAt($session);
    }

    public function logout(string $session): void
    {
        App::db()->execute('UPDATE admin_sessions SET revoked_at = NOW() WHERE session_hash = ?', [Crypto::hashKey($session)]);
        (new AuditService())->write('admin.logout');
    }

    public function userFromSession(string $session): ?array
    {
        if ($session === '') {
            return null;
        }

        $row = App::db()->first(
            'SELECT u.* FROM admin_sessions s JOIN users u ON u.id = s.user_id JOIN departments d ON d.id = u.department_id WHERE s.session_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW() AND u.status = "active" AND d.status = "active"',
            [Crypto::hashKey($session)]
        );

        return $row ? $this->publicUser($row) : null;
    }

    public function validateCsrf(string $session, string $token): bool
    {
        if ($session === '' || $token === '') {
            return false;
        }

        $row = App::db()->first('SELECT csrf_token_hash FROM admin_sessions WHERE session_hash = ? AND revoked_at IS NULL AND expires_at > NOW()', [Crypto::hashKey($session)]);
        return $row !== null && hash_equals($row['csrf_token_hash'], Crypto::hashKey($token));
    }

    private function publicUser(array $user): array
    {
        unset($user['password_hash']);
        $user['permissions'] = (new PermissionService())->permissionsFor($user);
        return $user;
    }
}
