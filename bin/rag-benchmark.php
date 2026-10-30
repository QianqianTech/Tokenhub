<?php

declare(strict_types=1);

/*
 * RAG scale benchmark (RAG-044). Creates a temporary knowledge base with synthetic
 * Chinese/English chunks, indexes them through the normal pipeline, runs hybrid
 * queries and reports throughput, latency percentiles and memory. Cleans up
 * afterwards unless --keep is given.
 *
 *   php bin/rag-benchmark.php --chunks=10000 --queries=50 [--config=<vector config id>] [--keep]
 *
 * --config selects the vector config (embedding model + store) to measure; without it the
 * first enabled text config is used. Embedding cost dominates indexing time: to measure
 * the vector store alone, use a config with a fast local embedding model.
 */

use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Services\RagVectorConfigService;
use TokenHub\Support\App;

require_once __DIR__ . '/../app/Bootstrap/autoload.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m) === 1) {
        $options[$m[1]] = $m[2] ?? true;
    }
}
$chunkCount = max(10, (int) ($options['chunks'] ?? 10000));
$queryCount = max(1, (int) ($options['queries'] ?? 50));
$db = App::db();
$config = isset($options['config'])
    ? $db->first('SELECT * FROM rag_vector_configs WHERE id = ? AND modality = "text"', [(int) $options['config']])
    : $db->first('SELECT * FROM rag_vector_configs WHERE enabled = 1 AND modality = "text" ORDER BY id LIMIT 1');
if ($config === null) {
    fwrite(STDERR, "No text vector config found.\n");
    exit(1);
}
$suffix = bin2hex(random_bytes(4));
$topics = ['温度告警', '报销流程', '远程接入', '密码重置', '设备维护', '合同审批', '采购申请', '数据备份', 'VPN 配置', '安全培训'];
$words = ['系统', '流程', '审批', '配置', '检查', '记录', '提交', '部门', '主管', '工单', 'server', 'backup', 'policy', 'deadline', 'invoice'];

$started = microtime(true);
$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, visibility, status) VALUES (?, ?, "private", "active")', ['kb_bench_' . $suffix, 'Benchmark ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', ['ds_bench_' . $suffix, $kbPk, 'Benchmark ' . $suffix, 'bench_' . $suffix, $config['id']]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "batch_import", "document", ?, ?, "confirmed", "processing", "vectorizing")', ['upl_bench_' . $suffix, $kbPk, $datasetPk, str_repeat('0', 64), str_repeat('0', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, dataset_id, upload_id, title, document_key, doc_type, parse_status, index_status) VALUES (?, ?, ?, ?, "Benchmark", ?, "plain_text", "parsed", "indexing")', ['doc_bench_' . $suffix, $kbPk, $datasetPk, $uploadPk, 'bench:' . $suffix]);
$documentPk = (int) $db->pdo()->lastInsertId();
$dataset = $db->first('SELECT d.*, k.kb_id AS external_kb_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = ?', [$datasetPk]);

$jobs = new RagEmbeddingJobService();
$configs = new RagVectorConfigService();
$store = $configs->store($config);
$collection = (string) $config['collection_name'];
$indexStarted = microtime(true);
for ($i = 1; $i <= $chunkCount; $i++) {
    $topic = $topics[$i % count($topics)];
    $text = $topic . '：' . implode('', array_map(static fn () => $words[array_rand($words)], range(1, 40))) . " 编号 B{$i}。";
    $chunkId = 'chk_bench_' . $suffix . '_' . $i;
    $db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, dataset_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, ?, "text", ?, ?, ?, ?, ?, 1, "active")', [$chunkId, $kbPk, $datasetPk, $documentPk, $i, $topic, $text, json_encode(['heading_path' => ['Benchmark', $topic]], JSON_UNESCAPED_UNICODE), hash('sha256', $text)]);
    $chunk = $db->first('SELECT c.*, "Benchmark" AS document_title FROM rag_chunks c WHERE c.chunk_id = ?', [$chunkId]);
    $jobs->indexChunk($store, $collection, $config, $dataset, $chunk, 'text');
}
$indexSeconds = microtime(true) - $indexStarted;

$retrieval = new RagRetrievalService();
$latencies = [];
for ($q = 0; $q < $queryCount; $q++) {
    $query = $topics[$q % count($topics)] . ' ' . $words[array_rand($words)];
    $t = microtime(true);
    $retrieval->searchSourcesForDatasets([$dataset], $query, 8);
    $latencies[] = (microtime(true) - $t) * 1000;
}
sort($latencies);
$percentile = static fn (float $p): float => round($latencies[(int) min(count($latencies) - 1, floor($p * count($latencies)))], 1);
$report = [
    'chunks' => $chunkCount,
    'queries' => $queryCount,
    'vector_store' => $config['vector_store'],
    'embedding_model' => $config['model_name'],
    'index_seconds' => round($indexSeconds, 2),
    'index_chunks_per_second' => round($chunkCount / max(0.001, $indexSeconds), 1),
    'query_ms_p50' => $percentile(0.5),
    'query_ms_p95' => $percentile(0.95),
    'query_ms_max' => round(end($latencies), 1),
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    'total_seconds' => round(microtime(true) - $started, 2),
];
if (!isset($options['keep'])) {
    $chunkIds = array_map(static fn (array $row): int => (int) $row['id'], $db->select('SELECT id FROM rag_chunks WHERE dataset_id = ?', [$datasetPk]));
    foreach (array_chunk($chunkIds, 500) as $batch) {
        (new \TokenHub\Services\RagVectorDeletionService())->deleteVectors($batch);
    }
    $db->execute('DELETE FROM rag_vector_records WHERE dataset_id = ?', [$datasetPk]);
    $db->execute('DELETE FROM rag_chunks WHERE dataset_id = ?', [$datasetPk]);
    $db->execute('DELETE FROM rag_documents WHERE id = ?', [$documentPk]);
    $db->execute('DELETE FROM rag_uploads WHERE id = ?', [$uploadPk]);
    $db->execute('DELETE FROM rag_vector_datasets WHERE id = ?', [$datasetPk]);
    $db->execute('DELETE FROM rag_knowledge_bases WHERE id = ?', [$kbPk]);
}
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
