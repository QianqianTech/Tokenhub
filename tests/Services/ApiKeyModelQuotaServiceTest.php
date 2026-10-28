<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ApiKeyService;
use TokenHub\Services\ApiKeyModelQuotaService;
use TokenHub\Support\App;

$service = new ApiKeyModelQuotaService();

$unlimited = $service->firstExceededLimit([
    'request_limit' => 0,
    'input_token_limit' => null,
    'output_token_limit' => 0,
    'total_token_limit' => 0,
], [
    'request_count' => 999,
    'input_tokens' => 999,
    'output_tokens' => 999,
    'total_tokens' => 999,
]);
assert_same(null, $unlimited, 'Null and zero model quota limits should be unlimited.');

$blocked = $service->firstExceededLimit([
    'total_token_limit' => 100,
], [
    'total_tokens' => 101,
]);
assert_same('MODEL_QUOTA_EXCEEDED', $blocked['code'], 'Positive token limit breach should return MODEL_QUOTA_EXCEEDED.');
assert_same('total_token_limit', $blocked['limit'], 'Exceeded model quota should identify total_token_limit.');

$key = $service->counterKey(
    ['id' => 5],
    ['api_key_id' => 7, 'model_id' => 11],
    ['bucket' => '20260708', 'ttl' => 86400],
    'total_tokens'
);
assert_same('model_quota:5:api_key:7:model:11:total_tokens:20260708', $key, 'Model quota counter key should isolate API key and model.');

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$departmentName = 'Model Quota Perf Dept ' . $suffix;
$username = 'model_quota_perf_user_' . $suffix;
$providerName = 'Model Quota Perf Provider ' . $suffix;
$modelName = 'model-quota-perf-' . $suffix;
$cleanup = static function () use ($db, $departmentName, $username, $providerName, $modelName): void {
    $modelIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT id FROM models WHERE name = ?', [$modelName])
    );
    $apiKeyIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT k.id FROM api_keys k JOIN users u ON u.id = k.user_id WHERE u.username = ?', [$username])
    );
    if ($apiKeyIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($apiKeyIds), '?'));
        $db->execute("DELETE FROM api_key_model_quotas WHERE api_key_id IN ({$placeholders})", $apiKeyIds);
        $db->execute("DELETE FROM api_key_model_permissions WHERE api_key_id IN ({$placeholders})", $apiKeyIds);
        $db->execute("DELETE FROM api_keys WHERE id IN ({$placeholders})", $apiKeyIds);
    }
    if ($modelIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($modelIds), '?'));
        $db->execute("DELETE FROM model_prices WHERE model_id IN ({$placeholders})", $modelIds);
        $db->execute("DELETE FROM models WHERE id IN ({$placeholders})", $modelIds);
    }
    $db->execute('DELETE FROM providers WHERE name = ?', [$providerName]);
    $db->execute('DELETE FROM users WHERE username = ?', [$username]);
    $db->execute('DELETE FROM departments WHERE name = ?', [$departmentName]);
};
$cleanup();
register_shutdown_function($cleanup);

$db->execute(
    'INSERT INTO departments (name, path, level, sort_order, status) VALUES (?, ?, 1, 0, "active")',
    [$departmentName, '/model-quota-perf-' . $suffix . '/']
);
$departmentId = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")',
    [$departmentId, $username, password_hash('secret', PASSWORD_DEFAULT)]
);
$userId = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO providers (name, protocol, base_url, status, timeout_ms, retry_count) VALUES (?, "openai", "https://model-quota-perf.example", "active", 60000, 1)',
    [$providerName]
);
$providerId = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO models (provider_id, name, external_name, capability, status, route_priority) VALUES (?, ?, ?, "text_generation", "active", 1)',
    [$providerId, $modelName, $modelName]
);
$modelId = (int) $db->pdo()->lastInsertId();
$apiKey = (new ApiKeyService())->create([
    'user_id' => $userId,
    'department_id' => $departmentId,
    'name' => 'Model Quota Perf Key ' . $suffix,
], $userId);
$apiKeyId = (int) $apiKey['id'];
$db->execute(
    'INSERT INTO api_key_model_quotas (api_key_id, model_id, period, request_limit, status) VALUES (?, ?, "minute", 1, "active")',
    [$apiKeyId, $modelId]
);
$route = ['id' => $modelId];
$apiKeyRow = ['id' => $apiKeyId];
App::redis()->delete($service->quotaCacheKey($apiKeyId, $modelId));

$firstModelPreflight = $service->preflight($apiKeyRow, $route, ['total_tokens' => 1]);
assert_same(true, $firstModelPreflight['allowed'], 'First model quota preflight should allow the first request.');

$db->execute('UPDATE api_key_model_quotas SET status = "disabled" WHERE api_key_id = ? AND model_id = ?', [$apiKeyId, $modelId]);
$secondModelPreflight = $service->preflight($apiKeyRow, $route, ['total_tokens' => 1]);
assert_same(false, $secondModelPreflight['allowed'], 'Warm model quota preflight should use cached model quota metadata before the short TTL expires.');
