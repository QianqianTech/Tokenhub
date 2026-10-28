<?php

declare(strict_types=1);

/**
 * Inspect and replay dead-lettered queue jobs.
 *
 *   php bin/queue-replay-failed.php --list [--limit=20]
 *   php bin/queue-replay-failed.php --replay --job-id=<id>
 *   php bin/queue-replay-failed.php --replay --type=usage.write [--limit=100]
 *
 * Replayed jobs get attempts reset to 0 and replayed_at set. Jobs are
 * idempotent by business key, so replaying an already applied job is safe.
 */

require __DIR__ . '/_bootstrap.php';

use TokenHub\Support\App;

$failedQueue = (string) App::config()->get('queue.failed_name', 'tokenhub:failed-jobs');
$queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
$limit = max(1, (int) cli_option($argv, 'limit', '20'));
$redis = App::redis()->connection();
$entries = $redis->lRange($failedQueue, 0, -1) ?: [];

if (cli_option($argv, 'list') !== null) {
    $out = [];
    foreach (array_slice($entries, 0, $limit) as $raw) {
        $entry = json_decode((string) $raw, true) ?: [];
        $job = $entry['job'] ?? $entry;
        $out[] = ['job_id' => $job['job_id'] ?? null, 'type' => $job['type'] ?? null, 'attempts' => $job['attempts'] ?? null, 'failed_at' => $entry['failed_at'] ?? null, 'error' => $entry['error'] ?? null];
    }
    echo json_encode(['failed_total' => count($entries), 'items' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if (cli_option($argv, 'replay') === null) {
    fwrite(STDERR, "Use --list or --replay (see file header)\n");
    exit(1);
}

$jobId = cli_option($argv, 'job-id');
$type = cli_option($argv, 'type');
$replayed = 0;
foreach ($entries as $raw) {
    if ($replayed >= $limit) {
        break;
    }
    $entry = json_decode((string) $raw, true) ?: [];
    $job = is_array($entry['job'] ?? null) ? $entry['job'] : $entry;
    if (($jobId !== null && ($job['job_id'] ?? null) !== $jobId) || ($type !== null && ($job['type'] ?? null) !== $type)) {
        continue;
    }
    unset($job['__reserved_payload'], $job['reserved_at'], $job['worker_id']);
    $job['attempts'] = 0;
    $job['replayed_at'] = gmdate('c');
    $script = "local removed = redis.call('LREM', KEYS[1], 1, ARGV[1]) if removed == 1 then redis.call('LPUSH', KEYS[2], ARGV[2]) end return removed";
    if ((int) $redis->eval($script, [$failedQueue, $queue, (string) $raw, (string) json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], 2) === 1) {
        $replayed++;
    }
}
echo json_encode(['replayed' => $replayed], JSON_UNESCAPED_SLASHES) . PHP_EOL;
