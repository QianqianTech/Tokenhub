<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagRetrievalEvaluationService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_eval_' . $suffix;
$datasetId = 'ds_evalgate_' . $suffix;

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Eval Gate KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)', ['cfg_evalgate_' . $suffix, 'Eval Gate Config ' . $suffix, 'evalgate_text_' . $suffix, json_encode(['allow_local_fallback' => true])]);
$configPk = (int) $db->pdo()->lastInsertId();
register_shutdown_function(static function () use ($kbPk, $configPk): void {
    cleanup_rag_test_scope([$kbPk], [$configPk]);
});
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Eval Gate DS ' . $suffix, 'evalgate_' . $suffix, $configPk]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_evalgate_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Eval Gate Doc", "plain_text", "parsed", "indexed")', ['doc_evalgate_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();
$chunkId = 'chk_evalgate_hit_' . $suffix;
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, "Gate Alpha", "gate alpha beta", "{}", ?, 1, "active")', [$chunkId, $kbPk, $documentPk, str_repeat('c', 64)]);
$chunkPk = (int) $db->pdo()->lastInsertId();
$vector = (new RagEmbeddingProviderService())->embed('text', ['text' => 'gate alpha beta'], ['dimension' => 3, 'config_json' => json_encode(['allow_local_fallback' => true])]);
$db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_evalgate_' . $suffix, 'evalgate_text_' . $suffix, $kbPk, $datasetPk, $chunkPk, json_encode($vector), str_repeat('d', 64)]);

$passingCaseId = 'evalgate_pass_' . $suffix;
$failingCaseId = 'evalgate_fail_' . $suffix;
foreach ([[$passingCaseId, [$chunkId]], [$failingCaseId, ['missing_evalgate_' . $suffix]]] as [$caseId, $expected]) {
    $db->execute('INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, query_modality, expected_chunk_ids, status, metadata) VALUES (?, ?, ?, "gate alpha beta", "text", ?, "active", "{}")', [$caseId, $kbPk, $datasetPk, json_encode($expected)]);
}
register_shutdown_function(static function () use ($passingCaseId): void {
    App::db()->execute('DELETE FROM rag_eval_runs WHERE status = "failed" AND JSON_SEARCH(metadata, "one", ?) IS NOT NULL', [$passingCaseId]);
});

$service = new RagRetrievalEvaluationService();
$passed = $service->run(['case_ids' => [$passingCaseId], 'top_k_values' => [5]]);
assert_same(true, $passed['quality_gate_passed'], 'A seeded hit should pass the default retrieval quality gate.');
assert_same(['recall_at_5' => 95.0, 'mrr' => 0.8], $passed['quality_gate_thresholds'], 'Default quality gate thresholds should be returned.');
assert_same([], $passed['quality_gate_violations'], 'A passing run should have no gate violations.');

$topOneOnly = $service->run(['case_ids' => [$passingCaseId], 'top_k_values' => [1]]);
assert_same(null, $topOneOnly['recall_at_5'], 'Recall@5 must be unavailable when the caller only requested top_k=1.');
assert_same(100.0, (float) ($topOneOnly['gate_evaluation_metrics']['recall_at_5'] ?? 0), 'A perfect top-1 result must still compute Recall@5 internally for the quality gate.');
assert_same([5], $topOneOnly['gate_evaluation_k'], 'The gate-added evaluation depth must be reported separately from requested top-K values.');
assert_same(true, $topOneOnly['quality_gate_passed'], 'A perfect result restricted to top_k=1 must not fail the default Recall@5 gate.');

$rankFiveService = new RagRetrievalEvaluationService(static function (array $case, int $topK) use ($chunkId): array {
    return [
        ['chunk_id' => 'noise_1'],
        ['chunk_id' => 'noise_2'],
        ['chunk_id' => 'noise_3'],
        ['chunk_id' => 'noise_4'],
        ['chunk_id' => $chunkId],
    ];
});
$rankFive = $rankFiveService->run(['case_ids' => [$passingCaseId], 'top_k_values' => [1]]);
assert_same(0.0, (float) $rankFive['mrr'], 'Requested MRR must only consider the requested top-K depth.');
assert_same(1, $rankFive['failed_cases'], 'Requested failed_cases must only consider the requested top-K depth.');
assert_same(0.2, (float) ($rankFive['gate_evaluation_metrics']['mrr'] ?? 0), 'Gate MRR must retain the hit found at its added depth.');
assert_same(0, $rankFive['gate_evaluation_metrics']['failed_cases'] ?? null, 'Gate failures must be reported separately from requested failures.');

$normalizedTopK = $service->run(['case_ids' => [$passingCaseId], 'top_k_values' => [100]]);
assert_same([20], $normalizedTopK['top_k_values'] ?? null, 'Requested top-K values must be bounded to the retrieval maximum.');
assert_same(100.0, (float) $normalizedTopK['recall_at_20'], 'The bounded top-K value must be reflected in returned request metrics.');
$normalizedTopKRow = $db->first('SELECT top_k_values FROM rag_eval_runs WHERE run_id = ?', [$normalizedTopK['run_id']]);
assert_same([20], json_decode((string) ($normalizedTopKRow['top_k_values'] ?? '[]'), true), 'The persisted top-K values must use the bounded request value.');

$topOneMetadata = $db->first('SELECT top_k_values, metadata FROM rag_eval_runs WHERE run_id = ?', [$topOneOnly['run_id']]);
assert_same([1], json_decode((string) ($topOneMetadata['top_k_values'] ?? '[]'), true), 'Persisted top-K values must retain the caller request.');
$topOneRunMetadata = json_decode((string) ($topOneMetadata['metadata'] ?? '{}'), true);
assert_same([5], $topOneRunMetadata['gate_evaluation_k'] ?? null, 'Persisted metadata must identify the gate-added K value.');
assert_true(array_key_exists('recall_at_5', $topOneRunMetadata['metrics'] ?? []), 'Persisted request metrics must include Recall@5 availability.');
assert_same(null, $topOneRunMetadata['metrics']['recall_at_5'], 'Persisted request metrics must mark an unrequested Recall@5 as unavailable.');

$threwInvalidThreshold = false;
try {
    $service->qualityGate(['recall_at_5' => 100.0, 'mrr' => 1.0], ['min_recall_at_5' => -1]);
} catch (InvalidArgumentException) {
    $threwInvalidThreshold = true;
}
assert_same(true, $threwInvalidThreshold, 'Out-of-range quality thresholds must be rejected instead of allowing a trivial pass.');

$failedRunService = new RagRetrievalEvaluationService(static function (array $case, int $topK): array {
    throw new RuntimeException('DB_PASSWORD=must-not-persist');
});
$threwRetrievalFailure = false;
try {
    $failedRunService->run(['case_ids' => [$passingCaseId], 'top_k_values' => [5]]);
} catch (RuntimeException) {
    $threwRetrievalFailure = true;
}
assert_same(true, $threwRetrievalFailure, 'An injected retrieval failure must escape to the caller.');
$failedRun = $db->first('SELECT id, run_id, status, metadata FROM rag_eval_runs WHERE status = "failed" AND JSON_SEARCH(metadata, "one", ?) IS NOT NULL ORDER BY id DESC LIMIT 1', [$passingCaseId]);
assert_same('failed', $failedRun['status'] ?? null, 'A retrieval failure after run creation must finalize the run as failed.');
$failedRunMetadata = json_decode((string) ($failedRun['metadata'] ?? '{}'), true);
assert_same('RAG_EVAL_RUN_FAILED', $failedRunMetadata['error_code'] ?? null, 'A failed run must persist a safe error code.');
assert_true(!str_contains(json_encode($failedRunMetadata), 'DB_PASSWORD'), 'A failed run must not persist exception secrets.');
$failedRunSummary = $service->summary(1, (string) $failedRun['run_id']);
assert_true(is_array($failedRunSummary['latest_run'] ?? null), 'A failed run must remain addressable in its summary. run_id=' . (string) $failedRun['run_id'] . ' summary=' . json_encode($failedRunSummary));
foreach (['recall_at_1', 'recall_at_5', 'mrr', 'failed_cases'] as $metric) {
    assert_true(array_key_exists($metric, $failedRunSummary['latest_run']), 'A failed run summary must expose ' . $metric . '.');
    assert_same(null, $failedRunSummary['latest_run'][$metric], 'A failed run summary must mark ' . $metric . ' unavailable.');
}
$db->execute('DELETE FROM rag_eval_runs WHERE id = ?', [$failedRun['id']]);

$persistedPass = $db->first('SELECT metadata FROM rag_eval_runs WHERE run_id = ?', [$passed['run_id']]);
$passMetadata = json_decode((string) ($persistedPass['metadata'] ?? '{}'), true);
assert_same(true, $passMetadata['quality_gate_passed'] ?? null, 'A passing gate result should be persisted in run metadata.');
assert_same(95.0, (float) ($passMetadata['quality_gate_thresholds']['recall_at_5'] ?? 0), 'Persisted Recall@5 threshold should retain its default value.');
assert_same(0.8, (float) ($passMetadata['quality_gate_thresholds']['mrr'] ?? 0), 'Persisted MRR threshold should retain its default value.');

$failedGateRun = $service->run(['case_ids' => [$failingCaseId], 'top_k_values' => [5], 'min_recall_at_5' => 100, 'min_mrr' => 1]);
assert_same(false, $failedGateRun['quality_gate_passed'], 'A seeded miss should fail the retrieval quality gate.');
assert_same(['recall_at_5' => 100.0, 'mrr' => 1.0], $failedGateRun['quality_gate_thresholds'], 'Custom gate thresholds should be returned.');
assert_same([
    ['metric' => 'recall_at_5', 'actual' => 0.0, 'required' => 100.0],
    ['metric' => 'mrr', 'actual' => 0.0, 'required' => 1.0],
], $failedGateRun['quality_gate_violations'], 'A failed gate should report metric, actual, and required values.');

$persistedFail = $db->first('SELECT metadata FROM rag_eval_runs WHERE run_id = ?', [$failedGateRun['run_id']]);
$failMetadata = json_decode((string) ($persistedFail['metadata'] ?? '{}'), true);
assert_same(false, $failMetadata['quality_gate_passed'] ?? null, 'A failed gate result should be persisted in run metadata.');
assert_same('recall_at_5', $failMetadata['quality_gate_violations'][0]['metric'] ?? null, 'Persisted violations should retain the failed metric.');
assert_same(0.0, (float) ($failMetadata['quality_gate_violations'][0]['actual'] ?? -1), 'Persisted violations should retain the actual metric value.');
assert_same(100.0, (float) ($failMetadata['quality_gate_violations'][0]['required'] ?? 0), 'Persisted violations should retain the required metric value.');

$noCasesCode = 'require "tests/bootstrap.php"; (new TokenHub\\Services\\RagRetrievalEvaluationService())->run(["case_ids" => ["missing_evalgate_case"]]);';
$noCasesPipes = [];
$noCasesProcess = proc_open(['php', '-r', $noCasesCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $noCasesPipes, '/srv/app');
assert_true(is_resource($noCasesProcess), 'No-cases evaluation child process must start.');
$noCasesOutput = stream_get_contents($noCasesPipes[1]);
foreach ($noCasesPipes as $pipe) {
    fclose($pipe);
}
proc_close($noCasesProcess);
assert_true(str_contains($noCasesOutput, 'RAG_EVAL_NO_CASES'), 'No active evaluation cases must not produce a passing quality gate.');

cleanup_rag_test_scope([$kbPk], [$configPk]);
assert_same(null, $db->first('SELECT id FROM rag_vector_configs WHERE id = ?', [$configPk]), 'Quality gate test must clean its vector config exactly.');
