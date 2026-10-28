<?php

declare(strict_types=1);

use TokenHub\Console\MigrationRunner;
use TokenHub\Support\Database;
use TokenHub\Support\Logger;

$container = require __DIR__ . '/../app/Bootstrap/container.php';

/** @var Database $database */
$database = $container[Database::class];
/** @var Logger $logger */
$logger = $container[Logger::class];

$runner = new MigrationRunner($database, $logger, base_path('database/migrations'));
$applied = $runner->run();

fwrite(STDOUT, "Applied {$applied} migration(s)." . PHP_EOL);

