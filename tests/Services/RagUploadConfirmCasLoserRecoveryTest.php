<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$lockName = '';
$transactionOpen = false;
$processes = [];
$kbPk = 0;
$configPk = 0;

try {
    $kbId = 'kb_content_chunk_cas_' . $suffix;
    $datasetId = 'ds_content_chunk_cas_' . $suffix;
    $profile = ['target_chars' => 200, 'max_chars' => 240, 'min_tail_chars' => 20, 'overlap_chars' => 24];
    $db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status, chunking_profile) VALUES (?, ?, 1, "active", ?)', [$kbId, 'CAS KB ' . $suffix, json_encode($profile)]);
    $kbPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, "CAS Text Config", "text", "text-embedding", 3, "mysql_fallback", ?, 1)', ['cfg_content_chunk_cas_' . $suffix, 'vec_content_chunk_cas_' . $suffix]);
    $configPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'CAS DS ' . $suffix, 'cas_' . $suffix, $configPk]);
    $datasetPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "write")', [$kbPk, $apiKey['id']]);
    $db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "write", 1)', [$datasetPk, $apiKey['id']]);

    $uploadId = 'upl_content_chunk_cas_' . $suffix;
    $db->execute(
        'INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, owner_user_id, department_id, api_key_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status, confirm_required, review_status) VALUES (?, ?, ?, ?, 1, ?, "api", "text", ?, ?, "ready", "not_started", "waiting_confirm", 1, "not_required")',
        [$uploadId, $kbPk, $datasetPk, $apiKey['user_id'], $apiKey['id'], str_repeat('1', 64), str_repeat('2', 64)]
    );
    $uploadPk = (int) $db->pdo()->lastInsertId();
    $previewItemId = 'pvi_content_chunk_cas_' . $suffix;
    $db->execute(
        'INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors, metadata) VALUES (?, ?, ?, ?, "text", "CAS recovery", ?, "valid", "[]", "{}")',
        [$previewItemId, $uploadPk, $kbPk, $datasetPk, str_repeat('并发确认失败者必须恢复确定性分段。', 40)]
    );

    $lockName = 'rag_chunk_upload_' . $uploadPk;
    assert_same(1, (int) ($db->first('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])['acquired'] ?? 0), 'The CAS test must hold the upload chunk lock before starting confirmers.');
    $db->execute('START TRANSACTION');
    $transactionOpen = true;
    $db->first('SELECT id FROM rag_uploads WHERE id = ? FOR UPDATE', [$uploadPk]);

    $apiKeyPayload = base64_encode(json_encode($apiKey));
    $childCode = 'require "tests/bootstrap.php"; $apiKey = json_decode(base64_decode("' . $apiKeyPayload . '"), true); (new TokenHub\\Services\\RagUploadService())->confirm($apiKey, "' . $uploadId . '");';
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $winner = proc_open(['php', '-r', $childCode], $descriptorSpec, $winnerPipes, '/srv/app');
    assert_true(is_resource($winner), 'The first confirmer process should start.');
    $processes[] = $winner;
    $processList = [];
    $blockedConfirmers = static function () use ($db, &$processList): int {
        $rows = $db->select('SHOW FULL PROCESSLIST');
        $processList = $rows;
        return count(array_filter($rows, static function (array $row): bool {
            return str_contains((string) ($row['Info'] ?? ''), 'UPDATE rag_uploads SET dataset_id');
        }));
    };
    $deadline = microtime(true) + 5;
    while ($blockedConfirmers() < 1 && microtime(true) < $deadline) {
        usleep(20000);
    }
    assert_true($blockedConfirmers() >= 1, 'The first confirmer must block after reading the ready upload and reaching dataset permission resolution. processlist=' . json_encode($processList));
    $loser = proc_open(['php', '-r', $childCode], $descriptorSpec, $loserPipes, '/srv/app');
    assert_true(is_resource($loser), 'The second confirmer process should start while the first CAS update is delayed.');
    $processes[] = $loser;
    $deadline = microtime(true) + 5;
    while ($blockedConfirmers() < 2 && microtime(true) < $deadline) {
        usleep(20000);
    }
    assert_true($blockedConfirmers() >= 2, 'Both confirmers must read the ready upload before the dataset permission barrier is released.');
    $db->execute('COMMIT');
    $transactionOpen = false;

    $deadline = microtime(true) + 5;
    do {
        $previewStatus = $db->first('SELECT preview_status FROM rag_uploads WHERE id = ?', [$uploadPk])['preview_status'] ?? null;
        if ($previewStatus === 'confirmed') {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    assert_same('confirmed', $previewStatus, 'One confirmer must win the compare-and-swap before its chunking attempt is terminated.');

    proc_terminate($winner, 9);
    proc_close($winner);
    array_shift($processes);
    $db->first('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
    $lockName = '';

    $deadline = microtime(true) + 12;
    do {
        $loserStatus = proc_get_status($loser);
        if (!$loserStatus['running']) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    assert_true(!$loserStatus['running'], 'The CAS-loser confirmer should finish after the chunk lock is released.');
    $loserStdout = stream_get_contents($loserPipes[1]);
    $loserStderr = stream_get_contents($loserPipes[2]);
    foreach ($loserPipes as $pipe) {
        fclose($pipe);
    }
    $loserExit = proc_close($loser);
    array_shift($processes);
    assert_true($loserExit === 0 || ($loserStatus['exitcode'] ?? -1) === 0, 'The CAS loser should recover without error. stdout=' . $loserStdout . ' stderr=' . $loserStderr);

    $chunks = $db->select('SELECT c.chunk_id FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ? AND c.lifecycle_status = "active" ORDER BY c.ordinal', [$uploadPk]);
    assert_true(count($chunks) >= 3, 'The CAS loser must rebuild the deterministic chunks after the winner is terminated.');
    assert_same(count($chunks), count(array_unique(array_column($chunks, 'chunk_id'))), 'CAS-loser recovery must not duplicate deterministic chunk identities.');
    assert_same(count($chunks), (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ? AND status = "queued"', [$uploadPk])['c'] ?? 0), 'The CAS loser must create one queued job per recovered chunk.');
} finally {
    foreach ($processes as $process) {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }
    if ($transactionOpen) {
        $db->execute('ROLLBACK');
    }
    if ($lockName !== '') {
        $db->first('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
    }
    cleanup_rag_test_scope([$kbPk], [$configPk]);
}
