<?php

declare(strict_types=1);

use TokenHub\Support\Config;
use TokenHub\Support\RedisClient;

$container = require __DIR__ . '/../app/Bootstrap/container.php';

/** @var Config $config */
$config = $container[Config::class];
/** @var RedisClient $redis */
$redis = $container[RedisClient::class];

$redis->push((string) $config->get('queue.name', 'tokenhub:jobs'), [
    'type' => 'test.log',
    'payload' => [
        'message' => 'TokenHub queue smoke test',
        'queued_at' => gmdate('c'),
    ],
]);

fwrite(STDOUT, 'Queued test job.' . PHP_EOL);

