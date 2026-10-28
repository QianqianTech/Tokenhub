<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\QueueWorker;
use TokenHub\Services\UsageService;
use TokenHub\Services\WorkerService;
use TokenHub\Support\Config;
use TokenHub\Support\JsonLogger;
use TokenHub\Support\RedisClient;

assert_true(method_exists(UsageService::class, 'write'), 'UsageService should expose the canonical usage write method.');
assert_true(!method_exists(WorkerService::class, 'writeUsage'), 'WorkerService should not keep a duplicate private usage write implementation.');

$reflection = new ReflectionClass(QueueWorker::class);
$handle = $reflection->getMethod('handle');
assert_true(str_contains($handle->getFileName(), 'QueueWorker.php'), 'QueueWorker should own queue dispatch.');

assert_true(class_exists(Config::class), 'Queue worker dependencies should remain available.');
assert_true(class_exists(RedisClient::class), 'Redis queue dependency should remain available.');
assert_true(class_exists(JsonLogger::class), 'Queue logger dependency should remain available.');

