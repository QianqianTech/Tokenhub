<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagRetrievalEvaluationService
{
    private ?\Closure $sourceRanker;
    private bool $throwOnNoCases = false;

    public function __construct(?callable $sourceRanker = null)
    {
        $this->sourceRanker = $sourceRanker === null ? null : \Closure::fromCallable($sourceRanker);
    }

    /**
     * @param array<string, mixed> $retrieval
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function createCandidateFromFeedback(string $feedbackId, array $retrieval, array $payload = []): ?array
    {
        $kbIds = $this->jsonList($retrieval['kb_ids'] ?? '[]');
        $datasetIds = $this->jsonList($retrieval['dataset_ids'] ?? '[]');
        $kbId = $kbIds[0] ?? null;
        $datasetId = $datasetIds[0] ?? null;
        if ($kbId === null || $datasetId === null) {
            return null;
        }
        $kb = App::db()->first('SELECT * FROM rag_knowledge_bases WHERE kb_id = ?', [$kbId]);
        $dataset = App::db()->first('SELECT * FROM rag_vector_datasets WHERE dataset_id = ?', [$datasetId]);
        if ($kb === null || $dataset === null) {
            return null;
        }

        $sources = json_decode((string) ($retrieval['sources'] ?? '[]'), true) ?: [];
        $expected = [];
        foreach ($sources as $source) {
            if (is_array($source) && isset($source['chunk_id']) && is_scalar($source['chunk_id'])) {
                $expected[] = (string) $source['chunk_id'];
            }
        }
        $caseId = 'eval_' . bin2hex(random_bytes(12));
        App::db()->execute(
            'INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, query_modality, expected_chunk_ids, status, source_feedback_id, metadata) VALUES (?, ?, ?, ?, ?, ?, "draft", ?, ?)',
            [
                $caseId,
                $kb['id'],
                $dataset['id'],
                $retrieval['query'] ?? '',
                $retrieval['query_modality'] ?? 'text',
                json_encode(array_values(array_unique($expected)), JSON_UNESCAPED_UNICODE),
                $feedbackId,
                json_encode([
                    'retrieval_id' => $retrieval['retrieval_id'] ?? null,
                    'expected_answer' => $payload['expected_answer'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
            ]
        );

        return ['case_id' => $caseId];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $windowHours = 24, ?string $runId = null): array
    {
        $totals = App::db()->first(
            'SELECT
                COUNT(*) AS total_cases,
                SUM(status = "active") AS active_cases,
                SUM(status = "draft") AS draft_cases
             FROM rag_eval_cases'
        ) ?? [];
        $runWhere = 'created_at >= DATE_SUB(NOW(), INTERVAL ' . max(1, $windowHours) . ' HOUR)';
        $runParams = [];
        if ($runId !== null && $runId !== '') {
            $runWhere .= ' AND run_id = ?';
            $runParams[] = $runId;
        }
        $latest = App::db()->select(
            'SELECT run_id, case_count, recall_at_1, recall_at_5, recall_at_10, recall_at_20, mrr, failed_cases, status, metadata, created_at
             FROM rag_eval_runs WHERE ' . $runWhere . ' ORDER BY id DESC LIMIT 10',
            $runParams
        );
        $latest = array_map(function (array $run): array {
            $metadata = json_decode((string) ($run['metadata'] ?? '{}'), true);
            $metrics = is_array($metadata) && is_array($metadata['metrics'] ?? null) ? $metadata['metrics'] : [];
            foreach (['recall_at_1', 'recall_at_5', 'recall_at_10', 'recall_at_20', 'mrr', 'failed_cases'] as $metric) {
                if (array_key_exists($metric, $metrics) && $metrics[$metric] === null) {
                    $run[$metric] = null;
                }
            }

            return $run;
        }, $latest);
        $failures = App::db()->select(
            'SELECT r.run_id, c.case_id, c.query, er.hit_rank, er.created_at
             FROM rag_eval_results er
             JOIN rag_eval_runs r ON r.id = er.run_id
             JOIN rag_eval_cases c ON c.id = er.case_id
             WHERE er.passed = 0
             ORDER BY er.id DESC LIMIT 10'
        );

        return [
            'source' => 'rag_eval_runs',
            'total_cases' => (int) ($totals['total_cases'] ?? 0),
            'active_cases' => (int) ($totals['active_cases'] ?? 0),
            'draft_cases' => (int) ($totals['draft_cases'] ?? 0),
            'latest_run' => $latest[0] ?? null,
            'latest_runs' => $latest,
            'recent_failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $started = microtime(true);
        $datasetIds = $this->stringList($options['dataset_ids'] ?? []);
        $caseIds = $this->stringList($options['case_ids'] ?? []);
        $requestedTopKValues = $this->topKValues($options['top_k_values'] ?? [5, 10, 20]);
        $requestedMaxTopK = max($requestedTopKValues);
        $gateEvaluationK = in_array(5, $requestedTopKValues, true) ? [] : [5];
        $evaluationTopKValues = array_values(array_unique([...$requestedTopKValues, ...$gateEvaluationK]));
        sort($evaluationTopKValues);
        $maxTopK = max($evaluationTopKValues);
        $cases = $this->cases($datasetIds, $caseIds);
        if ($cases === []) {
            if ($this->throwOnNoCases) {
                throw new \RuntimeException('No active RAG evaluation cases matched the request.');
            }
            abort_json('RAG_EVAL_NO_CASES', 'No active RAG evaluation cases matched the request.', 422);
        }
        $runId = 'rer_' . bin2hex(random_bytes(12));
        $runPk = $this->createRun($runId, $datasetIds, $caseIds, $requestedTopKValues, count($cases));

        try {
            $hitsByTopK = array_fill_keys(array_map('strval', $evaluationTopKValues), 0);
            $requestedMrrTotal = 0.0;
            $requestedFailed = 0;
            $gateMrrTotal = 0.0;
            $gateFailed = 0;

            foreach ($cases as $case) {
            $expected = $this->jsonList($case['expected_chunk_ids'] ?? '[]');
            $sources = $this->rankSources($case, $maxTopK);
            $hitRank = null;
            foreach ($sources as $index => $source) {
                if (in_array((string) ($source['chunk_id'] ?? ''), $expected, true)) {
                    $hitRank = $index + 1;
                    break;
                }
            }
            foreach ($evaluationTopKValues as $topK) {
                if ($hitRank !== null && $hitRank <= $topK) {
                    $hitsByTopK[(string) $topK]++;
                }
            }
            $requestedHitRank = $hitRank !== null && $hitRank <= $requestedMaxTopK ? $hitRank : null;
            if ($requestedHitRank === null) {
                $requestedFailed++;
            } else {
                $requestedMrrTotal += 1 / $requestedHitRank;
            }
            if ($hitRank === null) {
                $gateFailed++;
            } else {
                $gateMrrTotal += 1 / $hitRank;
            }
            App::db()->execute(
                'INSERT INTO rag_eval_results (run_id, case_id, query, expected_chunk_ids, hit_rank, passed, sources) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $runPk,
                    $case['id'],
                    $case['query'],
                    json_encode($expected, JSON_UNESCAPED_UNICODE),
                    $hitRank,
                    $requestedHitRank === null ? 0 : 1,
                    json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            }

            $caseCount = max(1, count($cases));
            $metrics = [
                'recall_at_1' => in_array(1, $requestedTopKValues, true) ? $this->percent($hitsByTopK['1'] ?? 0, $caseCount) : null,
                'recall_at_5' => in_array(5, $requestedTopKValues, true) ? $this->percent($hitsByTopK['5'] ?? 0, $caseCount) : null,
                'recall_at_10' => in_array(10, $requestedTopKValues, true) ? $this->percent($hitsByTopK['10'] ?? 0, $caseCount) : null,
                'recall_at_20' => in_array(20, $requestedTopKValues, true) ? $this->percent($hitsByTopK['20'] ?? 0, $caseCount) : null,
                'mrr' => round($requestedMrrTotal / $caseCount, 4),
            ];
            $gateMetrics = [
                'recall_at_5' => $this->percent($hitsByTopK['5'] ?? 0, $caseCount),
                'mrr' => round($gateMrrTotal / $caseCount, 4),
                'failed_cases' => $gateFailed,
            ];
            $qualityGate = $this->qualityGate($gateMetrics, $options);
            $latencyMs = (int) ((microtime(true) - $started) * 1000);
            App::db()->execute(
                'UPDATE rag_eval_runs SET recall_at_1 = ?, recall_at_5 = ?, recall_at_10 = ?, recall_at_20 = ?, mrr = ?, failed_cases = ?, status = "succeeded", latency_ms = ?, metadata = ?, completed_at = NOW() WHERE id = ?',
                [
                    $this->databaseMetric($metrics['recall_at_1']),
                    $this->databaseMetric($metrics['recall_at_5']),
                    $this->databaseMetric($metrics['recall_at_10']),
                    $this->databaseMetric($metrics['recall_at_20']),
                    $metrics['mrr'],
                    $requestedFailed,
                    $latencyMs,
                    json_encode([
                        'case_ids' => $caseIds,
                        'metrics' => [...$metrics, 'failed_cases' => $requestedFailed],
                        'gate_evaluation_k' => $gateEvaluationK,
                        'gate_evaluation_metrics' => $gateMetrics,
                        ...$qualityGate,
                    ], JSON_UNESCAPED_UNICODE),
                    $runPk,
                ]
            );

            return [
                'run_id' => $runId,
                'case_count' => count($cases),
                'top_k_values' => $requestedTopKValues,
                ...$metrics,
                'gate_evaluation_k' => $gateEvaluationK,
                'gate_evaluation_metrics' => $gateMetrics,
                ...$qualityGate,
                'failed_cases' => $requestedFailed,
            ];
        } catch (\Throwable $error) {
            $this->failRun($runPk, $caseIds, (int) ((microtime(true) - $started) * 1000));
            throw $error;
        }
    }

    /**
     * Runs the evaluation with CLI-safe handling for an empty case selection.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function runCli(array $options = []): array
    {
        $this->throwOnNoCases = true;
        try {
            return $this->run($options);
        } finally {
            $this->throwOnNoCases = false;
        }
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $options
     * @return array{quality_gate_passed: bool, quality_gate_thresholds: array{recall_at_5: float, mrr: float}, quality_gate_violations: list<array{metric: string, actual: float, required: float}>}
     */
    public function qualityGate(array $metrics, array $options = []): array
    {
        $thresholds = [
            'recall_at_5' => $this->qualityThreshold($options['min_recall_at_5'] ?? null, 95.0, 100.0),
            'mrr' => $this->qualityThreshold($options['min_mrr'] ?? null, 0.8, 1.0),
        ];
        $violations = [];
        foreach ($thresholds as $metric => $required) {
            $actual = (float) ($metrics[$metric] ?? 0.0);
            if ($actual < $required) {
                $violations[] = [
                    'metric' => $metric,
                    'actual' => $actual,
                    'required' => $required,
                ];
            }
        }

        return [
            'quality_gate_passed' => $violations === [],
            'quality_gate_thresholds' => $thresholds,
            'quality_gate_violations' => $violations,
        ];
    }

    /**
     * @param list<string> $datasetIds
     * @param list<string> $caseIds
     * @return list<array<string, mixed>>
     */
    private function cases(array $datasetIds, array $caseIds): array
    {
        $where = ['c.status = "active"'];
        $params = [];
        if ($datasetIds !== []) {
            $where[] = 'd.dataset_id IN (' . implode(',', array_fill(0, count($datasetIds), '?')) . ')';
            array_push($params, ...$datasetIds);
        }
        if ($caseIds !== []) {
            $where[] = 'c.case_id IN (' . implode(',', array_fill(0, count($caseIds), '?')) . ')';
            array_push($params, ...$caseIds);
        }

        return App::db()->select(
            'SELECT c.*, d.dataset_id AS external_dataset_id, d.name AS dataset_name,
                    d.text_vector_config_id, d.image_vector_config_id, d.multimodal_vector_config_id,
                    k.kb_id AS external_kb_id, k.retrieval_profile AS kb_retrieval_profile
             FROM rag_eval_cases c
             JOIN rag_vector_datasets d ON d.id = c.dataset_id
             JOIN rag_knowledge_bases k ON k.id = c.kb_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id',
            $params
        );
    }

    /**
     * @param array<string, mixed> $case
     * @return list<array<string, mixed>>
     */
    private function rankSources(array $case, int $topK): array
    {
        if ($this->sourceRanker !== null) {
            return ($this->sourceRanker)($case, $topK);
        }

        $dataset = [
            'id' => (int) $case['dataset_id'],
            'dataset_id' => (string) ($case['external_dataset_id'] ?? ''),
            'name' => (string) ($case['dataset_name'] ?? ''),
            'kb_id' => (int) $case['kb_id'],
            'external_kb_id' => (string) ($case['external_kb_id'] ?? ''),
            'text_vector_config_id' => $case['text_vector_config_id'] ?? null,
            'image_vector_config_id' => $case['image_vector_config_id'] ?? null,
            'multimodal_vector_config_id' => $case['multimodal_vector_config_id'] ?? null,
            'kb_retrieval_profile' => $case['kb_retrieval_profile'] ?? null,
        ];

        return (new RagRetrievalService())->searchSourcesForDatasets(
            [$dataset],
            (string) ($case['query'] ?? ''),
            max(1, $topK),
            (string) ($case['query_modality'] ?? 'text')
        );
    }

    /**
     * @param list<string> $datasetIds
     * @param list<string> $caseIds
     * @param list<int> $topKValues
     */
    private function createRun(string $runId, array $datasetIds, array $caseIds, array $topKValues, int $caseCount): int
    {
        App::db()->execute(
            'INSERT INTO rag_eval_runs (run_id, dataset_ids, top_k_values, case_count, status, metadata) VALUES (?, ?, ?, ?, "running", ?)',
            [
                $runId,
                json_encode($datasetIds, JSON_UNESCAPED_UNICODE),
                json_encode($topKValues),
                $caseCount,
                json_encode(['case_ids' => $caseIds], JSON_UNESCAPED_UNICODE),
            ]
        );

        return (int) App::db()->pdo()->lastInsertId();
    }

    /**
     * @param list<string> $caseIds
     */
    private function failRun(int $runPk, array $caseIds, int $latencyMs): void
    {
        try {
            App::db()->execute(
                'UPDATE rag_eval_runs SET status = "failed", latency_ms = ?, metadata = ?, completed_at = NOW() WHERE id = ?',
                [
                    $latencyMs,
                    json_encode([
                        'case_ids' => $caseIds,
                        'error_code' => 'RAG_EVAL_RUN_FAILED',
                        'metrics' => [
                            'recall_at_1' => null,
                            'recall_at_5' => null,
                            'recall_at_10' => null,
                            'recall_at_20' => null,
                            'mrr' => null,
                            'failed_cases' => null,
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    $runPk,
                ]
            );
        } catch (\Throwable) {
            // The original evaluation exception remains the actionable failure.
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)));
    }

    /**
     * @return list<int>
     */
    private function topKValues(mixed $value): array
    {
        $items = is_array($value) ? $value : [5, 10, 20];
        $values = array_values(array_unique(array_filter(array_map(static fn (mixed $item): int => max(1, min(20, (int) $item)), $items))));
        sort($values);

        return $values === [] ? [5, 10, 20] : $values;
    }

    /**
     * @return list<string>
     */
    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $decoded)));
    }

    private function percent(int $hits, int $total): float
    {
        return round(($hits / max(1, $total)) * 100, 2);
    }

    private function qualityThreshold(mixed $value, float $default, float $maximum): float
    {
        if ($value === null) {
            return $default;
        }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > $maximum) {
            throw new \InvalidArgumentException('Quality gate threshold is outside the permitted range.');
        }

        return (float) $value;
    }

    private function databaseMetric(?float $metric): float
    {
        return $metric ?? 0.0;
    }
}
