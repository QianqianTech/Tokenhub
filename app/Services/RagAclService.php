<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Admin API for knowledge-set access control (RAG-012).
 *
 * PUT replaces the grants the acting administrator is allowed to manage and
 * keeps grants on subjects outside that scope (made by a broader
 * administrator), so a department administrator can never silently remove
 * another department's access.
 */
final class RagAclService
{
    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function knowledgeBaseAcl(array $admin, int $kbId): array
    {
        $kb = $this->managedKnowledgeBase($admin, $kbId, false);
        $datasets = App::db()->select('SELECT id, dataset_id, name, status, acl_mode FROM rag_vector_datasets WHERE kb_id = ? AND deleted_at IS NULL ORDER BY id', [$kbId]);

        return [
            'knowledge_base' => [
                'id' => (int) $kb['id'],
                'kb_id' => (string) $kb['kb_id'],
                'name' => (string) $kb['name'],
                'department_id' => $kb['department_id'] === null ? null : (int) $kb['department_id'],
                'department_name' => $this->departmentName($kb['department_id'] === null ? 0 : (int) $kb['department_id']),
                'owner_user_id' => $kb['owner_user_id'] === null ? null : (int) $kb['owner_user_id'],
                'visibility' => (string) ($kb['visibility'] ?? 'private'),
                'can_manage' => $this->canManageKnowledgeBase($admin, $kb),
            ],
            'grants' => $this->grants('rag_knowledge_base_bindings', 'kb_id', $kbId, $admin),
            'datasets' => array_map(fn (array $dataset): array => [
                'id' => (int) $dataset['id'],
                'dataset_id' => (string) $dataset['dataset_id'],
                'name' => (string) $dataset['name'],
                'status' => (string) $dataset['status'],
                'acl_mode' => (string) ($dataset['acl_mode'] ?? 'inherit'),
                'grants' => $this->grants('rag_dataset_bindings', 'dataset_id', (int) $dataset['id'], $admin),
            ], $datasets),
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $payload {visibility?, grants: list<grant>}
     * @return array<string, mixed>
     */
    public function replaceKnowledgeBaseAcl(array $admin, int $kbId, array $payload): array
    {
        $kb = $this->managedKnowledgeBase($admin, $kbId, true);
        $grants = $this->normalizeGrants($payload['grants'] ?? [], false);
        $visibility = array_key_exists('visibility', $payload) ? (string) $payload['visibility'] : null;
        if ($visibility !== null && !in_array($visibility, ['private', 'department', 'shared'], true)) {
            abort_json('RAG_ACL_INVALID', 'visibility must be private, department or shared', 422);
        }
        $guard = new RagAdminScopeGuard();
        foreach ($grants as $grant) {
            $guard->assertSubjectInScope($admin, $grant['subject_type'], $grant['subject_id']);
        }
        App::db()->transaction(function () use ($admin, $kbId, $grants, $visibility): void {
            if ($visibility !== null) {
                App::db()->execute('UPDATE rag_knowledge_bases SET visibility = ? WHERE id = ?', [$visibility, $kbId]);
            }
            $this->replace('rag_knowledge_base_bindings', 'kb_id', $kbId, $grants, $admin, false);
        });
        (new AuditService())->write('rag.acl.replace', 'rag_knowledge_bases', $kbId, [
            'kb_id' => $kb['kb_id'],
            'visibility' => $visibility,
            'grants' => array_map(static fn (array $grant): string => $grant['subject_type'] . ':' . $grant['subject_id'] . ':' . $grant['permission'], $grants),
        ]);

        return $this->knowledgeBaseAcl($admin, $kbId);
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $payload {acl_mode?, grants: list<grant>}
     * @return array<string, mixed>
     */
    public function replaceDatasetAcl(array $admin, int $datasetId, array $payload): array
    {
        $dataset = App::db()->first('SELECT * FROM rag_vector_datasets WHERE id = ? AND deleted_at IS NULL', [$datasetId]);
        if ($dataset === null) {
            abort_json('RAG_DATASET_NOT_FOUND', 'Vector dataset not found', 404);
        }
        $this->managedKnowledgeBase($admin, (int) $dataset['kb_id'], true);
        $mode = array_key_exists('acl_mode', $payload) ? (string) $payload['acl_mode'] : null;
        if ($mode !== null && !in_array($mode, ['inherit', 'custom'], true)) {
            abort_json('RAG_ACL_INVALID', 'acl_mode must be inherit or custom', 422);
        }
        $grants = $this->normalizeGrants($payload['grants'] ?? [], true);
        $guard = new RagAdminScopeGuard();
        foreach ($grants as $grant) {
            $guard->assertSubjectInScope($admin, $grant['subject_type'], $grant['subject_id']);
        }
        App::db()->transaction(function () use ($admin, $datasetId, $grants, $mode): void {
            if ($mode !== null) {
                App::db()->execute('UPDATE rag_vector_datasets SET acl_mode = ? WHERE id = ?', [$mode, $datasetId]);
            }
            $this->replace('rag_dataset_bindings', 'dataset_id', $datasetId, $grants, $admin, true);
        });
        (new AuditService())->write('rag.acl.replace', 'rag_vector_datasets', $datasetId, [
            'dataset_id' => $dataset['dataset_id'],
            'acl_mode' => $mode,
            'grants' => array_map(static fn (array $grant): string => $grant['subject_type'] . ':' . $grant['subject_id'] . ':' . $grant['permission'], $grants),
        ]);

        return $this->knowledgeBaseAcl($admin, (int) $dataset['kb_id']);
    }

    /**
     * "What can this user / key read?" — the effective result of every rule.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $query user_id | api_key_id
     * @return array<string, mixed>
     */
    public function accessCheck(array $admin, array $query): array
    {
        $permission = new PermissionService();
        if (!empty($query['api_key_id'])) {
            $key = App::db()->first('SELECT id, user_id, department_id, name, status FROM api_keys WHERE id = ?', [(int) $query['api_key_id']]);
            if ($key === null) {
                abort_json('ADMIN_NOT_FOUND', 'API key not found', 404);
            }
            if (!$permission->canManage($admin, (int) $key['department_id'])) {
                abort_json('ADMIN_PERMISSION_DENIED', 'API key is outside admin scope', 403);
            }
            $caller = ['id' => (int) $key['id'], 'user_id' => (int) $key['user_id'], 'department_id' => (int) $key['department_id']];
            $subject = ['type' => 'api_key', 'id' => (int) $key['id'], 'name' => (string) $key['name']];
        } elseif (!empty($query['user_id'])) {
            $user = App::db()->first('SELECT id, department_id, username FROM users WHERE id = ?', [(int) $query['user_id']]);
            if ($user === null) {
                abort_json('ADMIN_NOT_FOUND', 'User not found', 404);
            }
            if (!$permission->canManage($admin, (int) $user['department_id'])) {
                abort_json('ADMIN_PERMISSION_DENIED', 'User is outside admin scope', 403);
            }
            $caller = ['id' => 0, 'user_id' => (int) $user['id'], 'department_id' => (int) $user['department_id']];
            $subject = ['type' => 'user', 'id' => (int) $user['id'], 'name' => (string) $user['username']];
        } else {
            abort_json('RAG_ACL_INVALID', 'user_id or api_key_id is required', 422);
        }
        $service = new RagPermissionService();
        $resolver = $service->resolver($caller);

        return [
            'subject' => $subject,
            'restricted' => $resolver->isRestricted(),
            'department_chain' => array_map(fn (int $id): array => ['id' => $id, 'name' => $this->departmentName($id)], $resolver->departmentIds()),
            'knowledge_bases' => $service->effectiveAccess($caller),
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    private function managedKnowledgeBase(array $admin, int $kbId, bool $write): array
    {
        $kb = App::db()->first('SELECT * FROM rag_knowledge_bases WHERE id = ? AND deleted_at IS NULL', [$kbId]);
        if ($kb === null) {
            abort_json('RAG_KB_NOT_FOUND', 'Knowledge base not found', 404);
        }
        if (!$this->canManageKnowledgeBase($admin, $kb) && ($write || (string) ($kb['visibility'] ?? '') !== 'shared')) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Knowledge base is outside admin scope', 403);
        }

        return $kb;
    }

    /** @param array<string, mixed> $kb */
    private function canManageKnowledgeBase(array $admin, array $kb): bool
    {
        return (new PermissionService())->canManage($admin, $kb['department_id'] === null ? null : (int) $kb['department_id']);
    }

    /**
     * @param list<array<string, mixed>> $grants
     */
    private function replace(string $table, string $column, int $targetId, array $grants, array $admin, bool $dataset): void
    {
        $guard = new RagAdminScopeGuard();
        $wanted = [];
        foreach ($grants as $grant) {
            $wanted[$grant['subject_type'] . ':' . $grant['subject_id']] = $grant;
        }
        foreach (App::db()->select("SELECT id, subject_type, subject_id FROM {$table} WHERE {$column} = ?", [$targetId]) as $row) {
            $key = $row['subject_type'] . ':' . $row['subject_id'];
            if (isset($wanted[$key])) {
                continue;
            }
            try {
                $guard->assertSubjectInScope($admin, (string) $row['subject_type'], (int) $row['subject_id']);
            } catch (\TokenHub\Support\HttpAbortException) {
                continue; // outside this admin's scope: keep it
            }
            App::db()->execute("DELETE FROM {$table} WHERE id = ?", [$row['id']]);
        }
        $bindings = new RagBindingService();
        foreach ($wanted as $grant) {
            $options = [
                'include_descendants' => $grant['include_descendants'],
                'expires_at' => $grant['expires_at'],
                'is_default' => $grant['is_default'],
                'granted_by_user_id' => (int) ($admin['id'] ?? 0) ?: null,
            ];
            if ($dataset) {
                $options['priority'] = $grant['priority'];
                $bindings->grantDataset($targetId, $grant['subject_type'], $grant['subject_id'], $grant['permission'], $options);
            } else {
                $bindings->grantKnowledgeBase($targetId, $grant['subject_type'], $grant['subject_id'], $grant['permission'], $options);
            }
        }
    }

    /**
     * @return list<array{subject_type: string, subject_id: int, permission: string, include_descendants: bool, expires_at: ?string, is_default: bool, priority: int}>
     */
    private function normalizeGrants(mixed $grants, bool $dataset): array
    {
        if (!is_array($grants) || !array_is_list($grants)) {
            abort_json('RAG_ACL_INVALID', 'grants must be a list', 422);
        }
        if (count($grants) > 500) {
            abort_json('RAG_ACL_INVALID', 'too many grants', 422);
        }
        $result = [];
        foreach ($grants as $index => $grant) {
            if (!is_array($grant)) {
                abort_json('RAG_ACL_INVALID', 'grant #' . $index . ' must be an object', 422);
            }
            $type = (string) ($grant['subject_type'] ?? '');
            $id = (int) ($grant['subject_id'] ?? 0);
            $permission = (string) ($grant['permission'] ?? 'read');
            if (!in_array($type, RagBindingService::SUBJECT_TYPES, true) || $id <= 0 || !in_array($permission, RagBindingService::PERMISSIONS, true)) {
                abort_json('RAG_ACL_INVALID', 'grant #' . $index . ' has an invalid subject or permission', 422);
            }
            $exists = match ($type) {
                'department' => App::db()->first('SELECT id FROM departments WHERE id = ?', [$id]),
                'user' => App::db()->first('SELECT id FROM users WHERE id = ?', [$id]),
                default => App::db()->first('SELECT id FROM api_keys WHERE id = ?', [$id]),
            };
            if ($exists === null) {
                abort_json('RAG_ACL_INVALID', 'grant #' . $index . ' references an unknown ' . $type, 422);
            }
            $result[] = [
                'subject_type' => $type,
                'subject_id' => $id,
                'permission' => $permission,
                'include_descendants' => $type === 'department' ? (bool) ($grant['include_descendants'] ?? true) : true,
                'expires_at' => isset($grant['expires_at']) && $grant['expires_at'] !== '' ? (string) $grant['expires_at'] : null,
                'is_default' => (bool) ($grant['is_default'] ?? false),
                'priority' => $dataset ? max(0, (int) ($grant['priority'] ?? 100)) : 100,
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function grants(string $table, string $column, int $targetId, array $admin): array
    {
        $guard = new RagAdminScopeGuard();
        $rows = App::db()->select("SELECT * FROM {$table} WHERE {$column} = ? ORDER BY subject_type, subject_id", [$targetId]);
        $now = gmdate('Y-m-d H:i:s');

        return array_map(function (array $row) use ($guard, $admin, $now): array {
            try {
                $guard->assertSubjectInScope($admin, (string) $row['subject_type'], (int) $row['subject_id']);
                $editable = true;
            } catch (\TokenHub\Support\HttpAbortException) {
                $editable = false;
            }

            return [
                'subject_type' => (string) $row['subject_type'],
                'subject_id' => (int) $row['subject_id'],
                'subject_name' => $this->subjectName((string) $row['subject_type'], (int) $row['subject_id']),
                'permission' => (string) $row['permission'],
                'include_descendants' => (int) ($row['include_descendants'] ?? 1) === 1,
                'expires_at' => $row['expires_at'] ?? null,
                'expired' => !empty($row['expires_at']) && (string) $row['expires_at'] <= $now,
                'is_default' => (int) ($row['is_default'] ?? 0) === 1,
                'priority' => isset($row['priority']) ? (int) $row['priority'] : null,
                'editable' => $editable,
            ];
        }, $rows);
    }

    private function subjectName(string $type, int $id): string
    {
        return match ($type) {
            'department' => $this->departmentName($id),
            'user' => (string) (App::db()->first('SELECT username FROM users WHERE id = ?', [$id])['username'] ?? ('#' . $id)),
            default => (string) (App::db()->first('SELECT name FROM api_keys WHERE id = ?', [$id])['name'] ?? ('#' . $id)),
        };
    }

    private function departmentName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        return (string) (App::db()->first('SELECT name FROM departments WHERE id = ?', [$id])['name'] ?? ('#' . $id));
    }
}
