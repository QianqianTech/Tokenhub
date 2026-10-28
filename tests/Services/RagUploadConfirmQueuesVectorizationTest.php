<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagUploadService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$kbId = 'kb_queue_' . $suffix;
$datasetId = 'ds_queue_' . $suffix;

$db->execute('DELETE FROM system_settings WHERE setting_key = "rag_upload_review"');
$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, 1, "active")', [$kbId, 'Queue KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, "Queue Text Config", "text", "text-embedding", 3, "mysql_fallback", ?, 1)', ['cfg_queue_text_' . $suffix, 'vec_queue_text_' . $suffix]);
$textConfigPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Queue DS ' . $suffix, 'queue_' . $suffix, $textConfigPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "write")', [$kbPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "write", 1)', [$datasetPk, $apiKey['id']]);

$uploadId = 'upl_queue_' . $suffix;
$db->execute(
    'INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, owner_user_id, department_id, api_key_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status, confirm_required, review_status)
     VALUES (?, ?, ?, ?, 1, ?, "api", "text", ?, ?, "ready", "not_started", "waiting_confirm", 1, "not_required")',
    [$uploadId, $kbPk, $datasetPk, $apiKey['user_id'], $apiKey['id'], str_repeat('a', 64), str_repeat('b', 64)]
);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors, metadata)
     VALUES (?, ?, ?, ?, "text", "Queue test", "Queued vectorization content", "valid", "[]", "{}")',
    ['pvi_queue_' . $suffix, $uploadPk, $kbPk, $datasetPk]
);

$confirmed = (new RagUploadService())->confirm($apiKey, $uploadId);

assert_same('vectorizing', $confirmed['status'], 'Confirmed upload should wait for the vector worker.');
assert_same('queued', $confirmed['vectorize_status'], 'Confirmed upload should expose queued vectorization status.');
assert_same(0, $confirmed['summary']['processed_items'], 'Queued vectorization should not report processed items before a worker runs.');

$jobs = $db->select('SELECT job_type, status, progress_percent, vector_config_id, execution_backend FROM rag_embedding_jobs WHERE upload_id = ? ORDER BY id', [$uploadPk]);
assert_same(1, count($jobs), 'Confirming one text preview item should create one embedding job.');
assert_same('text_embedding', $jobs[0]['job_type'], 'Text preview items should queue a text embedding job.');
assert_same('queued', $jobs[0]['status'], 'Embedding jobs should be queued for the RAG worker.');
assert_same($textConfigPk, (int) $jobs[0]['vector_config_id'], 'Embedding jobs should persist their selected vector config.');
assert_same('python_local', $jobs[0]['execution_backend'], 'Unconfigured embedding models should remain explicit Python-local jobs.');
assert_same(0.0, (float) $jobs[0]['progress_percent'], 'Queued embedding jobs should start at zero progress.');

$vectorCount = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_vector_records WHERE dataset_id = ?', [$datasetPk])['c'] ?? 0);
assert_same(0, $vectorCount, 'Confirming an upload should not synchronously write vector records.');

$documentCount = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_documents WHERE upload_id = ?', [$uploadPk])['c'] ?? 0);
$chunkCount = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?', [$uploadPk])['c'] ?? 0);
$jobCount = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk])['c'] ?? 0);
(new RagUploadService())->confirm($apiKey, $uploadId);
assert_same($documentCount, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_documents WHERE upload_id = ?', [$uploadPk])['c'] ?? 0), 'Repeated confirmation must not duplicate documents.');
assert_same($chunkCount, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?', [$uploadPk])['c'] ?? 0), 'Repeated confirmation must not duplicate chunks.');
assert_same($jobCount, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk])['c'] ?? 0), 'Repeated confirmation must not duplicate embedding jobs.');

$db->execute('DELETE FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk]);
$db->execute('DELETE c FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?', [$uploadPk]);
$db->execute('DELETE FROM rag_documents WHERE upload_id = ?', [$uploadPk]);
(new RagUploadService())->confirm($apiKey, $uploadId);
assert_same(1, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_documents WHERE upload_id = ?', [$uploadPk])['c'] ?? 0), 'Retrying a confirmed upload must rebuild documents after a mid-confirm crash.');
assert_same(1, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?', [$uploadPk])['c'] ?? 0), 'Retrying a confirmed upload must rebuild chunks after a mid-confirm crash.');
assert_same(1, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk])['c'] ?? 0), 'Retrying a confirmed upload must rebuild durable jobs after a mid-confirm crash.');
