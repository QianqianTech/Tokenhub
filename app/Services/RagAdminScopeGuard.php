<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Department scope for admin writes to RAG tables that have no department_id
 * column of their own (RAG-001). Every such row belongs to a knowledge base;
 * a department-scoped administrator may write it only when that knowledge
 * base is owned by a department the administrator manages. Grant rows are
 * additionally checked on the grant subject: an administrator can only grant
 * to departments, users and API keys inside the managed scope.
 *
 * Global-scope administrators are never restricted here.
 */
final class RagAdminScopeGuard
{
    /** table => how the owning knowledge base is found */
    private const KB_OWNED = [
        'rag_knowledge_base_bindings' => 'kb_id',
        'rag_dataset_bindings' => 'dataset_id',
        'rag_vector_datasets' => 'kb_id',
        'rag_documents' => 'kb_id',
        'rag_chunks' => 'kb_id',
        'rag_image_assets' => 'kb_id',
        'rag_upload_preview_items' => 'kb_id',
        'rag_embedding_jobs' => 'kb_id',
        'rag_vector_records' => 'kb_id',
        'rag_eval_cases' => 'kb_id',
        'rag_retention_policies' => 'kb_or_dataset',
        'rag_correction_tasks' => 'kb_or_dataset',
    ];

    /** Platform-wide RAG configuration: only global administrators may write it. */
    private const GLOBAL_ONLY = ['rag_vector_configs', 'rag_import_templates'];

    public function handlesTable(string $table): bool
    {
        return isset(self::KB_OWNED[$table]) || in_array($table, self::GLOBAL_ONLY, true) || $table === 'rag_knowledge_bases';
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $data
     */
    public function assertWrite(array $actor, string $table, array $data): void
    {
        $permission = new PermissionService();
        if ($permission->usesGlobalScope($actor) || !$this->handlesTable($table)) {
            return;
        }
        if (in_array($table, self::GLOBAL_ONLY, true)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Platform RAG configuration requires a global administrator', 403);
        }
        if ($table === 'rag_knowledge_bases') {
            // department_id itself is checked by CrudService; a scoped admin may not create an
            // ownerless (global) knowledge base.
            if (array_key_exists('department_id', $data) && ($data['department_id'] === null || $data['department_id'] === '')) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Knowledge base must belong to a managed department', 403);
            }
            return;
        }

        $kb = $this->owningKnowledgeBase($table, $data);
        if ($kb === false) {
            // No parent reference in this write (e.g. an update that only changes content).
            return;
        }
        $isGrant = in_array($table, ['rag_knowledge_base_bindings', 'rag_dataset_bindings'], true);
        $inScope = $kb !== null && $permission->canManage($actor, $kb['department_id'] === null ? null : (int) $kb['department_id']);
        $sharedReadGrant = $isGrant && $kb !== null && (string) ($kb['visibility'] ?? '') === 'shared' && (string) ($data['permission'] ?? 'read') === 'read';
        if (!$inScope && !$sharedReadGrant) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Knowledge base is outside admin scope', 403);
        }
        if ($isGrant) {
            $this->assertSubjectInScope($actor, (string) ($data['subject_type'] ?? ''), (int) ($data['subject_id'] ?? 0));
        }
    }

    /**
     * A scoped administrator must name the owning knowledge base (or dataset)
     * when creating a row; an ownerless row would be platform-wide.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $data
     */
    public function assertCreateHasOwner(array $actor, string $table, array $data): void
    {
        if ($actor === [] || !isset(self::KB_OWNED[$table]) || (new PermissionService())->usesGlobalScope($actor)) {
            return;
        }
        if ($this->owningKnowledgeBase($table, $data) === false) {
            abort_json('ADMIN_PERMISSION_DENIED', 'A managed knowledge base is required', 403);
        }
    }

    /** @param array<string, mixed> $actor */
    public function assertExisting(array $actor, string $table, int $id): void
    {
        $permission = new PermissionService();
        if ($permission->usesGlobalScope($actor)) {
            return;
        }
        if (in_array($table, self::GLOBAL_ONLY, true)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Platform RAG configuration requires a global administrator', 403);
        }
        if ($table === 'rag_knowledge_bases') {
            $row = App::db()->first('SELECT department_id FROM rag_knowledge_bases WHERE id = ?', [$id]);
            if ($row === null || !$permission->canManage($actor, $row['department_id'] === null ? null : (int) $row['department_id'])) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Resource is outside admin scope', 403);
            }
            return;
        }
        $row = App::db()->first("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if ($row === null) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Resource is outside admin scope', 403);
        }
        $kb = $this->owningKnowledgeBase($table, $row);
        if (!is_array($kb) || !$permission->canManage($actor, $kb['department_id'] === null ? null : (int) $kb['department_id'])) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Resource is outside admin scope', 403);
        }
        if (in_array($table, ['rag_knowledge_base_bindings', 'rag_dataset_bindings'], true)) {
            $this->assertSubjectInScope($actor, (string) $row['subject_type'], (int) $row['subject_id']);
        }
    }

    /** @param array<string, mixed> $actor */
    public function assertSubjectInScope(array $actor, string $subjectType, int $subjectId): void
    {
        $permission = new PermissionService();
        if ($permission->usesGlobalScope($actor)) {
            return;
        }
        $departmentId = match ($subjectType) {
            'department' => $subjectId,
            'user' => (int) (App::db()->first('SELECT department_id FROM users WHERE id = ?', [$subjectId])['department_id'] ?? 0),
            'api_key' => (int) (App::db()->first('SELECT department_id FROM api_keys WHERE id = ?', [$subjectId])['department_id'] ?? 0),
            default => 0,
        };
        if ($departmentId <= 0 || !$permission->canManage($actor, $departmentId)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Grant subject is outside admin scope', 403);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null|false false when the row names no parent at all
     */
    private function owningKnowledgeBase(string $table, array $row): array|null|false
    {
        $mode = self::KB_OWNED[$table] ?? null;
        $kbId = null;
        if ($mode === 'dataset_id' || ($mode === 'kb_or_dataset' && empty($row['kb_id']) && !empty($row['dataset_id']))) {
            if (empty($row['dataset_id'])) {
                return false;
            }
            $dataset = App::db()->first('SELECT kb_id FROM rag_vector_datasets WHERE id = ?', [(int) $row['dataset_id']]);
            if ($dataset === null) {
                return null;
            }
            $kbId = (int) $dataset['kb_id'];
        } else {
            if (empty($row['kb_id'])) {
                return false;
            }
            $kbId = (int) $row['kb_id'];
        }

        return App::db()->first('SELECT id, department_id, visibility FROM rag_knowledge_bases WHERE id = ?', [$kbId]);
    }
}
