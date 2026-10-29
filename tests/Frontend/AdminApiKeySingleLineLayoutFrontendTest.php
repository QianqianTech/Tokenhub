<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$styles = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$tableSource = admin_js_bundle() ?: '';

foreach (['name', 'user_name', 'key'] as $column) {
    preg_match(
        '/\\.resource-table--api-keys \\.table-cell--' . preg_quote($column, '/') . '\\s*\\{(?<rules>[^}]+)\\}/',
        $styles,
        $matches
    );
    $rules = $matches['rules'] ?? '';
    assert_true(
        str_contains($rules, 'white-space: nowrap;') &&
        str_contains($rules, 'overflow: hidden;') &&
        str_contains($rules, 'text-overflow: ellipsis;'),
        "API key {$column} cells should stay on one line and truncate overflowing text."
    );
}

assert_true(
    str_contains($tableSource, 'title: cellText') &&
    str_contains($tableSource, 'const cellText = apiKeyCellText(column, row, context);'),
    'API key cells should expose truncated content through a native hover title.'
);
