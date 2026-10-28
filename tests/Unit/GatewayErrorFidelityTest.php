<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Error fidelity: a request the upstream rejects must reach the client with
 * the same status, type and (sanitized) message a direct call would return,
 * for plain and streamed requests, on both the OpenAI and Anthropic
 * protocols. Upstream account problems stay generic.
 */

use TokenHub\Services\GatewayService;
use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('proc_open')) {
    echo "SKIP sqlite or proc_open unavailable\n";
    return;
}
$queue = 'tokenhub:gwerr:' . bin2hex(random_bytes(4));
$_ENV['QUEUE_NAME'] = $queue;
$_ENV['OUTBOUND_PRIVATE_ALLOWLIST'] = '127.0.0.1/32';
$_ENV['PROVIDER_RETRY_MAX_BACKOFF_MS'] = '1';
$_ENV['GATEWAY_MAX_CHANNELS_PER_REQUEST'] = '2';
$_ENV['APP_KEY'] = 'gateway-flow-test-key-0123456789abcdef';
// bin/run-tests.php runs every file in one process: reload configuration so
// the env above applies (the queue name), and restore the singletons at the end.
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


// Fake upstream
$port = random_int(50001, 60000);
@unlink(sys_get_temp_dir() . '/tokenhub-fake-upstream-' . $port . '.json');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/upstream/server.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static fn () => proc_terminate($server));
$base = 'http://127.0.0.1:' . $port;
for ($i = 0; $i < 100; $i++) {
    // Wait until the fake upstream answers HTTP, not just accepts TCP.
    if (@file_get_contents($base . '/ok/v1/chat/completions', false, stream_context_create(['http' => ['method' => 'POST', 'content' => '{}', 'ignore_errors' => true, 'timeout' => 1]])) !== false) {
        break;
    }
    usleep(50000);
}

// SQLite stand-in for the tables the gateway reads synchronously.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('FIELD', static function (...$args): int {
    $needle = array_shift($args);
    $index = array_search($needle, $args, true);
    return $index === false ? 0 : $index + 1;
});
$pdo->sqliteCreateFunction('JSON_CONTAINS', static fn ($haystack, $needle): int => in_array(json_decode((string) $needle, true), json_decode((string) $haystack, true) ?: [], true) ? 1 : 0);
$pdo->exec('CREATE TABLE providers (id INTEGER PRIMARY KEY, name TEXT, protocol TEXT, base_url TEXT, encrypted_secret TEXT, status TEXT, timeout_ms INT, retry_count INT, default_headers TEXT)');
$pdo->exec('CREATE TABLE models (id INTEGER PRIMARY KEY, provider_id INT, name TEXT, external_name TEXT, capability TEXT, context_length INT, status TEXT, route_priority INT)');
$pdo->exec('CREATE TABLE quota_policies (id INTEGER PRIMARY KEY, name TEXT, period TEXT, status TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, audio_second_limit INT, tts_char_limit INT, image_limit INT, video_second_limit INT, cost_limit REAL, concurrency_limit INT)');
$pdo->exec('CREATE TABLE quota_bindings (id INTEGER PRIMARY KEY, quota_policy_id INT, target_type TEXT, target_id INT)');
$pdo->exec('CREATE TABLE api_key_model_quotas (id INTEGER PRIMARY KEY, api_key_id INT, model_id INT, period TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, status TEXT)');
$pdo->exec('CREATE TABLE desensitization_policies (id INTEGER PRIMARY KEY, status TEXT, scope_type TEXT, scope_id INT)');
$pdo->exec('CREATE TABLE multimodal_tasks (id INTEGER PRIMARY KEY, task_id TEXT)');
$insertProvider = $pdo->prepare('INSERT INTO providers (id, name, protocol, base_url, status, timeout_ms, retry_count, default_headers) VALUES (?, ?, ?, ?, "active", 2000, 1, ?)');
$insertProvider->execute([1, 'BadOpenAI', 'openai', $base . '/badreq', null]);
$insertProvider->execute([2, 'BadAnthropic', 'anthropic', $base . '/badreq', null]);
$insertProvider->execute([3, 'Billing', 'openai', $base . '/billing', null]);
$insertProvider->execute([4, 'Denied', 'openai', $base . '/denied', null]);
$insertProvider->execute([5, 'Limited', 'openai', $base . '/limited', null]);
$insertModel = $pdo->prepare('INSERT INTO models (id, provider_id, name, external_name, capability, status, route_priority) VALUES (?, ?, ?, ?, ?, "active", ?)');
$insertModel->execute([110, 1, 'public-gpt', 'badreq-up', 'text_generation', 1]);
$insertModel->execute([120, 2, 'public-claude', 'claude-up', 'text_generation', 1]);
$insertModel->execute([130, 3, 'billing', 'billing-up', 'text_generation', 1]);
$insertModel->execute([140, 4, 'denied', 'denied-up', 'text_generation', 1]);
$insertModel->execute([150, 5, 'limited', 'limited-up', 'text_generation', 1]);
foreach ([110, 120, 130, 140, 150] as $modelId) {
    App::redis()->delete('route_health:' . $modelId);
}
\TokenHub\Support\ConfigVersion::bump('global', 'gateway-error-test');
\TokenHub\Support\ConfigVersion::refresh();

$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);

$call = static function (string $path, array $body, array $allowedModelIds): array {
    $context = RequestContext::fromHeaders([]);
    (new ReflectionProperty(App::class, 'context'))->setValue(null, $context);
    $context->setApiKey(['id' => 8001, 'user_id' => 1, 'department_id' => 1, 'allowed_model_ids' => $allowedModelIds, 'allowed_model_names' => []]);
    $request = new Request('POST', $path, ['REMOTE_ADDR' => '127.0.0.1'], [], $body, (string) json_encode($body), $context);
    $response = (new GatewayService())->handle($request);
    ob_start();
    ob_start();
    $response->send();
    $inner = (string) ob_get_clean();
    $output = (string) ob_get_clean() . $inner;

    return ['status' => $response->status, 'headers' => $response->headers, 'body' => $output, 'json' => json_decode($output, true)];
};
$messages = [['role' => 'user', 'content' => 'hi']];
// bin/run-tests.php runs every file in one CLI process whose output already
// started, so PHP can no longer change the status line there. The stream
// then reports in-band, which is what a real client gets once bytes flowed.
$canReplace = !headers_sent();

// 1. OpenAI protocol, non-streaming: status, type, param and code survive;
//    the URL and the upstream model id do not.
$plain = $call('/v1/chat/completions', ['model' => 'public-gpt', 'messages' => $messages], [110]);
assert_same(400, $plain['status'], 'The upstream 400 stays a 400.');
assert_same('PROVIDER_REJECTED', $plain['json']['error']['code'] ?? null, 'The TokenHub error code is unchanged for existing clients.');
assert_same('invalid_request_error', $plain['json']['error']['type'] ?? null, 'The upstream error type is passed through.');
assert_same('context_length_exceeded', $plain['json']['error']['upstream_code'] ?? null, 'The upstream error code is passed through.');
assert_same('messages', $plain['json']['error']['param'] ?? null, 'The offending parameter is passed through.');
$message = (string) ($plain['json']['error']['message'] ?? '');
assert_true(str_contains($message, 'maximum context length is 8192'), 'The upstream message reaches the client.');
assert_true(str_contains($message, 'public-gpt') && !str_contains($message, 'badreq-up'), 'The upstream model id is replaced by the public model name.');
assert_true(!str_contains($message, 'platform.example.com'), 'URLs in upstream messages are removed.');

// 2. OpenAI protocol, streaming, rejected before the first byte: a real HTTP
//    error with a JSON body instead of 200 + text/event-stream.
$streamed = $call('/v1/chat/completions', ['model' => 'public-gpt', 'stream' => true, 'messages' => $messages], [110]);
if ($canReplace) {
    assert_same(400, $streamed['status'], 'A stream rejected before its first byte answers with the upstream status.');
    assert_same('application/json; charset=utf-8', $streamed['headers']['Content-Type'] ?? null, 'The rejection is a JSON document, not an event stream.');
    assert_true(!isset($streamed['headers']['X-Accel-Buffering']), 'Stream-only headers are dropped.');
    assert_same('context_length_exceeded', $streamed['json']['error']['upstream_code'] ?? null, 'Streamed rejections carry the same details.');
} else {
    assert_true(str_contains($streamed['body'], 'event: error') && str_contains($streamed['body'], 'maximum context length is 8192'), 'Once output started the rejection is reported in-band with the upstream message.');
}

// 3. Anthropic protocol (Claude Code): Anthropic error shape and the exact
//    "prompt is too long" text that triggers automatic compaction.
$anthropicBody = ['model' => 'public-claude', 'max_tokens' => 16, 'messages' => $messages];
$claude = $call('/v1/messages', $anthropicBody, [120]);
assert_same(400, $claude['status'], 'Anthropic rejections keep their status.');
assert_same('error', $claude['json']['type'] ?? null, 'Anthropic endpoints answer with the Anthropic error envelope.');
assert_same('invalid_request_error', $claude['json']['error']['type'] ?? null, 'Anthropic error type is kept.');
assert_same('prompt is too long: 210000 tokens > 200000 maximum', $claude['json']['error']['message'] ?? null, 'The compaction trigger text is unchanged.');
$claudeStream = $call('/v1/messages', [...$anthropicBody, 'stream' => true], [120]);
if ($canReplace) {
    assert_same(400, $claudeStream['status'], 'Streamed Anthropic rejections are real HTTP errors.');
    assert_same('prompt is too long: 210000 tokens > 200000 maximum', $claudeStream['json']['error']['message'] ?? null, 'Streamed Anthropic rejections keep the message.');
} else {
    assert_true(str_contains($claudeStream['body'], 'prompt is too long: 210000 tokens > 200000 maximum') && str_contains($claudeStream['body'], '"invalid_request_error"'), 'In-band Anthropic errors keep the message and the Anthropic type.');
}

// 4. Upstream account problems never leak: billing text, key errors and
//    rate limits on TokenHub's own upstream account stay generic.
$billing = $call('/v1/chat/completions', ['model' => 'billing', 'messages' => $messages], [130]);
assert_same(400, $billing['status'], 'Billing rejections keep their status.');
assert_same('Upstream provider rejected the request', $billing['json']['error']['message'] ?? null, 'Billing messages about the upstream account are not shown to callers.');
$denied = $call('/v1/chat/completions', ['model' => 'denied', 'messages' => $messages], [140]);
assert_same(502, $denied['status'], 'An upstream credential failure is a gateway error, not the caller\'s 401.');
assert_true(!str_contains($denied['body'], 'sk-live') && !str_contains($denied['body'], 'invalid_api_key'), 'Upstream credential details never reach the caller.');
$limited = $call('/v1/chat/completions', ['model' => 'limited', 'stream' => true, 'messages' => $messages], [150]);
if ($canReplace) {
    assert_same(429, $limited['status'], 'An upstream 429 before the first byte is a real 429.');
    assert_same('PROVIDER_RATE_LIMITED', $limited['json']['error']['code'] ?? null, 'Rate limits keep the TokenHub code.');
} else {
    assert_true(str_contains($limited['body'], 'PROVIDER_RATE_LIMITED'), 'In-band rate limit errors keep the TokenHub code.');
}

App::redis()->delete($queue);
$appConfig->setValue(null, $originalConfig);
$appDatabase->setValue(null, $originalDatabase);

echo "GatewayErrorFidelityTest OK\n";
