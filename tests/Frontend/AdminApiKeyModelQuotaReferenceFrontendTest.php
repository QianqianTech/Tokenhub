<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';

assert_true(
    str_contains($resources, 'reference: "apiKeys"') && str_contains($resources, 'reference: "models"'),
    'Model quota form fields should use API key and model reference selectors instead of raw numeric ID inputs.'
);

assert_true(
    str_contains($tableSource, 'resourceKey === "api-key-model-quotas"') &&
    str_contains($tableSource, '/admin-api/api-keys') &&
    str_contains($tableSource, '/admin-api/models'),
    'Model quota rendering should load API key and model lookup data.'
);

assert_true(
    str_contains($tableSource, 'apiKeysById') &&
    str_contains($tableSource, 'modelQuotaApiKeyLabel') &&
    str_contains($tableSource, 'modelQuotaModelLabel'),
    'Model quota table cells should render readable API key/user and model labels.'
);
