<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\Config;
use TokenHub\Support\RedisClient;

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tokenhub-reliable-queue-test-' . bin2hex(random_bytes(4));
mkdir($base . DIRECTORY_SEPARATOR . 'config', 0777, true);
file_put_contents($base . DIRECTORY_SEPARATOR . '.env', implode(PHP_EOL, [
    'REDIS_HOST=redis',
    'REDIS_PORT=6379',
    'REDIS_DATABASE=0',
    'REDIS_TIMEOUT=2',
]));
file_put_contents($base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'redis.php', <<<'PHP'
<?php
return [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => (int) env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD', ''),
    'database' => (int) env('REDIS_DATABASE', 0),
    'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
];
PHP);

$redis = new RedisClient(new Config($base));
$queue = 'tokenhub:test:reliable:' . bin2hex(random_bytes(6));
$processing = $queue . ':processing';

$redis->delete($queue);
$redis->delete($processing);

$redis->push($queue, ['type' => 'usage.write', 'record' => ['request_id' => 'rq-test']]);

$reserved = $redis->reserve($queue, $processing, 1, 'worker-a');
assert_true(is_array($reserved), 'Reliable queue should reserve a pending job.');
assert_same('usage.write', $reserved['type'] ?? null, 'Reserved job should preserve the job type.');
assert_same('worker-a', $reserved['worker_id'] ?? null, 'Reserved job should record the worker id.');
assert_true(isset($reserved['job_id']) && is_string($reserved['job_id']), 'Reserved job should have a stable job id.');
assert_true(isset($reserved['reserved_at']), 'Reserved job should include reserved_at.');
assert_same(0, $redis->length($queue), 'Pending queue should be empty after reserve.');
assert_same(1, $redis->length($processing), 'Processing queue should contain the reserved job.');

$moved = $redis->requeueExpired($processing, $queue, 1, time() + 2);
assert_same(1, $moved, 'Expired processing job should be moved back to pending.');
assert_same(1, $redis->length($queue), 'Pending queue should contain the requeued job.');
assert_same(0, $redis->length($processing), 'Processing queue should be empty after requeue.');

$reservedAgain = $redis->reserve($queue, $processing, 1, 'worker-b');
assert_true(is_array($reservedAgain), 'Requeued job should be reservable again.');
assert_same($reserved['job_id'], $reservedAgain['job_id'], 'Requeued job should keep the same job id.');
$redis->ackReserved($processing, (string) $reservedAgain['__reserved_payload']);
assert_same(0, $redis->length($processing), 'Ack should remove the reserved job from processing.');

$redis->delete($queue);
$redis->delete($processing);
