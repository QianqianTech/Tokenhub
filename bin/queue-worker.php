<?php

declare(strict_types=1);

/*
 * Queue worker.
 *
 *   php bin/queue-worker.php                       consume every queue (default)
 *   php bin/queue-worker.php --queues=usage        only usage.write jobs
 *   php bin/queue-worker.php --queues=export,maintenance
 *   php bin/queue-worker.php --once                process at most one job and exit
 *
 * Queue groups (PERF-015): usage, default (the main queue: webhooks, RAG, ...),
 * tasks, export, maintenance. Without --queues the QUEUE_WORKER_QUEUES env
 * value is used ("all" by default), so existing deployments keep working.
 */

use TokenHub\Console\QueueWorker;
use TokenHub\Support\Config;
use TokenHub\Support\Logger;
use TokenHub\Support\RedisClient;

$container = require __DIR__ . '/../app/Bootstrap/container.php';

$once = in_array('--once', $argv, true);
$queues = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--queues=')) {
        $queues = substr($argument, strlen('--queues='));
    }
}

(new QueueWorker(
    $container[Config::class],
    $container[RedisClient::class],
    $container[Logger::class],
))->run($once, $queues);
