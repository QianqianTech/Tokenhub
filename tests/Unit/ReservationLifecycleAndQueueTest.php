<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Console\QueueWorker;
use TokenHub\Services\ApiKeyModelQuotaService;
use TokenHub\Services\Gateway\RequestLifecycle;
use TokenHub\Services\Gateway\ReservationLeaseStore;
use TokenHub\Services\Gateway\RouteHealth;
use TokenHub\Services\QuotaService;
use TokenHub\Services\UsageService;
use TokenHub\Support\App;
use TokenHub\Support\ConfigVersion;

$redis = App::redis();
try {
    $redis->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

$suffix = bin2hex(random_bytes(4));
$key = static fn (string $metric): string => "quota:unit:{$suffix}:{$metric}";
$counter = static fn (string $metric): float => (float) ($redis->get($key($metric)) ?? 0);
$reserve = static function (array $amounts) use ($redis, $key): array {
    $counters = [];
    foreach ($amounts as $metric => $amount) {
        $counters[] = ['metric' => $metric, 'usage' => $metric === 'requests' ? 'request_count' : $metric, 'amount' => $amount, 'key' => $key($metric), 'limit' => 0, 'ttl' => 300];
    }
    $redis->reserveCounters($counters);
    return [$counters];
};

$quota = new QuotaService();
$modelQuota = new ApiKeyModelQuotaService();

// Rejected before upstream: everything refunded, including the request counter.
$redis->increment($key('concurrency'), 300);
$lifecycle = new RequestLifecycle($quota, $modelQuota, ['id' => 1], [$key('concurrency')], $reserve(['requests' => 1, 'input_tokens' => 100, 'total_tokens' => 600]));
$lifecycle->reject();
$lifecycle->reject();
$lifecycle->finalizeAbandoned();
assert_same(0.0, $counter('requests'), 'Rejected requests do not consume the request limit.');
assert_same(0.0, $counter('input_tokens'), 'Rejected requests refund token reservations.');
assert_same(0.0, $counter('concurrency'), 'Rejected requests release concurrency exactly once.');

// Settled: reservation replaced by actual usage; repeated finalization is a no-op.
$redis->increment($key('concurrency'), 300);
$lifecycle = new RequestLifecycle($quota, $modelQuota, ['id' => 1], [$key('concurrency')], $reserve(['requests' => 1, 'input_tokens' => 100, 'total_tokens' => 600]));
$lifecycle->markUpstreamStarted();
$lifecycle->addReservations($reserve(['input_tokens' => 50, 'total_tokens' => 50]));
assert_same(150.0, $counter('input_tokens'), 'RAG top-up is reserved on the same counter.');
$lifecycle->settle(['input_tokens' => 120, 'total_tokens' => 180]);
$lifecycle->settle(['input_tokens' => 999, 'total_tokens' => 999]);
$lifecycle->finalizeAbandoned();
assert_same(1.0, $counter('requests'), 'Attempted requests keep the request counter.');
assert_same(120.0, $counter('input_tokens'), 'Merged reservations settle to actual usage once (no double counting).');
assert_same(180.0, $counter('total_tokens'), 'Unused output reservation is released.');
assert_same(0.0, $counter('concurrency'), 'Settlement releases concurrency without going negative.');

// Abandoned after upstream start (client abort / fatal error): partial usage is charged.
$redis->increment($key('concurrency'), 300);
$lifecycle = new RequestLifecycle($quota, $modelQuota, ['id' => 1], [$key('concurrency')], $reserve(['input_tokens' => 10]));
$lifecycle->markUpstreamStarted();
$lifecycle->onAbortUsage(static fn (): array => ['input_tokens' => 7]);
$lifecycle->finalizeAbandoned();
assert_same(RequestLifecycle::STATE_SETTLED, $lifecycle->state(), 'Abandoned requests are settled, not left open.');
assert_same(127.0, $counter('input_tokens'), 'Partial usage from the abort resolver is charged.');

// Crash recovery: expired leases release concurrency slots.
$leases = new ReservationLeaseStore($redis);
$redis->increment($key('crash-concurrency'), 300);
$leases->put('req-crash-' . $suffix, ['concurrency_keys' => [$key('crash-concurrency')], 'api_key_id' => 1], 1);
assert_same(0, $leases->sweepExpired(time() - 10), 'Live leases are not touched.');
assert_same(1, $leases->sweepExpired(time() + 5), 'Expired leases are swept.');
assert_same(0, $leases->sweepExpired(time() + 5), 'A lease is released only once.');
assert_same(0.0, $counter('crash-concurrency'), 'Concurrency of a dead process is recovered.');

// Circuit breaker
$_ENV['GATEWAY_CIRCUIT_FAILURES'] = '2';
$health = new RouteHealth();
$modelId = random_int(900000, 999999);
$health->recordSuccess($modelId);
$health->recordFailure($modelId, 'PROVIDER_ERROR');
assert_same('closed', $health->state($modelId), 'One failure keeps the circuit closed.');
$health->recordFailure($modelId, 'PROVIDER_ERROR');
assert_same('open', $health->state($modelId), 'Consecutive failures open the circuit.');
$ordered = $health->order([['id' => $modelId], ['id' => $modelId + 1]]);
assert_same($modelId + 1, $ordered[0]['id'], 'Open channels move behind healthy candidates.');
assert_same('half_open', $health->state($modelId, time() + 3600), 'Cooldown leads to half-open probing.');
$probeOne = $health->order([['id' => $modelId], ['id' => $modelId + 1]], time() + 3600);
assert_same([$modelId, 'half_open'], [$probeOne[0]['id'], $probeOne[0]['circuit_state']], 'The first request after the cooldown probes the channel.');
$probeTwo = $health->order([['id' => $modelId], ['id' => $modelId + 1]], time() + 3600);
assert_same([$modelId + 1, 'open'], [$probeTwo[0]['id'], $probeTwo[1]['circuit_state']], 'Concurrent requests do not pile onto a half-open channel.');
$health->recordSuccess($modelId);
assert_same('closed', $health->state($modelId), 'A success closes the circuit.');
assert_same(false, RouteHealth::countsAsFailure(400, 'PROVIDER_REJECTED'), 'Client errors do not trip the breaker.');
assert_same(true, RouteHealth::countsAsFailure(429, 'PROVIDER_RATE_LIMITED'), 'Rate limits count as channel failures.');
unset($_ENV['GATEWAY_CIRCUIT_FAILURES']);

// Config generation invalidation
$before = ConfigVersion::cacheSuffix();
ConfigVersion::bump('global', 'unit-test');
ConfigVersion::refresh();
assert_true(ConfigVersion::cacheSuffix() !== $before, 'A config bump changes every versioned cache key.');

// Queue: atomic retry, dead-letter, lease renewal
$queue = 'tokenhub:unit:' . $suffix;
$processing = $queue . ':processing';
$failed = $queue . ':failed';
$redis->push($queue, ['type' => 'unit.unknown-job']);
$job = $redis->reserve($queue, $processing, 0, 'unit-worker');
$raw = (string) $job['__reserved_payload'];
$renewed = $redis->renewReserved($processing, $raw, time() + 100);
assert_true($renewed !== null && $renewed !== $raw, 'Lease renewal replaces the reserved copy.');
assert_same(null, $redis->renewReserved($processing, $raw), 'A stale reserved copy cannot be renewed.');
assert_same(0, $redis->requeueExpired($processing, $queue, 30, time() + 110), 'A renewed lease is not requeued early.');
$worker = new QueueWorker(App::config(), $redis, App::logger());
$job['__reserved_payload'] = $renewed;
$worker->process($job, $queue, $failed, 2, 30);
assert_same(1, $redis->length($queue), 'A failing job is moved back atomically for retry.');
assert_same(0, $redis->length($processing), 'The retried job is no longer in processing.');
$retry = $redis->reserve($queue, $processing, 0, 'unit-worker');
$worker->process($retry, $queue, $failed, 2, 30);
assert_same(1, $redis->length($failed), 'After max attempts the job is dead-lettered.');
assert_same(0, $redis->length($queue) + $redis->length($processing), 'No copy of the dead job stays behind.');
$dead = json_decode((string) $redis->connection()->lIndex($failed, 0), true);
assert_true(str_contains((string) ($dead['error'] ?? ''), 'Unsupported job type'), 'Unknown job types are dead-lettered for diagnosis, not silently acked.');
assert_same(false, $redis->moveReserved($processing, '{"not":"reserved"}', $failed, ['x' => 1]), 'A job that is not reserved by this worker is not moved.');

foreach ([$queue, $processing, $failed] as $list) {
    $redis->delete($list);
}
foreach (['requests', 'input_tokens', 'total_tokens', 'concurrency', 'crash-concurrency'] as $metric) {
    $redis->delete($key($metric));
}

// Latency histogram keeps long requests apart (TH-033)
$buckets = (new UsageService())->latencyBuckets(45000, true);
assert_same(1, $buckets['latency_bucket_over_3000'], 'Legacy over-3s bucket still counts long requests.');
assert_same(1, $buckets['latency_bucket_120000'], 'A 45 s stream lands in the 30-120 s bucket.');
assert_same(0, $buckets['latency_bucket_10000'], 'Buckets are exclusive.');
