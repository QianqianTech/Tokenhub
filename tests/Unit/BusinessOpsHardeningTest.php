<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/BusinessSqliteFixture.php';

/*
 * Business / operations hardening (task list section 5b):
 * BIZ-002 export truncation, BIZ-006 media cleanup audit, BIZ-007 webhook
 * signatures and retry schedule, BIZ-009 retention purges, BIZ-011 price
 * versions and snapshot pricing, BIZ-016 vendor error rate, BIZ-017
 * readiness, BIZ-019 cancelled deliveries, BIZ-020 upstream cancel,
 * BIZ-021 poll back-off, BIZ-022 per-key limits, BIZ-024 metrics.
 */

use TokenHub\Http\Controllers\MetricsController;
use TokenHub\Middleware\ApiKeyAuthMiddleware;
use TokenHub\Services\AlertService;
use TokenHub\Services\Billing\PriceVersionPolicy;
use TokenHub\Services\Billing\TaskBillingService;
use TokenHub\Services\DataRetentionService;
use TokenHub\Services\ExportService;
use TokenHub\Services\HealthCheckService;
use TokenHub\Services\MultimodalTaskService;
use TokenHub\Services\WebhookService;
use TokenHub\Services\WorkerService;
use TokenHub\Support\App;
use TokenHub\Support\PrometheusWriter;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
$_ENV['APP_KEY'] = $_ENV['APP_KEY'] ?? 'unit-test-key-for-business-ops-hardening';

// ---------------------------------------------------------------- BIZ-007 webhook signatures
$webhooks = new WebhookService();
$secret = 'whsec_test';
$body = '{"event":"request.success"}';
$ts = 1730419200;
$v2 = $webhooks->signatureV2($secret, $body, $ts);
assert_same('sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret), $v2, 'V2 signs "<timestamp>.<body>".');
assert_true(WebhookService::verifySignatureV2($secret, $body, (string) $ts, $v2, $ts + 10), 'A fresh V2 signature verifies.');
assert_true(!WebhookService::verifySignatureV2($secret, $body, (string) $ts, $v2, $ts + 301), 'A replay outside the 300 s window is rejected.');
assert_true(!WebhookService::verifySignatureV2($secret, $body, (string) ($ts + 1), $v2, $ts), 'A changed timestamp breaks the signature.');
assert_true(!WebhookService::verifySignatureV2($secret, $body . ' ', (string) $ts, $v2, $ts), 'A changed body breaks the signature.');
$headers = $webhooks->signedHeaders(['secret' => $secret, 'event_type' => 'request.success', 'delivery_id' => 'whd_1'], $body, $ts);
assert_true(in_array('X-TokenHub-Timestamp: ' . $ts, $headers, true), 'Deliveries carry X-TokenHub-Timestamp.');
assert_true(in_array('X-TokenHub-Signature-V2: ' . $v2, $headers, true), 'Deliveries carry the V2 signature.');
assert_true(in_array('X-TokenHub-Signature: ' . $webhooks->signature($secret, $body), $headers, true), 'The legacy signature is still sent.');
assert_same([60, 300, 1800, 7200, 43200], array_map([WebhookService::class, 'retryDelaySeconds'], [1, 2, 3, 4, 5]), 'Retries back off 1m / 5m / 30m / 2h / 12h.');
assert_same(6, WebhookService::maxAttempts(), 'First attempt + 5 retries.');
assert_same(false, $webhooks->isDeliveryDue(['status' => 'cancelled', 'next_attempt_at' => null]), 'Cancelled deliveries are never due.');

// ---------------------------------------------------------------- BIZ-022 per-key limits
assert_same(600, ApiKeyAuthMiddleware::limitFor(['rate_limit_model_per_minute' => null], 'model_per_minute', 600), 'NULL keeps the global limit.');
assert_same(5, ApiKeyAuthMiddleware::limitFor(['rate_limit_model_per_minute' => '5'], 'model_per_minute', 600), 'A per-key override wins.');
assert_same(0, ApiKeyAuthMiddleware::limitFor(['rate_limit_write_per_minute' => 0], 'write_per_minute', 60), '0 disables the bucket for that key.');

// ---------------------------------------------------------------- BIZ-011 price versions
$effective = ['effective_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'input_token_price' => '0.0000020000', 'name' => 'x'];
assert_same(['input_token_price'], PriceVersionPolicy::blockedChanges($effective, ['input_token_price' => '0.000003']), 'An effective price cannot be rewritten.');
assert_same([], PriceVersionPolicy::blockedChanges($effective, ['input_token_price' => '0.000002']), 'Re-saving the same value is allowed.');
assert_same([], PriceVersionPolicy::blockedChanges($effective, ['name' => 'renamed']), 'Non-price fields stay editable.');
assert_same([], PriceVersionPolicy::blockedChanges(['effective_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'input_token_price' => '1'], ['input_token_price' => '2']), 'A scheduled (future) price can still be edited.');

$snapshot = json_encode(['price_id' => 7, 'currency' => 'USD', 'effective_at' => '2026-01-01 00:00:00', 'video_second_price' => '0.10', 'source' => 'catalog_estimate']);
$priced = TaskBillingService::priceFromSnapshot($snapshot, ['video_seconds' => 8], gmdate('Y-m-d H:i:s'));
assert_same(0, bccomp_like($priced['cost_amount'] ?? null, '0.8'), 'Settlement prices with the snapshot taken at submission.');
$upstream = TaskBillingService::priceFromSnapshot(json_encode(['source' => 'upstream_cost', 'upstream_cost_multiplier' => '1.5', 'video_second_price' => '9']), ['upstream_cost' => '2', 'video_seconds' => 8], gmdate('Y-m-d H:i:s'));
assert_same(0, bccomp_like($upstream['cost_amount'] ?? null, '3'), 'Cost-plus snapshots apply their multiplier to the measured upstream cost.');
assert_same(null, TaskBillingService::priceFromSnapshot(null, ['video_seconds' => 1], gmdate('Y-m-d H:i:s')), 'Without a snapshot the catalog is used.');

// ---------------------------------------------------------------- BIZ-002 export truncation
$export = new ExportService();
$path = sys_get_temp_dir() . '/tokenhub-export-test-' . bin2hex(random_bytes(4)) . '.csv.enc';
$rows = (static function (): Generator {
    for ($i = 1; $i <= 5; $i++) {
        yield ['id' => $i, 'value' => 'v' . $i];
    }
})();
$result = $export->writeCsv($path, $rows, 3);
assert_same(['rows' => 3, 'truncated' => true], $result, 'Hitting the row cap is reported, not silent.');
$csv = $export->decryptCsvFromStorage((string) file_get_contents($path));
assert_same("id,value\n1,v1\n2,v2\n3,v3\n", $csv, 'Rows up to the cap are written with one header.');
assert_same(['rows' => 2, 'truncated' => false], $export->writeCsv($path, [['a' => 1], ['a' => 2]], 2), 'Exactly the cap is not truncated.');
@unlink($path);
$pages = [];
$paged = iterator_to_array($export->paginate(static function (?int $lastId, int $limit) use (&$pages): array {
    $pages[] = $lastId;
    $all = [['id' => 9], ['id' => 8], ['id' => 7], ['id' => 6], ['id' => 5]];
    return array_slice(array_values(array_filter($all, static fn (array $row): bool => $lastId === null || $row['id'] < $lastId)), 0, $limit);
}, static fn (array $row): array => $row, 2), false);
assert_same([9, 8, 7, 6, 5], array_column($paged, 'id'), 'Keyset pagination returns every row once, newest first.');
assert_same([null, 8, 6], $pages, 'Each page continues after the last id of the previous one.');

// ---------------------------------------------------------------- BIZ-006 media cleanup audit
$audited = [];
(new WorkerService())->cleanupExpiredMediaFilesForRows(
    [['id' => 1, 'storage_path' => '/gone/a.png'], ['id' => 2, 'storage_path' => '/here/b.png']],
    static fn (string $file): bool => $file === '/here/b.png',
    static function (int $id, bool $deleted) use (&$audited): void {
        if ($deleted) {
            $audited[] = $id;
        }
    },
);
assert_same([2], $audited, 'Only assets whose file was really removed are audited.');

// ---------------------------------------------------------------- BIZ-021 poll back-off
assert_same(15, MultimodalTaskService::pollDelaySeconds(true, 0), 'After a successful poll: 15 s.');
assert_same(30, MultimodalTaskService::pollDelaySeconds(false, 1), 'First failure: 30 s.');
assert_same(120, MultimodalTaskService::pollDelaySeconds(false, 3), 'Back-off doubles per failure.');
assert_same(600, MultimodalTaskService::pollDelaySeconds(false, 20), 'Capped at 10 minutes.');

// ---------------------------------------------------------------- SQLite-backed services
$pdo = business_sqlite();
$pdo->exec('CREATE TABLE multimodal_tasks (id INTEGER PRIMARY KEY, task_id TEXT, task_type TEXT, status TEXT, provider_task_id TEXT, provider_id INT, model_id INT, result TEXT, next_poll_at TEXT, poll_failures INT DEFAULT 0, upstream_cancel_status TEXT, upstream_final_status TEXT, error_code TEXT, error_message TEXT, finished_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE models (id INTEGER PRIMARY KEY, provider_id INT, name TEXT, external_name TEXT)');
$pdo->exec('CREATE TABLE providers (id INTEGER PRIMARY KEY, protocol TEXT, base_url TEXT, encrypted_secret TEXT, default_headers TEXT, timeout_ms INT, retry_count INT)');
$pdo->exec('CREATE TABLE usage_aggregates_minute (id INTEGER PRIMARY KEY, bucket_at TEXT)');
$pdo->exec('CREATE TABLE alert_events (id INTEGER PRIMARY KEY, status TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE alert_notifications (id INTEGER PRIMARY KEY, status TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE external_webhook_deliveries (id INTEGER PRIMARY KEY, webhook_id INT, status TEXT, next_attempt_at TEXT, error_summary TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE dashboard_share_access_logs (id INTEGER PRIMARY KEY, created_at TEXT)');
$pdo->exec('CREATE TABLE rag_retrieval_logs (id INTEGER PRIMARY KEY, created_at TEXT)');
$pdo->exec('CREATE TABLE usage_records (id INTEGER PRIMARY KEY, provider_id INT, status TEXT, error_code TEXT, created_at TEXT)');
business_use_sqlite($pdo, [
    'multimodal_tasks.next_poll_at' => true,
    'multimodal_tasks.poll_failures' => true,
    'multimodal_tasks.upstream_cancel_status' => true,
]);

// BIZ-009 retention: batched, keeps what must stay
$old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
$recent = gmdate('Y-m-d H:i:s', time() - 3600);
for ($i = 0; $i < 250; $i++) {
    $pdo->prepare('INSERT INTO usage_aggregates_minute (bucket_at) VALUES (?)')->execute([$old]);
}
$pdo->prepare('INSERT INTO usage_aggregates_minute (bucket_at) VALUES (?)')->execute([$recent]);
$pdo->prepare("INSERT INTO alert_events (status, created_at) VALUES ('resolved', ?), ('open', ?), ('resolved', ?)")->execute([$old, $old, $recent]);
$pdo->prepare("INSERT INTO external_webhook_deliveries (webhook_id, status, created_at) VALUES (1, 'succeeded', ?), (1, 'pending', ?), (1, 'dead', ?)")->execute([$old, $old, $recent]);
$retention = new DataRetentionService($pdo instanceof PDO ? App::db() : null, [
    'batch_size' => 100,
    'max_batches' => 10,
    'usage_aggregates_minute_days' => 14,
    'alert_events_days' => 30,
    'alert_notifications_days' => 30,
    'webhook_deliveries_days' => 30,
    'dashboard_share_access_logs_days' => 0,
    'rag_retrieval_logs_days' => 30,
]);
$purged = $retention->purge();
assert_same(250, $purged['usage_aggregates_minute'], 'Old minute aggregates are deleted in batches of 100.');
assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM usage_aggregates_minute')->fetchColumn(), 'Recent minute aggregates stay.');
assert_same(1, $purged['alert_events'], 'Old resolved alerts are purged.');
assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM alert_events')->fetchColumn(), 'Open alerts and recent alerts stay.');
assert_same(['pending'], array_column($pdo->query("SELECT status FROM external_webhook_deliveries WHERE created_at < '" . gmdate('Y-m-d', time() - 86400) . "'")->fetchAll(), 'status'), 'Old finished deliveries go; a pending one stays.');
assert_true(!array_key_exists('dashboard_share_access_logs', $purged), '0 days disables a purge.');
assert_same([], (new DataRetentionService(App::db(), ['archive_enabled' => false]))->archive(), 'usage_records / audit_logs archiving is off by default.');

// BIZ-019: deleting a webhook cancels what is still waiting
$pdo->exec("INSERT INTO external_webhook_deliveries (webhook_id, status, created_at) VALUES (2, 'failed', '{$recent}'), (2, 'succeeded', '{$recent}')");
assert_same(1, $webhooks->cancelPendingDeliveries(2), 'Waiting deliveries of the webhook are cancelled.');
assert_same(['cancelled', 'succeeded'], array_column($pdo->query('SELECT status FROM external_webhook_deliveries WHERE webhook_id = 2 ORDER BY id')->fetchAll(), 'status'), 'Finished deliveries keep their status.');

// BIZ-016: local rejections do not count as vendor failures
$pdo->exec("INSERT INTO usage_records (provider_id, status, error_code, created_at) VALUES
    (1, 'failed', 'QUOTA_EXCEEDED', '{$recent}'), (1, 'failed', 'MODEL_QUOTA_EXCEEDED', '{$recent}'), (1, 'failed', 'API_KEY_EXPIRED', '{$recent}'),
    (1, 'failed', 'PROVIDER_ERROR', '{$recent}'), (1, 'timeout', 'PROVIDER_TIMEOUT', '{$recent}'), (1, 'failed', NULL, '{$recent}'),
    (1, 'blocked', 'CONTENT_FORBIDDEN', '{$recent}'), (1, 'success', NULL, '{$recent}')");
$vendorFailures = (int) $pdo->query('SELECT COUNT(*) FROM usage_records WHERE ' . AlertService::providerFailureCondition())->fetchColumn();
assert_same(3, $vendorFailures, 'Only failed/timeout rows without a local error code are vendor failures.');

// BIZ-021: a failing poll pushes the task back
$pdo->exec("INSERT INTO multimodal_tasks (id, task_id, task_type, status, provider_task_id, model_id, created_at, updated_at) VALUES
    (1, 'task_a', 'video_generation', 'processing', 'up_a', 1, '{$old}', '{$old}'),
    (2, 'task_b', 'video_generation', 'processing', 'up_b', 1, '{$old}', '{$old}')");
$polled = [];
$refreshed = (new MultimodalTaskService())->pollProcessing(1, static function (array $row) use (&$polled): array {
    $polled[] = $row['task_id'];
    if ($row['task_id'] === 'task_a') {
        throw new RuntimeException('vendor 500');
    }
    return ['refreshed' => true];
});
assert_same(['task_a', 'task_b'], $polled, 'The batch grows to the number of due tasks.');
assert_same(1, $refreshed, 'One poll succeeded.');
$a = $pdo->query("SELECT next_poll_at, poll_failures FROM multimodal_tasks WHERE task_id = 'task_a'")->fetch();
assert_same(1, (int) $a['poll_failures'], 'The failure is counted.');
assert_true(strtotime($a['next_poll_at'] . ' UTC') >= time() + 25, 'A failed task waits before its next poll instead of blocking the queue head.');
$polled = [];
(new MultimodalTaskService())->pollProcessing(20, static function (array $row) use (&$polled): array {
    $polled[] = $row['task_id'];
    return ['refreshed' => true];
});
assert_same([], $polled, 'Tasks are not polled again before next_poll_at.');

// BIZ-020: upstream cancel of a timed-out Ark task
$pdo->exec("INSERT INTO providers (id, protocol, base_url, default_headers, timeout_ms, retry_count) VALUES (1, 'openai', 'https://ark.example', '{\"tokenhub_adapter\":\"volcengine_ark\"}', 1000, 0), (2, 'openai', 'https://plain.example', NULL, 1000, 0)");
$pdo->exec("INSERT INTO models (id, provider_id, name, external_name) VALUES (1, 1, 'seedance', 'seedance-1'), (2, 2, 'plain', 'plain')");
$pdo->exec("INSERT INTO multimodal_tasks (id, task_id, task_type, status, provider_task_id, model_id, created_at, updated_at) VALUES
    (3, 'task_c', 'video_generation', 'failed', 'up_c', 1, '{$old}', '{$old}'),
    (4, 'task_d', 'video_generation', 'failed', 'up_d', 2, '{$old}', '{$old}')");
$calls = [];
$send = static function (string $endpoint, array $route, array $options) use (&$calls): array {
    $calls[] = $options['method'] . ' ' . $endpoint;
    return $options['method'] === 'GET'
        ? ['status' => 200, 'json' => ['status' => 'queued'], 'body' => '']
        : ['status' => 200, 'json' => [], 'body' => ''];
};
$cancel = (new MultimodalTaskService())->cancelUpstream('task_c', $send);
assert_same('cancelled', $cancel['cancel'], 'A vendor that supports cancelling is asked to cancel.');
assert_same(['GET /contents/generations/tasks/up_c', 'DELETE /contents/generations/tasks/up_c'], $calls, 'The final vendor status is read before cancelling.');
assert_same(['cancelled', 'queued'], array_values($pdo->query("SELECT upstream_cancel_status, upstream_final_status FROM multimodal_tasks WHERE task_id = 'task_c'")->fetch(PDO::FETCH_NUM)), 'Cancel outcome and upstream status are recorded.');
assert_same('unsupported', (new MultimodalTaskService())->cancelUpstream('task_d', $send)['cancel'], 'Vendors without a cancel API are recorded as unsupported.');

// ---------------------------------------------------------------- BIZ-017 readiness
$_ENV['READINESS_REQUIRE'] = 'queue-worker';
assert_same(['queue-worker'], (new HealthCheckService(App::db(), App::redis()))->requiredServices(), 'READINESS_REQUIRE selects the components /ready waits for.');
unset($_ENV['READINESS_REQUIRE']);
assert_same(['queue-worker', 'scheduler'], (new HealthCheckService(App::db(), App::redis()))->requiredServices(), 'By default the queue worker and scheduler are required; RAG pieces are optional.');

// ---------------------------------------------------------------- BIZ-024 metrics
unset($_ENV['METRICS_TOKEN']);
putenv('METRICS_TOKEN');
$request = static fn (string $auth = ''): Request => new Request('GET', '/metrics', $auth !== '' ? ['HTTP_AUTHORIZATION' => $auth] : [], [], [], '', new RequestContext('req_m', 'trc_m'));
assert_same(404, (new MetricsController())->metrics($request())->status, '/metrics is off while METRICS_TOKEN is empty.');
$_ENV['METRICS_TOKEN'] = 'metrics-secret';
assert_same(401, (new MetricsController())->metrics($request())->status, 'A token is required.');
assert_same(401, (new MetricsController())->metrics($request('Bearer wrong'))->status, 'A wrong token is rejected.');
unset($_ENV['METRICS_TOKEN']);
$writer = new PrometheusWriter();
$writer->gauge('tokenhub_queue_length', 'Jobs ready.', [[['queue' => 'tokenhub:jobs'], 3]]);
$writer->histogram('tokenhub_provider_latency_ms', 'Latency.', [[['provider' => 'a"b', 'le' => '100'], 1], [['provider' => 'a"b', 'le' => '+Inf'], 2]], [[['provider' => 'a"b'], 350]], [[['provider' => 'a"b'], 2]]);
$text = $writer->render();
assert_true(str_contains($text, "# TYPE tokenhub_queue_length gauge\ntokenhub_queue_length{queue=\"tokenhub:jobs\"} 3\n"), 'Gauges use the Prometheus text format.');
assert_true(str_contains($text, 'tokenhub_provider_latency_ms_bucket{provider="a\"b",le="+Inf"} 2'), 'Histogram buckets are emitted with escaped labels.');
assert_true(str_contains($text, 'tokenhub_provider_latency_ms_count{provider="a\"b"} 2'), 'Histograms have _count.');

echo "OK\n";

function bccomp_like(?string $a, string $b): int
{
    if ($a === null) {
        return -2;
    }

    return \TokenHub\Support\Decimal::compare(\TokenHub\Support\Decimal::normalize($a), \TokenHub\Support\Decimal::normalize($b));
}
