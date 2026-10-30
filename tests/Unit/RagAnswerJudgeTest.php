<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * RAG-041: low-confidence rate and answer-level (judge model) evaluation.
 * The answer model cites sources with [n]; the judge scores faithfulness,
 * relevance and citation accuracy; citation_hit is computed locally against
 * the expected chunks. Provider failures are recorded per case and never
 * fail the run; a missing judge model is refused before the run starts.
 */

use TokenHub\Services\RagAnswerJudgeService;
use TokenHub\Services\RagRetrievalEvaluationService;

$pdo = rag_sqlite_fixture();
$pdo->exec("INSERT INTO providers (id, name, protocol, base_url, status) VALUES (1, 'p', 'openai', 'https://example.invalid/v1', 'active')");
$pdo->exec("INSERT INTO models (id, provider_id, name, external_name, capability, status) VALUES (7, 1, 'judge', 'judge-x', 'text_generation', 'active'), (8, 1, 'emb', 'emb', 'embedding', 'active')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id) VALUES (1, 'kb_1', 'KB', 1)");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name) VALUES (1, 'ds_1', 1, 'DS')");
$pdo->exec("INSERT INTO rag_eval_cases (id, case_id, kb_id, dataset_id, query, expected_chunk_ids, status, metadata) VALUES
  (1, 'case_1', 1, 1, '温度过高怎么办', '[\"chk_temp\"]', 'active', '{\"expected_answer\": \"停机检查冷却单元\"}'),
  (2, 'case_2', 1, 1, '保修多久', '[\"chk_warranty\"]', 'active', '{}'),
  (3, 'case_3', 1, 1, '没有资料的问题', '[\"chk_none\"]', 'active', '{}')");

$ranker = static fn (array $case, int $topK): array => match ($case['query']) {
    '温度过高怎么办' => [
        ['chunk_id' => 'chk_other', 'title' => '概述', 'content' => '本设备用于工业冷却。', 'confidence_score' => 0.8],
        ['chunk_id' => 'chk_temp', 'title' => '温度告警', 'content' => '温度超过 80 度必须停机检查冷却单元。', 'confidence_score' => 0.9],
    ],
    '保修多久' => [['chunk_id' => 'chk_warranty', 'title' => '保修', 'content' => '整机保修两年。', 'confidence_score' => 0.2]],
    default => [],
};

$calls = [];
$requester = static function (array $route, string $path, array $payload) use (&$calls): array {
    $calls[] = ['path' => $path, 'model' => $payload['model'], 'messages' => $payload['messages']];
    $system = (string) $payload['messages'][0]['content'];
    $user = (string) $payload['messages'][1]['content'];
    if (str_contains($user, '保修多久') && !str_contains($system, '评审')) {
        return ['ok' => false, 'error_code' => 'PROVIDER_TIMEOUT'];
    }
    if (str_contains($system, '评审')) {
        assert_true(str_contains($user, '参考答案：') === str_contains($user, '温度过高怎么办'), 'The reference answer is passed to the judge only when the case has one.');
        return ['ok' => true, 'json' => ['choices' => [['message' => ['content' => "评分如下：\n{\"faithfulness\": 0.9, \"answer_relevance\": 1.2, \"citation_accuracy\": 1, \"reason\": \"有依据\"}"]]]]];
    }
    assert_true(str_contains($user, '<资料>') && str_contains($user, '[2] 温度告警'), 'Sources are numbered for citation.');
    return ['ok' => true, 'json' => ['choices' => [['message' => ['content' => '温度超过 80 度时需要停机检查冷却单元 [2]。另见 [9]。']]]]];
};

$service = new RagRetrievalEvaluationService($ranker, new RagAnswerJudgeService($requester));

// Without the judge: only retrieval metrics, plus the low-confidence rate.
$plain = $service->run(['dataset_ids' => ['ds_1'], 'top_k_values' => [5]]);
assert_same([], $calls, 'No model is called unless the answer-level evaluation is requested.');
assert_same(33.33, (float) $plain['low_confidence_rate'], 'One of three cases has only low-confidence sources (0.2 < 0.35).');
assert_true(!isset($plain['answer_evaluation']), 'No answer metrics without the judge.');

rag_expect_abort(static fn () => $service->run(['dataset_ids' => ['ds_1'], 'judge' => true]), 'RAG_JUDGE_NOT_CONFIGURED', 'Requesting the judge without a model is refused.');
rag_expect_abort(static fn () => $service->run(['dataset_ids' => ['ds_1'], 'judge_model_id' => 8]), 'RAG_JUDGE_NOT_CONFIGURED', 'An embedding model cannot be the judge.');
$runsBefore = (int) $pdo->query('SELECT COUNT(*) FROM rag_eval_runs')->fetchColumn();
assert_same(1, $runsBefore, 'Refused judge runs never create a run row.');

$run = $service->run(['dataset_ids' => ['ds_1'], 'top_k_values' => [5], 'judge_model_id' => 7]);
$answer = $run['answer_evaluation'];
assert_same(1, $answer['judged_cases'], 'Only the case with a successful answer is judged.');
assert_same(1, $answer['judge_failures'], 'The provider failure is recorded, not thrown.');
assert_same(0.9, $answer['answer_faithfulness'], 'Faithfulness comes from the judge.');
assert_same(1.0, $answer['answer_relevance'], 'Scores are clamped to 0..1.');
assert_same(100.0, (float) $answer['citation_hit_rate'], 'The answer cites the expected chunk.');
assert_same('judge-x', $calls[0]['model'], 'The external model name is sent.');

$judge = json_decode((string) $pdo->query("SELECT judge FROM rag_eval_results r JOIN rag_eval_cases c ON c.id = r.case_id WHERE c.case_id = 'case_1' ORDER BY r.id DESC LIMIT 1")->fetchColumn(), true);
assert_same([2], $judge['citations'], 'Out-of-range citations ([9]) are dropped.');
assert_same(['chk_temp'], $judge['cited_chunk_ids'], 'Citations map back to chunk ids.');
$skipped = json_decode((string) $pdo->query("SELECT judge FROM rag_eval_results r JOIN rag_eval_cases c ON c.id = r.case_id WHERE c.case_id = 'case_3' ORDER BY r.id DESC LIMIT 1")->fetchColumn(), true);
assert_same('skipped', $skipped['status'], 'A query without sources is skipped, not sent to the model.');

$stored = json_decode((string) $pdo->query("SELECT metadata FROM rag_eval_runs WHERE run_id = '{$run['run_id']}'")->fetchColumn(), true);
assert_same(7, $stored['metrics']['answer_evaluation']['judge_model_id'], 'Answer metrics are stored with the run.');

// Sampling: judge_sample=1 sends only the first case.
$calls = [];
$service->run(['dataset_ids' => ['ds_1'], 'top_k_values' => [5], 'judge_model_id' => 7, 'judge_sample' => 1]);
assert_same(2, count($calls), 'One sampled case: one answer call and one judge call.');

echo "PASS\n";
