<?php

declare(strict_types=1);

use TokenHub\Services\ApiKeyModelQuotaService;
use TokenHub\Services\ApiKeyService;
use TokenHub\Services\DesensitizationService;
use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\ModelRouter;
use TokenHub\Services\QuotaService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Database;

require __DIR__ . '/../vendor/autoload.php';

App::bootstrap(dirname(__DIR__));

$options = getopt('', ['iterations:', 'warmup:', 'help']) ?: [];
if (isset($options['help'])) {
    echo "Usage: php scripts/api-key-performance-benchmark.php [--iterations=500] [--warmup=20]\n";
    echo "Environment: TOKENHUB_API_KEY_BENCHMARK_ITERATIONS, TOKENHUB_API_KEY_BENCHMARK_WARMUP\n";
    exit(0);
}
$iterations = positive_int(
    $options['iterations'] ?? getenv('TOKENHUB_API_KEY_BENCHMARK_ITERATIONS') ?: 500,
    500
);
$warmupIterations = positive_int(
    $options['warmup'] ?? getenv('TOKENHUB_API_KEY_BENCHMARK_WARMUP') ?: 20,
    20
);

$db = App::db();
$redis = App::redis();
$suffix = bin2hex(random_bytes(4));
$prefix = 'bench_' . $suffix;

$cleanup = static function () use ($db, $prefix): void {
    cleanup_benchmark_rows($db, $prefix);
};
$cleanup();
register_shutdown_function($cleanup);

$fixture = create_fixture($db, $prefix);
$apiKeyService = new ApiKeyService();
$createdKey = $apiKeyService->create([
    'user_id' => $fixture['user_id'],
    'department_id' => $fixture['department_id'],
    'name' => $prefix . '_api_key',
    'model_ids' => [$fixture['model_id']],
], $fixture['user_id']);
$plainKey = (string) $createdKey['key'];
$apiKeyId = (int) $createdKey['id'];

$routeRequest = new GatewayRequest(
    '/v1/chat/completions',
    'openai',
    'text_generation',
    $fixture['model_name'],
    false,
    ['model' => $fixture['model_name'], 'messages' => [['role' => 'user', 'content' => 'ping']]],
    ['input_tokens' => 8, 'output_tokens' => 4, 'total_tokens' => 12, 'cost' => 0.0001]
);

$route = [
    'id' => $fixture['model_id'],
    'provider_id' => $fixture['provider_id'],
    'capability' => 'text_generation',
];

$db->execute(
    'INSERT INTO api_key_model_quotas (api_key_id, model_id, period, request_limit, input_token_limit, output_token_limit, total_token_limit, status)
     VALUES (?, ?, "day", 10000000, 10000000, 10000000, 10000000, "active")',
    [$apiKeyId, $fixture['model_id']]
);
$db->execute(
    'INSERT INTO quota_policies (name, period, request_limit, input_token_limit, output_token_limit, total_token_limit, cost_limit, status)
     VALUES (?, "day", 10000000, 10000000, 10000000, 10000000, NULL, "active")',
    [$prefix . '_quota_policy']
);
$quotaPolicyId = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES (?, "api_key", ?)',
    [$quotaPolicyId, $apiKeyId]
);
$db->execute(
    'INSERT INTO desensitization_policies (name, scope_type, scope_id, parsers, action, status)
     VALUES (?, "api_key", ?, ?, "replace", "active")',
    [$prefix . '_desens_policy', $apiKeyId, json_encode(['json'], JSON_UNESCAPED_SLASHES)]
);

$apiKey = $db->first('SELECT * FROM api_keys WHERE id = ?', [$apiKeyId]) ?: [];
$apiKey['user_id'] = $fixture['user_id'];
$apiKey['department_id'] = $fixture['department_id'];

$modelRouter = new ModelRouter();
$quotaService = new QuotaService();
$modelQuotaService = new ApiKeyModelQuotaService();
$desensitizationService = new DesensitizationService();
$hash = Crypto::hashKey($plainKey);

$cacheKeys = [
    'api_key' => $apiKeyService->cacheKey($hash),
    'api_key_last_used' => $apiKeyService->lastUsedCacheKey($apiKeyId),
    'model_route' => $modelRouter->cacheKey($routeRequest),
    'quota_policies' => $quotaService->policyCacheKey($apiKey),
    'model_quotas' => $modelQuotaService->quotaCacheKey($apiKeyId, $fixture['model_id']),
    'desensitization_policies' => $desensitizationService->policyCacheKey($apiKey, $route),
];
foreach ($cacheKeys as $cacheKey) {
    $redis->delete($cacheKey);
}

$measurements = [
    'api_key_authenticate' => [],
    'model_route' => [],
    'quota_preflight' => [],
    'api_key_model_quota_preflight' => [],
    'desensitization_policy_lookup' => [],
    'combined_hot_path' => [],
];

$operation = static function () use (
    $apiKeyService,
    $plainKey,
    $fixture,
    $modelRouter,
    $routeRequest,
    $quotaService,
    $apiKey,
    $modelQuotaService,
    $route,
    $desensitizationService
): array {
    $auth = $apiKeyService->authenticate($plainKey, '127.0.0.1', $fixture['model_name']);
    if (($auth['ok'] ?? false) !== true) {
        throw new RuntimeException('API key authentication failed during benchmark.');
    }
    $resolvedRoute = $modelRouter->route($routeRequest);
    if ($resolvedRoute === null) {
        throw new RuntimeException('Model route failed during benchmark.');
    }
    $quota = $quotaService->preflight($apiKey, $routeRequest->estimatedUsage);
    if (($quota['allowed'] ?? false) !== true) {
        throw new RuntimeException('Quota preflight failed during benchmark.');
    }
    $modelQuota = $modelQuotaService->preflight($apiKey, $route, $routeRequest->estimatedUsage);
    if (($modelQuota['allowed'] ?? false) !== true) {
        throw new RuntimeException('Model quota preflight failed during benchmark.');
    }
    $policies = $desensitizationService->policiesFor($apiKey, $route);

    return [
        'route_id' => (int) ($resolvedRoute['id'] ?? 0),
        'desensitization_policy_count' => count($policies),
    ];
};

for ($i = 0; $i < $warmupIterations; $i++) {
    $operation();
}

for ($i = 0; $i < $iterations; $i++) {
    $measurements['api_key_authenticate'][] = duration_ms(
        static fn (): array => $apiKeyService->authenticate($plainKey, '127.0.0.1', $fixture['model_name'])
    );
    $measurements['model_route'][] = duration_ms(static fn (): ?array => $modelRouter->route($routeRequest));
    $measurements['quota_preflight'][] = duration_ms(
        static fn (): array => $quotaService->preflight($apiKey, $routeRequest->estimatedUsage)
    );
    $measurements['api_key_model_quota_preflight'][] = duration_ms(
        static fn (): array => $modelQuotaService->preflight($apiKey, $route, $routeRequest->estimatedUsage)
    );
    $measurements['desensitization_policy_lookup'][] = duration_ms(
        static fn (): array => $desensitizationService->policiesFor($apiKey, $route)
    );
    $measurements['combined_hot_path'][] = duration_ms($operation);
}

$summary = [];
foreach ($measurements as $name => $durations) {
    $summary[$name] = latency_summary($durations);
}

$warmed = [];
foreach ($cacheKeys as $name => $cacheKey) {
    $warmed[$name] = $redis->get($cacheKey) !== null;
}

echo json_encode([
    'ok' => true,
    'iterations' => $iterations,
    'warmup_iterations' => $warmupIterations,
    'benchmark' => 'api_key_hot_path',
    'cache_keys_warmed' => $warmed,
    'metrics' => $summary,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

/**
 * @return array{department_id:int,user_id:int,provider_id:int,model_id:int,model_name:string}
 */
function create_fixture(Database $db, string $prefix): array
{
    $departmentName = $prefix . '_dept';
    $username = $prefix . '_user';
    $providerName = $prefix . '_provider';
    $modelName = $prefix . '_model';

    $db->execute(
        'INSERT INTO departments (name, path, level, sort_order, status) VALUES (?, ?, 1, 0, "active")',
        [$departmentName, '/' . $prefix . '/']
    );
    $departmentId = (int) $db->pdo()->lastInsertId();

    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")',
        [$departmentId, $username, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]
    );
    $userId = (int) $db->pdo()->lastInsertId();

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

    return [
        'department_id' => $departmentId,
        'user_id' => $userId,
        'provider_id' => $providerId,
        'model_id' => $modelId,
        'model_name' => $modelName,
    ];
}

function cleanup_benchmark_rows(Database $db, string $prefix): void
{
    $apiKeyIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT id FROM api_keys WHERE name = ?', [$prefix . '_api_key'])
    );
    delete_by_ids($db, 'api_key_model_permissions', 'api_key_id', $apiKeyIds);
    delete_by_ids($db, 'api_key_model_quotas', 'api_key_id', $apiKeyIds);
    delete_by_ids($db, 'desensitization_events', 'policy_id', policy_ids($db, $prefix));
    $db->execute('DELETE FROM desensitization_policies WHERE name = ?', [$prefix . '_desens_policy']);
    delete_by_ids($db, 'quota_bindings', 'quota_policy_id', policy_quota_ids($db, $prefix));
    $db->execute('DELETE FROM quota_policies WHERE name = ?', [$prefix . '_quota_policy']);
    delete_by_ids($db, 'api_keys', 'id', $apiKeyIds);

    $modelIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT id FROM models WHERE name = ?', [$prefix . '_model'])
    );
    delete_by_ids($db, 'model_prices', 'model_id', $modelIds);
    delete_by_ids($db, 'models', 'id', $modelIds);
    $db->execute('DELETE FROM providers WHERE name = ?', [$prefix . '_provider']);
    $db->execute('DELETE FROM users WHERE username = ?', [$prefix . '_user']);
    $db->execute('DELETE FROM departments WHERE name = ?', [$prefix . '_dept']);
}

/**
 * @return list<int>
 */
function policy_ids(Database $db, string $prefix): array
{
    return array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT id FROM desensitization_policies WHERE name = ?', [$prefix . '_desens_policy'])
    );
}

/**
 * @return list<int>
 */
function policy_quota_ids(Database $db, string $prefix): array
{
    return array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT id FROM quota_policies WHERE name = ?', [$prefix . '_quota_policy'])
    );
}

/**
 * @param list<int> $ids
 */
function delete_by_ids(Database $db, string $table, string $column, array $ids): void
{
    if ($ids === []) {
        return;
    }
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $db->execute("DELETE FROM {$table} WHERE {$column} IN ({$placeholders})", $ids);
}

function duration_ms(callable $callback): float
{
    $start = hrtime(true);
    $callback();
    return (hrtime(true) - $start) / 1_000_000;
}

/**
 * @param list<float> $durations
 * @return array{count:int,min_ms:float,p50_ms:float,p95_ms:float,max_ms:float,avg_ms:float}
 */
function latency_summary(array $durations): array
{
    sort($durations);
    $count = count($durations);
    $sum = array_sum($durations);

    return [
        'count' => $count,
        'min_ms' => round($durations[0] ?? 0.0, 4),
        'p50_ms' => round(percentile($durations, 0.50), 4),
        'p95_ms' => round(percentile($durations, 0.95), 4),
        'max_ms' => round($durations[$count - 1] ?? 0.0, 4),
        'avg_ms' => round($count > 0 ? $sum / $count : 0.0, 4),
    ];
}

/**
 * @param list<float> $values
 */
function percentile(array $values, float $percentile): float
{
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }
    $index = (int) ceil($count * $percentile) - 1;
    return $values[max(0, min($count - 1, $index))];
}

function positive_int(mixed $value, int $fallback): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($parsed) ? $parsed : $fallback;
}
