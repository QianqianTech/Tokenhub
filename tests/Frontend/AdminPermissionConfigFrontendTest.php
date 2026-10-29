<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$table = admin_js_bundle() ?: '';
$domains = file_get_contents(__DIR__ . '/../../public/admin/assets/js/domains.js') ?: '';

assert_true(str_contains($resources, '"permission-config"'), 'Admin resources should expose a dedicated permission configuration page.');
assert_true(str_contains($resources, 'admins.manage'), 'Permission configuration navigation should require administrator management capability.');
assert_true(str_contains($domains, '{ key: "organization", label: "组织与权限", tabs: ["departments", "users", "admin-users", "permission-config"] }'), 'Permission configuration should be grouped under the organization and permissions domain.');
assert_true(!str_contains($domains, '.shift()') && !str_contains($domains, '.push('), 'Domain definitions should be declarative instead of mutated at module load.');
assert_true(str_contains($table, 'renderPermissionConfigPage'), 'Table renderer should render the dedicated permission configuration page.');
assert_true(str_contains($table, '/admin-api/admin-users'), 'Permission configuration should load administrator accounts from the dedicated API.');
assert_true(str_contains($table, 'adminPermissionOptions'), 'Permission configuration should reuse the canonical capability list.');
