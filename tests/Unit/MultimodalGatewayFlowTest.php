<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * End-to-end multimodal gateway contract: text, vision (OpenAI chat,
 * Responses and Anthropic image blocks), embeddings, image generation and
 * edits, text-to-speech, speech-to-text/translation and asynchronous video
 * generation against a fake upstream (tests/fixtures/upstream/multimodal.php).
 *
 * The relational store is emulated by SQLite like GatewayFlowTest; Redis must
 * be reachable. Every scenario asserts what an OpenAI/Anthropic SDK client
 * receives AND what the upstream received, so drift on either side fails.
 */

use TokenHub\Http\Controllers\DownloadController;
use TokenHub\Services\GatewayService;
use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('proc_open')) {
    echo "SKIP sqlite or proc_open unavailable\n";
    return;
}
$queue = 'tokenhub:mmtest:' . bin2hex(random_bytes(4));
$_ENV['QUEUE_NAME'] = $queue;
$_ENV['OUTBOUND_PRIVATE_ALLOWLIST'] = '127.0.0.1/32';
$_ENV['PROVIDER_RETRY_MAX_BACKOFF_MS'] = '1';
$_ENV['APP_KEY'] = 'multimodal-flow-test-key-0123456789abcdef';
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
$pdo->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, status TEXT, expires_at TEXT)');
$pdo->exec('INSERT INTO users (id, status) VALUES (1, "active")');
$pdo->exec('INSERT INTO api_keys (id, user_id, status) VALUES (7101, 1, "active")');

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
    $context->setApiKey(['id' => $keyId, 'user_id' => 1, 'department_id' => 1, 'allowed_model_ids' => $allModels, 'allowed_model_names' => []]);
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

// A large inline image: ~600 KB of base64. Its token estimate must not be
// the base64 length / 4 (~150k tokens), or token quotas reject the call.
$bigImage = 'data:image/png;base64,' . base64_encode(random_bytes(450000));
$signedImageUrl = 'https://cdn.example.com/a.png?X-Amz-Security-Token=abc123&Expires=1730123456789';
$policyId = random_int(900000, 999999);
$pdo->exec("INSERT INTO quota_policies (id, name, period, status, total_token_limit) VALUES ({$policyId}, 'vision-budget', 'day', 'active', 20000)");
$pdo->exec("INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES ({$policyId}, 'api_key', {$apiKeyId})");

// ---------------------------------------------------------------- text
$text = $call('/v1/chat/completions', ['model' => 'chat-text', 'messages' => [['role' => 'user', 'content' => '你好']]]);
assert_same(200, $text['status'], 'Text chat is served.');
assert_same('up-chat-text', $text['json']['received_model'] ?? null, 'Upstream receives the channel model id.');
assert_same(125, (int) ($text['record']['total_tokens'] ?? 0), 'Text usage is measured.');

// A vision model also answers plain text requests, even without tags.
$textOnVision = $call('/v1/chat/completions', ['model' => 'chat-vision', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
assert_same(200, $textOnVision['status'], 'A vision_understanding model serves text-only chat. Got ' . $textOnVision['body']);

// ---------------------------------------------------------------- vision
$vision = $call('/v1/chat/completions', ['model' => 'chat-vision', 'messages' => [['role' => 'user', 'content' => [
    ['type' => 'text', 'text' => 'describe'],
    ['type' => 'image_url', 'image_url' => ['url' => $bigImage, 'detail' => 'high']],
    ['type' => 'image_url', 'image_url' => ['url' => $signedImageUrl]],
]]]]);
assert_same(200, $vision['status'], 'Vision chat with a large inline image fits a 20k token budget. Got ' . substr($vision['body'], 0, 300));
assert_same('vision_understanding', $vision['record']['capability'] ?? null, 'Image parts route as vision_understanding.');
assert_same([hash('sha256', $bigImage), hash('sha256', $signedImageUrl)], $vision['json']['media'] ?? null, 'Image bytes and signed URLs reach the upstream unchanged.');
assert_same(2, (int) ($vision['record']['image_count'] ?? 0), 'Both images are counted.');

$textModelImage = $call('/v1/chat/completions', ['model' => 'chat-text', 'messages' => [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => $signedImageUrl]]]]]]);
assert_same(422, $textModelImage['status'], 'Images to a text-only model are refused before the upstream call.');
assert_same('MODEL_CAPABILITY_UNSUPPORTED', $textModelImage['json']['error']['code'] ?? null, 'Refusal names the capability problem.');

$responsesVision = $call('/v1/responses', ['model' => 'chat-vision', 'input' => [['role' => 'user', 'content' => [
    ['type' => 'input_text', 'text' => 'describe'],
    ['type' => 'input_image', 'image_url' => $bigImage],
]]]]);
assert_same(200, $responsesVision['status'], 'Responses API vision is served. Got ' . substr($responsesVision['body'], 0, 300));
assert_same([hash('sha256', $bigImage)], $responsesVision['json']['media'] ?? null, 'Responses input_image reaches the upstream unchanged.');

$anthropicVision = $call('/v1/messages', ['model' => 'claude-vision', 'max_tokens' => 64, 'messages' => [['role' => 'user', 'content' => [
    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => substr($bigImage, 22)]],
    ['type' => 'text', 'text' => 'describe'],
]]]]);
assert_same(200, $anthropicVision['status'], 'Anthropic image blocks are served. Got ' . substr($anthropicVision['body'], 0, 300));
assert_same([hash('sha256', substr($bigImage, 22))], $anthropicVision['json']['media'] ?? null, 'Anthropic base64 image reaches the upstream unchanged.');
assert_same(152, (int) ($anthropicVision['record']['total_tokens'] ?? 0), 'Anthropic usage is measured.');

$anthropicPdf = $call('/v1/messages', ['model' => 'claude-vision', 'max_tokens' => 64, 'messages' => [['role' => 'user', 'content' => [
    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 fake')]],
    ['type' => 'text', 'text' => 'summarize'],
]]]]);
assert_same(0, (int) ($anthropicPdf['record']['image_count'] ?? 0), 'Anthropic document blocks are not counted (or billed) as images.');

// Desensitization must not rewrite media payloads.
$pdo->exec('INSERT INTO desensitization_policies (id, status, scope_type, scope_id, action) VALUES (1, "active", "system", 0, "replace")');
\TokenHub\Support\ConfigVersion::bump('global', 'multimodal-flow-test-policy');
\TokenHub\Support\ConfigVersion::refresh();
$redacted = $call('/v1/chat/completions', ['model' => 'chat-vision', 'messages' => [['role' => 'user', 'content' => [
    ['type' => 'text', 'text' => '我的手机号是13812345678'],
    ['type' => 'image_url', 'image_url' => ['url' => $signedImageUrl]],
]]]]);
assert_same(200, $redacted['status'], 'Vision request passes the desensitization policy.');
assert_same([hash('sha256', $signedImageUrl)], $redacted['json']['media'] ?? null, 'Signed media URLs survive desensitization unchanged.');
$pdo->exec('DELETE FROM desensitization_policies');
\TokenHub\Support\ConfigVersion::bump('global', 'multimodal-flow-test-policy-off');
\TokenHub\Support\ConfigVersion::refresh();

// ---------------------------------------------------------------- embeddings
$embedding = $call('/v1/embeddings', ['model' => 'embed', 'input' => 'hello']);
assert_same(200, $embedding['status'], 'Embeddings are served.');
assert_same([0.1, 0.2, 0.3], $embedding['json']['data'][0]['embedding'] ?? null, 'Embedding vectors are returned unchanged.');
assert_same(4, (int) ($embedding['record']['input_tokens'] ?? 0), 'Embedding usage is measured.');

// ---------------------------------------------------------------- image generation
$imageUrl = $call('/v1/images/generations', ['model' => 'image-gen', 'prompt' => 'a dot', 'n' => 2, 'size' => '1024x1024']);
assert_same(200, $imageUrl['status'], 'Image generation is served. Got ' . substr($imageUrl['body'], 0, 300));
assert_same(2, count($imageUrl['json']['data'] ?? []), 'OpenAI images response keeps one data item per image.');
$firstUrl = (string) ($imageUrl['json']['data'][0]['url'] ?? '');
assert_true(str_starts_with($firstUrl, 'https://tokenhub.test/download/'), 'data[].url is a usable TokenHub download link (OpenAI SDK contract). Got ' . json_encode($imageUrl['json']));
assert_true(!str_contains($imageUrl['body'], '127.0.0.1'), 'The upstream asset URL is not exposed.');
assert_same('a dot', $imageUrl['json']['data'][0]['revised_prompt'] ?? null, 'Other per-image fields are kept.');
assert_same(2, count($imageUrl['json']['assets'] ?? []), 'Assets are registered for governance.');
assert_same(2, (int) ($imageUrl['record']['image_count'] ?? 0), 'Image count is recorded for billing.');
$fetched = $download($firstUrl);
assert_same(200, $fetched['status'], 'The returned image URL downloads.');
assert_true(str_starts_with($fetched['body'], "\x89PNG"), 'Downloaded bytes are the generated image.');

$imageB64 = $call('/v1/images/generations', ['model' => 'image-gen', 'prompt' => 'a dot', 'response_format' => 'b64_json']);
assert_same(200, $imageB64['status'], 'b64_json image generation is served.');
assert_true(str_starts_with((string) base64_decode((string) ($imageB64['json']['data'][0]['b64_json'] ?? ''), true), "\x89PNG"), 'data[].b64_json carries the image when the client asked for b64_json.');
assert_same(1, count($imageB64['json']['assets'] ?? []), 'b64 images are stored as assets too.');

$edit = $multipart('/v1/images/edits', ['model' => 'image-gen', 'prompt' => 'make it red', 'response_format' => 'b64_json'], 'image', 'in.png', 'image/png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
assert_same(200, $edit['status'], 'Image edits (multipart) are served. Got ' . substr($edit['body'], 0, 300));
assert_same('up-image', $edit['json']['received_fields']['model'] ?? null, 'Image edit upstream receives the channel model id.');
assert_same('in.png', $edit['json']['received_files']['image']['name'] ?? null, 'Image edit upstream receives the image file.');
assert_true(($edit['json']['data'][0]['b64_json'] ?? '') !== '', 'Image edit returns the edited image.');

// ---------------------------------------------------------------- text-to-speech
$tts = $call('/v1/audio/speech', ['model' => 'tts', 'input' => '你好，世界', 'voice' => 'alloy']);
assert_same(200, $tts['status'], 'TTS is served.');
assert_same('audio/mpeg', $tts['headers']['Content-Type'] ?? null, 'TTS returns the upstream audio media type.');
assert_true(str_starts_with($tts['body'], 'ID3'), 'TTS returns raw audio bytes.');
assert_true(isset($tts['headers']['X-TokenHub-Task-Id']), 'TTS output is tracked as a task.');
assert_same(5, (int) ($tts['record']['tts_chars'] ?? 0), 'TTS bills characters, not UTF-8 bytes.');
$ttsJson = $call('/v1/audio/speech', ['model' => 'tts', 'input' => 'hello', 'voice' => 'alloy'], ['HTTP_X_TOKENHUB_RESPONSE_MODE' => 'json']);
assert_same(200, $ttsJson['status'], 'TTS JSON envelope is served.');
assert_true(str_starts_with((string) base64_decode((string) ($ttsJson['json']['b64_json'] ?? ''), true), 'ID3'), 'The JSON envelope carries the audio as b64_json.');
assert_same(1, count($ttsJson['json']['assets'] ?? []), 'TTS audio is stored as an asset.');

// ---------------------------------------------------------------- speech-to-text
$audioBytes = "ID3\x03\x00" . random_bytes(2048);
$asr = $multipart('/v1/audio/transcriptions', ['model' => 'asr', 'language' => 'zh'], 'file', 'speech.mp3', 'audio/mpeg', $audioBytes);
assert_same(200, $asr['status'], 'Transcription is served. Got ' . substr($asr['body'], 0, 300));
assert_same('你好世界', $asr['json']['text'] ?? null, 'Transcription text is returned.');
assert_same('up-asr', $asr['json']['received_fields']['model'] ?? null, 'ASR upstream receives the channel model id.');
assert_same(strlen($audioBytes), (int) ($asr['json']['received_files']['file']['size'] ?? 0), 'Audio bytes reach the upstream intact.');
assert_same(4, (int) ($asr['record']['audio_seconds'] ?? 0), 'Audio seconds come from the upstream usage.');

$asrVerbose = $multipart('/v1/audio/transcriptions', ['model' => 'asr', 'response_format' => 'verbose_json'], 'file', 'speech.mp3', 'audio/mpeg', $audioBytes);
assert_same(8, (int) ($asrVerbose['record']['audio_seconds'] ?? 0), 'verbose_json duration is billed (rounded up).');

$asrText = $multipart('/v1/audio/transcriptions', ['model' => 'asr', 'response_format' => 'text'], 'file', 'speech.mp3', 'audio/mpeg', $audioBytes);
assert_same(200, $asrText['status'], 'Plain-text transcription is served.');
assert_same("你好世界\n", $asrText['body'], 'response_format=text returns the plain text body, not a JSON wrapper.');
assert_true(str_starts_with((string) ($asrText['headers']['Content-Type'] ?? ''), 'text/plain'), 'Plain-text transcription keeps the text media type.');

$asrSrt = $multipart('/v1/audio/transcriptions', ['model' => 'asr', 'response_format' => 'srt'], 'file', 'speech.mp3', 'audio/mpeg', $audioBytes);
assert_true(str_starts_with($asrSrt['body'], "1\n00:00:00,000"), 'response_format=srt returns subtitles.');

$translation = $multipart('/v1/audio/translations', ['model' => 'asr'], 'file', 'speech.mp3', 'audio/mpeg', $audioBytes);
assert_same(200, $translation['status'], 'Audio translation is served. Got ' . substr($translation['body'], 0, 300));
assert_same('hello world', $translation['json']['text'] ?? null, 'Translation text is returned.');

$notAudio = $multipart('/v1/audio/transcriptions', ['model' => 'asr'], 'file', 'x.exe', 'application/x-dosexec', "MZ\x90\x00binary");
assert_same(422, $notAudio['status'], 'Non-audio uploads are refused.');

// ---------------------------------------------------------------- video generation (async)
$video = $call('/v1/videos/generations', ['model' => 'seedance', 'prompt' => 'a product demo', 'duration' => 5, 'ratio' => '16:9', 'image_url' => $signedImageUrl]);
assert_same(202, $video['status'], 'Video generation is accepted asynchronously. Got ' . substr($video['body'], 0, 300));
$taskId = (string) ($video['json']['task_id'] ?? '');
assert_true(str_starts_with($taskId, 'task_'), 'A TokenHub task id is returned.');
assert_same('processing', $video['json']['status'] ?? null, 'Task starts in processing.');
assert_same('cgt-fake-1', $video['json']['provider_task_id'] ?? null, 'Upstream task id is kept.');
assert_same(5, (int) ($video['record']['video_seconds'] ?? 0), 'Requested duration is recorded for billing.');

$useContext();
$refreshed = (new GatewayService())->refreshTask($taskId);
assert_same(true, $refreshed['refreshed'] ?? null, 'Task refresh polls the Ark task API.');
assert_same('succeeded', $refreshed['status'] ?? null, 'Finished upstream task is succeeded.');
assert_same(1, count($refreshed['assets'] ?? []), 'The generated video is registered as an asset.');
assert_same('video', $refreshed['assets'][0]['media_type'] ?? null, 'Asset is a video.');
$link = (new GatewayService())->assetDownloadUrl((string) $refreshed['assets'][0]['asset_id']);
$videoFile = $download($link['download_url']);
assert_same(200, $videoFile['status'], 'The video asset downloads.');
assert_true(str_contains($videoFile['body'], 'ftypmp42'), 'Downloaded bytes are the video.');

App::redis()->delete($queue);
$_FILES = [];
$appConfig->setValue(null, $originalConfig);
$appDatabase->setValue(null, $originalDatabase);
echo "MultimodalGatewayFlowTest OK\n";
