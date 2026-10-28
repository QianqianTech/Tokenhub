<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\SchemaInspector;

/**
 * Periodic usage consistency checks (queue job "usage.reconcile").
 *
 * 1. Resumes usage rows stuck between persistence stages.
 * 2. Compares day aggregates with the detail rows of the window and reports
 *    any bucket whose request/token counts drifted (the operator can then run
 *    UsageService::rebuildAggregates for that range via bin/usage-reconcile.php).
 */
final class UsageReconciliationService
{
    /**
     * @return array{resumed: int, drift: list<array<string, mixed>>}
     */
    public function reconcileRecent(int $minutes = 60): array
    {
        $usage = new UsageService();
        $resumed = $usage->resumePending();
        $drift = $this->drift(gmdate('Y-m-d H:00:00', time() - max(1, $minutes) * 60), gmdate('Y-m-d H:i:s'));
        if ($drift !== []) {
            App::logger()->warning('usage', 'usage aggregate drift detected', ['buckets' => count($drift)]);
        }

        return ['resumed' => $resumed, 'drift' => $drift];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function drift(string $from, string $to): array
    {
        if (!SchemaInspector::hasColumn('usage_records', 'aggregated_at')) {
            return [];
        }
        $detail = App::db()->select(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') bucket_at, COUNT(*) request_count, SUM(input_tokens) input_tokens, SUM(output_tokens) output_tokens
             FROM usage_records WHERE created_at >= ? AND created_at < ? AND aggregated_at IS NOT NULL GROUP BY bucket_at",
            [$from, $to]
        );
        $aggregates = App::db()->select(
            'SELECT bucket_at, SUM(request_count) request_count, SUM(input_tokens) input_tokens, SUM(output_tokens) output_tokens FROM usage_aggregates_hour WHERE bucket_at >= ? AND bucket_at < ? GROUP BY bucket_at',
            [$from, $to]
        );
        $byBucket = [];
        foreach ($aggregates as $row) {
            $byBucket[(string) $row['bucket_at']] = $row;
        }
        $drift = [];
        foreach ($detail as $row) {
            $aggregate = $byBucket[(string) $row['bucket_at']] ?? ['request_count' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
            foreach (['request_count', 'input_tokens', 'output_tokens'] as $metric) {
                if ((int) $row[$metric] !== (int) $aggregate[$metric]) {
                    $drift[] = ['bucket_at' => $row['bucket_at'], 'metric' => $metric, 'detail' => (int) $row[$metric], 'aggregate' => (int) $aggregate[$metric]];
                }
            }
        }

        return $drift;
    }
}
