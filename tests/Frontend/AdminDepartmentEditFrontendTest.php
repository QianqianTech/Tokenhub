<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';

assert_true(str_contains($tableSource, 'showDepartmentEdit'), 'Department tree rows should expose a dedicated edit action.');
assert_true(str_contains($tableSource, '{ name: "name",'), 'Department edit form should allow updating the department name.');
assert_true(str_contains($tableSource, 'api(`/admin-api/departments/${row.id}`'), 'Department edit should save through the department update endpoint.');
assert_true(str_contains($tableSource, 'department-layout'), 'Department management should use a dedicated visual layout.');
assert_true(str_contains($tableSource, 'department-summary-card'), 'Department tree should expose visual summary cards.');
assert_true(str_contains($tableSource, 'department-node-card'), 'Department rows should render as visual tree cards.');
assert_true(str_contains($tableSource, 'department-node-connector'), 'Department rows should show hierarchy connectors.');
assert_true(str_contains($tableSource, 'renderDepartmentBranch'), 'Department tree should render recursively instead of flattening hierarchy into sibling rows.');
assert_true(str_contains($tableSource, 'department-children'), 'Department tree should render child departments inside a nested children container.');
assert_true(str_contains($tableSource, 'department-child-count'), 'Department tree should show how many direct child departments a node has.');
assert_true(str_contains($adminCss, '.modal-body .edit-form'), 'Modal edit forms should have scoped layout styles.');
assert_true(str_contains($adminCss, '.modal-body .edit-form > .field'), 'Modal edit form fields should keep compact vertical spacing.');
assert_true(str_contains($adminCss, '.modal-body .edit-form > button'), 'Modal edit form actions should keep normal button sizing.');
assert_true(str_contains($adminCss, '.modal-body .edit-form > pre:empty'), 'Modal edit forms should hide empty output panels.');
assert_true(str_contains($adminCss, '.department-layout'), 'Department page should define a responsive two-column layout.');
assert_true(str_contains($adminCss, '.department-node-card'), 'Department tree cards should have dedicated visual styling.');
assert_true(str_contains($adminCss, '.department-node-connector'), 'Department tree should style hierarchy connectors.');
assert_true(str_contains($adminCss, '.department-children'), 'Department tree should style nested child department groups.');
assert_true(str_contains($adminCss, '.department-child-count'), 'Department tree should style direct child count labels.');
