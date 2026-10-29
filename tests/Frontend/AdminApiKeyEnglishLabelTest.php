<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js');
$tableSource = admin_js_bundle();

assert_true(is_string($resources), 'Admin resources source should be readable.');
assert_true(is_string($tableSource), 'Admin table source should be readable.');

assert_true(str_contains($resources, '"api_key": "API 密钥"'), 'api_key enum labels should use Chinese API key wording.');
assert_true(str_contains($resources, '"api_key_id": "API 密钥 ID"'), 'api_key_id column label should use Chinese API key wording.');
assert_true(str_contains($resources, '"key_prefix": "密钥前缀"'), 'API key prefix column should use Chinese labels.');
assert_true(str_contains($resources, '"key_suffix": "密钥后缀"'), 'API key suffix column should use Chinese labels.');
assert_true(str_contains($resources, '"api-keys": { label: "API 密钥"'), 'API key resource should use a Chinese label.');
assert_true(str_contains($resources, '{ name: "name", label: "名称" }'), 'API key create form should use a Chinese name label.');
assert_true(str_contains($resources, '{ name: "model_ids", label: "允许模型"'), 'API key create form should use Chinese allowed models label.');
assert_true(str_contains($resources, '{ name: "allowed_ip_cidrs", label: "允许 IP 网段'), 'API key create form should use Chinese IP range label.');
assert_true(str_contains($tableSource, '{ lookup: "api-keys", prefix: "api_key", label: "API 密钥" }'), 'Quota target labels should display API key in Chinese.');

assert_true(!str_contains($resources, '"api_key": "API Key"'), 'api_key enum labels should not use English API Key wording.');
assert_true(!str_contains($resources, '"api_key_id": "API Key ID"'), 'api_key_id column label should not use English API Key ID wording.');
assert_true(!str_contains($resources, '"api-keys": { label: "API Keys"'), 'API key resource labels should not use English wording.');
assert_true(!str_contains($resources, 'label: "Key name"'), 'API key form labels should not use English key-name wording.');
assert_true(!str_contains($tableSource, '`API Key / ${modelQuotaApiKeyLabel'), 'Quota target labels should not display English API Key wording.');

assert_true(!str_contains($resources, '"api_key": "接口密钥"'), 'api_key enum labels should not use Chinese interface-key wording.');
assert_true(!str_contains($resources, '"api_key_id": "接口密钥 ID"'), 'api_key_id column label should not use Chinese interface-key wording.');
assert_true(!str_contains($resources, 'label: "接口密钥"'), 'API key resource labels should not use Chinese interface-key wording.');
assert_true(!str_contains($resources, 'label: "密钥名称"'), 'API key form labels should not use Chinese key-name wording.');
assert_true(!str_contains($tableSource, '`接口密钥 / ${modelQuotaApiKeyLabel'), 'Quota target labels should not display Chinese interface-key wording.');
