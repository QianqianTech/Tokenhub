<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$migration = __DIR__ . '/../../database/migrations/2026_07_08_000001_create_foundation_tables.sql';

assert_true(is_file($migration), 'Foundation migration SQL file should exist.');

$sql = file_get_contents($migration);
assert_true(str_contains($sql, 'CREATE TABLE IF NOT EXISTS migrations'), 'Migration should create migrations table.');
assert_true(str_contains($sql, 'CREATE TABLE IF NOT EXISTS system_settings'), 'Migration should create system_settings table.');
assert_true(str_contains($sql, 'CREATE TABLE IF NOT EXISTS audit_logs'), 'Migration should create audit_logs table.');
assert_true(str_contains($sql, 'CREATE TABLE IF NOT EXISTS log_retention_policies'), 'Migration should create log_retention_policies table.');

$schema = file_get_contents(__DIR__ . '/../../database/migrations/0001_create_tokenhub_schema.sql');
assert_true(str_contains($schema, 'CREATE TABLE IF NOT EXISTS providers'), 'Full schema should create providers table.');
assert_true(str_contains($schema, 'CREATE TABLE IF NOT EXISTS models'), 'Full schema should create models table.');
assert_true(str_contains($schema, 'CREATE TABLE IF NOT EXISTS model_prices'), 'Full schema should create model_prices table.');
assert_true(str_contains($schema, 'CREATE TABLE IF NOT EXISTS usage_records'), 'Full schema should create usage_records table.');

$multimodalV1 = __DIR__ . '/../../database/migrations/2026_07_08_000006_multimodal_assets_v1.sql';
assert_true(is_file($multimodalV1), 'Multimodal v1 migration SQL file should exist.');

$multimodalSql = file_get_contents($multimodalV1);
assert_true(str_contains($multimodalSql, 'usage_record_id'), 'Multimodal v1 migration should add usage_record_id fields.');
assert_true(str_contains($multimodalSql, 'submit_payload'), 'Multimodal v1 migration should add submit_payload.');
assert_true(str_contains($multimodalSql, 'access_scope'), 'Multimodal v1 migration should add access_scope.');

$logsMigration = __DIR__ . '/../../database/migrations/2026_07_08_000011_logs_observability_audit.sql';
assert_true(is_file($logsMigration), 'Logs observability migration SQL file should exist.');
$logsSql = file_get_contents($logsMigration);
assert_true(str_contains($logsSql, 'CREATE TABLE IF NOT EXISTS log_archive_files'), 'Logs migration should create log_archive_files table.');
assert_true(str_contains($logsSql, 'CREATE TABLE IF NOT EXISTS alert_events'), 'Logs migration should create alert_events table.');
assert_true(str_contains($logsSql, "'gateway-access'"), 'Logs migration should seed gateway-access retention policy.');
assert_true(str_contains($logsSql, "'audit'"), 'Logs migration should seed audit retention policy.');

$runner = file_get_contents(__DIR__ . '/../../app/Console/MigrationRunner.php');
assert_true(str_contains($runner, '*logs_observability*.sql'), 'Console migration runner should include logs observability migrations.');
assert_true(str_contains($runner, '*external_sync*.sql'), 'Console migration runner should include external sync migrations.');

$wafMigration = __DIR__ . '/../../database/migrations/2026_07_10_000017_waf_core_attack_defense.sql';
assert_true(is_file($wafMigration), 'WAF core attack defense migration should exist.');
assert_true(str_contains((string) file_get_contents($wafMigration), 'risk_score'), 'WAF core attack defense migration should add risk score storage.');
assert_true(str_contains($runner, '*waf*.sql'), 'Console migration runner should include WAF migrations.');
