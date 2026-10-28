<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * End-to-end gateway flow against a fake upstream, with the relational store
 * emulated by SQLite (MySQL-only functions registered as UDFs). Covers the
 * request lifecycle that pure unit tests cannot: channel-level authorization,
 * failover, aggregator policy, native-route rejection, streaming terminal
 * state and the usage record handed to the queue.
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
$queue = 'tokenhub:gwtest:' . bin2hex(random_bytes(4));
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
$port = random_int(40001, 50000);
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
$insertProvider->execute([1, 'Limited', 'openai', $base . '/limited', null]);
$insertProvider->execute([2, 'Healthy', 'openai', $base . '/ok', null]);
$insertProvider->execute([3, 'Streamer', 'openai', $base . '/stream', null]);
$insertProvider->execute([4, 'Native', 'native', $base . '/native', null]);
$insertProvider->execute([5, 'Router', 'openai', $base . '/ok', '{"tokenhub_client_routing":"restricted","tokenhub_provider_policy":{"data_collection":"deny"}}']);
$insertModel = $pdo->prepare('INSERT INTO models (id, provider_id, name, external_name, capability, status, route_priority) VALUES (?, ?, ?, ?, ?, "active", ?)');
$insertModel->execute([10, 1, 'shared', 'shared-up-a', 'text_generation', 1]);
$insertModel->execute([20, 2, 'shared', 'shared-up-b', 'text_generation', 2]);
$insertModel->execute([30, 3, 'streamy', 'streamy-up', 'text_generation', 1]);
$insertModel->execute([40, 4, 'native-tts', 'native-tts', 'tts', 1]);
$insertModel->execute([50, 5, 'routed', 'vendor/routed', 'text_generation', 1]);
$insertModel->execute([51, 5, 'routed-fallback', 'vendor/fallback', 'text_generation', 1]);
$insertModel->execute([60, 2, 'reranker', 'bge-reranker-up', 'rerank', 1]);
$insertModel->execute([61, 2, 'guard', 'omni-moderation-up', 'moderation', 1]);
$insertModel->execute([62, 2, 'legacy', 'legacy-up', 'text_generation', 1]);
$insertModel->execute([63, 3, 'legacy-stream', 'legacy-stream-up', 'text_generation', 1]);

// Route/quota/policy caches and circuit state from earlier runs point at
// other fake ports or would reorder channels.
foreach ([10, 20, 30, 40, 50, 51, 60, 61, 62, 63] as $modelId) {
    App::redis()->delete('route_health:' . $modelId);
}
\TokenHub\Support\ConfigVersion::bump('global', 'gateway-flow-test');
\TokenHub\Support\ConfigVersion::refresh();

$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);

$call = static function (string $path, array $body, array $allowedModelIds, int $apiKeyId = 7001) use ($queue): array {
    $context = RequestContext::fromHeaders([]);
    (new ReflectionProperty(App::class, 'context'))->setValue(null, $context);
    $context->setApiKey(['id' => $apiKeyId, 'user_id' => 1, 'department_id' => 1, 'allowed_model_ids' => $allowedModelIds, 'allowed_model_names' => []]);
    $request = new Request('POST', $path, ['REMOTE_ADDR' => '127.0.0.1'], [], $body, (string) json_encode($body), $context);
    $response = (new GatewayService())->handle($request);
    // Two buffers: the stream writer calls ob_flush(), which moves bytes
    // from the inner buffer into the outer one instead of to stdout.
    ob_start();
    ob_start();
    $response->send();
    $inner = (string) ob_get_clean();
    $output = (string) ob_get_clean() . $inner;
    $record = null;
    foreach (App::redis()->connection()->lRange($queue, 0, -1) ?: [] as $raw) {
        $job = json_decode((string) $raw, true);
        if (($job['record']['request_id'] ?? null) === $context->requestId()) {
            $record = $job['record'];
        }
    }

    return ['status' => $response->status, 'body' => $output, 'json' => json_decode($output, true), 'record' => $record];
};

// 1. Channel-level authorization: the key only holds channel 20, so the
//    higher-priority channel 10 with the same name is never used.
$authorized = $call('/v1/chat/completions', ['model' => 'shared', 'messages' => [['role' => 'user', 'content' => 'hi']]], [20]);
assert_same(200, $authorized['status'], 'Authorized channel serves the request.');
assert_same(20, (int) $authorized['record']['model_id'], 'Routing never leaves the granted channel set.');
assert_same('shared-up-b', $authorized['json']['received']['model'] ?? null, 'Upstream receives the channel\'s upstream model id.');
assert_same(11, (int) $authorized['record']['input_tokens'], 'Measured usage is recorded.');
assert_same('measured', $authorized['record']['usage_source'] ?? null, 'Usage provenance is recorded.');
assert_same('gen-ok-1', $authorized['record']['upstream_request_id'] ?? null, 'Upstream generation id is kept for reconciliation.');
assert_same('FakeVendor', $authorized['record']['upstream_provider'] ?? null, 'Actual upstream vendor is kept.');
assert_same('TokenHub', $authorized['json']['provider'] ?? null, 'Clients see TokenHub as the vendor.');
assert_true(isset($authorized['record']['content_encrypted']) && !isset($authorized['record']['content']), 'Prompt and output travel through the queue encrypted.');

// 2. Failover: channel 10 answers 429, the request moves to channel 20.
$failover = $call('/v1/chat/completions', ['model' => 'shared', 'messages' => [['role' => 'user', 'content' => 'hi']]], [10, 20]);
assert_same(200, $failover['status'], 'Retryable upstream failure fails over to the next authorized channel.');
assert_same(2, count($failover['record']['attempts'] ?? []), 'Both channel attempts are recorded.');
assert_same(429, $failover['record']['attempts'][0]['status'] ?? null, 'The failed attempt keeps its upstream status.');

// 3. No grant for the name: explicit MODEL_FORBIDDEN, no upstream call.
$forbidden = $call('/v1/chat/completions', ['model' => 'shared', 'messages' => []], [30]);
assert_same(403, $forbidden['status'], 'Ungranted model is forbidden.');
assert_same('MODEL_FORBIDDEN', $forbidden['json']['error']['code'] ?? null, 'Diagnosis distinguishes forbidden from missing.');
assert_same(404, $call('/v1/chat/completions', ['model' => 'nope', 'messages' => []], [20])['status'], 'Unknown model is 404 MODEL_NOT_FOUND.');

// 4. Native channels have no executor: 501 instead of a fake success.
$native = $call('/v1/audio/speech', ['model' => 'native-tts', 'input' => 'hello'], [40]);
assert_same(501, $native['status'], 'Native-only capability is rejected before queueing.');
assert_same('CAPABILITY_UNAVAILABLE', $native['json']['error']['code'] ?? null, 'Rejection says why.');

// 5. Aggregator policy: client cannot widen routing; server policy is injected.
$widened = $call('/v1/chat/completions', ['model' => 'routed', 'messages' => [], 'provider' => ['data_collection' => 'allow']], [50]);
assert_same(422, $widened['status'], 'Client cannot relax the channel data policy.');
$fallback = $call('/v1/chat/completions', ['model' => 'routed', 'messages' => [], 'models' => ['routed-fallback']], [50, 51]);
assert_same(['vendor/fallback'], $fallback['json']['received']['models'] ?? null, 'Authorized fallback models are rewritten to upstream ids.');
assert_same('deny', $fallback['json']['received']['provider']['data_collection'] ?? null, 'Server-side provider policy reaches the upstream.');
assert_same(403, $call('/v1/chat/completions', ['model' => 'routed', 'messages' => [], 'models' => ['routed-fallback']], [50])['status'], 'Unauthorized fallback models are refused.');

// 6. Streaming through the gateway: split chunks, measured usage, success.
$stream = $call('/v1/chat/completions', ['model' => 'streamy', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hi']]], [30]);
assert_true(str_contains($stream['body'], '[DONE]'), 'Stream bytes reach the client.');
assert_same('success', $stream['record']['status'] ?? null, 'Completed stream is recorded as success.');
assert_same(7, (int) ($stream['record']['total_tokens'] ?? 0), 'Stream usage comes from the final usage frame.');
assert_same('eof', $stream['record']['stream_outcome'] ?? null, 'Transport outcome is recorded.');

// 7. Tool-call contract is checked before routing.
$orphan = $call('/v1/chat/completions', ['model' => 'shared', 'messages' => [['role' => 'tool', 'tool_call_id' => 'x', 'content' => 'r']]], [20]);
assert_same('TOOL_CALL_MISMATCH', $orphan['json']['error']['code'] ?? null, 'Orphan tool results are rejected early.');

// 8. Model quota rejection refunds the general quota completely (TH-007).
$keyId = random_int(800000, 899999);
$policyId = random_int(900000, 999999);
$pdo->exec("INSERT INTO quota_policies (id, name, period, status, request_limit) VALUES ({$policyId}, 'unit', 'day', 'active', 100)");
$pdo->exec("INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES ({$policyId}, 'api_key', {$keyId})");
$pdo->exec("INSERT INTO api_key_model_quotas (api_key_id, model_id, period, total_token_limit, status) VALUES ({$keyId}, 20, 'day', 1, 'active')");
$bucket = (new \TokenHub\Services\QuotaService())->periodWindow('day')['bucket'];
$requestsKey = "quota:{$policyId}:api_key:{$keyId}:requests:{$bucket}";
$rejected = $call('/v1/chat/completions', ['model' => 'shared', 'messages' => [['role' => 'user', 'content' => 'hi']]], [20], $keyId);
assert_same('MODEL_QUOTA_EXCEEDED', $rejected['json']['error']['code'] ?? null, 'Model quota blocks the request.');
assert_same(0.0, (float) (App::redis()->get($requestsKey) ?? 0), 'The general request reservation is refunded when the model quota rejects.');

// 9. Hard output budget: max_tokens counts against total_token_limit before the call.
$budgetKey = $keyId + 1;
$budgetPolicy = $policyId + 1;
$pdo->exec("INSERT INTO quota_policies (id, name, period, status, total_token_limit) VALUES ({$budgetPolicy}, 'budget', 'day', 'active', 500)");
$pdo->exec("INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES ({$budgetPolicy}, 'api_key', {$budgetKey})");
$overBudget = $call('/v1/chat/completions', ['model' => 'shared', 'max_tokens' => 1000, 'messages' => [['role' => 'user', 'content' => 'hi']]], [20], $budgetKey);
assert_same('QUOTA_EXCEEDED', $overBudget['json']['error']['code'] ?? null, 'Declared output that cannot fit the hard budget is refused up front.');
$withinBudget = $call('/v1/chat/completions', ['model' => 'shared', 'max_tokens' => 100, 'messages' => [['role' => 'user', 'content' => 'hi']]], [20], $budgetKey);
assert_same(200, $withinBudget['status'], 'A request whose bound fits is served.');
assert_same(15.0, (float) App::redis()->get("quota:{$budgetPolicy}:api_key:{$budgetKey}:total_tokens:{$bucket}"), 'After settlement the counter holds measured usage, not the reservation.');

// 10. /v1/rerank: routed to rerank models only, usage from total_tokens.
$rerank = $call('/v1/rerank', ['model' => 'reranker', 'query' => '报销流程', 'documents' => ['差旅报销需要发票', '年假规定']], [60]);
assert_same(200, $rerank['status'], 'Rerank requests are proxied.');
assert_same('bge-reranker-up', $rerank['json']['received']['model'] ?? null, 'Rerank upstream receives the channel model id.');
assert_same(0.9, $rerank['json']['results'][0]['relevance_score'] ?? null, 'Rerank results pass through.');
assert_same('rerank', $rerank['record']['capability'] ?? null, 'Rerank usage is recorded under its own capability.');
assert_same(42, (int) ($rerank['record']['input_tokens'] ?? 0), 'Rerank total_tokens counts as input.');
assert_same(0, (int) ($rerank['record']['output_tokens'] ?? -1), 'Rerank has no output tokens.');
assert_same('measured', $rerank['record']['usage_source'] ?? null, 'Rerank usage is measured.');
$wrongEndpoint = $call('/v1/rerank', ['model' => 'shared', 'query' => 'q', 'documents' => ['a']], [20]);
assert_same('MODEL_CAPABILITY_UNSUPPORTED', $wrongEndpoint['json']['error']['code'] ?? null, 'Chat models are not reachable through /v1/rerank.');
$wrongModel = $call('/v1/chat/completions', ['model' => 'reranker', 'messages' => [['role' => 'user', 'content' => 'hi']]], [60]);
assert_same('MODEL_CAPABILITY_UNSUPPORTED', $wrongModel['json']['error']['code'] ?? null, 'Rerank models are not reachable through chat.');

// 11. /v1/moderations: no usage block upstream, estimate is recorded.
$moderation = $call('/v1/moderations', ['model' => 'guard', 'input' => 'some text to classify'], [61]);
assert_same(200, $moderation['status'], 'Moderation requests are proxied.');
assert_same(false, $moderation['json']['results'][0]['flagged'] ?? null, 'Moderation results pass through.');
assert_same('moderation', $moderation['record']['capability'] ?? null, 'Moderation usage has its own capability.');
assert_same('estimated', $moderation['record']['usage_source'] ?? null, 'Missing upstream usage falls back to the estimate.');
assert_true((int) ($moderation['record']['input_tokens'] ?? 0) > 0, 'The estimate counts the input.');

// 12. Legacy /v1/completions, plain and streamed.
$legacy = $call('/v1/completions', ['model' => 'legacy', 'prompt' => 'Say done'], [62]);
assert_same(200, $legacy['status'], 'Legacy completions are proxied.');
assert_same('done', $legacy['json']['choices'][0]['text'] ?? null, 'Completion text passes through.');
assert_same(8, (int) ($legacy['record']['total_tokens'] ?? 0), 'Completion usage is measured.');
$legacyStream = $call('/v1/completions', ['model' => 'legacy-stream', 'prompt' => 'hi', 'stream' => true], [63]);
assert_true(str_contains($legacyStream['body'], '[DONE]'), 'Legacy stream reaches the client.');
assert_same(6, (int) ($legacyStream['record']['total_tokens'] ?? 0), 'Gateway asks legacy streams for usage and records it.');
assert_same('success', $legacyStream['record']['status'] ?? null, 'Legacy stream completes.');

// 13. rag is refused where there is no conversation to inject into.
$ragOnRerank = $call('/v1/rerank', ['model' => 'reranker', 'query' => 'q', 'documents' => ['a'], 'rag' => ['enabled' => true]], [60]);
assert_same(422, $ragOnRerank['status'], 'rag on rerank is rejected before the upstream call.');
assert_same('RAG_ENDPOINT_UNSUPPORTED', $ragOnRerank['json']['error']['code'] ?? null, 'Rejection names the reason.');
assert_same(200, $call('/v1/rerank', ['model' => 'reranker', 'query' => 'q', 'documents' => ['a'], 'rag' => ['enabled' => false]], [60])['status'], 'A disabled rag object is ignored, not rejected.');

// 14. A blocking desensitization policy only blocks when something matched;
//     clean requests must reach the upstream intact.
$pdo->exec('ALTER TABLE desensitization_policies ADD COLUMN action TEXT');
$pdo->exec('ALTER TABLE desensitization_policies ADD COLUMN parsers TEXT');
$pdo->exec('CREATE TABLE IF NOT EXISTS desensitization_events (id INTEGER PRIMARY KEY, policy_id INT, request_id TEXT, target_type TEXT, action TEXT, match_count INT, summary TEXT)');
$blockKey = random_int(700000, 799999);
$pdo->exec("INSERT INTO desensitization_policies (status, scope_type, scope_id, action) VALUES ('active', 'api_key', {$blockKey}, 'block')");
$clean = $call('/v1/chat/completions', ['model' => 'shared', 'messages' => [['role' => 'user', 'content' => '你好']]], [20], $blockKey);
assert_same(200, $clean['status'], 'Clean content passes a blocking policy.');
assert_same('shared-up-b', $clean['json']['received']['model'] ?? null, 'The upstream receives the full payload, not an empty one.');
assert_same('你好', $clean['json']['received']['messages'][0]['content'] ?? null, 'Messages are forwarded unchanged.');
$dirty = $call('/v1/chat/completions', ['model' => 'shared', 'messages' => [['role' => 'user', 'content' => 'my token: abc123']]], [20], $blockKey);
assert_same('DESENSITIZATION_BLOCKED', $dirty['json']['error']['code'] ?? null, 'Matching content is blocked.');

App::redis()->delete($queue);
$appConfig->setValue(null, $originalConfig);
$appDatabase->setValue(null, $originalDatabase);
