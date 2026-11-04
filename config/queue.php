<?php

declare(strict_types=1);

return [
    'connection' => env('QUEUE_CONNECTION', 'redis'),
    'name' => env('QUEUE_NAME', env('REDIS_QUEUE', 'tokenhub:jobs')),
    'failed_name' => env('QUEUE_FAILED_NAME', 'tokenhub:failed-jobs'),
    'retry_after_seconds' => (int) env('QUEUE_RETRY_AFTER_SECONDS', 30),
    'max_attempts' => (int) env('QUEUE_MAX_ATTEMPTS', 3),
    // BIZ-005: failed jobs wait base * 2^(attempt-1) seconds (capped) in a delayed set.
    'backoff_base_seconds' => (int) env('QUEUE_BACKOFF_BASE_SECONDS', 30),
    'backoff_max_seconds' => (int) env('QUEUE_BACKOFF_MAX_SECONDS', 3600),
    // PERF-022: idle workers block (BLPOP on a wake-up list) instead of polling;
    // expired leases are swept every N seconds.
    'block_timeout_seconds' => (int) env('QUEUE_BLOCK_TIMEOUT_SECONDS', 5),
    'requeue_interval_seconds' => (int) env('QUEUE_REQUEUE_INTERVAL_SECONDS', 20),
    // PERF-015: jobs are routed to sub-queues "<name>:<group>" by type. A worker
    // consumes QUEUE_WORKER_QUEUES (comma list of groups, "all" = every group
    // plus the legacy main queue), so existing single-worker setups keep working.
    'routing' => env('QUEUE_ROUTING', true),
    'worker_queues' => env('QUEUE_WORKER_QUEUES', 'all'),
    'groups' => [
        'usage' => ['usage.write'],
        'tasks' => ['tasks.poll', 'tasks.expire', 'tasks.settle', 'tasks.cancel', 'openrouter.reconcile'],
        'export' => ['export'],
        'maintenance' => ['cleanup.*', 'logs.*', 'alerts.*', 'retention.*', 'usage.reconcile', 'catalog.*', 'rag.cleanup', 'rag.reconcile', 'rag.publish-versions'],
    ],
];

