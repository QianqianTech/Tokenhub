<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ScreenService
{
    public function listShareLinks(array $query = []): array
    {
        $conditions = [];
        $bindings = [];
        if (($query['status'] ?? '') !== '') {
            $conditions[] = 'status = ?';
            $bindings[] = $query['status'];
        }
        [$scopeWhere, $scopeBindings] = $this->shareScopeWhere(App::context()->adminUser());
        if ($scopeWhere !== '') {
            $conditions[] = $scopeWhere;
            $bindings = [...$bindings, ...$scopeBindings];
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        return array_map(
            fn (array $row): array => $this->sanitizeShare($row),
            App::db()->select("SELECT * FROM dashboard_share_links{$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $bindings)
        );
    }

    public function listAccessLogs(array $query = []): array
    {
        $conditions = [];
        $bindings = [];
        foreach (['share_link_id', 'event_type'] as $field) {
            if (($query[$field] ?? '') !== '') {
                $conditions[] = "{$field} = ?";
                $bindings[] = $query[$field];
            }
        }
        if (($query['from'] ?? '') !== '') {
            $conditions[] = 'created_at >= ?';
            $bindings[] = $query['from'];
        }
        if (($query['to'] ?? '') !== '') {
            $conditions[] = 'created_at <= ?';
            $bindings[] = $query['to'];
        }
        [$scopeWhere, $scopeBindings] = $this->shareScopeWhere(App::context()->adminUser());
        if ($scopeWhere !== '') {
            $conditions[] = "share_link_id IN (SELECT id FROM dashboard_share_links WHERE {$scopeWhere})";
            $bindings = [...$bindings, ...$scopeBindings];
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        return App::db()->select("SELECT * FROM dashboard_share_access_logs{$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $bindings);
    }

    public function createShareLink(array $data): array
    {
        $password = (string) ($data['password'] ?? Crypto::token('scr_pwd_'));
        $shareCode = (string) ($data['share_code'] ?? $this->generateShareCode());
        $payload = $this->storagePayload($data, true);
        $payload['share_code'] = $shareCode;
        $payload['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $this->assertShareScopeAllowed($payload);

        $columns = array_keys($payload);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        App::db()->execute(
            sprintf('INSERT INTO dashboard_share_links (%s) VALUES (%s)', implode(', ', $columns), $placeholders),
            array_values($payload)
        );

        $id = (int) App::db()->pdo()->lastInsertId();
        $this->audit('dashboard_share_link.create', $id, [
            'share_code' => $shareCode,
            'username' => $payload['username'],
        ]);

        return [
            ...$this->sanitizeShare(App::db()->first('SELECT * FROM dashboard_share_links WHERE id = ?', [$id]) ?: ['id' => $id]),
            'password' => $password,
            'share_url' => '/screen/' . rawurlencode($shareCode),
        ];
    }

    public function updateShareLink(int $id, array $data): array
    {
        $existing = $this->assertExistingShareAllowed($id);
        $payload = $this->storagePayload($data, false);
        unset($payload['password_hash']);
        if ($payload !== []) {
            $this->assertShareScopeAllowed([...$existing, ...$payload]);
        }
        if ($payload !== []) {
            $sets = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($payload)));
            App::db()->execute("UPDATE dashboard_share_links SET {$sets} WHERE id = ?", [...array_values($payload), $id]);
            $this->audit('dashboard_share_link.update', $id, $payload);
        }

        return $this->sanitizeShare(App::db()->first('SELECT * FROM dashboard_share_links WHERE id = ?', [$id]) ?: ['id' => $id]);
    }

    public function disableShareLink(int $id): array
    {
        $this->assertExistingShareAllowed($id);
        App::db()->transaction(function () use ($id): void {
            App::db()->execute('UPDATE dashboard_share_links SET status = "disabled", session_version = session_version + 1 WHERE id = ?', [$id]);
            App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE share_link_id = ?', [$id]);
            $this->log($id, 'session_revoked', $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? '', 'link_disabled');
        });
        $this->audit('dashboard_share_link.disable', $id);
        return ['id' => $id, 'disabled' => true];
    }

    public function resetPassword(int $id, ?string $password = null): array
    {
        $this->assertExistingShareAllowed($id);
        $plain = $password ?: Crypto::token('scr_pwd_');
        App::db()->transaction(function () use ($id, $plain): void {
            App::db()->execute(
                'UPDATE dashboard_share_links SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',
                [password_hash($plain, PASSWORD_DEFAULT), $id]
            );
            App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE share_link_id = ?', [$id]);
            $this->log($id, 'session_revoked', $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? '', 'password_reset');
        });
        $this->audit('dashboard_share_link.password_reset', $id);

        return [
            ...$this->sanitizeShare(App::db()->first('SELECT * FROM dashboard_share_links WHERE id = ?', [$id]) ?: ['id' => $id]),
            'password' => $plain,
        ];
    }

    public function revokeSessions(int $id): array
    {
        $this->assertExistingShareAllowed($id);
        App::db()->transaction(function () use ($id): void {
            App::db()->execute('UPDATE dashboard_share_links SET session_version = session_version + 1 WHERE id = ?', [$id]);
            App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE share_link_id = ?', [$id]);
            $this->log($id, 'session_revoked', $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? '', 'admin_revoked');
        });
        $this->audit('dashboard_share_link.sessions_revoke', $id);
        return ['id' => $id, 'revoked' => true];
    }

    public function loginByShareCode(string $shareCode, string $clientIp, string $userAgent): array
    {
        $share = App::db()->first('SELECT * FROM dashboard_share_links WHERE share_code = ?', [$shareCode]);
        if (!$share) {
            return $this->error('SCREEN_UNAUTHENTICATED', 'Invalid screen link', 401);
        }

        $error = $this->validateShareForLogin($share, $clientIp, $userAgent);
        if ($error !== null) {
            return $error;
        }

        return $this->createSession($share, $clientIp, $userAgent);
    }

    public function loginByCredentials(string $username, string $password, string $clientIp, string $userAgent): array
    {
        $shares = App::db()->select('SELECT * FROM dashboard_share_links WHERE username = ? ORDER BY id DESC LIMIT 20', [$username]);
        foreach ($shares as $share) {
            if (!password_verify($password, $share['password_hash'])) {
                continue;
            }

            $error = $this->validateShareForLogin($share, $clientIp, $userAgent);
            if ($error !== null) {
                return $error;
            }

            return $this->createSession($share, $clientIp, $userAgent);
        }

        return $this->error('SCREEN_UNAUTHENTICATED', 'Invalid screen credentials', 401);
    }

    public function login(string $shareCode, string $username, string $password, string $clientIp, string $userAgent): array
    {
        $share = App::db()->first('SELECT * FROM dashboard_share_links WHERE share_code = ? AND username = ?', [$shareCode, $username]);
        if (!$share) {
            return $this->error('SCREEN_UNAUTHENTICATED', 'Invalid screen credentials', 401);
        }
        $error = $this->validateShareForLogin($share, $clientIp, $userAgent);
        if ($error !== null) {
            return $error;
        }
        if (!password_verify($password, $share['password_hash'])) {
            $this->log((int) $share['id'], 'login_failed', $clientIp, $userAgent, 'bad_password');
            return $this->error('SCREEN_UNAUTHENTICATED', 'Invalid screen credentials', 401);
        }

        return $this->createSession($share, $clientIp, $userAgent);
    }

    public function allowClientIp(int $id, string $clientIp): array
    {
        $share = $this->assertExistingShareAllowed($id);

        $ip = $this->firstClientIp($clientIp);
        if ($ip === '' || $ip === 'unknown') {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Client IP is required', 422);
        }

        $cidrs = json_decode($share['allowed_ip_cidrs'] ?? '[]', true);
        $cidrs = is_array($cidrs) ? array_values(array_filter(array_map('strval', $cidrs))) : [];
        if (!in_array($ip, $cidrs, true)) {
            $cidrs[] = $ip;
            App::db()->execute(
                'UPDATE dashboard_share_links SET allowed_ip_cidrs = ?, session_version = session_version + 1 WHERE id = ?',
                [json_encode($cidrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]
            );
            App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE share_link_id = ?', [$id]);
            $this->audit('dashboard_share_link.allow_client_ip', $id, ['client_ip' => $ip]);
        }

        return $this->sanitizeShare(App::db()->first('SELECT * FROM dashboard_share_links WHERE id = ?', [$id]) ?: ['id' => $id]);
    }

    private function validateShareForLogin(array $share, string $clientIp, string $userAgent): ?array
    {
        if (($share['status'] ?? '') !== 'active') {
            $this->log((int) $share['id'], 'link_disabled', $clientIp, $userAgent, 'link_disabled');
            return $this->error('SCREEN_LINK_DISABLED', 'Screen link is disabled', 403);
        }
        if ($share['expires_at'] && strtotime($share['expires_at']) < time()) {
            $this->log((int) $share['id'], 'login_expired', $clientIp, $userAgent, 'share_expired');
            return $this->error('SCREEN_LINK_EXPIRED', 'Screen link login has expired', 403);
        }
        if (!$this->ipAllowed($clientIp, json_decode($share['allowed_ip_cidrs'] ?? '[]', true) ?: [])) {
            $this->log((int) $share['id'], 'ip_denied', $clientIp, $userAgent, 'ip_forbidden');
            return $this->error('SCREEN_IP_FORBIDDEN', 'Client IP is not allowed', 403);
        }

        return null;
    }

    private function createSession(array $share, string $clientIp, string $userAgent): array
    {
        $session = Crypto::token('scr_');
        App::db()->execute(
            'INSERT INTO dashboard_share_sessions (share_link_id, session_hash, session_version, client_ip, user_agent) VALUES (?, ?, ?, ?, ?)',
            [$share['id'], Crypto::hashKey($session), $share['session_version'], $clientIp, substr($userAgent, 0, 255)]
        );
        $this->log((int) $share['id'], 'login_success', $clientIp, $userAgent);
        return ['ok' => true, 'session' => $session, 'share' => $this->publicShare($share)];
    }

    public function session(string $session, string $clientIp): ?array
    {
        if ($session === '') {
            return null;
        }
        $row = App::db()->first(
            'SELECT l.*, s.id AS session_id, s.revoked_at, s.session_version AS active_session_version FROM dashboard_share_sessions s JOIN dashboard_share_links l ON l.id = s.share_link_id WHERE s.session_hash = ?',
            [Crypto::hashKey($session)]
        );
        if (!$row) {
            return null;
        }
        if ($row['revoked_at'] !== null || ($row['status'] ?? '') !== 'active' || (int) $row['active_session_version'] !== (int) $row['session_version']) {
            return null;
        }
        if (!$this->ipAllowed($clientIp, json_decode($row['allowed_ip_cidrs'] ?? '[]', true) ?: [])) {
            $this->log((int) $row['id'], 'ip_denied', $clientIp, $_SERVER['HTTP_USER_AGENT'] ?? '', 'ip_forbidden');
            return null;
        }
        $this->log((int) $row['id'], 'refresh', $clientIp, $_SERVER['HTTP_USER_AGENT'] ?? '');
        return $this->publicShare($row);
    }

    public function logout(string $session, string $clientIp): void
    {
        $hash = Crypto::hashKey($session);
        $row = App::db()->first('SELECT share_link_id FROM dashboard_share_sessions WHERE session_hash = ?', [$hash]);
        App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = NOW() WHERE session_hash = ?', [$hash]);
        if ($row) {
            $this->log((int) $row['share_link_id'], 'logout', $clientIp, $_SERVER['HTTP_USER_AGENT'] ?? '');
        }
    }

    private function publicShare(array $share): array
    {
        return $this->sanitizeShare($share);
    }

    private function sanitizeShare(array $share): array
    {
        unset($share['password_hash'], $share['revoked_at']);
        $share['screen'] = true;
        return $share;
    }

    private function storagePayload(array $data, bool $creating): array
    {
        $allowed = [
            'share_code',
            'name',
            'username',
            'scope_type',
            'scope_ids',
            'allowed_metrics',
            'allow_drilldown',
            'allowed_ip_cidrs',
            'expires_at',
            'status',
        ];
        $payload = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (in_array($field, ['scope_ids', 'allowed_metrics', 'allowed_ip_cidrs'], true) && is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                if ($field === 'allow_drilldown') {
                    $value = filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0;
                }
                if ($value === '') {
                    $value = null;
                }
                $payload[$field] = $value;
            }
        }

        if ($creating) {
            $payload['name'] = (string) ($payload['name'] ?? 'Screen Share');
            $payload['username'] = (string) ($payload['username'] ?? 'screen');
            $payload['scope_type'] = (string) ($payload['scope_type'] ?? 'global');
            $payload['status'] = (string) ($payload['status'] ?? 'active');
        }

        unset($payload['password']);
        return $payload;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function shareScopeWhere(?array $actor): array
    {
        if ($actor === null || (new PermissionService())->usesGlobalScope($actor)) {
            return ['', []];
        }
        $departmentIds = $this->adminDepartmentScopeIds($actor);
        if ($departmentIds === []) {
            return ['1 = 0', []];
        }

        $checks = implode(' OR ', array_fill(0, count($departmentIds), 'JSON_CONTAINS(COALESCE(scope_ids, JSON_ARRAY()), CAST(? AS JSON))'));
        return ['scope_type = "department" AND (' . $checks . ')', array_map('strval', $departmentIds)];
    }

    /**
     * @param array<string, mixed> $share
     */
    private function assertShareScopeAllowed(array $share): void
    {
        $actor = App::context()->adminUser();
        if ($actor === null) {
            return;
        }

        $permission = new PermissionService();
        $error = $permission->requireAdmin($actor);
        if ($error !== null) {
            abort_json($error['code'], $error['message'], $error['status']);
        }
        if ((new PermissionService())->usesGlobalScope($actor)) {
            return;
        }
        if (($share['scope_type'] ?? '') !== 'department') {
            abort_json('ADMIN_PERMISSION_DENIED', 'Screen share scope is outside admin scope', 403);
        }

        $scopeIds = $this->jsonIntList($share['scope_ids'] ?? []);
        if ($scopeIds === []) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Screen share department scope is required', 403);
        }
        foreach ($scopeIds as $departmentId) {
            if (!$permission->canManage($actor, $departmentId)) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Screen share scope is outside admin scope', 403);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function assertExistingShareAllowed(int $id): array
    {
        $share = App::db()->first('SELECT * FROM dashboard_share_links WHERE id = ?', [$id]);
        if (!$share) {
            abort_json('ADMIN_NOT_FOUND', 'Screen share link not found', 404);
        }
        $this->assertShareScopeAllowed($share);

        return $share;
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<int, int>
     */
    private function adminDepartmentScopeIds(array $actor): array
    {
        $roots = $this->jsonIntList($actor['managed_department_ids'] ?? []);
        if (!empty($actor['department_id'])) {
            $roots[] = (int) $actor['department_id'];
        }
        $ids = [];
        foreach (array_values(array_unique($roots)) as $root) {
            $department = App::db()->first('SELECT path FROM departments WHERE id = ?', [$root]);
            if ($department === null) {
                continue;
            }
            foreach (App::db()->select('SELECT id FROM departments WHERE path LIKE ?', [(string) $department['path'] . '%']) as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function jsonIntList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
    }

    private function generateShareCode(): string
    {
        return 'screen_' . bin2hex(random_bytes(12));
    }

    private function error(string $code, string $message, int $status): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'status' => $status];
    }

    private function audit(string $action, int $id, array $details = []): void
    {
        try {
            (new AuditService())->write($action, 'dashboard_share_links', $id, $details);
        } catch (\Throwable $throwable) {
            App::logger()->error('Share link audit write failed', [
                'action' => $action,
                'target_id' => $id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function log(int $shareId, string $event, ?string $clientIp, string $userAgent, ?string $reason = null): void
    {
        App::db()->execute('INSERT INTO dashboard_share_access_logs (share_link_id, event_type, client_ip, user_agent, reason) VALUES (?, ?, ?, ?, ?)', [$shareId, $event, $clientIp, substr($userAgent, 0, 255), $reason]);
    }

    private function ipAllowed(string $ip, array $cidrs): bool
    {
        return (new ApiKeyService())->ipAllowed($ip, $cidrs);
    }

    private function firstClientIp(string $clientIp): string
    {
        $parts = explode(',', $clientIp);
        return trim((string) ($parts[0] ?? $clientIp));
    }
}
