<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * PERF-011 / PERF-015 / PERF-022 / BIZ-005 / BIZ-008 / BIZ-001:
 * - job types are routed to sub-queues; a default worker consumes them all;
 * - a worker restricted to some groups only sees those queues;
 * - queued jobs carry the request/trace ids, workers adopt them in logs;
 * - Lua scripts run by SHA and survive SCRIPT FLUSH (NOSCRIPT fallback);
 * - the database session is pinned to UTC.
 */

use TokenHub\Console\QueueWorker;
use TokenHub\Services\WorkerService;
use TokenHub\Support\App;
use TokenHub\Support\Config;
use TokenHub\Support\Database;
use TokenHub\Support\JsonLogger;
use TokenHub\Support\Logger;
use TokenHub\Support\QueueRouter;
use TokenHub\Support\RedisClient;
use TokenHub\Support\RequestContext;

try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

$suffix = bin2hex(random_bytes(4));
$base = sys_get_temp_dir() . '/tokenhub-queue-routing-' . $suffix;
mkdir($base . '/config', 0777, true);
file_put_contents($base . '/.env', "QUEUE_NAME=tokenhub:unit-routing:{$suffix}\nREDIS_HOST=127.0.0.1\n");
copy(__DIR__ . '/../../config/queue.php', $base . '/config/queue.php');
copy(__DIR__ . '/../../config/redis.php', $base . '/config/redis.php');
$config = new Config($base);
$router = new QueueRouter($config);
$main = 'tokenhub:unit-routing:' . $suffix;

// Routing table
assert_same($main . ':usage', $router->queueFor('usage.write'), 'usage.write has its own queue.');
assert_same($main . ':export', $router->queueFor('export'), 'Exports have their own queue.');
assert_same($main . ':tasks', $router->queueFor('tasks.poll'), 'Task polling goes to the tasks queue.');
assert_same($main . ':maintenance', $router->queueFor('cleanup.media'), 'Wildcard patterns route maintenance jobs.');
assert_same($main . ':maintenance', $router->queueFor('retention.purge'), 'Retention purges are maintenance.');
assert_same($main, $router->queueFor('webhook.deliver'), 'Unknown / other types stay on the main queue.');
assert_same($main, $router->queueFor('rag.vectorize'), 'RAG jobs stay on the main queue.');
assert_same('other:queue', $router->route('other:queue', ['type' => 'usage.write']), 'Explicit non-main queues are never rerouted.');
assert_same([$main . ':usage', $main, $main . ':tasks', $main . ':export', $main . ':maintenance'], $router->consumedQueues('all'), 'The default worker consumes every queue, usage first.');
assert_same([$main . ':export', $main . ':maintenance'], $router->consumedQueues('export, maintenance'), 'A worker can be restricted to some groups.');
assert_same([$main], $router->consumedQueues('default'), '"default" means the main queue.');
assert_same($router->allQueues(), $router->consumedQueues('nonsense'), 'An unusable list falls back to all queues.');

// push() routes and carries the request context
$redis = new RedisClient($config);
$context = App::context();
$previous = $context->adopt('req_routing_' . $suffix, 'trc_routing_' . $suffix);
$redis->push($main, ['type' => 'usage.write', 'record' => ['request_id' => 'x']]);
$redis->push($main, ['type' => 'webhook.deliver', 'delivery_id' => 'whd_1']);
$context->restore($previous);
assert_same(1, $redis->length($main . ':usage'), 'usage.write was routed to the usage queue.');
assert_same(1, $redis->length($main), 'webhook.deliver stayed on the main queue.');
$queues = [];
foreach ($router->consumedQueues('all') as $name) {
    $queues[$name] = QueueRouter::processing($name);
}
$first = $redis->reserveAny($queues, 0, 'unit');
assert_same($main . ':usage', $first['__queue'] ?? null, 'Usage jobs are reserved first.');
assert_same('req_routing_' . $suffix, $first['request_context']['request_id'] ?? null, 'Queued jobs carry the request id of the producer.');
assert_same('trc_routing_' . $suffix, $first['request_context']['trace_id'] ?? null, 'Queued jobs carry the trace id of the producer.');

// The worker adopts the job's ids while it runs, then restores its own.
$seen = [];
$worker = new QueueWorker($config, $redis, App::logger(), new WorkerService(null, null, [
    'usage.write' => static function () use (&$seen): void {
        $seen = App::context()->propagation();
    },
]));
$before = App::context()->propagation();
$worker->process($first, $main . ':usage', $main . ':failed', 3, 30);
assert_same('req_routing_' . $suffix, $seen['request_id'] ?? null, 'Logs written by the job carry the producer request id.');
assert_same($before, App::context()->propagation(), 'The worker restores its own context after the job.');
assert_same(0, $redis->length($main . ':usage:processing'), 'The processed job was acknowledged on its own queue.');
$second = $redis->reserveAny($queues, 0, 'unit');
assert_same($main, $second['__queue'] ?? null, 'The main queue is consumed by the default worker.');
$redis->ackReserved(QueueRouter::processing($main), (string) $second['__reserved_payload']);

// A worker limited to "export" never sees a usage job.
$redis->push($main, ['type' => 'usage.write', 'record' => []]);
$exportOnly = [$main . ':export' => QueueRouter::processing($main . ':export')];
assert_same(null, $redis->reserveAny($exportOnly, 0, 'unit'), 'An export-only worker does not take usage jobs.');
$job = $redis->reserve($main . ':usage', $main . ':usage:processing', 0, 'unit');
$redis->ackReserved($main . ':usage:processing', (string) $job['__reserved_payload']);

// EVALSHA with NOSCRIPT fallback
$connection = $redis->connection();
$key = 'tokenhub:unit-routing:counter:' . $suffix;
$redis->incrementByFloat($key, 1.5, 60);
$connection->script('flush');
assert_same(3.0, $redis->incrementByFloat($key, 1.5, 60), 'Scripts are reloaded after SCRIPT FLUSH (NOSCRIPT fallback).');
assert_same(4.5, $redis->incrementByFloat($key, 1.5, 60), 'The reloaded script runs by SHA again.');
assert_same(false, $redis->evalScript('return redis.call("GET", KEYS[1])', ['tokenhub:unit-routing:missing:' . $suffix], 1), 'A nil script result stays false, like EVAL.');

foreach ([...array_keys($queues), ...array_values($queues)] as $list) {
    $redis->delete($list);
    $redis->delete($list . ':notify');
    $redis->delete($list . ':delayed');
}
$redis->delete($key);

// RequestContext: header validation, traceparent, adopt/restore
$fromTraceparent = RequestContext::fromHeaders(['HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
assert_same('4bf92f3577b34da6a3ce929d0e0e4736', $fromTraceparent->traceId(), 'A W3C traceparent sets the trace id.');
$injected = RequestContext::fromHeaders(['HTTP_X_REQUEST_ID' => "evil\nline"]);
assert_true(str_starts_with($injected->requestId(), 'req_'), 'Request ids with control characters are replaced.');
assert_true(preg_match('/^00-4bf92f3577b34da6a3ce929d0e0e4736-[0-9a-f]{16}-01$/', $fromTraceparent->traceparent()) === 1, 'traceparent keeps a hex trace id.');
assert_true(preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', (new RequestContext('req_1', 'trc_custom'))->traceparent()) === 1, 'Non-hex trace ids are hashed into a valid traceparent.');

// JsonLogger records carry the correlation ids
$logDir = $base . '/logs';
$adopted = App::context()->adopt('req_json_' . $suffix, 'trc_json_' . $suffix);
Logger::setStdout(false);
(new JsonLogger($logDir))->info('unit', 'hello', ['password' => 'secret']);
App::context()->restore($adopted);
$line = json_decode((string) file_get_contents($logDir . '/unit.jsonl'), true);
assert_same('req_json_' . $suffix, $line['request_id'] ?? null, 'JsonLogger injects the request id.');
assert_same('trc_json_' . $suffix, $line['trace_id'] ?? null, 'JsonLogger injects the trace id.');
assert_same('****', $line['context']['password'] ?? null, 'JsonLogger still masks secrets.');

// BIZ-001: UTC session; PERF-011b: reused persistent connections are reset.
assert_same("SET time_zone = '+00:00'", Database::sessionInitSql(), 'Every MySQL session runs in UTC.');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE t (id INTEGER)');
$pdo->beginTransaction();
$pdo->exec('INSERT INTO t VALUES (1)');
Database::resetReusedConnection($pdo);
assert_same(false, $pdo->inTransaction(), 'A transaction left open by a dead request is rolled back on reuse.');
assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(), 'Its writes are discarded.');

echo "OK\n";
