<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * End-to-end contract for the vendor adapters added for popular models
 * (MM-001, MM-003, MM-010..MM-015, MM-021): every scenario goes through
 * GatewayService against the fake vendor upstream
 * (tests/fixtures/upstream/vendors.php) and asserts both what the client
 * receives and what the vendor received. Storage is emulated by SQLite like
 * MultimodalGatewayFlowTest; Redis must be reachable.
 */

use TokenHub\Services\GatewayService;
use TokenHub\Services\TaskCallbackService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Database;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('proc_open')) {
    echo "SKIP sqlite or proc_open unavailable\n";
    return;
}
$queue = 'tokenhub:vendortest:' . bin2hex(random_bytes(4));
$_ENV['QUEUE_NAME'] = $queue;
$_ENV['OUTBOUND_PRIVATE_ALLOWLIST'] = '127.0.0.1/32';
$_ENV['PROVIDER_RETRY_MAX_BACKOFF_MS'] = '1';
$_ENV['APP_KEY'] = 'model-support-flow-test-key-0123456789abcdef';
$_ENV['APP_URL'] = 'https://tokenhub.test';
$mediaRoot = sys_get_temp_dir() . '/tokenhub-media-' . bin2hex(random_bytes(4));
$_ENV['MEDIA_STORAGE_PATH'] = $mediaRoot;
$appConfig = new ReflectionProperty(App::class, 'config');
$appDatabase = new ReflectionProperty(App::class, 'database');
$originalConfig = $appConfig->getValue();
$originalDatabase = $appDatabase->getValue();
$appConfig->setValue(null, \TokenHub\Support\Config::default());
try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

$port = random_int(40001, 50000);
@unlink(sys_get_temp_dir() . '/tokenhub-fake-vendors-' . $port . '.json');
@unlink(sys_get_temp_dir() . '/tokenhub-fake-vendors-expired-' . $port);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/upstream/vendors.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static fn () => proc_terminate($server));
$base = 'http://127.0.0.1:' . $port;
for ($i = 0; $i < 100; $i++) {
    if (@file_get_contents($base . '/files/ping.png', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]])) !== false) {
        break;
    }
    usleep(50000);
}
$sent = static function (string $key) use ($port): ?array {
    $all = json_decode((string) @file_get_contents(sys_get_temp_dir() . '/tokenhub-fake-vendors-' . $port . '.json'), true) ?: [];
    return $all[$key] ?? null;
};

final class VendorSqlitePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/i', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('" . $m[1] . "')";
        }

        return parent::prepare($query, $options);
    }
}
$pdo = new VendorSqlitePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('FIELD', static function (...$args): int {
    $needle = array_shift($args);
    $index = array_search($needle, $args, true);
    return $index === false ? 0 : $index + 1;
});
$pdo->sqliteCreateFunction('JSON_CONTAINS', static fn ($haystack, $needle): int => in_array(json_decode((string) $needle, true), json_decode((string) $haystack, true) ?: [], true) ? 1 : 0);
$pdo->sqliteCreateFunction('JSON_ARRAY', static fn (): string => '[]');
$pdo->sqliteCreateFunction('JSON_QUOTE', static fn ($value): string => (string) json_encode((string) $value));
$pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE providers (id INTEGER PRIMARY KEY, name TEXT, protocol TEXT, base_url TEXT, encrypted_secret TEXT, status TEXT, timeout_ms INT, retry_count INT, default_headers TEXT)');
$pdo->exec('CREATE TABLE models (id INTEGER PRIMARY KEY, provider_id INT, name TEXT, external_name TEXT, capability TEXT, capability_tags TEXT, capabilities TEXT, context_length INT, status TEXT, route_priority INT)');
$pdo->exec('CREATE TABLE quota_policies (id INTEGER PRIMARY KEY, name TEXT, period TEXT, status TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, audio_second_limit INT, tts_char_limit INT, image_limit INT, video_second_limit INT, cost_limit REAL, concurrency_limit INT)');
$pdo->exec('CREATE TABLE quota_bindings (id INTEGER PRIMARY KEY, quota_policy_id INT, target_type TEXT, target_id INT)');
$pdo->exec('CREATE TABLE api_key_model_quotas (id INTEGER PRIMARY KEY, api_key_id INT, model_id INT, period TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, status TEXT)');
$pdo->exec('CREATE TABLE desensitization_policies (id INTEGER PRIMARY KEY, status TEXT, scope_type TEXT, scope_id INT, action TEXT, parsers TEXT)');
$pdo->exec('CREATE TABLE multimodal_tasks (id INTEGER PRIMARY KEY, task_id TEXT UNIQUE, task_type TEXT, status TEXT, progress INT, provider_task_id TEXT, user_id INT, department_id INT, api_key_id INT, provider_id INT, model_id INT, submit_payload TEXT, result TEXT, error_code TEXT, error_message TEXT, latency_ms INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, finished_at TEXT)');
$pdo->exec('CREATE TABLE media_assets (id INTEGER PRIMARY KEY, asset_id TEXT UNIQUE, task_id INT, owner_user_id INT, department_id INT, api_key_id INT, access_scope TEXT, media_type TEXT, storage_path TEXT, mime_type TEXT, size_bytes INT, duration_seconds INT, width INT, height INT, expires_at TEXT, deleted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT)');
$pdo->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, status TEXT, expires_at TEXT)');
$pdo->exec('INSERT INTO users (id, status) VALUES (1, "active")');
$pdo->exec('INSERT INTO api_keys (id, user_id, status) VALUES (7201, 1, "active")');

$secret = static fn (string $value): string => Crypto::encrypt($value);
$insertProvider = $pdo->prepare('INSERT INTO providers (id, name, protocol, base_url, encrypted_secret, status, timeout_ms, retry_count, default_headers) VALUES (?, ?, ?, ?, ?, "active", 5000, 1, ?)');
$insertProvider->execute([1, 'Kling', 'openai', $base, $secret('ak-test:sk-test'), '{"tokenhub_adapter":"kling"}']);
$insertProvider->execute([2, 'MiniMax', 'openai', $base, $secret('mm-key'), '{"tokenhub_adapter":"minimax"}']);
$insertProvider->execute([3, 'Bailian', 'openai', $base, $secret('ds-key'), '{"tokenhub_adapter":"dashscope"}']);
$insertProvider->execute([4, 'Vidu', 'openai', $base, $secret('vidu-key'), '{"tokenhub_adapter":"vidu"}']);
$insertProvider->execute([5, 'BFL', 'openai', $base, $secret('bfl-key'), '{"tokenhub_adapter":"bfl"}']);
$insertProvider->execute([6, 'fal', 'openai', $base, $secret('fal-key'), '{"tokenhub_adapter":"fal"}']);
$insertProvider->execute([7, 'OpenRouter', 'openai', $base . '/api/v1', $secret('sk-or-test'), '{"tokenhub_adapter":"openrouter"}']);
$insertProvider->execute([8, 'xAI', 'openai', $base . '/v1', $secret('xai-key'), '{"tokenhub_adapter":"xai"}']);
$insertProvider->execute([9, 'ElevenLabs', 'openai', $base, $secret('el-key'), '{"tokenhub_adapter":"elevenlabs"}']);
$insertProvider->execute([10, 'Kimi Code', 'openai', $base . '/coding/v1', $secret('kimi-key'), null]);
$insertProvider->execute([11, 'Gemini', 'gemini', $base, $secret('g-key'), null]);
$insertProvider->execute([12, 'OpenAI Realtime', 'openai', $base . '/v1', $secret('sk-rt'), null]);
$insertModel = $pdo->prepare('INSERT INTO models (id, provider_id, name, external_name, capability, capability_tags, status, route_priority) VALUES (?, ?, ?, ?, ?, NULL, "active", 1)');
$models = [
    [101, 1, 'kling-video', 'kling-4.0', 'video_generation'],
    [102, 2, 'hailuo', 'MiniMax-H3', 'video_generation'],
    [103, 2, 'mm-image', 'image-01', 'image_generation'],
    [104, 2, 'mm-tts', 'speech-2.8-hd', 'tts'],
    [105, 3, 'wan-video', 'wan3.0-video', 'video_generation'],
    [106, 3, 'cosyvoice', 'cosyvoice-v3.5-flash', 'tts'],
    [107, 3, 'qwen-asr', 'qwen3-asr-flash', 'asr'],
    [108, 4, 'vidu', 'viduq3-pro', 'video_generation'],
    [109, 5, 'flux3', 'flux-3-image', 'image_generation'],
    [110, 6, 'fal-kling', 'fal-ai/kling-video/v2/master/text-to-video', 'video_generation'],
    [111, 7, 'or-veo', 'google/veo-3.1', 'video_generation'],
    [112, 8, 'grok-video', 'grok-imagine-video-1.5', 'video_generation'],
    [113, 9, 'eleven', 'eleven_v4', 'tts'],
    [114, 10, 'k3.1-pro', 'kimi-for-coding', 'text_generation'],
    [115, 11, 'gemini-3.8-flash', 'gemini-3.8-flash', 'vision_understanding'],
    [116, 12, 'gpt-realtime', 'gpt-realtime-2.1', 'realtime'],
];
foreach ($models as $model) {
    $insertModel->execute($model);
    App::redis()->delete('route_health:' . $model[0]);
}
$allModels = array_column($models, 0);
\TokenHub\Support\ConfigVersion::bump('global', 'model-support-flow-test');
\TokenHub\Support\ConfigVersion::refresh();
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);

$useContext = static function () use ($allModels): RequestContext {
    $context = RequestContext::fromHeaders([]);
    (new ReflectionProperty(App::class, 'context'))->setValue(null, $context);
    $context->setApiKey(['id' => 7201, 'user_id' => 1, 'department_id' => 1, 'allowed_model_ids' => $allModels, 'allowed_model_names' => []]);
    return $context;
};
$capture = static function (\TokenHub\Support\Response $response): string {
    ob_start();
    ob_start();
    $response->send();
    $inner = (string) ob_get_clean();
    return (string) ob_get_clean() . $inner;
};
$recordFor = static function (string $requestId) use ($queue): ?array {
    foreach (App::redis()->connection()->lRange($queue, 0, -1) ?: [] as $raw) {
        $job = json_decode((string) $raw, true);
        if (($job['record']['request_id'] ?? null) === $requestId) {
            return $job['record'];
        }
    }
    return null;
};
$call = static function (string $path, array $body, array $server = []) use ($useContext, $capture, $recordFor): array {
    $context = $useContext();
    $request = new Request('POST', $path, ['REMOTE_ADDR' => '127.0.0.1', ...$server], [], $body, (string) json_encode($body), $context);
    $response = (new GatewayService())->handle($request);
    $output = $capture($response);

    return ['status' => $response->status, 'headers' => (new ReflectionProperty($response, 'headers'))->getValue($response), 'body' => $output, 'json' => json_decode($output, true), 'record' => $recordFor($context->requestId())];
};
$refresh = static function (string $taskId) use ($useContext): array {
    $useContext();
    return (new GatewayService())->refreshTask($taskId);
};
$assetPath = static fn (string $assetId): string => (string) (App::db()->first('SELECT storage_path FROM media_assets WHERE asset_id = ?', [$assetId])['storage_path'] ?? '');

// ---------------------------------------------------------------- MM-001 Kimi Code via Claude Code
$kimi = $call('/v1/messages', ['model' => 'k3.1-pro', 'max_tokens' => 512, 'tools' => [['name' => 'Read', 'input_schema' => ['type' => 'object']]], 'messages' => [['role' => 'user', 'content' => 'read a.txt']]]);
assert_same(200, $kimi['status'], 'Claude Code request to Kimi Code is served. Got ' . substr($kimi['body'], 0, 300));
assert_same('tool_use', $kimi['json']['stop_reason'] ?? null, 'Kimi tool calls come back as stop_reason tool_use.');
assert_same('tool_use', $kimi['json']['content'][1]['type'] ?? null, 'Kimi tool calls come back as tool_use blocks.');
assert_same(['path' => 'a.txt'], $kimi['json']['content'][1]['input'] ?? null, 'Tool arguments are decoded.');
assert_same('Read', $sent('kimi')['body']['tools'][0]['function']['name'] ?? null, 'Kimi receives the tool definitions.');
assert_same(40, (int) ($kimi['record']['input_tokens'] ?? 0), 'Billing reads the upstream prompt tokens (cache included).');
assert_same(30, (int) ($kimi['record']['cache_read_tokens'] ?? 0), 'Cache reads are recorded.');

// ---------------------------------------------------------------- MM-012 Kling 4.0 (JWT auth, task, asset copy)
$kling = $call('/v1/videos/generations', ['model' => 'kling-video', 'prompt' => 'a cat', 'duration' => 5, 'resolution' => '1080p', 'aspect_ratio' => '16:9', 'image' => 'data:image/png;base64,QUJD']);
assert_same(202, $kling['status'], 'Kling task is accepted. Got ' . substr($kling['body'], 0, 300));
$klingSent = $sent('kling');
assert_true(str_starts_with((string) ($klingSent['headers']['authorization'] ?? ''), 'Bearer ey'), 'AK:SK secret is turned into a JWT bearer token.');
assert_same(['type' => 'prompt', 'text' => 'a cat'], $klingSent['body']['contents'][0] ?? null, 'Prompt becomes a prompt content.');
assert_same('QUJD', $klingSent['body']['contents'][1]['url'] ?? null, 'Kling receives bare base64 for the first frame.');
assert_same('1080p', $klingSent['body']['settings']['resolution'] ?? null, 'Resolution goes to settings.');
assert_true(str_starts_with((string) ($klingSent['body']['options']['callback_url'] ?? ''), 'https://tokenhub.test/v1/task-callbacks/'), 'A signed TokenHub callback URL is sent.');
$klingTask = $refresh((string) $kling['json']['task_id']);
assert_same('succeeded', $klingTask['status'] ?? null, 'Kling poll maps succeeded.');
$klingAsset = (string) ($klingTask['assets'][0]['asset_id'] ?? '');
assert_true(is_file($assetPath($klingAsset)), 'The Kling video is copied into TokenHub media storage (MM-003).');

// vendor_options adds vendor fields but cannot change what is billed.
$klingOpts = $call('/v1/videos/generations', ['model' => 'kling-video', 'prompt' => 'a dog', 'duration' => 5, 'vendor_options' => ['model_name' => 'kling-cheap', 'settings' => ['duration' => 99, 'camera' => 'pan'], 'callback_url' => 'https://evil.example/cb', 'watermark' => false]]);
assert_same(202, $klingOpts['status'], 'Kling task with vendor_options is accepted. Got ' . substr($klingOpts['body'], 0, 300));
$klingOptsSent = $sent('kling');
assert_same(5, $klingOptsSent['body']['settings']['duration'] ?? null, 'vendor_options cannot override the billed duration.');
assert_same('pan', $klingOptsSent['body']['settings']['camera'] ?? null, 'Non-billing vendor fields are merged.');
assert_same(false, $klingOptsSent['body']['watermark'] ?? null, 'Top-level vendor fields are merged.');
assert_true(!isset($klingOptsSent['body']['model_name']), 'vendor_options cannot set the upstream model.');
assert_true(!str_contains((string) json_encode($klingOptsSent['body']), 'evil.example'), 'vendor_options cannot redirect the callback.');

// Without a duration the vendor default is both sent and billed.
$klingDefault = $call('/v1/videos/generations', ['model' => 'kling-video', 'prompt' => 'a bird']);
assert_same(202, $klingDefault['status'], 'Kling task without duration is accepted.');
assert_same(5, $sent('kling')['body']['settings']['duration'] ?? null, 'Kling default duration (5 s) is sent explicitly.');
assert_same(5, (int) ($klingDefault['record']['video_seconds'] ?? 0), 'The billed duration is the default that was sent. Record: ' . json_encode($klingDefault['record']));
assert_true(str_contains((string) file_get_contents($assetPath($klingAsset)), 'ftypmp42'), 'Stored bytes are the video.');

// ---------------------------------------------------------------- MM-014 callbacks
$callbacks = new TaskCallbackService();
$callbackTask = (string) $kling['json']['task_id'];
assert_same(404, $callbacks->handle($callbackTask, 'forged', [])['status'], 'A forged callback signature is rejected.');
assert_same(['challenge' => 'abc'], $callbacks->handle($callbackTask, $callbacks->signature($callbackTask), ['challenge' => 'abc'])['body'], 'MiniMax-style challenge is echoed.');
$again = $callbacks->handle($callbackTask, $callbacks->signature($callbackTask), ['status' => 'succeeded']);
assert_same(200, $again['status'], 'A repeated callback for a finished task is accepted.');
assert_same(false, $again['body']['refreshed'] ?? null, 'A finished task is not refreshed again.');
$ark = new \TokenHub\Services\Providers\VolcengineArkAdapter();
assert_true($callbacks->urlFor($ark, 'tsk_ark', 'video_generation') !== null, 'Ark video tasks get a callback URL.');
assert_same(null, $callbacks->urlFor($ark, 'tsk_ark', 'image_generation'), 'Ark image requests (OpenAI-compatible API) never carry a callback URL.');

// ---------------------------------------------------------------- MiniMax video failure surfaces
$hailuo = $call('/v1/videos/generations', ['model' => 'hailuo', 'prompt' => 'waves', 'duration' => 6, 'resolution' => '1080p', 'image' => 'https://img.example/a.png']);
assert_same(202, $hailuo['status'], 'MiniMax task is accepted. Got ' . substr($hailuo['body'], 0, 300));
assert_same('2K', $sent('minimax_video')['body']['resolution'] ?? null, 'MiniMax resolution vocabulary is used.');
assert_same('first_frame', $sent('minimax_video')['body']['content'][1]['role'] ?? null, 'First frame role is sent.');
$hailuoTask = $refresh((string) $hailuo['json']['task_id']);
assert_same('failed', $hailuoTask['status'] ?? null, 'A failed MiniMax task is marked failed.');

// ---------------------------------------------------------------- Wan (DashScope async header)
$wan = $call('/v1/videos/generations', ['model' => 'wan-video', 'prompt' => 'city', 'duration' => 5, 'resolution' => '1080p', 'generate_audio' => true]);
assert_same(202, $wan['status'], 'Wan task is accepted (X-DashScope-Async sent). Got ' . substr($wan['body'], 0, 300));
assert_same('1080P', $sent('wan')['body']['parameters']['resolution'] ?? null, 'Wan resolution is upper-case.');
assert_same(true, $sent('wan')['body']['parameters']['audio'] ?? null, 'Audio flag is sent.');
$wanTask = $refresh((string) $wan['json']['task_id']);
assert_same('succeeded', $wanTask['status'] ?? null, 'Wan poll maps SUCCEEDED.');

// ---------------------------------------------------------------- Vidu (Token auth scheme)
$vidu = $call('/v1/videos/generations', ['model' => 'vidu', 'prompt' => 'go', 'image' => 'https://img.example/a.png', 'duration' => 4]);
assert_same(202, $vidu['status'], 'Vidu task is accepted with Authorization: Token. Got ' . substr($vidu['body'], 0, 300));
assert_same(['https://img.example/a.png'], $sent('vidu')['body']['images'] ?? null, 'Vidu img2video receives images[].');
$viduTask = $refresh((string) $vidu['json']['task_id']);
assert_same('failed', $viduTask['status'] ?? null, 'A "success" poll with no output is a failure, not a billed success.');
assert_same('PROVIDER_TASK_EMPTY', $viduTask['error']['code'] ?? $viduTask['error_code'] ?? null, 'The empty result has its own error code.');

// ---------------------------------------------------------------- fal (two-step result)
$fal = $call('/v1/videos/generations', ['model' => 'fal-kling', 'prompt' => 'robot', 'duration' => 5, 'image' => 'https://img.example/b.png']);
assert_same(202, $fal['status'], 'fal request is queued. Got ' . substr($fal['body'], 0, 300));
assert_same('https://img.example/b.png', $sent('fal')['body']['image_url'] ?? null, 'fal input uses image_url.');
assert_true(str_starts_with((string) ($sent('fal')['headers']['authorization'] ?? ''), 'Key '), 'fal uses Authorization: Key.');
$falTask = $refresh((string) $fal['json']['task_id']);
assert_same('succeeded', $falTask['status'] ?? null, 'fal result is fetched after COMPLETED.');
assert_same(1, count($falTask['assets'] ?? []), 'fal video becomes an asset.');

// ---------------------------------------------------------------- OpenRouter video (authenticated content download, cost)
$or = $call('/v1/videos/generations', ['model' => 'or-veo', 'prompt' => 'sunrise', 'duration' => 8, 'resolution' => '720p', 'image' => 'https://img.example/c.png']);
assert_same(202, $or['status'], 'OpenRouter video job is accepted. Got ' . substr($or['body'], 0, 300));
assert_same('first_frame', $sent('openrouter_video')['body']['frame_images'][0]['frame_type'] ?? null, 'OpenRouter frame_images carry the first frame.');
$orTask = $refresh((string) $or['json']['task_id']);
assert_same('succeeded', $orTask['status'] ?? null, 'OpenRouter job completes.');
$orAsset = (string) ($orTask['assets'][0]['asset_id'] ?? '');
assert_true(str_contains((string) @file_get_contents($assetPath($orAsset)), 'ftypmp42'), 'The content endpoint is downloaded with the channel credential.');
$orRow = App::db()->first('SELECT result FROM multimodal_tasks WHERE task_id = ?', [(string) $or['json']['task_id']]);
assert_same('0.42000000', json_decode((string) $orRow['result'], true)['settled_usage']['upstream_cost'] ?? null, 'Upstream cost reported at completion is kept on the task.');

// ---------------------------------------------------------------- xAI video (cost ticks)
$xai = $call('/v1/videos/generations', ['model' => 'grok-video', 'prompt' => 'rain', 'duration' => 6, 'aspect_ratio' => '9:16']);
assert_same(202, $xai['status'], 'xAI video is accepted. Got ' . substr($xai['body'], 0, 300));
$xaiTask = $refresh((string) $xai['json']['task_id']);
$xaiRow = App::db()->first('SELECT result FROM multimodal_tasks WHERE task_id = ?', [(string) $xai['json']['task_id']]);
assert_same('succeeded', $xaiTask['status'] ?? null, 'xAI "done" maps to succeeded.');
assert_same('0.12000000', json_decode((string) $xaiRow['result'], true)['settled_usage']['upstream_cost'] ?? null, 'cost_in_usd_ticks is converted to USD.');

// ---------------------------------------------------------------- MM-013 BFL FLUX 3 (x-key, async image)
$flux = $call('/v1/images/generations', ['model' => 'flux3', 'prompt' => 'a red apple', 'aspect_ratio' => '1:1', 'size' => '1024x1024']);
assert_same(202, $flux['status'], 'FLUX 3 task is accepted. Got ' . substr($flux['body'], 0, 300));
assert_same(1024, $sent('bfl')['body']['width'] ?? null, 'size becomes width/height.');
assert_true(!isset($sent('bfl')['body']['model']), 'BFL body carries no unknown fields (model is in the path).');
$fluxTask = $refresh((string) $flux['json']['task_id']);
assert_same('succeeded', $fluxTask['status'] ?? null, 'FLUX "Ready" maps to succeeded.');
// Upstream URLs expire after 10 minutes: the stored copy must survive that.
touch(sys_get_temp_dir() . '/tokenhub-fake-vendors-expired-' . $port);
$fluxAsset = (string) ($fluxTask['assets'][0]['asset_id'] ?? '');
assert_true(is_file($assetPath($fluxAsset)), 'The FLUX image was copied before the upstream URL expired.');
@unlink(sys_get_temp_dir() . '/tokenhub-fake-vendors-expired-' . $port);

// ---------------------------------------------------------------- MM-021 sync vendors
$mmImage = $call('/v1/images/generations', ['model' => 'mm-image', 'prompt' => 'tea', 'n' => 1, 'aspect_ratio' => '1:1']);
assert_same(200, $mmImage['status'], 'MiniMax image is served synchronously. Got ' . substr($mmImage['body'], 0, 300));
// "__files" is internal (multipart uploads); a JSON client cannot inject it.
$injected = $call('/v1/images/generations', ['model' => 'mm-image', 'prompt' => 'tea', 'n' => 1, '__files' => ['image' => ['tmp_name' => '/etc/passwd', 'name' => 'x.png', 'type' => 'image/png']]]);
assert_same(200, $injected['status'], 'A JSON body with __files is served. Got ' . substr($injected['body'], 0, 300));
assert_true(!str_contains((string) json_encode($sent('minimax_image')), 'passwd'), 'Injected __files never reach the upstream.');
assert_true(str_starts_with((string) ($mmImage['json']['data'][0]['url'] ?? ''), 'https://tokenhub.test/download/'), 'MiniMax image_urls become OpenAI data[].url download links.');

$mmTts = $call('/v1/audio/speech', ['model' => 'mm-tts', 'input' => '你好，欢迎使用', 'voice' => 'female-shaonv']);
assert_same(200, $mmTts['status'], 'MiniMax TTS is served. Got ' . substr($mmTts['body'], 0, 200));
assert_true(str_starts_with($mmTts['body'], 'ID3'), 'Hex audio is decoded to raw bytes.');
assert_same('audio/mpeg', $mmTts['headers']['Content-Type'] ?? null, 'Audio media type is set.');
assert_same('female-shaonv', $sent('minimax_tts')['body']['voice_setting']['voice_id'] ?? null, 'voice maps to voice_setting.voice_id.');
assert_same(7, (int) ($mmTts['record']['tts_chars'] ?? 0), 'Billed characters come from usage_characters.');

$cosy = $call('/v1/audio/speech', ['model' => 'cosyvoice', 'input' => '你好世界', 'voice' => 'longxiaochun']);
assert_same(200, $cosy['status'], 'CosyVoice is served. Got ' . substr($cosy['body'], 0, 200));
assert_true(str_starts_with($cosy['body'], 'ID3'), 'CosyVoice audio URL is fetched and returned as bytes.');

$eleven = $call('/v1/audio/speech', ['model' => 'eleven', 'input' => 'hello', 'voice' => 'voice123']);
assert_same(200, $eleven['status'], 'ElevenLabs TTS is served. Got ' . substr($eleven['body'], 0, 200));
assert_true(str_starts_with($eleven['body'], 'ID3'), 'ElevenLabs binary audio is passed through.');
assert_same('eleven_v4', $sent('elevenlabs')['body']['model_id'] ?? null, 'ElevenLabs receives model_id.');

$tmp = tempnam(sys_get_temp_dir(), 'asr');
file_put_contents($tmp, "ID3\x03\x00" . random_bytes(512));
$_FILES = ['file' => ['name' => 'a.mp3', 'type' => 'audio/mpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
$asr = $call('/v1/audio/transcriptions', ['model' => 'qwen-asr', 'language' => 'zh'], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
$_FILES = [];
@unlink($tmp);
assert_same(200, $asr['status'], 'Qwen ASR via chat is served. Got ' . substr($asr['body'], 0, 300));
assert_same('你好世界', $asr['json']['text'] ?? null, 'Transcript text is returned in the OpenAI shape.');
assert_true(str_starts_with((string) ($sent('qwen_asr')['body']['messages'][0]['content'][0]['input_audio']['data'] ?? ''), 'data:audio/mpeg;base64,'), 'Audio travels as a data URI in input_audio.');
assert_same(3, (int) ($asr['record']['audio_seconds'] ?? 0), 'Audio seconds come from the upstream usage.');

// ---------------------------------------------------------------- MM-022 Gemini native (Google GenAI SDK shape)
$gemini = static function (string $action, array $body) use ($useContext, $capture, $recordFor): array {
    $context = $useContext();
    $path = '/v1beta/models/gemini-3.8-flash:' . $action;
    $request = new Request('POST', $path, ['REMOTE_ADDR' => '127.0.0.1'], ['alt' => 'sse'], $body, (string) json_encode($body), $context);
    $request->params = ['model' => 'gemini-3.8-flash'];
    $response = (new GatewayService())->handle($request);
    $output = $capture($response);

    return ['status' => $response->status, 'body' => $output, 'json' => json_decode($output, true), 'record' => $recordFor($context->requestId())];
};
$geminiImage = $gemini('generateContent', ['contents' => [['role' => 'user', 'parts' => [['text' => 'what is this'], ['inline_data' => ['mime_type' => 'image/png', 'data' => 'QUJD']]]]]]);
assert_same(200, $geminiImage['status'], 'Gemini generateContent is proxied. Got ' . substr($geminiImage['body'], 0, 300));
assert_same('A cat.', $geminiImage['json']['candidates'][0]['content']['parts'][1]['text'] ?? null, 'Gemini response is returned unchanged.');
assert_same(1300, (int) ($geminiImage['record']['input_tokens'] ?? 0), 'usageMetadata prompt tokens are billed.');
assert_same(24, (int) ($geminiImage['record']['output_tokens'] ?? 0), 'Candidates + thoughts are output tokens.');
assert_same('vision_understanding', $geminiImage['record']['capability'] ?? null, 'Inline images route as vision.');
assert_true(!isset($sent('gemini')['body']['model']), 'The model stays in the path, not the body.');
$geminiStream = $gemini('streamGenerateContent', ['contents' => [['role' => 'user', 'parts' => [['text' => 'hi']]]]]);
assert_true(str_contains($geminiStream['body'], '"lo"'), 'Gemini SSE chunks reach the client.');
assert_same('success', $geminiStream['record']['status'] ?? null, 'A Gemini stream ending with finishReason is a success.');
assert_same(12, (int) ($geminiStream['record']['total_tokens'] ?? 0), 'Stream usage is the last cumulative usageMetadata.');

// ---------------------------------------------------------------- MM-030 realtime relay: client secret -> session -> usage
$realtimeContext = $useContext();
$realtime = new \TokenHub\Services\RealtimeService();
$clientSecret = $realtime->issueClientSecret(App::context()->apiKey() ?? [], 'gpt-realtime', 60);
assert_true(str_starts_with($clientSecret['value'], 'ek_'), 'Browsers get an ek_ client secret, never the API key.');
assert_same(403, $realtime->authorize($clientSecret['value'], 'other-model', '127.0.0.1')['status'] ?? null, 'A client secret only opens its own model.');
$clientSecret = $realtime->issueClientSecret(App::context()->apiKey() ?? [], 'gpt-realtime', 60);
$sessionGrant = $realtime->authorize($clientSecret['value'], 'gpt-realtime', '127.0.0.1');
assert_same(true, $sessionGrant['ok'] ?? null, 'The relay is authorized with the client secret. Got ' . json_encode($sessionGrant));
assert_same('ws://127.0.0.1:' . $port . '/v1/realtime?model=gpt-realtime-2.1', $sessionGrant['data']['upstream_url'] ?? null, 'Upstream realtime URL uses ws(s) and the upstream model.');
assert_same('Bearer sk-rt', $sessionGrant['data']['upstream_headers']['Authorization'] ?? null, 'The relay gets the channel credential, the client never does.');
assert_same(401, $realtime->authorize($clientSecret['value'], 'gpt-realtime', '127.0.0.1')['status'] ?? null, 'Client secrets are single use.');
$sessionId = (string) $sessionGrant['data']['session_id'];
$usageContext = $useContext();
$reported = $realtime->recordUsage($sessionId, ['input_tokens' => 120, 'output_tokens' => 80, 'audio_input_tokens' => 90, 'audio_output_tokens' => 70], 'success', '', 1500, false);
assert_same(true, $reported['data']['allowed'] ?? null, 'Within quota the session continues.');
$realtimeRecord = $recordFor($usageContext->requestId());
assert_same('realtime', $realtimeRecord['capability'] ?? null, 'Realtime usage is recorded with its capability.');
assert_same(200, (int) ($realtimeRecord['total_tokens'] ?? 0), 'Realtime tokens are recorded.');
assert_same(90, (int) ($realtimeRecord['audio_input_tokens'] ?? 0), 'Audio tokens are kept for modality pricing.');
$useContext();
assert_same(true, $realtime->recordUsage($sessionId, [], 'success', '', 2000, true)['data']['closed'] ?? null, 'The final report closes the session.');
assert_same(404, $realtime->recordUsage($sessionId, [], 'success', '', 2000, false)['status'] ?? null, 'A closed session cannot report usage.');

// ---------------------------------------------------------------- MM-032 catalog prices: preview, then explicit apply
$pdo->exec('CREATE TABLE model_prices (id INTEGER PRIMARY KEY, model_id INT, input_token_price TEXT, output_token_price TEXT, cache_read_token_price TEXT, cache_write_token_price TEXT, reasoning_token_price TEXT, audio_second_price TEXT, tts_char_price TEXT, image_price TEXT, video_second_price TEXT, request_price TEXT, pricing_rules TEXT, currency TEXT, effective_at TEXT)');
$pdo->exec("INSERT INTO model_prices (model_id, input_token_price, output_token_price, currency, effective_at) VALUES (111, '0.000002', '0.000008', 'USD', '2026-01-01 00:00:00')");
$pdo->exec('UPDATE models SET capabilities = ' . $pdo->quote((string) json_encode(['upstream' => ['pricing' => ['input_token_price' => '0.0000025', 'output_token_price' => '0.000008', 'currency' => 'USD']]])) . ' WHERE id = 111');
$sync = new \TokenHub\Services\ProviderModelSyncService();
$preview = $sync->catalogPrices(7);
assert_same(1, count($preview['models']), 'Only models whose catalog price differs are listed.');
assert_same(['current' => '0.000002', 'catalog' => '0.0000025'], $preview['models'][0]['changes']['input_token_price'] ?? null, 'The difference is shown field by field.');
assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM model_prices')->fetchColumn(), 'Preview writes nothing.');
$applied = $sync->catalogPrices(7, true, [111]);
assert_same(true, $applied['models'][0]['applied'] ?? null, 'Apply writes a new price version.');
assert_same('0.0000025', (string) $pdo->query('SELECT input_token_price FROM model_prices ORDER BY id DESC LIMIT 1')->fetchColumn(), 'The new version carries the catalog price.');

App::redis()->delete($queue);
$appConfig->setValue(null, $originalConfig);
$appDatabase->setValue(null, $originalDatabase);
echo "ModelSupportFlowTest OK\n";
