<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\SchemaInspector;
use TokenHub\Support\ConfigVersion;
use TokenHub\Support\Crypto;

final class ApiKeyService
{
    private const CACHE_TTL_SECONDS = 60;
    private const LAST_USED_UPDATE_TTL_SECONDS = 60;
    private const MISS_CACHE_TTL_SECONDS = 30;

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
        App::redis()->delete($this->missCacheKey(Crypto::hashKey($plain)));
        (new ApiKeyManagementScopeService())->replace($id, $managementScopes);
        foreach ($data['model_ids'] ?? [] as $modelId) {
            App::db()->execute('INSERT IGNORE INTO api_key_model_permissions (api_key_id, model_id) VALUES (?, ?)', [$id, $modelId]);
        }
        (new AuditService())->write('api_key.create', 'api_keys', $id, ['key' => $prefix . '****' . $suffix]);
        return ['id' => $id, 'key' => $plain, 'display' => $prefix . '****' . $suffix];
    }

    /**
     * @param string $actorType who changes the key: 'admin' (console) or
     *        'management' (agent management API). Recorded in
     *        api_keys.disabled_by when the key is disabled, so an agent cannot
     *        re-enable a key an administrator switched off.
     */
    public function update(int $id, array $data, string $actorType = 'admin'): array
    {
        $modelIds = array_key_exists('model_ids', $data) && is_array($data['model_ids'])
            ? $this->normalizeModelIds($data['model_ids'])
            : null;
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

        $scopeChange = null;
        if (array_key_exists('management_scopes', $data)) {
            // Admin path only: the management API never forwards this field.
            try {
                $scopeChange = (new ApiKeyManagementScopeService())->normalize($data['management_scopes'] ?? []);
            } catch (\InvalidArgumentException) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid management scopes', 422);
            }
            // A delegated key may never hold more than its parent (the same
            // invariant ApiKeyManagementScopeService::delegate() enforces).
            $beyond = (new ApiKeyManagementScopeService())->scopesBeyondParent($id, $scopeChange);
            if ($beyond !== []) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'This key was delegated by another management key that does not hold: ' . implode(', ', $beyond), 422);
            }
        }

        if (isset($updates['status']) && SchemaInspector::hasColumn('api_keys', 'disabled_by')) {
            $updates['disabled_by'] = $updates['status'] === 'disabled' ? self::disabledByValue($actorType) : null;
        }

        // Key attributes, model permissions and management scopes change
        // together or not at all.
        App::db()->transaction(function () use ($id, $updates, $modelIds, $scopeChange): void {
            if ($updates !== []) {
                $sets = implode(', ', array_map(fn (string $field): string => "{$field} = ?", array_keys($updates)));
                App::db()->execute("UPDATE api_keys SET {$sets} WHERE id = ?", [...array_values($updates), $id]);
            }
            if ($modelIds !== null) {
                $this->writeModelPermissions($id, $modelIds);
            }
            if ($scopeChange !== null) {
                (new ApiKeyManagementScopeService())->replace($id, $scopeChange);
                (new ApiKeyManagementScopeService())->pruneDescendants($id);
            }
        });
        if ($scopeChange !== null) {
            $updates['management_scopes'] = $scopeChange;
        }

        $this->invalidateById($id);
        (new AuditService())->write('api_key.update', 'api_keys', $id, $modelIds === null ? $updates : [...$updates, 'model_ids' => $modelIds]);
        $row = (new SensitiveDataService())->sanitizeRow('api_keys', App::db()->first('SELECT * FROM api_keys WHERE id = ?', [$id]) ?: ['id' => $id]);
        if ($modelIds !== null) {
            $row['model_ids'] = $this->modelIds($id);
        }
        return $row;
    }

    /**
     * @param array<int, mixed> $modelIds
     * @return list<int>
     */
    private function normalizeModelIds(array $modelIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $modelIds), fn (int $id): bool => $id > 0)));
    }

    /**
     * @param list<int> $modelIds
     */
    private function writeModelPermissions(int $id, array $modelIds): void
    {
        App::db()->execute('DELETE FROM api_key_model_permissions WHERE api_key_id = ?', [$id]);
        foreach ($modelIds as $modelId) {
            App::db()->execute('INSERT IGNORE INTO api_key_model_permissions (api_key_id, model_id) VALUES (?, ?)', [$id, $modelId]);
        }
    }

    public function replaceModels(int $id, array $modelIds): array
    {
        $modelIds = $this->normalizeModelIds($modelIds);
        App::db()->transaction(function () use ($id, $modelIds): void {
            $this->writeModelPermissions($id, $modelIds);
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
            $knowledgeBase = App::db()->first('SELECT id, department_id, visibility FROM rag_knowledge_bases WHERE id = ? AND deleted_at IS NULL', [$knowledgeBaseId]);
            if ($knowledgeBase === null) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'Knowledge base not found', 422);
            }
            $this->assertKnowledgeBaseInAdminScope($knowledgeBase);
        }
        foreach ($datasetIds as $datasetId) {
            $dataset = App::db()->first('SELECT d.id, kb.department_id, kb.visibility FROM rag_vector_datasets d JOIN rag_knowledge_bases kb ON kb.id = d.kb_id WHERE d.id = ? AND d.deleted_at IS NULL AND kb.deleted_at IS NULL', [$datasetId]);
            if ($dataset === null) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'Dataset not found', 422);
            }
            $this->assertKnowledgeBaseInAdminScope($dataset);
        }

        // One transaction: a failure part-way must not leave the key with its grants wiped.
        App::db()->transaction(function () use ($id, $knowledgeBaseIds, $datasetIds): void {
            App::db()->execute('DELETE FROM rag_knowledge_base_bindings WHERE subject_type = "api_key" AND subject_id = ?', [$id]);
            App::db()->execute('DELETE FROM rag_dataset_bindings WHERE subject_type = "api_key" AND subject_id = ?', [$id]);
            $bindings = new RagBindingService();
            foreach ($knowledgeBaseIds as $knowledgeBaseId) {
                $bindings->grantKnowledgeBase($knowledgeBaseId, 'api_key', $id);
            }
            foreach ($datasetIds as $datasetId) {
                $bindings->grantDataset($datasetId, 'api_key', $id);
            }
        });
        (new AuditService())->write('api_key.rag_bindings.replace', 'api_keys', $id, ['knowledge_base_ids' => $knowledgeBaseIds, 'dataset_ids' => $datasetIds]);
        return $this->ragBindings($id);
    }

    /**
     * Department-scoped admins may only bind knowledge bases of departments
     * they manage, or knowledge bases shared platform-wide.
     *
     * @param array<string, mixed> $knowledgeBase department_id, visibility
     */
    private function assertKnowledgeBaseInAdminScope(array $knowledgeBase): void
    {
        $actor = App::context()->adminUser();
        if ($actor === null || ($knowledgeBase['visibility'] ?? '') === 'shared') {
            return;
        }
        $departmentId = $knowledgeBase['department_id'] === null ? null : (int) $knowledgeBase['department_id'];
        if (!(new PermissionService())->canManage($actor, $departmentId)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Knowledge base is outside admin scope', 403);
        }
    }

    public function ragBindings(int $id): array
    {
        return [
            'knowledge_base_ids' => array_map(static fn (array $row): int => (int) $row['kb_id'], App::db()->select('SELECT kb_id FROM rag_knowledge_base_bindings WHERE subject_type = "api_key" AND subject_id = ? ORDER BY kb_id', [$id])),
            'dataset_ids' => array_map(static fn (array $row): int => (int) $row['dataset_id'], App::db()->select('SELECT dataset_id FROM rag_dataset_bindings WHERE subject_type = "api_key" AND subject_id = ? ORDER BY dataset_id', [$id])),
        ];
    }

    /**
     * Rotates an API key without downtime: a new secret with the same owner,
     * model grants, RAG bindings, IP allowlist and management scopes is
     * issued, and the old key keeps working only until the grace period ends
     * (expires_at). Rotation is audited on both keys.
     *
     * @return array{id: int, key: string, display: string, replaces_api_key_id: int, old_key_expires_at: string}
     */
    public function rotate(int $id, int $graceMinutes, int $actorId): array
    {
        $old = App::db()->first('SELECT * FROM api_keys WHERE id = ? AND status = "active"', [$id]);
        if ($old === null) {
            abort_json('ADMIN_NOT_FOUND', 'Active API key not found', 404);
        }
        $actor = App::context()->adminUser();
        if ($actor !== null && !(new PermissionService())->canManage($actor, (int) $old['department_id'])) {
            abort_json('ADMIN_PERMISSION_DENIED', 'API key is outside admin scope', 403);
        }
        $graceMinutes = max(0, min(10080, $graceMinutes));
        $graceEnds = gmdate('Y-m-d H:i:s', time() + $graceMinutes * 60);
        if (!empty($old['expires_at']) && strtotime((string) $old['expires_at']) < strtotime($graceEnds . ' UTC')) {
            $graceEnds = (string) $old['expires_at'];
        }

        $plain = Crypto::token('tkh_');
        $prefix = substr($plain, 0, 8);
        $suffix = substr($plain, -4);
        $newId = App::db()->transaction(function () use ($old, $plain, $prefix, $suffix, $actorId, $graceEnds, $id): int {
            App::db()->execute(
                'INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, allowed_ip_cidrs, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$old['user_id'], $old['department_id'], $old['name'], $prefix, $suffix, Crypto::hashKey($plain), $old['allowed_ip_cidrs'] ?? '[]', $old['expires_at'] ?? null, $actorId]
            );
            $newId = (int) App::db()->pdo()->lastInsertId();
            App::db()->execute('INSERT INTO api_key_model_permissions (api_key_id, model_id) SELECT ?, model_id FROM api_key_model_permissions WHERE api_key_id = ?', [$newId, $id]);
            App::db()->execute('INSERT INTO api_key_model_quotas (api_key_id, model_id, period, request_limit, input_token_limit, output_token_limit, total_token_limit, status) SELECT ?, model_id, period, request_limit, input_token_limit, output_token_limit, total_token_limit, status FROM api_key_model_quotas WHERE api_key_id = ?', [$newId, $id]);
            App::db()->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission, is_default) SELECT kb_id, subject_type, ?, permission, is_default FROM rag_knowledge_base_bindings WHERE subject_type = "api_key" AND subject_id = ?', [$newId, $id]);
            App::db()->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default, priority) SELECT dataset_id, subject_type, ?, permission, is_default, priority FROM rag_dataset_bindings WHERE subject_type = "api_key" AND subject_id = ?', [$newId, $id]);
            App::db()->execute('UPDATE api_keys SET expires_at = ? WHERE id = ?', [$graceEnds, $id]);
            // Keep the management delegation tree intact: the new key takes the
            // old key's place under its parent, and keys the old key delegated
            // to now hang off the new key (otherwise a rotated child would lose
            // the "parent disabled => scopes revoked" guarantee, and the old
            // key's children would keep working after it expires).
            App::db()->execute('INSERT INTO api_key_management_scopes (api_key_id, scope) SELECT ?, scope FROM api_key_management_scopes WHERE api_key_id = ?', [$newId, $id]);
            App::db()->execute('INSERT INTO api_key_management_delegations (child_api_key_id, parent_api_key_id) SELECT ?, parent_api_key_id FROM api_key_management_delegations WHERE child_api_key_id = ?', [$newId, $id]);
            App::db()->execute('UPDATE api_key_management_delegations SET parent_api_key_id = ? WHERE parent_api_key_id = ?', [$newId, $id]);

            return $newId;
        });
        App::redis()->delete($this->missCacheKey(Crypto::hashKey($plain)));
        $this->invalidateById($id);
        (new AuditService())->write('api_key.rotate', 'api_keys', $id, ['replacement_id' => $newId, 'old_key_expires_at' => $graceEnds]);
        (new AuditService())->write('api_key.create', 'api_keys', $newId, ['key' => $prefix . '****' . $suffix, 'rotated_from' => $id]);

        return ['id' => $newId, 'key' => $plain, 'display' => $prefix . '****' . $suffix, 'replaces_api_key_id' => $id, 'old_key_expires_at' => $graceEnds];
    }

    private static function disabledByValue(string $actorType): string
    {
        return $actorType === 'management' ? 'management' : 'admin';
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
        $updated = SchemaInspector::hasColumn('api_keys', 'disabled_by')
            ? App::db()->execute("UPDATE api_keys SET status = ?, disabled_by = ? WHERE id IN ({$matchedPlaceholders})", [$status, $status === 'disabled' ? 'admin' : null, ...$foundIds])
            : App::db()->execute("UPDATE api_keys SET status = ? WHERE id IN ({$matchedPlaceholders})", [$status, ...$foundIds]);

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

    public function authenticate(string $plain, string $clientIp, ?string $modelName, bool $allowLookup = true): array
    {
        $hash = Crypto::hashKey($plain);
        $row = App::redis()->get($this->cacheKey($hash));
        if (!is_array($row)) {
            // Unknown keys are remembered briefly so repeated garbage keys do
            // not hit MySQL on every request.
            if (App::redis()->get($this->missCacheKey($hash)) !== null) {
                return ['ok' => false, 'code' => 'API_KEY_INVALID', 'message' => 'Invalid API key', 'status' => 401];
            }
            if (!$allowLookup) {
                return ['ok' => false, 'code' => 'RATE_LIMITED', 'message' => 'Too many requests', 'status' => 429];
            }
            $row = App::db()->first(
                'SELECT k.*, u.status AS user_status, d.status AS department_status FROM api_keys k JOIN users u ON u.id = k.user_id JOIN departments d ON d.id = k.department_id WHERE k.key_hash = ?',
                [$hash]
            );
            if (!$row) {
                $row = $this->findByRotatedHash($plain, $hash);
            }
            if ($row) {
                $row['allowed_model_names'] = $this->allowedModelNames((int) $row['id']);
                // Channel-level grants: the router only considers these model rows.
                $row['allowed_model_ids'] = $this->activeModelIds((int) $row['id']);
                App::redis()->set($this->cacheKey($hash), $row, self::CACHE_TTL_SECONDS);
            } else {
                App::redis()->set($this->missCacheKey($hash), '1', self::MISS_CACHE_TTL_SECONDS);
            }
        }
        return $this->verifyRow(is_array($row) ? $row : null, $clientIp, $modelName);
    }

    /**
     * Authenticates a key by id for credentials that stand in for it (MCP
     * OAuth access tokens): the same status, owner, expiry and IP checks as a
     * presented key, so disabling or expiring the key ends the token too.
     */
    public function authenticateById(int $id, string $clientIp, ?string $modelName = null): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'code' => 'API_KEY_INVALID', 'message' => 'Invalid API key', 'status' => 401];
        }
        $hash = App::redis()->get($this->idHashCacheKey($id));
        if (!is_string($hash) || $hash === '') {
            $hash = (string) (App::db()->first('SELECT key_hash FROM api_keys WHERE id = ?', [$id])['key_hash'] ?? '');
            if ($hash === '') {
                return ['ok' => false, 'code' => 'API_KEY_INVALID', 'message' => 'Invalid API key', 'status' => 401];
            }
            App::redis()->set($this->idHashCacheKey($id), $hash, self::CACHE_TTL_SECONDS);
        }
        $row = App::redis()->get($this->cacheKey($hash));
        if (!is_array($row) || (int) ($row['id'] ?? 0) !== $id) {
            $row = App::db()->first(
                'SELECT k.*, u.status AS user_status, d.status AS department_status FROM api_keys k JOIN users u ON u.id = k.user_id JOIN departments d ON d.id = k.department_id WHERE k.id = ?',
                [$id]
            );
            if ($row) {
                $row['allowed_model_names'] = $this->allowedModelNames((int) $row['id']);
                $row['allowed_model_ids'] = $this->activeModelIds((int) $row['id']);
                App::redis()->set($this->cacheKey((string) $row['key_hash']), $row, self::CACHE_TTL_SECONDS);
            }
        }

        return $this->verifyRow($row ?: null, $clientIp, $modelName);
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{ok: bool, code?: string, message?: string, status?: int, data?: array<string, mixed>}
     */
    private function verifyRow(?array $row, string $clientIp, ?string $modelName): array
    {
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

    private function idHashCacheKey(int $id): string
    {
        return 'api_key_id_hash:' . $id;
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

        $scopesByKey = [];
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        foreach (App::db()->select("SELECT api_key_id, scope FROM api_key_management_scopes WHERE api_key_id IN ({$placeholders})", $ids) as $scopeRow) {
            $scopesByKey[(int) $scopeRow['api_key_id']][] = (string) $scopeRow['scope'];
        }

        return array_map(static function (array $row) use ($usageByKey, $modelIdsByKey, $departmentNames, $userNames, $scopesByKey): array {
            $apiKeyId = (int) ($row['id'] ?? 0);
            $summary = $usageByKey[$apiKeyId] ?? [];
            return [
                ...$row,
                'model_ids' => $modelIdsByKey[$apiKeyId] ?? [],
                'management_scopes' => $scopesByKey[$apiKeyId] ?? [],
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
        return 'api_key:' . $keyHash . ConfigVersion::cacheSuffix();
    }

    public function missCacheKey(string $keyHash): string
    {
        return 'api_key:miss:' . $keyHash . ConfigVersion::cacheSuffix();
    }

    /**
     * Model ids (channels) this key may route to, or null when the key row
     * predates channel grants and only name-level checks apply.
     *
     * @param array<string, mixed> $apiKey
     * @return list<int>|null
     */
    public function routableModelIds(array $apiKey): ?array
    {
        if (isset($apiKey['allowed_model_ids']) && is_array($apiKey['allowed_model_ids'])) {
            return array_values(array_map('intval', $apiKey['allowed_model_ids']));
        }
        if (!isset($apiKey['id']) || (int) $apiKey['id'] <= 0) {
            return null;
        }

        return $this->activeModelIds((int) $apiKey['id']);
    }

    /**
     * @return list<int>
     */
    private function activeModelIds(int $apiKeyId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['model_id'],
            App::db()->select('SELECT p.model_id FROM api_key_model_permissions p JOIN models m ON m.id = p.model_id WHERE p.api_key_id = ? AND m.status = "active"', [$apiKeyId])
        );
    }

    /**
     * During an APP_KEY rotation stored hashes may still use a previous key.
     * A hit is re-hashed with the current key so the fallback is needed once.
     *
     * @return array<string, mixed>|null
     */
    private function findByRotatedHash(string $plain, string $currentHash): ?array
    {
        foreach (Crypto::hashKeyCandidates($plain) as $candidate) {
            if ($candidate === $currentHash) {
                continue;
            }
            $row = App::db()->first(
                'SELECT k.*, u.status AS user_status, d.status AS department_status FROM api_keys k JOIN users u ON u.id = k.user_id JOIN departments d ON d.id = k.department_id WHERE k.key_hash = ?',
                [$candidate]
            );
            if ($row) {
                App::db()->execute('UPDATE api_keys SET key_hash = ? WHERE id = ? AND key_hash = ?', [$currentHash, (int) $row['id'], $candidate]);
                $row['key_hash'] = $currentHash;
                return $row;
            }
        }

        return null;
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
