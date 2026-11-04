<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\BusinessTime;
use TokenHub\Support\SchemaInspector;

final class DashboardService
{
    public function realtime(array $query = []): array
    {
        $row = $this->aggregateAcross(
            "SELECT COALESCE(SUM(request_count),0) request_count,
                    COALESCE(SUM(input_tokens),0) input_tokens,
                    COALESCE(SUM(output_tokens),0) output_tokens,
                    COALESCE(SUM(cache_read_tokens),0) cache_read_tokens,
                    COALESCE(SUM(cache_write_tokens),0) cache_write_tokens,
                    COALESCE(SUM(success_count),0) success_count,
                    COALESCE(SUM(failed_count),0) failed_count,
                    COALESCE(SUM(total_cost),0) total_cost,
                    COALESCE(SUM(latency_ms_sum),0) latency_ms_sum
             FROM {table}{where}",
            $query,
            [],
            ['request_count', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'success_count', 'failed_count', 'total_cost', 'latency_ms_sum'],
            App::context()->adminUser()
        )[0] ?? [];
        $row['total_tokens'] = (int) ($row['input_tokens'] ?? 0) + (int) ($row['output_tokens'] ?? 0);
        $row['cache_tokens'] = (int) ($row['cache_read_tokens'] ?? 0) + (int) ($row['cache_write_tokens'] ?? 0);
        $row['avg_latency_ms'] = (int) ($row['request_count'] ?? 0) > 0
            ? round(((float) ($row['latency_ms_sum'] ?? 0)) / (int) $row['request_count'], 2)
            : 0;

        return [
            'today' => $row,
            'business_timezone' => BusinessTime::name(),
            'period_start' => $this->periodStartIso($query),
            'generated_at' => gmdate('c'),
        ];
    }

    public function trends(array $query): array
    {
        $requestedBucket = (string) ($query['bucket'] ?? 'hour');
        $bucket = in_array($requestedBucket, ['hour', 'day'], true) ? $requestedBucket : 'hour';
        [$where, $bindings] = $this->aggregateWhere($query, $bucket, App::context()->adminUser());
        $table = $bucket === 'day' ? 'usage_aggregates_day' : 'usage_aggregates_hour';

        return App::db()->select(
            "SELECT bucket_at,
                    SUM(request_count) request_count,
                    SUM(success_count) success_count,
                    SUM(failed_count) failed_count,
                    SUM(input_tokens) input_tokens,
                    SUM(output_tokens) output_tokens,
                    SUM(cache_read_tokens) cache_read_tokens,
                    SUM(cache_write_tokens) cache_write_tokens,
                    SUM(cache_read_tokens + cache_write_tokens) cache_tokens,
                    SUM(total_cost) total_cost,
                    SUM(latency_ms_sum) latency_ms_sum
             FROM {$table}{$where}
             GROUP BY bucket_at
             ORDER BY bucket_at DESC
             LIMIT 48",
            $bindings
        );
    }

    public function rankings(array $query): array
    {
        $requestedDimension = (string) ($query['dimension'] ?? 'model');
        $dimension = in_array($requestedDimension, ['department', 'user', 'api_key', 'model', 'provider'], true) ? $requestedDimension : 'model';
        $column = match ($dimension) {
            'department' => 'department_id',
            'user' => 'user_id',
            'api_key' => 'api_key_id',
            'provider' => 'provider_id',
            default => 'model_id',
        };
        $requestedSort = (string) ($query['sort'] ?? 'request_count');
        $sort = in_array($requestedSort, ['request_count', 'total_tokens', 'modal_count', 'error_count'], true)
            ? $requestedSort
            : 'request_count';
        $sortColumn = $sort === 'modal_count' ? 'modal_count' : $sort;
        $rows = $this->aggregateAcross(
            "SELECT {$column} AS id,
                    SUM(request_count) request_count,
                    SUM(input_tokens + output_tokens) total_tokens,
                    SUM(cache_read_tokens + cache_write_tokens) cache_tokens,
                    SUM(CASE WHEN capability <> 'text_generation' THEN request_count ELSE 0 END) modal_count,
                    SUM(failed_count) error_count,
                    SUM(total_cost) total_cost
             FROM {table}{where}
             GROUP BY {$column}{tail}",
            $query,
            ['id'],
            ['request_count', 'total_tokens', 'cache_tokens', 'modal_count', 'error_count', 'total_cost'],
            App::context()->adminUser(),
            $sortColumn,
            20
        );

        return $this->withRankingNames($rows, $dimension);
    }

    /**
     * Aggregates only carry ids; rankings are read by people, so each row
     * also gets the department / user / key / model / provider name.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withRankingNames(array $rows, string $dimension): array
    {
        [$table, $nameColumn] = match ($dimension) {
            'department' => ['departments', 'name'],
            'user' => ['users', 'username'],
            'api_key' => ['api_keys', 'name'],
            'provider' => ['providers', 'name'],
            default => ['models', 'name'],
        };
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return $rows;
        }
        try {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $names = [];
            foreach (App::db()->select("SELECT id, {$nameColumn} AS name FROM {$table} WHERE id IN ({$placeholders})", $ids) as $named) {
                $names[(int) $named['id']] = (string) ($named['name'] ?? '');
            }
        } catch (\Throwable) {
            return $rows;
        }

        return array_map(static function (array $row) use ($names): array {
            $id = (int) ($row['id'] ?? 0);
            $row['name'] = $names[$id] ?? null;
            return $row;
        }, $rows);
    }

    public function security(array $query): array
    {
        [$where, $bindings] = $this->timeWhere($query, 'created_at');
        return [
            'desensitization' => App::db()->select(
                "SELECT action, SUM(match_count) match_count, COUNT(*) event_count FROM desensitization_events{$where} GROUP BY action ORDER BY event_count DESC LIMIT 20",
                $bindings
            ),
            'audit' => App::db()->select(
                "SELECT action, COUNT(*) event_count FROM audit_logs{$where} GROUP BY action ORDER BY event_count DESC LIMIT 20",
                $bindings
            ),
        ];
    }

    public function performance(array $query): array
    {
        [$where, $bindings] = $this->aggregateWhere($query, 'hour', App::context()->adminUser());
        // p95/p99 need the histogram columns; they were not selected before,
        // which made every percentile read 0.
        $bucketColumns = ['latency_bucket_100', 'latency_bucket_300', 'latency_bucket_1000', 'latency_bucket_3000', 'latency_bucket_over_3000'];
        if (\TokenHub\Support\SchemaInspector::hasColumn('usage_aggregates_hour', 'latency_bucket_over_120000')) {
            array_push($bucketColumns, 'latency_bucket_10000', 'latency_bucket_30000', 'latency_bucket_120000', 'latency_bucket_over_120000');
        }
        $bucketSql = implode(', ', array_map(static fn (string $column): string => "SUM({$column}) {$column}", $bucketColumns));
        $rows = App::db()->select(
            "SELECT bucket_at,
                    SUM(request_count) request_count,
                    SUM(failed_count) failed_count,
                    SUM(latency_ms_sum) latency_ms_sum,
                    {$bucketSql}
             FROM usage_aggregates_hour{$where}
             GROUP BY bucket_at
             ORDER BY bucket_at DESC
             LIMIT 48",
            $bindings
        );
        return [
            'buckets' => array_map(function (array $row): array {
                $count = (int) ($row['request_count'] ?? 0);
                $avg = $count > 0 ? round(((float) ($row['latency_ms_sum'] ?? 0)) / $count, 2) : 0;
                return [
                    ...$row,
                    'avg_latency_ms' => $avg,
                    'p95_latency_ms' => $this->percentileFromBuckets($row, 0.95),
                    'p99_latency_ms' => $this->percentileFromBuckets($row, 0.99),
                ];
            }, $rows),
            'queue' => [
                'pending_exports' => (int) (App::db()->first('SELECT COUNT(*) count_value FROM export_tasks WHERE status IN ("queued","processing")')['count_value'] ?? 0),
            ],
        ];
    }

    public function multimodal(array $query, ?array $screenSession = null): array
    {
        [$taskWhere, $taskBindings] = $this->taskScopeWhere($screenSession, 't');
        [$assetWhere, $assetBindings] = $this->assetScopeWhere($screenSession);
        return [
            'capabilities' => $this->aggregateAcross(
                "SELECT capability,
                        SUM(request_count) request_count,
                        SUM(input_tokens + output_tokens) total_tokens,
                        SUM(total_cost) total_cost,
                        SUM(failed_count) error_count
                 FROM {table}{where}
                 GROUP BY capability{tail}",
                ['capability' => $query['capability'] ?? null, ...$query],
                ['capability'],
                ['request_count', 'total_tokens', 'total_cost', 'error_count'],
                App::context()->adminUser(),
                'request_count'
            ),
            'tasks' => App::db()->select(
                "SELECT t.task_type, t.status, COUNT(*) task_count FROM multimodal_tasks t WHERE 1 = 1{$taskWhere} GROUP BY t.task_type, t.status ORDER BY task_count DESC LIMIT 50",
                $taskBindings
            ),
            'assets' => App::db()->select(
                "SELECT media_type, COUNT(*) asset_count, SUM(size_bytes) size_bytes FROM media_assets WHERE 1 = 1{$assetWhere} GROUP BY media_type ORDER BY asset_count DESC LIMIT 20",
                $assetBindings
            ),
        ];
    }

    /**
     * Security panel of a screen: today's events (business time zone) inside the share
     * link's scope. Desensitization events carry only the request id, so a scoped screen
     * counts the events of its own requests; audit actions are system-wide and are only
     * shown on global screens.
     */
    public function screenSecurity(?array $screenSession): array
    {
        $from = BusinessTime::periodStartUtc('day');
        [$scopeColumn, $scopeIds] = $this->screenScopeFilter($screenSession);
        if ($scopeColumn === null) {
            return [
                'desensitization' => App::db()->select(
                    'SELECT action, SUM(match_count) match_count, COUNT(*) event_count FROM desensitization_events WHERE created_at >= ? GROUP BY action ORDER BY event_count DESC LIMIT 20',
                    [$from]
                ),
                'audit' => App::db()->select(
                    'SELECT action, COUNT(*) event_count FROM audit_logs WHERE created_at >= ? GROUP BY action ORDER BY event_count DESC LIMIT 20',
                    [$from]
                ),
                'period_start' => $this->isoUtc($from),
            ];
        }
        if ($scopeIds === []) {
            return ['desensitization' => [], 'audit' => [], 'period_start' => $this->isoUtc($from)];
        }
        $placeholders = implode(', ', array_fill(0, count($scopeIds), '?'));
        // Request-level lookup (not an aggregate): bounded to today and to the scope.
        $requestScope = "SELECT r.request_id FROM usage_records r WHERE r.created_at >= ? AND r.{$scopeColumn} IN ({$placeholders})";

        return [
            'desensitization' => App::db()->select(
                "SELECT e.action, SUM(e.match_count) match_count, COUNT(*) event_count
                 FROM desensitization_events e
                 WHERE e.created_at >= ? AND e.request_id IN ({$requestScope})
                 GROUP BY e.action ORDER BY event_count DESC LIMIT 20",
                [$from, $from, ...$scopeIds]
            ),
            'audit' => [],
            'period_start' => $this->isoUtc($from),
        ];
    }

    public function screenMetric(string $metric, ?array $screenSession): array
    {
        if (!$this->screenMetricAllowed($screenSession, $metric)) {
            return [
                'metric' => $metric,
                'blocked' => true,
                'data' => [],
            ];
        }

        return [
            'metric' => $metric,
            'scope' => [
                'type' => $screenSession['scope_type'] ?? 'global',
                'ids' => json_decode($screenSession['scope_ids'] ?? '[]', true),
            ],
            'business_timezone' => BusinessTime::name(),
            'data' => $this->cachedScreenData($metric, $screenSession, fn (): array => match ($metric) {
                'overview' => $this->screenOverview($screenSession),
                'trends' => $this->trends($this->screenQuery($screenSession)),
                // The screen already shows models (overview); rank departments here.
                'rankings' => $this->screenRows($this->rankings(['dimension' => 'department', ...$this->screenQuery($screenSession)]), $screenSession),
                'security' => $this->screenSecurity($screenSession),
                'performance' => $this->performance($this->screenQuery($screenSession)),
                'multimodal' => $this->multimodal($this->screenQuery($screenSession), $screenSession),
                'live' => $this->screenLive($screenSession),
                default => $this->realtime($this->screenQuery($screenSession)),
            }),
        ];
    }

    /**
     * A wall of screens polls the same few metrics every 10 s; identical (metric, scope)
     * reads share one result for a few seconds (SCREEN_METRIC_CACHE_SECONDS, 0 = off).
     *
     * @param callable(): array<mixed> $compute
     * @return array<mixed>
     */
    private function cachedScreenData(string $metric, ?array $screenSession, callable $compute): array
    {
        $ttl = min(60, max(0, (int) App::config()->get('app.screen.metric_cache_seconds', 5)));
        if ($ttl === 0) {
            return $compute();
        }
        $key = $this->screenCacheKey($metric, $screenSession);
        try {
            $cached = App::redis()->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            return $compute();
        }
        $data = $compute();
        try {
            App::redis()->set($key, $data, $ttl);
        } catch (\Throwable) {
        }

        return $data;
    }

    public function screenCacheKey(string $metric, ?array $screenSession): string
    {
        $ids = $screenSession['scope_ids'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }
        $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
        sort($ids);
        $scope = implode('|', [
            (string) ($screenSession['scope_type'] ?? 'global'),
            implode(',', $ids),
            ($screenSession['allow_drilldown'] ?? 0) ? 'ids' : 'noids',
            BusinessTime::name(),
        ]);

        return 'screen_metric:' . preg_replace('/[^a-z_]/', '', $metric) . ':' . substr(hash('sha256', $scope), 0, 24);
    }

    /**
     * Last minutes of traffic from usage_aggregates_minute: QPS / TPS / error rate,
     * latency percentiles, per-provider breakdown, circuit-breaker states (route_health:*
     * in Redis), open alerts and queue/worker health.
     */
    public function screenLive(?array $screenSession, int $minutes = 5, ?int $now = null): array
    {
        $now ??= time();
        $minutes = min(15, max(1, $minutes));
        $fromTs = intdiv($now, 60) * 60 - ($minutes - 1) * 60;
        $from = gmdate('Y-m-d H:i:s', $fromTs);
        $seconds = max(1, $now - $fromTs);
        $query = ['from' => $from, ...$this->screenQuery($screenSession)];
        [$where, $bindings] = $this->aggregateWhere($query, 'minute');

        $bucketColumns = ['latency_bucket_100', 'latency_bucket_300', 'latency_bucket_1000', 'latency_bucket_3000', 'latency_bucket_over_3000'];
        if (SchemaInspector::hasColumn('usage_aggregates_minute', 'latency_bucket_over_120000')) {
            array_push($bucketColumns, 'latency_bucket_10000', 'latency_bucket_30000', 'latency_bucket_120000', 'latency_bucket_over_120000');
        }
        $ttftColumn = SchemaInspector::hasColumn('usage_aggregates_minute', 'ttft_ms_sum') ? 'ttft_ms_sum' : null;
        $ttftCountColumn = $ttftColumn !== null && SchemaInspector::hasColumn('usage_aggregates_minute', 'ttft_count') ? 'ttft_count' : null;
        $extra = implode('', array_map(static fn (string $column): string => ", COALESCE(SUM({$column}),0) {$column}", [...$bucketColumns, ...array_filter([$ttftColumn, $ttftCountColumn])]));

        $series = App::db()->select(
            "SELECT bucket_at,
                    COALESCE(SUM(request_count),0) request_count,
                    COALESCE(SUM(success_count),0) success_count,
                    COALESCE(SUM(failed_count),0) failed_count,
                    COALESCE(SUM(input_tokens),0) input_tokens,
                    COALESCE(SUM(output_tokens),0) output_tokens,
                    COALESCE(SUM(latency_ms_sum),0) latency_ms_sum{$extra}
             FROM usage_aggregates_minute{$where}
             GROUP BY bucket_at
             ORDER BY bucket_at ASC",
            $bindings
        );
        $totals = ['request_count' => 0, 'success_count' => 0, 'failed_count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'latency_ms_sum' => 0];
        $histogram = [];
        foreach ($series as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) ($row[$key] ?? 0);
            }
            foreach ([...$bucketColumns, ...array_filter([$ttftColumn, $ttftCountColumn])] as $column) {
                $histogram[$column] = ($histogram[$column] ?? 0) + (int) ($row[$column] ?? 0);
            }
        }
        $requests = $totals['request_count'];
        $tokens = $totals['input_tokens'] + $totals['output_tokens'];

        $providers = App::db()->select(
            "SELECT provider_id AS id,
                    COALESCE(SUM(request_count),0) request_count,
                    COALESCE(SUM(failed_count),0) failed_count,
                    COALESCE(SUM(input_tokens + output_tokens),0) total_tokens,
                    COALESCE(SUM(latency_ms_sum),0) latency_ms_sum
             FROM usage_aggregates_minute{$where}
             GROUP BY provider_id
             ORDER BY request_count DESC
             LIMIT 10",
            $bindings
        );
        $providers = array_map(static function (array $row) use ($seconds): array {
            $count = (int) ($row['request_count'] ?? 0);
            return [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'request_count' => $count,
                'failed_count' => (int) ($row['failed_count'] ?? 0),
                'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                'qps' => round($count / $seconds, 3),
                'error_rate' => $count > 0 ? round((int) ($row['failed_count'] ?? 0) / $count * 100, 2) : 0,
                'avg_latency_ms' => $count > 0 ? round((float) ($row['latency_ms_sum'] ?? 0) / $count, 1) : 0,
            ];
        }, $this->withRankingNames($providers, 'provider'));

        $global = $this->screenScopeFilter($screenSession)[0] === null;
        $circuits = $this->circuitSnapshot($global ? null : $this->scopeModelIds($screenSession, $now), $now);
        $queue = $this->queueHealth();
        $errorRate = $requests > 0 ? round($totals['failed_count'] / $requests * 100, 2) : 0;
        $openAlerts = $global ? $this->openAlertCount() : null;

        return [
            'window_minutes' => $minutes,
            'window_seconds' => $seconds,
            'from' => $this->isoUtc($from),
            'qps' => round($requests / $seconds, 3),
            'tps' => round($tokens / $seconds, 2),
            'request_count' => $requests,
            'failed_count' => $totals['failed_count'],
            'total_tokens' => $tokens,
            'error_rate' => $errorRate,
            'avg_latency_ms' => $requests > 0 ? round($totals['latency_ms_sum'] / $requests, 1) : 0,
            'p95_latency_ms' => $this->percentileFromBuckets($histogram, 0.95),
            'p99_latency_ms' => $this->percentileFromBuckets($histogram, 0.99),
            // Time to first token needs the gateway to record it (ttft_ms_sum); until then
            // the screen shows latency percentiles only.
            'ttft' => $ttftColumn === null
                ? ['available' => false, 'avg_ms' => null]
                : ['available' => true, 'avg_ms' => ($histogram[$ttftCountColumn ?? ''] ?? $requests) > 0 ? round(($histogram[$ttftColumn] ?? 0) / ($histogram[$ttftCountColumn ?? ''] ?? $requests), 1) : 0],
            'series' => array_map(static fn (array $row): array => [
                'bucket_at' => $row['bucket_at'],
                'request_count' => (int) ($row['request_count'] ?? 0),
                'failed_count' => (int) ($row['failed_count'] ?? 0),
                'total_tokens' => (int) ($row['input_tokens'] ?? 0) + (int) ($row['output_tokens'] ?? 0),
            ], $series),
            'providers' => $providers,
            'circuits' => $circuits,
            'open_alerts' => $openAlerts,
            'queue' => $queue,
            'health' => $this->healthLevel($queue, $circuits, $errorRate, $requests),
            'business_timezone' => BusinessTime::name(),
            'generated_at' => gmdate('c', $now),
        ];
    }

    public function screenMetricAllowed(?array $screenSession, string $metric): bool
    {
        if (!$screenSession || empty($screenSession['allowed_metrics'])) {
            return true;
        }

        $allowed = is_array($screenSession['allowed_metrics'])
            ? $screenSession['allowed_metrics']
            : (json_decode((string) $screenSession['allowed_metrics'], true) ?: []);

        if ($metric === 'overview' && array_intersect(['overview', 'realtime', 'trends', 'rankings', 'performance'], array_map('strval', $allowed)) !== []) {
            return true;
        }
        if ($metric === 'live' && array_intersect(['live', 'overview', 'realtime', 'performance'], array_map('strval', $allowed)) !== []) {
            return true;
        }

        return $allowed === [] || in_array($metric, array_map('strval', $allowed), true);
    }

    public function screenOverview(?array $screenSession): array
    {
        $query = $this->screenQuery($screenSession);

        return [
            'totals' => $this->tokenSummary($query, $this->periodStart('today')),
            'period_totals' => [
                'today' => $this->periodTokenTotal($query, $this->periodStart('today')),
                'week' => $this->periodTokenTotal($query, $this->periodStart('week')),
                'month' => $this->periodTokenTotal($query, $this->periodStart('month')),
                'quarter' => $this->periodTokenTotal($query, $this->periodStart('quarter')),
                'year' => $this->periodTokenTotal($query, $this->periodStart('year')),
            ],
            'model_usage' => $this->modelUsage($query, $this->periodStart('month')),
            'model_token_share' => $this->modelTokenShare($query, $this->periodStart('month')),
            'live_generations' => $this->liveGenerations($screenSession),
            'business_timezone' => BusinessTime::name(),
            'period_starts' => array_map(fn (string $period): string => $this->isoUtc($this->periodStart($period)), ['today' => 'today', 'week' => 'week', 'month' => 'month', 'quarter' => 'quarter', 'year' => 'year']),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function aggregateWhere(array $query, string $bucket, ?array $actor = null): array
    {
        $conditions = [];
        $bindings = [];
        $column = 'bucket_at';
        $fromDefault = match ($bucket) {
            'day' => BusinessTime::periodStartUtc('day'),
            'minute' => gmdate('Y-m-d H:i:00', time() - 4 * 60),
            default => gmdate('Y-m-d H:00:00', time() - 47 * 3600),
        };
        $conditions[] = "{$column} >= ?";
        $bindings[] = (string) ($query['from'] ?? $fromDefault);
        if (!empty($query['to'])) {
            $conditions[] = "{$column} <= ?";
            $bindings[] = (string) $query['to'];
        }
        if (!empty($query['before'])) {
            $conditions[] = "{$column} < ?";
            $bindings[] = (string) $query['before'];
        }
        if ($actor !== null) {
            [$scopeWhere, $scopeBindings] = (new PermissionService())->tableScopeSql($actor, 'usage_records');
            $scopeWhere = trim($scopeWhere);
            if ($scopeWhere !== '') {
                $conditions[] = preg_replace('/^WHERE\s+/i', '', $scopeWhere) ?: $scopeWhere;
                array_push($bindings, ...$scopeBindings);
            }
        }
        foreach (['department_id', 'user_id', 'api_key_id', 'provider_id', 'model_id', 'capability'] as $key) {
            if (!isset($query[$key])) {
                continue;
            }
            if (is_array($query[$key])) {
                $values = array_values(array_filter($query[$key], static fn (mixed $value): bool => (string) $value !== ''));
                if ($values === []) {
                    continue;
                }
                $conditions[] = "{$key} IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';
                array_push($bindings, ...$values);
                continue;
            }
            if ((string) $query[$key] === '') {
                continue;
            }
            $conditions[] = "{$key} = ?";
            $bindings[] = $query[$key];
        }

        [$employeeCondition, $employeeBindings] = $this->employeeQueryCondition($query, $actor);
        if ($employeeCondition !== '') {
            $conditions[] = $employeeCondition;
            array_push($bindings, ...$employeeBindings);
        }

        return [' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    /**
     * @return array{0:string,1:list<int>}
     */
    private function employeeQueryCondition(array $query, ?array $actor): array
    {
        $keyword = trim((string) ($query['employee_query'] ?? ''));
        if ($keyword === '' || $actor === null) {
            return ['', []];
        }

        [$scopeWhere, $scopeBindings] = (new PermissionService())->tableScopeSql($actor, 'users');
        $userWhere = trim($scopeWhere) === ''
            ? ' WHERE username LIKE ?'
            : $scopeWhere . ' AND username LIKE ?';
        $rows = App::db()->select(
            'SELECT id FROM users' . $userWhere,
            [...$scopeBindings, '%' . $keyword . '%']
        );
        $userIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
        if ($userIds === []) {
            return ['1 = 0', []];
        }

        return ['user_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')', $userIds];
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function timeWhere(array $query, string $column): array
    {
        $conditions = [];
        $bindings = [];
        if (!empty($query['from'])) {
            $conditions[] = "{$column} >= ?";
            $bindings[] = (string) $query['from'];
        }
        if (!empty($query['to'])) {
            $conditions[] = "{$column} <= ?";
            $bindings[] = (string) $query['to'];
        }
        return $conditions === [] ? ['', []] : [' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    private function tokenSummary(array $query, string $from): array
    {
        $row = $this->aggregateAcross(
            "SELECT COALESCE(SUM(request_count),0) request_count,
                    COALESCE(SUM(success_count),0) success_count,
                    COALESCE(SUM(failed_count),0) failed_count,
                    COALESCE(SUM(input_tokens),0) input_tokens,
                    COALESCE(SUM(output_tokens),0) output_tokens,
                    COALESCE(SUM(cache_read_tokens),0) cache_read_tokens,
                    COALESCE(SUM(cache_write_tokens),0) cache_write_tokens
             FROM {table}{where}",
            ['from' => $from, ...$query],
            [],
            ['request_count', 'success_count', 'failed_count', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens'],
            App::context()->adminUser()
        )[0] ?? [];
        $input = (int) ($row['input_tokens'] ?? 0);
        $output = (int) ($row['output_tokens'] ?? 0);
        $cacheRead = (int) ($row['cache_read_tokens'] ?? 0);
        $cacheWrite = (int) ($row['cache_write_tokens'] ?? 0);
        $requestCount = (int) ($row['request_count'] ?? 0);
        $successCount = (int) ($row['success_count'] ?? 0);

        return [
            'request_count' => $requestCount,
            'success_count' => $successCount,
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cache_read_tokens' => $cacheRead,
            'cache_write_tokens' => $cacheWrite,
            'cache_tokens' => $cacheRead + $cacheWrite,
            'total_tokens' => $input + $output,
            'success_rate' => $requestCount > 0 ? round($successCount / $requestCount * 100, 2) : 0,
        ];
    }

    private function periodTokenTotal(array $query, string $from): array
    {
        $summary = $this->tokenSummary($query, $from);
        return [
            'request_count' => $summary['request_count'],
            'input_tokens' => $summary['input_tokens'],
            'output_tokens' => $summary['output_tokens'],
            'cache_tokens' => $summary['cache_tokens'],
            'total_tokens' => $summary['total_tokens'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modelUsage(array $query, string $from): array
    {
        $rows = $this->aggregateAcross(
            "SELECT a.model_id,
                    m.name configured_model_name,
                    COALESCE(m.name, CASE WHEN a.model_id = 0 THEN '未路由' ELSE CONCAT('模型 ', a.model_id) END) model_name,
                    COALESCE(SUM(a.request_count),0) request_count,
                    COALESCE(SUM(a.input_tokens),0) input_tokens,
                    COALESCE(SUM(a.output_tokens),0) output_tokens,
                    COALESCE(SUM(a.cache_read_tokens),0) cache_read_tokens,
                    COALESCE(SUM(a.cache_write_tokens),0) cache_write_tokens,
                    COALESCE(SUM(a.cache_read_tokens + a.cache_write_tokens),0) cache_tokens,
                    COALESCE(SUM(a.input_tokens + a.output_tokens),0) total_tokens,
                    COALESCE(SUM(a.failed_count),0) error_count
             FROM {table} a
             LEFT JOIN models m ON m.id = a.model_id{where}
             GROUP BY a.model_id, m.name{tail}",
            ['from' => $from, ...$query],
            ['model_id'],
            ['request_count', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cache_tokens', 'total_tokens', 'error_count'],
            App::context()->adminUser(),
            'total_tokens',
            8
        );
        return (new ModelDisplayNameService())->backfillMissingNames($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modelTokenShare(array $query, string $from): array
    {
        $rows = $this->modelUsage($query, $from);
        $total = array_sum(array_map(static fn (array $row): int => (int) ($row['total_tokens'] ?? 0), $rows));
        return array_map(static function (array $row) use ($total): array {
            $tokens = (int) ($row['total_tokens'] ?? 0);
            return [
                'model_id' => $row['model_id'] ?? null,
                'model_name' => $row['model_name'] ?? '模型',
                'total_tokens' => $tokens,
                'percent' => $total > 0 ? round($tokens / $total * 100, 2) : 0,
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveGenerations(?array $screenSession): array
    {
        [$scopeWhere, $scopeBindings] = $this->taskScopeWhere($screenSession, 't');
        $rows = App::db()->select(
            "SELECT t.task_id,
                    t.task_type,
                    t.status,
                    t.progress,
                    t.submit_payload,
                    t.created_at,
                    t.user_id,
                    t.department_id,
                    t.model_id,
                    m.name configured_model_name,
                    COALESCE(u.username, CONCAT('用户 ', t.user_id)) user_name,
                    COALESCE(d.name, CONCAT('部门 ', t.department_id)) department_name,
                    COALESCE(m.name, CONCAT('模型 ', t.model_id)) model_name
             FROM multimodal_tasks t
             LEFT JOIN models m ON m.id = t.model_id
             LEFT JOIN users u ON u.id = t.user_id
             LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.status IN ('queued','processing','succeeded'){$scopeWhere}
             ORDER BY CASE WHEN t.status IN ('queued','processing') THEN 0 ELSE 1 END, t.created_at DESC, t.id DESC
             LIMIT 10",
            $scopeBindings
        );
        $rows = (new ModelDisplayNameService())->backfillMissingNames($rows);

        if (count($rows) < 10) {
            [$recordScopeWhere, $recordScopeBindings] = $this->taskScopeWhere($screenSession, 'r');
            $recordRows = (new RecentGenerationService())->fromUsageRecords($recordScopeWhere, $recordScopeBindings, 10 - count($rows));
            array_push($rows, ...$recordRows);
        }

        return array_map(static function (array $row): array {
            $payload = json_decode((string) ($row['submit_payload'] ?? ''), true);
            $prompt = is_array($payload) ? (string) ($payload['prompt'] ?? $payload['source'] ?? '') : '';
            return [
                'task_id' => $row['task_id'],
                'task_type' => $row['task_type'],
                'status' => $row['status'],
                'progress' => (int) ($row['progress'] ?? 0),
                'model_name' => $row['model_name'],
                'user_id' => $row['user_id'] ?? null,
                'user_name' => $row['user_name'] ?? '',
                'department_id' => $row['department_id'] ?? null,
                'department_name' => $row['department_name'] ?? '',
                'prompt' => mb_substr($prompt, 0, 80),
                'created_at' => $row['created_at'],
            ];
        }, $rows);
    }

    /** UTC start of the current business-time period (BUSINESS_TIMEZONE). */
    private function periodStart(string $period): string
    {
        return BusinessTime::periodStartUtc($period === 'today' ? 'day' : $period);
    }

    private function isoUtc(string $utc): string
    {
        $time = strtotime($utc . ' UTC');

        return $time === false ? $utc : gmdate('c', $time);
    }

    private function periodStartIso(array $query): string
    {
        return $this->isoUtc(!empty($query['from']) ? (string) $query['from'] : $this->periodStart('today'));
    }

    /**
     * Day aggregates are bucketed by UTC day. A business-time period that does not start
     * at a UTC midnight reads its first, partial UTC day from the hourly table and the
     * rest from the daily table: [table, from, before].
     *
     * @return list<array{0:string,1:string,2:?string}>
     */
    public function periodRanges(string $fromUtc): array
    {
        $time = strtotime($fromUtc . ' UTC');
        if ($time === false) {
            return [['usage_aggregates_day', $fromUtc, null]];
        }
        $dayStart = gmdate('Y-m-d 00:00:00', $time);
        if ($dayStart === gmdate('Y-m-d H:i:s', $time)) {
            return [['usage_aggregates_day', $dayStart, null]];
        }
        $nextDay = gmdate('Y-m-d 00:00:00', $time + 86400);

        return [
            ['usage_aggregates_hour', gmdate('Y-m-d H:00:00', $time), $nextDay],
            ['usage_aggregates_day', $nextDay, null],
        ];
    }

    /**
     * Runs an aggregate SELECT ({table}, {where}, optional {tail}) over the ranges of the
     * query's period and merges the grouped rows. Sorting/limiting happens in SQL when a
     * single range is read and in PHP after the merge otherwise.
     *
     * @param list<string> $groupKeys
     * @param list<string> $sumKeys
     * @return list<array<string, mixed>>
     */
    private function aggregateAcross(string $sql, array $query, array $groupKeys, array $sumKeys, ?array $actor, ?string $orderBy = null, ?int $limit = null): array
    {
        $from = !empty($query['from']) ? (string) $query['from'] : BusinessTime::periodStartUtc('day');
        $ranges = $this->periodRanges($from);
        $tail = $orderBy === null ? '' : " ORDER BY {$orderBy} DESC" . ($limit !== null ? " LIMIT {$limit}" : '');
        $merged = [];
        foreach ($ranges as [$table, $rangeFrom, $before]) {
            $rangeQuery = [...$query, 'from' => $rangeFrom];
            unset($rangeQuery['before']);
            if ($before !== null) {
                $rangeQuery['before'] = $before;
            }
            [$where, $bindings] = $this->aggregateWhere($rangeQuery, 'day', $actor);
            $rows = App::db()->select(strtr($sql, [
                '{table}' => $table,
                '{where}' => $where,
                '{tail}' => count($ranges) === 1 ? $tail : '',
            ]), $bindings);
            foreach ($rows as $row) {
                $key = implode("\x1f", array_map(static fn (string $column): string => (string) ($row[$column] ?? ''), $groupKeys));
                if (!isset($merged[$key])) {
                    $merged[$key] = $row;
                    continue;
                }
                foreach ($sumKeys as $column) {
                    $merged[$key][$column] = $this->addNumeric($merged[$key][$column] ?? 0, $row[$column] ?? 0);
                }
            }
        }
        $rows = array_values($merged);
        if (count($ranges) > 1 && $orderBy !== null) {
            usort($rows, static fn (array $a, array $b): int => (float) ($b[$orderBy] ?? 0) <=> (float) ($a[$orderBy] ?? 0));
            if ($limit !== null) {
                $rows = array_slice($rows, 0, $limit);
            }
        }

        return $rows;
    }

    private function addNumeric(mixed $left, mixed $right): int|float|string
    {
        if ($left === null && $right === null) {
            return 0;
        }
        $isInt = static fn (mixed $value): bool => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) || $value === null;
        if ($isInt($left) && $isInt($right)) {
            return (int) $left + (int) $right;
        }

        return (float) $left + (float) $right;
    }

    /**
     * The share link's scope as [usage column, ids]; [null, []] for a global screen.
     *
     * @return array{0:?string,1:list<int>}
     */
    private function screenScopeFilter(?array $screenSession): array
    {
        $query = $this->screenQuery($screenSession);
        foreach (['department_id', 'user_id', 'api_key_id'] as $column) {
            if (isset($query[$column])) {
                return [$column, array_values(array_map('intval', (array) $query[$column]))];
            }
        }
        $type = (string) ($screenSession['scope_type'] ?? 'global');
        // A scoped link without ids sees nothing rather than everything.
        return $screenSession !== null && $type !== 'global' ? [$type . '_id', []] : [null, []];
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function assetScopeWhere(?array $screenSession): array
    {
        [$column, $ids] = $this->screenScopeFilter($screenSession);
        if ($column === null) {
            return ['', []];
        }
        $assetColumn = match ($column) {
            'department_id' => 'department_id',
            'user_id' => 'owner_user_id',
            default => null,
        };
        if ($assetColumn === null || $ids === []) {
            return [' AND 1 = 0', []];
        }

        return [' AND ' . $assetColumn . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /**
     * Models used inside the screen scope during the last hour (scoped circuit view).
     *
     * @return list<int>
     */
    private function scopeModelIds(?array $screenSession, int $now): array
    {
        [$where, $bindings] = $this->aggregateWhere(['from' => gmdate('Y-m-d H:i:00', $now - 3600), ...$this->screenQuery($screenSession)], 'minute');
        try {
            $rows = App::db()->select("SELECT DISTINCT model_id FROM usage_aggregates_minute{$where}", $bindings);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['model_id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
    }

    /**
     * Circuit-breaker states from the gateway's route_health:{model_id} keys.
     *
     * @param list<int>|null $modelIds null = every channel (global screen)
     * @return array<string, mixed>
     */
    private function circuitSnapshot(?array $modelIds, int $now): array
    {
        $snapshot = ['available' => false, 'closed' => 0, 'open' => 0, 'half_open' => 0, 'open_channels' => []];
        try {
            $redis = App::redis();
            if (!$redis->available()) {
                return $snapshot;
            }
            if ($modelIds === null) {
                $keys = [];
                $connection = $redis->connection();
                $iterator = null;
                do {
                    $batch = $connection->scan($iterator, 'route_health:*', 500);
                    foreach (is_array($batch) ? $batch : [] as $key) {
                        if (preg_match('/^route_health:(\d+)$/', (string) $key) === 1) {
                            $keys[] = (string) $key;
                        }
                    }
                } while ($iterator !== 0 && $iterator !== '0' && $iterator !== null && count($keys) < 5000);
            } else {
                $keys = array_map(static fn (int $id): string => 'route_health:' . $id, $modelIds);
            }
            $snapshot['available'] = true;
            if ($keys === []) {
                return $snapshot;
            }
            $values = $redis->getMany(array_values(array_unique($keys)));
        } catch (\Throwable) {
            return $snapshot;
        }
        $open = [];
        foreach ($values as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            $openUntil = (int) ($data['open_until'] ?? 0);
            $state = $openUntil === 0 ? 'closed' : ($now < $openUntil ? 'open' : 'half_open');
            $snapshot[$state]++;
            if ($state !== 'closed') {
                $open[] = ['id' => (int) substr((string) $key, strlen('route_health:')), 'state' => $state, 'failures' => (int) ($data['failures'] ?? 0)];
            }
        }
        if ($open !== [] && $modelIds === null) {
            $snapshot['open_channels'] = array_map(static fn (array $row): array => [
                'name' => $row['name'] ?? ('#' . $row['id']),
                'state' => $row['state'],
                'failures' => $row['failures'],
            ], $this->withRankingNames(array_slice($open, 0, 10), 'model'));
        }

        return $snapshot;
    }

    private function openAlertCount(): ?int
    {
        try {
            return (int) (App::db()->first("SELECT COUNT(*) count_value FROM alert_events WHERE status = 'open'")['count_value'] ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{pending: ?int, worker_alive: ?bool}
     */
    private function queueHealth(): array
    {
        $pending = null;
        $alive = null;
        try {
            $redis = App::redis();
            if ($redis->available()) {
                $pending = (int) $redis->connection()->lLen((string) App::config()->get('queue.name', 'tokenhub:jobs'));
                $alive = $redis->get('operations:worker:queue-worker') !== null;
            }
        } catch (\Throwable) {
        }

        return ['pending' => $pending, 'worker_alive' => $alive];
    }

    /**
     * ok / warn / critical with the reasons, for the screen's status light.
     *
     * @return array{level: string, reasons: list<string>}
     */
    private function healthLevel(array $queue, array $circuits, float|int $errorRate, int $requests): array
    {
        $reasons = [];
        $level = 'ok';
        $raise = static function (string $to) use (&$level): void {
            $order = ['ok' => 0, 'warn' => 1, 'critical' => 2];
            if ($order[$to] > $order[$level]) {
                $level = $to;
            }
        };
        if ($queue['worker_alive'] === false) {
            $reasons[] = 'worker_heartbeat_missing';
            $raise(($queue['pending'] ?? 0) > 0 ? 'critical' : 'warn');
        }
        if (($queue['pending'] ?? 0) >= 1000) {
            $reasons[] = 'queue_backlog';
            $raise('warn');
        }
        if ((int) ($circuits['open'] ?? 0) > 0) {
            $reasons[] = 'circuit_open';
            $raise('warn');
        }
        if ($requests >= 20 && $errorRate >= 20) {
            $reasons[] = 'error_rate_high';
            $raise($errorRate >= 50 ? 'critical' : 'warn');
        }

        return ['level' => $level, 'reasons' => $reasons];
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function taskScopeWhere(?array $screenSession, string $alias): array
    {
        if (!$screenSession) {
            return ['', []];
        }
        $ids = $screenSession['scope_ids'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return in_array((string) ($screenSession['scope_type'] ?? 'global'), ['department', 'user', 'api_key'], true) ? [' AND 1 = 0', []] : ['', []];
        }
        if ((string) ($screenSession['scope_type'] ?? 'global') === 'department') {
            $ids = $this->departmentAndDescendantIds($ids);
        }
        $column = match ((string) ($screenSession['scope_type'] ?? 'global')) {
            'department' => $alias . '.department_id',
            'user' => $alias . '.user_id',
            'api_key' => $alias . '.api_key_id',
            default => '',
        };
        if ($column === '') {
            return ['', []];
        }
        return [' AND ' . $column . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    private function percentileFromBuckets(array $row, float $percentile): int
    {
        $buckets = [
            100 => (int) ($row['latency_bucket_100'] ?? 0),
            300 => (int) ($row['latency_bucket_300'] ?? 0),
            1000 => (int) ($row['latency_bucket_1000'] ?? 0),
            3000 => (int) ($row['latency_bucket_3000'] ?? 0),
        ];
        $extended = isset($row['latency_bucket_10000']) || isset($row['latency_bucket_over_120000']);
        if ($extended) {
            // Long requests (streams) are split instead of all reading "3001 ms".
            // Rows recorded before the extended buckets existed are only in
            // over_3000; they are attributed to the 10 s bucket.
            $long = (int) ($row['latency_bucket_10000'] ?? 0) + (int) ($row['latency_bucket_30000'] ?? 0) + (int) ($row['latency_bucket_120000'] ?? 0) + (int) ($row['latency_bucket_over_120000'] ?? 0);
            $legacyLong = max(0, (int) ($row['latency_bucket_over_3000'] ?? 0) - $long);
            $buckets[10000] = (int) ($row['latency_bucket_10000'] ?? 0) + $legacyLong;
            $buckets[30000] = (int) ($row['latency_bucket_30000'] ?? 0);
            $buckets[120000] = (int) ($row['latency_bucket_120000'] ?? 0);
            $buckets[120001] = (int) ($row['latency_bucket_over_120000'] ?? 0);
        } else {
            $buckets[3001] = (int) ($row['latency_bucket_over_3000'] ?? 0);
        }
        $total = array_sum($buckets);
        if ($total <= 0) {
            return 0;
        }
        $target = (int) ceil($total * $percentile);
        $seen = 0;
        foreach ($buckets as $threshold => $count) {
            $seen += $count;
            if ($seen >= $target) {
                return $threshold;
            }
        }
        return (int) array_key_last($buckets);
    }

    /**
     * @return array<string, mixed>
     */
    private function screenQuery(?array $screenSession): array
    {
        if (!$screenSession) {
            return [];
        }
        $ids = $screenSession['scope_ids'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            // A scoped link without scope ids sees nothing, never everything.
            return match ((string) ($screenSession['scope_type'] ?? 'global')) {
                'department' => ['department_id' => [0]],
                'user' => ['user_id' => [0]],
                'api_key' => ['api_key_id' => [0]],
                default => [],
            };
        }
        return match ((string) ($screenSession['scope_type'] ?? 'global')) {
            'department' => ['department_id' => $this->departmentAndDescendantIds($ids)],
            'user' => ['user_id' => $ids],
            'api_key' => ['api_key_id' => $ids],
            default => [],
        };
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function departmentAndDescendantIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $conditions = [];
        $bindings = [];
        foreach ($ids as $id) {
            $conditions[] = '(id = ? OR path LIKE ?)';
            $bindings[] = $id;
            $bindings[] = '%/' . $id . '/%';
        }

        $rows = App::db()->select(
            'SELECT id FROM departments WHERE ' . implode(' OR ', $conditions),
            $bindings
        );
        $expanded = array_map('intval', array_column($rows, 'id'));
        return array_values(array_unique([...$ids, ...$expanded]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function screenRows(array $rows, ?array $screenSession): array
    {
        if (($screenSession['allow_drilldown'] ?? 0) || $rows === []) {
            return $rows;
        }
        return array_map(static function (array $row): array {
            unset($row['id']);
            return $row;
        }, $rows);
    }
}
