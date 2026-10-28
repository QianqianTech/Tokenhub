<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';

assert_true(
    str_contains($tableSource, 'function apiKeyActionIcon') &&
    str_contains($tableSource, 'const editButton = button("",') &&
    str_contains($tableSource, 'const deleteButton = button("",') &&
    str_contains($tableSource, 'editButton.append(apiKeyActionIcon') &&
    str_contains($tableSource, 'deleteButton.append(apiKeyActionIcon'),
    'API key row actions should use accessible SVG icon buttons instead of encoded text glyphs.'
);
