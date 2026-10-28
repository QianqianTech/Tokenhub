<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * GET /v1/management/department: the department a management key works in,
 * with resource counts, so an agent can discover its own boundary.
 */
final class AgentManagementDepartmentService
{
    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function current(array $apiKey): array
    {
        $departmentId = (int) ($apiKey['department_id'] ?? 0);
        $department = $departmentId > 0 ? App::db()->first('SELECT id, parent_id, name, path, level, status, created_at, updated_at FROM departments WHERE id = ?', [$departmentId]) : null;
        if ($department === null) {
            abort_json('MANAGEMENT_FORBIDDEN', 'API key has no management department', 403);
        }
        $count = static fn (string $sql): int => (int) (App::db()->first($sql, [$departmentId])['total'] ?? 0);

        return [
            'id' => (int) $department['id'],
            'parent_id' => $department['parent_id'] === null ? null : (int) $department['parent_id'],
            'name' => (string) $department['name'],
            'path' => (string) $department['path'],
            'level' => (int) $department['level'],
            'status' => (string) $department['status'],
            'created_at' => $department['created_at'],
            'updated_at' => $department['updated_at'],
            'counts' => [
                'users' => $count("SELECT COUNT(*) AS total FROM users WHERE department_id = ? AND role = 'user' AND status = 'active'"),
                'api_keys' => $count("SELECT COUNT(*) AS total FROM api_keys WHERE department_id = ? AND status = 'active'"),
            ],
            'management_scopes' => (new ApiKeyManagementScopeService())->scopesFor((int) ($apiKey['id'] ?? 0)),
        ];
    }
}
