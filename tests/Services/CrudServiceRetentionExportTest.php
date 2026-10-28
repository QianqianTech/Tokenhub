<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\CrudService;

$service = new CrudService();
$source = file_get_contents(__DIR__ . '/../../app/Services/CrudService.php') ?: '';

assert_true($service->isReadOnlyTable('usage_contents'), 'usage_contents should be read-only through generic CRUD.');
assert_true($service->isReadOnlyTable('export_tasks'), 'export_tasks should be read-only through generic CRUD.');
assert_true($service->isReadOnlyTable('audit_logs'), 'audit_logs should be read-only through generic CRUD.');
assert_true($service->isReadOnlyTable('alert_events'), 'alert_events should be read-only through generic CRUD.');
assert_true($service->isReadOnlyTable('log_archive_files'), 'log_archive_files should be read-only through generic CRUD.');
assert_true(!$service->isReadOnlyTable('providers'), 'providers should remain writable through generic CRUD.');
assert_true(str_contains($source, 'requireSuperAdminForGlobalWrite'), 'CrudService should have a super-admin write gate for global resources.');
assert_true(str_contains($source, "'providers'"), 'Global write gate should cover providers.');
assert_true(str_contains($source, "'models'"), 'Global write gate should cover models.');
assert_true(str_contains($source, "'model_prices'"), 'Global write gate should cover model prices.');
assert_true(str_contains($source, "'system_settings'"), 'Global write gate should cover system settings.');
assert_true(!str_contains($source, 'waf_'), 'Generic CRUD should not expose removed WAF tables.');
assert_true(str_contains($source, "'desensitization_policies'"), 'Global write gate should cover desensitization policies.');
assert_true(str_contains($source, "'log_retention_policies'"), 'Global write gate should cover log retention policies.');
