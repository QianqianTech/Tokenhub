<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Query-time embedding calls are billed to the API key that caused them
 * (POST /v1/rag/search, MCP search, gateway `rag`), through the usage queue.
 */

use TokenHub\Services\RagEmbeddingMeter;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
$queue = 'tokenhub:embtest:' . bin2hex(random_bytes(4));
$_ENV['QUEUE_NAME'] = $queue;
(new ReflectionProperty(App::class, 'config'))->setValue(null, \TokenHub\Support\Config::default());
try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE providers (id INTEGER PRIMARY KEY, name TEXT, protocol TEXT, base_url TEXT, encrypted_secret TEXT, default_headers TEXT, timeout_ms INT, retry_count INT, status TEXT)');
$pdo->exec('CREATE TABLE models (id INTEGER PRIMARY KEY, provider_id INT, name TEXT, external_name TEXT, capability TEXT, status TEXT)');
$pdo->exec("INSERT INTO providers VALUES (3, 'Embedder', 'openai', 'http://127.0.0.1:1', NULL, NULL, 1000, 1, 'active')");
$pdo->exec("INSERT INTO models VALUES (31, 3, 'bge-m3', 'bge-m3-up', 'embedding', 'active')");
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);

$responses = [];
$requested = [];
$service = new RagEmbeddingProviderService(null, static function (array $route, string $path, array $payload) use (&$responses, &$requested): array {
    $requested[] = [$path, $payload];
    return array_shift($responses);
});
$config = ['provider_id' => 3, 'model_id' => 31, 'dimension' => 2];
$jobs = static function () use ($queue): array {
    $out = [];
    foreach (App::redis()->connection()->lRange($queue, 0, -1) ?: [] as $raw) {
        $job = json_decode((string) $raw, true);
        if (($job['type'] ?? null) === 'usage.write') {
            $out[] = $job['record'];
        }
    }
    return $out;
};

// No API key in context (vectorization worker): not metered.
$context = RequestContext::fromHeaders([]);
(new ReflectionProperty(App::class, 'context'))->setValue(null, $context);
$responses[] = ['ok' => true, 'status' => 200, 'json' => ['data' => [['embedding' => [0.1, 0.2]]], 'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7]]];
$service->embed('text', ['text' => 'index me'], $config);
assert_same([], $jobs(), 'Calls without an API key are not billed.');

// API-key request: measured usage is billed to that key.
$context->setApiKey(['id' => 42, 'user_id' => 5, 'department_id' => 9]);
$responses[] = ['ok' => true, 'status' => 200, 'json' => ['data' => [['embedding' => [0.1, 0.2]]], 'usage' => ['prompt_tokens' => 12, 'total_tokens' => 12]]];
assert_same([0.1, 0.2], $service->embed('text', ['text' => '差旅报销'], $config), 'Retrieval still gets the vector.');
$records = $jobs();
assert_same(1, count($records), 'One usage record per provider call.');
$record = $records[0];
assert_same([42, 5, 9], [$record['api_key_id'], $record['user_id'], $record['department_id']], 'Billed to the calling key, user and department.');
assert_same([3, 31, 'bge-m3'], [(int) $record['provider_id'], (int) $record['model_id'], $record['model_name']], 'Priced with the embedding model.');
assert_same(['embedding', RagEmbeddingMeter::ENDPOINT, 'success'], [$record['capability'], $record['endpoint'], $record['status']], 'Recorded as a RAG query embedding.');
assert_same([12, 0, 'measured'], [(int) $record['input_tokens'], (int) $record['output_tokens'], $record['usage_source']], 'Upstream usage is used when present.');
assert_true(str_starts_with($record['request_id'], $context->requestId() . '-emb'), 'Request id is derived from, but distinct from, the caller request.');
assert_same('bge-m3-up', $requested[1][1]['model'], 'Upstream receives the provider model id.');

// No usage block: estimate; second call gets its own id.
$responses[] = ['ok' => true, 'status' => 200, 'json' => ['data' => [['embedding' => [0.3, 0.4]]]]];
$service->embed('text', ['text' => 'another query'], $config);
$records = $jobs();
$second = $records[0]['request_id'] === $record['request_id'] ? $records[1] : $records[0];
assert_true($second['request_id'] !== $record['request_id'], 'Each call has a unique request id.');
assert_same('estimated', $second['usage_source'], 'Missing upstream usage is estimated.');
assert_true((int) $second['input_tokens'] > 0, 'The estimate counts the query.');

// Failed call: recorded as failed with no tokens, and retrieval still fails.
$responses[] = ['ok' => false, 'status' => 503, 'json' => null, 'error_code' => 'PROVIDER_ERROR'];
$failed = false;
try {
    $service->embed('text', ['text' => 'x'], $config);
} catch (RuntimeException) {
    $failed = true;
}
assert_true($failed, 'A failed embedding still fails retrieval.');
$failedRecords = array_values(array_filter($jobs(), static fn (array $r): bool => $r['status'] === 'failed'));
assert_same(1, count($failedRecords), 'Failed calls are recorded.');
assert_same(['PROVIDER_ERROR', 0], [$failedRecords[0]['error_code'], (int) $failedRecords[0]['input_tokens']], 'Failed calls carry the error and no tokens.');

App::redis()->delete($queue);
echo "OK\n";
