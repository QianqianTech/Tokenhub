<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$service = file_get_contents(__DIR__ . '/../../app/Services/ExternalUsageService.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/UsageController.php') ?: '';
$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$docs = file_get_contents(__DIR__ . '/../../docs/external-api-sync.md') ?: '';

assert_true(str_contains($service, 'final class ExternalUsageService'), 'External usage service should exist.');
assert_true(str_contains($service, 'api_key_id = ?'), 'External usage service should scope queries to the current API key.');
assert_true(str_contains($service, 'usage_aggregates_day'), 'External usage summary should read aggregated usage.');
assert_true(str_contains($service, 'usage_aggregates_hour'), 'External usage trends should support hourly buckets.');
assert_true(str_contains($controller, 'final class UsageController'), 'Usage API controller should exist.');
assert_true(str_contains($controller, 'summary('), 'Usage API controller should expose summary.');
assert_true(str_contains($controller, 'trends('), 'Usage API controller should expose trends.');
assert_true(str_contains($kernel, "get('/v1/usage/summary'"), 'Kernel should register usage summary route.');
assert_true(str_contains($kernel, "get('/v1/usage/trends'"), 'Kernel should register usage trends route.');
assert_true(str_contains($docs, 'GET /v1/usage/summary'), 'External API docs should document usage summary.');
assert_true(str_contains($docs, 'GET /v1/usage/trends'), 'External API docs should document usage trends.');

