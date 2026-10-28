<?php

declare(strict_types=1);

return [
    'connection' => env('QUEUE_CONNECTION', 'redis'),
    'name' => env('QUEUE_NAME', env('REDIS_QUEUE', 'tokenhub:jobs')),
    'failed_name' => env('QUEUE_FAILED_NAME', 'tokenhub:failed-jobs'),
    'retry_after_seconds' => (int) env('QUEUE_RETRY_AFTER_SECONDS', 30),
    'max_attempts' => (int) env('QUEUE_MAX_ATTEMPTS', 3),
];

