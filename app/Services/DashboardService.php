<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class DashboardService
{
    public function realtime(array $query = []): array
    {
        [$where, $bindings] = $this->aggregateWhere($query, 'day', App::context()->adminUser());
        $row = App::db()->first(
            "SELECT COALESCE(SUM(request_count),0) request_count,
                    COALESCE(SUM(input_tokens),0) input_tokens,
                    COALESCE(SUM(output_tokens),0) output_tokens,
                    COALESCE(SUM(cache_read_tokens),0) cache_read_tokens,
                    COALESCE(SUM(cache_write_tokens),0) cache_write_tokens,
                    COALESCE(SUM(success_count),0) success_count,
                    COALESCE(SUM(failed_count),0) failed_count,
                    COALESCE(SUM(total_cost),0) total_cost,
                    COALESCE(SUM(latency_ms_sum),0) latency_ms_sum
             FROM usage_aggregates_day{$where}",
            $bindings
        ) ?: [];
        $row['total_tokens'] = (int) ($row['input_tokens'] ?? 0) + (int) ($row['output_tokens'] ?? 0);
        $row['cache_tokens'] = (int) ($row['cache_read_tokens'] ?? 0) + (int) ($row['cache_write_tokens'] ?? 0);
        $row['avg_latency_ms'] = (int) ($row['request_count'] ?? 0) > 0
            ? round(((float) ($row['latency_ms_sum'] ?? 0)) / (int) $row['request_count'], 2)
            : 0;

        return [
            'today' => $row,
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
        [$where, $bindings] = $this->aggregateWhere($query, 'day', App::context()->adminUser());

        $rows = App::db()->select(
            "SELECT {$column} AS id,
                    SUM(request_count) request_count,
                    SUM(input_tokens + output_tokens) total_tokens,
                    SUM(cache_read_tokens + cache_write_tokens) cache_tokens,
                    SUM(CASE WHEN capability <> 'text_generation' THEN request_count ELSE 0 END) modal_count,
                    SUM(failed_count) error_count,
                    SUM(total_cost) total_cost
             FROM usage_aggregates_day{$where}
             GROUP BY {$column}
             ORDER BY {$sortColumn} DESC
             LIMIT 20",
            $bindings
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

    public function multimodal(array $query): array
    {
        [$where, $bindings] = $this->aggregateWhere(['capability' => $query['capability'] ?? null, ...$query], 'day', App::context()->adminUser());
        return [
            'capabilities' => App::db()->select(
                "SELECT capability,
                        SUM(request_count) request_count,
                        SUM(input_tokens + output_tokens) total_tokens,
                        SUM(total_cost) total_cost,
                        SUM(failed_count) error_count
                 FROM usage_aggregates_day{$where}
                 GROUP BY capability
                 ORDER BY request_count DESC",
                $bindings
            ),
            'tasks' => App::db()->select(
                'SELECT task_type, status, COUNT(*) task_count FROM multimodal_tasks GROUP BY task_type, status ORDER BY task_count DESC LIMIT 50'
            ),
            'assets' => App::db()->select(
                'SELECT media_type, COUNT(*) asset_count, SUM(size_bytes) size_bytes FROM media_assets GROUP BY media_type ORDER BY asset_count DESC LIMIT 20'
            ),
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
            'data' => match ($metric) {
                'overview' => $this->screenOverview($screenSession),
                'trends' => $this->trends($this->screenQuery($screenSession)),
                // The screen already shows models (overview); rank departments here.
                'rankings' => $this->screenRows($this->rankings(['dimension' => 'department', ...$this->screenQuery($screenSession)]), $screenSession),
                'security' => $this->security([]),
                'performance' => $this->performance($this->screenQuery($screenSession)),
                'multimodal' => $this->multimodal($this->screenQuery($screenSession)),
                default => $this->realtime($this->screenQuery($screenSession)),
            },
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
        $fromDefault = $bucket === 'day' ? gmdate('Y-m-d 00:00:00') : gmdate('Y-m-d H:00:00', time() - 47 * 3600);
        $conditions[] = "{$column} >= ?";
        $bindings[] = (string) ($query['from'] ?? $fromDefault);
        if (!empty($query['to'])) {
            $conditions[] = "{$column} <= ?";
            $bindings[] = (string) $query['to'];
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
        [$where, $bindings] = $this->aggregateWhere(['from' => $from, ...$query], 'day', App::context()->adminUser());
        $row = App::db()->first(
            "SELECT COALESCE(SUM(request_count),0) request_count,
                    COALESCE(SUM(success_count),0) success_count,
                    COALESCE(SUM(failed_count),0) failed_count,
                    COALESCE(SUM(input_tokens),0) input_tokens,
                    COALESCE(SUM(output_tokens),0) output_tokens,
                    COALESCE(SUM(cache_read_tokens),0) cache_read_tokens,
                    COALESCE(SUM(cache_write_tokens),0) cache_write_tokens
             FROM usage_aggregates_day{$where}",
            $bindings
        ) ?: [];
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
        [$where, $bindings] = $this->aggregateWhere(['from' => $from, ...$query], 'day', App::context()->adminUser());
        $rows = App::db()->select(
            "SELECT a.model_id,
                    m.name configured_model_name,
                    COALESCE(m.name, CONCAT('模型 ', a.model_id)) model_name,
                    COALESCE(SUM(a.request_count),0) request_count,
                    COALESCE(SUM(a.input_tokens),0) input_tokens,
                    COALESCE(SUM(a.output_tokens),0) output_tokens,
                    COALESCE(SUM(a.cache_read_tokens),0) cache_read_tokens,
                    COALESCE(SUM(a.cache_write_tokens),0) cache_write_tokens,
                    COALESCE(SUM(a.cache_read_tokens + a.cache_write_tokens),0) cache_tokens,
                    COALESCE(SUM(a.input_tokens + a.output_tokens),0) total_tokens,
                    COALESCE(SUM(a.failed_count),0) error_count
             FROM usage_aggregates_day a
             LEFT JOIN models m ON m.id = a.model_id{$where}
             GROUP BY a.model_id, m.name
             ORDER BY total_tokens DESC
             LIMIT 8",
            $bindings
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

    private function periodStart(string $period): string
    {
        $now = time();
        return match ($period) {
            'week' => gmdate('Y-m-d 00:00:00', strtotime('monday this week', $now)),
            'month' => gmdate('Y-m-01 00:00:00', $now),
            'quarter' => gmdate('Y-', $now) . str_pad((string) (((int) floor(((int) gmdate('n', $now) - 1) / 3) * 3) + 1), 2, '0', STR_PAD_LEFT) . '-01 00:00:00',
            'year' => gmdate('Y-01-01 00:00:00', $now),
            default => gmdate('Y-m-d 00:00:00', $now),
        };
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
            return ['', []];
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
            return [];
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
