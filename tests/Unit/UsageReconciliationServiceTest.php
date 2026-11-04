<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * BIZ-010 / BIZ-026: UsageReconciliationService.
 * - drift() compares hour aggregates with usage_records, including the
 *   base-currency cost, and ignores rows that are not aggregated yet;
 * - checkDriftAndAlert() raises "usage_drift" only when something differs;
 * - rebuildQuotaCounters() recomputes the current period's Redis counters
 *   (requests, tokens, cost in micro-units) from the detail rows.
 */

use TokenHub\Services\AlertService;
use TokenHub\Services\QuotaService;
use TokenHub\Services\UsageReconciliationService;
use TokenHub\Support\App;
use TokenHub\Support\BusinessTime;
use TokenHub\Support\Database;
use TokenHub\Support\SchemaInspector;

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

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('DATE_FORMAT', static function (?string $value, string $format): ?string {
    if ($value === null) {
        return null;
    }
    return gmdate(strtr($format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's']), strtotime($value . ' UTC'));
});
$pdo->exec('CREATE TABLE usage_records (id INTEGER PRIMARY KEY, request_id TEXT, user_id INT, department_id INT, api_key_id INT, input_tokens INT, output_tokens INT, total_tokens INT, audio_seconds INT DEFAULT 0, tts_chars INT DEFAULT 0, image_count INT DEFAULT 0, video_seconds INT DEFAULT 0, cost_amount TEXT, cost_currency TEXT, aggregated_at TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE usage_aggregates_hour (id INTEGER PRIMARY KEY, bucket_at TEXT, request_count INT, input_tokens INT, output_tokens INT, total_cost TEXT)');
$pdo->exec('CREATE TABLE quota_policies (id INTEGER PRIMARY KEY, name TEXT, period TEXT, status TEXT)');
$pdo->exec('CREATE TABLE quota_bindings (id INTEGER PRIMARY KEY, quota_policy_id INT, target_type TEXT, target_id INT)');

$appDatabase = new ReflectionProperty(App::class, 'database');
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
$appDatabase->setValue(null, $database);
(new ReflectionProperty(SchemaInspector::class, 'memo'))->setValue(null, [
    'usage_records.aggregated_at' => true,
    'usage_records.cost_currency' => true,
]);

$hour = gmdate('Y-m-d H:00:00', time() - 7200);
$at = gmdate('Y-m-d H:05:00', time() - 7200);
$insert = $pdo->prepare('INSERT INTO usage_records (request_id, user_id, department_id, api_key_id, input_tokens, output_tokens, total_tokens, cost_amount, cost_currency, aggregated_at, created_at) VALUES (?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute(['r1', 7, 100, 10, 110, '0.50000000', 'USD', $at, $at]);
$insert->execute(['r2', 7, 50, 5, 55, '0.25000000', null, $at, $at]);
$insert->execute(['r3', 7, 999, 999, 1998, '9.00000000', 'EUR', $at, $at]); // other currency: not in total_cost
$insert->execute(['r4', 7, 1, 1, 2, '1.00000000', 'USD', null, $at]);     // not aggregated yet: skipped
$pdo->prepare('INSERT INTO usage_aggregates_hour (bucket_at, request_count, input_tokens, output_tokens, total_cost) VALUES (?, 3, 1149, 1014, ?)')->execute([$hour, '0.75000000']);

$service = new UsageReconciliationService();
$from = gmdate('Y-m-d H:00:00', time() - 3 * 3600);
$to = gmdate('Y-m-d H:00:00', time());
assert_same([], $service->drift($from, $to), 'Matching counts and base-currency cost: no drift.');

$pdo->exec("UPDATE usage_aggregates_hour SET total_cost = '0.70000000'");
$drift = $service->drift($from, $to);
assert_same(1, count($drift), 'A cost difference is reported.');
assert_same('total_cost', $drift[0]['metric'], 'The drifting metric is the cost.');
assert_same($hour, $drift[0]['bucket_at'], 'The drifting bucket is named.');

$pdo->exec("UPDATE usage_aggregates_hour SET total_cost = '0.75000040', request_count = 2");
$drift = $service->drift($from, $to);
assert_same(['request_count'], array_column($drift, 'metric'), 'Sub-micro cost rounding is tolerated; a missing request is not.');

// compare() treats equal buckets written differently as the same bucket.
assert_same([], $service->compare(
    [['bucket_at' => '2026-10-31 10:00:00', 'request_count' => 1, 'input_tokens' => 1, 'output_tokens' => 1, 'total_cost' => '1.0']],
    [['bucket_at' => '2026-10-31 10:00:00.000', 'request_count' => '1', 'input_tokens' => '1', 'output_tokens' => '1', 'total_cost' => '1.00000000']],
), 'Bucket timestamps and decimal formats are normalised.');

// checkDriftAndAlert raises usage_drift through the alert writer.
$events = [];
$alerts = new AlertService(static function (array $event) use (&$events): void {
    $events[] = $event;
});
$result = $service->checkDriftAndAlert(6, $alerts);
assert_same(1, $result['buckets'], 'One bucket drifts.');
assert_same('usage_drift', $events[0]['event_type'] ?? null, 'Drift raises the usage_drift alert.');
$pdo->exec("UPDATE usage_aggregates_hour SET request_count = 3, total_cost = '0.75'");
$events = [];
$service->checkDriftAndAlert(6, $alerts);
assert_same([], $events, 'No alert once aggregates match again.');

// rebuildQuotaCounters: day policy bound to API key 7.
$pdo->exec("INSERT INTO quota_policies (id, name, period, status) VALUES (900001, 'daily', 'day', 'active'), (900002, 'minute', 'minute', 'active')");
$pdo->exec("INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES (900001, 'api_key', 7), (900002, 'api_key', 7)");
$now = time();
$today = BusinessTime::periodStartUtc('day', $now);
$pdo->exec('DELETE FROM usage_records');
$insert->execute(['q1', 7, 10, 20, 30, '0.10000000', 'USD', $today, gmdate('Y-m-d H:i:s', max(strtotime($today . ' UTC'), $now - 60))]);
$insert->execute(['q2', 7, 1, 2, 3, '0.00000150', 'USD', $today, gmdate('Y-m-d H:i:s', max(strtotime($today . ' UTC'), $now - 30))]);
$insert->execute(['q3', 8, 99, 99, 198, '5.00000000', 'USD', $today, gmdate('Y-m-d H:i:s', $now - 30)]);  // other key
$insert->execute(['q4', 7, 500, 500, 1000, '5.00000000', 'USD', $today, gmdate('Y-m-d H:i:s', strtotime($today . ' UTC') - 60)]); // previous period

$dry = $service->rebuildQuotaCounters(null, true, $now);
assert_same(1, $dry['policies'], 'Minute policies are skipped.');
$quota = new QuotaService();
$values = [];
foreach ($dry['values'] as $entry) {
    $values[$entry['key']] = $entry['value'];
}
$key = static fn (string $metric): string => $quota->usageCounter(900001, 'api_key', 7, 'day', $metric, $now)['key'];
assert_same(2, $values[$key('request_count')] ?? null, 'Requests of the current period for this key.');
assert_same(33, $values[$key('total_tokens')] ?? null, 'Tokens of the current period.');
assert_same(100002, $values[$key('cost_amount')] ?? null, 'Cost in integer micro-units (0.1 + 0.0000015, rounded).');
assert_same(null, App::redis()->get($key('total_tokens')), 'A dry run writes nothing.');

$service->rebuildQuotaCounters(900001, false, $now);
assert_same(33, (int) App::redis()->get($key('total_tokens')), 'The rebuild writes the counters.');
assert_true((int) App::redis()->connection()->ttl($key('total_tokens')) > 0, 'Rebuilt counters expire with their period.');
foreach ($dry['values'] as $entry) {
    App::redis()->delete($entry['key']);
}

echo "OK\n";
