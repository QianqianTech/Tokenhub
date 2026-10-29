<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$kernelSource = file_get_contents($root . '/app/Bootstrap/Kernel.php') ?: '';
$controllerSource = file_get_contents($root . '/app/Http/Controllers/AdminController.php') ?: '';
$crudSource = file_get_contents($root . '/app/Services/CrudService.php') ?: '';
$tableSource = admin_js_bundle() ?: '';

assert_true(str_contains($kernelSource, "delete('/admin-api/providers/{id}'"), 'Kernel should route provider DELETE to a dedicated delete handler.');
assert_true(str_contains($controllerSource, 'deleteProvider'), 'Admin controller should expose a provider delete action.');
assert_true(str_contains($crudSource, 'deleteProvider('), 'CrudService should implement a provider delete operation.');
assert_true(str_contains($crudSource, "'providers'"), 'Provider delete should audit the providers resource.');
assert_true(str_contains($tableSource, 'resourceKey === "providers"'), 'Admin table should render provider-specific row actions.');
assert_true(str_contains($tableSource, 'showOperationResult(`删除${noun}`'), 'Provider management should show provider delete feedback.');
