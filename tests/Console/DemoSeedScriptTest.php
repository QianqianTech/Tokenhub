<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$script = __DIR__ . '/../../scripts/seed-demo.php';

assert_true(is_file($script), 'Demo seed script should exist.');

$source = file_get_contents($script);
assert_true($source !== false, 'Demo seed script should be readable.');
assert_true(str_contains($source, "app/Bootstrap/container.php"), 'Demo seed script should use the app container.');
assert_true(!preg_match('/\b(TRUNCATE|DELETE\s+FROM)\b/i', $source), 'Demo seed script should not destructively clear tables.');

$requiredTables = [
    'departments',
    'users',
    'providers',
    'models',
    'model_prices',
    'api_keys',
    'api_key_model_permissions',
    'quota_policies',
    'quota_bindings',
    'usage_records',
    'usage_contents',
    'usage_aggregates_minute',
    'usage_aggregates_hour',
    'usage_aggregates_day',
    'multimodal_tasks',
    'media_assets',
    'export_tasks',
    'dashboard_share_links',
    'dashboard_share_access_logs',
    'waf_rules',
    'waf_rule_versions',
    'waf_events',
    'desensitization_policies',
    'desensitization_events',
    'log_archive_files',
    'alert_events',
    'audit_logs',
    'system_settings',
];

foreach ($requiredTables as $table) {
    assert_true(str_contains($source, $table), "Demo seed script should reference {$table}.");
}

$requiredIdentifiers = [
    'demo-eng-platform',
    'demo-provider-openai',
    'demo-key-platform',
    'demo-share-global',
    'demo-waf-sqli',
    'demo-content-retention',
];

foreach ($requiredIdentifiers as $identifier) {
    assert_true(str_contains($source, $identifier), "Demo seed script should include stable identifier {$identifier}.");
}
