<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * The admin console lists only models the gateway can serve. ModelSupportPolicy
 * defines "supported" twice (PHP for annotations, SQL for paginated lists):
 * both must agree. Also covers the model list filter, provider catalog import
 * and catalog-sync "added" list.
 */

use TokenHub\Services\CrudService;
use TokenHub\Services\ModelSupportPolicy;
use TokenHub\Services\ProviderModelSyncService;
use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('proc_open')) {
    echo "SKIP sqlite or proc_open unavailable\n";
    return;
}
$_ENV['OUTBOUND_PRIVATE_ALLOWLIST'] = '127.0.0.1/32';
$_ENV['APP_KEY'] = 'model-support-test-key-0123456789abcdef';
(new ReflectionProperty(App::class, 'config'))->setValue(null, \TokenHub\Support\Config::default());
try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

final class ModelSupportTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(['INSERT IGNORE', 'UTC_TIMESTAMP()'], ['INSERT OR IGNORE', "datetime('now')"], $query);
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        }
        if (str_contains($query, 'FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?')) {
            $query = 'SELECT 1 AS present FROM pragma_table_info(?) WHERE name = ? LIMIT 1';
        }
        return parent::prepare($query, $options);
    }
}

$pdo = new ModelSupportTestPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE providers (id INTEGER PRIMARY KEY, name TEXT, protocol TEXT, base_url TEXT, encrypted_secret TEXT, default_headers TEXT, timeout_ms INT, retry_count INT, status TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE models (id INTEGER PRIMARY KEY, provider_id INT, name TEXT, external_name TEXT, capability TEXT, context_length INT, status TEXT, route_priority INT, created_at TEXT)');
// Same columns as the real table: AuditService caches the column list per process.
$pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)');
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);
(new ReflectionProperty(App::class, 'context'))->setValue(null, RequestContext::fromHeaders([]));

$providers = [
    1 => ['OpenAI', 'openai', 'https://api.openai.com/v1', null],
    2 => ['Claude', 'anthropic', 'https://api.anthropic.com', null],
    3 => ['Native', 'native', 'https://native.example.org', null],
    4 => ['Ark', 'openai', 'https://ark.cn-beijing.volces.com/api/v3', null],
    5 => ['Proxy with Ark adapter', 'openai', 'https://gw.internal.example', '{"tokenhub_adapter": "volcengine_ark"}'],
    6 => ['Gemini', 'gemini', 'https://generativelanguage.googleapis.com', null],
    8 => ['Kling', 'openai', 'https://api-beijing.klingai.com', null],
    9 => ['ElevenLabs', 'openai', 'https://api.elevenlabs.io', null],
];
foreach ($providers as $id => [$name, $protocol, $base, $headers]) {
    $pdo->prepare("INSERT INTO providers (id, name, protocol, base_url, default_headers, status, timeout_ms, retry_count) VALUES (?, ?, ?, ?, ?, 'active', 2000, 1)")->execute([$id, $name, $protocol, $base, $headers]);
}
// [id, provider, name, external, capability, expected reason]
$cases = [
    [10, 1, 'gpt-4o', 'gpt-4o', 'text_generation', null],
    [11, 1, 'embed', 'text-embedding-3-small', 'embedding', null],
    [12, 1, 'rerank', 'bge-reranker', 'rerank', null],
    [13, 1, 'tts', 'tts-1', 'tts', null],
    [14, 1, 'sora', 'sora-2', 'video_generation', 'TASK_ADAPTER_MISSING'],
    [15, 1, 'gpt-4o-realtime', 'gpt-4o-realtime-preview', 'text_generation', 'REALTIME_MISCONFIGURED'],
    [16, 1, 'voice', 'gpt-realtime', 'realtime', null],
    [17, 1, 'odd', 'odd', 'image_variation', 'CAPABILITY_UNSUPPORTED'],
    [18, 1, 'song', 'some-music', 'music_generation', 'TASK_ADAPTER_MISSING'],
    [20, 2, 'claude', 'claude-sonnet', 'vision_understanding', null],
    [21, 2, 'claude-embed', 'voyage', 'embedding', 'PROTOCOL_CAPABILITY_MISMATCH'],
    [22, 2, 'claude-live', 'claude-live', 'realtime', 'PROTOCOL_CAPABILITY_MISMATCH'],
    [30, 3, 'native-tts', 'native-tts', 'tts', 'NATIVE_PROTOCOL'],
    [40, 4, 'video', 'doubao-video', 'video_generation', null],
    [41, 4, 'chat', 'doubao-pro', 'text_generation', null],
    [50, 5, 'video', 'my-video', 'video_generation', null],
    [51, 1, 'seedance-1-pro', 'seedance-1-pro', 'video_generation', null],
    [60, 99, 'orphan', 'orphan', 'text_generation', 'PROVIDER_MISSING'],
    [70, 6, 'gemini', 'gemini-2.5-pro', 'text_generation', null],
    [71, 6, 'gemini-live', 'gemini-live-2.5-flash', 'realtime', null],
    [72, 6, 'imagen', 'imagen-4', 'image_generation', 'PROTOCOL_CAPABILITY_MISMATCH'],
    [80, 8, 'kling', 'kling-v2-master', 'video_generation', null],
    [90, 9, 'eleven-music', 'music_v1', 'music_generation', null],
    [91, 9, 'eleven-tts', 'eleven_multilingual_v2', 'tts', null],
];
foreach ($cases as [$id, $providerId, $name, $external, $capability]) {
    $pdo->prepare("INSERT INTO models (id, provider_id, name, external_name, capability, status, route_priority) VALUES (?, ?, ?, ?, ?, 'active', 100)")->execute([$id, $providerId, $name, $external, $capability]);
}

$policy = new ModelSupportPolicy();
$expectedSupported = [];
foreach ($cases as [$id, $providerId, $name, $external, $capability, $reason]) {
    $provider = $pdo->query('SELECT * FROM providers WHERE id = ' . $providerId)->fetch() ?: null;
    $result = $policy->evaluate(['name' => $name, 'external_name' => $external, 'capability' => $capability], $provider);
    assert_same($reason, $result['reason'], "Model {$id} ({$external}) reason.");
    assert_same($reason === null, $result['supported'], "Model {$id} supported flag.");
    if ($reason !== null) {
        assert_true(is_string($result['message']) && $result['message'] !== '', "Model {$id} has a Chinese explanation.");
    } else {
        $expectedSupported[] = $id;
    }
}
ModelSupportPolicy::forget();
assert_same($expectedSupported, $policy->modelIds(true), 'The id lists used for paging agree with evaluate().');
assert_true(ModelSupportPolicy::isVolcengineArkRoute(['base_url' => 'https://ark.cn-beijing.volces.com/api/v3']), 'Ark base URLs use the Ark adapter (shared with GatewayService).');

// Admin model list: supported only by default; support=all / unsupported on request.
$crud = new CrudService();
$listed = array_map(static fn (array $row): int => (int) $row['id'], $crud->list('models', ['limit' => 100, 'sort' => 'id_asc']));
sort($listed);
assert_same($expectedSupported, $listed, 'The model list shows only supported models by default.');
assert_same(count($expectedSupported), $crud->count('models', []), 'The total matches the filtered list.');
assert_same(count($cases), $crud->count('models', ['support' => 'all']), 'support=all lists every model.');
$unsupported = $crud->list('models', ['support' => 'unsupported', 'limit' => 100]);
assert_same(count($cases) - count($expectedSupported), count($unsupported), 'support=unsupported lists the rest.');
foreach ($unsupported as $row) {
    assert_same(false, $row['gateway_supported'], 'Unsupported rows are flagged.');
    assert_true(is_string($row['unsupported_reason']) && is_string($row['unsupported_message']), 'Unsupported rows say why.');
    assert_true(!array_key_exists('default_headers', $row), 'Provider headers never leak into model rows.');
}
assert_same(true, $crud->list('models', ['limit' => 1])[0]['gateway_supported'], 'Supported rows are flagged too.');
try {
    $crud->list('models', ['support' => 'maybe']);
    throw new RuntimeException('Invalid support filter should be rejected.');
} catch (HttpAbortException $exception) {
    assert_same('ADMIN_INVALID_PAYLOAD', $exception->errorCode, 'Invalid support filter is rejected.');
}

// Provider catalog: only importable models are offered, the rest are counted.
$port = random_int(50001, 60000);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/upstream/server.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static fn () => proc_terminate($server));
for ($i = 0; $i < 100 && @file_get_contents('http://127.0.0.1:' . $port . '/catalog/v1/models') === false; $i++) {
    usleep(50000);
}
$pdo->exec("INSERT INTO providers (id, name, protocol, base_url, status, timeout_ms, retry_count) VALUES (7, 'Catalog', 'openai', 'http://127.0.0.1:{$port}/catalog', 'active', 2000, 1)");
ModelSupportPolicy::forget();
$sync = new ProviderModelSyncService();
$catalog = $sync->fetchSupportedModels(7);
$offered = array_column($catalog['models'], 'external_name');
sort($offered);
assert_same(['bge-reranker-v2', 'gpt-4o-mini', 'gpt-4o-realtime-preview', 'text-embedding-3-small', 'whisper-1'], $offered, 'Video and music models without a task adapter are not offered for import.');
assert_same(2, $catalog['hidden_count'], 'Hidden models are counted.');
assert_same(['sora-2', 'suno-v4'], $catalog['hidden_reasons']['TASK_ADAPTER_MISSING']['examples'] ?? null, 'Hidden models are grouped by reason with examples.');

$imported = $sync->importModels(7, ['gpt-4o-mini', 'sora-2']);
assert_same([1, 1], [$imported['created'], $imported['skipped']], 'Unsupported models are skipped even if a client sends them.');
assert_same(null, $pdo->query("SELECT id FROM models WHERE provider_id = 7 AND external_name = 'sora-2'")->fetch() ?: null, 'The skipped model is not stored.');

$preview = $sync->syncProvider(7, false);
$added = array_column($preview['added'], 'external_name');
sort($added);
assert_same(['bge-reranker-v2', 'gpt-4o-realtime-preview', 'text-embedding-3-small', 'whisper-1'], $added, 'Catalog sync only proposes supported new models.');
assert_same(2, $preview['hidden_unsupported'], 'Catalog sync reports how many new upstream models were hidden.');

echo "OK\n";
