<?php

declare(strict_types=1);

/*
 * Upgrade the database of an existing installation to the code version in this directory.
 *
 *   php bin/upgrade.php --dry-run     show installed version and pending migrations
 *   php bin/upgrade.php --yes         apply (take a backup first: scripts/backup-tokenhub.sh)
 *
 * Docker: deploy/docker/upgrade.sh (or upgrade.ps1) backs up, switches the image tag and runs this.
 */

use TokenHub\Console\Install\Doctor;
use TokenHub\Console\Install\Installer;
use TokenHub\Console\MigrationRunner;
use TokenHub\Support\Config;
use TokenHub\Support\Database;
use TokenHub\Support\Logger;
use TokenHub\Support\RedisClient;

$basePath = dirname(__DIR__);
require_once $basePath . '/app/Bootstrap/autoload.php';

$args = array_slice($argv, 1);
$config = new Config($basePath . DIRECTORY_SEPARATOR . 'config', $basePath . DIRECTORY_SEPARATOR . '.env');
$database = new Database($config);
$redis = new RedisClient($config);
$logger = new Logger((string) $config->get('logging.path', $basePath . '/storage/logs'));
$installer = new Installer($database, $redis, $logger, $basePath);
$runner = new MigrationRunner($database, $logger, $basePath . '/database/migrations');

try {
    $installer->waitForServices(30);
    $state = $installer->state();
    $status = $runner->status();
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$installed = (string) ($state['version'] ?? 'unknown (installed before version tracking)');
$target = Installer::version($basePath);
fwrite(STDOUT, 'Installed version: ' . $installed . PHP_EOL);
fwrite(STDOUT, 'Code version:      ' . $target . PHP_EOL);
fwrite(STDOUT, 'Pending migrations: ' . count($status['pending']) . PHP_EOL);
foreach ($status['pending'] as $name) {
    fwrite(STDOUT, '  - ' . $name . PHP_EOL);
}

if (in_array('--dry-run', $args, true)) {
    exit(0);
}

if (!in_array('--yes', $args, true)) {
    fwrite(STDERR, 'Back up the database and storage first (scripts/backup-tokenhub.sh), then re-run with --yes.' . PHP_EOL);
    exit(2);
}

try {
    $applied = $installer->migrate();
    $installer->recordState('upgrade', is_file('/.dockerenv') ? 'docker' : 'native');
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'The upgrade stopped. Restore the backup or fix the failing migration and run again.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Applied ' . $applied . ' migration(s). Now at ' . $target . '.' . PHP_EOL);
$results = Doctor::runtimeChecks(Doctor::settingsFrom($config), $database, $redis, $basePath . '/database/migrations');
fwrite(STDOUT, Doctor::render($results));
exit(Doctor::hasFailures($results) ? 1 : 0);
