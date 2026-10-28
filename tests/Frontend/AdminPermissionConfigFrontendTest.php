<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$table = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$domains = file_get_contents(__DIR__ . '/../../public/admin/assets/js/domains.js') ?: '';

assert_true(str_contains($resources, '"permission-config"'), 'Admin resources should expose a dedicated permission configuration page.');
assert_true(str_contains($resources, 'admins.manage'), 'Permission configuration navigation should require administrator management capability.');
assert_true(str_contains($domains, 'organizationDomain.tabs.push(...permissionDomain.tabs)'), 'Permission configuration should be grouped under the organization and permissions domain.');
assert_true(str_contains($table, 'renderPermissionConfigPage'), 'Table renderer should render the dedicated permission configuration page.');
assert_true(str_contains($table, '/admin-api/admin-users'), 'Permission configuration should load administrator accounts from the dedicated API.');
assert_true(str_contains($table, 'adminPermissionOptions'), 'Permission configuration should reuse the canonical capability list.');
