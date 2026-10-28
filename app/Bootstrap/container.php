<?php

declare(strict_types=1);

use TokenHub\Support\Config;
use TokenHub\Support\Database;
use TokenHub\Support\Logger;
use TokenHub\Support\RedisClient;

require_once __DIR__ . '/autoload.php';

$config = new Config(base_path('config'), base_path('.env'));

return [
    Config::class => $config,
    Database::class => new Database($config),
    RedisClient::class => new RedisClient($config),
    Logger::class => new Logger((string) $config->get('logging.path', base_path('storage/logs'))),
];

