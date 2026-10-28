<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\App;

$tables = [
    'rag_knowledge_bases',
    'rag_vector_datasets',
    'rag_dataset_bindings',
    'rag_vector_configs',
    'rag_import_templates',
    'rag_uploads',
    'rag_upload_preview_items',
    'rag_documents',
    'rag_chunks',
    'rag_image_assets',
    'rag_embedding_jobs',
    'rag_vector_records',
    'rag_retrieval_logs',
    'rag_answer_feedback',
    'rag_correction_tasks',
    'rag_retention_policies',
    'rag_eval_cases',
    'rag_eval_runs',
    'rag_eval_results',
];

foreach ($tables as $table) {
    $row = App::db()->first('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    assert_true((int) ($row['total'] ?? 0) === 1, $table . ' should exist.');
}

$columns = [
    'rag_uploads' => ['owner_user_id', 'department_id', 'api_key_id', 'device_id', 'file_hash', 'stage', 'progress', 'review_status', 'reviewed_by_user_id', 'reviewed_at', 'review_comment'],
    'rag_upload_preview_items' => ['item_type', 'validation_status', 'validation_errors', 'image_asset_id'],
    'rag_image_assets' => ['thumbnail_path', 'purpose', 'short_description', 'detailed_description', 'ocr_text', 'warnings'],
    'rag_documents' => ['source_preview_item_id'],
    'rag_embedding_jobs' => ['vector_config_id', 'execution_backend', 'dispatched_at', 'lock_token'],
    'rag_vector_records' => ['collection_name', 'vector_id', 'vector_json', 'status', 'deleted_at'],
    'rag_retrieval_logs' => ['retrieval_id', 'dataset_ids', 'sources', 'fallback_used'],
    'rag_answer_feedback' => ['rating', 'selected_source_ids'],
    'rag_correction_tasks' => ['issue_type', 'status', 'priority'],
    'rag_eval_cases' => ['case_id', 'kb_id', 'dataset_id', 'query', 'expected_chunk_ids', 'status', 'source_feedback_id'],
    'rag_eval_runs' => ['run_id', 'case_count', 'recall_at_5', 'recall_at_10', 'recall_at_20', 'mrr', 'failed_cases'],
    'rag_eval_results' => ['run_id', 'case_id', 'hit_rank', 'passed', 'sources'],
];

foreach ($columns as $table => $expectedColumns) {
    foreach ($expectedColumns as $column) {
        $row = App::db()->first('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
        assert_true((int) ($row['total'] ?? 0) === 1, $table . '.' . $column . ' should exist.');
    }
}

$contractMigration = file_get_contents(__DIR__ . '/../../database/migrations/2026_07_10_000011_rag_embedding_job_contract.sql') ?: '';
assert_true(str_contains($contractMigration, 'vector_config_id'), 'RAG embedding job contract migration should persist vector config ownership.');
assert_true(str_contains($contractMigration, 'execution_backend'), 'RAG embedding job contract migration should persist worker ownership.');

$idempotencyMigration = file_get_contents(__DIR__ . '/../../database/migrations/2026_07_10_000012_rag_embedding_job_idempotency.sql') ?: '';
assert_true(str_contains($idempotencyMigration, 'uq_rag_jobs_upload_chunk_type'), 'RAG job migration should enforce upload/chunk/type idempotency.');
assert_true(str_contains($idempotencyMigration, "WHEN 'succeeded' THEN 4"), 'RAG job migration should keep the most valuable duplicate state.');

foreach (['uq_rag_jobs_upload_chunk_type', 'uq_rag_docs_upload_preview'] as $index) {
    $row = App::db()->first('SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ?', [$index]);
    assert_true((int) ($row['total'] ?? 0) >= 1, $index . ' should exist.');
}
