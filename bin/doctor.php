<?php

declare(strict_types=1);

/*
 * TokenHub self-check.
 *
 *   php bin/doctor.php             configuration, platform and running services
 *   php bin/doctor.php --config    configuration and platform only (no network; used by the Docker entrypoint)
 *   php bin/doctor.php --json      machine readable output
 *
 * Exit code 1 when any check fails.
 */

use TokenHub\Console\Install\Doctor;
use TokenHub\Support\Config;
use TokenHub\Support\Database;
use TokenHub\Support\RedisClient;

$basePath = dirname(__DIR__);
require_once $basePath . '/app/Bootstrap/autoload.php';

$args = array_slice($argv, 1);
$config = new Config($basePath . DIRECTORY_SEPARATOR . 'config', $basePath . DIRECTORY_SEPARATOR . '.env');
$settings = Doctor::settingsFrom($config);

$results = [...Doctor::platformChecks($settings), ...Doctor::configChecks($settings)];
if (!in_array('--config', $args, true)) {
    $results = [
        ...$results,
        ...Doctor::runtimeChecks($settings, new Database($config), new RedisClient($config), $basePath . '/database/migrations'),
    ];
}

if (in_array('--json', $args, true)) {
    fwrite(STDOUT, json_encode(['ok' => !Doctor::hasFailures($results), 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} else {
    fwrite(STDOUT, Doctor::render($results));
}

exit(Doctor::hasFailures($results) ? 1 : 0);
