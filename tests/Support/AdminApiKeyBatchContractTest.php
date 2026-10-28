<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$kernelSource = file_get_contents($root . '/app/Bootstrap/Kernel.php') ?: '';
$controllerSource = file_get_contents($root . '/app/Http/Controllers/AdminController.php') ?: '';
$apiKeyServiceSource = file_get_contents($root . '/app/Services/ApiKeyService.php') ?: '';
$tableSource = file_get_contents($root . '/public/admin/assets/js/table.js') ?: '';

assert_true(str_contains($kernelSource, "post('/admin-api/api-keys/batch'"), 'Kernel should route API key batch operations through a dedicated endpoint.');
assert_true(str_contains($controllerSource, 'batchApiKeys'), 'Admin controller should expose an API key batch action.');
assert_true(str_contains($apiKeyServiceSource, 'batchStatus'), 'ApiKeyService should implement API key batch status operations.');
assert_true(str_contains($apiKeyServiceSource, "'enable'"), 'API key batch operations should support enable.');
assert_true(str_contains($apiKeyServiceSource, "'disable'"), 'API key batch operations should support pause/disable.');
assert_true(str_contains($apiKeyServiceSource, "'delete'"), 'API key batch operations should support delete.');
assert_true(str_contains($apiKeyServiceSource, 'api_key.batch'), 'API key batch operations should be audited.');
assert_true(str_contains($tableSource, 'api-key-bulk-actions'), 'API key page should render batch controls.');
assert_true(str_contains($tableSource, 'runApiKeyBatchAction'), 'API key page should call the batch API from the UI.');
