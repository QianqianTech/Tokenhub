<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Support\App;

$script = __DIR__ . '/../../bin/rag-eval-gate.php';
$source = is_file($script) ? file_get_contents($script) : false;

assert_true(is_string($source), 'RAG evaluation gate CLI should exist.');
assert_true(str_contains($source, '--dataset'), 'RAG evaluation gate CLI should accept repeatable --dataset values.');
assert_true(str_contains($source, '--case'), 'RAG evaluation gate CLI should accept repeatable --case values.');
assert_true(str_contains($source, '--min-recall-at-5'), 'RAG evaluation gate CLI should accept a Recall@5 threshold.');
assert_true(str_contains($source, '--min-mrr'), 'RAG evaluation gate CLI should accept an MRR threshold.');
assert_true(str_contains($source, 'JSON_UNESCAPED_UNICODE'), 'RAG evaluation gate CLI should emit readable UTF-8 JSON.');

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_eval_' . $suffix;
$datasetId = 'ds_evalgatecli_' . $suffix;
$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Eval Gate CLI KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_evalgatecli_' . $suffix, 'Eval Gate CLI Config ' . $suffix, 'evalgatecli_text_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$configPk = (int) $db->pdo()->lastInsertId();
register_shutdown_function(static function () use ($kbPk, $configPk): void {
    cleanup_rag_test_scope([$kbPk], [$configPk]);
});
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Eval Gate CLI DS ' . $suffix, 'evalgatecli_' . $suffix, $configPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_evalgatecli_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Eval Gate CLI Doc", "plain_text", "parsed", "indexed")', ['doc_evalgatecli_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();
$chunkId = 'chk_evalgatecli_hit_' . $suffix;
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, "CLI Gate Alpha", "cli gate alpha beta", "{}", ?, 1, "active")', [$chunkId, $kbPk, $documentPk, str_repeat('c', 64)]);
$chunkPk = (int) $db->pdo()->lastInsertId();
$vector = (new RagEmbeddingProviderService())->embed('text', ['text' => 'cli gate alpha beta'], ['dimension' => 3, 'config_json' => json_encode(['allow_local_fallback' => true])]);
$db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_evalgatecli_' . $suffix, 'evalgatecli_text_' . $suffix, $kbPk, $datasetPk, $chunkPk, json_encode($vector), str_repeat('d', 64)]);
$passingCaseId = 'evalgatecli_pass_' . $suffix;
$failingCaseId = 'evalgatecli_fail_' . $suffix;
foreach ([[$passingCaseId, [$chunkId]], [$failingCaseId, ['missing_evalgatecli_' . $suffix]]] as [$caseId, $expected]) {
    $db->execute('INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, query_modality, expected_chunk_ids, status, metadata) VALUES (?, ?, ?, "cli gate alpha beta", "text", ?, "active", "{}")', [$caseId, $kbPk, $datasetPk, json_encode($expected)]);
}

$run = static function (array $arguments): array {
    $command = array_merge(['php', 'bin/rag-eval-gate.php'], $arguments);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/srv/app');
    assert_true(is_resource($process), 'RAG evaluation gate CLI child process must start.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
};

[$passCode, $passOutput, $passError] = $run(['--dataset', $datasetId, '--case', $passingCaseId]);
assert_same(0, $passCode, 'A passing evaluation gate should exit 0.');
assert_same('', trim($passError), 'A passing evaluation gate should not emit stderr output.');
$passPayload = json_decode($passOutput, true);
assert_true(is_array($passPayload), 'A passing evaluation gate should emit one JSON object.');
assert_same(true, $passPayload['quality_gate_passed'] ?? null, 'A passing evaluation should report a passed quality gate.');

[$failCode, $failOutput, $failError] = $run(['--case', $failingCaseId]);
assert_same(2, $failCode, 'A failed evaluation gate should exit 2.');
assert_same('', trim($failError), 'A failed quality gate should not emit stderr output.');
$failPayload = json_decode($failOutput, true);
assert_true(is_array($failPayload), 'A failed evaluation gate should emit one JSON object.');
assert_same(false, $failPayload['quality_gate_passed'] ?? null, 'A failing evaluation should report a failed quality gate.');

[$runtimeCode, $runtimeOutput, $runtimeError] = $run(['--case', $passingCaseId, '--min-mrr', 'invalid']);
assert_same(1, $runtimeCode, 'An invalid CLI threshold should exit 1.');
assert_same('', trim($runtimeError), 'A runtime error should not expose internal details on stderr.');
$runtimePayload = json_decode($runtimeOutput, true);
assert_true(is_array($runtimePayload), 'A runtime error should emit one JSON object.');
assert_same('RAG_EVAL_GATE_RUNTIME_ERROR', $runtimePayload['error'] ?? null, 'A runtime error should use a safe public error code.');

[$outOfRangeCode, $outOfRangeOutput, $outOfRangeError] = $run(['--case', $passingCaseId, '--min-recall-at-5', '101']);
assert_same(1, $outOfRangeCode, 'An out-of-range Recall@5 threshold should exit 1 rather than trivially pass.');
assert_same('', trim($outOfRangeError), 'An out-of-range threshold should not emit stderr output.');
$outOfRangePayload = json_decode($outOfRangeOutput, true);
assert_same('RAG_EVAL_GATE_RUNTIME_ERROR', $outOfRangePayload['error'] ?? null, 'An out-of-range threshold should return the safe runtime error object.');

[$noCasesCode, $noCasesOutput, $noCasesError] = $run(['--case', 'missing_evalgatecli_case']);
assert_same(1, $noCasesCode, 'A CLI evaluation with no active cases must exit 1.');
assert_same('', trim($noCasesError), 'A no-cases CLI error must not emit stderr output.');
$noCasesPayload = json_decode($noCasesOutput, true);
assert_true(is_array($noCasesPayload), 'A no-cases CLI error must emit one JSON object.');
assert_same('RAG_EVAL_GATE_RUNTIME_ERROR', $noCasesPayload['error'] ?? null, 'A no-cases CLI error must use the safe runtime error code.');
assert_true(!str_contains($noCasesOutput, 'PASSWORD'), 'A no-cases CLI error must not expose secrets.');

$runnerCompatibilityPipes = [];
$runnerCompatibilityCode = 'require "tests/bootstrap.php"; $failed = 0; require "tests/Services/RagRetrievalQualityGateTest.php"; $failed++; echo json_encode(["failed" => $failed]);';
$runnerCompatibilityProcess = proc_open(['php', '-r', $runnerCompatibilityCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $runnerCompatibilityPipes, '/srv/app');
assert_true(is_resource($runnerCompatibilityProcess), 'Quality gate runner-compatibility child process must start.');
$runnerCompatibilityOutput = stream_get_contents($runnerCompatibilityPipes[1]);
$runnerCompatibilityError = stream_get_contents($runnerCompatibilityPipes[2]);
foreach ($runnerCompatibilityPipes as $pipe) {
    fclose($pipe);
}
$runnerCompatibilityCode = proc_close($runnerCompatibilityProcess);
assert_same(0, $runnerCompatibilityCode, 'A quality gate include must preserve the runner failure counter type.');
assert_same('', trim($runnerCompatibilityError), 'Runner-compatible quality gate include must not emit errors.');
assert_same(['failed' => 1], json_decode($runnerCompatibilityOutput, true), 'A quality gate include must leave the runner failure counter incrementable.');

cleanup_rag_test_scope([$kbPk], [$configPk]);
assert_same(null, $db->first('SELECT id FROM rag_vector_configs WHERE id = ?', [$configPk]), 'CLI gate test must clean its vector config exactly.');
