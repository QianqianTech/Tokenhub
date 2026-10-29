<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagPermissionService
{
    private const DATASET_SELECT = 'SELECT d.*, k.kb_id AS external_kb_id, k.chunking_profile AS kb_chunking_profile, k.retrieval_profile AS kb_retrieval_profile, k.department_id AS kb_department_id, k.visibility AS kb_visibility, k.owner_user_id AS kb_owner_user_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id';
    private const ACTIVE = 'd.deleted_at IS NULL AND d.status != "disabled" AND k.deleted_at IS NULL AND k.status != "disabled"';

    /** @var array<string, RagAccessResolver> */
    private array $resolvers = [];

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listKnowledgeBases(array $apiKey, array $query = []): array
    {
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $resolver = $this->resolver($apiKey);
        [$where, $params] = $resolver->candidateKnowledgeBaseSql('k');
        $rows = App::db()->select(
            'SELECT k.* FROM rag_knowledge_bases k WHERE k.deleted_at IS NULL AND k.status != "disabled" AND ' . $where . ' ORDER BY k.id DESC',
            $params
        );
        $defaults = $this->defaultKnowledgeBaseIds($resolver);
        $visible = [];
        foreach ($rows as $row) {
            $level = $resolver->knowledgeBaseLevel($row);
            if ($level < RagAccessResolver::READ) {
                $level = $this->bestDatasetLevel($resolver, $row);
            }
            if ($level < RagAccessResolver::READ) {
                continue;
            }
            $row['bound_permission'] = RagAccessResolver::name($level);
            $row['is_default'] = isset($defaults[(int) $row['id']]) ? 1 : 0;
            $visible[] = $row;
        }
        usort($visible, static fn (array $a, array $b): int => [(int) $b['is_default'], (int) $b['id']] <=> [(int) $a['is_default'], (int) $a['id']]);

        return [
            'knowledge_bases' => array_map(fn (array $row): array => $this->publicKnowledgeBase($row), array_slice($visible, 0, $limit)),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listDatasets(array $apiKey, string $kbId, array $query = []): array
    {
        $kb = $this->knowledgeBaseRow($kbId);
        $resolver = $this->resolver($apiKey);
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $rows = App::db()->select(self::DATASET_SELECT . ' WHERE d.kb_id = ? AND ' . self::ACTIVE . ' ORDER BY d.id DESC', [(int) $kb['id']]);
        $defaults = $this->defaultDatasetOrder($resolver);
        $visible = [];
        foreach ($rows as $row) {
            $level = $resolver->datasetLevel($row, $this->kbFromDatasetRow($row));
            if ($level < RagAccessResolver::READ) {
                continue;
            }
            $row['bound_permission'] = RagAccessResolver::name($level);
            $row['is_default'] = isset($defaults[(int) $row['id']]) ? 1 : 0;
            $visible[] = $row;
        }
        if ($visible === []) {
            abort_json('RAG_KB_FORBIDDEN', 'Knowledge base permission denied', 403);
        }
        usort($visible, static fn (array $a, array $b): int => [(int) $b['is_default'], (int) $b['id']] <=> [(int) $a['is_default'], (int) $a['id']]);

        return [
            'kb_id' => (string) $kb['kb_id'],
            'datasets' => array_map(fn (array $row): array => $this->publicDataset($row), array_slice($visible, 0, $limit)),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function knowledgeBase(array $apiKey, string $kbId, string $permission = 'read'): array
    {
        $kb = $this->knowledgeBaseRow($kbId);
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
        $dataset = App::db()->first(self::DATASET_SELECT . ' WHERE d.dataset_id = ? AND ' . self::ACTIVE, [$datasetId]);
        if ($dataset === null) {
            abort_json('RAG_DATASET_NOT_FOUND', 'Vector dataset not found', 404);
        }
        if ($this->resolver($apiKey)->datasetLevel($dataset, $this->kbFromDatasetRow($dataset)) < RagAccessResolver::level($permission)) {
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
            $kb = $this->knowledgeBaseRow($kbId);
            $accessible = $this->datasetsForKnowledgeBase($apiKey, (int) $kb['id'], $permission);
            if ($accessible === []) {
                abort_json('RAG_KB_FORBIDDEN', 'Knowledge base permission denied', 403);
            }
            $datasets = [...$datasets, ...$accessible];
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
        if ($apiKey === []) {
            return false;
        }
        $kb = App::db()->first('SELECT * FROM rag_knowledge_bases WHERE id = ?', [$kbId]);
        if ($kb === null) {
            return false;
        }
        $required = RagAccessResolver::level($permission);
        $resolver = $this->resolver($apiKey);
        if ($resolver->knowledgeBaseLevel($kb) >= $required) {
            return true;
        }

        // Read access to any dataset of the knowledge base is enough to see the knowledge base.
        return $required === RagAccessResolver::READ && $this->bestDatasetLevel($resolver, $kb) >= RagAccessResolver::READ;
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    public function hasDatasetPermission(array $apiKey, int $datasetId, string $permission): bool
    {
        if ($apiKey === []) {
            return false;
        }
        $dataset = App::db()->first(self::DATASET_SELECT . ' WHERE d.id = ?', [$datasetId]);
        if ($dataset === null) {
            return false;
        }

        return $this->resolver($apiKey)->datasetLevel($dataset, $this->kbFromDatasetRow($dataset)) >= RagAccessResolver::level($permission);
    }

    /**
     * Effective permission summary, used by the admin "access check" view.
     *
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    public function effectiveAccess(array $apiKey): array
    {
        $resolver = $this->resolver($apiKey);
        [$where, $params] = $resolver->candidateKnowledgeBaseSql('k');
        $kbs = App::db()->select('SELECT k.* FROM rag_knowledge_bases k WHERE k.deleted_at IS NULL AND ' . $where . ' ORDER BY k.id', $params);
        $result = [];
        foreach ($kbs as $kb) {
            $datasets = [];
            foreach (App::db()->select(self::DATASET_SELECT . ' WHERE d.kb_id = ? AND d.deleted_at IS NULL ORDER BY d.id', [(int) $kb['id']]) as $dataset) {
                $level = $resolver->datasetLevel($dataset, $kb);
                if ($level >= RagAccessResolver::READ) {
                    $datasets[] = ['id' => (int) $dataset['id'], 'dataset_id' => (string) $dataset['dataset_id'], 'name' => (string) $dataset['name'], 'acl_mode' => (string) ($dataset['acl_mode'] ?? 'inherit'), 'permission' => RagAccessResolver::name($level)];
                }
            }
            $level = $resolver->knowledgeBaseLevel($kb);
            if ($level < RagAccessResolver::READ && $datasets === []) {
                continue;
            }
            $result[] = [
                'id' => (int) $kb['id'],
                'kb_id' => (string) $kb['kb_id'],
                'name' => (string) $kb['name'],
                'visibility' => (string) ($kb['visibility'] ?? 'private'),
                'permission' => RagAccessResolver::name($level),
                'datasets' => $datasets,
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $apiKey */
    public function resolver(array $apiKey): RagAccessResolver
    {
        $cacheKey = json_encode([
            'id' => (int) ($apiKey['id'] ?? 0),
            'user_id' => (int) ($apiKey['user_id'] ?? 0),
            'department_id' => (int) ($apiKey['department_id'] ?? 0),
        ]) ?: '';

        return $this->resolvers[$cacheKey] ??= new RagAccessResolver($apiKey);
    }

    /** @return array<string, mixed> */
    private function knowledgeBaseRow(string $kbId): array
    {
        $kb = App::db()->first('SELECT * FROM rag_knowledge_bases WHERE kb_id = ? AND deleted_at IS NULL AND status != "disabled"', [$kbId]);
        if ($kb === null) {
            abort_json('RAG_KB_NOT_FOUND', 'Knowledge base not found', 404);
        }

        return $kb;
    }

    /**
     * @param array<string, mixed> $row dataset row from DATASET_SELECT
     * @return array<string, mixed>
     */
    private function kbFromDatasetRow(array $row): array
    {
        return [
            'id' => (int) $row['kb_id'],
            'department_id' => $row['kb_department_id'] ?? null,
            'visibility' => $row['kb_visibility'] ?? 'private',
            'owner_user_id' => $row['kb_owner_user_id'] ?? null,
        ];
    }

    /** @param array<string, mixed> $kb */
    private function bestDatasetLevel(RagAccessResolver $resolver, array $kb): int
    {
        $best = RagAccessResolver::NONE;
        foreach (App::db()->select('SELECT id, acl_mode FROM rag_vector_datasets WHERE kb_id = ? AND deleted_at IS NULL AND status != "disabled"', [(int) $kb['id']]) as $dataset) {
            $best = max($best, $resolver->datasetLevel($dataset, $kb));
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function datasetsByName(array $apiKey, string $name, string $permission): array
    {
        $rows = App::db()->select(self::DATASET_SELECT . ' WHERE d.name = ? AND ' . self::ACTIVE, [$name]);
        $resolver = $this->resolver($apiKey);
        $required = RagAccessResolver::level($permission);

        return array_values(array_filter($rows, fn (array $row): bool => $resolver->datasetLevel($row, $this->kbFromDatasetRow($row)) >= $required));
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function datasetsForKnowledgeBase(array $apiKey, int $kbId, string $permission): array
    {
        $rows = App::db()->select(self::DATASET_SELECT . ' WHERE d.kb_id = ? AND ' . self::ACTIVE . ' ORDER BY d.id', [$kbId]);
        $resolver = $this->resolver($apiKey);
        $required = RagAccessResolver::level($permission);

        return array_values(array_filter($rows, fn (array $row): bool => $resolver->datasetLevel($row, $this->kbFromDatasetRow($row)) >= $required));
    }

    /**
     * Default datasets: is_default grants of the caller's subjects, most
     * specific subject first (key, user, own department, ancestors), then
     * binding priority. A default knowledge-base grant contributes every
     * dataset of that knowledge base the caller can read.
     *
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function defaultDatasets(array $apiKey, string $permission): array
    {
        $resolver = $this->resolver($apiKey);
        $order = $this->defaultDatasetOrder($resolver);
        if ($order === []) {
            return [];
        }
        $ids = array_keys($order);
        $rows = App::db()->select(self::DATASET_SELECT . ' WHERE d.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND ' . self::ACTIVE, $ids);
        $required = RagAccessResolver::level($permission);
        $rows = array_values(array_filter($rows, fn (array $row): bool => $resolver->datasetLevel($row, $this->kbFromDatasetRow($row)) >= $required));
        usort($rows, static fn (array $a, array $b): int => ($order[(int) $a['id']] <=> $order[(int) $b['id']]) ?: ((int) $a['id'] <=> (int) $b['id']));

        return $rows;
    }

    /** @return array<int, int> dataset id => sort rank */
    private function defaultDatasetOrder(RagAccessResolver $resolver): array
    {
        $order = [];
        $now = gmdate('Y-m-d H:i:s');
        foreach ($resolver->subjects() as $subjectRank => $subject) {
            $rows = App::db()->select(
                'SELECT dataset_id, priority, expires_at FROM rag_dataset_bindings WHERE subject_type = ? AND subject_id = ? AND is_default = 1 ORDER BY priority, dataset_id',
                [$subject['type'], $subject['id']]
            );
            foreach ($rows as $row) {
                if (!empty($row['expires_at']) && (string) $row['expires_at'] <= $now) {
                    continue;
                }
                $order[(int) $row['dataset_id']] ??= $subjectRank * 100000 + (int) ($row['priority'] ?? 100);
            }
            $kbRows = App::db()->select(
                'SELECT d.id AS dataset_id, b.expires_at FROM rag_knowledge_base_bindings b JOIN rag_vector_datasets d ON d.kb_id = b.kb_id WHERE b.subject_type = ? AND b.subject_id = ? AND b.is_default = 1 ORDER BY d.id',
                [$subject['type'], $subject['id']]
            );
            foreach ($kbRows as $row) {
                if (!empty($row['expires_at']) && (string) $row['expires_at'] <= $now) {
                    continue;
                }
                $order[(int) $row['dataset_id']] ??= $subjectRank * 100000 + 99999;
            }
        }

        return $order;
    }

    /** @return array<int, true> */
    private function defaultKnowledgeBaseIds(RagAccessResolver $resolver): array
    {
        $order = $this->defaultDatasetOrder($resolver);
        if ($order === []) {
            return [];
        }
        $ids = array_keys($order);
        $result = [];
        foreach (App::db()->select('SELECT DISTINCT kb_id FROM rag_vector_datasets WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) {
            $result[(int) $row['kb_id']] = true;
        }

        return $result;
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
}
