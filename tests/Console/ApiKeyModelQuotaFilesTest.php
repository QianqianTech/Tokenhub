<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$schema = file_get_contents($root . '/database/migrations/0001_create_tokenhub_schema.sql') ?: '';
$migration = file_get_contents($root . '/database/migrations/2026_07_08_000012_api_key_model_quotas.sql') ?: '';
$resources = file_get_contents($root . '/public/admin/assets/js/resources.js') ?: '';

assert_true(str_contains($schema, 'CREATE TABLE IF NOT EXISTS api_key_model_quotas'), 'Base schema should create api_key_model_quotas.');
assert_true(str_contains($migration, 'api_key_model_quotas'), 'Incremental migration should create api_key_model_quotas.');
assert_true(str_contains($resources, '"api-key-model-quotas"'), 'Admin resources should expose api-key-model-quotas.');
assert_true(str_contains($resources, '0 或留空表示不限制') || str_contains($resources, '0 or empty means unlimited'), 'Admin labels should explain unlimited quota input.');
