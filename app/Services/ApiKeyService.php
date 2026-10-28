<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ApiKeyService
{
    private const CACHE_TTL_SECONDS = 60;
    private const LAST_USED_UPDATE_TTL_SECONDS = 60;

    public function create(array $data, int $createdBy): array
    {
        try {
            $managementScopes = (new ApiKeyManagementScopeService())->normalize($data['management_scopes'] ?? []);
        } catch (\InvalidArgumentException) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid management scopes', 422);
        }

        $userId = (int) ($data['user_id'] ?? 0);
        $departmentId = (int) ($data['department_id'] ?? 0);
        $user = App::db()->first(
            'SELECT id FROM users WHERE id = ? AND department_id = ?',
            [$userId, $departmentId]
        );
        if ($user === null) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'API key user must belong to the selected department', 422);
        }

        $plain = Crypto::token('tkh_');
        $prefix = substr($plain, 0, 8);
        $suffix = substr($plain, -4);
        App::db()->execute(
            'INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, allowed_ip_cidrs, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $departmentId,
                $data['name'] ?? 'API Key',
                $prefix,
                $suffix,
                Crypto::hashKey($plain),
                json_encode($data['allowed_ip_cidrs'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $data['expires_at'] ?? null,
                $createdBy,
            ]
        );
        $id = (int) App::db()->pdo()->lastInsertId();
        (new ApiKeyManagementScopeService())->replace($id, $managementScopes);
        foreach ($data['model_ids'] ?? [] as $modelId) {
            App::db()->execute('INSERT IGNORE INTO api_key_model_permissions (api_key_id, model_id) VALUES (?, ?)', [$id, $modelId]);
        }
        (new AuditService())->write('api_key.create', 'api_keys', $id, ['key' => $prefix . '****' . $suffix]);
        return ['id' => $id, 'key' => $plain, 'display' => $prefix . '****' . $suffix];
    }

    public function update(int $id, array $data): array
    {
        $fields = $this->sanitizeForStorage($data);
        $allowed = ['name', 'status', 'allowed_ip_cidrs', 'expires_at'];
        $updates = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                $updates[$field] = is_array($fields[$field])
                    ? json_encode($fields[$field], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : $fields[$field];
            }
        }

        if ($updates !== []) {
            $sets = implode(', ', array_map(fn (string $field): string => "{$field} = ?", array_keys($updates)));
            App::db()->execute("UPDATE api_keys SET {$sets} WHERE id = ?", [...array_values($updates), $id]);
        }

        $this->invalidateById($id);
        (new AuditService())->write('api_key.update', 'api_keys', $id, $updates);
        return (new SensitiveDataService())->sanitizeRow('api_keys', App::db()->first('SELECT * FROM api_keys WHERE id = ?', [$id]) ?: ['id' => $id]);
    }

    public function replaceModels(int $id, array $modelIds): array
    {
        $modelIds = array_values(array_unique(array_filter(array_map('intval', $modelIds), fn (int $id): bool => $id > 0)));
        App::db()->transaction(function () use ($id, $modelIds): void {
            App::db()->execute('DELETE FROM api_key_model_permissions WHERE api_key_id = ?', [$id]);
            foreach ($modelIds as $modelId) {
                App::db()->execute('INSERT IGNORE INTO api_key_model_permissions (api_key_id, model_id) VALUES (?, ?)', [$id, $modelId]);
            }
        });

        $this->invalidateById($id);
        (new AuditService())->write('api_key.models.replace', 'api_keys', $id, ['model_ids' => $modelIds]);
        return ['id' => $id, 'model_ids' => $this->modelIds($id)];
    }

    public function replaceRagBindings(int $id, array $knowledgeBaseIds, array $datasetIds): array
    {
        $key = App::db()->first('SELECT id FROM api_keys WHERE id = ? AND status != "deleted"', [$id]);
        if ($key === null) {
            abort_json('ADMIN_NOT_FOUND', 'API key not found', 404);
        }
        $knowledgeBaseIds = $this->normalizeIds($knowledgeBaseIds);
        $datasetIds = $this->normalizeIds($datasetIds);
        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            if (App::db()->first('SELECT id FROM rag_knowledge_bases WHERE id = ? AND deleted_at IS NULL', [$knowledgeBaseId]) === null) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'Knowledge base not found', 422);
            }
        }
        foreach ($datasetIds as $datasetId) {
            if (App::db()->first('SELECT id FROM rag_vector_datasets WHERE id = ? AND deleted_at IS NULL', [$datasetId]) === null) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'Dataset not found', 422);
            }
        }

        App::db()->execute('DELETE FROM rag_knowledge_base_bindings WHERE subject_type = "api_key" AND subject_id = ?', [$id]);
        App::db()->execute('DELETE FROM rag_dataset_bindings WHERE subject_type = "api_key" AND subject_id = ?', [$id]);
        $bindings = new RagBindingService();
        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            $bindings->grantKnowledgeBase($knowledgeBaseId, 'api_key', $id);
        }
        foreach ($datasetIds as $datasetId) {
            $bindings->grantDataset($datasetId, 'api_key', $id);
        }
        (new AuditService())->write('api_key.rag_bindings.replace', 'api_keys', $id, ['knowledge_base_ids' => $knowledgeBaseIds, 'dataset_ids' => $datasetIds]);
        return $this->ragBindings($id);
    }

    public function ragBindings(int $id): array
    {
        return [
            'knowledge_base_ids' => array_map(static fn (array $row): int => (int) $row['kb_id'], App::db()->select('SELECT kb_id FROM rag_knowledge_base_bindings WHERE subject_type = "api_key" AND subject_id = ? ORDER BY kb_id', [$id])),
            'dataset_ids' => array_map(static fn (array $row): int => (int) $row['dataset_id'], App::db()->select('SELECT dataset_id FROM rag_dataset_bindings WHERE subject_type = "api_key" AND subject_id = ? ORDER BY dataset_id', [$id])),
        ];
    }

    public function softDelete(int $id): array
    {
        App::db()->execute('UPDATE api_keys SET status = "deleted" WHERE id = ?', [$id]);
        $this->invalidateById($id);
        (new AuditService())->write('api_key.delete', 'api_keys', $id);
        return ['id' => $id, 'deleted' => true];
    }

    /**
     * @param list<mixed> $ids
     */
    public function batchStatus(string $action, array $ids): array
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['enable', 'disable', 'delete'], true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid API key batch action', 422);
        }

        $apiKeyIds = $this->normalizeIds($ids);
        if ($apiKeyIds === []) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'ids cannot be empty', 422);
        }

        $placeholders = implode(', ', array_fill(0, count($apiKeyIds), '?'));
        $rows = App::db()->select("SELECT id, department_id, key_hash, key_prefix, key_suffix, name, status FROM api_keys WHERE id IN ({$placeholders})", $apiKeyIds);
        $foundIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $missingIds = array_values(array_diff($apiKeyIds, $foundIds));

        if ($foundIds === []) {
            abort_json('ADMIN_NOT_FOUND', 'No API keys found', 404);
        }

        $this->assertBatchScope($rows);

        $status = match ($action) {
            'enable' => 'active',
            'disable' => 'disabled',
            default => 'deleted',
        };
        $matchedPlaceholders = implode(', ', array_fill(0, count($foundIds), '?'));
        $updated = App::db()->execute("UPDATE api_keys SET status = ? WHERE id IN ({$matchedPlaceholders})", [$status, ...$foundIds]);

        foreach ($rows as $row) {
            if (isset($row['key_hash'])) {
                App::redis()->delete($this->cacheKey((string) $row['key_hash']));
            }
        }

        (new AuditService())->write('api_key.batch', 'api_keys', null, [
            'action' => $action,
            'status' => $status,
            'api_key_ids' => $foundIds,
            'missing_ids' => $missingIds,
            'updated' => $updated,
            'keys' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? null,
                'key' => (string) ($row['key_prefix'] ?? '') . '****' . (string) ($row['key_suffix'] ?? ''),
                'previous_status' => $row['status'] ?? null,
            ], $rows),
        ]);

        return [
            'action' => $action,
            'status' => $status,
            'requested' => count($apiKeyIds),
            'matched' => count($foundIds),
            'missing_ids' => $missingIds,
            'updated' => $updated,
            'deleted' => $action === 'delete' ? $updated : 0,
        ];
    }

    public function authenticate(string $plain, string $clientIp, ?string $modelName): array
    {
        $hash = Crypto::hashKey($plain);
        $row = App::redis()->get($this->cacheKey($hash));
        if (!is_array($row)) {
            $row = App::db()->first(
                'SELECT k.*, u.status AS user_status, d.status AS department_status FROM api_keys k JOIN users u ON u.id = k.user_id JOIN departments d ON d.id = k.department_id WHERE k.key_hash = ?',
                [$hash]
            );
            if ($row) {
                $row['allowed_model_names'] = $this->allowedModelNames((int) $row['id']);
                App::redis()->set($this->cacheKey($hash), $row, self::CACHE_TTL_SECONDS);
            }
        }
        if (!$row || $row['status'] !== 'active' || $row['user_status'] !== 'active' || $row['department_status'] !== 'active') {
            return ['ok' => false, 'code' => 'API_KEY_INVALID', 'message' => 'Invalid API key', 'status' => 401];
        }
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            return ['ok' => false, 'code' => 'API_KEY_EXPIRED', 'message' => 'API key expired', 'status' => 401];
        }
        if (!$this->ipAllowed($clientIp, json_decode($row['allowed_ip_cidrs'] ?? '[]', true) ?: [])) {
            return ['ok' => false, 'code' => 'IP_FORBIDDEN', 'message' => 'Client IP is not allowed', 'status' => 403];
        }
        if ($modelName && !$this->modelAllowed($row, $modelName)) {
            return ['ok' => false, 'code' => 'MODEL_FORBIDDEN', 'message' => 'Model is not allowed for this key', 'status' => 403];
        }

        $this->touchLastUsed((int) $row['id']);
        return ['ok' => true, 'data' => $row];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array{ok: bool, code?: string, message?: string, status?: int}
     */
    public function assertModelAllowed(array $apiKey, string $modelName): array
    {
        if ($modelName === '' || $this->modelAllowed($apiKey, $modelName)) {
            return ['ok' => true];
        }

        return ['ok' => false, 'code' => 'MODEL_FORBIDDEN', 'message' => 'Model is not allowed for this key', 'status' => 403];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWithUsage(array $query = []): array
    {
        $rows = (new CrudService())->list('api_keys', $query);
        $ids = array_values(array_filter(
            array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows),
            static fn (int $id): bool => $id > 0
        ));

        if ($ids === []) {
            return $rows;
        }

        $usageByKey = $this->usageSummaries($ids, $query);
        $modelIdsByKey = $this->modelIdsByApiKeyIds($ids);
        $departmentIds = array_values(array_unique(array_filter(
            array_map(static fn (array $row): int => (int) ($row['department_id'] ?? 0), $rows),
            static fn (int $id): bool => $id > 0
        )));
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn (array $row): int => (int) ($row['user_id'] ?? 0), $rows),
            static fn (int $id): bool => $id > 0
        )));
        $departmentNames = [];
        $userNames = [];
        if ($departmentIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($departmentIds), '?'));
            $departmentNames = array_column(App::db()->select("SELECT id, name FROM departments WHERE id IN ({$placeholders})", $departmentIds), 'name', 'id');
        }
        if ($userIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
            $userNames = array_column(App::db()->select("SELECT id, username FROM users WHERE id IN ({$placeholders})", $userIds), 'username', 'id');
        }

        return array_map(static function (array $row) use ($usageByKey, $modelIdsByKey, $departmentNames, $userNames): array {
            $apiKeyId = (int) ($row['id'] ?? 0);
            $summary = $usageByKey[$apiKeyId] ?? [];
            return [
                ...$row,
                'model_ids' => $modelIdsByKey[$apiKeyId] ?? [],
                'department_name' => $departmentNames[(int) ($row['department_id'] ?? 0)] ?? null,
                'user_name' => $userNames[(int) ($row['user_id'] ?? 0)] ?? null,
                'request_count' => (int) ($summary['request_count'] ?? 0),
                'success_count' => (int) ($summary['success_count'] ?? 0),
                'failed_count' => (int) ($summary['failed_count'] ?? 0),
                'input_tokens' => (int) ($summary['input_tokens'] ?? 0),
                'output_tokens' => (int) ($summary['output_tokens'] ?? 0),
                'cache_read_tokens' => (int) ($summary['cache_read_tokens'] ?? 0),
                'cache_write_tokens' => (int) ($summary['cache_write_tokens'] ?? 0),
                'cache_tokens' => (int) ($summary['cache_tokens'] ?? 0),
                'total_tokens' => (int) ($summary['total_tokens'] ?? 0),
                'total_cost' => $summary['total_cost'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listPageWithUsage(array $query = []): array
    {
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        return [
            'items' => $this->listWithUsage($query),
            'total' => (new CrudService())->count('api_keys', $query),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function ipAllowed(string $ip, array $cidrs): bool
    {
        if ($cidrs === []) {
            return true;
        }
        foreach ($cidrs as $cidr) {
            if ($cidr === $ip || $this->cidrMatch($ip, (string) $cidr)) {
                return true;
            }
        }
        return false;
    }

    public function cacheKey(string $keyHash): string
    {
        return 'api_key:' . $keyHash;
    }

    public function lastUsedCacheKey(int $apiKeyId): string
    {
        return 'api_key:last_used:' . $apiKeyId;
    }

    public function sanitizeForStorage(array $data): array
    {
        unset($data['key'], $data['plain'], $data['plaintext'], $data['key_hash']);
        return $data;
    }

    /**
     * @param list<int> $apiKeyIds
     * @return array<int, array<string, mixed>>
     */
    private function usageSummaries(array $apiKeyIds, array $query): array
    {
        $conditions = ['api_key_id IN (' . implode(', ', array_fill(0, count($apiKeyIds), '?')) . ')'];
        $bindings = $apiKeyIds;

        if (isset($query['from']) && (string) $query['from'] !== '') {
            $conditions[] = 'bucket_at >= ?';
            $bindings[] = (string) $query['from'];
        }
        if (isset($query['to']) && (string) $query['to'] !== '') {
            $conditions[] = 'bucket_at <= ?';
            $bindings[] = (string) $query['to'];
        }

        $rows = App::db()->select(
            'SELECT api_key_id,
                    COALESCE(SUM(request_count),0) request_count,
                    COALESCE(SUM(success_count),0) success_count,
                    COALESCE(SUM(failed_count),0) failed_count,
                    COALESCE(SUM(input_tokens),0) input_tokens,
                    COALESCE(SUM(output_tokens),0) output_tokens,
                    COALESCE(SUM(cache_read_tokens),0) cache_read_tokens,
                    COALESCE(SUM(cache_write_tokens),0) cache_write_tokens,
                    COALESCE(SUM(cache_read_tokens + cache_write_tokens),0) cache_tokens,
                    COALESCE(SUM(input_tokens + output_tokens),0) total_tokens,
                    COALESCE(SUM(total_cost),0) total_cost
             FROM usage_aggregates_day
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY api_key_id',
            $bindings
        );

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[(int) $row['api_key_id']] = $row;
        }

        return $summaries;
    }

    private function modelAllowed(array $apiKey, string $modelName): bool
    {
        $allowed = $apiKey['allowed_model_names'] ?? null;
        if (is_array($allowed)) {
            return in_array($modelName, $allowed, true);
        }

        return App::db()->first(
            'SELECT m.id FROM models m JOIN api_key_model_permissions p ON p.model_id = m.id WHERE p.api_key_id = ? AND m.name = ? AND m.status = "active"',
            [(int) $apiKey['id'], $modelName]
        ) !== null;
    }

    private function touchLastUsed(int $apiKeyId): void
    {
        if ($apiKeyId <= 0) {
            return;
        }

        $cacheKey = $this->lastUsedCacheKey($apiKeyId);
        if (App::redis()->get($cacheKey) !== null) {
            return;
        }

        App::db()->execute('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?', [$apiKeyId]);
        App::redis()->set($cacheKey, '1', self::LAST_USED_UPDATE_TTL_SECONDS);
    }

    private function allowedModelNames(int $apiKeyId): array
    {
        return array_map(
            fn (array $row): string => (string) $row['name'],
            App::db()->select('SELECT m.name FROM models m JOIN api_key_model_permissions p ON p.model_id = m.id WHERE p.api_key_id = ? AND m.status = "active"', [$apiKeyId])
        );
    }

    private function modelIds(int $apiKeyId): array
    {
        return $this->modelIdsByApiKeyIds([$apiKeyId])[$apiKeyId] ?? [];
    }

    /**
     * @param list<int> $apiKeyIds
     * @return array<int, list<int>>
     */
    private function modelIdsByApiKeyIds(array $apiKeyIds): array
    {
        if ($apiKeyIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($apiKeyIds), '?'));
        $rows = App::db()->select(
            "SELECT api_key_id, model_id FROM api_key_model_permissions WHERE api_key_id IN ({$placeholders}) ORDER BY api_key_id ASC, model_id ASC",
            $apiKeyIds
        );

        $modelIdsByKey = [];
        foreach ($apiKeyIds as $apiKeyId) {
            $modelIdsByKey[$apiKeyId] = [];
        }
        foreach ($rows as $row) {
            $apiKeyId = (int) ($row['api_key_id'] ?? 0);
            $modelId = (int) ($row['model_id'] ?? 0);
            if ($apiKeyId > 0 && $modelId > 0) {
                $modelIdsByKey[$apiKeyId][] = $modelId;
            }
        }

        return $modelIdsByKey;
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $normalized[$intId] = $intId;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function assertBatchScope(array $rows): void
    {
        $actor = App::context()->adminUser();
        if (!$actor || (new PermissionService())->usesGlobalScope($actor)) {
            return;
        }

        $permission = new PermissionService();
        foreach ($rows as $row) {
            if (!$permission->canManage($actor, (int) $row['department_id'])) {
                abort_json('ADMIN_PERMISSION_DENIED', 'API key is outside admin scope', 403);
            }
        }
    }

    private function invalidateById(int $id): void
    {
        $row = App::db()->first('SELECT key_hash FROM api_keys WHERE id = ?', [$id]);
        if ($row && isset($row['key_hash'])) {
            App::redis()->delete($this->cacheKey((string) $row['key_hash']));
        }
    }

    public function invalidateByUser(int $userId): void
    {
        foreach (App::db()->select('SELECT key_hash FROM api_keys WHERE user_id = ?', [$userId]) as $row) {
            if (isset($row['key_hash'])) {
                App::redis()->delete($this->cacheKey((string) $row['key_hash']));
            }
        }
    }

    public function invalidateByDepartment(int $departmentId): void
    {
        $department = App::db()->first('SELECT path FROM departments WHERE id = ?', [$departmentId]);
        if (!$department) {
            return;
        }

        foreach (App::db()->select(
            'SELECT k.key_hash FROM api_keys k JOIN departments d ON d.id = k.department_id WHERE d.path LIKE ?',
            [(string) $department['path'] . '%']
        ) as $row) {
            if (isset($row['key_hash'])) {
                App::redis()->delete($this->cacheKey((string) $row['key_hash']));
            }
        }
    }

    private function cidrMatch(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
