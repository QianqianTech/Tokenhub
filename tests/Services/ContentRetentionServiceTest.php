<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ContentRetentionService;

$service = new ContentRetentionService();

$policy = $service->effectivePolicy([
    'mode' => 'summary',
    'default_days' => 30,
    'max_days' => 90,
    'raw_allowed_roles' => ['super_admin'],
    'departments' => [
        '2' => ['mode' => 'redacted', 'days' => 60],
        '7' => ['mode' => 'raw', 'days' => 120],
    ],
    'users' => [
        '5' => ['mode' => 'none', 'days' => 10],
    ],
    'api_keys' => [
        '9' => ['mode' => 'raw', 'days' => 365],
    ],
], [
    'user_id' => 5,
    'department_path' => '/1/2/7/',
    'api_key_id' => 9,
]);

assert_same('raw', $policy['mode'], 'API key policy should override user and department policies.');
assert_same(90, $policy['days'], 'Retention days should be capped by max_days.');

$departmentPolicy = $service->effectivePolicy([
    'mode' => 'summary',
    'default_days' => 30,
    'max_days' => 90,
    'departments' => [
        '2' => ['mode' => 'redacted', 'days' => 60],
        '7' => ['mode' => 'raw', 'days' => 80],
    ],
], [
    'department_path' => '/1/2/7/',
]);

assert_same('raw', $departmentPolicy['mode'], 'Nearest department policy should win over ancestor policy.');
assert_same(80, $departmentPolicy['days'], 'Nearest department policy days should be used when within max_days.');

$summary = $service->prepareContent('summary', ['messages' => [['content' => str_repeat('a', 400)]]], ['text' => 'ok']);
assert_true($summary['summary'] !== '', 'Summary mode should keep a summary.');
assert_true($summary['encrypted_input'] === null && $summary['redacted_input'] === null, 'Summary mode should not keep body content.');

$redacted = $service->prepareContent('redacted', ['Authorization' => 'Bearer secret', 'safe' => 'ok'], ['email' => 'leo@example.com']);
assert_true(str_contains((string) $redacted['redacted_input'], '****'), 'Redacted mode should redact sensitive request content.');
assert_true(str_contains((string) $redacted['redacted_output'], '****'), 'Redacted mode should redact sensitive response content.');

$raw = $service->prepareContent('raw', ['input' => 'secret'], ['output' => 'answer']);
assert_true(is_string($raw['encrypted_input']) && $raw['encrypted_input'] !== '', 'Raw mode should encrypt input content.');
assert_true(is_string($raw['encrypted_output']) && $raw['encrypted_output'] !== '', 'Raw mode should encrypt output content.');

$none = $service->prepareContent('none', ['input' => 'secret'], ['output' => 'answer']);
assert_same(null, $none['summary'], 'None mode should not keep summary.');
assert_same(null, $none['encrypted_input'], 'None mode should not keep encrypted input.');
assert_same(null, $none['redacted_input'], 'None mode should not keep redacted input.');
