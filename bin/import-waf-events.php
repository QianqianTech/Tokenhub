<?php

declare(strict_types=1);

use TokenHub\Services\WafService;

$container = require __DIR__ . '/../app/Bootstrap/container.php';

$file = $argv[1] ?? null;
$result = (new WafService())->ingestSecurityLog(is_string($file) && $file !== '' ? $file : null);

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
