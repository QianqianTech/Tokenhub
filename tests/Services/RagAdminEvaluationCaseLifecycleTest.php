<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagAdminEvaluationCaseService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_evaladmin_' . $suffix;
$datasetId = 'ds_evaladmin_' . $suffix;
$chunkId = 'chk_evaladmin_' . $suffix;
$admin = ['id' => 1, 'role' => 'super_admin'];

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Admin evaluation KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Admin evaluation dataset ' . $suffix, 'evaladmin_' . $suffix]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_evaladmin_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, ?, "plain_text", "parsed", "indexed")', ['doc_evaladmin_' . $suffix, $kbPk, $uploadPk, 'Admin evaluation document']);
$documentPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", ?, ?, "{}", ?, 1, "active")', [$chunkId, $kbPk, $documentPk, 'Expected content', 'Expected content for evaluation', hash('sha256', $chunkId)]);

$service = new RagAdminEvaluationCaseService();
$created = $service->create($admin, [
    'dataset_id' => $datasetId,
    'query' => 'What is the expected content?',
    'expected_chunk_ids' => [$chunkId],
]);
assert_same('draft', $created['status'], 'New evaluation samples should default to draft.');

$activated = $service->updateStatus($admin, (string) $created['case_id'], 'active');
assert_same('active', $activated['status'], 'An admin should be able to enable a draft sample.');

$imported = $service->import($admin, ['rows' => [[
    'dataset_id' => $datasetId,
    'query' => 'Imported evaluation question',
    'expected_chunk_ids' => [$chunkId],
    'status' => 'active',
]]]);
assert_same(1, $imported['created_count'], 'Bulk import should create valid evaluation rows.');
assert_same(0, $imported['failed_count'], 'Bulk import should not report a failure for a valid row.');

$casePk = (int) $db->first('SELECT id FROM rag_eval_cases WHERE case_id = ?', [(string) $created['case_id']])['id'];
$runId = 'rer_evaladmin_' . $suffix;
$db->execute('INSERT INTO rag_eval_runs (run_id, dataset_ids, top_k_values, case_count, failed_cases, status, metadata) VALUES (?, ?, "[5]", 1, 1, "succeeded", "{}")', [$runId, json_encode([$datasetId])]);
$runPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_eval_results (run_id, case_id, query, expected_chunk_ids, hit_rank, passed, sources) VALUES (?, ?, ?, ?, NULL, 0, "[]")', [$runPk, $casePk, 'What is the expected content?', json_encode([$chunkId])]);

$correction = $service->createCorrectionFromFailure($admin, $runId, (string) $created['case_id']);
assert_same('open', $correction['status'], 'A failed evaluation sample should become an open correction task.');
assert_true($db->first('SELECT id FROM rag_correction_tasks WHERE task_id = ?', [(string) $correction['task_id']]) !== null, 'Correction task should be persisted.');
