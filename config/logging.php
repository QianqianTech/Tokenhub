<?php

declare(strict_types=1);

return [
    'path' => env('LOG_PATH', base_path('storage/logs')),
    'retention_days' => (int) env('LOG_RETENTION_DAYS', 90),
    'audit_retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 180),
    // BIZ-004: mirror JSON log lines to stdout/stderr. auto = on inside a container.
    'stdout' => env('LOG_STDOUT', 'auto'),
    'max_size_bytes' => (int) env('LOG_MAX_SIZE_BYTES', 104857600),
];

