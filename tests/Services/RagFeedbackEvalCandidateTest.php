<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagFeedbackService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$kbId = 'kb_evalfb_' . $suffix;
$datasetId = 'ds_evalfb_' . $suffix;

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Feedback Eval KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Feedback Eval DS ' . $suffix, 'evalfb_' . $suffix]);
$retrievalId = 'rtr_evalfb_' . $suffix;
$db->execute(
    'INSERT INTO rag_retrieval_logs (retrieval_id, request_id, api_key_id, user_id, department_id, kb_ids, dataset_ids, query, query_modality, top_k, hit_count, max_score, sources, latency_ms, fallback_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "text", 5, 1, 0.9, ?, 10, 0)',
    [$retrievalId, 'req_evalfb_' . $suffix, $apiKey['id'], $apiKey['user_id'], $apiKey['department_id'], json_encode([$kbId]), json_encode([$datasetId]), 'missing policy detail', json_encode([['chunk_id' => 'chk_evalfb_' . $suffix]])]
);

$bad = (new RagFeedbackService())->create($apiKey, [
    'retrieval_id' => $retrievalId,
    'rating' => 'missing_context',
    'expected_answer' => 'The policy requires approval.',
]);
assert_true(isset($bad['eval_case_id']) && str_starts_with((string) $bad['eval_case_id'], 'eval_'), 'Negative feedback should create draft eval case.');

$case = $db->first('SELECT status, source_feedback_id, expected_chunk_ids FROM rag_eval_cases WHERE case_id = ?', [$bad['eval_case_id']]);
assert_same('draft', $case['status'], 'Feedback-created eval case should be draft.');
assert_same($bad['feedback_id'], $case['source_feedback_id'], 'Eval case should reference source feedback.');
assert_same(['chk_evalfb_' . $suffix], json_decode((string) $case['expected_chunk_ids'], true), 'Eval case should preserve selected source chunks.');

$goodRetrievalId = 'rtr_evalgood_' . $suffix;
$db->execute(
    'INSERT INTO rag_retrieval_logs (retrieval_id, request_id, api_key_id, user_id, department_id, kb_ids, dataset_ids, query, query_modality, top_k, hit_count, max_score, sources, latency_ms, fallback_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "text", 5, 1, 0.9, "[]", 10, 0)',
    [$goodRetrievalId, 'req_evalgood_' . $suffix, $apiKey['id'], $apiKey['user_id'], $apiKey['department_id'], json_encode([$kbId]), json_encode([$datasetId]), 'good answer']
);
$good = (new RagFeedbackService())->create($apiKey, ['retrieval_id' => $goodRetrievalId, 'rating' => 'good']);
assert_true(!isset($good['eval_case_id']), 'Good feedback should not create eval case.');
