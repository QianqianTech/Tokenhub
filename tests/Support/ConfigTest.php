<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\Config;

// The assertions below must not depend on the caller's environment (the dev
// container exports APP_ENV=local), so clear the keys the fixture defines.
$saved = [];
foreach (['APP_ENV', 'DB_DATABASE', 'TOKENHUB_CONFIG_TEST_PRECEDENCE'] as $key) {
    $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}

try {
    $config = new Config(__DIR__ . '/../fixtures/config', __DIR__ . '/../fixtures/.env');

    assert_same('testing', $config->get('app.env'), 'Config should load config files.');
    assert_same('tokenhub_test', $config->get('database.database'), 'Config should resolve env placeholders.');
    assert_same('fallback', $config->get('missing.value', 'fallback'), 'Config should return default for missing keys.');
    assert_same('from-file', getenv('TOKENHUB_CONFIG_TEST_PRECEDENCE'), '.env values should be exported when the environment does not define them.');

    // A real environment variable (compose `environment:`, systemd, CI, a
    // test that sets $_ENV before booting) wins over the .env file.
    $_ENV['TOKENHUB_CONFIG_TEST_PRECEDENCE'] = 'from-process';
    new Config(__DIR__ . '/../fixtures/config', __DIR__ . '/../fixtures/.env');
    assert_same('from-process', $_ENV['TOKENHUB_CONFIG_TEST_PRECEDENCE'], 'Process environment should take precedence over .env.');

    unset($_ENV['TOKENHUB_CONFIG_TEST_PRECEDENCE'], $_SERVER['TOKENHUB_CONFIG_TEST_PRECEDENCE']);
    putenv('TOKENHUB_CONFIG_TEST_PRECEDENCE=from-process-env');
    new Config(__DIR__ . '/../fixtures/config', __DIR__ . '/../fixtures/.env');
    assert_same('from-process-env', getenv('TOKENHUB_CONFIG_TEST_PRECEDENCE'), 'getenv() values should take precedence over .env.');
    putenv('TOKENHUB_CONFIG_TEST_PRECEDENCE');

    // A value the loader itself placed may be refreshed when .env is rewritten
    // (installer generating keys, rotate-app-key) and config is reloaded.
    $envFile = tempnam(sys_get_temp_dir(), 'tokenhub-env-');
    file_put_contents($envFile, "TOKENHUB_CONFIG_TEST_RELOAD=first\n");
    new Config(__DIR__ . '/../fixtures/config', $envFile);
    assert_same('first', $_ENV['TOKENHUB_CONFIG_TEST_RELOAD'] ?? null, 'First .env load should export the value.');
    file_put_contents($envFile, "TOKENHUB_CONFIG_TEST_RELOAD=second\n");
    new Config(__DIR__ . '/../fixtures/config', $envFile);
    assert_same('second', $_ENV['TOKENHUB_CONFIG_TEST_RELOAD'] ?? null, 'Reloading a rewritten .env should refresh values it set earlier.');
    unlink($envFile);
    unset($_ENV['TOKENHUB_CONFIG_TEST_RELOAD'], $_SERVER['TOKENHUB_CONFIG_TEST_RELOAD']);
    putenv('TOKENHUB_CONFIG_TEST_RELOAD');
} finally {
    foreach ($saved as $key => [$env, $server, $process]) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
        if ($env !== null) {
            $_ENV[$key] = $env;
        }
        if ($server !== null) {
            $_SERVER[$key] = $server;
        }
        if ($process !== false) {
            putenv($key . '=' . $process);
        }
    }
}
