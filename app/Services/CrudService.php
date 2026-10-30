<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\SchemaInspector;

final class CrudService
{
    private const ALLOWED_TABLES = [
        'departments', 'users', 'providers', 'models', 'model_prices', 'api_keys', 'api_key_model_permissions', 'api_key_model_quotas', 'quota_policies', 'quota_bindings',
        'usage_records', 'multimodal_tasks', 'media_assets', 'usage_contents', 'export_tasks',
        'dashboard_share_links', 'dashboard_share_access_logs',
        'desensitization_policies', 'desensitization_events', 'log_retention_policies', 'log_archive_files', 'alert_events', 'audit_logs', 'system_settings',
        'rag_knowledge_bases', 'rag_knowledge_base_bindings', 'rag_vector_datasets', 'rag_dataset_bindings', 'rag_vector_configs', 'rag_import_templates',
        'rag_uploads', 'rag_upload_preview_items', 'rag_documents', 'rag_chunks', 'rag_image_assets', 'rag_embedding_jobs', 'rag_vector_records',
        'rag_retrieval_logs', 'rag_answer_feedback', 'rag_correction_tasks', 'rag_retention_policies', 'rag_eval_cases', 'rag_eval_runs', 'rag_eval_results',
    ];
    private const SUPER_ADMIN_WRITE_TABLES = [
        'providers',
        'models',
        'model_prices',
        'desensitization_policies',
        'log_retention_policies',
        'system_settings',
    ];

    public function list(string $table, array $query): array
    {
        $this->assertTable($table);
        [$where, $bindings] = $this->listWhere($table, $query);
        $defaultSort = $table === 'model_prices' && isset($query['effective_at']) ? 'effective_at_desc' : 'id_desc';
        [$sortColumn, $direction] = $this->sortClause($table, (string) ($query['sort'] ?? $defaultSort));
        if ($table === 'models') {
            $sortColumn = 'models.' . $sortColumn;
        }
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        [$select, $from] = $this->listSelectFrom($table);
        $rows = (new SensitiveDataService())->sanitizeRows(
            $table,
            App::db()->select("SELECT {$select} FROM {$from}{$where} ORDER BY {$sortColumn} {$direction} LIMIT {$limit} OFFSET {$offset}", $bindings)
        );

        return $table === 'models' ? (new ModelSupportPolicy())->annotate($rows) : $rows;
    }

    public function count(string $table, array $query): int
    {
        $this->assertTable($table);
        [$where, $bindings] = $this->listWhere($table, $query);
        [, $from] = $this->listSelectFrom($table);
        $row = App::db()->first("SELECT COUNT(*) AS total FROM {$from}{$where}", $bindings);
        return (int) ($row['total'] ?? 0);
    }

    public function create(string $table, array $data): array
    {
        $this->assertTable($table);
        $this->assertWritableTable($table);
        $this->requireSuperAdminForGlobalWrite($table);
        $this->requireWritePermission($table, $data);
        (new RagAdminScopeGuard())->assertCreateHasOwner(App::context()->adminUser() ?? [], $table, $data);
        $this->assertUserRoleWriteAllowed($table, $data);
        unset($data['expected_updated_at']);
        $this->validateConfigWrite($table, $data, null);
        $data = $this->normalizeForWrite($table, $data);
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), $placeholders);
        App::db()->execute($sql, array_values($data));
        $id = (int) App::db()->pdo()->lastInsertId();
        if ($table === 'departments') {
            $current = App::db()->first('SELECT parent_id FROM departments WHERE id = ?', [$id]);
            $parent = $current && $current['parent_id'] ? App::db()->first('SELECT path, level FROM departments WHERE id = ?', [$current['parent_id']]) : null;
            App::db()->execute('UPDATE departments SET path = ?, level = ? WHERE id = ?', [
                $parent ? ($parent['path'] . $id . '/') : ('/' . $id . '/'),
                $parent ? ((int) $parent['level'] + 1) : 0,
                $id,
            ]);
        }
        (new AuditService())->write('admin.create', $table, $id, $this->auditDetails($table, $data));
        return (new SensitiveDataService())->sanitizeRow($table, App::db()->first("SELECT * FROM {$table} WHERE id = ?", [$id]) ?: ['id' => $id]);
    }

    public function update(string $table, int $id, array $data): array
    {
        $this->assertTable($table);
        $this->assertWritableTable($table);
        $this->requireSuperAdminForGlobalWrite($table);
        $this->assertExistingScope($table, $id);
        $this->requireWritePermission($table, $data);
        $this->assertUserRoleWriteAllowed($table, $data, $id);
        $this->assertNotStale($table, $id, $data);
        unset($data['expected_updated_at']);
        $this->validateConfigWrite($table, $data, $id);
        $data = $this->normalizeForWrite($table, $data, false, $id);
        unset($data['id']);
        if ($data === []) {
            return App::db()->first("SELECT * FROM {$table} WHERE id = ?", [$id]) ?: ['id' => $id];
        }
        $sets = implode(', ', array_map(fn (string $column): string => "{$column} = ?", array_keys($data)));
        App::db()->execute("UPDATE {$table} SET {$sets} WHERE id = ?", [...array_values($data), $id]);
        if ($table === 'departments' && array_key_exists('parent_id', $data)) {
            (new DepartmentService())->rebuildPath($id);
        }
        if ($table === 'departments' && array_key_exists('status', $data)) {
            (new DepartmentService())->invalidateDepartmentApiKeys($id);
        }
        if ($table === 'users' && array_key_exists('department_id', $data)) {
            // API keys follow their user, so RAG and quota scope move with the user (RAG-005).
            App::db()->execute('UPDATE api_keys SET department_id = ? WHERE user_id = ?', [(int) $data['department_id'], $id]);
            $this->invalidateUserApiKeys($id);
        }
        if ($table === 'rag_chunks') {
            (new RagChunkLifecycleService())->afterAdminUpdate($id, $data);
        }
        (new AuditService())->write('admin.update', $table, $id, $this->auditDetails($table, $data));
        return (new SensitiveDataService())->sanitizeRow($table, App::db()->first("SELECT * FROM {$table} WHERE id = ?", [$id]) ?: ['id' => $id]);
    }

    public function disable(string $table, int $id): array
    {
        $this->assertTable($table);
        $this->assertWritableTable($table);
        $this->requireSuperAdminForGlobalWrite($table);
        $this->assertExistingScope($table, $id);
        $this->requireWritePermission($table, []);
        if (in_array($table, ['rag_knowledge_base_bindings', 'rag_dataset_bindings'], true)) {
            // A grant has no status column: revoking it means removing the row.
            App::db()->execute("DELETE FROM {$table} WHERE id = ?", [$id]);
            (new AuditService())->write('rag.acl.revoke', $table, $id);
            return ['id' => $id, 'disabled' => true];
        }
        if ($table === 'rag_chunks') {
            (new RagChunkLifecycleService())->disable([$id]);
            (new AuditService())->write('admin.disable', $table, $id);
            return ['id' => $id, 'disabled' => true];
        }
        $columns = App::db()->select("SHOW COLUMNS FROM {$table}");
        $names = array_column($columns, 'Field');
        if (in_array('status', $names, true)) {
            App::db()->execute("UPDATE {$table} SET status = ? WHERE id = ?", ['disabled', $id]);
        }
        if ($table === 'users') {
            $this->invalidateUserSessions($id);
            $this->invalidateUserApiKeys($id);
        }
        if ($table === 'departments') {
            (new DepartmentService())->invalidateDepartmentApiKeys($id);
        }
        (new AuditService())->write('admin.disable', $table, $id);
        return ['id' => $id, 'disabled' => true];
    }

    public function deleteModel(int $id): array
    {
        $this->assertTable('models');
        $this->assertWritableTable('models');
        $this->requireSuperAdminForGlobalWrite('models');
        $this->requireWritePermission('models', []);

        $model = App::db()->first('SELECT * FROM models WHERE id = ?', [$id]);
        if (!$model) {
            abort_json('ADMIN_NOT_FOUND', 'Model not found', 404);
        }
        if ($this->hasUsageHistory([$id])) {
            return $this->retireModels([$id], 'models', $id);
        }

        $deleted = App::db()->transaction(function ($db) use ($id, $model): array {
            $counts = [
                'model_prices' => $db->execute('DELETE FROM model_prices WHERE model_id = ?', [$id]),
                'api_key_model_permissions' => $db->execute('DELETE FROM api_key_model_permissions WHERE model_id = ?', [$id]),
                'api_key_model_quotas' => $db->execute('DELETE FROM api_key_model_quotas WHERE model_id = ?', [$id]),
            ];
            $counts['models'] = $db->execute('DELETE FROM models WHERE id = ?', [$id]);

            (new AuditService())->write('admin.delete', 'models', $id, [
                'model' => $this->auditDetails('models', $model),
                'deleted_related' => $counts,
            ]);

            return $counts;
        });

        return ['id' => $id, 'deleted' => true, 'deleted_related' => $deleted];
    }

    /**
     * @param list<mixed> $ids
     */
    public function batchModels(string $action, array $ids): array
    {
        $this->assertTable('models');
        $this->assertWritableTable('models');
        $this->requireSuperAdminForGlobalWrite('models');
        $this->requireWritePermission('models', []);

        $action = strtolower(trim($action));
        if (!in_array($action, ['enable', 'disable', 'delete'], true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid model batch action', 422);
        }

        $modelIds = $this->normalizeIds($ids);
        if ($modelIds === []) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'ids cannot be empty', 422);
        }

        $placeholders = implode(', ', array_fill(0, count($modelIds), '?'));
        $models = App::db()->select("SELECT * FROM models WHERE id IN ({$placeholders})", $modelIds);
        $foundIds = array_map(static fn (array $row): int => (int) $row['id'], $models);
        $missingIds = array_values(array_diff($modelIds, $foundIds));

        if ($foundIds === []) {
            abort_json('ADMIN_NOT_FOUND', 'No models found', 404);
        }

        if ($action === 'delete') {
            $withHistory = array_values(array_filter($foundIds, fn (int $modelId): bool => $this->hasUsageHistory([$modelId])));
            $retired = $withHistory !== [] ? $this->retireModels($withHistory, 'models', 0) : null;
            $foundIds = array_values(array_diff($foundIds, $withHistory));
            $models = array_values(array_filter($models, static fn (array $model): bool => in_array((int) $model['id'], $foundIds, true)));
            if ($foundIds === []) {
                return ['action' => 'delete', 'requested' => count($modelIds), 'matched' => count($withHistory), 'missing_ids' => $missingIds, 'deleted' => 0, 'retired_model_ids' => $withHistory, 'deleted_related' => []];
            }
            $deleted = App::db()->transaction(function ($db) use ($foundIds, $missingIds, $models): array {
                $counts = [
                    'model_prices' => $this->deleteByModelIds($foundIds, 'model_prices'),
                    'api_key_model_permissions' => $this->deleteByModelIds($foundIds, 'api_key_model_permissions'),
                    'api_key_model_quotas' => $this->deleteByModelIds($foundIds, 'api_key_model_quotas'),
                    'models' => $this->deleteByIds('models', $foundIds),
                ];

                (new AuditService())->write('admin.models.batch', 'models', null, [
                    'action' => 'delete',
                    'model_ids' => $foundIds,
                    'missing_ids' => $missingIds,
                    'models' => array_map(fn (array $model): array => $this->auditDetails('models', $model), $models),
                    'deleted_related' => $counts,
                ]);

                return $counts;
            });

            return [
                'action' => 'delete',
                'requested' => count($modelIds),
                'matched' => count($foundIds),
                'missing_ids' => $missingIds,
                'deleted' => (int) ($deleted['models'] ?? 0),
                'retired_model_ids' => $retired['retired_model_ids'] ?? [],
                'deleted_related' => $deleted,
            ];
        }

        $status = $action === 'enable' ? 'active' : 'disabled';
        $updated = App::db()->execute("UPDATE models SET status = ? WHERE id IN ({$placeholders})", [$status, ...$modelIds]);
        (new AuditService())->write('admin.models.batch', 'models', null, [
            'action' => $action,
            'status' => $status,
            'model_ids' => $foundIds,
            'missing_ids' => $missingIds,
            'updated' => $updated,
        ]);

        return [
            'action' => $action,
            'status' => $status,
            'requested' => count($modelIds),
            'matched' => count($foundIds),
            'missing_ids' => $missingIds,
            'updated' => $updated,
        ];
    }

    public function deleteProvider(int $id): array
    {
        $this->assertTable('providers');
        $this->assertWritableTable('providers');
        $this->requireSuperAdminForGlobalWrite('providers');
        $this->requireWritePermission('providers', []);

        $provider = App::db()->first('SELECT * FROM providers WHERE id = ?', [$id]);
        if (!$provider) {
            abort_json('ADMIN_NOT_FOUND', 'Provider not found', 404);
        }

        $providerModelIds = array_map(static fn (array $row): int => (int) $row['id'], App::db()->select('SELECT id FROM models WHERE provider_id = ?', [$id]));
        if ($this->hasUsageHistory($providerModelIds)) {
            $result = $this->retireModels($providerModelIds, 'providers', $id);
            App::db()->execute('UPDATE providers SET status = "retired"' . (SchemaInspector::hasColumn('providers', 'retired_at') ? ', retired_at = UTC_TIMESTAMP()' : '') . ' WHERE id = ?', [$id]);
            return [...$result, 'provider_retired' => true];
        }

        $deleted = App::db()->transaction(function ($db) use ($id, $provider): array {
            $modelIds = array_map(
                static fn (array $row): int => (int) $row['id'],
                $db->select('SELECT id FROM models WHERE provider_id = ?', [$id])
            );
            $counts = [
                'model_prices' => $this->deleteByModelIds($modelIds, 'model_prices'),
                'api_key_model_permissions' => $this->deleteByModelIds($modelIds, 'api_key_model_permissions'),
                'api_key_model_quotas' => $this->deleteByModelIds($modelIds, 'api_key_model_quotas'),
                'models' => $this->deleteByIds('models', $modelIds),
            ];
            $counts['providers'] = $db->execute('DELETE FROM providers WHERE id = ?', [$id]);

            (new AuditService())->write('admin.delete', 'providers', $id, [
                'provider' => $this->auditDetails('providers', $provider),
                'deleted_model_ids' => $modelIds,
                'deleted_related' => $counts,
            ]);

            return $counts;
        });

        return ['id' => $id, 'deleted' => true, 'deleted_related' => $deleted];
    }

    /**
     * @param list<int> $modelIds
     */
    private function hasUsageHistory(array $modelIds): bool
    {
        if ($modelIds === [] || !SchemaInspector::hasColumn('models', 'retired_at')) {
            return false;
        }
        $placeholders = implode(', ', array_fill(0, count($modelIds), '?'));

        return App::db()->first("SELECT 1 AS present FROM usage_records WHERE model_id IN ({$placeholders}) LIMIT 1", $modelIds) !== null;
    }

    /**
     * Retirement instead of physical deletion once a model has usage history:
     * the row and its price versions stay so historical usage, costs and
     * reconciliation remain explainable; grants and quotas are removed so the
     * model can no longer be called.
     *
     * @param list<int> $modelIds
     * @return array<string, mixed>
     */
    private function retireModels(array $modelIds, string $targetType, int $targetId): array
    {
        $placeholders = implode(', ', array_fill(0, count($modelIds), '?'));
        $counts = App::db()->transaction(function ($db) use ($modelIds, $placeholders): array {
            return [
                'api_key_model_permissions' => $db->execute("DELETE FROM api_key_model_permissions WHERE model_id IN ({$placeholders})", $modelIds),
                'api_key_model_quotas' => $db->execute("DELETE FROM api_key_model_quotas WHERE model_id IN ({$placeholders})", $modelIds),
                'models' => $db->execute("UPDATE models SET status = 'retired', retired_at = UTC_TIMESTAMP() WHERE id IN ({$placeholders})", $modelIds),
            ];
        });
        (new AuditService())->write('admin.delete', $targetType, $targetId, ['mode' => 'retired', 'model_ids' => $modelIds, 'related' => $counts]);

        return ['id' => $targetId, 'deleted' => false, 'retired' => true, 'retired_model_ids' => $modelIds, 'related' => $counts];
    }

    /**
     * Optimistic concurrency: a client that sends expected_updated_at (the
     * value it loaded) gets 409 instead of silently overwriting a concurrent
     * edit.
     *
     * @param array<string, mixed> $data
     */
    private function assertNotStale(string $table, int $id, array $data): void
    {
        if (!isset($data['expected_updated_at']) || (string) $data['expected_updated_at'] === '') {
            return;
        }
        $row = App::db()->first("SELECT updated_at FROM {$table} WHERE id = ?", [$id]);
        if ($row !== null && array_key_exists('updated_at', $row) && (string) $row['updated_at'] !== (string) $data['expected_updated_at']) {
            abort_json('ADMIN_WRITE_CONFLICT', 'The record was changed by someone else; reload and apply your change again', 409);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateConfigWrite(string $table, array $data, ?int $id): void
    {
        if ($table === 'model_prices' && array_key_exists('pricing_rules', $data)) {
            $errors = (new \TokenHub\Services\Pricing\PricingRules())->validate($data['pricing_rules']);
            if ($errors !== []) {
                abort_json('ADMIN_VALIDATION_FAILED', 'pricing_rules: ' . implode('; ', $errors), 422);
            }
            return;
        }
        if (!in_array($table, ['providers', 'models'], true)) {
            return;
        }
        $existing = $id !== null ? App::db()->first("SELECT * FROM {$table} WHERE id = ?", [$id]) : null;
        $validator = new ConfigWriteValidator();
        $validator->abortIfInvalid($table === 'providers' ? $validator->provider($data, $existing) : $validator->model($data, $existing));
    }

    private function normalizeForWrite(string $table, array $data, bool $creating = true, ?int $id = null): array
    {
        if (in_array($table, ['rag_vector_configs', 'rag_knowledge_bases', 'rag_vector_datasets'], true)) {
            try {
                $vectorConfigs = new RagVectorConfigService();
                if ($table === 'rag_vector_configs') {
                    $data = $vectorConfigs->normalizeForWrite($data, $creating, $id);
                } else {
                    $vectorConfigs->assertBindingReferences($table, $data);
                }
            } catch (\RuntimeException $exception) {
                $code = $exception->getMessage();
                if (in_array($code, ['RAG_VECTOR_CONFIG_INVALID', 'RAG_MODEL_NOT_CONFIGURED'], true)) {
                    abort_json($code, 'Invalid RAG vector configuration', 422);
                }
                throw $exception;
            }
        }
        if ($table === 'rag_knowledge_bases' && isset($data['chunking_profile'])) {
            // RAG-022: reject unknown redaction types up front instead of at upload time.
            $profile = is_array($data['chunking_profile']) ? $data['chunking_profile'] : json_decode((string) $data['chunking_profile'], true);
            if (is_array($profile) && array_key_exists('redaction', $profile)) {
                RagRedactionService::normalizeTypes($profile['redaction']);
            }
        }
        if ($table === 'users' && isset($data['password'])) {
            $data['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
            if (!$creating && $id !== null) {
                \TokenHub\Services\Mcp\OAuth\OAuthServer::revokeUserGrantsAfterPasswordChange((int) $id);
            }
        }
        if ($table === 'providers' && array_key_exists('default_headers', $data)) {
            $storedHeaders = null;
            if (!$creating && $id !== null) {
                $storedHeaders = App::db()->first('SELECT default_headers FROM providers WHERE id = ?', [$id])['default_headers'] ?? null;
            }
            $protected = (new ProviderHeaderVault())->protectForStorage(
                is_array($data['default_headers']) || is_string($data['default_headers']) ? $data['default_headers'] : null,
                is_string($storedHeaders) || is_array($storedHeaders) ? $storedHeaders : null
            );
            $data['default_headers'] = $protected === [] ? null : json_encode($protected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($table === 'providers' && isset($data['secret'])) {
            if ((string) $data['secret'] !== '') {
                $data['encrypted_secret'] = \TokenHub\Support\Crypto::encrypt((string) $data['secret']);
            }
            unset($data['secret']);
        }
        if ($table === 'log_retention_policies') {
            $logType = (string) ($data['log_type'] ?? '');
            if (!$creating && $logType === '' && $id !== null) {
                $existing = App::db()->first('SELECT log_type FROM log_retention_policies WHERE id = ?', [$id]);
                $logType = (string) ($existing['log_type'] ?? '');
            }
            $data = (new LogRetentionPolicyService())->normalize($logType, $data);
        }
        if ($table === 'model_prices' && array_key_exists('pricing_rules', $data) && ($data['pricing_rules'] === '' || $data['pricing_rules'] === [])) {
            $data['pricing_rules'] = null;
        }
        if ($table === 'model_prices' && is_string($data['pricing_rules'] ?? null)) {
            $data['pricing_rules'] = json_decode($data['pricing_rules'], true);
        }
        if (in_array($table, ['quota_policies', 'api_key_model_quotas'], true)) {
            foreach (['request_limit', 'input_token_limit', 'output_token_limit', 'total_token_limit', 'audio_second_limit', 'tts_char_limit', 'image_limit', 'video_second_limit', 'cost_limit', 'concurrency_limit'] as $limitField) {
                if (array_key_exists($limitField, $data) && $data[$limitField] === '') {
                    $data[$limitField] = null;
                }
            }
        }
        if ($creating && $table === 'rag_knowledge_bases' && empty($data['kb_id'])) {
            $data['kb_id'] = $this->uniqueExternalId('rag_knowledge_bases', 'kb_id', 'kb');
        }
        if ($creating && $table === 'rag_vector_datasets') {
            if (empty($data['dataset_id'])) {
                $data['dataset_id'] = $this->uniqueExternalId('rag_vector_datasets', 'dataset_id', 'ds');
            }
            if (empty($data['collection_prefix'])) {
                $prefix = trim((string) App::config()->get('rag.qdrant.collection_prefix', 'tokenhub_rag'), '_');
                $data['collection_prefix'] = $prefix . '_' . $data['dataset_id'];
            }
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        if ($creating && $table === 'departments') {
            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            $parent = $parentId ? App::db()->first('SELECT * FROM departments WHERE id = ?', [$parentId]) : null;
            $data['level'] = $parent ? ((int) $parent['level'] + 1) : 0;
            $data['path'] = $parent ? ($parent['path'] . 'pending/') : '/pending/';
        }
        return $data;
    }

    private function uniqueExternalId(string $table, string $column, string $prefix): string
    {
        do {
            $id = $prefix . '_' . bin2hex(random_bytes(12));
        } while (App::db()->first("SELECT id FROM {$table} WHERE {$column} = ?", [$id]));

        return $id;
    }

    /**
     * @param list<int> $modelIds
     */
    private function deleteByModelIds(array $modelIds, string $table): int
    {
        return $this->deleteByColumnIds($table, 'model_id', $modelIds);
    }

    /**
     * @param list<int> $ids
     */
    private function deleteByIds(string $table, array $ids): int
    {
        return $this->deleteByColumnIds($table, 'id', $ids);
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
     * @param list<int> $ids
     */
    private function deleteByColumnIds(string $table, string $column, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $ids = array_values(array_map(static fn (int $id): int => max(0, $id), $ids));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        return App::db()->execute("DELETE FROM {$table} WHERE {$column} IN ({$placeholders})", $ids);
    }

    private function assertTable(string $table): void
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Resource not allowed', 403);
        }
    }

    public function isReadOnlyTable(string $table): bool
    {
        return in_array($table, ['usage_records', 'usage_contents', 'export_tasks', 'audit_logs', 'dashboard_share_access_logs', 'desensitization_events', 'log_archive_files', 'alert_events', 'rag_eval_runs', 'rag_eval_results'], true);
    }

    private function assertWritableTable(string $table): void
    {
        if ($this->isReadOnlyTable($table)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Resource is read-only through generic CRUD', 403);
        }
    }

    private function requireSuperAdminForGlobalWrite(string $table): void
    {
        $capability = match ($table) {
            'providers', 'models', 'model_prices' => 'api_catalog.manage',
            'desensitization_policies' => 'security.manage',
            'log_retention_policies', 'system_settings' => 'system.manage',
            default => null,
        };
        if ($capability === null) {
            return;
        }

        $actor = App::context()->adminUser();
        if (!$actor) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }
        (new PermissionService())->requireCapability($actor, $capability);
    }

    /**
     * @return array<string, string>
     */
    private function filterColumns(string $table): array
    {
        $shared = [
            'department_id' => 'department_id',
            'user_id' => 'user_id',
            'provider_id' => 'provider_id',
            'model_id' => 'model_id',
            'status' => 'status',
        ];

        return match ($table) {
            'users', 'media_assets' => [
                'department_id' => 'department_id',
                'user_id' => match ($table) {
                    'users' => 'id',
                    'media_assets' => 'owner_user_id',
                },
                'status' => 'status',
            ],
            'api_keys' => [
                'api_key_id' => 'id',
                'department_id' => 'department_id',
                'user_id' => 'user_id',
                'status' => 'status',
            ],
            'providers' => [
                'protocol' => 'protocol',
                'status' => 'status',
            ],
            'models' => [
                'provider_id' => 'models.provider_id',
                'status' => 'models.status',
                'capability' => 'models.capability',
                'name' => 'models.name',
            ],
            'model_prices' => [
                'model_id' => 'model_id',
                'currency' => 'currency',
            ],
            'usage_records' => [
                ...$shared,
                'api_key_id' => 'api_key_id',
                'capability' => 'capability',
            ],
            'api_key_model_quotas' => [
                'api_key_id' => 'api_key_id',
                'model_id' => 'model_id',
                'status' => 'status',
            ],
            'multimodal_tasks' => [
                ...$shared,
                'api_key_id' => 'api_key_id',
                'capability' => 'task_type',
            ],
            'usage_contents' => [
                'usage_record_id' => 'usage_record_id',
                'status' => 'retention_mode',
            ],
            'export_tasks' => [
                'user_id' => 'requested_by',
                'status' => 'status',
                'capability' => 'export_type',
            ],
            'desensitization_events' => [
                'status' => 'action',
            ],
            'alert_events' => [
                'status' => 'status',
                'event_type' => 'event_type',
            ],
            'log_archive_files' => [
                'status' => 'log_type',
            ],
            default => array_intersect_key($shared, array_flip($this->tableColumns($table))),
        };
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function listWhere(string $table, array $query): array
    {
        $actor = App::context()->adminUser();
        [$scopeWhere, $bindings] = $actor ? (new PermissionService())->tableScopeSql($actor, $table) : ['', []];
        $conditions = [];
        $scopeWhere = trim($scopeWhere);
        if ($scopeWhere !== '') {
            $conditions[] = preg_replace('/^WHERE\s+/i', '', $scopeWhere) ?: $scopeWhere;
        }

        foreach ($this->filterColumns($table) as $queryKey => $column) {
            if (!array_key_exists($queryKey, $query) || $query[$queryKey] === '' || $query[$queryKey] === null) {
                continue;
            }
            $conditions[] = "{$column} = ?";
            $bindings[] = $query[$queryKey];
        }

        if ($table === 'api_keys' && isset($query['keyword']) && (string) $query['keyword'] !== '') {
            $keyword = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $query['keyword']);
            $pattern = '%' . $keyword . '%';
            $conditions[] = "(api_keys.name LIKE ? ESCAPE '\\\\' OR api_keys.key_prefix LIKE ? ESCAPE '\\\\' OR api_keys.key_suffix LIKE ? ESCAPE '\\\\' OR EXISTS (SELECT 1 FROM users WHERE users.id = api_keys.user_id AND users.username LIKE ? ESCAPE '\\\\'))";
            array_push($bindings, $pattern, $pattern, $pattern, $pattern);
        }

        if ($table === 'models') {
            // The console lists only models the gateway can serve unless the
            // caller asks for support=unsupported|all (ModelSupportPolicy).
            $support = (string) ($query['support'] ?? 'supported');
            if (!in_array($support, ['supported', 'unsupported', 'all'], true)) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'support must be supported, unsupported or all', 422);
            }
            if ($support !== 'all') {
                // Evaluated in PHP (vendor adapters are matched by host and
                // header), then applied as an id filter so paging stays exact.
                $ids = (new ModelSupportPolicy())->modelIds($support === 'supported');
                if ($ids === []) {
                    $conditions[] = '1 = 0';
                } else {
                    $conditions[] = 'models.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
                    array_push($bindings, ...$ids);
                }
            }
        }

        if ($table === 'models' && isset($query['keyword']) && (string) $query['keyword'] !== '') {
            // Partial match on the model name or its upstream name.
            $keyword = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $query['keyword']);
            $pattern = '%' . $keyword . '%';
            $conditions[] = "(models.name LIKE ? ESCAPE '\\\\' OR models.external_name LIKE ? ESCAPE '\\\\')";
            array_push($bindings, $pattern, $pattern);
        }

        if ($table === 'model_prices' && isset($query['effective_at']) && (string) $query['effective_at'] !== '') {
            $conditions[] = 'effective_at <= ?';
            $bindings[] = (string) $query['effective_at'];
        }

        if (isset($query['from']) && (string) $query['from'] !== '' && $this->hasCreatedAt($table)) {
            $conditions[] = $this->listColumn($table, 'created_at') . ' >= ?';
            $bindings[] = (string) $query['from'];
        }
        if (isset($query['to']) && (string) $query['to'] !== '' && $this->hasCreatedAt($table)) {
            $conditions[] = $this->listColumn($table, 'created_at') . ' <= ?';
            $bindings[] = (string) $query['to'];
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function listSelectFrom(string $table): array
    {
        if ($table === 'models') {
            return [
                'models.*, providers.name AS provider_name',
                'models LEFT JOIN providers ON providers.id = models.provider_id',
            ];
        }

        return ['*', $table];
    }

    private function listColumn(string $table, string $column): string
    {
        return $table === 'models' ? "models.{$column}" : $column;
    }

    private function hasCreatedAt(string $table): bool
    {
        return in_array('created_at', $this->tableColumns($table), true);
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

    /**
     * @return array{0:string,1:string}
     */
    private function sortClause(string $table, string $sort): array
    {
        $columns = $this->tableColumns($table);
        $allowed = array_values(array_intersect(['id', 'created_at', 'updated_at', 'effective_at', 'sort_order', 'request_count', 'total_tokens', 'cost_amount'], $columns));
        $fallback = in_array('id', $columns, true) ? 'id' : $allowed[0] ?? $columns[0];
        $direction = str_ends_with($sort, '_asc') ? 'ASC' : 'DESC';
        $column = preg_replace('/_(asc|desc)$/', '', $sort) ?: $fallback;
        if (!in_array($column, $allowed, true)) {
            $column = $fallback;
        }
        return [$column, $direction];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function auditDetails(string $table, array $data): array
    {
        if ($table === 'providers') {
            unset($data['encrypted_secret'], $data['secret']);
            if (isset($data['default_headers'])) {
                $data['default_headers'] = (new ProviderHeaderVault())->maskForDisplay(is_string($data['default_headers']) || is_array($data['default_headers']) ? $data['default_headers'] : null);
            }
        }

        if ($table === 'api_keys') {
            unset($data['key_hash']);
        }

        return $data;
    }

    private function requireWritePermission(string $table, array $data): void
    {
        $actor = App::context()->adminUser();
        if (!$actor) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }

        $permission = new PermissionService();
        $error = $permission->requireAdmin($actor);
        if ($error !== null) {
            abort_json($error['code'], $error['message'], $error['status']);
        }

        if (isset($data['department_id']) && !$permission->canManage($actor, (int) $data['department_id'])) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Department is outside admin scope', 403);
        }
        if ($table === 'api_key_model_quotas' && isset($data['api_key_id']) && !$this->canManageApiKey($actor, (int) $data['api_key_id'])) {
            abort_json('ADMIN_PERMISSION_DENIED', 'API key is outside admin scope', 403);
        }
        (new RagAdminScopeGuard())->assertWrite($actor, $table, $data);
    }

    private function assertUserRoleWriteAllowed(string $table, array $data, ?int $id = null): void
    {
        if ($table !== 'users') {
            return;
        }

        $actor = App::context()->adminUser();
        if (($actor['role'] ?? '') === 'super_admin') {
            return;
        }

        $target = $id !== null ? App::db()->first('SELECT role FROM users WHERE id = ?', [$id]) : null;
        $nextRole = (string) ($data['role'] ?? ($target['role'] ?? 'user'));
        $currentRole = (string) ($target['role'] ?? 'user');
        if (in_array($nextRole, ['admin', 'super_admin'], true) || in_array($currentRole, ['admin', 'super_admin'], true)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Super admin permission required for administrator role changes', 403);
        }
    }

    private function assertExistingScope(string $table, int $id): void
    {
        $actor = App::context()->adminUser();
        if (!$actor || (new PermissionService())->usesGlobalScope($actor)) {
            return;
        }

        $permission = new PermissionService();
        if ($table === 'departments') {
            $row = App::db()->first('SELECT path FROM departments WHERE id = ?', [$id]);
            if (!$row || !$permission->canAccessDepartmentPath($actor, (string) $row['path'])) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Department is outside admin scope', 403);
            }
            return;
        }

        if ($table === 'api_key_model_quotas') {
            $row = App::db()->first(
                'SELECT k.department_id FROM api_key_model_quotas q JOIN api_keys k ON k.id = q.api_key_id WHERE q.id = ?',
                [$id]
            );
            if (!$row || !$permission->canManage($actor, (int) $row['department_id'])) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Resource is outside admin scope', 403);
            }
            return;
        }

        if ((new RagAdminScopeGuard())->handlesTable($table)) {
            (new RagAdminScopeGuard())->assertExisting($actor, $table, $id);
            return;
        }

        if (in_array('department_id', $this->tableColumns($table), true)) {
            $row = App::db()->first("SELECT department_id FROM {$table} WHERE id = ?", [$id]);
            if (!$row || !$permission->canManage($actor, (int) $row['department_id'])) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Resource is outside admin scope', 403);
            }
        }
    }

    public function invalidateUserSessions(int $userId): void
    {
        App::db()->execute('UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ?', [$userId]);
    }

    public function invalidateUserApiKeys(int $userId): void
    {
        (new ApiKeyService())->invalidateByUser($userId);
    }

    private function canManageApiKey(array $actor, int $apiKeyId): bool
    {
        if ((new PermissionService())->usesGlobalScope($actor)) {
            return true;
        }

        $row = App::db()->first('SELECT department_id FROM api_keys WHERE id = ?', [$apiKeyId]);
        return $row !== null && (new PermissionService())->canManage($actor, (int) $row['department_id']);
    }
}
