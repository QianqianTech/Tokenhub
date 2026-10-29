<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableJs = admin_js_bundle() ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';

assert_true(
    str_contains($tableJs, 'showProviderModelImport'),
    'Provider rows should expose a model import modal.'
);

assert_true(
    str_contains($tableJs, 'showModelProviderImport'),
    'Model management should expose a provider picker for importing models.'
);

assert_same(
    1,
    preg_match_all('/function\s+showProviderModelImport\s*\(/', $tableJs),
    'Provider model import helper should be declared once so the admin module can load.'
);

assert_true(
    str_contains($tableJs, '拉取模型'),
    'Provider rows should show a fetch models action.'
);

assert_true(
    str_contains($tableJs, '/admin-api/providers/${row.id}/models'),
    'Provider model import should call the provider model sync endpoint.'
);

assert_true(
    str_contains($tableJs, 'resourceKey === "models"'),
    'Model management should show a fetch models action in its toolbar.'
);

assert_true(
    str_contains($tableJs, '/admin-api/providers'),
    'Model management import should load providers before fetching provider models.'
);

assert_true(
    str_contains($tableJs, 'provider-model-import-provider'),
    'Model management import should render a provider selector before fetching models.'
);

assert_true(
    str_contains($tableJs, 'provider-model-import-list'),
    'Provider model import modal should render a selectable model list.'
);

assert_true(
    str_contains($adminCss, '.provider-model-import-list'),
    'Provider model import list should have visible layout styling.'
);
