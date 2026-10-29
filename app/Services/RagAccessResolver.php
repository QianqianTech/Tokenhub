<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Effective RAG permission for one caller (API key, or an admin acting for a
 * department). One resolver instance is built per caller and preloads every
 * grant that can apply, so permission checks during a request cost no extra
 * queries.
 *
 * Rules (see docs: rag-knowledge-base-analysis.md §4.2):
 *  - subjects: the API key, its user, the user's CURRENT department and the
 *    active ancestors of that department. Ancestor grants apply only when
 *    include_descendants = 1; a grant on the caller's own department always
 *    applies. Expired grants never apply.
 *  - restriction mode: an API key that holds at least one explicit RAG grant
 *    sees only its own grants (no user/department/visibility inheritance).
 *  - visibility: shared => everyone read; department => members of the owning
 *    department and its sub-departments read; private => nothing implicit.
 *    The knowledge base owner user gets admin.
 *  - datasets: acl_mode inherit => max(knowledge base permission, dataset
 *    grants); custom => dataset grants only.
 */
final class RagAccessResolver
{
    public const NONE = 0;
    public const READ = 1;
    public const WRITE = 2;
    public const ADMIN = 3;

    private const LEVELS = ['read' => self::READ, 'write' => self::WRITE, 'admin' => self::ADMIN];

    private int $apiKeyId;
    private int $userId;
    private int $departmentId;
    /** @var list<int> department chain from root to the caller's department (active only) */
    private array $departmentChain = [];
    private bool $restricted = false;
    /** @var array<int, int> */
    private array $kbGrants = [];
    /** @var array<int, int> */
    private array $datasetGrants = [];

    /** @param array<string, mixed> $caller api_keys row or admin pseudo-caller */
    public function __construct(array $caller)
    {
        $this->apiKeyId = (int) ($caller['id'] ?? 0);
        $this->userId = (int) ($caller['user_id'] ?? 0);
        $this->departmentId = (int) ($caller['department_id'] ?? 0);
        if ($this->apiKeyId > 0 && $this->userId > 0) {
            // The user's current department wins over the department stored on the key, so a
            // department move takes effect immediately even when the key row was not updated.
            $user = App::db()->first('SELECT department_id FROM users WHERE id = ?', [$this->userId]);
            if ($user !== null && (int) ($user['department_id'] ?? 0) > 0) {
                $this->departmentId = (int) $user['department_id'];
            }
        }
        if ($caller === [] || ($this->apiKeyId === 0 && $this->userId === 0 && $this->departmentId === 0)) {
            return;
        }
        $this->departmentChain = $this->departmentChain($this->departmentId);
        if ($this->apiKeyId > 0) {
            $this->restricted = $this->hasExplicitKeyGrant($this->apiKeyId);
        }
        $this->kbGrants = $this->loadGrants('rag_knowledge_base_bindings', 'kb_id');
        $this->datasetGrants = $this->loadGrants('rag_dataset_bindings', 'dataset_id');
    }

    public static function level(string $permission): int
    {
        return self::LEVELS[$permission] ?? self::NONE;
    }

    public static function name(int $level): ?string
    {
        return match (true) {
            $level >= self::ADMIN => 'admin',
            $level === self::WRITE => 'write',
            $level === self::READ => 'read',
            default => null,
        };
    }

    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    /** @return list<int> */
    public function departmentIds(): array
    {
        return $this->departmentChain;
    }

    /** @param array<string, mixed> $kb rag_knowledge_bases row (id, department_id, visibility, owner_user_id) */
    public function knowledgeBaseLevel(array $kb): int
    {
        $kbId = (int) ($kb['id'] ?? 0);
        $level = $this->kbGrants[$kbId] ?? self::NONE;
        if ($this->restricted) {
            return $level;
        }
        if ($this->userId > 0 && (int) ($kb['owner_user_id'] ?? 0) === $this->userId) {
            $level = max($level, self::ADMIN);
        }

        return max($level, $this->visibilityLevel($kb));
    }

    /**
     * @param array<string, mixed> $dataset rag_vector_datasets row (id, acl_mode)
     * @param array<string, mixed> $kb owning knowledge base row
     */
    public function datasetLevel(array $dataset, array $kb): int
    {
        $own = $this->datasetGrants[(int) ($dataset['id'] ?? 0)] ?? self::NONE;
        if ((string) ($dataset['acl_mode'] ?? 'inherit') === 'custom') {
            return $own;
        }

        return max($own, $this->knowledgeBaseLevel($kb));
    }

    /**
     * Subjects whose grants apply, most specific first (key, user, own
     * department, nearest ancestor ... root). Used for default-dataset order.
     *
     * @return list<array{type: string, id: int}>
     */
    public function subjects(): array
    {
        $subjects = [];
        if ($this->apiKeyId > 0) {
            $subjects[] = ['type' => 'api_key', 'id' => $this->apiKeyId];
        }
        if ($this->restricted) {
            return $subjects;
        }
        if ($this->userId > 0) {
            $subjects[] = ['type' => 'user', 'id' => $this->userId];
        }
        foreach (array_reverse($this->departmentChain) as $departmentId) {
            $subjects[] = ['type' => 'department', 'id' => $departmentId];
        }

        return $subjects;
    }

    /** @return list<int> knowledge base ids that hold a direct grant for this caller */
    public function grantedKnowledgeBaseIds(): array
    {
        return array_keys($this->kbGrants);
    }

    /** @return list<int> dataset ids that hold a direct grant for this caller */
    public function grantedDatasetIds(): array
    {
        return array_keys($this->datasetGrants);
    }

    /**
     * SQL fragment selecting candidate knowledge bases (alias k). Final
     * permission is still decided by knowledgeBaseLevel()/datasetLevel().
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function candidateKnowledgeBaseSql(string $alias = 'k'): array
    {
        $parts = [];
        $params = [];
        $kbIds = $this->grantedKnowledgeBaseIds();
        if ($kbIds !== []) {
            $parts[] = "{$alias}.id IN (" . implode(',', array_fill(0, count($kbIds), '?')) . ')';
            array_push($params, ...$kbIds);
        }
        $datasetIds = $this->grantedDatasetIds();
        if ($datasetIds !== []) {
            $parts[] = "{$alias}.id IN (SELECT kb_id FROM rag_vector_datasets WHERE id IN (" . implode(',', array_fill(0, count($datasetIds), '?')) . '))';
            array_push($params, ...$datasetIds);
        }
        if (!$this->restricted && ($this->apiKeyId > 0 || $this->userId > 0 || $this->departmentId > 0)) {
            $parts[] = "{$alias}.visibility = 'shared'";
            if ($this->departmentChain !== []) {
                $parts[] = "({$alias}.visibility = 'department' AND {$alias}.department_id IN (" . implode(',', array_fill(0, count($this->departmentChain), '?')) . '))';
                array_push($params, ...$this->departmentChain);
            }
            if ($this->userId > 0) {
                $parts[] = "{$alias}.owner_user_id = ?";
                $params[] = $this->userId;
            }
        }

        return [$parts === [] ? '1 = 0' : '(' . implode(' OR ', $parts) . ')', $params];
    }

    /** @param array<string, mixed> $kb */
    private function visibilityLevel(array $kb): int
    {
        $visibility = (string) ($kb['visibility'] ?? 'private');
        if ($visibility === 'shared') {
            return self::READ;
        }
        if ($visibility === 'department' && $kb['department_id'] !== null && in_array((int) $kb['department_id'], $this->departmentChain, true)) {
            return self::READ;
        }

        return self::NONE;
    }

    /** @return array<int, int> */
    private function loadGrants(string $table, string $column): array
    {
        $conditions = [];
        $params = [];
        if ($this->apiKeyId > 0) {
            $conditions[] = '(subject_type = ? AND subject_id = ?)';
            array_push($params, 'api_key', $this->apiKeyId);
        }
        if (!$this->restricted) {
            if ($this->userId > 0) {
                $conditions[] = '(subject_type = ? AND subject_id = ?)';
                array_push($params, 'user', $this->userId);
            }
            if ($this->departmentChain !== []) {
                $conditions[] = '(subject_type = ? AND subject_id IN (' . implode(',', array_fill(0, count($this->departmentChain), '?')) . '))';
                array_push($params, 'department', ...$this->departmentChain);
            }
        }
        if ($conditions === []) {
            return [];
        }
        $hasLifecycle = $this->hasColumn($table, 'expires_at');
        $select = $hasLifecycle
            ? "SELECT {$column} AS target_id, subject_type, subject_id, permission, include_descendants, expires_at FROM {$table}"
            : "SELECT {$column} AS target_id, subject_type, subject_id, permission FROM {$table}";
        $rows = App::db()->select($select . ' WHERE ' . implode(' OR ', $conditions), $params);
        $now = gmdate('Y-m-d H:i:s');
        $grants = [];
        foreach ($rows as $row) {
            if ($hasLifecycle && !empty($row['expires_at']) && (string) $row['expires_at'] <= $now) {
                continue;
            }
            if ((string) $row['subject_type'] === 'department' && (int) $row['subject_id'] !== $this->departmentId) {
                $inherits = !$hasLifecycle || (int) ($row['include_descendants'] ?? 1) === 1;
                if (!$inherits) {
                    continue;
                }
            }
            $level = self::level((string) $row['permission']);
            $target = (int) $row['target_id'];
            $grants[$target] = max($grants[$target] ?? self::NONE, $level);
        }

        return $grants;
    }

    private function hasExplicitKeyGrant(int $apiKeyId): bool
    {
        foreach (['rag_knowledge_base_bindings', 'rag_dataset_bindings'] as $table) {
            if (App::db()->first("SELECT 1 AS present FROM {$table} WHERE subject_type = ? AND subject_id = ? LIMIT 1", ['api_key', $apiKeyId]) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ancestors come from the materialised path ("/1/4/9/"): every proper
     * prefix ending in "/" is an ancestor path. Only active departments count;
     * an inactive ancestor cuts inheritance from itself and everything above.
     * A malformed path ("/", no trailing slash) falls back to the department
     * itself, so it can never match unrelated departments.
     *
     * @return list<int>
     */
    private function departmentChain(int $departmentId): array
    {
        if ($departmentId <= 0) {
            return [];
        }
        $department = App::db()->first('SELECT id, path, status FROM departments WHERE id = ?', [$departmentId]);
        if ($department === null) {
            return [$departmentId];
        }
        if ((string) ($department['status'] ?? 'active') !== 'active') {
            return [];
        }
        $path = (string) ($department['path'] ?? '');
        if (strlen($path) < 3 || $path[0] !== '/' || !str_ends_with($path, '/')) {
            return [$departmentId];
        }
        $prefixes = [];
        $offset = 1;
        while (($position = strpos($path, '/', $offset)) !== false) {
            $prefixes[] = substr($path, 0, $position + 1);
            $offset = $position + 1;
        }
        $rows = App::db()->select('SELECT id, path, status FROM departments WHERE path IN (' . implode(',', array_fill(0, count($prefixes), '?')) . ')', $prefixes);
        $byPath = [];
        foreach ($rows as $row) {
            $byPath[(string) $row['path']] = $row;
        }
        $chain = [];
        foreach (array_reverse($prefixes) as $prefix) {
            $row = $byPath[$prefix] ?? null;
            if ($prefix === $path) {
                $row = $department;
            }
            if ($row === null) {
                continue;
            }
            if ((string) ($row['status'] ?? 'active') !== 'active') {
                break;
            }
            $chain[] = (int) $row['id'];
        }

        return array_reverse($chain);
    }

    /** @var array<string, bool> */
    private static array $columnCache = [];

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, self::$columnCache)) {
            try {
                App::db()->first("SELECT {$column} FROM {$table} LIMIT 1");
                self::$columnCache[$key] = true;
            } catch (\Throwable) {
                self::$columnCache[$key] = false;
            }
        }

        return self::$columnCache[$key];
    }
}
