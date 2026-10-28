<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$schema = file_get_contents($root . '/database/migrations/0001_create_tokenhub_schema.sql') ?: '';
$migration = file_get_contents($root . '/database/migrations/2026_07_08_000014_cache_usage_metrics.sql') ?: '';
$resources = file_get_contents($root . '/public/admin/assets/js/resources.js') ?: '';
$runner = file_get_contents($root . '/app/Console/MigrationRunner.php') ?: '';

foreach (['cache_read_tokens', 'cache_write_tokens'] as $column) {
    assert_true(str_contains($schema, $column), 'Base schema should include ' . $column . '.');
    assert_true(str_contains($migration, $column), 'Incremental migration should include ' . $column . '.');
    assert_true(str_contains($resources, $column), 'Admin resources should expose ' . $column . '.');
}

assert_true(str_contains($resources, 'cache_tokens'), 'Admin resources should expose total cache tokens.');
assert_true(str_contains($runner, 'cache_usage_metrics'), 'Console migration runner should include cache usage migration files.');
