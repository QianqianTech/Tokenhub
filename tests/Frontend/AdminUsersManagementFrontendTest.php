<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$table = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$app = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js') ?: '';
$index = file_get_contents(__DIR__ . '/../../public/admin/index.html') ?: '';
$auth = file_get_contents(__DIR__ . '/../../app/Services/AuthService.php') ?: '';

assert_true(str_contains($resources, '"admin-users"'), 'Super admin navigation should expose an administrator management page.');
assert_true(str_contains($resources, '{ key: "system", label: "\u7cfb\u7edf\u8bbe\u7f6e"'), 'System settings should be a left sidebar section.');
assert_true(str_contains($resources, 'items: ["content-retention-policy", "settings-center", "admin-users", "permission-config", "system-settings"]'), 'Administrator management should remain available alongside the dedicated permission configuration page.');
assert_true(!str_contains($resources, 'items: ["departments", "users", "admin-users"]'), 'Administrator management should not be grouped under ordinary organization management.');
assert_true(str_contains($resources, 'label: "\u7ba1\u7406\u5458\u7ba1\u7406"'), 'Administrator management page should have a dedicated Chinese label.');
assert_true(str_contains($resources, 'reference: "departments"'), 'Administrator management form should use department references instead of raw department JSON only.');
assert_true(str_contains($table, '/admin-api/admin-users'), 'Administrator management actions should call the dedicated admin-users API.');
assert_true(str_contains($table, 'showAdminUserDepartmentGrant'), 'Administrator management should provide an explicit department grant action.');
assert_true(str_contains($table, 'revoke-sessions'), 'Administrator management should expose session revocation.');
assert_true(str_contains($resources, 'admin_type'), 'Administrator form should expose preset administrator type selection.');
assert_true(str_contains($resources, 'admin_scope'), 'Administrator form should expose global or department data scope selection.');
assert_true(str_contains($resources, 'permissions'), 'Administrator form should expose individual capability selection.');
assert_true(str_contains($resources, '"admin_type": "管理员类型"'), 'Administrator type column should use a Chinese header.');
assert_true(str_contains($resources, '"admin_scope": "数据范围"'), 'Administrator scope column should use a Chinese header.');
assert_true(str_contains($resources, '"permissions": "权限"'), 'Administrator permission column should use a Chinese header.');
assert_true(str_contains($resources, '"managed_department_ids": "授权管理部门"'), 'Administrator managed department column should use a Chinese header.');
assert_true(str_contains($resources, 'full_admin: "全功能管理员"'), 'Administrator type values should use Chinese labels.');
assert_true(str_contains($table, 'adminUserPermissionLabels'), 'Administrator permissions should render through a readable-label helper.');
assert_true(str_contains($table, 'adminUserDepartmentLabels'), 'Administrator managed departments should render through a readable-label helper.');
preg_match('/app\.js\?v=(\d{8}-[A-Za-z0-9._-]+)/', $index, $entryVersionMatch);
$adminAssetVersion = $entryVersionMatch[1] ?? '';
assert_true($adminAssetVersion !== '', 'Administrator localization should refresh the admin application module through a cache-busted entrypoint.');
assert_true(
    str_contains($app, './resources.js?v=' . $adminAssetVersion . '"') && str_contains($app, './table.js?v=' . $adminAssetVersion . '"'),
    'Administrator localization modules should be imported with the same cache version as the entrypoint.'
);
assert_true(str_contains($resources, 'resourcesForUser'), 'Admin resources should be filtered from effective capabilities.');
assert_true(str_contains($app, 'resourcesForUser'), 'Admin navigation should consume effective capability-filtered resources.');
assert_true(str_contains($auth, "['permissions']"), 'Authenticated administrator data should include effective capabilities.');
