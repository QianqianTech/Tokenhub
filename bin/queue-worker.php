<?php

declare(strict_types=1);

use TokenHub\Console\QueueWorker;
use TokenHub\Support\Config;
use TokenHub\Support\Logger;
use TokenHub\Support\RedisClient;

$container = require __DIR__ . '/../app/Bootstrap/container.php';

$once = in_array('--once', $argv, true);

(new QueueWorker(
    $container[Config::class],
    $container[RedisClient::class],
    $container[Logger::class],
))->run($once);

