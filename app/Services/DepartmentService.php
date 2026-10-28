<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class DepartmentService
{
    public function move(int $departmentId, ?int $parentId, int $sortOrder = 0): array
    {
        $department = App::db()->first('SELECT * FROM departments WHERE id = ?', [$departmentId]);
        if (!$department) {
            abort_json('ADMIN_NOT_FOUND', 'Department not found', 404);
        }

        if ($parentId !== null) {
            $parent = App::db()->first('SELECT * FROM departments WHERE id = ?', [$parentId]);
            if (!$parent) {
                abort_json('ADMIN_NOT_FOUND', 'Parent department not found', 404);
            }
            if (str_starts_with((string) $parent['path'], (string) $department['path'])) {
                abort_json('ADMIN_INVALID_DEPARTMENT_MOVE', 'Cannot move department into its descendant', 422);
            }
        }

        App::db()->execute('UPDATE departments SET parent_id = ?, sort_order = ? WHERE id = ?', [$parentId, $sortOrder, $departmentId]);
        $this->rebuildPath($departmentId);
        $this->invalidateDepartmentApiKeys($departmentId);
        (new AuditService())->write('department.move', 'departments', $departmentId, ['parent_id' => $parentId, 'sort_order' => $sortOrder]);

        return App::db()->first('SELECT * FROM departments WHERE id = ?', [$departmentId]) ?: ['id' => $departmentId];
    }

    public function sort(array $items): array
    {
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }

            App::db()->execute('UPDATE departments SET sort_order = ? WHERE id = ?', [
                (int) ($item['sort_order'] ?? 0),
                (int) $item['id'],
            ]);
        }

        (new AuditService())->write('department.sort', 'departments', null, ['items' => $items]);
        return ['sorted' => true, 'count' => count($items)];
    }

    public function rebuildPath(int $departmentId): void
    {
        $department = App::db()->first('SELECT * FROM departments WHERE id = ?', [$departmentId]);
        if (!$department) {
            return;
        }

        $parent = $department['parent_id'] ? App::db()->first('SELECT * FROM departments WHERE id = ?', [$department['parent_id']]) : null;
        $moved = $this->movedPath((string) $department['path'], (int) $department['id'], $parent);
        $oldPath = (string) $department['path'];
        $oldLevel = (int) $department['level'];

        App::db()->execute('UPDATE departments SET path = ?, level = ? WHERE id = ?', [$moved['path'], $moved['level'], $departmentId]);

        $children = App::db()->select('SELECT id, path, level FROM departments WHERE path LIKE ? AND id != ? ORDER BY level ASC', [$oldPath . '%', $departmentId]);
        foreach ($children as $child) {
            $next = $this->descendantPath((string) $child['path'], $oldPath, $moved['path'], $oldLevel, $moved['level']);
            App::db()->execute('UPDATE departments SET path = ?, level = ? WHERE id = ?', [$next['path'], $next['level'], $child['id']]);
        }
    }

    public function invalidateDepartmentApiKeys(int $departmentId): void
    {
        (new ApiKeyService())->invalidateByDepartment($departmentId);
        $department = App::db()->first('SELECT path FROM departments WHERE id = ?', [$departmentId]);
        if (!$department) {
            return;
        }

        App::db()->execute(
            'UPDATE admin_sessions s JOIN users u ON u.id = s.user_id JOIN departments d ON d.id = u.department_id SET s.revoked_at = COALESCE(s.revoked_at, NOW()) WHERE d.path LIKE ?',
            [(string) $department['path'] . '%']
        );
    }

    /**
     * @param array<string, mixed>|null $parent
     * @return array{path: string, level: int}
     */
    public function movedPath(string $oldPath, int $departmentId, ?array $parent): array
    {
        if ($parent === null) {
            return ['path' => '/' . $departmentId . '/', 'level' => 0];
        }

        return [
            'path' => rtrim((string) $parent['path'], '/') . '/' . $departmentId . '/',
            'level' => (int) $parent['level'] + 1,
        ];
    }

    /**
     * @return array{path: string, level: int}
     */
    public function descendantPath(string $childPath, string $oldRootPath, string $newRootPath, int $oldRootLevel, int $newRootLevel): array
    {
        $suffix = substr($childPath, strlen($oldRootPath));
        $depth = count(array_filter(explode('/', trim((string) $suffix, '/')), static fn (string $part): bool => $part !== ''));

        return [
            'path' => rtrim($newRootPath, '/') . '/' . ltrim((string) $suffix, '/'),
            'level' => max(0, $newRootLevel + $depth),
        ];
    }
}
