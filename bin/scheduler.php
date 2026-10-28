<?php

declare(strict_types=1);

/*
 * Periodic job scheduler: enqueues the maintenance jobs that the queue worker executes.
 * Replaces the crontab entries of earlier releases.
 *
 *   php bin/scheduler.php           run forever (Docker `scheduler` service, systemd tokenhub-scheduler.service)
 *   php bin/scheduler.php --once    enqueue the jobs that are due now and exit (for cron)
 *
 * Several scheduler instances may run; a Redis key per job and time slot prevents duplicates.
 */

use TokenHub\Support\Config;
use TokenHub\Support\RedisClient;

$basePath = dirname(__DIR__);
require_once $basePath . '/app/Bootstrap/autoload.php';

/** job type => interval in seconds */
const TOKENHUB_SCHEDULE = [
    'cleanup.retention' => 60,
    'cleanup.exports' => 300,
    'cleanup.media' => 600,
    'cleanup.external-events' => 3600,
];

$config = new Config($basePath . '/config', $basePath . '/.env');
$redis = new RedisClient($config);
$queue = (string) $config->get('queue.name', 'tokenhub:jobs');
$once = in_array('--once', $argv, true);
$running = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

do {
    $now = time();
    foreach (TOKENHUB_SCHEDULE as $type => $interval) {
        $slot = intdiv($now, $interval);
        try {
            // SET NX: only the first scheduler claims this time slot.
            $claimed = $redis->connection()->set('scheduler:' . $type . ':' . $slot, (string) $now, ['nx', 'ex' => $interval * 2]);
            if ($claimed) {
                $redis->push($queue, ['type' => $type, 'queued_at' => gmdate('c'), 'source' => 'scheduler']);
                fwrite(STDOUT, gmdate('c') . " queued {$type}" . PHP_EOL);
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, gmdate('c') . " scheduler: {$type} not queued: " . $exception->getMessage() . PHP_EOL);
            // Let Docker / systemd restart the process with a fresh Redis connection.
            exit(1);
        }
    }
    @touch(sys_get_temp_dir() . '/tokenhub-scheduler.heartbeat');
    if ($once) {
        break;
    }
    $sleepUntil = (intdiv(time(), 60) + 1) * 60;
    while ($running && time() < $sleepUntil) {
        sleep(1);
    }
} while ($running);
