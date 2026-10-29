<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableSource = admin_js_bundle() ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$redesignCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/redesign.css') ?: '';

assert_true(str_contains($tableSource, 'showDepartmentEdit'), 'Department tree rows should expose a dedicated edit action.');
assert_true(str_contains($tableSource, '{ name: "name",'), 'Department edit form should allow updating the department name.');
assert_true(str_contains($tableSource, 'api(`/admin-api/departments/${row.id}`'), 'Department edit should save through the department update endpoint.');
assert_true(str_contains($tableSource, 'department-layout'), 'Department management should use a tree + detail workspace layout.');
assert_true(str_contains($tableSource, 'department-summary-card'), 'Department page should summarise totals, active, disabled and depth.');
assert_true(str_contains($tableSource, 'role: "tree"') && str_contains($tableSource, 'role: "treeitem"') && str_contains($tableSource, 'role: "group"'), 'Department tree should use WAI-ARIA tree semantics.');
assert_true(str_contains($tableSource, 'handleTreeKeydown'), 'Department tree should support keyboard navigation.');
assert_true(str_contains($tableSource, 'department-children'), 'Department tree should render child departments inside a nested group.');
assert_true(str_contains($tableSource, 'department-child-count'), 'Department tree should show how many direct child departments a node has.');
assert_true(str_contains($tableSource, 'department-search'), 'Department tree should be searchable.');
assert_true(str_contains($tableSource, 'department-path'), 'Department detail should show the path from the top-level department.');
assert_true(str_contains($tableSource, 'department-facts'), 'Department detail should list parent, level, children and sort order.');
assert_true(str_contains($tableSource, 'setFilters?.({ dept: state.selectedId })'), 'The selected department should be kept in the URL.');
assert_true(str_contains($adminCss, '.modal-body .edit-form'), 'Modal edit forms should have scoped layout styles.');
assert_true(str_contains($adminCss, '.modal-body .edit-form > .field'), 'Modal edit form fields should keep compact vertical spacing.');
assert_true(str_contains($adminCss, '.modal-body .edit-form > button'), 'Modal edit form actions should keep normal button sizing.');
assert_true(str_contains($adminCss, '.modal-body .edit-form > pre:empty'), 'Modal edit forms should hide empty output panels.');
assert_true(str_contains($redesignCss, '.department-layout'), 'Department page should define a responsive two-column workspace.');
assert_true(str_contains($redesignCss, '.department-node-row'), 'Department tree rows should have dedicated styling.');
assert_true(str_contains($redesignCss, '.department-children'), 'Department tree should style nested child department groups.');
assert_true(str_contains($redesignCss, '.department-child-count'), 'Department tree should style direct child count labels.');
assert_true(str_contains($redesignCss, '.department-detail'), 'Department detail pane should have dedicated styling.');
