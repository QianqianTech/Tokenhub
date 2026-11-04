<?php

declare(strict_types=1);

return [
    'connection' => env('DB_CONNECTION', 'mysql'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'tokenhub'),
    'username' => env('DB_USERNAME', 'tokenhub'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    // PERF-011b: persistent PDO connections (rolled back / locks released on reuse).
    'persistent' => env('DB_PERSISTENT', true),
];

