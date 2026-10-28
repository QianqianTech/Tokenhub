<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\SchemaInspector;

final class UsageService
{
    /**
     * Idempotent, resumable usage persistence.
     *
     * Stage 1 (one DB transaction): usage_records row, retained content,
     * task/asset links and minute/hour/day aggregates, then aggregated_at.
     * Stage 2: external events/webhooks, then events_emitted_at.
     *
     * A retry of the same request_id resumes at the first unfinished stage,
     * so a crash after the insert can no longer leave a detail row without
     * aggregates. Before the stage columns exist (pre-migration) the legacy
     * "skip if the row exists" behaviour is kept.
     *
     * Business time and price version come from the producer (created_at),
     * not from the moment the queue worker runs.
     *
     * @param array<string, mixed> $record
     */
    public function write(array $record): void
    {
        $staged = SchemaInspector::hasColumn('usage_records', 'aggregated_at');
        $requestId = (string) ($record['request_id'] ?? '');
        if (isset($record['content_encrypted']) && is_string($record['content_encrypted'])) {
            $decoded = json_decode(Crypto::decrypt($record['content_encrypted']), true);
            if (is_array($decoded)) {
                $record['content'] = $decoded;
            }
            unset($record['content_encrypted']);
        }
        $record['created_at'] = $this->normalizeTime($record['created_at'] ?? null);

        $existing = $requestId !== '' ? $this->existing($requestId, $staged) : null;
        if ($existing !== null) {
            if (!$staged) {
                return;
            }
            $this->resume($existing, $record);
            return;
        }

        $modelId = isset($record['model_id']) ? (int) $record['model_id'] : 0;
        $priced = $modelId > 0
            ? (new PriceService())->priceUsage($modelId, $record, $record['created_at'])
            : ['cost_amount' => null, 'price_snapshot' => null];

        $record['cost_amount'] = $priced['cost_amount'];
        $record['cost_currency'] = $priced['price_snapshot']['currency'] ?? null;
        $record['price_snapshot'] = $priced['price_snapshot'] === null
            ? null
            : json_encode($priced['price_snapshot'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $usageRecord = App::db()->transaction(function () use ($record, $staged): ?array {
                $this->insertRecord($record, $staged);
                $usageRecord = App::db()->first('SELECT id FROM usage_records WHERE request_id = ?', [$record['request_id']]);
                $this->linkAndAggregate($usageRecord, $record, $staged);

                return $usageRecord;
            });
        } catch (\PDOException $exception) {
            // Another worker inserted the same request concurrently: resume instead.
            if ((string) $exception->getCode() === '23000' && $requestId !== '') {
                $existing = $this->existing($requestId, $staged);
                if ($existing !== null && $staged) {
                    $this->resume($existing, $record);
                }
                return;
            }
            throw $exception;
        }

        $this->emitAndMark($record, $usageRecord, $staged);
        $this->settleFinishedTask($record);
        $logRecord = $record;
        unset($logRecord['content']);
        App::logger()->info('usage', 'usage record written', $logRecord);
    }

    /**
     * Rebuilds aggregates for a time range from usage_records (reconciliation).
     * Only rows whose aggregation is complete are summed, so the result equals
     * what incremental aggregation produced when nothing was lost.
     *
     * @return array<string, int>
     */
    public function rebuildAggregates(string $from, string $to): array
    {
        $counts = [];
        foreach (['usage_aggregates_minute' => '%Y-%m-%d %H:%i:00', 'usage_aggregates_hour' => '%Y-%m-%d %H:00:00', 'usage_aggregates_day' => '%Y-%m-%d 00:00:00'] as $table => $format) {
            $grain = match ($table) {
                'usage_aggregates_minute' => 'minute',
                'usage_aggregates_hour' => 'hour',
                default => 'day',
            };
            [$fromBucket, $toBucket] = $this->bucketRange($from, $to, $grain);
            App::db()->transaction(function () use ($table, $format, $fromBucket, $toBucket, &$counts): void {
                App::db()->execute("DELETE FROM {$table} WHERE bucket_at >= ? AND bucket_at < ?", [$fromBucket, $toBucket]);
                $counts[$table] = App::db()->execute(
                    "INSERT INTO {$table} (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, total_cost, latency_ms_sum, latency_bucket_100, latency_bucket_300, latency_bucket_1000, latency_bucket_3000, latency_bucket_over_3000, latency_bucket_10000, latency_bucket_30000, latency_bucket_120000, latency_bucket_over_120000)
                     SELECT DATE_FORMAT(created_at, '{$format}') AS bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability,
                            COUNT(*), SUM(status = 'success'), SUM(status <> 'success'), SUM(input_tokens), SUM(output_tokens), SUM(cache_read_tokens), SUM(cache_write_tokens),
                            SUM(CASE WHEN cost_amount IS NOT NULL AND (cost_currency IS NULL OR cost_currency = ?) THEN cost_amount END),
                            SUM(COALESCE(latency_ms, 0)), SUM(COALESCE(latency_ms, 0) <= 100), SUM(latency_ms > 100 AND latency_ms <= 300), SUM(latency_ms > 300 AND latency_ms <= 1000), SUM(latency_ms > 1000 AND latency_ms <= 3000), SUM(latency_ms > 3000),
                            SUM(latency_ms > 3000 AND latency_ms <= 10000), SUM(latency_ms > 10000 AND latency_ms <= 30000), SUM(latency_ms > 30000 AND latency_ms <= 120000), SUM(latency_ms > 120000)
                     FROM usage_records
                     WHERE created_at >= ? AND created_at < ?
                     GROUP BY bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability",
                    [$this->baseCurrency(), $fromBucket, $toBucket]
                );
            });
        }

        return $counts;
    }

    /**
     * Finishes rows whose aggregation or event stage never completed (worker
     * crash between stages, poison job dead-lettered). Safe to run repeatedly.
     */
    public function resumePending(int $limit = 200, int $minAgeSeconds = 120): int
    {
        if (!SchemaInspector::hasColumn('usage_records', 'aggregated_at')) {
            return 0;
        }
        $rows = App::db()->select(
            'SELECT * FROM usage_records WHERE (aggregated_at IS NULL OR events_emitted_at IS NULL) AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND) ORDER BY id ASC LIMIT ' . max(1, min(1000, $limit)),
            [$minAgeSeconds]
        );
        foreach ($rows as $row) {
            $record = $row;
            unset($record['id']);
            $this->resume($row, $record);
        }

        return count($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existing(string $requestId, bool $staged): ?array
    {
        return App::db()->first(
            $staged
                ? 'SELECT id, request_id, aggregated_at, events_emitted_at, cost_amount, cost_currency, price_snapshot, created_at FROM usage_records WHERE request_id = ?'
                : 'SELECT id FROM usage_records WHERE request_id = ?',
            [$requestId]
        );
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $record
     */
    private function resume(array $existing, array $record): void
    {
        // Use the stored price/time so a resumed aggregate matches the detail row.
        $record['cost_amount'] = $existing['cost_amount'] ?? null;
        $record['cost_currency'] = $existing['cost_currency'] ?? null;
        $record['created_at'] = (string) ($existing['created_at'] ?? $record['created_at']);
        if (empty($existing['aggregated_at'])) {
            App::db()->transaction(function () use ($existing, $record): void {
                // Row lock: concurrent resumers aggregate at most once.
                $locked = App::db()->first('SELECT id, aggregated_at FROM usage_records WHERE id = ? FOR UPDATE', [(int) $existing['id']]);
                if ($locked === null || !empty($locked['aggregated_at'])) {
                    return;
                }
                $this->linkAndAggregate(['id' => (int) $existing['id']], $record, true);
            });
            App::logger()->warning('usage', 'usage aggregation resumed', ['request_id' => $record['request_id'] ?? null]);
        }
        if (empty($existing['events_emitted_at'])) {
            $this->emitAndMark($record, ['id' => (int) $existing['id']], true);
        }
        $this->settleFinishedTask($record);
    }

    /**
     * An asynchronous task can finish (or fail) before the queue writes its
     * usage record; the settlement then runs here, once the record exists.
     *
     * @param array<string, mixed> $record
     */
    private function settleFinishedTask(array $record): void
    {
        if (empty($record['task_id'])) {
            return;
        }
        try {
            (new \TokenHub\Services\Billing\TaskBillingService())->settleByTaskId((string) $record['task_id']);
        } catch (\Throwable $throwable) {
            App::logger()->warning('billing', 'task settlement deferred', ['task_id' => $record['task_id'], 'error' => $throwable->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function insertRecord(array $record, bool $staged): void
    {
        $columns = ['request_id', 'trace_id', 'user_id', 'department_id', 'api_key_id', 'provider_id', 'model_id', 'model_name', 'protocol', 'endpoint', 'capability', 'status', 'http_status', 'error_code', 'input_tokens', 'output_tokens', 'total_tokens', 'cache_read_tokens', 'cache_write_tokens', 'audio_seconds', 'tts_chars', 'image_count', 'video_seconds', 'cost_amount', 'price_snapshot', 'latency_ms', 'client_ip', 'created_at'];
        $values = [
            $record['request_id'],
            $record['trace_id'],
            $record['user_id'] ?? null,
            $record['department_id'] ?? null,
            $record['api_key_id'] ?? null,
            $record['provider_id'] ?? null,
            $record['model_id'] ?? null,
            $record['model_name'] ?? null,
            $record['protocol'],
            $record['endpoint'],
            $record['capability'] ?? 'text_generation',
            $record['status'],
            $record['http_status'] ?? null,
            $record['error_code'] ?? null,
            (int) ($record['input_tokens'] ?? 0),
            (int) ($record['output_tokens'] ?? 0),
            (int) ($record['total_tokens'] ?? 0),
            (int) ($record['cache_read_tokens'] ?? 0),
            (int) ($record['cache_write_tokens'] ?? 0),
            (int) ($record['audio_seconds'] ?? 0),
            (int) ($record['tts_chars'] ?? 0),
            (int) ($record['image_count'] ?? 0),
            (int) ($record['video_seconds'] ?? 0),
            $record['cost_amount'],
            $record['price_snapshot'],
            $record['latency_ms'] ?? null,
            $record['client_ip'] ?? null,
            $record['created_at'],
        ];
        if ($staged) {
            $extra = [
                'finished_at' => isset($record['finished_at']) ? $this->normalizeTime($record['finished_at']) : null,
                'usage_source' => isset($record['usage_source']) ? substr((string) $record['usage_source'], 0, 20) : null,
                'reasoning_tokens' => (int) ($record['reasoning_tokens'] ?? 0),
                'cost_currency' => $record['cost_currency'] ?? null,
                'upstream_request_id' => isset($record['upstream_request_id']) ? substr((string) $record['upstream_request_id'], 0, 190) : null,
                'upstream_model' => isset($record['upstream_model']) ? substr((string) $record['upstream_model'], 0, 190) : null,
                'upstream_provider' => isset($record['upstream_provider']) ? substr((string) $record['upstream_provider'], 0, 120) : null,
                'upstream_cost' => isset($record['upstream_cost']) && is_numeric($record['upstream_cost']) ? (string) $record['upstream_cost'] : null,
                'attempts' => isset($record['attempts']) && is_array($record['attempts']) ? json_encode($record['attempts'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'stream_outcome' => isset($record['stream_outcome']) ? substr((string) $record['stream_outcome'], 0, 40) : null,
                'first_byte_ms' => isset($record['first_byte_ms']) ? (int) $record['first_byte_ms'] : null,
            ];
            foreach ($extra as $column => $value) {
                $columns[] = $column;
                $values[] = $value;
            }
        }

        App::db()->execute(
            'INSERT INTO usage_records (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            $values
        );
    }

    /**
     * @param array<string, mixed>|null $usageRecord
     * @param array<string, mixed> $record
     */
    private function linkAndAggregate(?array $usageRecord, array $record, bool $staged): void
    {
        if ($usageRecord && !empty($record['task_id'])) {
            App::db()->execute('UPDATE multimodal_tasks SET usage_record_id = ? WHERE task_id = ?', [(int) $usageRecord['id'], (string) $record['task_id']]);
            App::db()->execute(
                'UPDATE media_assets a JOIN multimodal_tasks t ON t.id = a.task_id SET a.usage_record_id = ? WHERE t.task_id = ?',
                [(int) $usageRecord['id'], (string) $record['task_id']]
            );
        }
        if ($usageRecord && isset($record['content']) && is_array($record['content'])) {
            (new ContentRetentionService())->writeForRecord((int) $usageRecord['id'], $record);
        }
        $this->aggregate($record);
        if ($staged && $usageRecord) {
            App::db()->execute('UPDATE usage_records SET aggregated_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $usageRecord['id']]);
        }
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $usageRecord
     */
    private function emitAndMark(array $record, ?array $usageRecord, bool $staged): void
    {
        $this->emitExternalEvents($record, $usageRecord);
        if ($staged && $usageRecord) {
            App::db()->execute('UPDATE usage_records SET events_emitted_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $usageRecord['id']]);
        }
    }

    private function normalizeTime(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            $time = strtotime($value . (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $value) === 1 ? '' : ' UTC'));
            if ($time !== false) {
                return gmdate('Y-m-d H:i:s', $time);
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    public function baseCurrency(): string
    {
        return strtoupper((string) env_value('BILLING_BASE_CURRENCY', 'USD'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function bucketRange(string $from, string $to, string $grain): array
    {
        $fromTime = strtotime($from . ' UTC') ?: time();
        $toTime = strtotime($to . ' UTC') ?: time();
        $format = match ($grain) {
            'minute' => 'Y-m-d H:i:00',
            'hour' => 'Y-m-d H:00:00',
            default => 'Y-m-d 00:00:00',
        };
        $step = match ($grain) {
            'minute' => 60,
            'hour' => 3600,
            default => 86400,
        };

        return [gmdate($format, $fromTime), gmdate($format, (int) (floor(($toTime - 1) / $step) * $step) + $step)];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function aggregate(array $record): void
    {
        $createdAt = strtotime((string) ($record['created_at'] ?? gmdate('Y-m-d H:i:s')) . ' UTC') ?: time();
        // total_cost only sums the base currency; other currencies are kept
        // apart (usage_cost_aggregates_day) and never added together.
        $currency = isset($record['cost_currency']) && $record['cost_currency'] !== null ? strtoupper((string) $record['cost_currency']) : null;
        $baseCost = $currency === null || $currency === $this->baseCurrency() ? $record['cost_amount'] : null;
        if ($currency !== null && $record['cost_amount'] !== null && SchemaInspector::hasColumn('usage_cost_aggregates_day', 'currency')) {
            App::db()->execute(
                'INSERT INTO usage_cost_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, currency, request_count, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE request_count = request_count + 1, total_cost = total_cost + VALUES(total_cost)',
                [gmdate('Y-m-d 00:00:00', $createdAt), $record['department_id'] ?? null, $record['user_id'] ?? null, $record['api_key_id'] ?? null, $record['provider_id'] ?? null, $record['model_id'] ?? null, $currency, $record['cost_amount']]
            );
        }
        $latencyBuckets = $this->latencyBuckets((int) ($record['latency_ms'] ?? 0), SchemaInspector::hasColumn('usage_aggregates_day', 'latency_bucket_over_120000'));
        $columns = ['bucket_at', 'department_id', 'user_id', 'api_key_id', 'provider_id', 'model_id', 'capability', 'request_count', 'success_count', 'failed_count', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'total_cost', 'latency_ms_sum', ...array_keys($latencyBuckets)];
        $updates = [
            'request_count = request_count + 1',
            'success_count = success_count + VALUES(success_count)',
            'failed_count = failed_count + VALUES(failed_count)',
            'input_tokens = input_tokens + VALUES(input_tokens)',
            'output_tokens = output_tokens + VALUES(output_tokens)',
            'cache_read_tokens = cache_read_tokens + VALUES(cache_read_tokens)',
            'cache_write_tokens = cache_write_tokens + VALUES(cache_write_tokens)',
            'total_cost = IF(total_cost IS NULL, VALUES(total_cost), total_cost + IFNULL(VALUES(total_cost), 0))',
            'latency_ms_sum = latency_ms_sum + VALUES(latency_ms_sum)',
        ];
        foreach (array_keys($latencyBuckets) as $bucketColumn) {
            $updates[] = "{$bucketColumn} = {$bucketColumn} + VALUES({$bucketColumn})";
        }
        foreach ([
            'usage_aggregates_minute' => gmdate('Y-m-d H:i:00', $createdAt),
            'usage_aggregates_hour' => gmdate('Y-m-d H:00:00', $createdAt),
            'usage_aggregates_day' => gmdate('Y-m-d 00:00:00', $createdAt),
        ] as $table => $bucket) {
            App::db()->execute(
                "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
                [
                    $bucket,
                    $record['department_id'] ?? null,
                    $record['user_id'] ?? null,
                    $record['api_key_id'] ?? null,
                    $record['provider_id'] ?? null,
                    $record['model_id'] ?? null,
                    $record['capability'] ?? 'text_generation',
                    1,
                    ($record['status'] ?? '') === 'success' ? 1 : 0,
                    ($record['status'] ?? '') === 'success' ? 0 : 1,
                    (int) ($record['input_tokens'] ?? 0),
                    (int) ($record['output_tokens'] ?? 0),
                    (int) ($record['cache_read_tokens'] ?? 0),
                    (int) ($record['cache_write_tokens'] ?? 0),
                    $baseCost,
                    (int) ($record['latency_ms'] ?? 0),
                    ...array_values($latencyBuckets),
                ]
            );
        }
    }

    /**
     * Histogram buckets. over_3000 keeps counting everything above 3 s for
     * existing dashboards; the long-request buckets split that tail so p95/p99
     * of streaming requests are not all reported as "3001 ms".
     *
     * @return array<string, int>
     */
    public function latencyBuckets(int $latencyMs, bool $extended = false): array
    {
        $buckets = [
            'latency_bucket_100' => $latencyMs <= 100 ? 1 : 0,
            'latency_bucket_300' => $latencyMs > 100 && $latencyMs <= 300 ? 1 : 0,
            'latency_bucket_1000' => $latencyMs > 300 && $latencyMs <= 1000 ? 1 : 0,
            'latency_bucket_3000' => $latencyMs > 1000 && $latencyMs <= 3000 ? 1 : 0,
            'latency_bucket_over_3000' => $latencyMs > 3000 ? 1 : 0,
        ];
        if ($extended) {
            $buckets['latency_bucket_10000'] = $latencyMs > 3000 && $latencyMs <= 10000 ? 1 : 0;
            $buckets['latency_bucket_30000'] = $latencyMs > 10000 && $latencyMs <= 30000 ? 1 : 0;
            $buckets['latency_bucket_120000'] = $latencyMs > 30000 && $latencyMs <= 120000 ? 1 : 0;
            $buckets['latency_bucket_over_120000'] = $latencyMs > 120000 ? 1 : 0;
        }

        return $buckets;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $usageRecord
     */
    private function emitExternalEvents(array $record, ?array $usageRecord): void
    {
        if (empty($record['api_key_id'])) {
            return;
        }

        $apiKey = [
            'id' => (int) $record['api_key_id'],
            'user_id' => $record['user_id'] ?? null,
            'department_id' => $record['department_id'] ?? null,
        ];
        $payload = [
            'request_id' => $record['request_id'] ?? null,
            'trace_id' => $record['trace_id'] ?? null,
            'usage_record_id' => $usageRecord['id'] ?? null,
            'status' => $record['status'] ?? null,
            'http_status' => $record['http_status'] ?? null,
            'error_code' => $record['error_code'] ?? null,
            'model_id' => $record['model_id'] ?? null,
            'model_name' => $record['model_name'] ?? null,
            'capability' => $record['capability'] ?? 'text_generation',
            'usage' => [
                'input_tokens' => (int) ($record['input_tokens'] ?? 0),
                'output_tokens' => (int) ($record['output_tokens'] ?? 0),
                'total_tokens' => (int) ($record['total_tokens'] ?? 0),
                'audio_seconds' => (int) ($record['audio_seconds'] ?? 0),
                'tts_chars' => (int) ($record['tts_chars'] ?? 0),
                'image_count' => (int) ($record['image_count'] ?? 0),
                'video_seconds' => (int) ($record['video_seconds'] ?? 0),
            ],
            'created_at' => gmdate('c'),
        ];

        $webhooks = new WebhookService();
        $webhooks->emit($apiKey, 'request.' . (string) ($record['status'] ?? 'unknown'), $payload);
        (new ExternalEventOutboxService())->append((int) $apiKey['id'], 'request.' . (string) ($record['status'] ?? 'unknown'), 'request', isset($record['request_id']) ? (string) $record['request_id'] : null, (string) ($record['status'] ?? 'unknown'), ['error_code' => $record['error_code'] ?? null, 'model_name' => $record['model_name'] ?? null]);

        if (!empty($record['task_id'])) {
            $task = App::db()->first(
                'SELECT task_id, task_type, status, error_code, error_message, progress, provider_task_id, created_at, finished_at FROM multimodal_tasks WHERE task_id = ?',
                [(string) $record['task_id']]
            );
            if ($task !== null) {
                $webhooks->emit($apiKey, 'task.' . (string) ($task['status'] ?? 'unknown'), [
                    ...$payload,
                    'task' => $task,
                ]);
            }
        }
    }
}
