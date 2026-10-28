<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$crud = file_get_contents(__DIR__ . '/../../app/Services/CrudService.php') ?: '';
$settings = file_get_contents(__DIR__ . '/../../app/Services/SystemSettingsService.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/AdminController.php') ?: '';

assert_true(str_contains($crud, 'usesGlobalScope'), 'CRUD mutations should honor the configured global administrator scope, not only the legacy super_admin role.');
assert_true(str_contains($crud, 'api_catalog.manage'), 'Global API catalog writes should use the API catalog capability instead of a hard-coded super-admin role check.');
assert_true(str_contains($settings, "requireCapability(\$actor, 'system.manage')"), 'System settings writes should use system management capability.');
assert_true(!str_contains($controller, "(\$actor['role'] ?? '') !== 'super_admin'"), 'Controller-only business actions should defer to route capability middleware rather than reintroducing a super-admin-only gate.');
