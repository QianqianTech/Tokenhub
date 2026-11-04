<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ScreenService
{
    /** Metrics a share link may be limited to (allowed_metrics). */
    public const METRICS = ['overview', 'realtime', 'trends', 'rankings', 'performance', 'security', 'multimodal', 'live'];

    /** Minimum length of an operator-chosen share code (the code alone signs a screen in). */
    public const MIN_CUSTOM_SHARE_CODE_LENGTH = 16;

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
        $customCode = trim((string) ($data['share_code'] ?? ''));
        if ($customCode !== '') {
            $this->assertCustomShareCode($customCode);
        }
        $shareCode = $customCode !== '' ? $customCode : $this->generateShareCode();
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
        if (array_key_exists('share_code', $payload)) {
            $code = trim((string) $payload['share_code']);
            if ($code === '' || $code === (string) ($existing['share_code'] ?? '')) {
                unset($payload['share_code']);
            } else {
                $this->assertCustomShareCode($code);
                $payload['share_code'] = $code;
            }
        }
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
            App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE share_link_id = ?', [$id]);
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
            App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE share_link_id = ?', [$id]);
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
            App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE share_link_id = ?', [$id]);
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
            App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE share_link_id = ?', [$id]);
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
            'SELECT l.*, s.id AS session_id, s.revoked_at, s.session_version AS active_session_version, s.updated_at AS session_seen_at,
                    TIMESTAMPDIFF(SECOND, s.updated_at, NOW()) AS session_idle_seconds,
                    TIMESTAMPDIFF(SECOND, l.expires_at, NOW()) AS link_expired_seconds
             FROM dashboard_share_sessions s JOIN dashboard_share_links l ON l.id = s.share_link_id WHERE s.session_hash = ?',
            [Crypto::hashKey($session)]
        );
        if (!$row) {
            return null;
        }
        if ($row['revoked_at'] !== null || ($row['status'] ?? '') !== 'active' || (int) $row['active_session_version'] !== (int) $row['session_version']) {
            return null;
        }
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // The link's access deadline ends existing sessions too, not only new logins.
        if ($row['expires_at'] !== null && $row['link_expired_seconds'] !== null && (int) $row['link_expired_seconds'] >= 0) {
            $this->endSession((int) $row['session_id'], (int) $row['id'], 'session_expired', $clientIp, $userAgent, 'share_expired');
            return null;
        }
        // Sliding lifetime: a session unused for longer than the idle window is over.
        if ((int) ($row['session_idle_seconds'] ?? 0) > $this->sessionIdleSeconds()) {
            $this->endSession((int) $row['session_id'], (int) $row['id'], 'session_expired', $clientIp, $userAgent, 'idle_timeout');
            return null;
        }
        if (!$this->ipAllowed($clientIp, json_decode($row['allowed_ip_cidrs'] ?? '[]', true) ?: [])) {
            $this->log((int) $row['id'], 'ip_denied', $clientIp, $userAgent, 'ip_forbidden');
            return null;
        }
        // A screen polls several endpoints every few seconds: record activity (and the
        // "refresh" access-log row) at most once per session per interval. The
        // conditional UPDATE lets exactly one of several parallel requests win.
        if ((int) ($row['session_idle_seconds'] ?? 0) >= $this->refreshLogIntervalSeconds()) {
            $touched = App::db()->execute(
                'UPDATE dashboard_share_sessions SET updated_at = NOW() WHERE id = ? AND updated_at = ?',
                [(int) $row['session_id'], $row['session_seen_at']]
            );
            if ($touched > 0) {
                $this->log((int) $row['id'], 'refresh', $clientIp, $userAgent);
            }
        }

        unset($row['session_seen_at'], $row['session_idle_seconds'], $row['link_expired_seconds']);
        return $this->publicShare($row);
    }

    /** Seconds a screen session survives without use (cookie lifetime, slid on every request). */
    public function sessionIdleSeconds(): int
    {
        return max(300, (int) App::config()->get('app.screen.session_idle_seconds', 86400));
    }

    private function refreshLogIntervalSeconds(): int
    {
        return max(1, (int) App::config()->get('app.screen.refresh_log_interval_seconds', 300));
    }

    private function endSession(int $sessionId, int $shareId, string $event, string $clientIp, string $userAgent, string $reason): void
    {
        $ended = App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$sessionId]);
        if ($ended > 0) {
            $this->log($shareId, $event, $clientIp, $userAgent, $reason);
        }
    }

    /**
     * Login throttling for /screen-api/auth/login and /share-login, per client IP and
     * per share code or username. Every attempt counts (a share code alone signs in,
     * so successful guesses must be rate limited as well).
     *
     * @return array{retry_after: int}|null null when the attempt may proceed
     */
    public function loginThrottle(string $clientIp, string $identity): ?array
    {
        $limiter = new ExternalRateLimitService();
        $checks = [
            ['screen-login-ip', $this->firstClientIp($clientIp), (int) App::config()->get('app.screen.login_per_ip_per_minute', 20)],
            ['screen-login-code', strtolower(trim($identity)), (int) App::config()->get('app.screen.login_per_code_per_minute', 10)],
        ];
        foreach ($checks as [$scope, $subject, $limit]) {
            if ($subject === '') {
                continue;
            }
            try {
                $result = $limiter->attempt($scope, $subject, $limit);
            } catch (\Throwable $throwable) {
                App::logger()->warning('Screen login throttle unavailable', ['error' => $throwable->getMessage()]);
                return null;
            }
            if (($result['allowed'] ?? true) !== true) {
                return ['retry_after' => (int) ($result['retry_after'] ?? 60)];
            }
        }

        return null;
    }

    /** Deletes access-log rows older than the retention window; returns the number removed. */
    public function pruneAccessLogs(?int $retentionDays = null): int
    {
        $days = max(1, $retentionDays ?? (int) App::config()->get('app.screen.access_log_retention_days', 90));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $removed = 0;
        do {
            $batch = App::db()->execute('DELETE FROM dashboard_share_access_logs WHERE created_at < ? LIMIT 5000', [$cutoff]);
            $removed += $batch;
        } while ($batch >= 5000);

        return $removed;
    }

    public function logout(string $session, string $clientIp): void
    {
        $hash = Crypto::hashKey($session);
        $row = App::db()->first('SELECT share_link_id FROM dashboard_share_sessions WHERE session_hash = ?', [$hash]);
        App::db()->execute('UPDATE dashboard_share_sessions SET revoked_at = UTC_TIMESTAMP() WHERE session_hash = ?', [$hash]);
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
        // allow_drilldown only ever meant "ranking rows keep their object ids"; the
        // clearer name is exposed alongside the legacy column.
        if (array_key_exists('allow_drilldown', $share)) {
            $share['show_ranking_ids'] = (bool) $share['allow_drilldown'];
        }
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
        if (array_key_exists('show_ranking_ids', $data) && !array_key_exists('allow_drilldown', $data)) {
            $data['allow_drilldown'] = $data['show_ranking_ids'];
        }
        if (array_key_exists('allowed_metrics', $data)) {
            $data['allowed_metrics'] = $this->normalizeAllowedMetrics($data['allowed_metrics']);
        }
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

    /**
     * @return list<string>|null
     */
    private function normalizeAllowedMetrics(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array_map('trim', explode(',', $value));
        }
        if (!is_array($value)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'allowed_metrics must be a list', 422);
        }
        $metrics = array_values(array_unique(array_filter(array_map(static fn (mixed $metric): string => trim((string) $metric), $value), static fn (string $metric): bool => $metric !== '')));
        $unknown = array_values(array_diff($metrics, self::METRICS));
        if ($unknown !== []) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Unknown screen metric: ' . implode(', ', $unknown) . ' (allowed: ' . implode(', ', self::METRICS) . ')', 422);
        }

        return $metrics;
    }

    /**
     * A share code alone signs a screen in, so an operator-chosen code must be as hard to
     * guess as a generated one: at least 16 characters of [A-Za-z0-9_-], mixing letters
     * and digits, at least 10 distinct characters, no runs ("aaaa", "12345") and no
     * word-like stretches of 6+ same-case letters. A heuristic: leaving the code empty
     * (generated) is always the safer choice.
     */
    public function assertCustomShareCode(string $code): void
    {
        if (!self::customShareCodeAcceptable($code)) {
            abort_json(
                'ADMIN_INVALID_PAYLOAD',
                '自定义链接码至少 ' . self::MIN_CUSTOM_SHARE_CODE_LENGTH . ' 位，只能包含字母、数字、下划线和连字符，需同时包含字母和数字、看起来随机（不能有单词或简单重复）；建议留空自动生成。',
                422
            );
        }
    }

    public static function customShareCodeAcceptable(string $code): bool
    {
        if (strlen($code) < self::MIN_CUSTOM_SHARE_CODE_LENGTH || strlen($code) > 80) {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            return false;
        }
        if (preg_match('/[A-Za-z]/', $code) !== 1 || preg_match('/[0-9]/', $code) !== 1) {
            return false;
        }
        if (count(array_unique(str_split(strtolower($code)))) < 10) {
            return false;
        }
        // Word-like stretches ("lobby", "screen") make a code guessable.
        if (preg_match('/[a-z]{6,}|[A-Z]{6,}/', $code) === 1) {
            return false;
        }
        // Long runs of one character ("aaaa") or of consecutive characters ("12345", "abcde").
        $run = 1;
        $sequence = 1;
        for ($i = 1, $length = strlen($code); $i < $length; $i++) {
            $run = $code[$i] === $code[$i - 1] ? $run + 1 : 1;
            $sequence = ord(strtolower($code[$i])) === ord(strtolower($code[$i - 1])) + 1 ? $sequence + 1 : 1;
            if ($run >= 4 || $sequence >= 5) {
                return false;
            }
        }

        return true;
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
