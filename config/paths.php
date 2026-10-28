<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'logs' => env('LOG_PATH', $root . '/storage/logs'),
    'exports' => env('EXPORT_PATH', $root . '/storage/exports'),
    'media' => env('MEDIA_PATH', $root . '/storage/media'),
    'caddy_data' => env('CADDY_DATA_PATH', $root . '/storage/caddy-data'),
];
