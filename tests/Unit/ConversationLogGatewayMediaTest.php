<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * 会话记录 · 多模态, through the real gateway: a vision chat with an inline
 * image, image generation and edits, text-to-speech, speech recognition and
 * an asynchronous video against the fake upstream
 * (tests/fixtures/upstream/multimodal.php). The usage records the gateway
 * queues carry the turn and the media key; the conversation log then holds the
 * images, audio and video themselves (copied from the tasks), byte for byte.
 *
 * SQLite stands in for both databases; Redis must be reachable.
 */

use TokenHub\Http\Controllers\DownloadController;
use TokenHub\Services\GatewayService;
use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Services\ConversationLogDb;
use TokenHub\Services\ConversationLogService;
use TokenHub\Support\Crypto;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('proc_open')) {
    echo "SKIP sqlite or proc_open unavailable\n";
    return;
}
$queue = 'tokenhub:convmedia:' . bin2hex(random_bytes(4));
$_ENV['QUEUE_NAME'] = $queue;
$_ENV['QUEUE_ROUTING'] = 'false'; // these assertions read the main queue (PERF-015 routes usage.write to <name>:usage)
$_ENV['OUTBOUND_PRIVATE_ALLOWLIST'] = '127.0.0.1/32';
$_ENV['PROVIDER_RETRY_MAX_BACKOFF_MS'] = '1';
$_ENV['APP_KEY'] = 'conversation-media-flow-key-0123456789abcdef';
putenv('APP_KEY=' . $_ENV['APP_KEY']);
$tmpRoot = sys_get_temp_dir() . '/tokenhub-conv-gw-' . getmypid();
@mkdir($tmpRoot . '/media', 0777, true);
foreach (['CONVERSATION_LOG_PATH' => $tmpRoot . '/logs', 'MEDIA_STORAGE_PATH' => $tmpRoot . '/media'] as $name => $value) {
    $_ENV[$name] = $value;
    putenv($name . '=' . $value);
}
register_shutdown_function(static function () use ($tmpRoot): void {
    exec('rm -rf ' . escapeshellarg($tmpRoot));
});
$_ENV['APP_URL'] = 'https://tokenhub.test';
// bin/run-tests.php runs every file in one process: reload configuration so
// the env above applies, and put the shared singletons back at the end.
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
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/upstream/multimodal.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static fn () => proc_terminate($server));
$base = 'http://127.0.0.1:' . $port;
for ($i = 0; $i < 100; $i++) {
    if (@file_get_contents($base . '/mm/v1/embeddings', false, stream_context_create(['http' => ['method' => 'POST', 'content' => '{}', 'ignore_errors' => true, 'timeout' => 1]])) !== false) {
        break;
    }
    usleep(50000);
}

// SQLite stand-in that understands the one MySQL introspection statement the
// audited download path issues (AuditService: SHOW COLUMNS FROM audit_logs).
final class MultimodalSqlitePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/i', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('" . $m[1] . "')";
        }

        return parent::prepare($query, $options);
    }
}
$pdo = new MultimodalSqlitePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
$pdo->exec('CREATE TABLE desensitization_events (id INTEGER PRIMARY KEY, policy_id INT, request_id TEXT, target_type TEXT, action TEXT, match_count INT, summary TEXT)');
$pdo->exec('CREATE TABLE multimodal_tasks (id INTEGER PRIMARY KEY, task_id TEXT UNIQUE, task_type TEXT, status TEXT, progress INT, provider_task_id TEXT, user_id INT, department_id INT, api_key_id INT, provider_id INT, model_id INT, submit_payload TEXT, result TEXT, error_code TEXT, error_message TEXT, latency_ms INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, finished_at TEXT)');
$pdo->exec('CREATE TABLE media_assets (id INTEGER PRIMARY KEY, asset_id TEXT UNIQUE, task_id INT, owner_user_id INT, department_id INT, api_key_id INT, access_scope TEXT, media_type TEXT, storage_path TEXT, mime_type TEXT, size_bytes INT, duration_seconds INT, width INT, height INT, expires_at TEXT, deleted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT)');
$pdo->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, status TEXT, expires_at TEXT, conversation_log_enabled INT NOT NULL DEFAULT 0, conversation_log_retention_days INT NULL)');
$pdo->exec('CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT, updated_at TEXT)');
$pdo->exec('INSERT INTO users (id, status) VALUES (1, "active")');
$pdo->exec('INSERT INTO api_keys (id, user_id, status, conversation_log_enabled) VALUES (7101, 1, "active", 1)');
// Conversation logging on for the feature and for key 7101.
$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('conversation_log.settings', '{\"enabled\":true,\"timezone\":\"Asia/Shanghai\"}')");
App::redis()->delete('conversation_log:enabled');
\TokenHub\Services\ConversationLogService::forgetEnabled();

$insertProvider = $pdo->prepare('INSERT INTO providers (id, name, protocol, base_url, status, timeout_ms, retry_count, default_headers) VALUES (?, ?, ?, ?, "active", 5000, 1, ?)');
$insertProvider->execute([1, 'OpenAI-compatible', 'openai', $base . '/mm', null]);
$insertProvider->execute([2, 'Anthropic', 'anthropic', $base . '/anth', null]);
$insertProvider->execute([3, 'Ark', 'openai', $base . '/ark/api/v3', '{"tokenhub_adapter":"volcengine_ark"}']);
$insertModel = $pdo->prepare('INSERT INTO models (id, provider_id, name, external_name, capability, capability_tags, status, route_priority) VALUES (?, ?, ?, ?, ?, ?, "active", 1)');
$insertModel->execute([101, 1, 'chat-text', 'up-chat-text', 'text_generation', null]);
// A vision model created by hand in the console: no capability_tags.
$insertModel->execute([102, 1, 'chat-vision', 'up-chat-vision', 'vision_understanding', null]);
$insertModel->execute([103, 1, 'embed', 'up-embed', 'embedding', null]);
$insertModel->execute([104, 1, 'image-gen', 'up-image', 'image_generation', null]);
$insertModel->execute([105, 1, 'tts', 'up-tts', 'tts', null]);
$insertModel->execute([106, 1, 'asr', 'up-asr', 'asr', null]);
$insertModel->execute([107, 2, 'claude-vision', 'up-claude', 'vision_understanding', '["vision_understanding","text_generation"]']);
$insertModel->execute([108, 3, 'seedance', 'doubao-seedance-1-0-pro', 'video_generation', null]);
$allModels = [101, 102, 103, 104, 105, 106, 107, 108];

foreach ($allModels as $modelId) {
    App::redis()->delete('route_health:' . $modelId);
}
\TokenHub\Support\ConfigVersion::bump('global', 'multimodal-flow-test');
\TokenHub\Support\ConfigVersion::refresh();

$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);

$apiKeyId = 7101;
$useContext = static function (int $keyId = 7101) use ($allModels): RequestContext {
    $context = RequestContext::fromHeaders([]);
    (new ReflectionProperty(App::class, 'context'))->setValue(null, $context);
    $context->setApiKey(['id' => $keyId, 'user_id' => 1, 'department_id' => 1, 'allowed_model_ids' => $allModels, 'allowed_model_names' => [], 'conversation_log_enabled' => 1]);
    return $context;
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
$capture = static function (\TokenHub\Support\Response $response): string {
    ob_start();
    ob_start();
    $response->send();
    $inner = (string) ob_get_clean();
    return (string) ob_get_clean() . $inner;
};
$call = static function (string $path, array $body, array $server = [], int $keyId = 7101) use ($useContext, $recordFor, $capture): array {
    $context = $useContext($keyId);
    $request = new Request('POST', $path, ['REMOTE_ADDR' => '127.0.0.1', ...$server], [], $body, (string) json_encode($body), $context);
    $response = (new GatewayService())->handle($request);
    $output = $capture($response);

    return [
        'status' => $response->status,
        'headers' => (new ReflectionProperty($response, 'headers'))->getValue($response),
        'body' => $output,
        'json' => json_decode($output, true),
        'record' => $recordFor($context->requestId()),
    ];
};
$download = static function (string $url) use ($capture): array {
    $token = basename((string) parse_url($url, PHP_URL_PATH));
    $request = new Request('GET', '/download/' . $token, [], [], [], '', RequestContext::fromHeaders([]));
    $request->params = ['token' => $token];
    $response = (new DownloadController())->show($request);

    return ['status' => $response->status, 'body' => $capture($response), 'headers' => (new ReflectionProperty($response, 'headers'))->getValue($response)];
};
$multipart = static function (string $path, array $fields, string $field, string $filename, string $mime, string $bytes) use ($call): array {
    $tmp = tempnam(sys_get_temp_dir(), 'mmup');
    file_put_contents($tmp, $bytes);
    $_FILES = [$field => ['name' => $filename, 'type' => $mime, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]];
    try {
        return $call($path, $fields, ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
    } finally {
        $_FILES = [];
        @unlink($tmp);
    }
};


$logPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
ConversationLogDb::useConnection($logPdo);
$usageId = 0;
// What the usage.write consumer does: decrypt the turn, store it.
$store = static function (array $result) use (&$usageId): array {
    $record = $result['record'];
    assert_true(is_string($record['conversation_encrypted'] ?? null), 'The usage record carries the conversation turn for ' . ($record['endpoint'] ?? '?'));
    assert_true(strlen((string) $record['conversation_encrypted']) < 20000, 'Media bytes never travel through the queue.');
    $record['conversation'] = json_decode(Crypto::decrypt((string) $record['conversation_encrypted']), true);
    unset($record['conversation_encrypted']);
    (new ConversationLogService())->writeForRecord(['id' => ++$usageId], $record);
    return $record['conversation'];
};
$service = new ConversationLogService();
$mediaOf = static fn (string $requestId): array => $logPdo->query('SELECT m.* FROM conversation_log_media m JOIN conversation_logs c ON c.id = m.log_id WHERE c.request_id = ' . $logPdo->quote($requestId) . ' ORDER BY m.position, m.id')->fetchAll();
$bytes = static fn (array $row): string => $service->mediaStore()->readAll((string) $row['file']);
$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

// ---------------------------------------------------------------- vision chat with an inline image
$vision = $call('/v1/chat/completions', ['model' => 'chat-vision', 'messages' => [['role' => 'user', 'content' => [
    ['type' => 'text', 'text' => '图里有什么'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode($png)]],
]]]]);
assert_same(200, $vision['status'], 'Vision chat is served. Got ' . substr($vision['body'], 0, 200));
$turn = $store($vision);
assert_true(isset($turn['messages'][0]['parts'][1]['media']), 'The image part points at its media.');
$rows = $mediaOf((string) $vision['record']['request_id']);
assert_same('stored', $rows[0]['status'] ?? null, 'The inline image was written by the gateway.');
assert_same($png, $bytes($rows[0]), 'The stored image is the one sent.');

// ---------------------------------------------------------------- image generation (URL results fetched by the gateway)
$image = $call('/v1/images/generations', ['model' => 'image-gen', 'prompt' => 'a dot', 'n' => 2, 'size' => '1024x1024']);
assert_same(200, $image['status'], 'Image generation is served.');
$turn = $store($image);
assert_same('image_generation', $turn['format'], 'Image generation is a turn.');
assert_same('1024x1024', $turn['params']['size'] ?? null, 'The size is a parameter.');
$service->processMedia();
$outputs = array_values(array_filter($mediaOf((string) $image['record']['request_id']), static fn (array $row): bool => $row['source'] === 'asset'));
assert_same(2, count($outputs), 'Both generated images are copied into the conversation log.');
foreach ($outputs as $row) {
    assert_same('stored', $row['status'], 'Generated image copied.');
    assert_true(str_starts_with($bytes($row), "\x89PNG"), 'The copy is the generated PNG.');
}

// ---------------------------------------------------------------- image edit (multipart upload)
$edit = $multipart('/v1/images/edits', ['model' => 'image-gen', 'prompt' => 'make it red', 'response_format' => 'b64_json'], 'image', 'in.png', 'image/png', $png);
assert_same(200, $edit['status'], 'Image edit is served.');
$turn = $store($edit);
$service->processMedia();
$rows = $mediaOf((string) $edit['record']['request_id']);
$input = array_values(array_filter($rows, static fn (array $row): bool => $row['direction'] === 'input'));
assert_same($png, $bytes($input[0]), 'The uploaded image of an edit is kept.');
assert_same(1, count(array_filter($rows, static fn (array $row): bool => $row['source'] === 'asset' && $row['status'] === 'stored')), 'The edited image is kept.');

// ---------------------------------------------------------------- text-to-speech
$tts = $call('/v1/audio/speech', ['model' => 'tts', 'input' => '你好，世界', 'voice' => 'alloy']);
$turn = $store($tts);
assert_same('你好，世界', $turn['messages'][0]['parts'][0]['text'] ?? null, 'The text to speak is the question.');
$service->processMedia();
$audio = array_values(array_filter($mediaOf((string) $tts['record']['request_id']), static fn (array $row): bool => $row['source'] === 'asset'));
assert_same($tts['body'], $bytes($audio[0]), 'The generated speech is kept byte for byte.');

// ---------------------------------------------------------------- speech recognition
$audioBytes = "ID3\x03\x00" . random_bytes(2048);
$asr = $multipart('/v1/audio/transcriptions', ['model' => 'asr', 'language' => 'zh'], 'file', 'speech.mp3', 'audio/mpeg', $audioBytes);
$turn = $store($asr);
assert_same('你好世界', $turn['reply']['text'] ?? null, 'The recognised text is the answer.');
$rows = $mediaOf((string) $asr['record']['request_id']);
assert_same(1, count($rows), 'ASR keeps the uploaded audio (no output files).');
assert_same($audioBytes, $bytes($rows[0]), 'The uploaded audio is kept byte for byte.');

// ---------------------------------------------------------------- video (asynchronous)
$video = $call('/v1/videos/generations', ['model' => 'seedance', 'prompt' => 'a product demo', 'duration' => 5, 'ratio' => '16:9']);
assert_same(202, $video['status'], 'Video generation is accepted.');
$turn = $store($video);
$service->processMedia();
$rows = $mediaOf((string) $video['record']['request_id']);
assert_same('waiting', $rows[0]['status'] ?? null, 'The record waits for the video task.');
$useContext();
(new GatewayService())->refreshTask((string) $video['json']['task_id']);
$service->processMedia();
$rows = $mediaOf((string) $video['record']['request_id']);
$file = array_values(array_filter($rows, static fn (array $row): bool => $row['source'] === 'asset'));
assert_same('stored', $file[0]['status'] ?? null, 'The finished video is copied into the conversation log.');
assert_true(str_contains($bytes($file[0]), 'ftypmp42'), 'The copy is the generated video.');

assert_same(6, (int) $logPdo->query("SELECT COUNT(DISTINCT log_id) FROM conversation_log_media WHERE status = 'stored'")->fetchColumn(), 'Every logged call has its media stored.');
assert_same(0, (int) $logPdo->query("SELECT COUNT(*) FROM conversation_log_media WHERE status IN ('pending', 'working', 'waiting', 'failed')")->fetchColumn(), 'Nothing is left waiting or failed.');

App::redis()->delete($queue);
$_FILES = [];
ConversationLogDb::reset();
$appConfig->setValue(null, $originalConfig);
$appDatabase->setValue(null, $originalDatabase);
echo "ConversationLogGatewayMediaTest OK\n";
