<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\BusinessTime;
use TokenHub\Support\Decimal;
use TokenHub\Support\SchemaInspector;

/**
 * Periodic usage consistency checks (queue job "usage.reconcile").
 *
 * 1. Resumes usage rows stuck between persistence stages.
 * 2. Compares hour aggregates with the detail rows of the window and reports
 *    any bucket whose request/token counts or base-currency cost drifted (the
 *    operator can then run UsageService::rebuildAggregates for that range via
 *    bin/usage-reconcile.php). checkDriftAndAlert() raises "usage_drift".
 * 3. rebuildQuotaCounters() recomputes the Redis quota counters of the
 *    current period from usage_records (after a Redis loss or flush).
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
        $base = (new UsageService())->baseCurrency();
        $hasCurrency = SchemaInspector::hasColumn('usage_records', 'cost_currency');
        $costExpr = $hasCurrency
            ? 'SUM(CASE WHEN cost_amount IS NOT NULL AND (cost_currency IS NULL OR cost_currency = ?) THEN cost_amount END)'
            : 'SUM(cost_amount)';
        $detail = App::db()->select(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') bucket_at, COUNT(*) request_count, SUM(input_tokens) input_tokens, SUM(output_tokens) output_tokens, {$costExpr} total_cost
             FROM usage_records WHERE created_at >= ? AND created_at < ? AND aggregated_at IS NOT NULL GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')",
            $hasCurrency ? [$base, $from, $to] : [$from, $to]
        );
        $aggregates = App::db()->select(
            'SELECT bucket_at, SUM(request_count) request_count, SUM(input_tokens) input_tokens, SUM(output_tokens) output_tokens, SUM(total_cost) total_cost FROM usage_aggregates_hour WHERE bucket_at >= ? AND bucket_at < ? GROUP BY bucket_at',
            [$from, $to]
        );

        return $this->compare($detail, $aggregates);
    }

    /**
     * Bucket-by-bucket comparison of detail sums and aggregate sums.
     * Costs are compared as decimals (tolerance 0.000001).
     *
     * @param list<array<string, mixed>> $detail
     * @param list<array<string, mixed>> $aggregates
     * @return list<array<string, mixed>>
     */
    public function compare(array $detail, array $aggregates): array
    {
        $byBucket = [];
        foreach ($aggregates as $row) {
            $byBucket[$this->bucketKey($row['bucket_at'] ?? '')] = $row;
        }
        $drift = [];
        foreach ($detail as $row) {
            $aggregate = $byBucket[$this->bucketKey($row['bucket_at'] ?? '')] ?? ['request_count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_cost' => null];
            foreach (['request_count', 'input_tokens', 'output_tokens'] as $metric) {
                if ((int) ($row[$metric] ?? 0) !== (int) ($aggregate[$metric] ?? 0)) {
                    $drift[] = ['bucket_at' => $row['bucket_at'], 'metric' => $metric, 'detail' => (int) ($row[$metric] ?? 0), 'aggregate' => (int) ($aggregate[$metric] ?? 0)];
                }
            }
            if (array_key_exists('total_cost', $row)) {
                $detailCost = Decimal::normalize($row['total_cost'] ?? null) ?? '0';
                $aggregateCost = Decimal::normalize($aggregate['total_cost'] ?? null) ?? '0';
                $difference = Decimal::sub($detailCost, $aggregateCost);
                if (Decimal::compare(ltrim((string) $difference, '-'), '0.000001') > 0) {
                    $drift[] = ['bucket_at' => $row['bucket_at'], 'metric' => 'total_cost', 'detail' => $detailCost, 'aggregate' => $aggregateCost];
                }
            }
        }

        return $drift;
    }

    /**
     * BIZ-010: scheduled "usage.drift-check". Checks the last $hours (the
     * newest 10 minutes are skipped: still being aggregated) and raises or
     * resolves the "usage_drift" alert.
     *
     * @return array{buckets: int, drift: list<array<string, mixed>>}
     */
    public function checkDriftAndAlert(int $hours = 24, ?AlertService $alerts = null): array
    {
        $to = time() - 600;
        $from = $to - max(1, $hours) * 3600;
        $drift = $this->drift(gmdate('Y-m-d H:00:00', $from), gmdate('Y-m-d H:00:00', $to));
        $alerts ??= new AlertService();
        if ($drift !== []) {
            $buckets = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['bucket_at'], $drift)));
            $alerts->record('usage_drift', 'warning', 'Usage aggregates differ from usage records', [
                'buckets' => count($buckets),
                'metrics' => array_values(array_unique(array_column($drift, 'metric'))),
                'first_bucket' => $buckets[0] ?? null,
                'hint' => 'php bin/usage-reconcile.php --rebuild --from=... --to=...',
            ]);
        }
        $alerts->resolveCleared(['usage_drift']);

        return ['buckets' => count(array_unique(array_column($drift, 'bucket_at'))), 'drift' => $drift];
    }

    /**
     * BIZ-010: recomputes the Redis quota counters of the current period from
     * usage_records, for every active quota binding (or one policy). Used
     * after Redis lost its data: without it every quota would restart at 0.
     * Minute policies are skipped (they expire within a minute anyway).
     *
     * @return array{policies: int, counters: int, dry_run: bool, values: list<array<string, mixed>>}
     */
    public function rebuildQuotaCounters(?int $policyId = null, bool $dryRun = false, ?int $now = null): array
    {
        $now ??= time();
        $rows = App::db()->select(
            'SELECT p.id, p.period, b.target_type, b.target_id FROM quota_policies p JOIN quota_bindings b ON b.quota_policy_id = p.id
             WHERE p.status = "active" AND p.period <> "minute"' . ($policyId !== null ? ' AND p.id = ?' : '') . ' ORDER BY p.id',
            $policyId !== null ? [$policyId] : []
        );
        // Same unit as QuotaService::recordUsage: the cost counter adds every
        // charged cost_amount (micro-units), whatever its currency.
        $quota = new QuotaService();
        $values = [];
        $counters = 0;
        foreach ($rows as $row) {
            $column = match ((string) $row['target_type']) {
                'user' => 'user_id',
                'department' => 'department_id',
                default => 'api_key_id',
            };
            $period = (string) $row['period'];
            $start = $period === 'hour' ? gmdate('Y-m-d H:00:00', $now) : BusinessTime::periodStartUtc($period, $now);
            $sums = App::db()->first(
                "SELECT COUNT(*) request_count, COALESCE(SUM(input_tokens), 0) input_tokens, COALESCE(SUM(output_tokens), 0) output_tokens, COALESCE(SUM(total_tokens), 0) total_tokens,
                        COALESCE(SUM(audio_seconds), 0) audio_seconds, COALESCE(SUM(tts_chars), 0) tts_chars, COALESCE(SUM(image_count), 0) image_count, COALESCE(SUM(video_seconds), 0) video_seconds,
                        COALESCE(SUM(cost_amount), 0) cost_amount
                 FROM usage_records WHERE {$column} = ? AND created_at >= ? AND created_at <= ?",
                [(int) $row['target_id'], $start, gmdate('Y-m-d H:i:s', $now)]
            ) ?? [];
            foreach (['request_count', 'input_tokens', 'output_tokens', 'total_tokens', 'audio_seconds', 'tts_chars', 'image_count', 'video_seconds', 'cost_amount'] as $metric) {
                $counter = $quota->usageCounter((int) $row['id'], (string) $row['target_type'], (int) $row['target_id'], $period, $metric, $now);
                $value = $metric === 'cost_amount'
                    ? QuotaService::toMicro((string) ($sums['cost_amount'] ?? '0'))
                    : (int) ($sums[$metric] ?? 0);
                $values[] = ['key' => $counter['key'], 'value' => $value];
                if (!$dryRun) {
                    App::redis()->set($counter['key'], (string) $value, max(60, (int) $counter['ttl']));
                }
                $counters++;
            }
        }
        if (!$dryRun) {
            App::logger()->warning('quota', 'quota counters rebuilt from usage_records', ['policies' => count($rows), 'counters' => $counters]);
        }

        return ['policies' => count($rows), 'counters' => $counters, 'dry_run' => $dryRun, 'values' => $values];
    }

    private function bucketKey(mixed $bucket): string
    {
        $time = strtotime((string) $bucket . ' UTC');

        return $time === false ? (string) $bucket : gmdate('Y-m-d H:i:s', $time);
    }
}
