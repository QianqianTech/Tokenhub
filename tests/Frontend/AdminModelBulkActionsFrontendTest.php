<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$tableSource = admin_js_bundle() ?: '';

assert_true(str_contains($tableSource, 'model-bulk-actions'), 'Model management should render a bulk action toolbar.');
assert_true(str_contains($tableSource, 'className: "row-select"'), 'Model rows should expose selection checkboxes.');
assert_true(str_contains($tableSource, 'className: "row-select-all"'), 'Model table should expose a select-all checkbox.');
assert_true(str_contains($tableSource, 'const BULK_ACTIONS = {') && str_contains($tableSource, 'endpoint: "/admin-api/models/batch"'), 'Bulk selection should be a reusable table capability configured per resource.');
assert_true(str_contains($tableSource, '批量启用'), 'Model management should expose a bulk enable action.');
assert_true(str_contains($tableSource, '批量暂停'), 'Model management should expose a bulk pause action.');
assert_true(str_contains($tableSource, '批量删除'), 'Model management should expose a bulk delete action.');
assert_true(str_contains($tableSource, '/admin-api/models/batch'), 'Bulk model actions should call the dedicated batch endpoint.');

