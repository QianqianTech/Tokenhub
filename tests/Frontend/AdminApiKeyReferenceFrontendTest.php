<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$domSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/dom.js') ?: '';

$apiKeyResourceStart = strpos($resources, '"api-keys": {');
$apiKeyResourceEnd = strpos($resources, '"api-key-model-quotas": {', $apiKeyResourceStart);
assert_true($apiKeyResourceStart !== false && $apiKeyResourceEnd !== false, 'API key resource config should be inspectable.');
$apiKeyResource = substr($resources, $apiKeyResourceStart, $apiKeyResourceEnd - $apiKeyResourceStart);

assert_true(
    str_contains($apiKeyResource, 'reference: "departments"') &&
    str_contains($apiKeyResource, 'reference: "users"') &&
    str_contains($apiKeyResource, 'reference: "models"') &&
    str_contains($apiKeyResource, 'type: "multiselect"'),
    'API key form should use department, user, and model name selectors instead of raw ID or JSON inputs.'
);

assert_true(
    strpos($apiKeyResource, 'name: "department_id"') < strpos($apiKeyResource, 'name: "user_id"'),
    'API key creation should select the department before the employee.'
);

assert_true(
    str_contains($apiKeyResource, 'emptyOption: "请选择部门"'),
    'API key creation should require an explicit department choice before enabling employee selection.'
);

assert_true(
    str_contains($tableSource, 'resourceKey === "api-keys"') &&
    str_contains($tableSource, 'apiKeyReferenceContext') &&
    str_contains($tableSource, 'apiKeyUserLabel') &&
    str_contains($tableSource, 'apiKeyDepartmentLabel') &&
    str_contains($tableSource, 'apiKeyModelLabels'),
    'API key table and detail rendering should resolve user, department, and model names.'
);

assert_true(
    str_contains($tableSource, 'bindApiKeyDepartmentUserPicker') &&
    str_contains($tableSource, 'api-key-user-picker') &&
    str_contains($tableSource, 'select[name="department_id"]') &&
    str_contains($tableSource, 'select[name="user_id"]') &&
    str_contains($tableSource, 'userDepartmentIds') &&
    !str_contains($tableSource, 'api-key-user-search'),
    'API key create and edit forms should provide one searchable employee picker filtered by the selected department.'
);

assert_true(
    !str_contains($tableSource, 'ordinary user login') &&
    !str_contains($tableSource, 'user login flow'),
    'API key user search must not introduce an ordinary-user login flow.'
);

// The multi-select control is a checkbox list (see dom.js field()); readFields collects
// the checked boxes into an array instead of reading <select multiple>.selectedOptions.
assert_true(
    str_contains($domSource, 'config.type === "multiselect"') &&
    str_contains($domSource, 'input[type="checkbox"][name="${config.name}"]:checked') &&
    str_contains($domSource, '.map((input) => input.value)') &&
    str_contains($domSource, 'selected.map(Number)'),
    'Admin form reader should submit multi-select model choices as an array.'
);
