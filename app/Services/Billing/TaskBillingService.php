<?php

declare(strict_types=1);

namespace TokenHub\Services\Billing;

use TokenHub\Services\ApiKeyModelQuotaService;
use TokenHub\Services\PriceService;
use TokenHub\Services\Pricing\PricingDimensions;
use TokenHub\Services\QuotaService;
use TokenHub\Support\App;
use TokenHub\Support\Decimal;
use TokenHub\Support\SchemaInspector;

/**
 * Settles the charge of an asynchronous task (video, async image, music...)
 * once the task has reached its terminal state.
 *
 * At submission the gateway charges what was requested (duration, images,
 * resolution), because quotas must hold while the task runs. When the task
 * ends, the charge is corrected:
 *
 *   failed / cancelled / expired    full refund: every usage metric and the cost go to 0
 *   succeeded                       true-up to what the vendor measured (actual
 *                                   video seconds, output tokens, image count,
 *                                   upstream cost), priced with the price that was
 *                                   in force at submission; unmeasured metrics keep
 *                                   the charged value
 *
 * The correction is applied in place to the usage record, the minute / hour /
 * day aggregates and the base-currency cost aggregates (so reports and
 * UsageReconciliationService stay consistent), to the quota and model-quota
 * counters of the period that was charged, and it is journaled in
 * usage_settlements. Every task is settled exactly once (settled_at claim).
 *
 * TASK_BILLING_SETTLEMENT: actual (default) | refund_only | off
 * TASK_BILLING_ALLOW_INCREASE: true (default) also charges more when the
 *   vendor measured more than was requested.
 */
final class TaskBillingService
{
    public const METRICS = ['input_tokens', 'output_tokens', 'total_tokens', 'audio_seconds', 'tts_chars', 'image_count', 'video_seconds'];

    /** Metrics a vendor may report for the finished task. */
    private const MEASURED = ['output_tokens', 'audio_seconds', 'tts_chars', 'image_count', 'video_seconds'];

    private const TERMINAL = ['succeeded', 'failed', 'expired'];

    /** @var null|callable(int, array<string, mixed>, string): array{cost_amount: ?string} */
    private $pricer;

    /**
     * @param null|callable(int, array<string, mixed>, string): array{cost_amount: ?string} $pricer
     */
    public function __construct(?callable $pricer = null)
    {
        $this->pricer = $pricer;
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) env_value('TASK_BILLING_SETTLEMENT', 'actual')));

        return in_array($mode, ['actual', 'refund_only', 'off'], true) ? $mode : 'actual';
    }

    public function settleByTaskId(string $taskId): array
    {
        $task = App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$taskId]);

        return $task === null ? ['settled' => false, 'reason' => 'task_not_found'] : $this->settle($task);
    }

    /**
     * Worker sweep: terminal tasks whose usage record arrived after the task
     * finished, or whose settlement was interrupted.
     */
    public function settlePending(int $limit = 50): int
    {
        if ($this->mode() === 'off') {
            return 0;
        }
        try {
            $rows = App::db()->select(
                'SELECT * FROM multimodal_tasks WHERE status IN ("succeeded", "failed", "expired") AND settled_at IS NULL AND usage_record_id IS NOT NULL ORDER BY id ASC LIMIT ' . max(1, min(500, $limit))
            );
        } catch (\Throwable) {
            return 0;
        }
        $settled = 0;
        foreach ($rows as $row) {
            if (($this->settle($row)['settled'] ?? false) === true) {
                $settled++;
            }
        }

        return $settled;
    }

    /**
     * @param array<string, mixed> $task multimodal_tasks row
     * @return array{settled: bool, reason?: string, cost_delta?: string|null, delta?: array<string, int>}
     */
    public function settle(array $task): array
    {
        if ($this->mode() === 'off') {
            return ['settled' => false, 'reason' => 'disabled'];
        }
        if (!in_array((string) ($task['status'] ?? ''), self::TERMINAL, true)) {
            return ['settled' => false, 'reason' => 'not_terminal'];
        }
        if (!empty($task['settled_at'])) {
            return ['settled' => false, 'reason' => 'already_settled'];
        }
        $record = !empty($task['usage_record_id'])
            ? App::db()->first('SELECT * FROM usage_records WHERE id = ?', [(int) $task['usage_record_id']])
            : null;
        if ($record === null) {
            // The usage record is written by the queue worker; it settles the
            // task itself once linked (UsageService) or the sweep picks it up.
            return ['settled' => false, 'reason' => 'usage_record_pending'];
        }
        if (array_key_exists('aggregated_at', $record) && $record['aggregated_at'] === null) {
            return ['settled' => false, 'reason' => 'usage_record_pending'];
        }
        if (App::db()->execute('UPDATE multimodal_tasks SET settled_at = NOW() WHERE id = ? AND settled_at IS NULL', [(int) $task['id']]) !== 1) {
            return ['settled' => false, 'reason' => 'already_settled'];
        }

        try {
            $charged = $this->charged($record);
            [$final, $reason] = $this->final($task, $record, $charged);
            $delta = [];
            foreach (self::METRICS as $metric) {
                $delta[$metric] = (int) $final[$metric] - (int) $charged[$metric];
            }
            $costDelta = $charged['cost_amount'] === null && $final['cost_amount'] === null
                ? null
                : Decimal::sub((string) ($final['cost_amount'] ?? '0'), (string) ($charged['cost_amount'] ?? '0'));
            $changed = array_filter($delta) !== [] || ($costDelta !== null && Decimal::compare(ltrim($costDelta, '-'), '0') !== 0);

            if ($changed) {
                App::db()->transaction(function () use ($task, $record, $charged, $final, $delta, $costDelta, $reason): void {
                    $this->applyToRecords($record, $final, $delta, $costDelta);
                    $this->journal($task, $record, $reason, $charged, $final, $costDelta);
                });
            }
        } catch (\Throwable $throwable) {
            // Nothing was written: release the claim so the sweep retries.
            App::db()->execute('UPDATE multimodal_tasks SET settled_at = NULL WHERE id = ?', [(int) $task['id']]);
            App::logger()->error('billing', 'task settlement failed', ['task_id' => $task['task_id'] ?? null, 'error' => $throwable->getMessage()]);

            return ['settled' => false, 'reason' => 'error'];
        }

        // The records are corrected and journaled: from here on failures are
        // logged, never retried (a retry would apply the correction twice).
        try {
            if ($changed) {
                $this->applyToQuotas($task, $record, $delta, $costDelta);
            }
        } catch (\Throwable $throwable) {
            App::logger()->error('billing', 'task settlement quota adjustment failed', ['task_id' => $task['task_id'] ?? null, 'error' => $throwable->getMessage()]);
        }
        try {
            $this->rememberOnTask($task, [
                'reason' => $reason,
                'adjusted' => $changed,
                'charged' => $charged,
                'final' => $final,
                'cost_delta' => $costDelta,
                'settled_at' => gmdate('c'),
            ]);
        } catch (\Throwable $throwable) {
            App::logger()->warning('billing', 'task billing summary not stored', ['task_id' => $task['task_id'] ?? null, 'error' => $throwable->getMessage()]);
        }
        App::logger()->info('billing', 'task settled', ['task_id' => $task['task_id'] ?? null, 'reason' => $reason, 'cost_delta' => $costDelta, 'delta' => array_filter($delta)]);

        return ['settled' => true, 'reason' => $reason, 'cost_delta' => $costDelta, 'delta' => array_filter($delta)];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, int|string|null>
     */
    private function charged(array $record): array
    {
        $charged = [];
        foreach (self::METRICS as $metric) {
            $charged[$metric] = (int) ($record[$metric] ?? 0);
        }
        $charged['cost_amount'] = $record['cost_amount'] === null ? null : (string) $record['cost_amount'];

        return $charged;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $record
     * @param array<string, int|string|null> $charged
     * @return array{0: array<string, int|string|null>, 1: string}
     */
    private function final(array $task, array $record, array $charged): array
    {
        $status = (string) $task['status'];
        if ($status !== 'succeeded') {
            $final = array_fill_keys(self::METRICS, 0);
            $final['cost_amount'] = $charged['cost_amount'] === null ? null : '0';
            $reason = match (true) {
                $status === 'expired' => 'refund_expired',
                (string) ($task['error_code'] ?? '') === 'TASK_CANCELLED' => 'refund_cancelled',
                default => 'refund_failed',
            };

            return [$final, $reason];
        }
        if ($this->mode() === 'refund_only') {
            return [$charged, 'kept_requested'];
        }

        $result = json_decode((string) ($task['result'] ?? ''), true);
        $result = is_array($result) ? $result : [];
        $measured = is_array($result['settled_usage'] ?? null) ? $result['settled_usage'] : (is_array($result['provider_response']['vendor_usage'] ?? null) ? $result['provider_response']['vendor_usage'] : []);
        $final = $charged;
        $observed = false;
        foreach (self::MEASURED as $metric) {
            if (isset($measured[$metric]) && is_numeric($measured[$metric]) && (float) $measured[$metric] >= 0) {
                $final[$metric] = (int) ceil((float) $measured[$metric]);
                $observed = true;
            }
        }
        $upstreamCost = isset($measured['upstream_cost']) && is_numeric($measured['upstream_cost']) ? (string) $measured['upstream_cost'] : null;
        if (!$observed && $upstreamCost === null) {
            return [$charged, 'no_measurement'];
        }
        $final['total_tokens'] = max((int) $final['total_tokens'], (int) $final['input_tokens'] + (int) $final['output_tokens']);
        if ((int) $final['output_tokens'] !== (int) $charged['output_tokens']) {
            $final['total_tokens'] = (int) $final['input_tokens'] + (int) $final['output_tokens'];
        }

        if ($charged['cost_amount'] !== null && !empty($task['model_id'])) {
            $payload = json_decode((string) ($task['submit_payload'] ?? ''), true);
            $usage = [...$final, 'usage_source' => 'measured'];
            unset($usage['cost_amount']);
            $dimensions = PricingDimensions::fromPayload((string) ($task['task_type'] ?? 'video_generation'), is_array($payload) ? $payload : []);
            if ($dimensions !== []) {
                $usage['pricing_dimensions'] = $dimensions;
            }
            if ($upstreamCost !== null) {
                $usage['upstream_cost'] = $upstreamCost;
            }
            $at = (string) ($record['created_at'] ?? gmdate('Y-m-d H:i:s'));
            $priced = $this->pricer !== null
                ? ($this->pricer)((int) $task['model_id'], $usage, $at)
                : (new PriceService())->priceUsage((int) $task['model_id'], $usage, $at);
            if ($priced['cost_amount'] !== null) {
                $final['cost_amount'] = (string) $priced['cost_amount'];
            }
        }

        $allowIncrease = filter_var(env_value('TASK_BILLING_ALLOW_INCREASE', true), FILTER_VALIDATE_BOOL);
        if (!$allowIncrease) {
            foreach (self::METRICS as $metric) {
                $final[$metric] = min((int) $final[$metric], (int) $charged[$metric]);
            }
            if ($final['cost_amount'] !== null && $charged['cost_amount'] !== null && Decimal::compare((string) $final['cost_amount'], (string) $charged['cost_amount']) > 0) {
                $final['cost_amount'] = $charged['cost_amount'];
            }
        }

        return [$final, 'actual_usage'];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, int|string|null> $final
     * @param array<string, int> $delta
     */
    private function applyToRecords(array $record, array $final, array $delta, ?string $costDelta): void
    {
        $sets = [];
        $params = [];
        foreach (self::METRICS as $metric) {
            $sets[] = $metric . ' = ?';
            $params[] = (int) $final[$metric];
        }
        $sets[] = 'cost_amount = ?';
        $params[] = $final['cost_amount'];
        $params[] = (int) $record['id'];
        App::db()->execute('UPDATE usage_records SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        $createdAt = strtotime((string) ($record['created_at'] ?? '') . ' UTC') ?: time();
        $currency = isset($record['cost_currency']) && $record['cost_currency'] !== null ? strtoupper((string) $record['cost_currency']) : null;
        $baseCurrency = strtoupper((string) env_value('BILLING_BASE_CURRENCY', 'USD'));
        $baseCostDelta = $costDelta !== null && ($currency === null || $currency === $baseCurrency) ? $costDelta : null;
        $scope = '(department_id = ? OR (department_id IS NULL AND ? IS NULL)) AND (user_id = ? OR (user_id IS NULL AND ? IS NULL)) AND (api_key_id = ? OR (api_key_id IS NULL AND ? IS NULL)) AND (provider_id = ? OR (provider_id IS NULL AND ? IS NULL)) AND (model_id = ? OR (model_id IS NULL AND ? IS NULL))';
        $scopeParams = [];
        foreach (['department_id', 'user_id', 'api_key_id', 'provider_id', 'model_id'] as $column) {
            $scopeParams[] = $record[$column] ?? null;
            $scopeParams[] = $record[$column] ?? null;
        }
        foreach ([
            'usage_aggregates_minute' => gmdate('Y-m-d H:i:00', $createdAt),
            'usage_aggregates_hour' => gmdate('Y-m-d H:00:00', $createdAt),
            'usage_aggregates_day' => gmdate('Y-m-d 00:00:00', $createdAt),
        ] as $table => $bucket) {
            App::db()->execute(
                "UPDATE {$table} SET
                    input_tokens = CASE WHEN CAST(input_tokens AS SIGNED) + ? < 0 THEN 0 ELSE CAST(input_tokens AS SIGNED) + ? END,
                    output_tokens = CASE WHEN CAST(output_tokens AS SIGNED) + ? < 0 THEN 0 ELSE CAST(output_tokens AS SIGNED) + ? END,
                    total_cost = CASE WHEN total_cost IS NULL OR ? IS NULL THEN total_cost ELSE total_cost + ? END
                 WHERE bucket_at = ? AND capability = ? AND {$scope}",
                [$delta['input_tokens'], $delta['input_tokens'], $delta['output_tokens'], $delta['output_tokens'], $baseCostDelta, $baseCostDelta, $bucket, (string) ($record['capability'] ?? 'text_generation'), ...$scopeParams]
            );
        }
        if ($costDelta !== null && $currency !== null && SchemaInspector::hasColumn('usage_cost_aggregates_day', 'currency')) {
            App::db()->execute(
                "UPDATE usage_cost_aggregates_day SET total_cost = total_cost + ? WHERE bucket_at = ? AND currency = ? AND {$scope}",
                [$costDelta, gmdate('Y-m-d 00:00:00', $createdAt), $currency, ...$scopeParams]
            );
        }
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $record
     * @param array<string, int|string|null> $charged
     * @param array<string, int|string|null> $final
     */
    private function journal(array $task, array $record, string $reason, array $charged, array $final, ?string $costDelta): void
    {
        App::db()->execute(
            'INSERT INTO usage_settlements (task_id, usage_record_id, api_key_id, model_id, reason, charged, final, cost_delta, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) $task['task_id'],
                (int) $record['id'],
                $record['api_key_id'] ?? null,
                $record['model_id'] ?? null,
                $reason,
                json_encode($charged, JSON_UNESCAPED_SLASHES),
                json_encode($final, JSON_UNESCAPED_SLASHES),
                $costDelta,
                $record['cost_currency'] ?? null,
            ]
        );
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $record
     * @param array<string, int> $delta
     */
    private function applyToQuotas(array $task, array $record, array $delta, ?string $costDelta): void
    {
        $apiKey = [
            'id' => $record['api_key_id'] ?? $task['api_key_id'] ?? null,
            'user_id' => $record['user_id'] ?? $task['user_id'] ?? null,
            'department_id' => $record['department_id'] ?? $task['department_id'] ?? null,
        ];
        if (empty($apiKey['id'])) {
            return;
        }
        $chargedAt = strtotime((string) ($record['created_at'] ?? '') . ' UTC') ?: time();
        $quotaDelta = $delta;
        if ($costDelta !== null) {
            $quotaDelta['cost_amount'] = (float) $costDelta;
        }
        (new QuotaService())->adjustUsage($apiKey, $quotaDelta, $chargedAt);
        if (!empty($record['model_id'])) {
            (new ApiKeyModelQuotaService())->adjustUsage($apiKey, ['id' => (int) $record['model_id']], $delta, $chargedAt);
        }
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $billing
     */
    private function rememberOnTask(array $task, array $billing): void
    {
        $current = App::db()->first('SELECT result FROM multimodal_tasks WHERE id = ?', [(int) $task['id']]);
        $result = json_decode((string) ($current['result'] ?? ''), true);
        $result = is_array($result) ? $result : [];
        $result['billing'] = $billing;
        App::db()->execute('UPDATE multimodal_tasks SET result = ? WHERE id = ?', [json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $task['id']]);
    }
}
