<?php

declare(strict_types=1);

use TokenHub\Services\ApiKeyService;
use TokenHub\Support\App;

require __DIR__ . '/../vendor/autoload.php';

App::bootstrap(dirname(__DIR__));

$baseUrl = rtrim((string) ($_SERVER['BASE_URL'] ?? getenv('BASE_URL') ?: 'http://caddy'), '/');
$db = App::db();
$username = 'sync_api_smoke_' . bin2hex(random_bytes(4));
$db->execute(
    'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (1, ?, ?, "user", "active")',
    [$username, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]
);
$userId = (int) $db->pdo()->lastInsertId();
$key = (new ApiKeyService())->create(['user_id' => $userId, 'department_id' => 1, 'name' => 'sync api smoke'], $userId);
$token = $key['key'];
$deviceId = 'dev_api_smoke_' . bin2hex(random_bytes(4));
$providerName = 'sync_api_provider_' . bin2hex(random_bytes(4));
$modelName = 'sync-api-model-' . bin2hex(random_bytes(4));
$db->execute(
    'INSERT INTO providers (name, protocol, base_url, status) VALUES (?, "openai", "https://example.invalid/v1", "active")',
    [$providerName]
);
$providerId = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO models (provider_id, name, external_name, capability, context_length, status) VALUES (?, ?, ?, "text_generation", 8192, "active")',
    [$providerId, $modelName, $modelName]
);
$modelId = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO api_key_model_permissions (api_key_id, model_id) VALUES (?, ?)', [$key['id'], $modelId]);
$db->execute('INSERT INTO api_key_model_quotas (api_key_id, model_id, period, request_limit, total_token_limit, status) VALUES (?, ?, "day", 1000, 100000, "active")', [$key['id'], $modelId]);
$db->execute('INSERT INTO quota_policies (name, period, request_limit, total_token_limit, status) VALUES (?, "day", 2000, 200000, "active")', ['sync_api_policy_' . bin2hex(random_bytes(4))]);
$quotaPolicyId = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES (?, "api_key", ?)', [$quotaPolicyId, $key['id']]);
$requestId = 'req_smoke_' . bin2hex(random_bytes(4));
$traceId = 'trace_smoke_' . bin2hex(random_bytes(4));
$db->execute(
    'INSERT INTO usage_records (request_id, trace_id, user_id, department_id, api_key_id, provider_id, model_id, model_name, protocol, endpoint, capability, status, http_status, input_tokens, output_tokens, total_tokens, latency_ms, client_ip)
     VALUES (?, ?, ?, 1, ?, ?, ?, ?, "openai", "/v1/chat/completions", "text_generation", "success", 200, 11, 7, 18, 123, "127.0.0.1")',
    [$requestId, $traceId, $userId, $key['id'], $providerId, $modelId, $modelName]
);
$bucketDay = gmdate('Y-m-d 00:00:00');
$bucketHour = gmdate('Y-m-d H:00:00');
$db->execute(
    'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, total_cost, latency_ms_sum)
     VALUES (?, 1, ?, ?, NULL, NULL, "text_generation", 3, 2, 1, 100, 40, 5, 7, 0.012, 900)',
    [$bucketDay, $userId, $key['id']]
);
$db->execute(
    'INSERT INTO usage_aggregates_hour (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, total_cost, latency_ms_sum)
     VALUES (?, 1, ?, ?, NULL, NULL, "text_generation", 3, 2, 1, 100, 40, 5, 7, 0.012, 900)',
    [$bucketHour, $userId, $key['id']]
);

$me = request_json($baseUrl, 'GET', '/v1/me', $token);
assert_response($me, 200, 'me');
if (($me['json']['data']['api_key']['id'] ?? null) !== (int) $key['id'] || isset($me['json']['data']['api_key']['key_hash'])) {
    throw new RuntimeException('HTTP smoke me did not return scoped safe identity.');
}

$models = request_json($baseUrl, 'GET', '/v1/models', $token);
assert_response($models, 200, 'models');
if (($models['json']['data']['models'][0]['name'] ?? null) !== $modelName) {
    throw new RuntimeException('HTTP smoke models did not return authorized model.');
}

$quota = request_json($baseUrl, 'GET', '/v1/quota', $token);
assert_response($quota, 200, 'quota');
if (($quota['json']['data']['per_model'][0]['model_name'] ?? null) !== $modelName) {
    throw new RuntimeException('HTTP smoke quota did not return per-model quota.');
}

$device = request_json($baseUrl, 'POST', '/v1/sync/devices', $token, [
    'device_id' => $deviceId,
    'device_name' => 'API Smoke',
    'platform' => 'test',
]);
assert_response($device, 201, 'device registration');

$devices = request_json($baseUrl, 'GET', '/v1/sync/devices', $token);
assert_response($devices, 200, 'device list');
if (($devices['json']['data']['devices'][0]['device_id'] ?? null) !== $deviceId) {
    throw new RuntimeException('HTTP smoke device list did not return registered device.');
}

$upsert = request_json($baseUrl, 'PUT', '/v1/sync/records/settings/default', $token, [
    'device_id' => $deviceId,
    'payload' => ['theme' => 'dark'],
    'client_updated_at' => gmdate('c'),
]);
assert_response($upsert, 200, 'record upsert');

$records = request_json($baseUrl, 'GET', '/v1/sync/records?cursor=ver_0&limit=10', $token);
assert_response($records, 200, 'record list');
if (($records['json']['data']['records'][0]['payload']['theme'] ?? null) !== 'dark') {
    throw new RuntimeException('HTTP smoke record list did not return uploaded payload.');
}

$namespaces = request_json($baseUrl, 'GET', '/v1/sync/namespaces', $token);
assert_response($namespaces, 200, 'sync namespaces');
if (($namespaces['json']['data']['namespaces'][0]['namespace'] ?? null) !== 'settings') {
    throw new RuntimeException('HTTP smoke namespaces did not include settings.');
}

$single = request_json($baseUrl, 'GET', '/v1/sync/records/settings/default', $token);
assert_response($single, 200, 'single record');
if (($single['json']['data']['payload']['theme'] ?? null) !== 'dark') {
    throw new RuntimeException('HTTP smoke single record did not return payload.');
}

$batch = request_json($baseUrl, 'POST', '/v1/sync/records:batch', $token, [
    'device_id' => $deviceId,
    'records' => [[
        'namespace' => 'settings',
        'client_record_id' => 'default',
        'payload' => ['theme' => 'light'],
        'base_sync_version' => 0,
    ]],
]);
assert_response($batch, 200, 'record batch conflict');
if (($batch['json']['data']['conflicted'] ?? 0) !== 1 || (($batch['json']['data']['records'][0]['status'] ?? null) !== 'conflict')) {
    throw new RuntimeException('HTTP smoke batch conflict was not reported.');
}

$resolved = request_json($baseUrl, 'POST', '/v1/sync/records/settings/default:resolve-conflict', $token, [
    'strategy' => 'client_wins',
    'device_id' => $deviceId,
    'payload' => ['theme' => 'light'],
]);
assert_response($resolved, 200, 'resolve conflict');
if (($resolved['json']['data']['strategy'] ?? null) !== 'client_wins') {
    throw new RuntimeException('HTTP smoke conflict resolve did not accept client_wins.');
}

$usageSummary = request_json($baseUrl, 'GET', '/v1/usage/summary?from=' . rawurlencode(gmdate('Y-m-d 00:00:00', time() - 3600)), $token);
assert_response($usageSummary, 200, 'usage summary');
if (($usageSummary['json']['data']['totals']['request_count'] ?? 0) < 3 || (($usageSummary['json']['data']['totals']['total_tokens'] ?? 0) < 140)) {
    throw new RuntimeException('HTTP smoke usage summary did not return seeded usage.');
}

$usageTrends = request_json($baseUrl, 'GET', '/v1/usage/trends?bucket=hour&from=' . rawurlencode(gmdate('Y-m-d H:00:00', time() - 3600)), $token);
assert_response($usageTrends, 200, 'usage trends');
if (($usageTrends['json']['data']['buckets'][0]['request_count'] ?? 0) < 3) {
    throw new RuntimeException('HTTP smoke usage trends did not return seeded usage.');
}

$usageRecords = request_json($baseUrl, 'GET', '/v1/usage/records?limit=10', $token);
assert_response($usageRecords, 200, 'usage records');
if (($usageRecords['json']['data']['records'][0]['request_id'] ?? null) !== $requestId) {
    throw new RuntimeException('HTTP smoke usage records did not return seeded request.');
}

$requestDetail = request_json($baseUrl, 'GET', '/v1/requests/' . rawurlencode($requestId), $token);
assert_response($requestDetail, 200, 'request detail');
if (($requestDetail['json']['data']['request_id'] ?? null) !== $requestId) {
    throw new RuntimeException('HTTP smoke request detail did not return seeded request.');
}

$file = request_json($baseUrl, 'POST', '/v1/files', $token, [
    'file_name' => 'settings.json',
    'mime_type' => 'application/json',
    'b64_data' => base64_encode('{"ok":true}'),
]);
assert_response($file, 201, 'file upload');
$fileId = (string) ($file['json']['data']['file_id'] ?? '');
if ($fileId === '') {
    throw new RuntimeException('HTTP smoke file upload did not return file_id.');
}

$download = request_json($baseUrl, 'GET', '/v1/files/' . rawurlencode($fileId) . '/download-url', $token);
assert_response($download, 200, 'file download url');
if (empty($download['json']['data']['download_url'])) {
    throw new RuntimeException('HTTP smoke file download-url was empty.');
}

$capabilities = request_json($baseUrl, 'GET', '/v1/capabilities', $token);
assert_response($capabilities, 200, 'capabilities');
if (($capabilities['json']['data']['auth']['user_password_login'] ?? true) !== false) {
    throw new RuntimeException('HTTP smoke capabilities should report no user password login.');
}

$openapi = request_json($baseUrl, 'GET', '/v1/openapi.json', $token);
if ($openapi['status'] !== 200 || !isset($openapi['json']['paths']['/v1/me'])) {
    throw new RuntimeException('HTTP smoke openapi.json failed: HTTP ' . $openapi['status'] . ' ' . $openapi['body']);
}

$events = request_json($baseUrl, 'GET', '/v1/events?limit=20', $token);
assert_response($events, 200, 'events');
if (($events['json']['data']['events'] ?? []) === []) {
    throw new RuntimeException('HTTP smoke events did not return any scoped events.');
}

$feedback = request_json($baseUrl, 'POST', '/v1/feedback', $token, [
    'request_id' => $requestId,
    'rating' => 5,
    'tags' => ['smoke'],
    'comment' => 'ok',
]);
assert_response($feedback, 201, 'feedback');

$deletedFile = request_json($baseUrl, 'DELETE', '/v1/files/' . rawurlencode($fileId), $token);
assert_response($deletedFile, 200, 'file delete');
if (($deletedFile['json']['data']['deleted'] ?? false) !== true) {
    throw new RuntimeException('HTTP smoke file delete did not return deleted=true.');
}

$deleted = request_json($baseUrl, 'DELETE', '/v1/sync/records/settings/default', $token, [
    'device_id' => $deviceId,
]);
assert_response($deleted, 200, 'record delete');
if (($deleted['json']['data']['deleted'] ?? false) !== true) {
    throw new RuntimeException('HTTP smoke delete did not return deleted=true.');
}

$disabled = request_json($baseUrl, 'DELETE', '/v1/sync/devices/' . rawurlencode($deviceId), $token);
assert_response($disabled, 200, 'device disable');
if (($disabled['json']['data']['disabled'] ?? false) !== true) {
    throw new RuntimeException('HTTP smoke device disable did not return disabled=true.');
}

echo json_encode([
    'ok' => true,
    'base_url' => $baseUrl,
    'user_id' => $userId,
    'api_key_display' => $key['display'],
    'device_id' => $deviceId,
    'request_id' => $requestId,
    'file_id' => $fileId,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

/**
 * @return array{status: int, body: string, json: array<string, mixed>|null}
 */
function request_json(string $baseUrl, string $method, string $path, string $token, ?array $payload = null): array
{
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    $body = '';
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $responseBody = file_get_contents($baseUrl . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }
    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;

    return [
        'status' => $status,
        'body' => is_string($responseBody) ? $responseBody : '',
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

/**
 * @param array{status: int, body: string, json: array<string, mixed>|null} $response
 */
function assert_response(array $response, int $status, string $label): void
{
    if ($response['status'] !== $status || ($response['json']['ok'] ?? null) !== true) {
        throw new RuntimeException($label . ' failed: HTTP ' . $response['status'] . ' ' . $response['body']);
    }
}
