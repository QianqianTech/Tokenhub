<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * RAG-040 / RAG-041: evaluation cases survive re-chunking through anchors,
 * remap() rewrites their expected chunk ids, and runs report nDCG@10 and the
 * no-result rate.
 */

use TokenHub\Services\RagEvaluationAnchorService;
use TokenHub\Services\RagRetrievalEvaluationService;

$pdo = rag_sqlite_fixture();
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id) VALUES (1, 'kb_1', 'KB', 1)");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name) VALUES (1, 'ds_1', 1, 'DS')");
$pdo->exec("INSERT INTO rag_documents (id, document_id, kb_id, dataset_id, upload_id, title) VALUES (1, 'doc_1', 1, 1, 1, 'v1'), (2, 'doc_2', 1, 1, 2, 'v2')");
$pdo->exec("INSERT INTO rag_chunks (id, chunk_id, kb_id, dataset_id, document_id, content, metadata) VALUES
  (1, 'chk_old', 1, 1, 1, '前言。\n温度超过八十度时必须立即停机并检查冷却单元的风扇与滤网。\n结束。', '{\"document_key\": \"file:manual.docx\"}')");

$anchors = (new RagEvaluationAnchorService())->anchorsFor(['chk_old']);
assert_same('温度超过八十度时必须立即停机并检查冷却单元的风扇与滤网。', $anchors[0]['text'], 'The anchor is a verbatim passage from the body.');
$pdo->prepare("INSERT INTO rag_eval_cases (id, case_id, kb_id, dataset_id, query, expected_chunk_ids, status, metadata) VALUES (1, 'case_1', 1, 1, '温度过高怎么办', ?, 'active', ?)")
    ->execute([json_encode(['chk_old']), json_encode(['expected_anchors' => $anchors], JSON_UNESCAPED_UNICODE)]);
$pdo->exec("INSERT INTO rag_eval_cases (id, case_id, kb_id, dataset_id, query, expected_chunk_ids, status, metadata) VALUES (2, 'case_2', 1, 1, '没有答案的问题', '[\"chk_none\"]', 'active', '{}')");

// Re-upload: the old chunk is superseded, the same passage now lives in a new chunk.
$pdo->exec("UPDATE rag_chunks SET enabled = 0, lifecycle_status = 'superseded' WHERE id = 1");
$pdo->exec("INSERT INTO rag_chunks (id, chunk_id, kb_id, dataset_id, document_id, content, metadata) VALUES (2, 'chk_new', 1, 1, 2, '第三章 告警\n温度超过八十度时必须立即停机并检查冷却单元的风扇与滤网。', '{\"document_key\": \"file:manual.docx\"}')");

$ranker = static fn (array $case, int $topK): array => $case['query'] === '温度过高怎么办'
    ? [['chunk_id' => 'chk_other', 'content' => '无关'], ['chunk_id' => 'chk_new', 'content' => '第三章 告警 温度超过八十度时必须立即停机并检查冷却单元的风扇与滤网。', 'document_key' => 'file:manual.docx']]
    : [];
$run = (new RagRetrievalEvaluationService($ranker))->run(['dataset_ids' => ['ds_1'], 'top_k_values' => [5, 10]]);
assert_same(50.0, (float) $run['recall_at_5'], 'The re-chunked case still counts as a hit through its anchor; the other case misses.');
assert_same(round((1 / log(3, 2)) / 2, 4), $run['ndcg_at_10'], 'nDCG@10 for a hit at rank 2 over two cases.');
assert_same(50.0, (float) $run['no_result_rate'], 'One of two queries returned nothing.');

$remap = (new RagEvaluationAnchorService())->remap(1);
assert_same(1, $remap['remapped'], 'One case is re-pointed.');
assert_same(['case_2'], $remap['unresolved'], 'A case whose chunks are gone and that has no anchors is reported.');
assert_same(['chk_new'], json_decode((string) $pdo->query("SELECT expected_chunk_ids FROM rag_eval_cases WHERE id = 1")->fetchColumn(), true), 'Expected chunk ids now point at the new chunk.');

echo "PASS\n";
