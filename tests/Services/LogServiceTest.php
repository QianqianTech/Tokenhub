<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\LogService;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tokenhub-log-' . bin2hex(random_bytes(6));
$service = new LogService($root);

$service->write('gateway-access', 'info', 'gateway request', [
    'request_id' => 'req_log_1',
    'trace_id' => 'trc_log_1',
    'user_id' => 7,
    'department_id' => 3,
    'api_key_id' => 5,
    'provider_id' => 11,
    'model_id' => 13,
    'client_ip' => '10.0.0.9',
    'status' => 'success',
    'latency_ms' => 123,
    'error_code' => null,
    'Authorization' => 'Bearer provider-secret',
    'Cookie' => 'session=secret',
    'api_key' => 'tkh_plain_secret',
    'prompt' => 'full user prompt must not be logged',
    'response' => ['content' => 'full provider response must not be logged'],
]);

$path = $root . DIRECTORY_SEPARATOR . 'gateway-access.jsonl';
assert_true(is_file($path), 'LogService should write gateway-access into its independent JSONL file.');

$line = trim((string) file_get_contents($path));
$record = json_decode($line, true);
assert_true(is_array($record), 'Log line should be valid JSON.');
assert_same('info', $record['level'], 'Log level should be top-level.');
assert_same('req_log_1', $record['request_id'], 'request_id should be top-level.');
assert_same('trc_log_1', $record['trace_id'], 'trace_id should be top-level.');
assert_same(7, $record['user_id'], 'user_id should be top-level.');
assert_same(3, $record['department_id'], 'department_id should be top-level.');
assert_same(5, $record['api_key_id'], 'api_key_id should be top-level.');
assert_same(11, $record['provider_id'], 'provider_id should be top-level.');
assert_same(13, $record['model_id'], 'model_id should be top-level.');
assert_same('10.0.0.9', $record['client_ip'], 'client_ip should be top-level.');
assert_same('success', $record['status'], 'status should be top-level.');
assert_same(123, $record['latency_ms'], 'latency_ms should be top-level.');
assert_true(!str_contains($line, 'provider-secret'), 'Authorization secret should be redacted before log write.');
assert_true(!str_contains($line, 'session=secret'), 'Cookie should be redacted before log write.');
assert_true(!str_contains($line, 'tkh_plain_secret'), 'API key plaintext should be redacted before log write.');
assert_true(!str_contains($line, 'full user prompt'), 'Full prompt should not be logged.');
assert_true(!str_contains($line, 'full provider response'), 'Full response should not be logged.');

$service->write('provider', 'error', 'provider failed', [
    'request_id' => 'req_log_2',
    'provider_id' => 22,
    'model_id' => 33,
    'status' => 502,
    'error_code' => 'PROVIDER_ERROR',
]);

$rows = $service->query(['log_type' => 'provider', 'request_id' => 'req_log_2']);
assert_same(1, count($rows), 'Log query should filter by log_type and request_id.');
assert_same('PROVIDER_ERROR', $rows[0]['error_code'], 'Log query should return matching JSONL fields.');

file_put_contents($root . DIRECTORY_SEPARATOR . 'caddy-access.jsonl', json_encode([
    'ts' => 1783500000.25,
    'level' => 'info',
    'msg' => 'handled request',
    'request' => [
        'remote_ip' => '203.0.113.10',
        'method' => 'POST',
        'uri' => '/v1/chat/completions',
        'headers' => [
            'Authorization' => ['Bearer secret'],
            'Cookie' => ['sid=secret'],
        ],
    ],
    'status' => 200,
    'duration' => 0.245,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

$caddyRows = $service->query(['log_type' => 'caddy-access', 'client_ip' => '203.0.113.10']);
assert_same(1, count($caddyRows), 'Log query should normalize and filter Caddy access JSONL rows by client_ip.');
assert_same('caddy-access', $caddyRows[0]['log_type'], 'Caddy row should keep log_type.');
assert_same('203.0.113.10', $caddyRows[0]['client_ip'], 'Caddy remote_ip should map to client_ip.');
assert_same(200, $caddyRows[0]['status'], 'Caddy status should map to top-level status.');
assert_same(245, $caddyRows[0]['latency_ms'], 'Caddy duration seconds should map to latency_ms.');
assert_true(isset($caddyRows[0]['timestamp']), 'Caddy ts should map to timestamp.');
assert_true(!str_contains(json_encode($caddyRows[0], JSON_UNESCAPED_SLASHES), 'Bearer secret'), 'Caddy Authorization header should be removed from normalized query results.');

assert_true(!in_array('caddy-security', LogService::LOG_TYPES, true), 'The WAF caddy-security log type should be removed.');
