<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require __DIR__ . '/../app/Support/helpers.php';
    spl_autoload_register(function (string $class): void {
        $prefix = 'TokenHub\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

if (!function_exists('admin_js_bundle')) {
    /**
     * Concatenated source of every admin SPA module. Static frontend tests use it
     * so they keep working when code moves between modules.
     */
    function admin_js_bundle(): string
    {
        $root = __DIR__ . '/../public/admin/assets/js';
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $files));
    }
}

if (!function_exists('admin_js_function')) {
    /**
     * Source of one top-level function (from "function name(" to its closing
     * brace at column 0) inside the admin module bundle; '' when absent.
     */
    function admin_js_function(string $source, string $name): string
    {
        $start = strpos($source, "function {$name}(");
        if ($start === false) {
            return '';
        }
        $end = strpos($source, "\n}\n", $start);
        return $end === false ? substr($source, $start) : substr($source, $start, $end - $start + 3);
    }
}

if (!function_exists('assert_true')) {
    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

if (!function_exists('assert_same')) {
    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
}

if (!function_exists('register_rag_test_cleanup')) {
    function register_rag_test_cleanup(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function('cleanup_synthetic_rag_test_data');
    }
}

if (!function_exists('cleanup_synthetic_rag_test_data')) {
    function cleanup_synthetic_rag_test_data(): void
    {
        try {
            cleanup_synthetic_external_gap_test_data();

            $db = \TokenHub\Support\App::db();
            $kbRows = $db->select(
                'SELECT id, kb_id FROM rag_knowledge_bases WHERE kb_id REGEXP ?',
                ['^kb_(sum|met|review|ret|vec|pkg|ai|gap|py|queue|eval|evaladmin|evalfb|hybrid|del|route|e2e)_']
            );
            if ($kbRows === []) {
                rag_test_cleanup_orphan_core_records();
                rag_test_cleanup_orphan_image_assets();
                rag_test_cleanup_orphan_preview_items();
                rag_test_cleanup_orphan_core_records();
                return;
            }

            $kbIds = array_map(static fn (array $row): int => (int) $row['id'], $kbRows);
            cleanup_rag_test_scope($kbIds);
            rag_test_cleanup_orphan_core_records();
            rag_test_cleanup_orphan_image_assets();
            rag_test_cleanup_orphan_preview_items();
            rag_test_cleanup_orphan_core_records();
        } catch (Throwable) {
            // Cleanup must not mask the real test failure.
        }
    }
}

if (!function_exists('cleanup_rag_test_scope')) {
    /**
     * Deletes only records owned by the supplied synthetic knowledge-base and vector-config IDs.
     *
     * @param list<int> $kbIds
     * @param list<int> $vectorConfigIds
     */
    function cleanup_rag_test_scope(array $kbIds, array $vectorConfigIds = []): void
    {
        $kbIds = array_values(array_unique(array_filter($kbIds, static fn (int $id): bool => $id > 0)));
        $vectorConfigIds = array_values(array_unique(array_filter($vectorConfigIds, static fn (int $id): bool => $id > 0)));
        if ($kbIds === []) {
            rag_test_delete_by_column('rag_vector_configs', 'id', $vectorConfigIds);
            return;
        }

        $db = \TokenHub\Support\App::db();
        $kbRows = rag_test_select_ids('SELECT id, kb_id FROM rag_knowledge_bases WHERE id IN (%s)', $kbIds, true);
        $kbExternalIds = array_map(static fn (array $row): string => (string) $row['kb_id'], $kbRows);
        $datasets = rag_test_select_ids('SELECT id, dataset_id FROM rag_vector_datasets WHERE kb_id IN (%s)', $kbIds, true);
        $datasetIds = array_map(static fn (array $row): int => (int) $row['id'], $datasets);
        $datasetExternalIds = array_map(static fn (array $row): string => (string) $row['dataset_id'], $datasets);
        $uploadIds = rag_test_select_scalar_ids('SELECT id FROM rag_uploads WHERE kb_id IN (%s)', $kbIds);
        $documentIds = rag_test_select_scalar_ids('SELECT id FROM rag_documents WHERE kb_id IN (%s)', $kbIds);
        $chunkIds = rag_test_select_scalar_ids('SELECT id FROM rag_chunks WHERE kb_id IN (%s)', $kbIds);
        $imageIds = rag_test_select_scalar_ids('SELECT id FROM rag_image_assets WHERE kb_id IN (%s)', $kbIds);
        $evalCaseIds = rag_test_select_scalar_ids('SELECT id FROM rag_eval_cases WHERE kb_id IN (%s)', $kbIds);
        $evalRunIds = $evalCaseIds === [] ? [] : rag_test_select_scalar_ids('SELECT DISTINCT run_id AS id FROM rag_eval_results WHERE case_id IN (%s)', $evalCaseIds);
        $retrievalIds = rag_test_synthetic_retrieval_ids($kbExternalIds, $datasetExternalIds);
        $feedbackIds = rag_test_select_feedback_ids($retrievalIds);

        rag_test_delete_by_column('rag_eval_results', 'case_id', $evalCaseIds);
        rag_test_delete_by_column('rag_eval_runs', 'id', $evalRunIds);
        rag_test_delete_by_column('rag_eval_cases', 'id', $evalCaseIds);
        rag_test_delete_by_column('rag_correction_tasks', 'feedback_id', $feedbackIds, true);
        rag_test_delete_by_column('rag_correction_tasks', 'retrieval_id', $retrievalIds, true);
        rag_test_delete_by_column('rag_answer_feedback', 'feedback_id', $feedbackIds, true);
        rag_test_delete_by_column('rag_answer_feedback', 'retrieval_id', $retrievalIds, true);
        rag_test_delete_by_column('rag_retrieval_logs', 'retrieval_id', $retrievalIds, true);
        rag_test_delete_by_column('rag_vector_records', 'chunk_id', $chunkIds);
        rag_test_delete_by_column('rag_vector_records', 'image_id', $imageIds);
        rag_test_delete_by_column('rag_vector_records', 'dataset_id', $datasetIds);
        rag_test_delete_by_column('rag_embedding_jobs', 'chunk_id', $chunkIds);
        rag_test_delete_by_column('rag_embedding_jobs', 'document_id', $documentIds);
        rag_test_delete_by_column('rag_embedding_jobs', 'upload_id', $uploadIds);
        rag_test_delete_by_column('rag_embedding_jobs', 'dataset_id', $datasetIds);
        rag_test_delete_by_column('rag_upload_preview_items', 'image_asset_id', $imageIds);
        rag_test_delete_by_column('rag_upload_preview_items', 'upload_id', $uploadIds);
        rag_test_delete_by_column('rag_image_assets', 'id', $imageIds);
        rag_test_delete_by_column('rag_chunks', 'id', $chunkIds);
        rag_test_delete_by_column('rag_documents', 'id', $documentIds);
        rag_test_delete_by_column('rag_uploads', 'id', $uploadIds);
        rag_test_delete_by_column('rag_dataset_bindings', 'dataset_id', $datasetIds);
        rag_test_delete_by_column('rag_vector_datasets', 'id', $datasetIds);
        rag_test_delete_by_column('rag_knowledge_base_bindings', 'kb_id', $kbIds);
        rag_test_delete_by_column('rag_retention_policies', 'kb_id', $kbIds);
        rag_test_delete_by_column('rag_knowledge_bases', 'id', $kbIds);
        rag_test_delete_by_column('rag_vector_configs', 'id', $vectorConfigIds);
    }
}

if (!function_exists('cleanup_synthetic_external_gap_test_data')) {
    function cleanup_synthetic_external_gap_test_data(): void
    {
        $db = \TokenHub\Support\App::db();
        $providerIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $db->select('SELECT id FROM providers WHERE name REGEXP ?', ['^Gap Provider [0-9a-f]{8}$'])
        );
        $modelRows = $db->select('SELECT id FROM models WHERE name REGEXP ?', ['^gap-chat-[0-9a-f]{8}$']);
        $modelIds = array_map(static fn (array $row): int => (int) $row['id'], $modelRows);
        if ($providerIds !== []) {
            foreach (rag_test_select_ids('SELECT id FROM models WHERE provider_id IN (%s)', $providerIds) as $row) {
                $modelIds[] = (int) $row['id'];
            }
        }
        $modelIds = array_values(array_unique($modelIds));

        rag_test_delete_by_column('api_key_model_permissions', 'model_id', $modelIds);
        rag_test_delete_by_column('api_key_model_quotas', 'model_id', $modelIds);
        rag_test_delete_by_column('model_prices', 'model_id', $modelIds);
        rag_test_delete_by_column('models', 'id', $modelIds);
        rag_test_delete_by_column('providers', 'id', $providerIds);

        $apiKeyIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $db->select('SELECT id FROM api_keys WHERE name REGEXP ? AND key_prefix = "gap" AND key_suffix = "test"', ['^Gap Key [0-9a-f]{8}$'])
        );
        $userIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $db->select('SELECT id FROM users WHERE username REGEXP ?', ['^gap_user_[0-9a-f]{8}$'])
        );
        $departmentIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $db->select('SELECT id FROM departments WHERE name REGEXP ? AND path REGEXP ?', ['^Gap Dept [0-9a-f]{8}$', '^/gap-[0-9a-f]{8}/$'])
        );

        rag_test_delete_by_column('media_assets', 'owner_user_id', $userIds);
        rag_test_delete_by_column('api_keys', 'id', $apiKeyIds);
        rag_test_delete_by_column('users', 'id', $userIds);
        rag_test_delete_by_column('departments', 'id', $departmentIds);
    }
}

if (!function_exists('rag_test_cleanup_orphan_core_records')) {
    function rag_test_cleanup_orphan_core_records(): void
    {
        $db = \TokenHub\Support\App::db();
        $db->execute(
            'DELETE v
             FROM rag_vector_records v
             LEFT JOIN rag_knowledge_bases k ON k.id = v.kb_id
             LEFT JOIN rag_vector_datasets d ON d.id = v.dataset_id
             LEFT JOIN rag_chunks c ON c.id = v.chunk_id
             LEFT JOIN rag_image_assets i ON i.id = v.image_id
             WHERE k.id IS NULL OR d.id IS NULL OR (v.chunk_id IS NOT NULL AND c.id IS NULL) OR (v.image_id IS NOT NULL AND i.id IS NULL)'
        );
        $db->execute(
            'DELETE j
             FROM rag_embedding_jobs j
             LEFT JOIN rag_knowledge_bases k ON k.id = j.kb_id
             LEFT JOIN rag_vector_datasets d ON d.id = j.dataset_id
             LEFT JOIN rag_uploads u ON u.id = j.upload_id
             LEFT JOIN rag_documents doc ON doc.id = j.document_id
             LEFT JOIN rag_chunks c ON c.id = j.chunk_id
             WHERE k.id IS NULL
                OR (j.dataset_id IS NOT NULL AND d.id IS NULL)
                OR (j.upload_id IS NOT NULL AND u.id IS NULL)
                OR (j.document_id IS NOT NULL AND doc.id IS NULL)
                OR (j.chunk_id IS NOT NULL AND c.id IS NULL)'
        );
        $db->execute(
            'DELETE c
             FROM rag_chunks c
             LEFT JOIN rag_knowledge_bases k ON k.id = c.kb_id
             LEFT JOIN rag_documents doc ON doc.id = c.document_id
             LEFT JOIN rag_image_assets i ON i.id = c.image_asset_id
             WHERE k.id IS NULL OR doc.id IS NULL OR (c.image_asset_id IS NOT NULL AND i.id IS NULL)'
        );
        $db->execute(
            'DELETE doc
             FROM rag_documents doc
             LEFT JOIN rag_knowledge_bases k ON k.id = doc.kb_id
             LEFT JOIN rag_uploads u ON u.id = doc.upload_id
             WHERE k.id IS NULL OR (doc.upload_id IS NOT NULL AND doc.upload_id <> 0 AND u.id IS NULL)'
        );
        $db->execute(
            'DELETE u
             FROM rag_uploads u
             LEFT JOIN rag_knowledge_bases k ON k.id = u.kb_id
             LEFT JOIN rag_vector_datasets d ON d.id = u.dataset_id
             WHERE k.id IS NULL OR (u.dataset_id IS NOT NULL AND u.dataset_id <> 0 AND d.id IS NULL)'
        );
        $db->execute(
            'DELETE d
             FROM rag_vector_datasets d
             LEFT JOIN rag_knowledge_bases k ON k.id = d.kb_id
             WHERE k.id IS NULL'
        );
    }
}

if (!function_exists('rag_test_cleanup_orphan_image_assets')) {
    function rag_test_cleanup_orphan_image_assets(): void
    {
        \TokenHub\Support\App::db()->execute(
            'DELETE i
             FROM rag_image_assets i
             LEFT JOIN rag_uploads u ON u.id = i.upload_id
             LEFT JOIN rag_knowledge_bases k ON k.id = i.kb_id
             WHERE u.id IS NULL OR k.id IS NULL'
        );
    }
}

if (!function_exists('rag_test_cleanup_orphan_preview_items')) {
    function rag_test_cleanup_orphan_preview_items(): void
    {
        \TokenHub\Support\App::db()->execute(
            'DELETE p
             FROM rag_upload_preview_items p
             LEFT JOIN rag_uploads u ON u.id = p.upload_id
             LEFT JOIN rag_knowledge_bases k ON k.id = p.kb_id
             WHERE u.id IS NULL OR k.id IS NULL'
        );
    }
}

if (!function_exists('rag_test_select_scalar_ids')) {
    /**
     * @param list<int> $ids
     * @return list<int>
     */
    function rag_test_select_scalar_ids(string $sql, array $ids): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], rag_test_select_ids($sql, $ids));
    }
}

if (!function_exists('rag_test_select_ids')) {
    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    function rag_test_select_ids(string $sql, array $ids, bool $rows = false): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        return \TokenHub\Support\App::db()->select(sprintf($sql, $placeholders), $ids);
    }
}

if (!function_exists('rag_test_synthetic_retrieval_ids')) {
    /**
     * @param list<string> $kbExternalIds
     * @param list<string> $datasetExternalIds
     * @return list<string>
     */
    function rag_test_synthetic_retrieval_ids(array $kbExternalIds, array $datasetExternalIds): array
    {
        $ids = [];
        $rows = \TokenHub\Support\App::db()->select('SELECT id, retrieval_id, kb_ids, dataset_ids FROM rag_retrieval_logs');
        foreach ($rows as $row) {
            $kbIds = json_decode((string) ($row['kb_ids'] ?? '[]'), true) ?: [];
            $datasetIds = json_decode((string) ($row['dataset_ids'] ?? '[]'), true) ?: [];
            if (array_intersect($kbExternalIds, array_map('strval', $kbIds)) !== [] || array_intersect($datasetExternalIds, array_map('strval', $datasetIds)) !== []) {
                $ids[] = (string) $row['retrieval_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('rag_test_select_feedback_ids')) {
    /**
     * @param list<string> $retrievalIds
     * @return list<string>
     */
    function rag_test_select_feedback_ids(array $retrievalIds): array
    {
        $feedbackIds = [];
        if ($retrievalIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($retrievalIds), '?'));
            $rows = \TokenHub\Support\App::db()->select("SELECT feedback_id FROM rag_answer_feedback WHERE retrieval_id IN ({$placeholders})", $retrievalIds);
            $feedbackIds = array_map(static fn (array $row): string => (string) $row['feedback_id'], $rows);
        }
        $prefixed = \TokenHub\Support\App::db()->select('SELECT feedback_id FROM rag_answer_feedback WHERE feedback_id REGEXP ?', ['^rfb_(sum|met|review|ret|vec|pkg|ai|gap|py)_']);
        foreach ($prefixed as $row) {
            $feedbackIds[] = (string) $row['feedback_id'];
        }

        return array_values(array_unique($feedbackIds));
    }
}

if (!function_exists('rag_test_delete_by_column')) {
    /**
     * @param list<int|string> $ids
     */
    function rag_test_delete_by_column(string $table, string $column, array $ids, bool $strings = false): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        \TokenHub\Support\App::db()->execute("DELETE FROM {$table} WHERE {$column} IN ({$placeholders})", array_values($ids));
    }
}
