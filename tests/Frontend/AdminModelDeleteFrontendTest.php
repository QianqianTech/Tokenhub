<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$kernelSource = file_get_contents($root . '/app/Bootstrap/Kernel.php') ?: '';
$controllerSource = file_get_contents($root . '/app/Http/Controllers/AdminController.php') ?: '';
$crudSource = file_get_contents($root . '/app/Services/CrudService.php') ?: '';
$tableSource = file_get_contents($root . '/public/admin/assets/js/table.js') ?: '';

assert_true(str_contains($kernelSource, "delete('/admin-api/models/{id}'"), 'Kernel should route model DELETE to a dedicated delete handler.');
assert_true(str_contains($controllerSource, 'deleteModel'), 'Admin controller should expose a model delete action.');
assert_true(str_contains($crudSource, 'deleteModel('), 'CrudService should implement a model delete operation.');
assert_true(str_contains($crudSource, 'admin.delete'), 'Model delete should be audited as a delete, not a disable.');
assert_true(str_contains($tableSource, 'resourceKey === "models"'), 'Admin table should render model-specific row actions.');
assert_true(str_contains($tableSource, 'button("删除"'), 'Model management should show a delete button.');
