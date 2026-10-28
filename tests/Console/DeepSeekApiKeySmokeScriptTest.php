<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$script = __DIR__ . '/../../scripts/deepseek-api-key-smoke.php';
$source = is_file($script) ? file_get_contents($script) : false;

assert_true(is_string($source), 'DeepSeek API key smoke script should exist.');
assert_true(str_contains($source, 'external-deepseek-smoke'), 'Smoke script should create a named external DeepSeek test key.');
assert_true(str_contains($source, 'deepseek'), 'Smoke script should locate DeepSeek models.');
assert_true(str_contains($source, 'flash'), 'Smoke script should verify the flash model authorization.');
assert_true(str_contains($source, 'pro'), 'Smoke script should verify the pro model authorization.');
assert_true(str_contains($source, '/v1/me'), 'Smoke script should verify the created key through /v1/me.');
assert_true(str_contains($source, '/v1/models'), 'Smoke script should verify the created key through /v1/models.');
assert_true(str_contains($source, 'ApiKeyService'), 'Smoke script should create the key through ApiKeyService.');
assert_true(str_contains($source, 'assert_http_response($chat, 200, "chat {$variant}")'), 'Smoke script should accept raw OpenAI-compatible model responses.');
