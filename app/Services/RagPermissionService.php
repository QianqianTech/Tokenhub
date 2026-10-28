<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagPermissionService
{
    private const ORDER = ['read' => 1, 'write' => 2, 'admin' => 3];

    /** @var array<string, list<array{type: string, id: int}>> */
    private array $subjectsCache = [];

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listKnowledgeBases(array $apiKey, array $query = []): array
    {
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $rows = App::db()->select(
            'SELECT k.*, CASE MAX(CASE b.permission WHEN "admin" THEN 3 WHEN "write" THEN 2 ELSE 1 END) WHEN 3 THEN "admin" WHEN 2 THEN "write" ELSE "read" END AS bound_permission, MAX(b.is_default) AS is_default
             FROM rag_knowledge_bases k
             JOIN rag_knowledge_base_bindings b ON b.kb_id = k.id
             WHERE k.deleted_at IS NULL
               AND k.status != "disabled"
               AND ' . $this->subjectWhere('b', $apiKey) . '
             GROUP BY k.id
             ORDER BY MAX(b.is_default) DESC, k.id DESC
             LIMIT ' . $limit
        );

        return [
            'knowledge_bases' => array_map(fn (array $row): array => $this->publicKnowledgeBase($row), $rows),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listDatasets(array $apiKey, string $kbId, array $query = []): array
    {
        $kb = $this->knowledgeBase($apiKey, $kbId, 'read');
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $rows = App::db()->select(
            'SELECT d.*, k.kb_id AS external_kb_id, k.chunking_profile AS kb_chunking_profile, k.retrieval_profile AS kb_retrieval_profile, CASE MAX(CASE b.permission WHEN "admin" THEN 3 WHEN "write" THEN 2 ELSE 1 END) WHEN 3 THEN "admin" WHEN 2 THEN "write" ELSE "read" END AS bound_permission, MAX(b.is_default) AS is_default
             FROM rag_vector_datasets d
             JOIN rag_knowledge_bases k ON k.id = d.kb_id
             JOIN rag_dataset_bindings b ON b.dataset_id = d.id
             WHERE d.kb_id = ?
               AND d.deleted_at IS NULL
               AND d.status != "disabled"
               AND k.deleted_at IS NULL
               AND k.status != "disabled"
               AND ' . $this->subjectWhere('b', $apiKey) . '
             GROUP BY d.id
             ORDER BY MAX(b.is_default) DESC, d.id DESC
             LIMIT ' . $limit,
            [(int) $kb['id']]
        );

        return [
            'kb_id' => (string) $kb['kb_id'],
            'datasets' => array_map(fn (array $row): array => $this->publicDataset($row), $rows),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function knowledgeBase(array $apiKey, string $kbId, string $permission = 'read'): array
    {
        $kb = App::db()->first('SELECT * FROM rag_knowledge_bases WHERE kb_id = ? AND deleted_at IS NULL AND status != "disabled"', [$kbId]);
        if ($kb === null) {
            abort_json('RAG_KB_NOT_FOUND', 'Knowledge base not found', 404);
        }
        if (!$this->hasKnowledgeBasePermission($apiKey, (int) $kb['id'], $permission)) {
            abort_json('RAG_KB_FORBIDDEN', 'Knowledge base permission denied', 403);
        }

        return $kb;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function datasetByExternalId(array $apiKey, string $datasetId, string $permission = 'read'): array
    {
        $dataset = App::db()->first(
            'SELECT d.*, k.kb_id AS external_kb_id, k.chunking_profile AS kb_chunking_profile, k.retrieval_profile AS kb_retrieval_profile FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.dataset_id = ? AND d.deleted_at IS NULL AND d.status != "disabled" AND k.deleted_at IS NULL AND k.status != "disabled"',
            [$datasetId]
        );
        if ($dataset === null) {
            abort_json('RAG_DATASET_NOT_FOUND', 'Vector dataset not found', 404);
        }
        if (!$this->hasDatasetPermission($apiKey, (int) $dataset['id'], $permission) || !$this->hasKnowledgeBasePermission($apiKey, (int) $dataset['kb_id'], $permission)) {
            abort_json('RAG_DATASET_FORBIDDEN', 'Vector dataset permission denied', 403);
        }

        return $dataset;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param list<string> $datasetIds
     * @param list<string> $datasetNames
     * @param list<string> $knowledgeBaseIds
     * @return list<array<string, mixed>>
     */
    public function resolveDatasets(array $apiKey, array $datasetIds = [], array $datasetNames = [], array $knowledgeBaseIds = [], string $permission = 'read'): array
    {
        $datasets = [];
        foreach ($datasetIds as $datasetId) {
            $datasets[] = $this->datasetByExternalId($apiKey, $datasetId, $permission);
        }

        foreach ($datasetNames as $name) {
            $matches = $this->datasetsByName($apiKey, $name, $permission);
            if (count($matches) > 1) {
                abort_json('RAG_DATASET_NAME_CONFLICT', 'Vector dataset name is ambiguous; use dataset_id', 409);
            }
            if ($matches === []) {
                abort_json('RAG_DATASET_NOT_FOUND', 'Vector dataset not found', 404);
            }
            $datasets[] = $matches[0];
        }

        foreach ($knowledgeBaseIds as $kbId) {
            $kb = $this->knowledgeBase($apiKey, $kbId, $permission);
            $datasets = [...$datasets, ...$this->datasetsForKnowledgeBase($apiKey, (int) $kb['id'], $permission)];
        }

        if ($datasets === []) {
            $datasets = $this->defaultDatasets($apiKey, $permission);
        }

        $unique = [];
        foreach ($datasets as $dataset) {
            $unique[(string) $dataset['dataset_id']] = $dataset;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    public function hasKnowledgeBasePermission(array $apiKey, int $kbId, string $permission): bool
    {
        return $this->hasBinding('rag_knowledge_base_bindings', 'kb_id', $kbId, $apiKey, $permission);
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    public function hasDatasetPermission(array $apiKey, int $datasetId, string $permission): bool
    {
        return $this->hasBinding('rag_dataset_bindings', 'dataset_id', $datasetId, $apiKey, $permission);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function datasetsByName(array $apiKey, string $name, string $permission): array
    {
        $rows = App::db()->select('SELECT d.*, k.kb_id AS external_kb_id, k.chunking_profile AS kb_chunking_profile, k.retrieval_profile AS kb_retrieval_profile FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.name = ? AND d.deleted_at IS NULL AND d.status != "disabled" AND k.deleted_at IS NULL AND k.status != "disabled"', [$name]);
        return array_values(array_filter($rows, fn (array $row): bool => $this->hasDatasetPermission($apiKey, (int) $row['id'], $permission) && $this->hasKnowledgeBasePermission($apiKey, (int) $row['kb_id'], $permission)));
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function datasetsForKnowledgeBase(array $apiKey, int $kbId, string $permission): array
    {
        $rows = App::db()->select('SELECT d.*, k.kb_id AS external_kb_id, k.chunking_profile AS kb_chunking_profile, k.retrieval_profile AS kb_retrieval_profile FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.kb_id = ? AND d.deleted_at IS NULL AND d.status != "disabled" AND k.deleted_at IS NULL AND k.status != "disabled" ORDER BY d.id', [$kbId]);
        return array_values(array_filter($rows, fn (array $row): bool => $this->hasDatasetPermission($apiKey, (int) $row['id'], $permission)));
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function defaultDatasets(array $apiKey, string $permission): array
    {
        $subjects = $this->subjects($apiKey);
        $rows = [];
        foreach ($subjects as $subject) {
            $rows = [...$rows, ...App::db()->select(
                'SELECT d.*, k.kb_id AS external_kb_id, k.chunking_profile AS kb_chunking_profile, k.retrieval_profile AS kb_retrieval_profile FROM rag_dataset_bindings b JOIN rag_vector_datasets d ON d.id = b.dataset_id JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE b.subject_type = ? AND b.subject_id = ? AND b.is_default = 1 AND d.deleted_at IS NULL AND d.status != "disabled" AND k.deleted_at IS NULL AND k.status != "disabled" ORDER BY b.priority, d.id',
                [$subject['type'], $subject['id']]
            )];
        }

        return array_values(array_filter($rows, fn (array $row): bool => $this->hasDatasetPermission($apiKey, (int) $row['id'], $permission) && $this->hasKnowledgeBasePermission($apiKey, (int) $row['kb_id'], $permission)));
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    private function hasBinding(string $table, string $column, int $id, array $apiKey, string $permission): bool
    {
        if ($apiKey === []) {
            return false;
        }
        foreach ($this->subjects($apiKey) as $subject) {
            $row = App::db()->first("SELECT permission FROM {$table} WHERE {$column} = ? AND subject_type = ? AND subject_id = ?", [$id, $subject['type'], $subject['id']]);
            if ($row !== null && self::ORDER[(string) $row['permission']] >= self::ORDER[$permission]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return list<array{type: string, id: int}>
     */
    private function subjects(array $apiKey): array
    {
        $cacheKey = json_encode([
            'id' => (int) ($apiKey['id'] ?? 0),
            'user_id' => (int) ($apiKey['user_id'] ?? 0),
            'department_id' => (int) ($apiKey['department_id'] ?? 0),
        ]);
        if (isset($this->subjectsCache[$cacheKey])) {
            return $this->subjectsCache[$cacheKey];
        }
        $subjects = [];
        if (!empty($apiKey['id'])) {
            $subjects[] = ['type' => 'api_key', 'id' => (int) $apiKey['id']];
        }
        if (!empty($apiKey['user_id'])) {
            $subjects[] = ['type' => 'user', 'id' => (int) $apiKey['user_id']];
        }
        if (!empty($apiKey['department_id'])) {
            $department = App::db()->first('SELECT path FROM departments WHERE id = ?', [(int) $apiKey['department_id']]);
            if ($department === null) {
                $subjects[] = ['type' => 'department', 'id' => (int) $apiKey['department_id']];
            } else {
                $departments = App::db()->select(
                    'SELECT id FROM departments WHERE ? LIKE CONCAT(path, "%") ORDER BY level ASC',
                    [(string) $department['path']]
                );
                foreach ($departments as $ancestor) {
                    $subjects[] = ['type' => 'department', 'id' => (int) $ancestor['id']];
                }
            }
        }

        return $this->subjectsCache[$cacheKey] = $subjects;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicKnowledgeBase(array $row): array
    {
        return [
            'kb_id' => (string) $row['kb_id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] ?? null,
            'visibility' => (string) ($row['visibility'] ?? 'private'),
            'status' => (string) ($row['status'] ?? 'active'),
            'permission' => (string) ($row['bound_permission'] ?? 'read'),
            'is_default' => ((int) ($row['is_default'] ?? 0)) > 0,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicDataset(array $row): array
    {
        return [
            'dataset_id' => (string) $row['dataset_id'],
            'kb_id' => (string) ($row['external_kb_id'] ?? ''),
            'name' => (string) $row['name'],
            'description' => $row['description'] ?? null,
            'dataset_type' => (string) ($row['dataset_type'] ?? 'mixed'),
            'status' => (string) ($row['status'] ?? 'active'),
            'permission' => (string) ($row['bound_permission'] ?? 'read'),
            'is_default' => ((int) ($row['is_default'] ?? 0)) > 0,
            'chunk_count' => isset($row['chunk_count']) ? (int) $row['chunk_count'] : 0,
            'vector_count' => isset($row['vector_count']) ? (int) $row['vector_count'] : 0,
            'last_indexed_at' => $row['last_indexed_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function subjectWhere(string $alias, array $apiKey): string
    {
        $parts = [];
        foreach ($this->subjects($apiKey) as $subject) {
            $parts[] = sprintf(
                '(%s.subject_type = "%s" AND %s.subject_id = %d)',
                $alias,
                $subject['type'],
                $alias,
                $subject['id']
            );
        }

        return $parts === [] ? '1 = 0' : '(' . implode(' OR ', $parts) . ')';
    }
}
