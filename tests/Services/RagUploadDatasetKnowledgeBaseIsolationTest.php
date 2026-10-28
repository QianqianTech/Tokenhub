<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagUploadService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$uploadPk = 0;
$kbAPk = 0;
$kbBPk = 0;
$configPk = 0;

register_shutdown_function(static function () use ($db, &$uploadPk, &$kbAPk, &$kbBPk, &$configPk): void {
    try {
        if ($uploadPk <= 0) {
            return;
        }
        $chunkCount = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?', [$uploadPk])['c'] ?? 0);
        $jobCount = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk])['c'] ?? 0);
        assert_same(0, $chunkCount, 'A cross-KB dataset rejection must not create or recover chunks.');
        assert_same(0, $jobCount, 'A cross-KB dataset rejection must not create or recover embedding jobs.');
    } finally {
        cleanup_rag_test_scope([$kbAPk, $kbBPk], [$configPk]);
    }
});

$kbAId = 'kb_content_chunk_isolation_a_' . $suffix;
$kbBId = 'kb_content_chunk_isolation_b_' . $suffix;
$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, 1, "active")', [$kbAId, 'Isolation KB A ' . $suffix]);
$kbAPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, 1, "active")', [$kbBId, 'Isolation KB B ' . $suffix]);
$kbBPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, "Isolation Text Config", "text", "text-embedding", 3, "mysql_fallback", ?, 1)', ['cfg_content_chunk_isolation_' . $suffix, 'vec_content_chunk_isolation_' . $suffix]);
$configPk = (int) $db->pdo()->lastInsertId();
$datasetAId = 'ds_content_chunk_isolation_a_' . $suffix;
$datasetBId = 'ds_content_chunk_isolation_b_' . $suffix;
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetAId, $kbAPk, 'Isolation DS A ' . $suffix, 'isolation_a_' . $suffix, $configPk]);
$datasetAPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetBId, $kbBPk, 'Isolation DS B ' . $suffix, 'isolation_b_' . $suffix, $configPk]);
$datasetBPk = (int) $db->pdo()->lastInsertId();
foreach ([[$kbAPk, 'rag_knowledge_base_bindings', 'kb_id'], [$kbBPk, 'rag_knowledge_base_bindings', 'kb_id']] as [$resourcePk, $table, $column]) {
    $db->execute("INSERT INTO {$table} ({$column}, subject_type, subject_id, permission) VALUES (?, \"api_key\", ?, \"write\")", [$resourcePk, $apiKey['id']]);
}
foreach ([$datasetAPk, $datasetBPk] as $datasetPk) {
    $db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "write")', [$datasetPk, $apiKey['id']]);
}

$uploadId = 'upl_content_chunk_isolation_' . $suffix;
$db->execute(
    'INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, owner_user_id, department_id, api_key_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status, confirm_required, review_status)
     VALUES (?, ?, ?, ?, 1, ?, "api", "text", ?, ?, "ready", "not_started", "waiting_confirm", 1, "not_required")',
    [$uploadId, $kbAPk, $datasetAPk, $apiKey['user_id'], $apiKey['id'], str_repeat('e', 64), str_repeat('f', 64)]
);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors, metadata) VALUES (?, ?, ?, ?, "text", "Isolation", "Must remain in KB A", "valid", "[]", "{}")',
    ['pvi_content_chunk_isolation_' . $suffix, $uploadPk, $kbAPk, $datasetAPk]
);

$payload = base64_encode(json_encode([
    'api_key' => $apiKey,
    'upload_id' => $uploadId,
    'dataset_id' => $datasetBId,
]));
$childCode = 'require "tests/bootstrap.php"; $payload = json_decode(base64_decode("' . $payload . '"), true); (new TokenHub\\Services\\RagUploadService())->confirm($payload["api_key"], $payload["upload_id"], ["dataset_id" => $payload["dataset_id"]]);';
$descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(['php', '-r', $childCode], $descriptorSpec, $pipes, '/srv/app');
assert_true(is_resource($process), 'The cross-KB rejection assertion child process should start.');
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
$exitCode = proc_close($process);
$response = json_decode(trim($stdout), true);
assert_same(0, $exitCode, 'The expected cross-KB API rejection should exit its child process successfully. stderr=' . $stderr);
assert_same('RAG_DATASET_KB_MISMATCH', $response['error']['code'] ?? null, 'Confirming an upload against another knowledge base must return the explicit mismatch error.');
