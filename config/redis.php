<?php

declare(strict_types=1);

return [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => (int) env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD', ''),
    'database' => (int) env('REDIS_DATABASE', 0),
    'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
    // PERF-011: reuse the Redis connection across FPM requests (pconnect).
    'persistent' => env('REDIS_PERSISTENT', true),
    'persistent_id' => env('REDIS_PERSISTENT_ID', 'tokenhub'),
];

