<?php

declare(strict_types=1);

use TokenHub\Support\Database;

$container = require __DIR__ . '/../app/Bootstrap/container.php';

/** @var Database $db */
$db = $container[Database::class];

$requiredTables = [
    'departments',
    'users',
    'providers',
    'models',
    'model_prices',
    'api_keys',
    'api_key_model_permissions',
    'quota_policies',
    'quota_bindings',
    'usage_records',
    'usage_contents',
    'usage_aggregates_minute',
    'usage_aggregates_hour',
    'usage_aggregates_day',
    'multimodal_tasks',
    'media_assets',
    'export_tasks',
    'dashboard_share_links',
    'dashboard_share_access_logs',
    'waf_rules',
    'waf_rule_versions',
    'waf_events',
    'desensitization_policies',
    'desensitization_events',
    'log_archive_files',
    'alert_events',
    'audit_logs',
    'system_settings',
];

foreach ($requiredTables as $table) {
    if (!tableExists($db, $table)) {
        fwrite(STDERR, "Missing table {$table}. Run migrations before seeding demo data.\n");
        exit(1);
    }
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$summary = [];

$db->transaction(function (Database $db) use ($now, &$summary): void {
    $departments = seedDepartments($db);
    $users = seedUsers($db, $departments, $now);
    [$providers, $models] = seedProvidersAndModels($db, $now);
    seedModelPrices($db, $models, $now);
    $apiKeys = seedApiKeys($db, $users, $departments, $models, $now);
    seedQuotas($db, $departments, $users, $apiKeys);
    $usage = seedUsage($db, $departments, $users, $providers, $models, $apiKeys, $now);
    seedAggregates($db, $departments, $users, $providers, $models, $apiKeys, $now);
    seedMultimodal($db, $departments, $users, $providers, $models, $apiKeys, $usage, $now);
    seedSecurity($db, $users, $now);
    seedOperations($db, $users, $departments, $now);
    seedScreenShares($db, $now);
    seedSystemSettings($db, $now);

    $summary = [
        'departments' => count($departments),
        'users' => count($users),
        'providers' => count($providers),
        'models' => count($models),
        'api_keys' => count($apiKeys),
        'usage_records' => count($usage),
        'usage_aggregates_hour' => countRowsLike($db, 'usage_aggregates_hour', 'capability', 'text_generation'),
        'multimodal_tasks' => countRowsLike($db, 'multimodal_tasks', 'task_id', 'demo-task-%'),
        'waf_events' => countRowsLike($db, 'waf_events', 'request_id', 'demo-waf-%'),
        'dashboard_share_links' => countRowsLike($db, 'dashboard_share_links', 'share_code', 'demo-share-%'),
    ];
});

echo "TokenHub demo data seeded.\n";
foreach ($summary as $name => $count) {
    echo "- {$name}: {$count}\n";
}
echo "Demo admin users use password: Demo@123456\n";
echo "Demo share user password: DemoScreen@123\n";

function tableExists(Database $db, string $table): bool
{
    return $db->first(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    ) !== null;
}

/**
 * @return list<string>
 */
function tableColumns(Database $db, string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_map('strval', array_column($db->select("SHOW COLUMNS FROM {$table}"), 'Field'));
    }
    return $cache[$table];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function filterRow(Database $db, string $table, array $row): array
{
    return array_intersect_key($row, array_flip(tableColumns($db, $table)));
}

/**
 * @param array<string, mixed> $row
 */
function insertOnDuplicate(Database $db, string $table, array $row, array $skipUpdate = ['id', 'created_at']): void
{
    $row = filterRow($db, $table, $row);
    $columns = array_keys($row);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $updates = [];
    foreach ($columns as $column) {
        if (!in_array($column, $skipUpdate, true)) {
            $updates[] = "{$column} = VALUES({$column})";
        }
    }
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
        $table,
        implode(', ', $columns),
        $placeholders,
        $updates === [] ? "{$columns[0]} = {$columns[0]}" : implode(', ', $updates)
    );
    $db->execute($sql, array_values($row));
}

/**
 * @param array<string, mixed> $keys
 * @param array<string, mixed> $row
 */
function upsertByKey(Database $db, string $table, array $keys, array $row): int
{
    $row = filterRow($db, $table, $row);
    $where = implode(' AND ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($keys)));
    $existing = $db->first("SELECT id FROM {$table} WHERE {$where} LIMIT 1", array_values($keys));
    if ($existing) {
        $updates = array_diff_key($row, array_flip(['id', 'created_at']));
        if ($updates !== []) {
            $sets = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($updates)));
            $db->execute("UPDATE {$table} SET {$sets} WHERE id = ?", [...array_values($updates), (int) $existing['id']]);
        }
        return (int) $existing['id'];
    }

    $columns = array_keys($row);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $db->execute(
        sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), $placeholders),
        array_values($row)
    );
    return (int) $db->pdo()->lastInsertId();
}

function jsonValue(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function ts(DateTimeImmutable $now, string $modifier = 'now'): string
{
    return $now->modify($modifier)->format('Y-m-d H:i:s');
}

function money(float $value): string
{
    return number_format($value, 8, '.', '');
}

function demoHash(string $value): string
{
    return hash('sha256', 'tokenhub-demo:' . $value);
}

/**
 * @return array<string, int>
 */
function seedDepartments(Database $db): array
{
    insertOnDuplicate($db, 'departments', [
        'id' => 1,
        'parent_id' => null,
        'name' => 'TokenHub 总部',
        'path' => '/1/',
        'level' => 0,
        'sort_order' => 0,
        'status' => 'active',
    ]);

    $items = [
        'platform' => ['name' => '平台工程部', 'parent_id' => 1, 'sort_order' => 10],
        'growth' => ['name' => '增长运营部', 'parent_id' => 1, 'sort_order' => 20],
        'risk' => ['name' => '安全风控部', 'parent_id' => 1, 'sort_order' => 30],
        'platform-ai' => ['name' => 'AI 应用组', 'parent_key' => 'platform', 'sort_order' => 11],
        'growth-data' => ['name' => '数据分析组', 'parent_key' => 'growth', 'sort_order' => 21],
    ];

    $ids = ['root' => 1, 'demo-eng-platform' => 1];
    foreach ($items as $key => $item) {
        $parentId = isset($item['parent_key']) ? $ids[(string) $item['parent_key']] : (int) $item['parent_id'];
        $id = upsertByKey($db, 'departments', ['name' => $item['name'], 'parent_id' => $parentId], [
            'parent_id' => $parentId,
            'name' => $item['name'],
            'path' => '/pending/',
            'level' => 1,
            'sort_order' => $item['sort_order'],
            'status' => 'active',
        ]);
        $parent = $db->first('SELECT path, level FROM departments WHERE id = ?', [$parentId]) ?: ['path' => '/1/', 'level' => 0];
        $db->execute('UPDATE departments SET path = ?, level = ? WHERE id = ?', [
            (string) $parent['path'] . $id . '/',
            (int) $parent['level'] + 1,
            $id,
        ]);
        $ids[$key] = $id;
    }

    return $ids;
}

/**
 * @param array<string, int> $departments
 * @return array<string, int>
 */
function seedUsers(Database $db, array $departments, DateTimeImmutable $now): array
{
    $password = password_hash('Demo@123456', PASSWORD_DEFAULT);
    $users = [
        'super' => ['username' => 'demo-super-admin', 'department_id' => 1, 'role' => 'super_admin', 'managed' => [1], 'last' => '-8 minutes'],
        'platform-admin' => ['username' => 'demo-platform-admin', 'department_id' => $departments['platform'], 'role' => 'admin', 'managed' => [$departments['platform'], $departments['platform-ai']], 'last' => '-18 minutes'],
        'ops-admin' => ['username' => 'demo-ops-admin', 'department_id' => $departments['growth'], 'role' => 'admin', 'managed' => [$departments['growth'], $departments['growth-data']], 'last' => '-42 minutes'],
        'risk-user' => ['username' => 'demo-risk-analyst', 'department_id' => $departments['risk'], 'role' => 'user', 'managed' => [], 'last' => '-2 hours'],
        'app-user' => ['username' => 'demo-app-builder', 'department_id' => $departments['platform-ai'], 'role' => 'user', 'managed' => [], 'last' => '-31 minutes'],
        'data-user' => ['username' => 'demo-data-analyst', 'department_id' => $departments['growth-data'], 'role' => 'user', 'managed' => [], 'last' => '-3 hours'],
    ];

    $ids = [];
    foreach ($users as $key => $user) {
        $ids[$key] = upsertByKey($db, 'users', ['username' => $user['username']], [
            'department_id' => $user['department_id'],
            'username' => $user['username'],
            'password_hash' => $password,
            'role' => $user['role'],
            'status' => 'active',
            'managed_department_ids' => jsonValue($user['managed']),
            'last_login_at' => ts($now, $user['last']),
        ]);
    }

    return $ids;
}

/**
 * @return array{0:array<string,int>,1:array<string,int>}
 */
function seedProvidersAndModels(Database $db, DateTimeImmutable $now): array
{
    $providerRows = [
        'openai' => ['name' => 'demo-provider-openai', 'protocol' => 'openai', 'base_url' => 'https://api.openai.example/v1', 'timeout_ms' => 60000, 'retry_count' => 2],
        'anthropic' => ['name' => 'demo-provider-anthropic', 'protocol' => 'anthropic', 'base_url' => 'https://api.anthropic.example', 'timeout_ms' => 65000, 'retry_count' => 1],
        'native' => ['name' => 'demo-provider-native-media', 'protocol' => 'native', 'base_url' => 'https://media.example.internal', 'timeout_ms' => 120000, 'retry_count' => 1],
    ];

    $providers = [];
    foreach ($providerRows as $key => $row) {
        $providers[$key] = upsertByKey($db, 'providers', ['name' => $row['name']], [
            ...$row,
            'encrypted_secret' => null,
            'status' => 'active',
            'default_headers' => jsonValue(['X-Demo-Tenant' => 'tokenhub']),
            'created_at' => ts($now, '-10 days'),
        ]);
    }

    $modelRows = [
        'gpt-4o-demo' => ['provider' => 'openai', 'external_name' => 'gpt-4o', 'capability' => 'text_generation', 'context_length' => 128000, 'route_priority' => 10],
        'gpt-4o-mini-demo' => ['provider' => 'openai', 'external_name' => 'gpt-4o-mini', 'capability' => 'text_generation', 'context_length' => 128000, 'route_priority' => 20],
        'vision-demo' => ['provider' => 'openai', 'external_name' => 'gpt-4o-vision', 'capability' => 'vision_understanding', 'context_length' => 64000, 'route_priority' => 30],
        'embed-demo' => ['provider' => 'openai', 'external_name' => 'text-embedding-3-large', 'capability' => 'embedding', 'context_length' => 8191, 'route_priority' => 40],
        'claude-demo' => ['provider' => 'anthropic', 'external_name' => 'claude-sonnet-demo', 'capability' => 'text_generation', 'context_length' => 200000, 'route_priority' => 15],
        'asr-demo' => ['provider' => 'native', 'external_name' => 'demo-asr-v1', 'capability' => 'asr', 'context_length' => null, 'route_priority' => 50],
        'tts-demo' => ['provider' => 'native', 'external_name' => 'demo-tts-v1', 'capability' => 'tts', 'context_length' => null, 'route_priority' => 51],
        'image-demo' => ['provider' => 'native', 'external_name' => 'demo-image-v1', 'capability' => 'image_generation', 'context_length' => null, 'route_priority' => 52],
        'video-demo' => ['provider' => 'native', 'external_name' => 'demo-video-v1', 'capability' => 'video_generation', 'context_length' => null, 'route_priority' => 53],
    ];

    $models = [];
    foreach ($modelRows as $name => $row) {
        $models[$name] = upsertByKey($db, 'models', ['provider_id' => $providers[$row['provider']], 'name' => $name], [
            'provider_id' => $providers[$row['provider']],
            'name' => $name,
            'external_name' => $row['external_name'],
            'capability' => $row['capability'],
            'context_length' => $row['context_length'],
            'status' => 'active',
            'route_priority' => $row['route_priority'],
            'created_at' => ts($now, '-10 days'),
        ]);
    }

    return [$providers, $models];
}

/**
 * @param array<string, int> $models
 */
function seedModelPrices(Database $db, array $models, DateTimeImmutable $now): void
{
    $prices = [
        'gpt-4o-demo' => ['input_token_price' => '0.00000250', 'output_token_price' => '0.00001000'],
        'gpt-4o-mini-demo' => ['input_token_price' => '0.00000015', 'output_token_price' => '0.00000060'],
        'vision-demo' => ['input_token_price' => '0.00000300', 'output_token_price' => '0.00001200', 'request_price' => '0.00100000'],
        'embed-demo' => ['input_token_price' => '0.00000013', 'output_token_price' => null],
        'claude-demo' => ['input_token_price' => '0.00000300', 'output_token_price' => '0.00001500'],
        'asr-demo' => ['audio_second_price' => '0.00010000'],
        'tts-demo' => ['tts_char_price' => '0.00001000'],
        'image-demo' => ['image_price' => '0.03000000'],
        'video-demo' => ['video_second_price' => '0.05000000'],
    ];

    foreach ($prices as $modelName => $price) {
        upsertByKey($db, 'model_prices', ['model_id' => $models[$modelName], 'currency' => 'USD'], [
            'model_id' => $models[$modelName],
            'input_token_price' => $price['input_token_price'] ?? null,
            'output_token_price' => $price['output_token_price'] ?? null,
            'audio_second_price' => $price['audio_second_price'] ?? null,
            'tts_char_price' => $price['tts_char_price'] ?? null,
            'image_price' => $price['image_price'] ?? null,
            'video_second_price' => $price['video_second_price'] ?? null,
            'request_price' => $price['request_price'] ?? null,
            'currency' => 'USD',
            'effective_at' => ts($now, '-30 days'),
        ]);
    }
}

/**
 * @param array<string, int> $users
 * @param array<string, int> $departments
 * @param array<string, int> $models
 * @return array<string, int>
 */
function seedApiKeys(Database $db, array $users, array $departments, array $models, DateTimeImmutable $now): array
{
    $rows = [
        'platform' => ['name' => 'demo-key-platform', 'user_id' => $users['app-user'], 'department_id' => $departments['platform-ai'], 'prefix' => 'demo-key-platform', 'suffix' => 'p001', 'models' => ['gpt-4o-demo', 'gpt-4o-mini-demo', 'vision-demo', 'embed-demo']],
        'ops' => ['name' => 'demo-key-growth-ops', 'user_id' => $users['data-user'], 'department_id' => $departments['growth-data'], 'prefix' => 'demo-key-growth', 'suffix' => 'g002', 'models' => ['gpt-4o-mini-demo', 'embed-demo', 'claude-demo']],
        'media' => ['name' => 'demo-key-media-lab', 'user_id' => $users['platform-admin'], 'department_id' => $departments['platform'], 'prefix' => 'demo-key-media', 'suffix' => 'm003', 'models' => ['asr-demo', 'tts-demo', 'image-demo', 'video-demo']],
        'risk' => ['name' => 'demo-key-risk-review', 'user_id' => $users['risk-user'], 'department_id' => $departments['risk'], 'prefix' => 'demo-key-risk', 'suffix' => 'r004', 'models' => ['gpt-4o-demo', 'claude-demo']],
    ];

    $ids = [];
    foreach ($rows as $key => $row) {
        $hash = demoHash($row['name']);
        $ids[$key] = upsertByKey($db, 'api_keys', ['key_hash' => $hash], [
            'user_id' => $row['user_id'],
            'department_id' => $row['department_id'],
            'name' => $row['name'],
            'key_prefix' => $row['prefix'],
            'key_suffix' => $row['suffix'],
            'key_hash' => $hash,
            'allowed_ip_cidrs' => jsonValue(['10.0.0.0/8', '192.0.2.0/24']),
            'status' => 'active',
            'expires_at' => ts($now, '+90 days'),
            'last_used_at' => ts($now, '-7 minutes'),
            'created_by' => $users['super'],
            'created_at' => ts($now, '-9 days'),
        ]);
        foreach ($row['models'] as $modelName) {
            insertOnDuplicate($db, 'api_key_model_permissions', [
                'api_key_id' => $ids[$key],
                'model_id' => $models[$modelName],
            ]);
        }
    }

    return $ids;
}

/**
 * @param array<string, int> $departments
 * @param array<string, int> $users
 * @param array<string, int> $apiKeys
 */
function seedQuotas(Database $db, array $departments, array $users, array $apiKeys): void
{
    $policies = [
        'demo-policy-platform-daily' => ['period' => 'day', 'request_limit' => 50000, 'total_token_limit' => 80000000, 'cost_limit' => '250.00000000', 'concurrency_limit' => 80, 'target_type' => 'department', 'target_id' => $departments['platform']],
        'demo-policy-growth-hourly' => ['period' => 'hour', 'request_limit' => 8000, 'total_token_limit' => 12000000, 'cost_limit' => '40.00000000', 'concurrency_limit' => 30, 'target_type' => 'department', 'target_id' => $departments['growth']],
        'demo-policy-risk-user' => ['period' => 'day', 'request_limit' => 2000, 'total_token_limit' => 3000000, 'cost_limit' => '20.00000000', 'concurrency_limit' => 8, 'target_type' => 'user', 'target_id' => $users['risk-user']],
        'demo-policy-media-key' => ['period' => 'day', 'request_limit' => 1000, 'image_limit' => 300, 'video_second_limit' => 1800, 'cost_limit' => '120.00000000', 'concurrency_limit' => 5, 'target_type' => 'api_key', 'target_id' => $apiKeys['media']],
    ];

    foreach ($policies as $name => $policy) {
        $policyId = upsertByKey($db, 'quota_policies', ['name' => $name], [
            'name' => $name,
            'period' => $policy['period'],
            'request_limit' => $policy['request_limit'],
            'input_token_limit' => null,
            'output_token_limit' => null,
            'total_token_limit' => $policy['total_token_limit'] ?? null,
            'audio_second_limit' => $policy['audio_second_limit'] ?? null,
            'tts_char_limit' => $policy['tts_char_limit'] ?? null,
            'image_limit' => $policy['image_limit'] ?? null,
            'video_second_limit' => $policy['video_second_limit'] ?? null,
            'cost_limit' => $policy['cost_limit'],
            'concurrency_limit' => $policy['concurrency_limit'],
            'status' => 'active',
        ]);
        upsertByKey($db, 'quota_bindings', [
            'quota_policy_id' => $policyId,
            'target_type' => $policy['target_type'],
            'target_id' => $policy['target_id'],
        ], [
            'quota_policy_id' => $policyId,
            'target_type' => $policy['target_type'],
            'target_id' => $policy['target_id'],
        ]);
    }
}

/**
 * @return list<array<string, mixed>>
 */
function seedUsage(Database $db, array $departments, array $users, array $providers, array $models, array $apiKeys, DateTimeImmutable $now): array
{
    $patterns = [
        ['suffix' => 'chat-001', 'user' => 'app-user', 'dept' => 'platform-ai', 'key' => 'platform', 'provider' => 'openai', 'model' => 'gpt-4o-demo', 'capability' => 'text_generation', 'status' => 'success', 'in' => 1860, 'out' => 760, 'latency' => 418, 'hours' => -1, 'endpoint' => '/v1/chat/completions'],
        ['suffix' => 'chat-002', 'user' => 'data-user', 'dept' => 'growth-data', 'key' => 'ops', 'provider' => 'openai', 'model' => 'gpt-4o-mini-demo', 'capability' => 'text_generation', 'status' => 'success', 'in' => 940, 'out' => 310, 'latency' => 156, 'hours' => -2, 'endpoint' => '/v1/chat/completions'],
        ['suffix' => 'claude-003', 'user' => 'risk-user', 'dept' => 'risk', 'key' => 'risk', 'provider' => 'anthropic', 'model' => 'claude-demo', 'capability' => 'text_generation', 'status' => 'failed', 'in' => 1200, 'out' => 0, 'latency' => 2900, 'hours' => -3, 'endpoint' => '/v1/messages', 'error' => 'PROVIDER_RATE_LIMIT'],
        ['suffix' => 'vision-004', 'user' => 'app-user', 'dept' => 'platform-ai', 'key' => 'platform', 'provider' => 'openai', 'model' => 'vision-demo', 'capability' => 'vision_understanding', 'status' => 'success', 'in' => 2640, 'out' => 420, 'latency' => 980, 'hours' => -5, 'endpoint' => '/v1/chat/completions'],
        ['suffix' => 'embed-005', 'user' => 'data-user', 'dept' => 'growth-data', 'key' => 'ops', 'provider' => 'openai', 'model' => 'embed-demo', 'capability' => 'embedding', 'status' => 'success', 'in' => 12800, 'out' => 0, 'latency' => 82, 'hours' => -7, 'endpoint' => '/v1/embeddings'],
        ['suffix' => 'asr-006', 'user' => 'platform-admin', 'dept' => 'platform', 'key' => 'media', 'provider' => 'native', 'model' => 'asr-demo', 'capability' => 'asr', 'status' => 'success', 'in' => 0, 'out' => 680, 'latency' => 4100, 'hours' => -10, 'endpoint' => '/v1/audio/transcriptions', 'audio_seconds' => 138],
        ['suffix' => 'tts-007', 'user' => 'platform-admin', 'dept' => 'platform', 'key' => 'media', 'provider' => 'native', 'model' => 'tts-demo', 'capability' => 'tts', 'status' => 'success', 'in' => 520, 'out' => 0, 'latency' => 1350, 'hours' => -12, 'endpoint' => '/v1/audio/speech', 'tts_chars' => 1180],
        ['suffix' => 'image-008', 'user' => 'platform-admin', 'dept' => 'platform', 'key' => 'media', 'provider' => 'native', 'model' => 'image-demo', 'capability' => 'image_generation', 'status' => 'success', 'in' => 240, 'out' => 0, 'latency' => 6200, 'hours' => -14, 'endpoint' => '/v1/images/generations', 'image_count' => 4],
        ['suffix' => 'video-009', 'user' => 'platform-admin', 'dept' => 'platform', 'key' => 'media', 'provider' => 'native', 'model' => 'video-demo', 'capability' => 'video_generation', 'status' => 'timeout', 'in' => 400, 'out' => 0, 'latency' => 30000, 'hours' => -16, 'endpoint' => '/v1/videos/generations', 'video_seconds' => 12, 'error' => 'PROVIDER_TIMEOUT'],
        ['suffix' => 'blocked-010', 'user' => 'risk-user', 'dept' => 'risk', 'key' => 'risk', 'provider' => 'anthropic', 'model' => 'claude-demo', 'capability' => 'text_generation', 'status' => 'blocked', 'in' => 300, 'out' => 0, 'latency' => 24, 'hours' => -18, 'endpoint' => '/v1/messages', 'error' => 'WAF_BLOCKED'],
    ];

    $records = [];
    foreach ($patterns as $index => $pattern) {
        $total = (int) $pattern['in'] + (int) $pattern['out'];
        $cost = costForPattern($pattern);
        $requestId = 'demo-usage-' . $pattern['suffix'];
        insertOnDuplicate($db, 'usage_records', [
            'request_id' => $requestId,
            'trace_id' => 'demo-trace-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
            'user_id' => $users[$pattern['user']],
            'department_id' => $departments[$pattern['dept']],
            'api_key_id' => $apiKeys[$pattern['key']],
            'provider_id' => $providers[$pattern['provider']],
            'model_id' => $models[$pattern['model']],
            'model_name' => $pattern['model'],
            'protocol' => $pattern['provider'] === 'anthropic' ? 'anthropic' : ($pattern['provider'] === 'native' ? 'native' : 'openai'),
            'endpoint' => $pattern['endpoint'],
            'capability' => $pattern['capability'],
            'status' => $pattern['status'],
            'http_status' => $pattern['status'] === 'success' ? 200 : ($pattern['status'] === 'blocked' ? 403 : 502),
            'error_code' => $pattern['error'] ?? null,
            'input_tokens' => $pattern['in'],
            'output_tokens' => $pattern['out'],
            'total_tokens' => $total,
            'audio_seconds' => $pattern['audio_seconds'] ?? 0,
            'tts_chars' => $pattern['tts_chars'] ?? 0,
            'image_count' => $pattern['image_count'] ?? 0,
            'video_seconds' => $pattern['video_seconds'] ?? 0,
            'cost_amount' => money($cost),
            'price_snapshot' => jsonValue(['currency' => 'USD', 'demo' => true]),
            'latency_ms' => $pattern['latency'],
            'client_ip' => '192.0.2.' . (20 + $index),
            'created_at' => ts($now, $pattern['hours'] . ' hours'),
        ]);

        $record = $db->first('SELECT * FROM usage_records WHERE request_id = ?', [$requestId]);
        if ($record) {
            $records[] = $record;
            upsertByKey($db, 'usage_contents', ['usage_record_id' => (int) $record['id']], [
                'usage_record_id' => (int) $record['id'],
                'user_id' => $record['user_id'],
                'department_id' => $record['department_id'],
                'api_key_id' => $record['api_key_id'],
                'model_id' => $record['model_id'],
                'session_id' => 'demo-session-' . (($index % 4) + 1),
                'retention_mode' => $pattern['status'] === 'blocked' ? 'summary' : 'redacted',
                'encrypted_input' => null,
                'encrypted_output' => null,
                'redacted_input' => jsonValue(['message' => '请基于业务指标生成分析，敏感字段已隐藏。']),
                'redacted_output' => jsonValue(['message' => '已生成可用于演示的分析摘要。']),
                'summary' => demoUsageSummary((string) $pattern['capability'], (string) $pattern['status']),
                'expires_at' => ts($now, '+30 days'),
                'cleaned_at' => null,
                'created_at' => $record['created_at'],
            ]);
        }
    }

    return $records;
}

/**
 * @param array<string, mixed> $pattern
 */
function costForPattern(array $pattern): float
{
    $cost = ((int) $pattern['in'] * 0.000002) + ((int) $pattern['out'] * 0.000008);
    $cost += ((int) ($pattern['audio_seconds'] ?? 0)) * 0.0001;
    $cost += ((int) ($pattern['tts_chars'] ?? 0)) * 0.00001;
    $cost += ((int) ($pattern['image_count'] ?? 0)) * 0.03;
    $cost += ((int) ($pattern['video_seconds'] ?? 0)) * 0.05;
    return $cost;
}

function demoUsageSummary(string $capability, string $status): string
{
    return match ($status) {
        'failed' => '上游限流导致调用失败，已记录错误码用于告警分析。',
        'blocked' => '请求命中安全策略，内容已被阻断并写入审计链路。',
        'timeout' => '多模态任务等待超时，可在性能看板查看延迟桶分布。',
        default => match ($capability) {
            'embedding' => '批量知识片段向量化完成，写入检索索引。',
            'vision_understanding' => '识别上传截图中的运营指标并生成结构化摘要。',
            'asr' => '客户访谈音频转写完成，生成会议纪要草稿。',
            'tts' => '运营播报文本已合成为语音素材。',
            'image_generation' => '根据活动 brief 生成多张宣传图候选。',
            'video_generation' => '视频生成任务已提交并返回预览状态。',
            default => '模型完成业务问答、归因分析和行动建议生成。',
        },
    };
}

function seedAggregates(Database $db, array $departments, array $users, array $providers, array $models, array $apiKeys, DateTimeImmutable $now): void
{
    $series = [
        ['dept' => 'platform-ai', 'user' => 'app-user', 'key' => 'platform', 'provider' => 'openai', 'model' => 'gpt-4o-demo', 'capability' => 'text_generation', 'base' => 180],
        ['dept' => 'growth-data', 'user' => 'data-user', 'key' => 'ops', 'provider' => 'openai', 'model' => 'gpt-4o-mini-demo', 'capability' => 'text_generation', 'base' => 120],
        ['dept' => 'risk', 'user' => 'risk-user', 'key' => 'risk', 'provider' => 'anthropic', 'model' => 'claude-demo', 'capability' => 'text_generation', 'base' => 45],
        ['dept' => 'platform', 'user' => 'platform-admin', 'key' => 'media', 'provider' => 'native', 'model' => 'image-demo', 'capability' => 'image_generation', 'base' => 18],
        ['dept' => 'platform', 'user' => 'platform-admin', 'key' => 'media', 'provider' => 'native', 'model' => 'asr-demo', 'capability' => 'asr', 'base' => 26],
        ['dept' => 'platform', 'user' => 'platform-admin', 'key' => 'media', 'provider' => 'native', 'model' => 'tts-demo', 'capability' => 'tts', 'base' => 34],
    ];

    for ($hour = 47; $hour >= 0; $hour--) {
        $bucket = $now->modify("-{$hour} hours")->format('Y-m-d H:00:00');
        foreach ($series as $index => $item) {
            $count = max(1, (int) round($item['base'] * (0.55 + (($hour + $index) % 9) / 10)));
            $failed = ($hour + $index) % 7 === 0 ? max(1, (int) floor($count * 0.04)) : 0;
            $input = $count * (360 + $index * 120);
            $output = $count * (120 + $index * 35);
            $latency = $count * (120 + $index * 180 + (($hour % 5) * 30));
            $row = aggregateRow($departments, $users, $providers, $models, $apiKeys, $item, $bucket, $count, $failed, $input, $output, $latency);
            insertOnDuplicate($db, 'usage_aggregates_hour', $row);
            if ($hour < 2) {
                for ($minute = 0; $minute < 60; $minute += 15) {
                    $minuteBucket = $now->modify("-{$hour} hours")->modify("+{$minute} minutes")->format('Y-m-d H:i:00');
                    insertOnDuplicate($db, 'usage_aggregates_minute', [
                        ...$row,
                        'bucket_at' => $minuteBucket,
                        'request_count' => max(1, (int) floor($count / 4)),
                        'success_count' => max(1, (int) floor(($count - $failed) / 4)),
                        'failed_count' => (int) floor($failed / 4),
                        'input_tokens' => (int) floor($input / 4),
                        'output_tokens' => (int) floor($output / 4),
                        'total_cost' => money(((float) $row['total_cost']) / 4),
                        'latency_ms_sum' => (int) floor($latency / 4),
                    ]);
                }
            }
        }
    }

    foreach (['today' => 0, 'yesterday' => -1] as $dayName => $dayOffset) {
        $dayBucket = $now->modify("{$dayOffset} days")->format('Y-m-d 00:00:00');
        foreach ($series as $index => $item) {
            $count = ($dayName === 'today' ? 2600 : 3100) + $index * 180;
            $failed = 30 + $index * 3;
            $input = $count * (380 + $index * 100);
            $output = $count * (130 + $index * 30);
            $latency = $count * (150 + $index * 160);
            insertOnDuplicate($db, 'usage_aggregates_day', aggregateRow($departments, $users, $providers, $models, $apiKeys, $item, $dayBucket, $count, $failed, $input, $output, $latency));
        }
    }
}

function aggregateRow(array $departments, array $users, array $providers, array $models, array $apiKeys, array $item, string $bucket, int $count, int $failed, int $input, int $output, int $latency): array
{
    return [
        'bucket_at' => $bucket,
        'department_id' => $departments[$item['dept']],
        'user_id' => $users[$item['user']],
        'api_key_id' => $apiKeys[$item['key']],
        'provider_id' => $providers[$item['provider']],
        'model_id' => $models[$item['model']],
        'capability' => $item['capability'],
        'request_count' => $count,
        'success_count' => $count - $failed,
        'failed_count' => $failed,
        'input_tokens' => $input,
        'output_tokens' => $output,
        'total_cost' => money(($input * 0.0000015) + ($output * 0.000006)),
        'latency_ms_sum' => $latency,
        'latency_bucket_100' => (int) floor($count * 0.18),
        'latency_bucket_300' => (int) floor($count * 0.42),
        'latency_bucket_1000' => (int) floor($count * 0.28),
        'latency_bucket_3000' => (int) floor($count * 0.09),
        'latency_bucket_over_3000' => max(0, $count - (int) floor($count * 0.97)),
    ];
}

function seedMultimodal(Database $db, array $departments, array $users, array $providers, array $models, array $apiKeys, array $usage, DateTimeImmutable $now): void
{
    $usageByCapability = [];
    foreach ($usage as $record) {
        $usageByCapability[(string) $record['capability']] = $record;
    }

    $tasks = [
        ['task_id' => 'demo-task-asr-001', 'type' => 'asr', 'status' => 'succeeded', 'progress' => 100, 'model' => 'asr-demo', 'capability' => 'asr', 'asset' => ['demo-asset-audio-001', 'audio', 'storage/demo/audio/interview-001.mp3', 'audio/mpeg', 18432000, 138, null, null]],
        ['task_id' => 'demo-task-tts-002', 'type' => 'tts', 'status' => 'succeeded', 'progress' => 100, 'model' => 'tts-demo', 'capability' => 'tts', 'asset' => ['demo-asset-tts-002', 'audio', 'storage/demo/audio/briefing-002.wav', 'audio/wav', 7320000, 42, null, null]],
        ['task_id' => 'demo-task-image-003', 'type' => 'image_generation', 'status' => 'succeeded', 'progress' => 100, 'model' => 'image-demo', 'capability' => 'image_generation', 'asset' => ['demo-asset-image-003', 'image', 'storage/demo/images/campaign-003.png', 'image/png', 2480000, null, 1536, 1024]],
        ['task_id' => 'demo-task-video-004', 'type' => 'video_generation', 'status' => 'processing', 'progress' => 68, 'model' => 'video-demo', 'capability' => 'video_generation', 'asset' => ['demo-asset-video-004', 'video', 'storage/demo/videos/product-004.mp4', 'video/mp4', 68200000, 12, 1920, 1080]],
    ];

    foreach ($tasks as $index => $task) {
        $record = $usageByCapability[$task['capability']] ?? null;
        $taskDbId = upsertByKey($db, 'multimodal_tasks', ['task_id' => $task['task_id']], [
            'usage_record_id' => $record['id'] ?? null,
            'task_id' => $task['task_id'],
            'task_type' => $task['type'],
            'status' => $task['status'],
            'progress' => $task['progress'],
            'provider_task_id' => 'provider-' . $task['task_id'],
            'user_id' => $users['platform-admin'],
            'department_id' => $departments['platform'],
            'api_key_id' => $apiKeys['media'],
            'provider_id' => $providers['native'],
            'model_id' => $models[$task['model']],
            'submit_payload' => jsonValue(['prompt' => 'Demo multimodal task', 'source' => 'demo-seed']),
            'result' => jsonValue(['preview' => true, 'quality' => 'demo']),
            'error_code' => null,
            'error_message' => null,
            'latency_ms' => 1200 + $index * 900,
            'finished_at' => $task['status'] === 'succeeded' ? ts($now, '-' . (4 + $index) . ' hours') : null,
            'created_at' => ts($now, '-' . (5 + $index) . ' hours'),
        ]);

        [$assetId, $mediaType, $path, $mime, $size, $duration, $width, $height] = $task['asset'];
        insertOnDuplicate($db, 'media_assets', [
            'usage_record_id' => $record['id'] ?? null,
            'asset_id' => $assetId,
            'task_id' => $taskDbId,
            'owner_user_id' => $users['platform-admin'],
            'department_id' => $departments['platform'],
            'api_key_id' => $apiKeys['media'],
            'access_scope' => 'department',
            'media_type' => $mediaType,
            'storage_path' => $path,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'duration_seconds' => $duration,
            'width' => $width,
            'height' => $height,
            'expires_at' => ts($now, '+14 days'),
            'created_at' => ts($now, '-' . (4 + $index) . ' hours'),
        ]);
    }
}

function seedSecurity(Database $db, array $users, DateTimeImmutable $now): void
{
    $policyId = upsertByKey($db, 'desensitization_policies', ['name' => 'demo-policy-pii-redaction'], [
        'name' => 'demo-policy-pii-redaction',
        'scope_type' => 'system',
        'scope_id' => null,
        'parsers' => jsonValue(['email', 'phone', 'id_card', 'api_key']),
        'action' => 'replace',
        'status' => 'active',
    ]);

    foreach ([
        ['demo-waf-sqli', 'global', ['path' => ['/v1/chat/completions'], 'keywords' => ['UNION SELECT', 'sleep(']], 'block', 10, '阻断疑似 SQL 注入提示词'],
        ['demo-waf-leakage', 'global', ['headers' => ['Authorization'], 'patterns' => ['sk-*', 'tkh_*']], 'redact', 20, '脱敏请求中的密钥片段'],
        ['demo-waf-internal-path', 'global', ['path_prefix' => ['/admin-api']], 'allow', 90, '允许后台受保护路径通过应用鉴权'],
    ] as $rule) {
        [$code, $scope, $matcher, $action, $priority, $description] = $rule;
        insertOnDuplicate($db, 'waf_rules', [
            'rule_code' => $code,
            'scope' => $scope,
            'matcher' => jsonValue($matcher),
            'action' => $action,
            'priority' => $priority,
            'status' => 'active',
            'description' => $description,
        ]);
    }

    insertOnDuplicate($db, 'waf_rule_versions', [
        'version_code' => 'waf-version-20260708',
        'rules_snapshot' => jsonValue(['rules' => ['demo-waf-sqli', 'demo-waf-leakage', 'demo-waf-internal-path']]),
        'caddyfile_fragment' => "# demo waf fragment\n@demoBlocked path /v1/*\n",
        'status' => 'published',
        'published_by' => $users['super'],
        'created_at' => ts($now, '-6 hours'),
    ]);

    foreach ([
        ['demo-waf-001', 'demo-waf-sqli', '198.51.100.12', 'POST', '/v1/chat/completions', 'block', ['reason' => 'sqli_keyword']],
        ['demo-waf-002', 'demo-waf-leakage', '198.51.100.21', 'POST', '/v1/messages', 'redact', ['fields' => ['Authorization']]],
        ['demo-waf-003', 'demo-waf-sqli', '203.0.113.8', 'POST', '/v1/images/generations', 'block', ['reason' => 'prompt_injection']],
    ] as $index => $event) {
        [$requestId, $ruleCode, $ip, $method, $path, $action, $summary] = $event;
        upsertByKey($db, 'waf_events', ['request_id' => $requestId], [
            'rule_code' => $ruleCode,
            'request_id' => $requestId,
            'client_ip' => $ip,
            'method' => $method,
            'path' => $path,
            'action' => $action,
            'summary' => jsonValue($summary),
            'created_at' => ts($now, '-' . (20 + $index * 17) . ' minutes'),
        ]);
    }

    foreach ([
        ['demo-desens-001', 'request_payload', 'replace', 5, ['types' => ['phone', 'email']]],
        ['demo-desens-002', 'provider_response', 'summary', 2, ['types' => ['id_card']]],
        ['demo-desens-003', 'usage_content', 'replace', 9, ['types' => ['api_key']]],
    ] as $index => $event) {
        [$requestId, $targetType, $action, $count, $summary] = $event;
        upsertByKey($db, 'desensitization_events', ['request_id' => $requestId], [
            'policy_id' => $policyId,
            'request_id' => $requestId,
            'target_type' => $targetType,
            'action' => $action,
            'match_count' => $count,
            'summary' => jsonValue($summary),
            'created_at' => ts($now, '-' . (12 + $index * 15) . ' minutes'),
        ]);
    }
}

function seedOperations(Database $db, array $users, array $departments, DateTimeImmutable $now): void
{
    foreach ([
        ['demo-export-usage-001', 'usage', 'succeeded', 'storage/exports/demo-usage-001.csv', null, '+7 days', '-50 minutes'],
        ['demo-export-logs-002', 'logs', 'processing', null, null, '+1 day', '-7 minutes'],
        ['demo-export-archive-003', 'session_archive', 'failed', null, 'OBJECT_STORAGE_UNAVAILABLE', '+1 day', '-2 hours'],
    ] as $export) {
        [$exportId, $type, $status, $filePath, $error, $expires, $created] = $export;
        insertOnDuplicate($db, 'export_tasks', [
            'export_id' => $exportId,
            'requested_by' => $users['super'],
            'export_type' => $type,
            'status' => $status,
            'format' => 'csv',
            'filters' => jsonValue(['demo' => true, 'department_id' => $departments['platform']]),
            'file_path' => $filePath,
            'error_code' => $error,
            'download_count' => $status === 'succeeded' ? 3 : 0,
            'expires_at' => ts($now, $expires),
            'finished_at' => $status === 'succeeded' ? ts($now, '-45 minutes') : null,
            'created_at' => ts($now, $created),
        ]);
    }

    foreach ([
        ['gateway-access', 'storage/logs/gateway-access.20260708.jsonl', 'storage/archive/gateway-access.20260708.jsonl.gz.enc', 1682400],
        ['audit', 'storage/logs/audit.20260708.jsonl', 'storage/archive/audit.20260708.jsonl.gz.enc', 842000],
        ['security', 'storage/logs/security.20260708.jsonl', 'storage/archive/security.20260708.jsonl.gz.enc', 522400],
    ] as $archive) {
        [$type, $source, $path, $size] = $archive;
        insertOnDuplicate($db, 'log_archive_files', [
            'log_type' => $type,
            'source_path' => $source,
            'archive_path' => $path,
            'compressed' => 1,
            'encrypted' => 1,
            'size_bytes' => $size,
            'created_at' => ts($now, '-1 day'),
            'deleted_at' => null,
        ]);
    }

    foreach ([
        ['demo-high-error-rate', 'critical', 'OpenAI 兼容通道 5 分钟失败率超过 5%', 'open'],
        ['demo-export-backlog', 'warning', '导出队列存在处理中的任务', 'acknowledged'],
        ['demo-latency-spike', 'warning', '多模态生成 p95 延迟高于阈值', 'resolved'],
    ] as $index => $alert) {
        [$eventType, $severity, $message, $status] = $alert;
        upsertByKey($db, 'alert_events', ['event_type' => $eventType, 'message' => $message], [
            'event_type' => $eventType,
            'severity' => $severity,
            'message' => $message,
            'metadata' => jsonValue(['demo' => true, 'runbook' => 'docs/deployment/production.md']),
            'status' => $status,
            'created_at' => ts($now, '-' . (16 + $index * 22) . ' minutes'),
        ]);
    }

    foreach ([
        ['demo-audit-001', $users['super'], 'admin.login', 'users', (string) $users['super'], '192.0.2.10'],
        ['demo-audit-002', $users['platform-admin'], 'api_key.create', 'api_keys', 'demo-key-platform', '192.0.2.11'],
        ['demo-audit-003', $users['ops-admin'], 'export.create', 'export_tasks', 'demo-export-usage-001', '192.0.2.12'],
        ['demo-audit-004', $users['super'], 'waf.publish', 'waf_rule_versions', 'waf-version-20260708', '192.0.2.10'],
    ] as $index => $audit) {
        [$requestId, $actor, $action, $targetType, $targetId, $ip] = $audit;
        upsertByKey($db, 'audit_logs', ['request_id' => $requestId], [
            'actor_user_id' => $actor,
            'actor_type' => 'admin',
            'actor_id' => $actor,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'trace_id' => 'demo-audit-trace-' . ($index + 1),
            'client_ip' => $ip,
            'ip_address' => $ip,
            'user_agent' => 'TokenHub Demo Browser',
            'details' => jsonValue(['demo' => true]),
            'metadata' => jsonValue(['demo' => true]),
            'created_at' => ts($now, '-' . (5 + $index * 30) . ' minutes'),
        ]);
    }
}

function seedScreenShares(Database $db, DateTimeImmutable $now): void
{
    $links = [
        ['code' => 'demo-share-global', 'name' => '全局运营大屏', 'username' => 'demo-screen-global', 'scope_type' => 'global', 'scope_ids' => [], 'metrics' => ['realtime', 'trends', 'rankings', 'security', 'performance', 'multimodal'], 'drilldown' => 1],
        ['code' => 'demo-share-platform', 'name' => '平台部门大屏', 'username' => 'demo-screen-platform', 'scope_type' => 'department', 'scope_ids' => [2], 'metrics' => ['realtime', 'trends', 'rankings', 'performance'], 'drilldown' => 0],
    ];

    foreach ($links as $link) {
        $id = upsertByKey($db, 'dashboard_share_links', ['share_code' => $link['code']], [
            'share_code' => $link['code'],
            'name' => $link['name'],
            'username' => $link['username'],
            'password_hash' => password_hash('DemoScreen@123', PASSWORD_DEFAULT),
            'scope_type' => $link['scope_type'],
            'scope_ids' => jsonValue($link['scope_ids']),
            'allowed_metrics' => jsonValue($link['metrics']),
            'allow_drilldown' => $link['drilldown'],
            'allowed_ip_cidrs' => jsonValue(['192.0.2.0/24', '198.51.100.0/24']),
            'expires_at' => ts($now, '+30 days'),
            'status' => 'active',
            'session_version' => 1,
            'created_at' => ts($now, '-3 days'),
        ]);

        foreach ([
            ['login_success', '192.0.2.44', null, '-9 minutes'],
            ['login_failed', '198.51.100.22', 'bad_password', '-31 minutes'],
            ['ip_denied', '203.0.113.99', 'cidr_not_allowed', '-42 minutes'],
            ['session_expired', '192.0.2.45', 'expired', '-2 hours'],
        ] as $event) {
            [$type, $ip, $reason, $time] = $event;
            upsertByKey($db, 'dashboard_share_access_logs', [
                'share_link_id' => $id,
                'event_type' => $type,
                'client_ip' => $ip,
                'reason' => $reason ?? '',
            ], [
                'share_link_id' => $id,
                'event_type' => $type,
                'client_ip' => $ip,
                'user_agent' => 'TokenHub Demo Screen',
                'reason' => $reason ?? '',
                'created_at' => ts($now, $time),
            ]);
        }
    }
}

function seedSystemSettings(Database $db, DateTimeImmutable $now): void
{
    insertOnDuplicate($db, 'system_settings', [
        'setting_key' => 'content_retention.policy',
        'setting_value' => jsonValue([
            'mode' => 'summary',
            'default_days' => 30,
            'max_days' => 90,
            'raw_allowed_roles' => ['super_admin'],
            'departments' => ['2' => ['mode' => 'redacted', 'days' => 45]],
            'users' => [],
            'api_keys' => [],
            'demo_marker' => 'demo-content-retention',
        ]),
        'description' => 'Demo content retention policy for admin review.',
        'updated_at' => ts($now),
    ]);

    insertOnDuplicate($db, 'system_settings', [
        'setting_key' => 'demo-content-retention.seed_marker',
        'setting_value' => jsonValue(['seeded_at' => ts($now), 'source' => 'scripts/seed-demo.php']),
        'description' => 'Marker showing demo data has been seeded.',
        'updated_at' => ts($now),
    ]);
}

function countRowsLike(Database $db, string $table, string $column, string $value): int
{
    $operator = str_contains($value, '%') ? 'LIKE' : '=';
    $row = $db->first("SELECT COUNT(*) AS count_value FROM {$table} WHERE {$column} {$operator} ?", [$value]);
    return (int) ($row['count_value'] ?? 0);
}
