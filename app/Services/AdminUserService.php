<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class AdminUserService
{
    /**
     * @return list<string>
     */
    public static function adminRoles(): array
    {
        return ['admin', 'super_admin'];
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function list(array $query): array
    {
        $this->requireSuperAdminActor();

        $conditions = ['role IN ("admin", "super_admin")'];
        $bindings = [];
        foreach (['department_id', 'status', 'role'] as $field) {
            if (!isset($query[$field]) || $query[$field] === '') {
                continue;
            }
            if ($field === 'role' && !in_array((string) $query[$field], self::adminRoles(), true)) {
                continue;
            }
            $conditions[] = "{$field} = ?";
            $bindings[] = $query[$field];
        }

        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $where = ' WHERE ' . implode(' AND ', $conditions);

        return array_map(
            fn (array $row): array => $this->presentAdminUser($row),
            App::db()->select("SELECT * FROM users{$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $bindings)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->requireSuperAdminActor();

        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $departmentId = (int) ($data['department_id'] ?? 0);
        $role = $this->adminRole((string) ($data['role'] ?? 'admin'));
        $managedDepartmentIds = $this->managedDepartmentIds($data['managed_department_ids'] ?? []);
        [$adminType, $adminScope, $permissionMode, $permissionCodes] = $this->administratorAccess($role, $data);

        if ($username === '') {
            abort_json('ADMIN_INVALID_PAYLOAD', 'username is required', 422);
        }
        if ($password === '') {
            abort_json('ADMIN_INVALID_PAYLOAD', 'password is required', 422);
        }
        $this->assertDepartmentExists($departmentId);
        $this->assertManagedDepartmentsAllowed($managedDepartmentIds);

        App::db()->execute(
            'INSERT INTO users (department_id, username, password_hash, role, admin_type, managed_department_ids, admin_scope, admin_permission_mode, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $departmentId,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $adminType,
                json_encode($managedDepartmentIds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $adminScope,
                $permissionMode,
                $this->status((string) ($data['status'] ?? 'active')),
            ]
        );
        $id = (int) App::db()->pdo()->lastInsertId();
        (new AuditService())->write('admin_user.create', 'users', $id, [
            'department_id' => $departmentId,
            'role' => $role,
            'admin_type' => $adminType,
            'admin_scope' => $adminScope,
            'permissions' => $permissionCodes,
            'managed_department_ids' => $managedDepartmentIds,
        ]);

        $this->syncPermissions($id, $permissionCodes);

        return $this->adminUser($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $this->requireSuperAdminActor();
        $current = $this->adminUser($id);

        $updates = [];
        if (array_key_exists('username', $data)) {
            $username = trim((string) $data['username']);
            if ($username === '') {
                abort_json('ADMIN_INVALID_PAYLOAD', 'username is required', 422);
            }
            $updates['username'] = $username;
        }
        if (array_key_exists('department_id', $data)) {
            $departmentId = (int) $data['department_id'];
            $this->assertDepartmentExists($departmentId);
            $updates['department_id'] = $departmentId;
        }
        if (array_key_exists('role', $data)) {
            $updates['role'] = $this->adminRole((string) $data['role']);
        }
        $nextRole = (string) ($updates['role'] ?? $current['role']);
        if (array_key_exists('role', $data) || array_key_exists('admin_type', $data) || array_key_exists('admin_scope', $data) || array_key_exists('permissions', $data)) {
            [$adminType, $adminScope, $permissionMode, $permissionCodes] = $this->administratorAccess($nextRole, $data, $current);
            $updates['admin_type'] = $adminType;
            $updates['admin_scope'] = $adminScope;
            $updates['admin_permission_mode'] = $permissionMode;
        } else {
            $permissionCodes = null;
        }
        if (array_key_exists('managed_department_ids', $data)) {
            $managedDepartmentIds = $this->managedDepartmentIds($data['managed_department_ids']);
            $this->assertManagedDepartmentsAllowed($managedDepartmentIds);
            $updates['managed_department_ids'] = json_encode($managedDepartmentIds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('status', $data)) {
            $updates['status'] = $this->status((string) $data['status']);
        }

        if ($updates !== []) {
            $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", array_keys($updates)));
            App::db()->execute("UPDATE users SET {$sets} WHERE id = ?", [...array_values($updates), $id]);
            if ($permissionCodes !== null) {
                $this->syncPermissions($id, $permissionCodes);
            }
            $this->revokeSessions($id, false);
            (new ApiKeyService())->invalidateByUser($id);
            (new AuditService())->write('admin_user.update', 'users', $id, $this->auditDetails($updates));
        }

        return $this->adminUser($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(int $id, string $password): array
    {
        $this->requireSuperAdminActor();
        $this->adminUser($id);
        if ($password === '') {
            abort_json('ADMIN_INVALID_PAYLOAD', 'password is required', 422);
        }
        App::db()->execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        $this->revokeSessions($id, false);
        (new ApiKeyService())->invalidateByUser($id);
        (new AuditService())->write('admin_user.password.reset', 'users', $id);

        return ['id' => $id, 'password_reset' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(int $id, string $status): array
    {
        return $this->update($id, ['status' => $status]);
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeSessions(int $id, bool $requireActor = true): array
    {
        if ($requireActor) {
            $this->requireSuperAdminActor();
            $this->adminUser($id);
        }
        $revoked = App::db()->execute(
            'UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ? AND revoked_at IS NULL',
            [$id]
        );
        if ($requireActor) {
            (new AuditService())->write('admin_user.sessions.revoke', 'users', $id, ['revoked' => $revoked]);
        }

        return ['id' => $id, 'revoked' => $revoked];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminUser(int $id): array
    {
        $row = App::db()->first('SELECT * FROM users WHERE id = ? AND role IN ("admin", "super_admin")', [$id]);
        if (!$row) {
            abort_json('ADMIN_NOT_FOUND', 'Admin user not found', 404);
        }

        return $this->presentAdminUser($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireSuperAdminActor(): array
    {
        $actor = App::context()->adminUser();
        if (!$actor) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }
        if (($actor['role'] ?? '') !== 'super_admin') {
            abort_json('ADMIN_PERMISSION_DENIED', 'Super admin permission required', 403);
        }

        return $actor;
    }

    private function adminRole(string $role): string
    {
        if (!in_array($role, self::adminRoles(), true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'role must be admin or super_admin', 422);
        }

        return $role;
    }

    private function status(string $status): string
    {
        if (!in_array($status, ['active', 'disabled', 'departed'], true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid user status', 422);
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     * @return array{0:string, 1:string, 2:string, 3:list<string>}
     */
    private function administratorAccess(string $role, array $data, ?array $current = null): array
    {
        if ($role === 'super_admin') {
            return ['super_admin', 'global', 'preset', []];
        }

        $type = (string) ($data['admin_type'] ?? ($current['admin_type'] ?? 'full_admin'));
        if (!in_array($type, ['full_admin', 'user_admin', 'rag_admin', 'api_admin', 'custom'], true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid administrator type', 422);
        }
        $scope = (string) ($data['admin_scope'] ?? ($current['admin_scope'] ?? 'global'));
        if (!in_array($scope, ['global', 'departments'], true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid administrator scope', 422);
        }

        $hasExplicitPermissions = array_key_exists('permissions', $data);
        $mode = $hasExplicitPermissions ? 'custom' : (string) ($current['admin_permission_mode'] ?? 'preset');
        if (array_key_exists('admin_type', $data) && !$hasExplicitPermissions) {
            $mode = 'preset';
        }
        $permissionCodes = $hasExplicitPermissions ? $this->permissionCodes($data['permissions']) : [];

        return [$type, $scope, $mode, $permissionCodes];
    }

    /** @return list<string> */
    private function permissionCodes(mixed $value): array
    {
        if (!is_array($value)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'permissions must be an array', 422);
        }
        $permissions = array_values(array_unique(array_filter(array_map('strval', $value))));
        $valid = (new PermissionService())->capabilities();
        foreach ($permissions as $permission) {
            if (!in_array($permission, $valid, true)) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid administrator permission', 422);
            }
        }
        sort($permissions);
        return $permissions;
    }

    /** @param list<string> $permissionCodes */
    private function syncPermissions(int $userId, array $permissionCodes): void
    {
        App::db()->execute('DELETE FROM admin_user_permissions WHERE user_id = ?', [$userId]);
        foreach ($permissionCodes as $permissionCode) {
            App::db()->execute(
                'INSERT INTO admin_user_permissions (user_id, permission_code) VALUES (?, ?)',
                [$userId, $permissionCode]
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function presentAdminUser(array $row): array
    {
        $row = (new SensitiveDataService())->sanitizeRow('users', $row);
        $row['permissions'] = (new PermissionService())->permissionsFor($row);
        return $row;
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function managedDepartmentIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : (preg_split('/[\s,]+/', $value) ?: []);
        }
        if (!is_array($value)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'managed_department_ids must be an array', 422);
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $value),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @param list<int> $departmentIds
     */
    private function assertManagedDepartmentsAllowed(array $departmentIds): void
    {
        foreach ($departmentIds as $departmentId) {
            $this->assertDepartmentExists($departmentId);
        }
    }

    private function assertDepartmentExists(int $departmentId): void
    {
        if ($departmentId <= 0 || !App::db()->first('SELECT id FROM departments WHERE id = ?', [$departmentId])) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Department not found', 422);
        }
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>
     */
    private function auditDetails(array $updates): array
    {
        if (isset($updates['managed_department_ids']) && is_string($updates['managed_department_ids'])) {
            $updates['managed_department_ids'] = json_decode($updates['managed_department_ids'], true) ?: [];
        }
        unset($updates['password'], $updates['password_hash']);

        return $updates;
    }
}
