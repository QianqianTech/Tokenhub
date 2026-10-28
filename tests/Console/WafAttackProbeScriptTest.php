<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$script = __DIR__ . '/../../scripts/waf-attack-probe.py';
assert_true(is_file($script), 'WAF attack probe script should exist.');
$source = file_get_contents($script) ?: '';
assert_true(str_contains($source, '--mode'), 'WAF attack probe should support observe and block modes.');
assert_true(str_contains($source, 'WAF_BLOCKED'), 'WAF attack probe should validate the stable block error code.');
assert_true(str_contains($source, 'sqli'), 'WAF attack probe should include SQL injection payloads.');
assert_true(str_contains($source, 'path_traversal'), 'WAF attack probe should include path traversal payloads.');
assert_true(str_contains($source, 'json_body'), 'WAF attack probe should exercise JSON request bodies.');
assert_true(str_contains($source, 'request_path'), 'WAF attack probe should exercise request paths.');
assert_true(str_contains($source, 'union_select_encoded'), 'WAF attack probe should include encoded SQL injection variants.');
assert_true(str_contains($source, 'xss_event_handler'), 'WAF attack probe should include event-handler XSS variants.');
