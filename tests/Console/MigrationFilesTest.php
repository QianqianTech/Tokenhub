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

$discovered = array_map('basename', \TokenHub\Console\MigrationRunner::discover(__DIR__ . '/../../database/migrations'));
assert_true(in_array('2026_07_08_000011_logs_observability_audit.sql', $discovered, true), 'Console migration runner should include logs observability migrations.');
assert_true(in_array('2026_07_08_000013_external_sync_api.sql', $discovered, true), 'Console migration runner should include external sync migrations.');
assert_true(in_array('0001_create_tokenhub_schema.sql', $discovered, true), 'Console migration runner should apply the full schema on fresh installs.');
assert_true(in_array('2026_07_08_000006_multimodal_assets_v1.sql', $discovered, true), 'Console migration runner should include multimodal migrations.');

$removeWafMigration = (string) file_get_contents(__DIR__ . '/../../database/migrations/2026_10_28_000001_foundation_remove_waf.sql');
foreach (['waf_events', 'waf_rule_versions', 'waf_rules', 'system_ip_bans'] as $table) {
    assert_true(str_contains($removeWafMigration, 'DROP TABLE IF EXISTS ' . $table), 'Remove-WAF migration should drop ' . $table . '.');
}
$removeIndex = array_search('2026_10_28_000001_foundation_remove_waf.sql', $discovered, true);
foreach ($discovered as $index => $name) {
    if (str_contains($name, 'waf')) {
        assert_true($index <= $removeIndex, 'WAF tables must be dropped after every migration that created them: ' . $name);
    }
}

// New migrations must not reuse a version prefix: two files with the same
// prefix make the execution order depend on the rest of the file name. The
// three legacy duplicates are kept because installed databases record them
// by full file name; renaming them would re-run non-idempotent SQL.
$legacyDuplicatePrefixes = ['2026_07_08_000002', '2026_07_08_000008', '2026_07_09_000009'];
$prefixCounts = [];
foreach ($discovered as $name) {
    if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $name, $prefixMatch)) {
        $prefixCounts[$prefixMatch[1]] = ($prefixCounts[$prefixMatch[1]] ?? 0) + 1;
    }
}
$duplicatePrefixes = array_keys(array_filter($prefixCounts, static fn (int $count): bool => $count > 1));
assert_same($legacyDuplicatePrefixes, array_values(array_intersect($duplicatePrefixes, $legacyDuplicatePrefixes)), 'Legacy duplicate prefixes are known.');
assert_same([], array_values(array_diff($duplicatePrefixes, $legacyDuplicatePrefixes)), 'New migrations must use a unique version prefix.');

// models.capability is widened by several migrations; the last one to run
// must list every capability the gateway routes.
$capabilityValues = ['text_generation', 'vision_understanding', 'video_understanding', 'audio_understanding', 'embedding', 'asr', 'tts', 'image_generation', 'video_generation', 'rerank', 'moderation', 'music_generation', 'realtime'];
$lastCapabilityEnum = '';
foreach ($discovered as $name) {
    $migrationSql = (string) file_get_contents(__DIR__ . '/../../database/migrations/' . $name);
    if (preg_match_all("/ALTER TABLE models MODIFY COLUMN capability ENUM\(([^)]*)\)/", $migrationSql, $enumMatches)) {
        $lastCapabilityEnum = (string) end($enumMatches[1]);
    }
}
foreach ($capabilityValues as $capability) {
    assert_true(str_contains($lastCapabilityEnum, "''" . $capability . "''"), 'The final models.capability ENUM should include ' . $capability . '.');
}
