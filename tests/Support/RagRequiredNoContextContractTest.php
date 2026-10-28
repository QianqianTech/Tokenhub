<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagRetrievalService;
use TokenHub\Support\App;

$source = file_get_contents(__DIR__ . '/../../app/Services/RagRetrievalService.php');
assert_true($source !== false, 'Retrieval service source must be readable.');
assert_true(str_contains($source, 'if ($required)'), 'Required-context handling must remain explicit.');
assert_true(
    str_contains($source, "abort_json('RAG_REQUIRED_NO_CONTEXT', 'Required RAG context did not meet retrieval thresholds', 424)"),
    'Required retrievals with no accepted source must return the explicit no-context contract.'
);

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$configPk = 0;
register_shutdown_function(static function () use ($db, &$configPk): void {
    if ($configPk > 0) {
        $db->execute('DELETE FROM rag_vector_configs WHERE id = ?', [$configPk]);
    }
});
$fallbackLogIds = [];
register_shutdown_function(static function () use ($db, &$fallbackLogIds): void {
    foreach ($fallbackLogIds as $retrievalId) {
        $db->execute('DELETE FROM rag_retrieval_logs WHERE retrieval_id = ?', [$retrievalId]);
    }
});
$kbId = 'kb_hybrid_' . $suffix;
$datasetId = 'ds_hybrid_' . $suffix;

$db->execute(
    'INSERT INTO rag_knowledge_bases (kb_id, name, status, retrieval_profile) VALUES (?, ?, "active", ?)',
    [$kbId, 'No Context KB ' . $suffix, json_encode(['min_vector_score' => 1.0, 'min_lexical_score' => 1.0, 'min_hybrid_confidence' => 1.0])]
);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)',
    ['cfg_nocontext_' . $suffix, 'No Context Config ' . $suffix, 'nocontext_' . $suffix, json_encode(['allow_local_fallback' => true])]
);
$configPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")',
    [$datasetId, $kbPk, 'No Context Dataset ' . $suffix, 'nocontext_' . $suffix, $configPk]
);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "read")', [$kbPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "read", 1)', [$datasetPk, $apiKey['id']]);
$db->execute(
    'INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")',
    ['upl_nocontext_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]
);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, ?, "plain_text", "parsed", "indexed")',
    ['doc_nocontext_' . $suffix, $kbPk, $uploadPk, 'No Context Document ' . $suffix]
);
$documentPk = (int) $db->pdo()->lastInsertId();
$chunkId = 'chk_nocontext_' . $suffix;
$db->execute(
    'INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, ?, ?, "{}", ?, 1, "active")',
    [$chunkId, $kbPk, $documentPk, 'Unrelated material', 'Warranty registration information only.', hash('sha256', $chunkId)]
);
$chunkPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, "[0,0,0]", ?, "indexed", "indexed", NOW())',
    ['vec_nocontext_' . $suffix, 'nocontext_' . $suffix, $kbPk, $datasetPk, $chunkPk, hash('sha256', 'vec_nocontext_' . $suffix)]
);

$requiredPayload = [
    'messages' => [['role' => 'user', 'content' => 'How do I reset thermal overload E77?']],
    'rag' => ['enabled' => true, 'required' => true, 'dataset_ids' => [$datasetId], 'top_k' => 3],
];
$childCode = 'require "tests/bootstrap.php"; register_shutdown_function(static function (): void { fwrite(STDERR, "STATUS=" . http_response_code()); }); $apiKey = json_decode(base64_decode("' . base64_encode(json_encode($apiKey)) . '"), true); $payload = json_decode(base64_decode("' . base64_encode(json_encode($requiredPayload)) . '"), true); (new TokenHub\\Services\\RagRetrievalService())->retrieve($apiKey, $payload);';
$descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(['php', '-r', $childCode], $descriptorSpec, $pipes, '/srv/app');
assert_true(is_resource($process), 'Required no-context child process must start.');
$requiredBody = stream_get_contents($pipes[1]);
$requiredStatus = stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
proc_close($process);
$requiredResponse = json_decode($requiredBody, true);
assert_same('RAG_REQUIRED_NO_CONTEXT', $requiredResponse['error']['code'] ?? null, 'Required threshold rejection must return the no-context error code.');
assert_same('Required RAG context did not meet retrieval thresholds', $requiredResponse['error']['message'] ?? null, 'Required threshold rejection must return the specified message.');
assert_true(str_contains($requiredStatus, 'STATUS=424'), 'Required threshold rejection must send HTTP 424. stderr=' . $requiredStatus);

$result = (new RagRetrievalService())->retrieve($apiKey, [
    'messages' => [['role' => 'user', 'content' => 'How do I reset thermal overload E77?']],
    'rag' => ['enabled' => true, 'dataset_ids' => [$datasetId], 'top_k' => 3],
]);

assert_same([], $result['sources'], 'Candidates below the retrieval threshold must not be returned.');
assert_same(true, $result['fallback_used'], 'Optional RAG must use fallback when no candidate meets its threshold.');
assert_true(isset($result['retrieval_id']) && is_string($result['retrieval_id']), 'Optional no-context retrieval must be logged.');
$log = $db->first('SELECT hit_count, fallback_used FROM rag_retrieval_logs WHERE retrieval_id = ?', [$result['retrieval_id']]);
assert_same(0, (int) ($log['hit_count'] ?? -1), 'No-context retrieval log must record zero hits.');
assert_same(1, (int) ($log['fallback_used'] ?? -1), 'No-context retrieval log must record fallback usage.');

$topKCases = [
    ['missing', false, null, 8],
    ['invalid string', true, 'bad', 8],
    ['boolean', true, false, 8],
    ['zero', true, 0, 8],
    ['negative', true, -1, 8],
    ['integer', true, 1, 1],
    ['numeric string', true, '12', 12],
    ['upper bound', true, 20, 20],
    ['oversized', true, 21, 20],
    ['oversized integer', true, PHP_INT_MAX, 20],
];
foreach ($topKCases as [$label, $hasTopK, $value, $expectedTopK]) {
    $rag = ['enabled' => true];
    if ($hasTopK) {
        $rag['top_k'] = $value;
    }
    $noDataset = (new RagRetrievalService())->retrieve([], [
        'input' => 'No authorized RAG dataset is available.',
        'rag' => $rag,
    ]);
    assert_same([], $noDataset['sources'], "{$label} top_k fallback must not return sources without a dataset.");
    assert_same(true, $noDataset['fallback_used'], "{$label} top_k fallback must remain explicit.");
    assert_true(isset($noDataset['retrieval_id']) && is_string($noDataset['retrieval_id']), "{$label} top_k fallback must be logged.");
    $fallbackLogIds[] = $noDataset['retrieval_id'];
    $noDatasetLog = $db->first('SELECT top_k, hit_count, fallback_used FROM rag_retrieval_logs WHERE retrieval_id = ?', [$noDataset['retrieval_id']]);
    assert_same($expectedTopK, (int) ($noDatasetLog['top_k'] ?? -1), "{$label} top_k must normalize before fallback logging.");
    assert_same(0, (int) ($noDatasetLog['hit_count'] ?? -1), "{$label} top_k fallback log must record zero hits.");
    assert_same(1, (int) ($noDatasetLog['fallback_used'] ?? -1), "{$label} top_k fallback log must record fallback usage.");
}

$db->execute(
    'UPDATE rag_knowledge_bases SET retrieval_profile = ? WHERE id = ?',
    [json_encode(['min_vector_score' => 0.0, 'min_lexical_score' => 0.0, 'min_hybrid_confidence' => 0.0]), $kbPk]
);
for ($index = 1; $index <= 21; $index++) {
    $boundedChunkId = "chk_bounded_{$suffix}_{$index}";
    $db->execute(
        'INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", ?, ?, ?, "{}", ?, 1, "active")',
        [$boundedChunkId, $kbPk, $documentPk, $index + 1, "Bounded source {$index}", "bounded retrieval evidence unit uniqueitem{$index} verification", hash('sha256', $boundedChunkId)]
    );
}
$boundedResult = (new RagRetrievalService())->retrieve($apiKey, [
    'input' => 'bounded retrieval evidence',
    'rag' => ['enabled' => true, 'dataset_ids' => [$datasetId], 'top_k' => PHP_INT_MAX],
]);
assert_same(false, $boundedResult['fallback_used'], 'A sourced oversized top_k request must not fall back.');
assert_same(20, count($boundedResult['sources']), 'A sourced oversized top_k request must return exactly 20 sources.');
assert_true(count($boundedResult['sources']) <= 20, 'A sourced oversized top_k request must never return more than 20 sources.');
assert_true(isset($boundedResult['retrieval_id']) && is_string($boundedResult['retrieval_id']), 'A sourced oversized top_k request must be logged.');
$fallbackLogIds[] = $boundedResult['retrieval_id'];
$boundedLog = $db->first('SELECT top_k, hit_count, fallback_used FROM rag_retrieval_logs WHERE retrieval_id = ?', [$boundedResult['retrieval_id']]);
assert_same(20, (int) ($boundedLog['top_k'] ?? -1), 'A sourced oversized top_k must normalize before logging.');
assert_same(20, (int) ($boundedLog['hit_count'] ?? -1), 'A sourced oversized top_k log must record the bounded response count.');
assert_same(0, (int) ($boundedLog['fallback_used'] ?? -1), 'A sourced oversized top_k request must log normal retrieval.');
$sourcedPayload = [
    'input' => 'bounded retrieval evidence',
    'rag' => ['enabled' => true, 'dataset_ids' => [$datasetId], 'top_k' => 1],
];
$sourcedRecordFailure = (new RagRetrievalService(null, null, null, static function (): never {
    throw new RuntimeException('controlled successful retrieval-log failure');
}))->retrieve($apiKey, $sourcedPayload);
assert_same(false, $sourcedRecordFailure['fallback_used'], 'A successful optional retrieval must not fall back when only logging fails.');
assert_same(null, $sourcedRecordFailure['retrieval_id'], 'A successful optional retrieval log failure must expose a null retrieval id.');
assert_same(1, count($sourcedRecordFailure['sources']), 'A successful optional retrieval log failure must preserve retrieved sources.');
assert_true(!isset($sourcedRecordFailure['payload']['rag']), 'A successful optional retrieval log failure must preserve the injected payload.');
assert_true(str_contains((string) ($sourcedRecordFailure['payload']['input'] ?? ''), 'Use the following TokenHub knowledge base context'), 'A successful optional retrieval log failure must preserve injected context.');

$requiredSourcedPayload = $sourcedPayload;
$requiredSourcedPayload['rag']['required'] = true;
$childCode = 'require "tests/bootstrap.php"; register_shutdown_function(static function (): void { fwrite(STDERR, "STATUS=" . http_response_code()); }); $apiKey = json_decode(base64_decode("' . base64_encode(json_encode($apiKey)) . '"), true); $payload = json_decode(base64_decode("' . base64_encode(json_encode($requiredSourcedPayload)) . '"), true); $result = (new TokenHub\\Services\\RagRetrievalService(null, null, null, static function (): never { throw new RuntimeException("controlled successful retrieval-log failure"); }))->retrieve($apiKey, $payload); echo json_encode($result);';
$process = proc_open(['php', '-r', $childCode], $descriptorSpec, $pipes, '/srv/app');
assert_true(is_resource($process), 'Required sourced log-failure child process must start.');
$requiredSourcedBody = stream_get_contents($pipes[1]);
$requiredSourcedStatus = stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
proc_close($process);
$requiredSourcedResult = json_decode($requiredSourcedBody, true);
assert_true(!str_contains($requiredSourcedStatus, 'STATUS=502'), 'A successful required retrieval must not become a 502 when only logging fails. stderr=' . $requiredSourcedStatus);
assert_same(false, $requiredSourcedResult['fallback_used'] ?? null, 'A successful required retrieval log failure must not fall back.');
assert_true(array_key_exists('retrieval_id', $requiredSourcedResult), 'A successful required retrieval log failure must include a retrieval id field.');
assert_same(null, $requiredSourcedResult['retrieval_id'], 'A successful required retrieval log failure must expose a null retrieval id.');
assert_same(1, count($requiredSourcedResult['sources'] ?? []), 'A successful required retrieval log failure must preserve retrieved sources.');
$db->execute(
    'UPDATE rag_knowledge_bases SET retrieval_profile = ? WHERE id = ?',
    [json_encode(['min_vector_score' => 1.0, 'min_lexical_score' => 1.0, 'min_hybrid_confidence' => 1.0]), $kbPk]
);

$logWriteFailure = (new RagRetrievalService(null, null, null, static function (): never {
    throw new RuntimeException('controlled retrieval-log failure');
}))->retrieve([], [
    'input' => 'Fallback logging failure must remain identifiable.',
    'rag' => ['enabled' => true, 'top_k' => 8],
]);
assert_same([], $logWriteFailure['sources'], 'A fallback log write failure must still return no RAG sources.');
assert_same(true, $logWriteFailure['fallback_used'], 'A fallback log write failure must still be an explicit fallback.');
assert_same(null, $logWriteFailure['retrieval_id'], 'A fallback log write failure must expose a null retrieval id rather than failing the request.');

$caughtFailure = (new RagRetrievalService())->retrieve($apiKey, [
    'messages' => ['invalid message shape'],
    'rag' => ['enabled' => true, 'dataset_ids' => [$datasetId]],
]);
assert_same([], $caughtFailure['sources'], 'Optional retrieval exceptions must return no RAG sources.');
assert_same(true, $caughtFailure['fallback_used'], 'Optional retrieval exceptions must use fallback.');
assert_true(isset($caughtFailure['retrieval_id']) && is_string($caughtFailure['retrieval_id']), 'Optional retrieval exceptions must be logged with a retrieval id.');
$fallbackLogIds[] = $caughtFailure['retrieval_id'];
$caughtFailureLog = $db->first('SELECT hit_count, fallback_used FROM rag_retrieval_logs WHERE retrieval_id = ?', [$caughtFailure['retrieval_id']]);
assert_same(0, (int) ($caughtFailureLog['hit_count'] ?? -1), 'Caught retrieval failure log must record zero hits.');
assert_same(1, (int) ($caughtFailureLog['fallback_used'] ?? -1), 'Caught retrieval failure log must record fallback usage.');
