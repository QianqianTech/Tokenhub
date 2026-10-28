<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ContentArchiveService
{
    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $policy
     */
    public function canViewRaw(array $actor, array $policy): bool
    {
        $roles = $policy['raw_allowed_roles'] ?? ['super_admin'];
        return in_array((string) ($actor['role'] ?? ''), is_array($roles) ? array_map('strval', $roles) : ['super_admin'], true);
    }

    /**
     * @param array<string, mixed> $actor
     * @return array{0: string, 1: list<mixed>}
     */
    public function scopeSql(array $actor, string $alias = 'r'): array
    {
        if ((new PermissionService())->usesGlobalScope($actor)) {
            return ['', []];
        }

        if (($actor['role'] ?? '') === 'user') {
            return [" {$alias}.user_id = ? ", [(int) $actor['id']]];
        }

        $departmentIds = $actor['department_ids'] ?? null;
        if (!is_array($departmentIds)) {
            $departmentIds = $this->descendantDepartmentIds((int) ($actor['department_id'] ?? 0), json_decode($actor['managed_department_ids'] ?? '[]', true) ?: []);
        }
        $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));
        if ($departmentIds === []) {
            return [' 1 = 0 ', []];
        }

        $placeholders = implode(', ', array_fill(0, count($departmentIds), '?'));
        return [" {$alias}.department_id IN ({$placeholders}) ", $departmentIds];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $filters): array
    {
        $out = [];
        foreach (['user_id', 'department_id', 'api_key_id', 'model_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $out[$key] = (int) $filters[$key];
            }
        }
        foreach (['from', 'to', 'session_id'] as $key) {
            if (isset($filters[$key]) && is_scalar($filters[$key]) && trim((string) $filters[$key]) !== '') {
                $out[$key] = trim((string) $filters[$key]);
            }
        }
        if (array_key_exists('include_raw', $filters)) {
            $out['include_raw'] = filter_var($filters['include_raw'], FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function list(array $query): array
    {
        $actor = App::context()->adminUser();
        if (!$actor) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }

        [$where, $bindings] = $this->where($actor, $this->normalizeFilters($query), 'r');
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $rows = App::db()->select(
            "SELECT c.id AS content_id, c.usage_record_id, c.session_id, c.retention_mode, c.summary, c.expires_at, c.cleaned_at, c.created_at AS content_created_at,
                    r.request_id, r.trace_id, r.user_id, r.department_id, r.api_key_id, r.provider_id, r.model_id, r.model_name, r.capability, r.status, r.input_tokens, r.output_tokens, r.total_tokens, r.cost_amount, r.created_at
             FROM usage_contents c
             JOIN usage_records r ON r.id = c.usage_record_id
             {$where}
             ORDER BY r.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );

        (new AuditService())->write('content_archive.list', 'usage_contents', null, ['filters' => $this->normalizeFilters($query)]);
        return $rows;
    }

    public function detail(int $id, bool $includeRaw = false): array
    {
        $actor = App::context()->adminUser();
        if (!$actor) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }

        $row = App::db()->first(
            'SELECT c.*, r.request_id, r.trace_id, r.user_id, r.department_id, r.api_key_id, r.provider_id, r.model_id, r.model_name, r.capability, r.status, r.input_tokens, r.output_tokens, r.total_tokens, r.cost_amount, r.created_at AS usage_created_at
             FROM usage_contents c
             JOIN usage_records r ON r.id = c.usage_record_id
             WHERE c.id = ?',
            [$id]
        );
        if (!$row || !$this->canAccessRow($actor, $row)) {
            abort_json('CONTENT_FORBIDDEN', 'Content is outside admin scope', 403);
        }

        $policy = (new ContentRetentionService())->policy();
        $canRaw = $includeRaw && $this->canViewRaw($actor, $policy) && empty($row['cleaned_at']);
        if ($includeRaw && !$canRaw) {
            (new AuditService())->write('content_archive.raw_denied', 'usage_contents', $id);
        }

        $result = $row;
        unset($result['encrypted_input'], $result['encrypted_output']);
        if ($canRaw) {
            $result['raw_input'] = Crypto::decrypt((string) ($row['encrypted_input'] ?? ''));
            $result['raw_output'] = Crypto::decrypt((string) ($row['encrypted_output'] ?? ''));
            (new AuditService())->write('content_archive.raw_view', 'usage_contents', $id);
        } else {
            $result['raw_input'] = null;
            $result['raw_output'] = null;
        }

        (new AuditService())->write('content_archive.view', 'usage_contents', $id, ['include_raw' => $includeRaw, 'raw_returned' => $canRaw]);
        return $result;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    public function where(array $actor, array $filters, string $alias = 'r'): array
    {
        $clauses = [];
        $bindings = [];
        [$scopeSql, $scopeBindings] = $this->scopeSql($actor, $alias);
        if ($scopeSql !== '') {
            $clauses[] = $scopeSql;
            $bindings = [...$bindings, ...$scopeBindings];
        }

        foreach (['user_id', 'department_id', 'api_key_id', 'model_id'] as $key) {
            if (isset($filters[$key])) {
                $clauses[] = "{$alias}.{$key} = ?";
                $bindings[] = $filters[$key];
            }
        }
        if (isset($filters['from'])) {
            $clauses[] = "{$alias}.created_at >= ?";
            $bindings[] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $clauses[] = "{$alias}.created_at <= ?";
            $bindings[] = $filters['to'];
        }
        if (isset($filters['session_id'])) {
            $clauses[] = 'c.session_id = ?';
            $bindings[] = $filters['session_id'];
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $row
     */
    private function canAccessRow(array $actor, array $row): bool
    {
        if ((new PermissionService())->usesGlobalScope($actor)) {
            return true;
        }
        if (($actor['role'] ?? '') === 'user') {
            return (int) ($row['user_id'] ?? 0) === (int) ($actor['id'] ?? 0);
        }

        [$scopeSql, $bindings] = $this->scopeSql($actor, 'r');
        if ($scopeSql === ' 1 = 0 ') {
            return false;
        }

        return in_array((int) ($row['department_id'] ?? 0), array_map('intval', $bindings), true);
    }

    /**
     * @param list<int> $extraManaged
     * @return list<int>
     */
    private function descendantDepartmentIds(int $ownDepartmentId, array $extraManaged): array
    {
        $roots = array_values(array_unique(array_filter(array_map('intval', [...$extraManaged, $ownDepartmentId]))));
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
}
