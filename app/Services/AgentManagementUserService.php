<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class AgentManagementUserService
{
    public function list(array $apiKey, array $query): array
    {
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $departmentId = $this->departmentId($apiKey);
        $rows = App::db()->select(
            'SELECT id, department_id, username, role, status, created_at, updated_at FROM users WHERE department_id = ? AND role = "user" ORDER BY id DESC LIMIT ? OFFSET ?',
            [$departmentId, $limit, $offset]
        );
        $total = (int) (App::db()->first('SELECT COUNT(*) AS total FROM users WHERE department_id = ? AND role = "user"', [$departmentId])['total'] ?? 0);
        return ['data' => $rows, 'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total]];
    }

    public function show(int $id, array $apiKey): array
    {
        return $this->user($id, $apiKey);
    }

    public function create(array $apiKey, array $data): array
    {
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if ($username === '' || strlen($username) > 120 || $password === '') {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'username and password are required', 422);
        }
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'disabled', 'departed'], true)) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Invalid user status', 422);
        }
        App::db()->execute(
            'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", ?)',
            [$this->departmentId($apiKey), $username, password_hash($password, PASSWORD_DEFAULT), $status]
        );
        $id = (int) App::db()->pdo()->lastInsertId();
        (new AuditService())->write('agent_management.user.create', 'users', $id, ['department_id' => $this->departmentId($apiKey), 'username' => $username]);
        return $this->user($id, $apiKey);
    }

    public function update(int $id, array $apiKey, array $data): array
    {
        $this->user($id, $apiKey);
        $this->assertUserManageable($id, $apiKey);
        $updates = [];
        foreach (['username', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $field === 'username' ? trim((string) $data[$field]) : (string) $data[$field];
            }
        }
        if (array_key_exists('password', $data)) {
            if (!is_string($data['password']) || $data['password'] === '') {
                abort_json('MANAGEMENT_INVALID_PAYLOAD', 'password must be a non-empty string', 422);
            }
            $updates['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (($updates['username'] ?? 'ok') === '' || strlen($updates['username'] ?? '') > 120 || (isset($updates['status']) && !in_array($updates['status'], ['active', 'disabled', 'departed'], true))) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Invalid user update payload', 422);
        }
        if ($updates !== []) {
            $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", array_keys($updates)));
            App::db()->execute("UPDATE users SET {$sets} WHERE id = ?", [...array_values($updates), $id]);
            if (isset($updates['status']) || isset($updates['password_hash'])) {
                // Cached API key rows carry the user's state; a disabled or
                // departed user must stop authenticating immediately.
                (new ApiKeyService())->invalidateByUser($id);
            }
            (new AuditService())->write('agent_management.user.update', 'users', $id, array_diff_key($updates, ['password_hash' => true]));
        }
        return $this->user($id, $apiKey);
    }

    public function disable(int $id, array $apiKey): array
    {
        $this->user($id, $apiKey);
        $this->assertUserManageable($id, $apiKey);
        App::db()->execute('UPDATE users SET status = "disabled" WHERE id = ?', [$id]);
        (new ApiKeyService())->invalidateByUser($id);
        (new AuditService())->write('agent_management.user.disable', 'users', $id);
        return ['id' => $id, 'disabled' => true];
    }

    /**
     * Disabling a user (or changing their password) disables every key the
     * user owns, so the delegation rule for keys applies to their owners too.
     */
    private function assertUserManageable(int $id, array $apiKey): void
    {
        if (!(new ApiKeyManagementScopeService())->canManageUser((int) ($apiKey['id'] ?? 0), $id)) {
            abort_json('MANAGEMENT_TARGET_FORBIDDEN', 'This user owns a management API key outside the calling key\'s delegated keys', 403);
        }
    }

    private function user(int $id, array $apiKey): array
    {
        $row = App::db()->first(
            'SELECT id, department_id, username, role, status, created_at, updated_at FROM users WHERE id = ? AND department_id = ?',
            [$id, $this->departmentId($apiKey)]
        );
        if ($row === null || ($row['role'] ?? '') !== 'user') {
            abort_json('MANAGEMENT_RESOURCE_NOT_FOUND', 'User not found in API key department', 404);
        }
        return $row;
    }

    private function departmentId(array $apiKey): int
    {
        $departmentId = (int) ($apiKey['department_id'] ?? 0);
        if ($departmentId <= 0) {
            abort_json('MANAGEMENT_FORBIDDEN', 'API key has no management department', 403);
        }
        return $departmentId;
    }
}
