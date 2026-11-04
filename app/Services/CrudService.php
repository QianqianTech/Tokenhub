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

    /**
     * Columns (and virtual fields such as users.password / providers.secret)
     * a client may write through the generic admin CRUD endpoints (SEC-101).
     * Derived from the console forms (public/admin/assets/js/resources.js and
     * the page modules) plus the service logic that normalizes them; never
     * from SHOW COLUMNS. Anything else - role-escalation columns, tree
     * bookkeeping (path, level), storage paths, hashes, encrypted secrets,
     * pipeline state - is set only by dedicated, validated code paths.
     * A table missing here (or mapped to []) accepts no fields at all.
     */
    private const WRITABLE_COLUMNS = [
        // path and level are computed by DepartmentService (SEC-103).
        'departments' => ['name', 'parent_id', 'sort_order', 'status'],
        // role is checked by assertUserRoleWriteAllowed(); password is hashed in normalizeForWrite().
        // admin_type/admin_scope/admin_permission_mode/email belong to the admin-users endpoints.
        'users' => ['department_id', 'username', 'password', 'role', 'status', 'managed_department_ids'],
        // secret is encrypted into encrypted_secret; default_headers go through ProviderHeaderVault.
        'providers' => ['name', 'protocol', 'base_url', 'secret', 'timeout_ms', 'retry_count', 'default_headers', 'status'],
        'models' => ['provider_id', 'name', 'external_name', 'capability', 'context_length', 'max_output_tokens', 'route_priority', 'route_weight', 'max_in_flight', 'rpm_limit', 'tpm_limit', 'status', 'capabilities', 'capability_tags'],
        'model_prices' => [
            'model_id', 'input_token_price', 'output_token_price', 'audio_second_price', 'tts_char_price', 'image_price', 'video_second_price',
            'request_price', 'cache_read_token_price', 'cache_write_token_price', 'reasoning_token_price', 'pricing_rules', 'currency', 'effective_at',
        ],
        'api_key_model_quotas' => ['api_key_id', 'model_id', 'period', 'request_limit', 'input_token_limit', 'output_token_limit', 'total_token_limit', 'status'],
        'quota_policies' => [
            'name', 'period', 'request_limit', 'input_token_limit', 'output_token_limit', 'total_token_limit', 'audio_second_limit',
            'tts_char_limit', 'image_limit', 'video_second_limit', 'cost_limit', 'concurrency_limit', 'status',
        ],
        'quota_bindings' => ['quota_policy_id', 'target_type', 'target_id'],
        // Gateway-owned records: the console only lists and disables them.
        'multimodal_tasks' => [],
        'media_assets' => [],
        'desensitization_policies' => ['name', 'scope_type', 'scope_id', 'parsers', 'action', 'status'],
        'log_retention_policies' => [
            'log_type', 'online_retention_days', 'archive_retention_days', 'min_retention_days', 'compress_enabled', 'encrypt_enabled',
            'retention_days', 'archive_days', 'status',
        ],
        'system_settings' => ['setting_key', 'setting_value', 'description'],
        // visibility / acl_mode go through the knowledge-set ACL endpoints; owner_user_id is not client-chosen.
        'rag_knowledge_bases' => [
            'name', 'description', 'department_id', 'status', 'default_text_vector_config_id', 'default_image_vector_config_id',
            'default_multimodal_vector_config_id', 'chunking_profile', 'retrieval_profile',
        ],
        'rag_knowledge_base_bindings' => ['kb_id', 'subject_type', 'subject_id', 'permission', 'is_default', 'include_descendants', 'expires_at'],
        // collection_prefix is derived from dataset_id on create; counters are maintained by the indexer.
        'rag_vector_datasets' => [
            'kb_id', 'name', 'description', 'dataset_type', 'status', 'text_vector_config_id', 'image_vector_config_id',
            'multimodal_vector_config_id', 'retrieval_profile',
        ],
        'rag_dataset_bindings' => ['dataset_id', 'subject_type', 'subject_id', 'permission', 'is_default', 'priority', 'include_descendants', 'expires_at'],
        'rag_vector_configs' => [
            'config_id', 'name', 'modality', 'provider_id', 'model_id', 'model_name', 'dimension', 'distance_metric', 'vector_store',
            'collection_name', 'batch_size', 'max_input_tokens', 'image_max_pixels', 'normalize_vector', 'enabled', 'config_json',
        ],
        // storage_path is written only by RagTemplateService.
        'rag_import_templates' => ['name', 'template_type', 'version', 'required_columns', 'optional_columns', 'style_profile', 'enabled'],
        // Upload rows (storage_path, hashes, workflow state) are owned by the upload pipeline.
        'rag_uploads' => [],
        'rag_upload_preview_items' => ['title', 'content', 'question', 'answer', 'purpose', 'description', 'detailed_description'],
        'rag_documents' => ['title', 'doc_type', 'language', 'metadata', 'effective_at', 'expires_at'],
        'rag_chunks' => ['title', 'content', 'question', 'answer', 'enabled', 'lifecycle_status', 'effective_at', 'expires_at', 'metadata'],
        // storage_path / thumbnail_path are written only by the image pipeline.
        'rag_image_assets' => ['purpose', 'short_description', 'detailed_description', 'ocr_text', 'labels'],
        'rag_embedding_jobs' => [],
        'rag_vector_records' => [],
        'rag_retrieval_logs' => [],
        'rag_answer_feedback' => ['status'],
        'rag_correction_tasks' => ['kb_id', 'dataset_id', 'feedback_id', 'retrieval_id', 'issue_type', 'source_type', 'action', 'status', 'priority', 'reason', 'details'],
        'rag_retention_policies' => ['kb_id', 'dataset_id', 'name', 'expires_after_days', 'inactive_after_days', 'action_on_expiry', 'require_approval', 'enabled'],
        'rag_eval_cases' => ['kb_id', 'dataset_id', 'query', 'query_modality', 'expected_chunk_ids', 'status', 'metadata'],
    ];

    /** External identifiers a client may choose when creating a row, but never change afterwards. */
    private const CREATE_ONLY_COLUMNS = [
        'rag_knowledge_bases' => ['kb_id'],
        'rag_vector_datasets' => ['dataset_id'],
        'rag_import_templates' => ['template_id'],
        'rag_correction_tasks' => ['task_id'],
        'rag_retention_policies' => ['policy_id'],
        'rag_eval_cases' => ['case_id'],
    ];

    /** Request-control keys that are not columns. */
    private const CONTROL_KEYS = ['expected_updated_at'];

    private const USER_ROLES = ['user', 'admin', 'super_admin'];

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
        $this->assertWritableColumns($table, $data, true);
        $this->requireWritePermission($table, $data);
        (new RagAdminScopeGuard())->assertCreateHasOwner(App::context()->adminUser() ?? [], $table, $data);
        $this->assertUserRoleWriteAllowed($table, $data);
        $this->assertScopedReferences($table, $data, null);
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
        $this->assertWritableColumns($table, $data, false);
        $this->requireWritePermission($table, $data);
        $this->assertUserRoleWriteAllowed($table, $data, $id);
        $this->assertScopedReferences($table, $data, $id);
        $this->assertNotStale($table, $id, $data);
        unset($data['expected_updated_at']);
        $this->validateConfigWrite($table, $data, $id);
        $data = $this->normalizeForWrite($table, $data, false, $id);
        unset($data['id']);
        if ($table === 'model_prices' && $data !== []) {
            // BIZ-011: an effective price is history; changes go into a new row.
            \TokenHub\Services\Billing\PriceVersionPolicy::assertEditable(App::db()->first('SELECT * FROM model_prices WHERE id = ?', [$id]) ?: [], $data);
        }
        if ($data === []) {
            // Same redaction as every other CRUD response (SEC-106): no password_hash / encrypted_secret.
            return (new SensitiveDataService())->sanitizeRow($table, App::db()->first("SELECT * FROM {$table} WHERE id = ?", [$id]) ?: ['id' => $id]);
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
                    $vectorConfigs->assertBindingReferences($table, $data, $creating ? null : $id);
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
            // Audit trail by console/APP channel and APP device (APP-009).
            'audit_logs' => array_intersect_key([
                'actor_user_id' => 'actor_user_id',
                'action' => 'action',
                'channel' => 'channel',
                'device_id' => 'device_id',
            ], array_flip($this->tableColumns($table))),
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

        if ($table === 'users') {
            unset($data['password_hash'], $data['password']);
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

    /**
     * SEC-101: every key must be a plain column name and on the table's
     * allowlist. Unknown, sensitive or malformed keys (e.g. "role " with a
     * trailing space, which would otherwise slip past the role check and still
     * be accepted by MySQL as a column name) are rejected with 422.
     *
     * @param array<mixed> $data
     */
    private function assertWritableColumns(string $table, array $data, bool $creating): void
    {
        $allowed = self::WRITABLE_COLUMNS[$table] ?? [];
        if ($creating) {
            $allowed = [...$allowed, ...(self::CREATE_ONLY_COLUMNS[$table] ?? [])];
        }
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || preg_match('/^[a-z_][a-z0-9_]*$/', $key) !== 1) {
                abort_json('ADMIN_VALIDATION_FAILED', 'Invalid field name', 422);
            }
            if (in_array($key, self::CONTROL_KEYS, true) || (!$creating && $key === 'id')) {
                continue;
            }
            if (!in_array($key, $allowed, true)) {
                abort_json('ADMIN_VALIDATION_FAILED', "Field {$key} cannot be written through this endpoint", 422);
            }
        }
    }

    public static function writableColumns(string $table, bool $creating = false): array
    {
        $allowed = self::WRITABLE_COLUMNS[$table] ?? [];
        return $creating ? [...$allowed, ...(self::CREATE_ONLY_COLUMNS[$table] ?? [])] : $allowed;
    }

    /**
     * Reference checks for tables whose scope is not a department_id column
     * (SEC-103 department parents, SEC-104 quota policies and bindings, and
     * the managed departments of a user).
     *
     * @param array<string, mixed> $data
     */
    private function assertScopedReferences(string $table, array $data, ?int $id): void
    {
        $actor = App::context()->adminUser() ?? [];
        $permission = new PermissionService();
        $global = $actor !== [] && $permission->usesGlobalScope($actor);

        if ($table === 'quota_policies' && !$global) {
            // Policies have no department: a change applies to every binding platform-wide.
            abort_json('ADMIN_PERMISSION_DENIED', 'Quota policies can only be changed by a global administrator', 403);
        }

        if ($table === 'quota_bindings') {
            $current = $id !== null ? App::db()->first('SELECT target_type, target_id FROM quota_bindings WHERE id = ?', [$id]) : null;
            $targetType = (string) ($data['target_type'] ?? ($current['target_type'] ?? ''));
            $targetId = (int) ($data['target_id'] ?? ($current['target_id'] ?? 0));
            if (!in_array($targetType, ['api_key', 'user', 'department'], true) || $targetId <= 0) {
                abort_json('ADMIN_VALIDATION_FAILED', 'target_type must be api_key, user or department with a target_id', 422);
            }
            if (!$global && !$permission->canManageQuotaTarget($actor, $targetType, $targetId)) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Quota target is outside admin scope', 403);
            }
        }

        if ($table === 'departments' && array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] === null || $data['parent_id'] === '' ? null : (int) $data['parent_id'];
            $currentParent = $id !== null ? App::db()->first('SELECT parent_id FROM departments WHERE id = ?', [$id]) : null;
            // The edit form always resends parent_id; an unchanged parent is not a move.
            $unchanged = $currentParent !== null && ($currentParent['parent_id'] === null ? null : (int) $currentParent['parent_id']) === $parentId;
            if (!$unchanged && $parentId !== null) {
                $parent = App::db()->first('SELECT id, path FROM departments WHERE id = ?', [$parentId]);
                if (!$parent) {
                    abort_json('ADMIN_NOT_FOUND', 'Parent department not found', 404);
                }
                if ($id !== null) {
                    $self = App::db()->first('SELECT path FROM departments WHERE id = ?', [$id]);
                    if ($self && str_starts_with((string) $parent['path'], (string) $self['path'])) {
                        abort_json('ADMIN_INVALID_DEPARTMENT_MOVE', 'Cannot move department into its descendant', 422);
                    }
                }
            }
            if (!$unchanged && !$global && ($parentId === null || !$permission->canManage($actor, $parentId))) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Parent department is outside admin scope', 403);
            }
        } elseif ($table === 'departments' && $id === null && !$global) {
            // A scoped administrator cannot create a new top-level department.
            abort_json('ADMIN_PERMISSION_DENIED', 'Parent department is outside admin scope', 403);
        }

        if ($table === 'users' && array_key_exists('managed_department_ids', $data) && ($actor['role'] ?? '') !== 'super_admin') {
            $next = $this->departmentIdList($data['managed_department_ids']);
            $currentRow = $id !== null ? App::db()->first('SELECT managed_department_ids FROM users WHERE id = ?', [$id]) : null;
            $current = $this->departmentIdList($currentRow['managed_department_ids'] ?? null, false);
            if ($next !== $current) {
                foreach ($next as $departmentId) {
                    if (!$permission->canManage($actor, $departmentId)) {
                        abort_json('ADMIN_PERMISSION_DENIED', 'Managed department is outside admin scope', 403);
                    }
                }
            }
        }
    }

    /** @return list<int> */
    private function departmentIdList(mixed $value, bool $strict = true): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true);
        }
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            if (!$strict) {
                return [];
            }
            abort_json('ADMIN_VALIDATION_FAILED', 'managed_department_ids must be a JSON array of department ids', 422);
        }
        $ids = [];
        foreach ($value as $item) {
            if (!is_int($item) && !(is_string($item) && ctype_digit($item))) {
                if (!$strict) {
                    continue;
                }
                abort_json('ADMIN_VALIDATION_FAILED', 'managed_department_ids must be a JSON array of department ids', 422);
            }
            $ids[(int) $item] = (int) $item;
        }
        ksort($ids);

        return array_values($ids);
    }

    private function assertUserRoleWriteAllowed(string $table, array $data, ?int $id = null): void
    {
        if ($table !== 'users') {
            return;
        }

        if (array_key_exists('role', $data) && !in_array($data['role'], self::USER_ROLES, true)) {
            abort_json('ADMIN_VALIDATION_FAILED', 'role must be user, admin or super_admin', 422);
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
        if ($table === 'quota_policies') {
            abort_json('ADMIN_PERMISSION_DENIED', 'Quota policies can only be changed by a global administrator', 403);
        }
        if ($table === 'quota_bindings') {
            $row = App::db()->first('SELECT target_type, target_id FROM quota_bindings WHERE id = ?', [$id]);
            if (!$row || !$permission->canManageQuotaTarget($actor, (string) $row['target_type'], (int) $row['target_id'])) {
                abort_json('ADMIN_PERMISSION_DENIED', 'Resource is outside admin scope', 403);
            }
            return;
        }
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
        App::db()->execute('UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE user_id = ?', [$userId]);
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
