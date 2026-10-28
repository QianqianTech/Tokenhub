<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagRetrievalQualityService
{
    public function __construct(private readonly int $sampleCapacity = 5000)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(array $actor, int $windowHours = 24, float $lowConfidence = 0.35): array
    {
        $windowHours = max(1, min(720, $windowHours));
        $lowConfidence = max(0.0, min(1.0, $lowConfidence));
        $capacity = max(1, min(10000, $this->sampleCapacity));
        [$scopeWhere, $bindings] = (new PermissionService())->tableScopeSql($actor, 'rag_retrieval_logs');
        $rows = App::db()->select(
            'SELECT retrieval_id, dataset_ids, query, hit_count, fallback_used, sources, latency_ms, created_at, id
             FROM rag_retrieval_logs
             ' . ($scopeWhere === '' ? '' : $scopeWhere) . '
             ORDER BY id DESC
             LIMIT ' . ($capacity + 1),
            $bindings
        );
        $truncated = count($rows) > $capacity;
        if ($truncated) {
            array_pop($rows);
        }
        $cutoff = time() - ($windowHours * 3600);
        $rows = array_values(array_filter($rows, static function (array $row) use ($cutoff): bool {
            $createdAt = strtotime((string) ($row['created_at'] ?? ''));
            return $createdAt !== false && $createdAt >= $cutoff;
        }));

        $total = count($rows);
        $noHitCount = 0;
        $fallbackCount = 0;
        $lowConfidenceCount = 0;
        $latencies = [];
        $problematic = [];

        foreach ($rows as $row) {
            $noHit = (int) ($row['hit_count'] ?? 0) === 0;
            $fallback = (int) ($row['fallback_used'] ?? 0) === 1;
            $low = !$noHit && $this->isLowConfidence((string) ($row['sources'] ?? '[]'), $lowConfidence);
            if ($noHit) {
                $noHitCount++;
            }
            if ($fallback) {
                $fallbackCount++;
            }
            if ($low) {
                $lowConfidenceCount++;
            }
            $latencies[] = max(0, (int) ($row['latency_ms'] ?? 0));

            if (($noHit || $fallback || $low) && count($problematic) < 20) {
                $reasons = [];
                if ($noHit) {
                    $reasons[] = 'no_hit';
                }
                if ($fallback) {
                    $reasons[] = 'fallback';
                }
                if ($low) {
                    $reasons[] = 'low_confidence';
                }
                $problematic[] = [
                    'retrieval_id' => (string) ($row['retrieval_id'] ?? ''),
                    'dataset_ids' => $this->stringList((string) ($row['dataset_ids'] ?? '[]')),
                    'query' => (string) ($row['query'] ?? ''),
                    'reasons' => $reasons,
                ];
            }
        }

        sort($latencies, SORT_NUMERIC);
        $latencyCount = count($latencies);
        $average = $latencyCount === 0 ? 0.0 : round(array_sum($latencies) / $latencyCount, 2);
        $p95 = $latencyCount === 0 ? 0 : $latencies[(int) ceil($latencyCount * 0.95) - 1];

        return [
            'window_hours' => $windowHours,
            'low_confidence_threshold' => $lowConfidence,
            'metrics_scope' => 'latest_sample',
            'capacity' => $capacity,
            'sampled' => $total,
            'truncated' => $truncated,
            'total' => $total,
            'no_hit' => $this->metric($noHitCount, $total),
            'fallback' => $this->metric($fallbackCount, $total),
            'low_confidence' => $this->metric($lowConfidenceCount, $total),
            'latency_ms' => [
                'average' => $average,
                'p95' => $p95,
            ],
            'problematic_queries' => $problematic,
        ];
    }

    /**
     * @return array{count: int, rate: float}
     */
    private function metric(int $count, int $total): array
    {
        return [
            'count' => $count,
            'rate' => $total === 0 ? 0.0 : round($count / $total, 4),
        ];
    }

    private function isLowConfidence(string $sourcesJson, float $threshold): bool
    {
        $sources = json_decode($sourcesJson, true);
        if (!is_array($sources)) {
            return false;
        }

        $confidence = [];
        foreach ($sources as $source) {
            if (!is_array($source) || !isset($source['confidence_score']) || !is_numeric($source['confidence_score'])) {
                continue;
            }
            $confidence[] = (float) $source['confidence_score'];
        }

        return $confidence !== [] && max($confidence) < $threshold;
    }

    /**
     * @return list<string>
     */
    private function stringList(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $decoded), static fn (string $value): bool => $value !== ''));
    }
}
