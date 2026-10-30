<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * RAG-030..RAG-036: sparse lexical candidates (no full scan), one query
 * embedding per model, model rerank with graceful fallback, knowledge-base
 * synonyms, user-only query extraction, neighbour expansion inside a section,
 * Qdrant distance/payload indexes, reconcile, and blue/green reindex with an
 * evaluation gate and alias swap.
 */

use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagIndexMaintenanceService;
use TokenHub\Services\RagRerankService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Services\RagVectorConfigService;
use TokenHub\Services\RagVectorContractService;

$pdo = rag_sqlite_fixture();
$qdrant = new RagFakeQdrant();
RagVectorConfigService::useQdrantFactory(static fn (): QdrantVectorStore => new QdrantVectorStore($qdrant));
$storage = sys_get_temp_dir() . '/rag-retrieval-' . bin2hex(random_bytes(4));

$pdo->exec("INSERT INTO departments (id, name, path) VALUES (1, 'D', '/1/')");
$pdo->exec("INSERT INTO users (id, department_id, username) VALUES (10, 1, 'u')");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (100, 10, 1, 'k')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility, retrieval_profile) VALUES (1, 'kb_1', 'KB', 1, 'department', '{\"synonyms\": {\"vpn\": [\"远程接入\"]}, \"neighbor_chunks\": 1}')");
$pdo->exec("INSERT INTO rag_vector_configs (id, config_id, name, modality, provider_id, model_id, model_name, dimension, distance_metric, vector_store, collection_name) VALUES (1, 'cfg', 'text', 'text', 1, 1, 'm', 3, 'dot', 'qdrant', 'col_text'), (2, 'cfg2', 'text v2', 'text', 1, 1, 'm2', 3, 'cosine', 'qdrant', 'col_text_v2')");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name, text_vector_config_id) VALUES (1, 'ds_1', 1, 'DS', 1), (2, 'ds_2', 1, 'DS2', 1)");
$pdo->exec("INSERT INTO rag_uploads (id, upload_id, kb_id, dataset_id) VALUES (1, 'upl_1', 1, 1)");
$pdo->exec("INSERT INTO rag_documents (id, document_id, kb_id, dataset_id, upload_id, title) VALUES (1, 'doc_1', 1, 1, 1, '员工手册')");
$rows = [
    [1, 'chk_a', 1, '如何申请远程接入账号：提交工单并由主管审批。'],
    [2, 'chk_b', 2, '审批通过后，IT 在一个工作日内开通。'],
    [3, 'chk_c', 3, '报销流程：填写报销单并附发票。'],
];
foreach ($rows as [$id, $chunkId, $ordinal, $content]) {
    $pdo->prepare('INSERT INTO rag_chunks (id, chunk_id, kb_id, dataset_id, document_id, ordinal, title, content, metadata) VALUES (?, ?, 1, 1, 1, ?, ?, ?, ?)')
        ->execute([$id, $chunkId, $ordinal, '', $content, json_encode(['heading_path' => ['员工手册', $id <= 2 ? '远程办公' : '报销'], 'section_key' => $id <= 2 ? 'sec_remote' : 'sec_money', 'segment_ordinal' => $ordinal, 'document_key' => 'file:handbook.docx'], JSON_UNESCAPED_UNICODE)]);
}

$embedCalls = 0;
$embedder = new RagEmbeddingProviderService(static function (string $modality, array $input) use (&$embedCalls): array {
    $embedCalls++;
    $text = (string) $input['text'];
    return [str_contains($text, '远程') || str_contains($text, 'vpn') ? 1.0 : 0.1, str_contains($text, '报销') ? 1.0 : 0.1, 0.2];
});
$jobs = new RagEmbeddingJobService($embedder);
$config = $pdo->query('SELECT * FROM rag_vector_configs WHERE id = 1')->fetch();
$dataset = $pdo->query("SELECT d.*, k.kb_id AS external_kb_id, k.retrieval_profile AS kb_retrieval_profile FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = 1")->fetch();
foreach ($pdo->query('SELECT c.*, d.title AS document_title FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id')->fetchAll() as $chunk) {
    $jobs->indexChunk((new RagVectorConfigService())->store($config), 'col_text', $config, $dataset, $chunk, 'text');
}
assert_same('Dot', $qdrant->collections['col_text']['config']['vectors']['distance'] ?? null, 'Collection distance follows the vector config (dot).');
assert_same('keyword', $qdrant->collections['col_text']['indexes']['dataset_id'] ?? null, 'Payload index on dataset_id is created.');
assert_true(isset($qdrant->collections['col_text__lexical']), 'Sparse lexical collection is written next to the dense one.');

$apiKey = ['id' => 100, 'user_id' => 10, 'department_id' => 1];

// Synonym from the knowledge base profile: "VPN" also searches "远程接入".
$embedCalls = 0;
$retrieval = new RagRetrievalService($embedder);
$result = $retrieval->search($apiKey, '怎么开通 VPN', ['dataset_ids' => ['ds_1', 'ds_2'], 'top_k' => 1]);
$ids = array_map(static fn (array $s): string => (string) $s['chunk_id'], $result['sources']);
assert_same('chk_a', $ids[0] ?? null, 'Synonym expansion finds the 远程接入 chunk for a VPN question.');
assert_same(1, $embedCalls, 'The query is embedded once for two datasets that share a model.');
assert_true(str_contains((string) $result['sources'][0]['content'], '一个工作日内开通'), 'The hit is widened with its neighbour chunk from the same section (not returned on its own).');
assert_same(['chk_b'], $result['sources'][0]['expanded_from_chunk_ids'] ?? null, 'Expansion is reported.');
assert_true(!str_contains((string) $result['sources'][0]['content'], '报销'), 'Expansion never crosses into another section.');
assert_true(in_array($result['sources'][0]['rank_signals']['lexical_rank'] ?? null, [1, 2], true), 'Lexical candidates came from the sparse index.');

// Model rerank: configured via profile; a failing provider falls back to the rule ranking.
$pdo->exec("INSERT INTO providers (id, name, protocol, base_url, status) VALUES (5, 'rr', 'openai', 'http://rr', 'active')");
$pdo->exec("INSERT INTO models (id, provider_id, name, capability, status) VALUES (50, 5, 'reranker', 'rerank', 'active')");
$pdo->exec("UPDATE rag_knowledge_bases SET retrieval_profile = '{\"rerank\": {\"model_id\": 50, \"top_n\": 10}, \"neighbor_chunks\": 0}' WHERE id = 1");
$requests = [];
$reranker = new RagRerankService(static function (array $route, string $path, array $payload) use (&$requests): array {
    $requests[] = [$path, $payload];
    // Prefer the reimbursement chunk to prove the model order wins.
    $results = [];
    foreach ($payload['documents'] as $index => $document) {
        $results[] = ['index' => $index, 'relevance_score' => str_contains($document, '报销') ? 0.95 : 0.4];
    }
    return ['ok' => true, 'status' => 200, 'json' => ['results' => $results]];
});
$result = (new RagRetrievalService($embedder, null, null, null, $reranker))->search($apiKey, '远程接入 报销', ['dataset_ids' => ['ds_1'], 'top_k' => 3]);
assert_same('/v1/rerank', $requests[0][0] ?? null, 'The rerank endpoint is called.');
assert_same('chk_c', $result['sources'][0]['chunk_id'] ?? null, 'Model rerank order wins.');
assert_same(0.95, $result['sources'][0]['confidence_score'] ?? null, 'Confidence comes from the rerank score.');
$failing = new RagRerankService(static fn (): array => ['ok' => false, 'status' => 503, 'error_code' => 'PROVIDER_ERROR']);
$fallback = (new RagRetrievalService($embedder, null, null, null, $failing))->search($apiKey, '远程接入', ['dataset_ids' => ['ds_1'], 'top_k' => 3]);
assert_true(($fallback['sources'] ?? []) !== [], 'A failing reranker never empties retrieval.');
$pdo->exec("UPDATE rag_knowledge_bases SET retrieval_profile = '{\"synonyms\": {\"vpn\": [\"远程接入\"]}, \"neighbor_chunks\": 1}' WHERE id = 1");

// Query extraction: the last user text, not tool output or image data.
$method = new ReflectionMethod(RagRetrievalService::class, 'query');
$query = $method->invoke(new RagRetrievalService(), ['messages' => [
    ['role' => 'user', 'content' => [['type' => 'text', 'text' => '报销需要什么材料？'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']]]],
    ['role' => 'assistant', 'content' => '我来查一下'],
    ['role' => 'tool', 'content' => '{"result": 1}'],
]]);
assert_same('报销需要什么材料？', $query, 'Only the user text is used as the retrieval query.');

// Reconcile: an orphan point (deleted record) and a missing point (lost write).
$contract = new RagVectorContractService();
(new QdrantVectorStore($qdrant))->upsert('col_text', 'vec_text_chk_ghost', [1.0, 0.0, 0.0], ['vector_id' => 'vec_text_chk_ghost', 'dataset_id' => 'ds_1', 'chunk_id' => 'chk_ghost', 'modality' => 'text']);
unset($qdrant->collections['col_text']['points'][$contract->pointId('vec_text_chk_c')]);
$pdo->exec("INSERT INTO rag_embedding_jobs (job_id, kb_id, dataset_id, upload_id, chunk_id, vector_config_id, job_type, status) VALUES ('job_c', 1, 1, 1, 3, 1, 'text_embedding', 'succeeded')");
$maintenance = new RagIndexMaintenanceService($jobs);
$report = $maintenance->reconcile(1, false);
assert_same(1, $report['orphans'], 'One orphan point is reported.');
assert_same(1, $report['missing'], 'One missing point is reported.');
$maintenance->reconcile(1, true);
assert_true(!isset($qdrant->collections['col_text']['points'][$contract->pointId('vec_text_chk_ghost')]), 'Orphan point removed.');
assert_same('queued', $pdo->query("SELECT status FROM rag_embedding_jobs WHERE job_id = 'job_c'")->fetchColumn(), 'Missing vector is re-queued.');
assert_same('stale', $pdo->query("SELECT status FROM rag_vector_records WHERE vector_id = 'vec_text_chk_c'")->fetchColumn(), 'Its record is marked stale.');
$jobs->indexChunk((new RagVectorConfigService())->store($config), 'col_text', $config, $dataset, $pdo->query('SELECT c.*, d.title AS document_title FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE c.id = 3')->fetch(), 'text');

// Blue/green reindex to a new model, gated by evaluation cases.
$pdo->exec("INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, expected_chunk_ids, status) VALUES ('case_1', 1, 1, '远程接入账号', '[\"chk_a\"]', 'active'), ('case_2', 1, 1, '报销流程', '[\"chk_c\"]', 'active')");
$evaluations = 0;
$evaluator = static function (callable $ranker) use (&$evaluations): object {
    return new class($ranker, $evaluations) {
        public function __construct(private $ranker, private int &$count) {}
        public function run(array $options): array {
            $this->count++;
            $hits = 0; $mrr = 0.0;
            foreach ([['远程接入账号', 'chk_a'], ['报销流程', 'chk_c']] as [$q, $expected]) {
                $ids = array_column(($this->ranker)(['query' => $q, 'query_modality' => 'text'], 5), 'chunk_id');
                $rank = array_search($expected, $ids, true);
                if ($rank !== false) { $hits++; $mrr += 1 / ($rank + 1); }
            }
            return ['run_id' => 'r' . $this->count, 'recall_at_5' => $hits * 50.0, 'mrr' => $mrr / 2];
        }
    };
};
putenv('STORAGE_PATH=' . $storage);
$maintenance = new RagIndexMaintenanceService($jobs, null, $evaluator);
$report = $maintenance->reindex(1, 2);
assert_true($report['gate']['passed'] === true, 'Candidate index passes the gate.');
assert_same(2, $evaluations, 'Baseline and candidate were both evaluated.');
assert_true($report['switched'], 'The dataset switched to the new model.');
assert_same(2, (int) $pdo->query('SELECT text_vector_config_id FROM rag_vector_datasets WHERE id = 1')->fetchColumn(), 'Dataset points at the new vector config.');
assert_same($report['collection'], $qdrant->aliases['col_text_v2'] ?? null, 'Alias of the new config points at the freshly built collection.');
assert_same(3, count($qdrant->collections[$report['collection']]['points']), 'All active chunks were rebuilt.');
assert_same(0, count(array_filter($qdrant->collections['col_text']['points'], static fn (array $p): bool => ($p['payload']['dataset_id'] ?? '') === 'ds_1')), 'Old model points of this dataset are removed.');
assert_same('2', (string) $pdo->query("SELECT vector_config_id FROM rag_vector_records WHERE vector_id = 'vec_text_chk_a'")->fetchColumn(), 'Vector records follow the new config.');

// Rebuild with the same config re-uses stored vectors and swaps the alias again.
$embedCalls = 0;
$again = $maintenance->reindex(1, null, true);
assert_same(0, $embedCalls, 'An unchanged rebuild calls no embedding model.');
assert_same($again['collection'], $qdrant->aliases['col_text_v2'], 'Alias moved to the new physical collection.');
assert_true(!isset($qdrant->collections[$report['collection']]), 'The previous physical collection was dropped.');

$results = (new RagRetrievalService($embedder))->search($apiKey, '远程接入', ['dataset_ids' => ['ds_1'], 'top_k' => 3]);
assert_same('chk_a', $results['sources'][0]['chunk_id'] ?? null, 'Retrieval works through the alias after the swap.');

// A worse candidate is rejected: the live index and config stay, the candidate is dropped.
$calls = 0;
$worse = static function (callable $ranker) use (&$calls): object {
    return new class($calls) {
        public function __construct(private int &$calls) {}
        public function run(array $options): array {
            $this->calls++;
            return $this->calls === 1 ? ['run_id' => 'base', 'recall_at_5' => 100.0, 'mrr' => 1.0] : ['run_id' => 'cand', 'recall_at_5' => 50.0, 'mrr' => 0.5];
        }
    };
};
$before = $qdrant->aliases['col_text_v2'];
$blocked = (new RagIndexMaintenanceService($jobs, null, $worse))->reindex(1, null);
assert_true($blocked['gate']['passed'] === false && $blocked['switched'] === false, 'The gate blocks a worse candidate.');
assert_same($before, $qdrant->aliases['col_text_v2'], 'The alias still points at the live collection.');
assert_true(!isset($qdrant->collections[$blocked['collection']]), 'The rejected candidate collection is removed.');

echo "PASS\n";
