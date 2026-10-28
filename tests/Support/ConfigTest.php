<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\Config;

$config = new Config(__DIR__ . '/../fixtures/config', __DIR__ . '/../fixtures/.env');

assert_same('testing', $config->get('app.env'), 'Config should load config files.');
assert_same('tokenhub_test', $config->get('database.database'), 'Config should resolve env placeholders.');
assert_same('fallback', $config->get('missing.value', 'fallback'), 'Config should return default for missing keys.');

