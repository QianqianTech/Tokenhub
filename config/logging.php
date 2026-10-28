<?php

declare(strict_types=1);

return [
    'path' => env('LOG_PATH', base_path('storage/logs')),
    'retention_days' => (int) env('LOG_RETENTION_DAYS', 90),
    'audit_retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 180),
];

