<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Small, searchable reference lists for admin pickers and label resolution.
 *
 * Replaces "download up to N full rows of every related table" in the admin
 * frontend: callers search by keyword (?q=) or resolve specific ids (?ids=),
 * and receive only the columns needed to build a label. Rows are restricted
 * by the same department scope as the corresponding list endpoint.
 */
final class LookupService
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const MAX_IDS = 200;

    /** @var array<string, array{table: string, capability: string, columns: list<string>, search: list<string>, filters: list<string>}> */
    private const TYPES = [
        'users' => ['table' => 'users', 'capability' => 'users.read', 'columns' => ['id', 'username', 'role', 'status', 'department_id'], 'search' => ['username'], 'filters' => ['department_id', 'status', 'role']],
        'departments' => ['table' => 'departments', 'capability' => 'departments.read', 'columns' => ['id', 'name', 'parent_id', 'status'], 'search' => ['name'], 'filters' => ['status', 'parent_id']],
        'api-keys' => ['table' => 'api_keys', 'capability' => 'api_keys.read', 'columns' => ['id', 'name', 'user_id', 'department_id', 'key_prefix', 'key_suffix', 'status'], 'search' => ['name', 'key_suffix'], 'filters' => ['department_id', 'user_id', 'status']],
        'models' => ['table' => 'models', 'capability' => 'api_catalog.read', 'columns' => ['id', 'name', 'external_name', 'provider_id', 'capability', 'status'], 'search' => ['name', 'external_name'], 'filters' => ['provider_id', 'capability', 'status']],
        'providers' => ['table' => 'providers', 'capability' => 'api_catalog.read', 'columns' => ['id', 'name', 'status'], 'search' => ['name'], 'filters' => ['status']],
        'quota-policies' => ['table' => 'quota_policies', 'capability' => 'quotas.read', 'columns' => ['id', 'name', 'period', 'status'], 'search' => ['name'], 'filters' => ['status']],
        'rag-knowledge-bases' => ['table' => 'rag_knowledge_bases', 'capability' => 'rag.read', 'columns' => ['id', 'kb_id', 'name', 'status', 'department_id'], 'search' => ['name', 'kb_id'], 'filters' => ['department_id', 'status']],
        'rag-vector-datasets' => ['table' => 'rag_vector_datasets', 'capability' => 'rag.read', 'columns' => ['id', 'dataset_id', 'name', 'status', 'kb_id'], 'search' => ['name', 'dataset_id'], 'filters' => ['kb_id', 'status']],
        'rag-vector-configs' => ['table' => 'rag_vector_configs', 'capability' => 'rag.read', 'columns' => ['id', 'name', 'modality', 'model_name', 'status'], 'search' => ['name', 'model_name'], 'filters' => ['modality', 'status']],
    ];

    public static function capabilityFor(string $type): ?string
    {
        return self::TYPES[$type]['capability'] ?? null;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query q (keyword), ids (comma separated), limit, plus per-type equality filters
     * @return list<array<string, mixed>>
     */
    public function find(array $actor, string $type, array $query): array
    {
        $config = self::TYPES[$type] ?? null;
        if ($config === null) {
            abort_json('NOT_FOUND', 'Unknown lookup type', 404);
        }

        $table = $config['table'];
        $available = $this->tableColumns($table);
        $columns = array_values(array_intersect($config['columns'], $available));
        if (!in_array('id', $columns, true)) {
            return [];
        }

        [$scopeWhere, $bindings] = (new PermissionService())->tableScopeSql($actor, $table);
        $conditions = [];
        $scopeWhere = trim((string) $scopeWhere);
        if ($scopeWhere !== '') {
            $conditions[] = preg_replace('/^WHERE\s+/i', '', $scopeWhere) ?: $scopeWhere;
        }
        if (in_array('deleted_at', $available, true)) {
            $conditions[] = 'deleted_at IS NULL';
        }
        if ($table === 'api_keys') {
            $conditions[] = "status <> 'deleted'";
        }

        foreach ($config['filters'] as $filter) {
            $value = $query[$filter] ?? null;
            if ($value === null || $value === '' || !in_array($filter, $available, true)) {
                continue;
            }
            $conditions[] = "{$filter} = ?";
            $bindings[] = (string) $value;
        }

        $ids = $this->ids($query['ids'] ?? null);
        if ($ids !== []) {
            $conditions[] = 'id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            array_push($bindings, ...$ids);
        }

        // Model pickers offer only models the gateway can serve
        // (ModelSupportPolicy); resolving ids for labels returns every model.
        if ($table === 'models' && $ids === [] && (string) ($query['support'] ?? 'supported') !== 'all') {
            $supportedIds = (new ModelSupportPolicy())->modelIds(true);
            if ($supportedIds === []) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = 'id IN (' . implode(', ', array_fill(0, count($supportedIds), '?')) . ')';
                array_push($bindings, ...$supportedIds);
            }
        }

        $keyword = trim((string) ($query['q'] ?? ''));
        if ($keyword !== '') {
            $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
            $parts = [];
            foreach (array_intersect($config['search'], $available) as $column) {
                $parts[] = "{$column} LIKE ? ESCAPE '\\\\'";
                $bindings[] = $pattern;
            }
            if (ctype_digit($keyword)) {
                $parts[] = 'id = ?';
                $bindings[] = (int) $keyword;
            }
            if ($parts !== []) {
                $conditions[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $limit = $ids !== []
            ? count($ids)
            : min(self::MAX_LIMIT, max(1, (int) ($query['limit'] ?? self::DEFAULT_LIMIT)));
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $select = implode(', ', $columns);

        $rows = App::db()->select("SELECT {$select} FROM {$table}{$where} ORDER BY id DESC LIMIT {$limit}", $bindings);

        return $table === 'models' ? (new ModelSupportPolicy())->annotate($rows) : $rows;
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0)));
        return array_slice($ids, 0, self::MAX_IDS);
    }

    /**
     * @return list<string>
     */
    private function tableColumns(string $table): array
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cache[$table] = array_map('strval', array_column(App::db()->select("SHOW COLUMNS FROM {$table}"), 'Field'));
        }
        return $cache[$table];
    }
}
