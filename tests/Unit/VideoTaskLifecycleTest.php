<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Video task lifecycle through GatewayService (tests/fixtures/upstream/video-lifecycle.php):
 * Seedance submit -> poll -> measured usage -> billing settlement, cancel of
 * queued tasks (Seedance, MiniMax H3) with refund, refusal for running
 * tasks, failed Wan task refund, the OpenAI Videos API surface, and
 * video / audio understanding routing.
 *
 * SQLite stands in for MySQL; Redis must be reachable.
 */

use TokenHub\Http\Controllers\DownloadController;
use TokenHub\Services\GatewayService;
use TokenHub\Services\Video\OpenAIVideoApi;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Database;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('proc_open')) {
    echo "SKIP sqlite or proc_open unavailable\n";
    return;
}
$queue = 'tokenhub:videolifecycle:' . bin2hex(random_bytes(4));
$_ENV['QUEUE_NAME'] = $queue;
$_ENV['OUTBOUND_PRIVATE_ALLOWLIST'] = '127.0.0.1/32';
$_ENV['PROVIDER_RETRY_MAX_BACKOFF_MS'] = '1';
$_ENV['APP_KEY'] = 'video-lifecycle-test-key-0123456789abcdef';
$_ENV['APP_URL'] = 'http://tokenhub.test';
$mediaRoot = sys_get_temp_dir() . '/tokenhub-media-lifecycle-' . bin2hex(random_bytes(4));
$_ENV['MEDIA_STORAGE_PATH'] = $mediaRoot;
$appConfig = new ReflectionProperty(App::class, 'config');
$appDatabase = new ReflectionProperty(App::class, 'database');
$originalConfig = $appConfig->getValue();
$originalDatabase = $appDatabase->getValue();
$appConfig->setValue(null, \TokenHub\Support\Config::default());
try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    $appConfig->setValue(null, $originalConfig);
    echo "SKIP redis not reachable\n";
    return;
}

$port = random_int(40001, 50000);
$stateFile = sys_get_temp_dir() . '/tokenhub-video-lifecycle-' . $port . '.json';
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/upstream/video-lifecycle.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static function () use ($server, $stateFile): void {
    proc_terminate($server);
    @unlink($stateFile);
});
$base = 'http://127.0.0.1:' . $port;
for ($i = 0; $i < 100; $i++) {
    if (@file_get_contents($base . '/files/ping.mp4', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]])) !== false) {
        break;
    }
    usleep(50000);
}
@unlink($stateFile);
$lastRequest = static function (string $method, string $path) use ($stateFile): ?array {
    $state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    foreach (array_reverse($state['log'] ?? []) as $entry) {
        if ($entry['method'] === $method && $entry['path'] === $path) {
            return $entry;
        }
    }
    return null;
};

final class LifecycleSqlitePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/i', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('" . $m[1] . "')";
        }
        return parent::prepare($query, $options);
    }
}
$pdo = new LifecycleSqlitePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
$aggregateColumns = 'id INTEGER PRIMARY KEY, bucket_at TEXT, department_id INT, user_id INT, api_key_id INT, provider_id INT, model_id INT, capability TEXT, request_count INT, success_count INT, failed_count INT, input_tokens INT, output_tokens INT, cache_read_tokens INT, cache_write_tokens INT, total_cost REAL, latency_ms_sum INT';
foreach ([
    'CREATE TABLE providers (id INTEGER PRIMARY KEY, name TEXT, protocol TEXT, base_url TEXT, encrypted_secret TEXT, status TEXT, timeout_ms INT, retry_count INT, default_headers TEXT)',
    'CREATE TABLE models (id INTEGER PRIMARY KEY, provider_id INT, name TEXT, external_name TEXT, capability TEXT, capability_tags TEXT, capabilities TEXT, context_length INT, status TEXT, route_priority INT)',
    'CREATE TABLE quota_policies (id INTEGER PRIMARY KEY, name TEXT, period TEXT, status TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, audio_second_limit INT, tts_char_limit INT, image_limit INT, video_second_limit INT, cost_limit REAL, concurrency_limit INT)',
    'CREATE TABLE quota_bindings (id INTEGER PRIMARY KEY, quota_policy_id INT, target_type TEXT, target_id INT)',
    'CREATE TABLE api_key_model_quotas (id INTEGER PRIMARY KEY, api_key_id INT, model_id INT, period TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, status TEXT)',
    'CREATE TABLE desensitization_policies (id INTEGER PRIMARY KEY, status TEXT, scope_type TEXT, scope_id INT, action TEXT, parsers TEXT)',
    'CREATE TABLE multimodal_tasks (id INTEGER PRIMARY KEY, task_id TEXT UNIQUE, task_type TEXT, status TEXT, progress INT, provider_task_id TEXT, user_id INT, department_id INT, api_key_id INT, provider_id INT, model_id INT, usage_record_id INT, submit_payload TEXT, result TEXT, error_code TEXT, error_message TEXT, latency_ms INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, finished_at TEXT, settled_at TEXT)',
    'CREATE TABLE media_assets (id INTEGER PRIMARY KEY, asset_id TEXT UNIQUE, task_id INT, owner_user_id INT, department_id INT, api_key_id INT, access_scope TEXT, media_type TEXT, storage_path TEXT, mime_type TEXT, size_bytes INT, duration_seconds INT, width INT, height INT, expires_at TEXT, deleted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT)',
    'CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, status TEXT, expires_at TEXT)',
    'CREATE TABLE usage_records (id INTEGER PRIMARY KEY, request_id TEXT, user_id INT, department_id INT, api_key_id INT, provider_id INT, model_id INT, capability TEXT, status TEXT, input_tokens INT, output_tokens INT, total_tokens INT, cache_read_tokens INT DEFAULT 0, cache_write_tokens INT DEFAULT 0, audio_seconds INT DEFAULT 0, tts_chars INT DEFAULT 0, image_count INT DEFAULT 0, video_seconds INT DEFAULT 0, cost_amount TEXT, cost_currency TEXT, aggregated_at TEXT, created_at TEXT)',
    "CREATE TABLE usage_aggregates_minute ({$aggregateColumns})",
    "CREATE TABLE usage_aggregates_hour ({$aggregateColumns})",
    "CREATE TABLE usage_aggregates_day ({$aggregateColumns})",
    'CREATE TABLE usage_settlements (id INTEGER PRIMARY KEY, task_id TEXT UNIQUE, usage_record_id INT, api_key_id INT, model_id INT, reason TEXT, charged TEXT, final TEXT, cost_delta TEXT, currency TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE model_prices (id INTEGER PRIMARY KEY, model_id INT, effective_at TEXT, currency TEXT, input_token_price TEXT, output_token_price TEXT, cache_read_token_price TEXT, cache_write_token_price TEXT, reasoning_token_price TEXT, audio_second_price TEXT, tts_char_price TEXT, image_price TEXT, video_second_price TEXT, request_price TEXT, image_input_token_price TEXT, image_output_token_price TEXT, audio_input_token_price TEXT, audio_output_token_price TEXT, pricing_rules TEXT)',
    'INSERT INTO users (id, status) VALUES (1, "active")',
    'INSERT INTO api_keys (id, user_id, status) VALUES (7301, 1, "active")',
] as $sql) {
    $pdo->exec($sql);
}
$provider = $pdo->prepare('INSERT INTO providers (id, name, protocol, base_url, encrypted_secret, status, timeout_ms, retry_count, default_headers) VALUES (?, ?, "openai", ?, ?, "active", 5000, 1, ?)');
$provider->execute([1, 'Ark', $base . '/ark/api/v3', Crypto::encrypt('ark-key'), '{"tokenhub_adapter":"volcengine_ark"}']);
$provider->execute([2, 'MiniMax', $base, Crypto::encrypt('mm-key'), '{"tokenhub_adapter":"minimax"}']);
$provider->execute([3, 'Bailian', $base, Crypto::encrypt('ds-key'), '{"tokenhub_adapter":"dashscope"}']);
$provider->execute([4, 'Chat', $base . '/chat/v1', Crypto::encrypt('chat-key'), null]);
$model = $pdo->prepare('INSERT INTO models (id, provider_id, name, external_name, capability, capability_tags, capabilities, status, route_priority) VALUES (?, ?, ?, ?, ?, ?, ?, "active", 1)');
$model->execute([401, 1, 'seedance', 'doubao-seedance-2-5-260628', 'video_generation', null, null]);
$model->execute([402, 2, 'hailuo-h3', 'MiniMax-H3', 'video_generation', null, null]);
$model->execute([403, 3, 'wan', 'wan2.6-t2v', 'video_generation', null, null]);
$model->execute([410, 4, 'vl', 'up-vl', 'vision_understanding', null, null]);
$model->execute([411, 4, 'video-vl', 'up-video-vl', 'video_understanding', null, null]);
$model->execute([412, 4, 'audio-chat', 'up-audio', 'audio_understanding', null, null]);
$model->execute([413, 4, 'omni', 'up-omni', 'text_generation', null, '{"upstream":{"input_modalities":["text","image","video","audio"]}}']);
// Seedance is priced per output token (and per second as a fallback).
$pdo->exec("INSERT INTO model_prices (model_id, effective_at, currency, output_token_price, video_second_price) VALUES (401, '2020-01-01 00:00:00', 'USD', '0.00000200', '0.10000000')");
$pdo->exec("INSERT INTO model_prices (model_id, effective_at, currency, video_second_price) VALUES (402, '2020-01-01 00:00:00', 'USD', '0.10000000')");
$pdo->exec("INSERT INTO model_prices (model_id, effective_at, currency, video_second_price) VALUES (403, '2020-01-01 00:00:00', 'USD', '0.10000000')");
$allModels = [401, 402, 403, 410, 411, 412, 413];
foreach ($allModels as $modelId) {
    App::redis()->delete('route_health:' . $modelId);
}
\TokenHub\Support\ConfigVersion::bump('global', 'video-lifecycle-test');
\TokenHub\Support\ConfigVersion::refresh();
App::redis()->set('schema:column:usage_cost_aggregates_day.currency', '0', 300);
\TokenHub\Support\SchemaInspector::forget();
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
$appDatabase->setValue(null, $database);

$useContext = static function () use ($allModels): RequestContext {
    $context = RequestContext::fromHeaders([]);
    (new ReflectionProperty(App::class, 'context'))->setValue(null, $context);
    $context->setApiKey(['id' => 7301, 'user_id' => 1, 'department_id' => 1, 'allowed_model_ids' => $allModels, 'allowed_model_names' => []]);
    return $context;
};
$capture = static function (\TokenHub\Support\Response $response): string {
    ob_start();
    ob_start();
    $response->send();
    $inner = (string) ob_get_clean();
    return (string) ob_get_clean() . $inner;
};
/**
 * Calls the gateway and, like the queue worker, writes the usage record of
 * an accepted task (charged with the model price) and links it to the task.
 */
$call = static function (string $path, array $body) use ($useContext, $capture, $queue, $pdo): array {
    $context = $useContext();
    $request = new Request('POST', $path, ['REMOTE_ADDR' => '127.0.0.1'], [], $body, (string) json_encode($body), $context);
    $response = (new GatewayService())->handle($request);
    $output = $capture($response);
    $record = null;
    foreach (App::redis()->connection()->lRange($queue, 0, -1) ?: [] as $raw) {
        $job = json_decode((string) $raw, true);
        if (($job['record']['request_id'] ?? null) === $context->requestId()) {
            $record = $job['record'];
        }
    }
    if ($record !== null && !empty($record['task_id']) && ($record['status'] ?? '') === 'success') {
        $priced = (new \TokenHub\Services\PriceService())->priceUsage((int) $record['model_id'], $record);
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO usage_records (request_id, user_id, department_id, api_key_id, provider_id, model_id, capability, status, input_tokens, output_tokens, total_tokens, video_seconds, cost_amount, cost_currency, aggregated_at, created_at) VALUES (?, 1, 1, 7301, ?, ?, ?, "success", ?, ?, ?, ?, ?, "USD", ?, ?)')
            ->execute([$record['request_id'], $record['provider_id'] ?? null, $record['model_id'], $record['capability'], (int) ($record['input_tokens'] ?? 0), (int) ($record['output_tokens'] ?? 0), (int) ($record['total_tokens'] ?? 0), (int) ($record['video_seconds'] ?? 0), $priced['cost_amount'], $now, $now]);
        $pdo->prepare('UPDATE multimodal_tasks SET usage_record_id = ? WHERE task_id = ?')->execute([(int) $pdo->lastInsertId(), $record['task_id']]);
    }
    return ['status' => $response->status, 'body' => $output, 'json' => json_decode($output, true), 'record' => $record];
};
$taskRow = static fn (string $taskId): array => App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$taskId]);
$usageRow = static fn (string $taskId): ?array => App::db()->first('SELECT r.* FROM usage_records r JOIN multimodal_tasks t ON t.usage_record_id = r.id WHERE t.task_id = ?', [$taskId]);
$billingOf = static fn (string $taskId): array => json_decode((string) $taskRow($taskId)['result'], true)['billing'] ?? [];
$refresh = static function (string $taskId) use ($useContext): array {
    $useContext();
    return (new GatewayService())->refreshTask($taskId);
};
$expectAbort = static function (callable $fn, string $code, string $message): void {
    try {
        $fn();
        assert_true(false, $message . ' (no error raised)');
    } catch (HttpAbortException $abort) {
        assert_same($code, $abort->errorCode, $message);
    }
};

// ================================================================ Seedance: measured usage is settled
$ark = $call('/v1/videos/generations', ['model' => 'seedance', 'prompt' => 'a fox in snow', 'duration' => 10, 'resolution' => '1080p']);
assert_same(202, $ark['status'], 'Seedance task is accepted. Got ' . $ark['body']);
$arkTask = (string) $ark['json']['task_id'];
assert_same(10, (int) $ark['record']['video_seconds'], 'Submission charges the requested 10 s.');
assert_same(1.0, (float) $usageRow($arkTask)['cost_amount'], 'Requested charge: 10 s x 0.10.');
$refresh($arkTask);
assert_same([], $billingOf($arkTask), 'A running task is not settled.');
$done = $refresh($arkTask);
assert_same('succeeded', $done['status'] ?? null, 'Seedance task completes.');
assert_same(['video_seconds' => 6, 'output_tokens' => 108900], $done['result']['settled_usage'] ?? null, 'The vendor-measured length and tokens are kept on the task.');
$billing = $billingOf($arkTask);
assert_same('actual_usage', $billing['reason'] ?? null, 'Completion settles the charge to the measured usage.');
assert_same(6, (int) $usageRow($arkTask)['video_seconds'], 'Usage record bills the real 6 s.');
assert_same(108900, (int) $usageRow($arkTask)['output_tokens'], 'Usage record bills the real output tokens.');
assert_same(0.8178, round((float) $usageRow($arkTask)['cost_amount'], 8), 'Cost = 108900 tokens x 0.000002 + 6 s x 0.10.');
assert_same('-0.18220000', $billing['cost_delta'] ?? null, 'The difference is refunded.');

// ================================================================ failed task: refund
$arkFail = $call('/v1/videos/generations', ['model' => 'seedance', 'prompt' => 'fail please', 'duration' => 5]);
$failed = $refresh((string) $arkFail['json']['task_id']);
assert_same('failed', $failed['status'] ?? null, 'Failed upstream task is failed.');
assert_same('refund_failed', $billingOf((string) $arkFail['json']['task_id'])['reason'] ?? null, 'The failed task is refunded.');
assert_same(0.0, (float) $usageRow((string) $arkFail['json']['task_id'])['cost_amount'], 'Nothing is billed for a failed video.');
$wan = $call('/v1/videos/generations', ['model' => 'wan', 'prompt' => 'a dancer', 'duration' => 5]);
$wanDone = $refresh((string) $wan['json']['task_id']);
assert_same('failed', $wanDone['status'] ?? null, 'Failed Wan task is failed.');
assert_same('refund_failed', $billingOf((string) $wan['json']['task_id'])['reason'] ?? null, 'Wan failure is refunded too.');

// ================================================================ cancel
$queued = $call('/v1/videos/generations', ['model' => 'seedance', 'prompt' => 'stay queued', 'duration' => 5]);
$useContext();
$cancelled = (new GatewayService())->cancelTask((string) $queued['json']['task_id']);
assert_same(true, $cancelled['cancelled'] ?? null, 'A queued Seedance task is cancelled.');
assert_same('TASK_CANCELLED', $cancelled['error_code'] ?? null, 'Cancelled tasks say so.');
assert_true($lastRequest('DELETE', '/ark/api/v3/contents/generations/tasks/cgt-queued') !== null, 'Ark receives DELETE for the task.');
assert_same('refund_cancelled', $billingOf((string) $queued['json']['task_id'])['reason'] ?? null, 'A cancelled task is refunded.');
$running = $call('/v1/videos/generations', ['model' => 'seedance', 'prompt' => 'keep running', 'duration' => 5]);
$expectAbort(static function () use ($useContext, $running): void {
    $useContext();
    (new GatewayService())->cancelTask((string) $running['json']['task_id']);
}, 'TASK_NOT_CANCELLABLE', 'Ark refuses to cancel a running task: 409.');
assert_same([], $billingOf((string) $running['json']['task_id']), 'A refused cancel changes nothing.');
$h3 = $call('/v1/videos/generations', ['model' => 'hailuo-h3', 'prompt' => 'city', 'duration' => 6, 'resolution' => '768p']);
assert_same(202, $h3['status'], 'MiniMax H3 task is accepted. Got ' . $h3['body']);
$useContext();
assert_same(true, (new GatewayService())->cancelTask((string) $h3['json']['task_id'])['cancelled'] ?? null, 'A queued MiniMax H3 task is cancelled.');
assert_true($lastRequest('DELETE', '/v2/video_generation/mm-h3-queued') !== null, 'MiniMax receives DELETE /v2/video_generation/{id}.');
$expectAbort(static function () use ($useContext, $arkTask): void {
    $useContext();
    (new GatewayService())->cancelTask($arkTask);
}, 'TASK_NOT_CANCELLABLE', 'A finished task cannot be cancelled.');

// ================================================================ OpenAI Videos API
$created = $call('/v1/videos', ['model' => 'seedance', 'prompt' => 'waves on rocks', 'seconds' => '8', 'size' => '1920x1080', 'input_reference' => 'https://cdn.example.com/first.png']);
assert_same(200, $created['status'], 'POST /v1/videos answers 200 like OpenAI. Got ' . $created['body']);
assert_same('video', $created['json']['object'] ?? null, 'A video object is returned.');
assert_same('8', $created['json']['seconds'] ?? null, 'Seconds are echoed as a string.');
$sentCreate = $lastRequest('POST', '/ark/api/v3/contents/generations/tasks');
assert_same(8, $sentCreate['body']['duration'] ?? null, 'seconds -> duration.');
assert_same('16:9', $sentCreate['body']['ratio'] ?? null, 'size -> ratio.');
assert_same('1080p', $sentCreate['body']['resolution'] ?? null, 'size -> resolution.');
assert_same('https://cdn.example.com/first.png', $sentCreate['body']['content'][1]['image_url']['url'] ?? null, 'input_reference -> first frame.');
$videoId = (string) $created['json']['id'];
$useContext();
$api = new OpenAIVideoApi();
assert_same('in_progress', $api->retrieve($videoId)['status'] ?? null, 'GET /v1/videos/{id} refreshes: in_progress.');
$completed = $api->retrieve($videoId);
assert_same('completed', $completed['status'] ?? null, 'Then completed.');
assert_same('6', $completed['seconds'] ?? null, 'The measured length is reported.');
$content = $capture((new DownloadController())->serveAsset($api->contentAsset($videoId, 'video')));
assert_true(str_contains($content, 'ftypmp42'), 'GET /v1/videos/{id}/content returns the video bytes.');
assert_same(['id' => $videoId, 'object' => 'video.deleted', 'deleted' => true], $api->delete($videoId), 'DELETE /v1/videos/{id} answers like OpenAI.');

// ================================================================ video / audio understanding routing
$videoPart = ['type' => 'video_url', 'video_url' => ['url' => 'https://cdn.example.com/clip.mp4', 'fps' => 1]];
$audioPart = ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode(random_bytes(3000)), 'format' => 'wav']];
$toVl = $call('/v1/chat/completions', ['model' => 'vl', 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'what happens?'], $videoPart]]]]);
assert_same(422, $toVl['status'], 'Video input to an image-only model is refused before the upstream call.');
assert_true(str_contains((string) ($toVl['json']['error']['message'] ?? ''), 'video input'), 'The refusal names the missing modality. Got ' . $toVl['body']);
$toVideo = $call('/v1/chat/completions', ['model' => 'video-vl', 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'what happens?'], $videoPart]]]]);
assert_same(200, $toVideo['status'], 'A video_understanding model gets video input. Got ' . $toVideo['body']);
assert_same(['text', 'video_url'], $toVideo['json']['received_types'] ?? null, 'The video part is forwarded.');
assert_same(422, $call('/v1/chat/completions', ['model' => 'vl', 'messages' => [['role' => 'user', 'content' => [$audioPart]]]])['status'], 'Audio input to a vision model is refused.');
assert_same(200, $call('/v1/chat/completions', ['model' => 'audio-chat', 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'transcribe'], $audioPart]]]])['status'], 'An audio_understanding model gets audio input.');
assert_same(200, $call('/v1/chat/completions', ['model' => 'audio-chat', 'audio' => ['voice' => 'alloy', 'format' => 'wav'], 'modalities' => ['text', 'audio'], 'messages' => [['role' => 'user', 'content' => 'hi']]])['status'], 'Spoken-output options are not mistaken for audio input.');
assert_same(200, $call('/v1/chat/completions', ['model' => 'omni', 'messages' => [['role' => 'user', 'content' => [$videoPart, $audioPart]]]])['status'], 'A contract declaring video and audio input takes both.');
assert_same(200, $call('/v1/chat/completions', ['model' => 'vl', 'messages' => [['role' => 'user', 'content' => 'plain text']]])['status'], 'Plain text still reaches a vision model.');
$gemini = \TokenHub\Services\Gateway\InputModalities::count(['contents' => [['role' => 'user', 'parts' => [['text' => 'describe'], ['inline_data' => ['mime_type' => 'video/mp4', 'data' => 'AAAA']], ['file_data' => ['mime_type' => 'audio/wav', 'file_uri' => 'gs://x/a.wav']]]]]]);
assert_same(['image' => 0, 'video' => 1, 'audio' => 1], $gemini, 'Gemini inline/file parts are recognised by MIME type.');

App::redis()->delete($queue);
App::redis()->delete('schema:column:usage_cost_aggregates_day.currency');
\TokenHub\Support\SchemaInspector::forget();
if (is_dir($mediaRoot)) {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mediaRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($mediaRoot);
}
$appConfig->setValue(null, $originalConfig);
$appDatabase->setValue(null, $originalDatabase);
echo "VideoTaskLifecycleTest OK\n";
