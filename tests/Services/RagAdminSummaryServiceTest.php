<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagAdminSummaryService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', ['kb_sum_' . $suffix, 'Summary KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status, chunk_count, vector_count, last_indexed_at) VALUES (?, ?, ?, ?, "active", 1, 1, NOW())', ['ds_sum_' . $suffix, $kbPk, 'Summary DS ' . $suffix, 'sum_' . $suffix]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status, review_status, total_items, text_items, processed_items, progress_percent, progress, current_stage, stage)
     VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed", "approved", 1, 1, 1, 100, 100, "indexed", "indexed")',
    ['upl_sum_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]
);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors)
     VALUES (?, ?, ?, ?, "text", "Summary preview", "summary body", "valid", "[]")',
    ['pvi_sum_' . $suffix, $uploadPk, $kbPk, $datasetPk]
);
$db->execute(
    'INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Summary document", "plain_text", "parsed", "indexed")',
    ['doc_sum_' . $suffix, $kbPk, $uploadPk]
);
$documentPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, title, content, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", "Summary chunk", "chunk body", ?, 1, "active")',
    ['chk_sum_' . $suffix, $kbPk, $documentPk, str_repeat('c', 64)]
);
$chunkPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_embedding_jobs (job_id, kb_id, dataset_id, upload_id, document_id, chunk_id, job_type, status, total_items, processed_items, progress_percent) VALUES (?, ?, ?, ?, ?, ?, "text_embedding", "succeeded", 1, 1, 100)',
    ['job_sum_' . $suffix, $kbPk, $datasetPk, $uploadPk, $documentPk, $chunkPk]
);
$db->execute(
    'INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, "[1,0,0]", ?, "indexed", "indexed", NOW())',
    ['vec_sum_' . $suffix, 'sum_' . $suffix . '_text', $kbPk, $datasetPk, $chunkPk, str_repeat('c', 64)]
);
$db->execute(
    'INSERT INTO rag_retrieval_logs (retrieval_id, request_id, api_key_id, kb_ids, dataset_ids, query, query_modality, top_k, hit_count, max_score, sources, latency_ms, fallback_used) VALUES (?, ?, 1, ?, ?, "summary query", "text", 8, 1, 0.9, "[]", 12, 0)',
    ['rtr_sum_' . $suffix, 'req_sum_' . $suffix, json_encode(['kb_sum_' . $suffix]), json_encode(['ds_sum_' . $suffix])]
);
$db->execute(
    'INSERT INTO rag_answer_feedback (feedback_id, retrieval_id, request_id, api_key_id, rating, status, selected_source_ids) VALUES (?, ?, ?, 1, "bad", "open", "[]")',
    ['rfb_sum_' . $suffix, 'rtr_sum_' . $suffix, 'req_sum_' . $suffix]
);
$db->execute(
    'INSERT INTO rag_correction_tasks (correction_id, task_id, feedback_id, retrieval_id, issue_type, priority, status, details) VALUES (?, ?, ?, ?, "bad", 60, "open", "{}")',
    ['rct_sum_' . $suffix, 'rct_sum_' . $suffix, 'rfb_sum_' . $suffix, 'rtr_sum_' . $suffix]
);

$summary = (new RagAdminSummaryService())->summary(24);

assert_same('database', $summary['source'], 'RAG admin summary should identify database as the data source.');
assert_true(($summary['totals']['knowledge_bases'] ?? 0) >= 1, 'Summary should count knowledge bases from the database.');
assert_true(($summary['totals']['indexed_vectors'] ?? 0) >= 1, 'Summary should count indexed vectors from the database.');
assert_true(isset($summary['status']['uploads']['indexed']), 'Summary should bucket upload status values.');
assert_true(isset($summary['status']['jobs']['succeeded']), 'Summary should bucket recent job status values.');
assert_true(($summary['retrieval']['total'] ?? 0) >= 1, 'Summary should count recent retrieval logs.');

$latestUploads = array_map(static fn (array $row): string => (string) ($row['upload_id'] ?? ''), $summary['recent']['uploads'] ?? []);
assert_true(in_array('upl_sum_' . $suffix, $latestUploads, true), 'Summary should include recent uploads from rag_uploads.');

$pipelineSteps = array_map(static fn (array $row): string => (string) ($row['step'] ?? ''), $summary['pipeline'] ?? []);
foreach (['uploads', 'preview', 'documents', 'chunks', 'vectors'] as $step) {
    assert_true(in_array($step, $pipelineSteps, true), 'Summary pipeline should include ' . $step . '.');
}
