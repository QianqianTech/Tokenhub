<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Asynchronous task billing settlement (TaskBillingService): refunds of
 * failed / cancelled / expired tasks and true-up of successful ones to the
 * usage the vendor measured, applied to the usage record, the aggregates,
 * the quota counters of the charged period and the settlement journal.
 *
 * SQLite stands in for MySQL (like GatewayFlowTest); Redis must be reachable.
 */

use TokenHub\Services\Billing\TaskBillingService;
use TokenHub\Services\QuotaService;
use TokenHub\Support\App;
use TokenHub\Support\Database;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}
$appDatabase = new ReflectionProperty(App::class, 'database');
$originalDatabase = $appDatabase->getValue();
foreach (['TASK_BILLING_SETTLEMENT', 'TASK_BILLING_ALLOW_INCREASE', 'BILLING_BASE_CURRENCY'] as $variable) {
    unset($_ENV[$variable]);
    putenv($variable);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('FIELD', static function (...$args): int {
    $needle = array_shift($args);
    $index = array_search($needle, $args, true);
    return $index === false ? 0 : $index + 1;
});
$aggregateColumns = 'id INTEGER PRIMARY KEY, bucket_at TEXT, department_id INT, user_id INT, api_key_id INT, provider_id INT, model_id INT, capability TEXT, request_count INT, success_count INT, failed_count INT, input_tokens INT, output_tokens INT, cache_read_tokens INT, cache_write_tokens INT, total_cost REAL, latency_ms_sum INT';
foreach ([
    'CREATE TABLE multimodal_tasks (id INTEGER PRIMARY KEY, task_id TEXT UNIQUE, task_type TEXT, status TEXT, progress INT, provider_task_id TEXT, user_id INT, department_id INT, api_key_id INT, provider_id INT, model_id INT, usage_record_id INT, submit_payload TEXT, result TEXT, error_code TEXT, error_message TEXT, latency_ms INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, finished_at TEXT, settled_at TEXT)',
    'CREATE TABLE usage_records (id INTEGER PRIMARY KEY, request_id TEXT, user_id INT, department_id INT, api_key_id INT, provider_id INT, model_id INT, capability TEXT, status TEXT, input_tokens INT, output_tokens INT, total_tokens INT, cache_read_tokens INT DEFAULT 0, cache_write_tokens INT DEFAULT 0, audio_seconds INT DEFAULT 0, tts_chars INT DEFAULT 0, image_count INT DEFAULT 0, video_seconds INT DEFAULT 0, cost_amount TEXT, cost_currency TEXT, aggregated_at TEXT, created_at TEXT)',
    "CREATE TABLE usage_aggregates_minute ({$aggregateColumns})",
    "CREATE TABLE usage_aggregates_hour ({$aggregateColumns})",
    "CREATE TABLE usage_aggregates_day ({$aggregateColumns})",
    'CREATE TABLE usage_cost_aggregates_day (id INTEGER PRIMARY KEY, bucket_at TEXT, department_id INT, user_id INT, api_key_id INT, provider_id INT, model_id INT, currency TEXT, request_count INT, total_cost REAL)',
    'CREATE TABLE usage_settlements (id INTEGER PRIMARY KEY, task_id TEXT UNIQUE, usage_record_id INT, api_key_id INT, model_id INT, reason TEXT, charged TEXT, final TEXT, cost_delta TEXT, currency TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE model_prices (id INTEGER PRIMARY KEY, model_id INT, effective_at TEXT, currency TEXT, input_token_price TEXT, output_token_price TEXT, cache_read_token_price TEXT, cache_write_token_price TEXT, reasoning_token_price TEXT, audio_second_price TEXT, tts_char_price TEXT, image_price TEXT, video_second_price TEXT, request_price TEXT, image_input_token_price TEXT, image_output_token_price TEXT, audio_input_token_price TEXT, audio_output_token_price TEXT, pricing_rules TEXT)',
    'CREATE TABLE quota_policies (id INTEGER PRIMARY KEY, name TEXT, period TEXT, status TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, audio_second_limit INT, tts_char_limit INT, image_limit INT, video_second_limit INT, cost_limit REAL, concurrency_limit INT)',
    'CREATE TABLE quota_bindings (id INTEGER PRIMARY KEY, quota_policy_id INT, target_type TEXT, target_id INT)',
    'CREATE TABLE api_key_model_quotas (id INTEGER PRIMARY KEY, api_key_id INT, model_id INT, period TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, status TEXT)',
] as $sql) {
    $pdo->exec($sql);
}
// SchemaInspector reads information_schema, which SQLite lacks: declare the
// currency column of the cost aggregates (present on every migrated MySQL).
App::redis()->set('schema:column:usage_cost_aggregates_day.currency', '1', 300);
\TokenHub\Support\SchemaInspector::forget();
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
$appDatabase->setValue(null, $database);

$keyId = random_int(600000, 699999);
$policyId = random_int(700000, 799999);
$videoModel = 301;
$tokenModel = 302;
$pdo->exec("INSERT INTO model_prices (model_id, effective_at, currency, video_second_price) VALUES ({$videoModel}, '2020-01-01 00:00:00', 'USD', '0.10000000')");
$pdo->exec("INSERT INTO model_prices (model_id, effective_at, currency, output_token_price) VALUES ({$tokenModel}, '2020-01-01 00:00:00', 'USD', '0.00000200')");
$pdo->exec("INSERT INTO quota_policies (id, name, period, status, video_second_limit, cost_limit) VALUES ({$policyId}, 'video', 'day', 'active', 1000, 100)");
$pdo->exec("INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES ({$policyId}, 'api_key', {$keyId})");
App::redis()->delete((new QuotaService())->policyCacheKey(['id' => $keyId, 'user_id' => 1, 'department_id' => 1]));

$now = gmdate('Y-m-d H:i:s');
$bucket = gmdate('Ymd');
$secondsKey = "quota:{$policyId}:api_key:{$keyId}:video_seconds:{$bucket}";
$costKey = "quota:{$policyId}:api_key:{$keyId}:cost_amount:{$bucket}";

/**
 * A task charged at submission: usage record + aggregates + quota counters,
 * exactly as UsageService / QuotaService left them.
 */
$charge = static function (string $taskId, string $status, array $charged, array $result = [], ?string $errorCode = null, int $modelId = 301, bool $withRecord = true) use ($pdo, $keyId, $now, $secondsKey, $costKey): void {
    static $next = 1;
    $recordId = null;
    if ($withRecord) {
        $recordId = $next++;
        $pdo->prepare('INSERT INTO usage_records (id, request_id, user_id, department_id, api_key_id, provider_id, model_id, capability, status, input_tokens, output_tokens, total_tokens, video_seconds, cost_amount, cost_currency, aggregated_at, created_at) VALUES (?, ?, 1, 1, ?, 9, ?, "video_generation", "success", ?, ?, ?, ?, ?, "USD", ?, ?)')
            ->execute([$recordId, 'req_' . $taskId, $keyId, $modelId, $charged['input_tokens'] ?? 0, $charged['output_tokens'] ?? 0, ($charged['input_tokens'] ?? 0) + ($charged['output_tokens'] ?? 0), $charged['video_seconds'] ?? 0, $charged['cost_amount'], $now, $now]);
        foreach (['usage_aggregates_minute' => gmdate('Y-m-d H:i:00'), 'usage_aggregates_hour' => gmdate('Y-m-d H:00:00'), 'usage_aggregates_day' => gmdate('Y-m-d 00:00:00')] as $table => $bucketAt) {
            $existing = $pdo->query("SELECT id FROM {$table} WHERE bucket_at = '{$bucketAt}' AND model_id = {$modelId}")->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE {$table} SET request_count = request_count + 1, input_tokens = input_tokens + ?, output_tokens = output_tokens + ?, total_cost = total_cost + ? WHERE id = ?")
                    ->execute([$charged['input_tokens'] ?? 0, $charged['output_tokens'] ?? 0, $charged['cost_amount'], $existing['id']]);
            } else {
                $pdo->prepare("INSERT INTO {$table} (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, total_cost, latency_ms_sum) VALUES (?, 1, 1, ?, 9, ?, 'video_generation', 1, 1, 0, ?, ?, 0, 0, ?, 0)")
                    ->execute([$bucketAt, $keyId, $modelId, $charged['input_tokens'] ?? 0, $charged['output_tokens'] ?? 0, $charged['cost_amount']]);
            }
        }
        $existingCost = $pdo->query("SELECT id FROM usage_cost_aggregates_day WHERE model_id = {$modelId}")->fetch();
        if ($existingCost) {
            $pdo->prepare('UPDATE usage_cost_aggregates_day SET total_cost = total_cost + ? WHERE id = ?')->execute([$charged['cost_amount'], $existingCost['id']]);
        } else {
            $pdo->prepare("INSERT INTO usage_cost_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, currency, request_count, total_cost) VALUES (?, 1, 1, ?, 9, ?, 'USD', 1, ?)")
                ->execute([gmdate('Y-m-d 00:00:00'), $keyId, $modelId, $charged['cost_amount']]);
        }
        App::redis()->incrementByFloat($secondsKey, (float) ($charged['video_seconds'] ?? 0), 86400);
        App::redis()->incrementByFloat($costKey, (float) $charged['cost_amount'], 86400);
    }
    $pdo->prepare('INSERT INTO multimodal_tasks (task_id, task_type, status, provider_task_id, user_id, department_id, api_key_id, model_id, usage_record_id, submit_payload, result, error_code, finished_at) VALUES (?, "video_generation", ?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$taskId, $status, 'up_' . $taskId, $keyId, $modelId, $recordId, json_encode(['model' => 'seedance', 'duration' => $charged['video_seconds'] ?? 0, 'resolution' => '1080p']), json_encode($result), $errorCode, $now]);
};
$task = static fn (string $taskId): array => App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$taskId]);
$record = static fn (string $taskId): array => App::db()->first('SELECT r.* FROM usage_records r JOIN multimodal_tasks t ON t.usage_record_id = r.id WHERE t.task_id = ?', [$taskId]);
$dayCost = static fn (int $modelId = 301): float => (float) App::db()->first('SELECT total_cost FROM usage_aggregates_day WHERE model_id = ?', [$modelId])['total_cost'];
$counter = static fn (string $key): float => (float) (App::redis()->get($key) ?? 0);
$billing = new TaskBillingService();

// 1. A failed task is fully refunded everywhere.
App::redis()->delete($secondsKey);
App::redis()->delete($costKey);
$charge('t_fail', 'failed', ['video_seconds' => 10, 'cost_amount' => '1.00000000'], [], 'PROVIDER_TASK_FAILED');
assert_same(10.0, $counter($secondsKey), 'Precondition: 10 s charged to the quota.');
$outcome = $billing->settleByTaskId('t_fail');
assert_same(true, $outcome['settled'], 'Failed task is settled.');
assert_same('refund_failed', $outcome['reason'], 'Reason is a failure refund.');
assert_same('-1.00000000', $outcome['cost_delta'], 'The whole charge is refunded.');
assert_same(0, (int) $record('t_fail')['video_seconds'], 'Usage record no longer bills the seconds.');
assert_same(0.0, (float) $record('t_fail')['cost_amount'], 'Usage record cost is 0.');
assert_same(0.0, $dayCost(), 'Daily aggregate cost is reduced.');
assert_same(0.0, (float) App::db()->first('SELECT total_cost FROM usage_cost_aggregates_day WHERE model_id = 301')['total_cost'], 'Currency cost aggregate is reduced.');
assert_same(0.0, $counter($secondsKey), 'Quota video seconds are given back.');
assert_same(0.0, $counter($costKey), 'Quota cost is given back.');
$journal = App::db()->first('SELECT * FROM usage_settlements WHERE task_id = ?', ['t_fail']);
assert_same('refund_failed', $journal['reason'] ?? null, 'The refund is journaled.');
assert_same('refund_failed', json_decode((string) $task('t_fail')['result'], true)['billing']['reason'] ?? null, 'The task shows its billing outcome.');
assert_true(!empty($task('t_fail')['settled_at']), 'The task is marked settled.');

// 2. Exactly once.
assert_same('already_settled', $billing->settleByTaskId('t_fail')['reason'], 'A settled task is never settled twice.');
assert_same(0.0, $counter($costKey), 'Counters are not touched again.');

// 3. Success with a shorter measured duration: true-up down.
$charge('t_short', 'succeeded', ['video_seconds' => 10, 'cost_amount' => '1.00000000'], ['settled_usage' => ['video_seconds' => 6]]);
$short = $billing->settleByTaskId('t_short');
assert_same('actual_usage', $short['reason'], 'Measured usage is applied.');
assert_same('-0.40000000', $short['cost_delta'], '4 s at 0.10 are refunded.');
assert_same(6, (int) $record('t_short')['video_seconds'], 'Usage record bills the measured seconds.');
assert_same(0.6, round((float) $record('t_short')['cost_amount'], 8), 'Usage record cost is the measured cost.');
assert_same(6.0, $counter($secondsKey), 'Quota holds the measured seconds.');
assert_same(0.6, round($counter($costKey), 8), 'Quota holds the measured cost.');

// 4. Success with a longer measured duration: charged more by default...
$charge('t_long', 'succeeded', ['video_seconds' => 5, 'cost_amount' => '0.50000000'], ['settled_usage' => ['video_seconds' => 12]]);
assert_same('0.70000000', $billing->settleByTaskId('t_long')['cost_delta'], 'Model-chosen longer video is charged for its real length.');
assert_same(12, (int) $record('t_long')['video_seconds'], 'Usage record shows the real length.');
// ...but never above the request when increases are disabled.
$_ENV['TASK_BILLING_ALLOW_INCREASE'] = 'false';
$charge('t_long_capped', 'succeeded', ['video_seconds' => 5, 'cost_amount' => '0.50000000'], ['settled_usage' => ['video_seconds' => 12]]);
$capped = $billing->settleByTaskId('t_long_capped');
assert_same(false, (bool) json_decode((string) $task('t_long_capped')['result'], true)['billing']['adjusted'], 'With increases disabled nothing changes.');
assert_same(5, (int) $record('t_long_capped')['video_seconds'], 'The requested length stays the cap.');
unset($_ENV['TASK_BILLING_ALLOW_INCREASE']);

// 5. Token-priced video (Seedance): measured completion tokens set the cost.
$charge('t_tokens', 'succeeded', ['video_seconds' => 5, 'cost_amount' => '0.00000000'], ['settled_usage' => ['video_seconds' => 5, 'output_tokens' => 108900]], null, $tokenModel);
$tokens = $billing->settleByTaskId('t_tokens');
assert_same('0.21780000', $tokens['cost_delta'], '108900 output tokens at 0.000002 are charged.');
assert_same(108900, (int) $record('t_tokens')['output_tokens'], 'Output tokens are recorded.');
assert_same(108900, (int) App::db()->first('SELECT output_tokens FROM usage_aggregates_day WHERE model_id = ?', [$tokenModel])['output_tokens'], 'Token aggregates follow.');

// 6. Cancelled and expired tasks are refunded with their own reason.
$charge('t_cancel', 'failed', ['video_seconds' => 5, 'cost_amount' => '0.50000000'], [], 'TASK_CANCELLED');
assert_same('refund_cancelled', $billing->settleByTaskId('t_cancel')['reason'], 'Cancellation refund.');
$charge('t_expired', 'expired', ['video_seconds' => 5, 'cost_amount' => '0.50000000']);
assert_same('refund_expired', $billing->settleByTaskId('t_expired')['reason'], 'Expiry refund.');

// 7. Usage record not written yet: settlement waits, then runs.
$charge('t_late', 'failed', ['video_seconds' => 3, 'cost_amount' => '0.30000000'], [], 'PROVIDER_TASK_FAILED', 301, false);
assert_same('usage_record_pending', $billing->settleByTaskId('t_late')['reason'], 'No usage record yet: pending.');
assert_true(empty($task('t_late')['settled_at']), 'Pending tasks stay unsettled.');
$pdo->exec("INSERT INTO usage_records (id, request_id, api_key_id, user_id, department_id, provider_id, model_id, capability, status, input_tokens, output_tokens, total_tokens, video_seconds, cost_amount, cost_currency, aggregated_at, created_at) VALUES (99, 'req_late', {$keyId}, 1, 1, 9, 301, 'video_generation', 'success', 0, 0, 0, 3, '0.30000000', 'USD', '{$now}', '{$now}')");
$pdo->exec("UPDATE multimodal_tasks SET usage_record_id = 99 WHERE task_id = 't_late'");
assert_same(1, $billing->settlePending(), 'The sweep settles it once the record exists.');
assert_same(0, (int) $record('t_late')['video_seconds'], 'Late record is refunded.');

// 8. An expired quota window is not resurrected as a negative counter.
App::redis()->delete($secondsKey);
App::redis()->delete($costKey);
$pdo->exec("INSERT INTO usage_records (id, request_id, api_key_id, user_id, department_id, provider_id, model_id, capability, status, input_tokens, output_tokens, total_tokens, video_seconds, cost_amount, cost_currency, aggregated_at, created_at) VALUES (100, 'req_old', {$keyId}, 1, 1, 9, 301, 'video_generation', 'success', 0, 0, 0, 4, '0.40000000', 'USD', '{$now}', '{$now}')");
$pdo->exec("INSERT INTO multimodal_tasks (task_id, task_type, status, api_key_id, model_id, usage_record_id, result, error_code) VALUES ('t_old', 'video_generation', 'failed', {$keyId}, 301, 100, '{}', 'PROVIDER_TASK_FAILED')");
$billing->settleByTaskId('t_old');
assert_same(false, (bool) App::redis()->connection()->exists($secondsKey), 'No stray counter is created for a period that is gone.');

// 9. Modes.
$_ENV['TASK_BILLING_SETTLEMENT'] = 'refund_only';
$charge('t_refund_only', 'succeeded', ['video_seconds' => 10, 'cost_amount' => '1.00000000'], ['settled_usage' => ['video_seconds' => 4]]);
assert_same('kept_requested', $billing->settleByTaskId('t_refund_only')['reason'], 'refund_only keeps the requested charge for successes.');
assert_same(10, (int) $record('t_refund_only')['video_seconds'], 'Nothing changed.');
$_ENV['TASK_BILLING_SETTLEMENT'] = 'off';
$charge('t_off', 'failed', ['video_seconds' => 10, 'cost_amount' => '1.00000000'], [], 'PROVIDER_TASK_FAILED');
assert_same('disabled', $billing->settleByTaskId('t_off')['reason'], 'off disables settlement.');
unset($_ENV['TASK_BILLING_SETTLEMENT']);

// 10. Unfinished tasks are never settled; success without measurement is a no-op.
$charge('t_running', 'processing', ['video_seconds' => 5, 'cost_amount' => '0.50000000']);
assert_same('not_terminal', $billing->settleByTaskId('t_running')['reason'], 'Running tasks wait.');
$charge('t_unmeasured', 'succeeded', ['video_seconds' => 5, 'cost_amount' => '0.50000000']);
assert_same('no_measurement', $billing->settleByTaskId('t_unmeasured')['reason'], 'Without vendor figures the requested charge stands.');

foreach ([$secondsKey, $costKey] as $key) {
    App::redis()->delete($key);
}
$appDatabase->setValue(null, $originalDatabase);
echo "TaskBillingSettlementTest OK\n";
