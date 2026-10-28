<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\DesensitizationService;
use TokenHub\Support\App;

$service = new DesensitizationService();

$replace = $service->applyPolicy([
    'body' => '{"token":"abc123","email":"leo@example.com","nested":{"phone":"13800138000"}}',
    'sql' => 'select * from users where password = "secret"',
    'env' => "DB_PASSWORD=secret\nSAFE=value",
], ['action' => 'replace', 'parsers' => ['json', 'sql', 'env']]);

assert_same('replace', $replace['action'], 'Replace policy should return replace action.');
assert_true($replace['match_count'] >= 4, 'Replace policy should count detected sensitive values.');
assert_true(str_contains(json_encode($replace['payload'], JSON_UNESCAPED_UNICODE), '****'), 'Replace policy should redact payload values.');
assert_true(!str_contains(json_encode($replace['payload'], JSON_UNESCAPED_UNICODE), 'abc123'), 'Replace policy should remove original token.');
assert_true(!str_contains(json_encode($replace['payload'], JSON_UNESCAPED_UNICODE), '13800138000'), 'Replace policy should remove original phone.');

$block = $service->applyPolicy(['prompt' => 'Bearer super-secret-token'], ['action' => 'block']);
assert_same('block', $block['action'], 'Block policy should return block action when sensitive content is found.');
assert_same(true, $block['blocked'], 'Block policy should mark result blocked.');
assert_same(null, $block['payload'], 'Block policy should not return original payload.');

$summary = $service->applyPolicy(['prompt' => 'api_key=secret-key-value'], ['action' => 'summary']);
assert_same('summary', $summary['action'], 'Summary policy should return summary action.');
assert_same(null, $summary['payload'], 'Summary policy should not return original payload.');
assert_true(isset($summary['summary']['sha256']), 'Summary policy should include a content digest.');

$formats = $service->applyPolicy([
    'yaml' => "password: yaml-secret\nsafe: ok",
    'html' => '<input name="token" value="html-secret">',
    'typescript' => 'const API_KEY = "ts-secret";',
    'broken_json' => '{"token": "fallback-secret"',
], ['action' => 'replace', 'parsers' => ['yaml', 'html', 'javascript', 'json']]);
$encodedFormats = json_encode($formats['payload'], JSON_UNESCAPED_UNICODE);

assert_true(!str_contains((string) $encodedFormats, 'yaml-secret'), 'YAML-style secret values should be redacted.');
assert_true(!str_contains((string) $encodedFormats, 'html-secret'), 'HTML token input values should be redacted.');
assert_true(!str_contains((string) $encodedFormats, 'ts-secret'), 'JavaScript/TypeScript key values should be redacted.');
assert_true(!str_contains((string) $encodedFormats, 'fallback-secret'), 'Malformed structured content should fall back to text redaction.');

$modelPayload = $service->applyPolicy([
    'model' => 'deepseek-v4-flash',
    'messages' => [['role' => 'user', 'content' => 'Reply OK']],
    'max_tokens' => 8,
    'usage' => [
        'prompt_tokens' => 6,
        'completion_tokens' => 8,
        'total_tokens' => 14,
    ],
    'api_key' => 'secret-key-value',
], ['action' => 'replace', 'parsers' => ['json']]);

assert_same(8, $modelPayload['payload']['max_tokens'] ?? null, 'Model protocol max_tokens should not be redacted.');
assert_same(6, $modelPayload['payload']['usage']['prompt_tokens'] ?? null, 'Model usage prompt_tokens should not be redacted.');
assert_same(8, $modelPayload['payload']['usage']['completion_tokens'] ?? null, 'Model usage completion_tokens should not be redacted.');
assert_same(14, $modelPayload['payload']['usage']['total_tokens'] ?? null, 'Model usage total_tokens should not be redacted.');
assert_same('****', $modelPayload['payload']['api_key'] ?? null, 'Actual API key field should still be redacted.');

$db = App::db();
$policyName = 'Desens Cache Policy ' . bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'department_id' => random_int(100000, 999999)];
$route = ['id' => random_int(100000, 999999), 'provider_id' => random_int(100000, 999999)];
$cleanup = static function () use ($db, $policyName): void {
    $db->execute('DELETE FROM desensitization_events WHERE policy_id IN (SELECT id FROM desensitization_policies WHERE name = ?)', [$policyName]);
    $db->execute('DELETE FROM desensitization_policies WHERE name = ?', [$policyName]);
};
$cleanup();
register_shutdown_function($cleanup);

$db->execute(
    'INSERT INTO desensitization_policies (name, scope_type, scope_id, parsers, action, status) VALUES (?, "api_key", ?, ?, "replace", "active")',
    [$policyName, $apiKey['id'], json_encode(['json'], JSON_UNESCAPED_SLASHES)]
);
App::redis()->delete($service->policyCacheKey($apiKey, $route));
$policies = $service->policiesFor($apiKey, $route);
assert_same($policyName, (string) ($policies[0]['name'] ?? ''), 'First desensitization policy lookup should read the active policy.');

$db->execute('UPDATE desensitization_policies SET status = "disabled" WHERE name = ?', [$policyName]);
$cachedPolicies = $service->policiesFor($apiKey, $route);
assert_same($policyName, (string) ($cachedPolicies[0]['name'] ?? ''), 'Warm desensitization policy lookup should use the short policy cache.');
