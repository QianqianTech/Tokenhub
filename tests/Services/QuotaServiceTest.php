<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\QuotaService;
use TokenHub\Support\App;

$service = new QuotaService();

$minute = $service->periodWindow('minute', strtotime('2026-07-08 12:34:56 UTC'));
assert_same('202607081234', $minute['bucket'], 'Minute quota bucket should include minute.');
assert_same(60, $minute['ttl'], 'Minute quota TTL should be 60 seconds.');

$month = $service->periodWindow('month', strtotime('2026-07-08 12:34:56 UTC'));
assert_same('202607', $month['bucket'], 'Month quota bucket should include year and month.');
assert_true($month['ttl'] >= 2419200, 'Month quota TTL should cover at least 28 days.');

$allowed = $service->firstExceededLimit([
    'request_limit' => 10,
    'total_token_limit' => 100,
    'cost_limit' => '5.00',
], [
    'request_count' => 1,
    'total_tokens' => 99,
    'cost_amount' => '4.99',
]);
assert_same(null, $allowed, 'Usage below all limits should be allowed.');

$zeroUnlimited = $service->firstExceededLimit([
    'request_limit' => 0,
    'input_token_limit' => null,
    'output_token_limit' => 0,
    'total_token_limit' => 0,
    'cost_limit' => '0.00',
    'concurrency_limit' => 0,
], [
    'request_count' => 999,
    'input_tokens' => 999,
    'output_tokens' => 999,
    'total_tokens' => 999,
    'cost_amount' => '999.00',
    'concurrency' => 999,
]);
assert_same(null, $zeroUnlimited, 'Null and zero quota limits should be unlimited.');

$blocked = $service->firstExceededLimit([
    'request_limit' => 10,
    'total_token_limit' => 100,
], [
    'request_count' => 1,
    'total_tokens' => 101,
]);
assert_same('QUOTA_EXCEEDED', $blocked['code'], 'Token limit breach should return QUOTA_EXCEEDED.');
assert_same('total_token_limit', $blocked['limit'], 'Exceeded limit should identify total_token_limit.');

$concurrency = $service->firstExceededLimit(['concurrency_limit' => 2], ['concurrency' => 3]);
assert_same('CONCURRENCY_EXCEEDED', $concurrency['code'], 'Concurrency limit breach should return CONCURRENCY_EXCEEDED.');

assert_same(false, $service->requiresCostTrackingForPolicies([
    ['cost_limit' => null],
    ['cost_limit' => '0.00'],
]), 'Policies without a positive cost limit should not require synchronous cost pricing.');
assert_same(true, $service->requiresCostTrackingForPolicies([
    ['cost_limit' => null],
    ['cost_limit' => '1.00'],
]), 'A positive cost limit should require synchronous cost pricing.');

$db = App::db();
$apiKeyId = random_int(100000, 999999);
$policyName = 'Perf Quota Policy ' . bin2hex(random_bytes(4));
$cleanup = static function () use ($db, $policyName): void {
    $policyIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT id FROM quota_policies WHERE name = ?', [$policyName])
    );
    if ($policyIds === []) {
        return;
    }
    $placeholders = implode(', ', array_fill(0, count($policyIds), '?'));
    $db->execute("DELETE FROM quota_bindings WHERE quota_policy_id IN ({$placeholders})", $policyIds);
    $db->execute("DELETE FROM quota_policies WHERE id IN ({$placeholders})", $policyIds);
};
$cleanup();
register_shutdown_function($cleanup);

$db->execute(
    'INSERT INTO quota_policies (name, period, request_limit, status) VALUES (?, "minute", 1, "active")',
    [$policyName]
);
$policyId = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES (?, "api_key", ?)',
    [$policyId, $apiKeyId]
);
$apiKey = ['id' => $apiKeyId, 'user_id' => 0, 'department_id' => 0];
App::redis()->delete($service->policyCacheKey($apiKey));

$firstPreflight = $service->preflight($apiKey);
assert_same(true, $firstPreflight['allowed'], 'First preflight should allow the first request under a one-request policy.');

$db->execute('UPDATE quota_policies SET status = "disabled" WHERE id = ?', [$policyId]);
$secondPreflight = $service->preflight($apiKey);
assert_same(false, $secondPreflight['allowed'], 'Warm quota preflight should use cached policy metadata before the short TTL expires.');
