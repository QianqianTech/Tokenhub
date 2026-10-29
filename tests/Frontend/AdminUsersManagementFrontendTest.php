<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$table = admin_js_bundle() ?: '';
$app = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js') ?: '';
$index = file_get_contents(__DIR__ . '/../../public/admin/index.html') ?: '';
$auth = file_get_contents(__DIR__ . '/../../app/Services/AuthService.php') ?: '';

assert_true(str_contains($resources, '"admin-users"'), 'Super admin navigation should expose an administrator management page.');
assert_true(str_contains((string) file_get_contents(__DIR__ . '/../../public/admin/assets/js/domains.js'), '{ key: "system", label: "系统运维"'), 'System settings should be a left sidebar section.');
assert_true(str_contains((string) file_get_contents(__DIR__ . '/../../public/admin/assets/js/domains.js'), '"admin-users", "permission-config"'), 'Administrator management should remain available alongside the dedicated permission configuration page.');
assert_true(str_contains($resources, 'label: "管理员管理"'), 'Administrator management page should have a dedicated Chinese label.');
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
assert_true(str_contains($resources, 'resourcesForUser'), 'Admin resources should be filtered from effective capabilities.');
assert_true(str_contains($app, 'domainsForUser'), 'Admin navigation should consume effective capability-filtered domains.');
assert_true(str_contains($auth, "['permissions']"), 'Authenticated administrator data should include effective capabilities.');
