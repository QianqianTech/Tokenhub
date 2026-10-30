<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Request;

final class PermissionService
{
    /** @var list<string> */
    private const CAPABILITIES = [
        'dashboard.read',
        'departments.read', 'departments.manage',
        'users.read', 'users.manage',
        'api_catalog.read', 'api_catalog.manage',
        'api_keys.read', 'api_keys.manage',
        'quotas.read', 'quotas.manage',
        'usage.read', 'usage.manage', 'content.read', 'content.manage', 'content.export',
        'rag.read', 'rag.manage',
        'operations.read', 'operations.manage',
        'security.read', 'security.manage',
        'logs.read', 'logs.manage', 'logs.export',
        'system.read', 'system.manage',
        'admins.manage',
    ];

    /** @return list<string> */
    public function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    /** @return list<string> */
    public function permissionsFor(array $actor): array
    {
        if (($actor['role'] ?? '') === 'super_admin') {
            return self::CAPABILITIES;
        }

        if (($actor['role'] ?? '') !== 'admin') {
            return [];
        }

        if (($actor['admin_permission_mode'] ?? 'preset') === 'custom') {
            $rows = App::db()->select(
                'SELECT permission_code FROM admin_user_permissions WHERE user_id = ? ORDER BY permission_code',
                [(int) ($actor['id'] ?? 0)]
            );
            return array_values(array_intersect(
                self::CAPABILITIES,
                array_map(static fn (array $row): string => (string) $row['permission_code'], $rows)
            ));
        }

        return $this->presetPermissions((string) ($actor['admin_type'] ?? 'admin'));
    }

    public function hasCapability(array $actor, string $capability): bool
    {
        return in_array($capability, $this->permissionsFor($actor), true);
    }

    public function requireCapability(array $actor, string $capability): void
    {
        if (!$this->hasCapability($actor, $capability)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Administrator capability denied', 403);
        }
    }

    public function usesGlobalScope(array $actor): bool
    {
        if (($actor['role'] ?? '') === 'super_admin') {
            return true;
        }

        return ($actor['role'] ?? '') === 'admin' && ($actor['admin_scope'] ?? 'departments') === 'global';
    }

    public function capabilityForAdminRequest(Request $request): ?string
    {
        $path = $request->path;
        if (in_array($path, ['/admin-api/me', '/admin-api/csrf', '/admin-api/logout', '/admin-api/session/renew'], true)) {
            return null;
        }

        if (str_starts_with($path, '/admin-api/lookups/')) {
            return LookupService::capabilityFor(substr($path, strlen('/admin-api/lookups/'))) ?? 'unknown';
        }

        if (str_starts_with($path, '/admin-api/admin-users')) {
            return 'admins.manage';
        }

        $family = match (true) {
            str_starts_with($path, '/admin-api/rag/'),
            str_starts_with($path, '/admin-api/rag-') => 'rag',
            str_starts_with($path, '/admin-api/providers'),
            str_starts_with($path, '/admin-api/models'),
            str_starts_with($path, '/admin-api/model-prices') => 'api_catalog',
            str_starts_with($path, '/admin-api/api-keys') => 'api_keys',
            str_starts_with($path, '/admin-api/api-key-model-quotas'),
            str_starts_with($path, '/admin-api/quota-') => 'quotas',
            str_starts_with($path, '/admin-api/departments') => 'departments',
            str_starts_with($path, '/admin-api/users') => 'users',
            str_starts_with($path, '/admin-api/dashboard-share'),
            str_starts_with($path, '/admin-api/chat-session'),
            str_starts_with($path, '/admin-api/operations'),
            str_starts_with($path, '/admin-api/alerts') => 'operations',
            str_starts_with($path, '/admin-api/dashboard') => 'dashboard',
            str_starts_with($path, '/admin-api/desensitization') => 'security',
            str_starts_with($path, '/admin-api/system') => 'system',
            str_starts_with($path, '/admin-api/content-archive'),
            str_starts_with($path, '/admin-api/exports'),
            str_starts_with($path, '/admin-api/export-tasks'),
            str_starts_with($path, '/admin-api/usage-contents') => 'content',
            str_starts_with($path, '/admin-api/usage-records'),
            str_starts_with($path, '/admin-api/diagnostics/'),
            str_starts_with($path, '/admin-api/multimodal-tasks'),
            str_starts_with($path, '/admin-api/media-assets') => 'usage',
            str_starts_with($path, '/admin-api/logs'),
            str_starts_with($path, '/admin-api/log-'),
            str_starts_with($path, '/admin-api/alert-events'),
            str_starts_with($path, '/admin-api/audit-logs') => 'logs',
            default => null,
        };

        if ($family === null) {
            return 'unknown';
        }
        if ($family === 'dashboard') {
            return 'dashboard.read';
        }
        if (str_starts_with($path, '/admin-api/exports/') && str_contains($path, '/download-url')) {
            return 'content.export';
        }
        if (str_starts_with($path, '/admin-api/log-archive-files/') && str_contains($path, '/download-url')) {
            return 'logs.export';
        }

        return $family . ($request->method === 'GET' ? '.read' : '.manage');
    }

    public function canManage(array $actor, ?int $departmentId): bool
    {
        if ($this->usesGlobalScope($actor)) {
            return true;
        }
        if ($departmentId === null) {
            return false;
        }
        $department = App::db()->first('SELECT path FROM departments WHERE id = ?', [$departmentId]);
        return $department !== null && $this->canAccessDepartmentPath($actor, (string) $department['path']);
    }

    public function requireAdmin(array $actor): ?array
    {
        if (!in_array($actor['role'], ['super_admin', 'admin'], true)) {
            return ['code' => 'ADMIN_PERMISSION_DENIED', 'message' => 'Admin permission denied', 'status' => 403];
        }
        return null;
    }

    public function canAccessDepartmentPath(array $actor, string $targetPath): bool
    {
        if ($this->usesGlobalScope($actor)) {
            return true;
        }

        $managed = json_decode($actor['managed_department_ids'] ?? '[]', true) ?: [$actor['department_id'] ?? 0];
        foreach (array_map('intval', $managed) as $departmentId) {
            $department = App::db()->first('SELECT path FROM departments WHERE id = ?', [$departmentId]);
            if ($department && str_starts_with($targetPath, (string) $department['path'])) {
                return true;
            }
        }

        return false;
    }

    public function tableScopeSql(array $actor, string $table): array
    {
        if ($this->usesGlobalScope($actor)) {
            return ['', []];
        }

        if ($actor['role'] === 'user') {
            return match ($table) {
                'users' => [' WHERE id = ? ', [$actor['id']]],
                'usage_records', 'usage_contents', 'multimodal_tasks' => [' WHERE user_id = ? ', [$actor['id']]],
                'media_assets' => [' WHERE owner_user_id = ? ', [$actor['id']]],
                'export_tasks' => [' WHERE requested_by = ? ', [$actor['id']]],
                default => [' WHERE 1 = 0 ', []],
            };
        }

        $departmentIds = $this->descendantDepartmentIds((int) ($actor['department_id'] ?? 0), json_decode($actor['managed_department_ids'] ?? '[]', true) ?: []);
        if ($departmentIds === []) {
            return [' WHERE 1 = 0 ', []];
        }
        $placeholders = implode(', ', array_fill(0, count($departmentIds), '?'));

        return match ($table) {
            'departments' => [" WHERE id IN ({$placeholders}) ", $departmentIds],
            'users', 'api_keys', 'usage_records', 'usage_contents', 'multimodal_tasks', 'media_assets',
            'rag_knowledge_bases', 'rag_uploads', 'rag_answer_feedback' => [" WHERE department_id IN ({$placeholders}) ", $departmentIds],
            // Retrieval logs are visible to the caller's department and to the department that owns a
            // searched knowledge base, so knowledge-base owners can review how their content is used (RAG-016).
            'rag_retrieval_logs' => [" WHERE (department_id IN ({$placeholders}) OR EXISTS (SELECT 1 FROM rag_knowledge_bases scope_kb WHERE scope_kb.department_id IN ({$placeholders}) AND JSON_CONTAINS(kb_ids, JSON_QUOTE(scope_kb.kb_id)))) ", [...$departmentIds, ...$departmentIds]],
            'api_key_model_quotas' => [" WHERE api_key_id IN (SELECT id FROM api_keys WHERE department_id IN ({$placeholders})) ", $departmentIds],
            'rag_knowledge_base_bindings', 'rag_vector_datasets', 'rag_documents', 'rag_chunks', 'rag_image_assets', 'rag_eval_cases',
            'rag_embedding_jobs', 'rag_vector_records', 'rag_retention_policies' => [" WHERE kb_id IN (SELECT id FROM rag_knowledge_bases WHERE department_id IN ({$placeholders})) ", $departmentIds],
            'rag_eval_runs' => ['', []],
            'rag_eval_results' => [" WHERE case_id IN (SELECT c.id FROM rag_eval_cases c JOIN rag_knowledge_bases k ON k.id = c.kb_id WHERE k.department_id IN ({$placeholders})) ", $departmentIds],
            'rag_dataset_bindings' => [" WHERE dataset_id IN (SELECT d.id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE k.department_id IN ({$placeholders})) ", $departmentIds],
            'rag_upload_preview_items' => [" WHERE upload_id IN (SELECT id FROM rag_uploads WHERE department_id IN ({$placeholders})) ", $departmentIds],
            'rag_correction_tasks' => [" WHERE (kb_id IN (SELECT id FROM rag_knowledge_bases WHERE department_id IN ({$placeholders})) OR created_by_user_id = ?) ", [...$departmentIds, (int) $actor['id']]],
            'rag_import_templates' => ['', []],
            default => [' WHERE 1 = 0 ', []],
        };
    }

    private function descendantDepartmentIds(int $ownDepartmentId, array $extraManaged): array
    {
        $roots = array_values(array_unique(array_map('intval', [...$extraManaged, $ownDepartmentId])));
        $ids = [];
        foreach ($roots as $root) {
            $department = App::db()->first('SELECT path FROM departments WHERE id = ?', [$root]);
            if (!$department) {
                continue;
            }
            $rows = App::db()->select('SELECT id FROM departments WHERE path LIKE ?', [$department['path'] . '%']);
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function presetPermissions(string $type): array
    {
        $allBusiness = array_values(array_filter(
            self::CAPABILITIES,
            static fn (string $capability): bool => $capability !== 'admins.manage'
        ));

        return match ($type) {
            'full_admin' => $allBusiness,
            'user_admin' => [
                'dashboard.read', 'departments.read', 'departments.manage', 'users.read', 'users.manage',
                'api_keys.read', 'api_keys.manage', 'quotas.read', 'quotas.manage', 'usage.read', 'content.read', 'content.export',
            ],
            'rag_admin' => ['dashboard.read', 'rag.read', 'rag.manage', 'usage.read'],
            'api_admin' => [
                'dashboard.read', 'api_catalog.read', 'api_catalog.manage', 'api_keys.read', 'api_keys.manage',
                'quotas.read', 'quotas.manage', 'usage.read',
            ],
            default => [],
        };
    }
}
