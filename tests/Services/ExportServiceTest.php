<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ExportService;

$service = new ExportService();

assert_true($service->exportTypeAllowed('usage'), 'Usage export should be allowed.');
assert_true($service->exportTypeAllowed('session_archive'), 'Session archive export should be allowed.');
assert_true($service->exportTypeAllowed('logs'), 'Log export should be allowed.');
assert_true(!$service->exportTypeAllowed('providers'), 'Provider export should not be allowed.');

$filters = $service->sanitizeFilters([
    'user_id' => '7',
    'department_id' => '3',
    'api_key_id' => '5',
    'model_id' => '11',
    'from' => '2026-07-01 00:00:00',
    'to' => '2026-07-08 23:59:59',
    'include_raw' => true,
    'Authorization' => 'Bearer secret',
    'Cookie' => 'session=secret',
]);

assert_same(7, $filters['user_id'], 'Export filters should normalize user_id.');
assert_same(3, $filters['department_id'], 'Export filters should normalize department_id.');
assert_same(5, $filters['api_key_id'], 'Export filters should normalize api_key_id.');
assert_same(11, $filters['model_id'], 'Export filters should normalize model_id.');
assert_true($filters['include_raw'], 'Export filters should keep include_raw as a boolean.');
assert_true(!array_key_exists('Authorization', $filters), 'Export filters should remove Authorization.');
assert_true(!array_key_exists('Cookie', $filters), 'Export filters should remove Cookie.');

$logFilters = $service->sanitizeFilters([
    'log_type' => 'provider',
    'request_id' => 'req_1',
    'trace_id' => 'trc_1',
    'provider_id' => '9',
    'model_id' => '10',
    'error_code' => 'PROVIDER_ERROR',
], 'logs');
assert_same('provider', $logFilters['log_type'], 'Log export filters should keep log_type.');
assert_same('req_1', $logFilters['request_id'], 'Log export filters should keep request_id.');
assert_same('trc_1', $logFilters['trace_id'], 'Log export filters should keep trace_id.');
assert_same(9, $logFilters['provider_id'], 'Log export filters should normalize provider_id.');
assert_same(10, $logFilters['model_id'], 'Log export filters should normalize model_id.');
assert_same('PROVIDER_ERROR', $logFilters['error_code'], 'Log export filters should keep error_code.');

$row = $service->sanitizeCsvRow([
    'request_id' => 'req_1',
    'Authorization' => 'Bearer secret',
    'key_hash' => 'secret',
    'key_prefix' => 'tkh_abc',
    'key_suffix' => 'xyz',
    'safe' => 'ok',
]);

assert_true(!array_key_exists('Authorization', $row), 'CSV rows should not expose Authorization.');
assert_true(!array_key_exists('key_hash', $row), 'CSV rows should not expose API key hashes.');
assert_same('ok', $row['safe'], 'CSV rows should keep safe values.');

assert_true($service->canDownloadTask(['role' => 'super_admin', 'id' => 1], ['requested_by' => 9]), 'Super admin should download any export task.');
assert_true($service->canDownloadTask(['role' => 'admin', 'id' => 7], ['requested_by' => 7]), 'Export creator should download own task.');
assert_true(!$service->canDownloadTask(['role' => 'admin', 'id' => 7], ['requested_by' => 8]), 'Admin should not download another user export task.');

$encrypted = $service->encryptCsvForStorage("a,b\n1,2\n");
assert_true($encrypted !== "a,b\n1,2\n", 'Stored export content should be encrypted at rest.');
assert_same("a,b\n1,2\n", $service->decryptCsvFromStorage($encrypted), 'Encrypted export content should decrypt to CSV.');
assert_true(str_ends_with($service->exportFilePath('exp_demo'), 'exp_demo.csv.enc'), 'Export files should use encrypted-at-rest extension.');

[$logWhere, $logBindings] = $service->logScopeSql(['role' => 'user', 'id' => 11]);
assert_true(str_contains($logWhere, 'actor_user_id = ?'), 'User log export should be scoped to actor_user_id.');
assert_same([11], $logBindings, 'User log export should bind own id.');
