<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use Throwable;
use TokenHub\Support\App;

/**
 * Index operations (RAG-030, RAG-042, RAG-043):
 *  - health():        coverage, stale vectors, failed jobs, lexical status per dataset
 *  - reconcile():     compares rag_vector_records with the Qdrant points of each dataset,
 *                     removes orphan points and re-queues records whose point is missing
 *  - backfillLexical(): builds the sparse lexical index for chunks indexed before it existed
 *  - reindex():       blue/green rebuild into a new physical collection, optional switch to a
 *                     new vector config (model), evaluation gate, then an atomic alias swap
 *
 * The database is authoritative throughout; the vector store is derived data.
 */
final class RagIndexMaintenanceService
{
    public function __construct(
        private readonly ?RagEmbeddingJobService $jobs = null,
        private readonly ?RagVectorConfigService $configs = null,
        private readonly mixed $evaluator = null,
    ) {
    }

    /**
     * @param list<int>|null $datasetIds limit to these datasets (null = all)
     * @return array<string, mixed>
     */
    public function health(?array $datasetIds = null): array
    {
        $where = 'd.deleted_at IS NULL';
        $params = [];
        if ($datasetIds !== null) {
            if ($datasetIds === []) {
                return ['datasets' => [], 'totals' => $this->totals([]), 'unbound_vector_configs' => 0, 'last_reconcile' => $this->lastReconcile()];
            }
            $where .= ' AND d.id IN (' . implode(',', array_fill(0, count($datasetIds), '?')) . ')';
            $params = $datasetIds;
        }
        $datasets = App::db()->select('SELECT d.*, k.kb_id AS external_kb_id, k.name AS kb_name FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE ' . $where . ' ORDER BY d.id', $params);
        $rows = [];
        foreach ($datasets as $dataset) {
            $id = (int) $dataset['id'];
            $chunks = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_chunks c JOIN rag_documents doc ON doc.id = c.document_id JOIN rag_vector_datasets dset ON dset.id = COALESCE(c.dataset_id, doc.dataset_id) WHERE dset.id = ? AND c.enabled = 1 AND c.lifecycle_status = "active" AND doc.deleted_at IS NULL', [$id])['c'] ?? 0);
            $covered = (int) (App::db()->first('SELECT COUNT(DISTINCT r.chunk_id) AS c FROM rag_vector_records r JOIN rag_chunks c ON c.id = r.chunk_id WHERE r.dataset_id = ? AND r.status = "indexed" AND c.enabled = 1 AND c.lifecycle_status = "active"', [$id])['c'] ?? 0);
            $stale = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_vector_records WHERE dataset_id = ? AND status = "stale"', [$id])['c'] ?? 0);
            $jobs = App::db()->first('SELECT SUM(status = "failed") AS failed, SUM(status IN ("queued","retrying","running")) AS active FROM rag_embedding_jobs WHERE dataset_id = ? AND job_type <> "parse"', [$id]) ?? [];
            $config = ($this->configs ?? new RagVectorConfigService())->textConfig($dataset);
            $rows[] = [
                'id' => $id,
                'dataset_id' => (string) $dataset['dataset_id'],
                'name' => (string) $dataset['name'],
                'kb_id' => (string) $dataset['external_kb_id'],
                'kb_name' => (string) $dataset['kb_name'],
                'active_chunks' => $chunks,
                'indexed_chunks' => $covered,
                'coverage_percent' => $chunks === 0 ? 100.0 : round($covered * 100 / $chunks, 2),
                'stale_vectors' => $stale,
                'failed_jobs' => (int) ($jobs['failed'] ?? 0),
                'active_jobs' => (int) ($jobs['active'] ?? 0),
                'lexical_index_status' => (string) ($dataset['lexical_index_status'] ?? 'none'),
                'last_indexed_at' => $dataset['last_indexed_at'] ?? null,
                'vector_store' => (string) ($config['vector_store'] ?? ''),
                'embedding_model' => (string) ($config['model_name'] ?? ''),
                'model_bound' => !empty($config['provider_id']) && !empty($config['model_id']),
            ];
        }
        $unbound = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_vector_configs WHERE enabled = 1 AND (provider_id IS NULL OR model_id IS NULL)')['c'] ?? 0);

        return ['datasets' => $rows, 'totals' => $this->totals($rows), 'unbound_vector_configs' => $unbound, 'last_reconcile' => $this->lastReconcile()];
    }

    /**
     * @return array<string, mixed> report per dataset/collection
     */
    public function reconcile(?int $datasetId = null, bool $fix = false): array
    {
        $contract = new RagVectorContractService();
        $report = ['started_at' => gmdate('c'), 'fix' => $fix, 'collections' => []];
        $where = $datasetId === null ? 'deleted_at IS NULL' : 'id = ?';
        $datasets = App::db()->select('SELECT * FROM rag_vector_datasets WHERE ' . $where . ' ORDER BY id', $datasetId === null ? [] : [$datasetId]);
        foreach ($datasets as $dataset) {
            $records = App::db()->select(
                'SELECT r.id, r.vector_id, r.collection_name, r.chunk_id, r.status, r.vector_config_id, c.vector_store
                 FROM rag_vector_records r LEFT JOIN rag_vector_configs c ON c.id = r.vector_config_id
                 WHERE r.dataset_id = ? AND r.status IN ("indexed","stale")',
                [(int) $dataset['id']]
            );
            $byCollection = [];
            foreach ($records as $record) {
                if ((string) ($record['vector_store'] ?? 'qdrant') === 'mysql_fallback' || (string) $record['collection_name'] === '') {
                    continue;
                }
                $byCollection[(string) $record['collection_name']][$contract->pointId((string) $record['vector_id'])] = $record;
            }
            foreach ($this->datasetCollections($dataset) as $collection) {
                $byCollection[$collection] ??= [];
            }
            foreach ($byCollection as $collection => $expected) {
                $entry = ['dataset_id' => (string) $dataset['dataset_id'], 'collection' => $collection, 'expected' => count($expected), 'points' => 0, 'orphans' => 0, 'missing' => 0, 'fixed' => 0, 'error' => null];
                try {
                    $store = $this->qdrant();
                    $seen = [];
                    $orphans = [];
                    foreach ($store->scroll($collection, 256, ['dataset_id' => (string) $dataset['dataset_id']]) as $point) {
                        $entry['points']++;
                        $seen[$point['id']] = true;
                        if (!isset($expected[$point['id']]) || (string) $expected[$point['id']]['status'] !== 'indexed') {
                            $orphans[] = $point['id'];
                        }
                    }
                    $missing = array_values(array_filter($expected, static fn (array $record, string $pointId): bool => !isset($seen[$pointId]) && (string) $record['status'] === 'indexed', ARRAY_FILTER_USE_BOTH));
                    $entry['orphans'] = count($orphans);
                    $entry['missing'] = count($missing);
                    if ($fix) {
                        // Orphans: points without an indexed record (deleted, superseded, failed writes).
                        foreach (array_chunk($orphans, 256) as $batch) {
                            $store->deletePoints($collection, $batch);
                        }
                        // Missing: indexed records without a point; mark stale and re-queue their jobs.
                        foreach ($missing as $record) {
                            App::db()->execute('UPDATE rag_vector_records SET status = "stale", index_status = "stale", indexed_at = NULL WHERE id = ?', [$record['id']]);
                            App::db()->execute(
                                'UPDATE rag_embedding_jobs SET status = "queued", attempts = 0, next_run_at = NULL, dispatched_at = NULL, locked_at = NULL, lock_token = NULL, error_code = NULL, error_message = NULL WHERE chunk_id = ? AND job_type <> "parse"',
                                [$record['chunk_id']]
                            );
                        }
                        $entry['fixed'] = count($orphans) + count($missing);
                    }
                } catch (Throwable $throwable) {
                    $entry['error'] = $throwable->getMessage();
                }
                $report['collections'][] = $entry;
            }
        }
        $report['finished_at'] = gmdate('c');
        $report['orphans'] = array_sum(array_column($report['collections'], 'orphans'));
        $report['missing'] = array_sum(array_column($report['collections'], 'missing'));
        if ($datasetId === null) {
            $this->storeReport('last-reconcile.json', $report);
        }

        return $report;
    }

    /** @return array{dataset_id: string, chunks: int, points: int} */
    public function backfillLexical(int $datasetId): array
    {
        $dataset = $this->dataset($datasetId);
        $configService = $this->configs ?? new RagVectorConfigService();
        $config = $configService->textConfig($dataset);
        if ((string) ($config['vector_store'] ?? '') !== 'qdrant') {
            return ['dataset_id' => (string) $dataset['dataset_id'], 'chunks' => 0, 'points' => 0, 'skipped' => 'vector store is not qdrant (MySQL FULLTEXT is used instead)'];
        }
        App::db()->execute('UPDATE rag_vector_datasets SET lexical_index_status = "building" WHERE id = ?', [$datasetId]);
        $store = $configService->store($config);
        if (!$store instanceof QdrantVectorStore) {
            throw new RuntimeException('RAG_VECTOR_STORE_UNAVAILABLE');
        }
        $collection = (string) ($config['collection_name'] ?? $configService->collection((string) $dataset['dataset_id'], 'text'));
        $jobs = $this->jobs ?? new RagEmbeddingJobService();
        $chunks = 0;
        $points = 0;
        foreach ($this->activeChunks($datasetId) as $chunk) {
            $chunks++;
            foreach ($jobs->prepareChunkVectors($config, $dataset, $chunk, 'text') as $prepared) {
                $store->upsertLexical($collection, $prepared['vector_id'], $prepared['lexical_text'], RagEmbeddingJobService::lexicalPayload($prepared['payload']));
                $points++;
            }
        }
        App::db()->execute('UPDATE rag_vector_datasets SET lexical_index_status = "ready" WHERE id = ?', [$datasetId]);

        return ['dataset_id' => (string) $dataset['dataset_id'], 'chunks' => $chunks, 'points' => $points];
    }

    /**
     * Blue/green rebuild of a dataset's text index.
     *
     * Without $targetConfigId: re-embeds (re-using stored vectors when the input is unchanged)
     * into "<collection>_v<timestamp>" and swaps the alias "<collection>" to it — used after
     * changing chunking, distance metric or payload indexes.
     * With $targetConfigId: builds the index of another vector config (embedding model) and,
     * when the gate passes, switches the dataset to it.
     *
     * Gate: when the dataset has active evaluation cases, the candidate must not lose more
     * than $maxRecallDrop points of Recall@5 or $maxMrrDrop of MRR against the current index.
     *
     * @return array<string, mixed>
     */
    public function reindex(int $datasetId, ?int $targetConfigId = null, bool $skipGate = false, float $maxRecallDrop = 2.0, float $maxMrrDrop = 0.02, bool $dryRun = false): array
    {
        $dataset = $this->dataset($datasetId);
        $configService = $this->configs ?? new RagVectorConfigService();
        $current = $configService->textConfig($dataset);
        $target = $targetConfigId === null ? $current : App::db()->first('SELECT * FROM rag_vector_configs WHERE id = ? AND enabled = 1 AND modality = "text"', [$targetConfigId]);
        if ($target === null) {
            throw new RuntimeException('RAG_VECTOR_CONFIG_NOT_FOUND');
        }
        if ((string) ($target['vector_store'] ?? '') !== 'qdrant') {
            throw new RuntimeException('Blue/green reindex requires a Qdrant vector config');
        }
        $store = $configService->store($target);
        if (!$store instanceof QdrantVectorStore) {
            throw new RuntimeException('RAG_VECTOR_STORE_UNAVAILABLE');
        }
        $alias = (string) ($target['collection_name'] ?? '') ?: $configService->collection((string) $dataset['dataset_id'], 'text');
        $physical = $alias . '_v' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(2));
        $jobs = $this->jobs ?? new RagEmbeddingJobService();
        $spool = storage_path('rag' . DIRECTORY_SEPARATOR . 'maintenance');
        if (!is_dir($spool)) {
            mkdir($spool, 0775, true);
        }
        $spoolFile = $spool . DIRECTORY_SEPARATOR . 'reindex-' . $dataset['dataset_id'] . '-' . bin2hex(random_bytes(4)) . '.jsonl';
        $handle = fopen($spoolFile, 'wb');
        $report = ['dataset_id' => (string) $dataset['dataset_id'], 'alias' => $alias, 'collection' => $physical, 'target_config_id' => (int) $target['id'], 'chunks' => 0, 'vectors' => 0, 'reused' => 0, 'gate' => null, 'switched' => false];
        try {
            foreach ($this->activeChunks($datasetId) as $chunk) {
                $report['chunks']++;
                foreach ($jobs->prepareChunkVectors($target, $dataset, $chunk, 'text') as $prepared) {
                    $store->upsert($physical, $prepared['vector_id'], $prepared['vector'], $prepared['payload']);
                    $store->upsertLexical($physical, $prepared['vector_id'], $prepared['lexical_text'], RagEmbeddingJobService::lexicalPayload($prepared['payload']));
                    fwrite($handle, json_encode(['vector_id' => $prepared['vector_id'], 'chunk_id' => (int) $chunk['id'], 'image_id' => $chunk['image_asset_id'] ?: null, 'vector' => $prepared['vector'], 'input_hash' => $prepared['input_hash']], JSON_UNESCAPED_SLASHES) . "\n");
                    $report['vectors']++;
                    $report['reused'] += $prepared['reused'] ? 1 : 0;
                }
            }
            fclose($handle);
            $handle = null;

            if ($report['chunks'] > 0 && $report['vectors'] === 0) {
                throw new RuntimeException('Reindex produced no vectors; the live index was left unchanged');
            }
            if (!$skipGate) {
                $report['gate'] = $this->gate($dataset, $current, $target, $physical, $maxRecallDrop, $maxMrrDrop);
                if (($report['gate']['passed'] ?? false) !== true) {
                    $store->deleteCollection($physical);
                    $store->deleteCollection($physical . QdrantVectorStore::LEXICAL_SUFFIX);
                    return $report;
                }
            }
            if ($dryRun) {
                $store->deleteCollection($physical);
                $store->deleteCollection($physical . QdrantVectorStore::LEXICAL_SUFFIX);
                return $report;
            }

            $previous = $store->aliases()[$alias] ?? null;
            $store->swapAlias($alias, $physical);
            App::db()->transaction(function () use ($spoolFile, $dataset, $target, $alias, $datasetId, $targetConfigId): void {
                $read = fopen($spoolFile, 'rb');
                while (($line = fgets($read)) !== false) {
                    $row = json_decode($line, true);
                    if (!is_array($row)) {
                        continue;
                    }
                    App::db()->execute(
                        'INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, image_id, vector_config_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, "text", ?, ?, ?, "indexed", "indexed", NOW())
                         ON DUPLICATE KEY UPDATE collection_name = VALUES(collection_name), vector_config_id = VALUES(vector_config_id), dimension = VALUES(dimension), vector_json = VALUES(vector_json), content_hash = VALUES(content_hash), index_status = "indexed", status = "indexed", indexed_at = NOW(), deleted_at = NULL',
                        [$row['vector_id'], $alias, $dataset['kb_id'], $datasetId, $row['chunk_id'], $row['image_id'], $target['id'], count($row['vector']), json_encode($row['vector'], JSON_UNESCAPED_SLASHES), $row['input_hash']]
                    );
                }
                fclose($read);
                if ($targetConfigId !== null) {
                    App::db()->execute('UPDATE rag_vector_datasets SET text_vector_config_id = ? WHERE id = ?', [$targetConfigId, $datasetId]);
                }
                App::db()->execute('UPDATE rag_vector_datasets SET lexical_index_status = "ready", last_indexed_at = NOW() WHERE id = ?', [$datasetId]);
            });
            if ($previous !== null && $previous !== $physical) {
                $store->deleteCollection($previous);
                $store->deleteCollection($previous . QdrantVectorStore::LEXICAL_SUFFIX);
            }
            if ($targetConfigId !== null && (int) ($current['id'] ?? 0) > 0 && (string) ($current['collection_name'] ?? '') !== $alias) {
                // Old model's points for this dataset are no longer used.
                $this->qdrant()->deleteByFilter((string) $current['collection_name'], ['dataset_id' => (string) $dataset['dataset_id']]);
            }
            $report['switched'] = true;
            (new AuditService())->write('rag.index.reindex', 'rag_vector_datasets', $datasetId, $report);

            return $report;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($spoolFile);
        }
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $current
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function gate(array $dataset, array $current, array $target, string $candidateCollection, float $maxRecallDrop, float $maxMrrDrop): array
    {
        $cases = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_eval_cases WHERE dataset_id = ? AND status = "active"', [(int) $dataset['id']])['c'] ?? 0);
        if ($cases === 0) {
            return ['passed' => true, 'skipped' => 'no active evaluation cases for this dataset', 'cases' => 0];
        }
        $evaluate = function (array $override) use ($dataset): array {
            $ranker = static function (array $case, int $topK) use ($dataset, $override): array {
                return (new RagRetrievalService())->searchSourcesForDatasets([[...$dataset, ...$override]], (string) $case['query'], $topK, (string) ($case['query_modality'] ?? 'text'));
            };
            $service = is_callable($this->evaluator) ? ($this->evaluator)($ranker) : new RagRetrievalEvaluationService($ranker);

            return $service->run(['dataset_ids' => [(string) $dataset['dataset_id']], 'top_k_values' => [5, 10], 'min_recall_at_5' => 0, 'min_mrr' => 0]);
        };
        $baseline = $evaluate([]);
        $candidate = $evaluate(['_text_config_override' => $target, '_collection_override' => $candidateCollection]);
        $baseRecall = (float) ($baseline['recall_at_5'] ?? 0);
        $candidateRecall = (float) ($candidate['recall_at_5'] ?? 0);
        $passed = $candidateRecall >= $baseRecall - $maxRecallDrop && (float) $candidate['mrr'] >= (float) $baseline['mrr'] - $maxMrrDrop;

        return [
            'passed' => $passed,
            'cases' => $cases,
            'baseline' => ['run_id' => $baseline['run_id'] ?? null, 'recall_at_5' => $baseRecall, 'mrr' => (float) $baseline['mrr']],
            'candidate' => ['run_id' => $candidate['run_id'] ?? null, 'recall_at_5' => $candidateRecall, 'mrr' => (float) $candidate['mrr']],
            'max_recall_drop' => $maxRecallDrop,
            'max_mrr_drop' => $maxMrrDrop,
        ];
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function activeChunks(int $datasetId): \Generator
    {
        $lastId = 0;
        do {
            $rows = App::db()->select(
                'SELECT c.*, doc.title AS document_title FROM rag_chunks c JOIN rag_documents doc ON doc.id = c.document_id JOIN rag_uploads u ON u.id = doc.upload_id
                 JOIN rag_vector_datasets dset ON dset.id = COALESCE(c.dataset_id, doc.dataset_id, u.dataset_id)
                 WHERE dset.id = ? AND c.enabled = 1 AND c.lifecycle_status = "active" AND doc.deleted_at IS NULL AND c.id > ?
                 ORDER BY c.id LIMIT 200',
                [$datasetId, $lastId]
            );
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                yield $row;
            }
        } while (count($rows) === 200);
    }

    /** @return array<string, mixed> */
    private function dataset(int $datasetId): array
    {
        $dataset = App::db()->first('SELECT d.*, k.kb_id AS external_kb_id, k.chunking_profile AS kb_chunking_profile, k.retrieval_profile AS kb_retrieval_profile FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = ? AND d.deleted_at IS NULL', [$datasetId]);
        if ($dataset === null) {
            throw new RuntimeException('RAG_DATASET_NOT_FOUND');
        }

        return $dataset;
    }

    /** @param array<string, mixed> $dataset @return list<string> */
    private function datasetCollections(array $dataset): array
    {
        $configService = $this->configs ?? new RagVectorConfigService();
        $collections = [];
        foreach ([$configService->textConfig($dataset), $configService->imageConfig($dataset), $configService->multimodalConfig($dataset)] as $config) {
            if ($config !== null && (string) ($config['vector_store'] ?? '') === 'qdrant' && !empty($config['collection_name'])) {
                $collections[] = (string) $config['collection_name'];
            }
        }

        return array_values(array_unique($collections));
    }

    private function qdrant(): QdrantVectorStore
    {
        $store = ($this->configs ?? new RagVectorConfigService())->store(['vector_store' => 'qdrant']);
        if (!$store instanceof QdrantVectorStore) {
            throw new RuntimeException('RAG_VECTOR_STORE_UNAVAILABLE');
        }

        return $store;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function totals(array $rows): array
    {
        $chunks = array_sum(array_column($rows, 'active_chunks'));
        $indexed = array_sum(array_column($rows, 'indexed_chunks'));

        return [
            'datasets' => count($rows),
            'active_chunks' => $chunks,
            'indexed_chunks' => $indexed,
            'coverage_percent' => $chunks === 0 ? 100.0 : round($indexed * 100 / $chunks, 2),
            'stale_vectors' => array_sum(array_column($rows, 'stale_vectors')),
            'failed_jobs' => array_sum(array_column($rows, 'failed_jobs')),
            'lexical_pending' => count(array_filter($rows, static fn (array $row): bool => $row['lexical_index_status'] !== 'ready' && $row['vector_store'] === 'qdrant')),
        ];
    }

    /** @param array<string, mixed> $report */
    private function storeReport(string $name, array $report): void
    {
        $dir = storage_path('rag' . DIRECTORY_SEPARATOR . 'maintenance');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . DIRECTORY_SEPARATOR . $name, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed>|null */
    private function lastReconcile(): ?array
    {
        $file = storage_path('rag' . DIRECTORY_SEPARATOR . 'maintenance' . DIRECTORY_SEPARATOR . 'last-reconcile.json');
        if (!is_file($file)) {
            return null;
        }
        $report = json_decode((string) file_get_contents($file), true);

        return is_array($report) ? ['finished_at' => $report['finished_at'] ?? null, 'orphans' => $report['orphans'] ?? 0, 'missing' => $report['missing'] ?? 0, 'fix' => $report['fix'] ?? false] : null;
    }
}
