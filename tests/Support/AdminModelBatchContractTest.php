<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$kernelSource = file_get_contents($root . '/app/Bootstrap/Kernel.php') ?: '';
$controllerSource = file_get_contents($root . '/app/Http/Controllers/AdminController.php') ?: '';
$crudSource = file_get_contents($root . '/app/Services/CrudService.php') ?: '';

assert_true(str_contains($kernelSource, "post('/admin-api/models/batch'"), 'Kernel should route model batch operations through a dedicated endpoint.');
assert_true(str_contains($controllerSource, 'batchModels'), 'Admin controller should expose a model batch action.');
assert_true(str_contains($crudSource, 'batchModels('), 'CrudService should implement model batch operations.');
assert_true(str_contains($crudSource, "'enable'"), 'Model batch operations should support enable.');
assert_true(str_contains($crudSource, "'disable'"), 'Model batch operations should support pause/disable.');
assert_true(str_contains($crudSource, "'delete'"), 'Model batch operations should support delete.');
assert_true(str_contains($crudSource, 'admin.models.batch'), 'Model batch operations should be audited.');

