<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$source = file_get_contents(__DIR__ . '/../../public/admin/assets/js/dashboard.js') ?: '';

assert_true(str_contains($source, 'select("department_id"'), 'Smart analysis should expose a department filter.');
assert_true(str_contains($source, 'name: "employee_query"'), 'Smart analysis should expose an employee keyword input.');
assert_true(str_contains($source, 'placeholder: "搜索员工账号"'), 'Employee keyword input should explain the supported search field.');
assert_true(str_contains($source, 'departmentSelectOptions()'), 'Smart analysis should load permitted department options.');
assert_true(str_contains($source, 'department_id: department.value'), 'Dashboard requests should include the selected department.');
assert_true(str_contains($source, 'employee_query: employeeQuery.value.trim()'), 'Dashboard requests should include the employee keyword.');
assert_true(str_contains($source, 'dimension: "department"'), 'Smart analysis should request department usage rankings.');
assert_true(str_contains($source, 'dimension: "user"'), 'Smart analysis should request employee usage rankings.');
assert_true(str_contains($source, '"部门用量排行"'), 'Smart analysis should render a department usage ranking panel.');
assert_true(str_contains($source, '"员工用量排行"'), 'Smart analysis should render an employee usage ranking panel.');
assert_true(str_contains($source, 'button("详情"'), 'Ranking action buttons should use the detail label.');
assert_true(!str_contains($source, 'button("下钻"'), 'Ranking action buttons should not use the drill-down label.');
assert_true(str_contains($source, 'api("/admin-api/dashboard/security", { query: params })'), 'Security analysis should keep the shared filter scope.');
assert_true(str_contains($source, 'api("/admin-api/dashboard/performance", { query: params })'), 'Performance analysis should keep the shared filter scope.');
assert_true(str_contains($source, 'api("/admin-api/dashboard/multimodal", { query: params })'), 'Multimodal analysis should keep the shared filter scope.');
