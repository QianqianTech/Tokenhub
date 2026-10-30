<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableSource = admin_js_bundle() ?: '';

assert_true(
    str_contains($tableSource, 'function apiKeyActionIcon') &&
    str_contains($tableSource, 'export function editButton(name, onClick)') &&
    str_contains($tableSource, 'node.append(svgIcon(PENCIL_ICON))') &&
    str_contains($tableSource, 'ops.push(editButton(row.name') &&
    str_contains($tableSource, 'arrangeRowActions(ops)'),
    'API key row actions should use an accessible SVG edit icon and keep the rest in the “更多” menu.'
);
