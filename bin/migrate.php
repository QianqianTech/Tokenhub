<?php

declare(strict_types=1);

/*
 * Apply database migrations.
 *
 *   php bin/migrate.php            apply all pending migrations (MySQL advisory lock held while running)
 *   php bin/migrate.php --status   list applied / pending migrations
 *   php bin/migrate.php --pretend  list what would run without changing the database
 */

use TokenHub\Console\MigrationRunner;
use TokenHub\Support\Database;
use TokenHub\Support\Logger;

$container = require __DIR__ . '/../app/Bootstrap/container.php';

$args = array_slice($argv, 1);
/** @var Database $database */
$database = $container[Database::class];
/** @var Logger $logger */
$logger = $container[Logger::class];

$runner = new MigrationRunner($database, $logger, base_path('database/migrations'));

try {
    if (in_array('--status', $args, true) || in_array('--pretend', $args, true)) {
        $status = $runner->status();
        if (in_array('--pretend', $args, true)) {
            foreach ($status['pending'] as $name) {
                fwrite(STDOUT, 'would apply ' . $name . PHP_EOL);
            }
            fwrite(STDOUT, count($status['pending']) . ' pending migration(s).' . PHP_EOL);
            exit(0);
        }
        foreach ($status['applied'] as $name) {
            fwrite(STDOUT, '[applied] ' . $name . PHP_EOL);
        }
        foreach ($status['pending'] as $name) {
            fwrite(STDOUT, '[pending] ' . $name . PHP_EOL);
        }
        fwrite(STDOUT, count($status['applied']) . ' applied, ' . count($status['pending']) . ' pending.' . PHP_EOL);
        exit(0);
    }

    $applied = $runner->run(static function (string $name): void {
        fwrite(STDOUT, 'applying ' . $name . PHP_EOL);
    });
    fwrite(STDOUT, "Applied {$applied} migration(s)." . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
