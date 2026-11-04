<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/BusinessSqliteFixture.php';

/*
 * BIZ-026: OpenRouterGenerationService against a fake /generation endpoint.
 * - the summary keeps the authoritative cost, native tokens and token delta;
 * - only OpenRouter "gen-" rows older than a minute are reconciled, once;
 * - a lagging (404) or failing generation lookup leaves the row for later.
 */

use TokenHub\Services\OpenRouterGenerationService;
use TokenHub\Services\OutboundUrlPolicy;
use TokenHub\Services\ProviderHttpClient;
use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\SchemaInspector;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('proc_open')) {
    echo "SKIP sqlite or proc_open unavailable\n";
    return;
}

$service = new OpenRouterGenerationService();
$summary = $service->summarize(['data' => ['total_cost' => '0.5', 'provider_name' => 'P', 'model' => 'm', 'native_tokens_prompt' => 10, 'native_tokens_completion' => 5]], ['input_tokens' => 9, 'output_tokens' => 5, 'cost_amount' => '0.4']);
assert_same('0.5', $summary['upstream_cost'], 'The authoritative cost is kept as a string.');
assert_same(1, $summary['token_delta'], 'Token delta = native - recorded.');
assert_same('0.4', $summary['recorded_cost_amount'], 'The recorded cost is kept for comparison.');
$partial = $service->summarize(['total_cost' => 'n/a', 'native_tokens_prompt' => 3], []);
assert_same(null, $partial['upstream_cost'], 'A non-numeric cost is ignored.');
assert_same(null, $partial['token_delta'], 'No delta without both native counts.');

$port = random_int(40001, 50000);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/upstream/openrouter-generation.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static function () use ($server): void {
    proc_terminate($server);
});
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($socket) {
        fclose($socket);
        break;
    }
    usleep(50000);
}

$pdo = business_sqlite();
$pdo->exec('CREATE TABLE providers (id INTEGER PRIMARY KEY, base_url TEXT, encrypted_secret TEXT, default_headers TEXT, protocol TEXT, timeout_ms INT, retry_count INT)');
$pdo->exec('CREATE TABLE usage_records (id INTEGER PRIMARY KEY, upstream_request_id TEXT, provider_id INT, model_id INT, input_tokens INT, output_tokens INT, cost_amount TEXT, upstream_cost TEXT, upstream_provider TEXT, reconciled_at TEXT, reconciliation TEXT, created_at TEXT)');
// The base URL contains "openrouter.ai" in its path so the provider filter matches the fake server.
$pdo->prepare('INSERT INTO providers VALUES (1, ?, NULL, NULL, "openai", 2000, 0), (2, "https://api.other.example", NULL, NULL, "openai", 2000, 0)')->execute(['http://127.0.0.1:' . $port . '/openrouter.ai']);
$old = gmdate('Y-m-d H:i:s', time() - 600);
$insert = $pdo->prepare('INSERT INTO usage_records (id, upstream_request_id, provider_id, model_id, input_tokens, output_tokens, cost_amount, created_at) VALUES (?, ?, ?, 1, 10, 8, "0.001", ?)');
$insert->execute([1, 'gen-ok', 1, $old]);
$insert->execute([2, 'gen-lagging', 1, $old]);
$insert->execute([3, 'gen-broken', 1, $old]);
$insert->execute([4, 'gen-recent', 1, gmdate('Y-m-d H:i:s')]);
$insert->execute([5, 'gen-other', 2, $old]);
$insert->execute([6, 'chatcmpl-1', 1, $old]);

business_use_sqlite($pdo, ['usage_records.reconciled_at' => true]);

$client = new ProviderHttpClient(new OutboundUrlPolicy(null, ['127.0.0.1/32']));
$reconciler = new OpenRouterGenerationService($client);
assert_same(1, $reconciler->reconcilePending(), 'Only the available OpenRouter generation is reconciled.');
$row = $pdo->query('SELECT * FROM usage_records WHERE id = 1')->fetch();
$stored = json_decode((string) $row['reconciliation'], true);
assert_true($row['reconciled_at'] !== null, 'The row is marked reconciled.');
assert_same('FakeVendor', $row['upstream_provider'], 'The serving provider is stored.');
assert_same(2, $stored['token_delta'], 'Native tokens (12 + 8) vs recorded (10 + 8).');
assert_same(3, $stored['native_tokens_reasoning'], 'Reasoning tokens are kept.');
foreach ([2 => 'lagging generation (404)', 3 => 'failing lookup', 4 => 'too recent row', 5 => 'non-OpenRouter provider', 6 => 'non-generation id'] as $id => $why) {
    assert_same(null, $pdo->query('SELECT reconciled_at FROM usage_records WHERE id = ' . $id)->fetchColumn() ?: null, 'Not reconciled: ' . $why . '.');
}
assert_same(false, $reconciler->reconcileRow(['id' => 1, 'upstream_request_id' => 'gen-ok', 'base_url' => 'http://127.0.0.1:' . $port . '/openrouter.ai', 'protocol' => 'openai', 'timeout_ms' => 2000, 'retry_count' => 0]), 'A row is never reconciled twice.');

(new ReflectionProperty(SchemaInspector::class, 'memo'))->setValue(null, ['usage_records.reconciled_at' => false]);
assert_same(0, $reconciler->reconcilePending(), 'Before the migration nothing is attempted.');

echo "OK\n";
