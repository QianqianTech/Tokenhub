<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\Stream\StreamAccumulator;
use TokenHub\Services\Gateway\UsageNormalizer;
use TokenHub\Services\OutboundUrlPolicy;
use TokenHub\Services\ProviderHttpClient;
use TokenHub\Services\Providers\OpenAIAdapter;

if (!function_exists('proc_open')) {
    echo "SKIP proc_open unavailable\n";
    return;
}
$port = random_int(20000, 40000);
@unlink(sys_get_temp_dir() . '/tokenhub-fake-upstream-' . $port . '.json');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/upstream/server.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
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

$_ENV['PROVIDER_RETRY_MAX_BACKOFF_MS'] = '10';
$client = new ProviderHttpClient(new OutboundUrlPolicy(null, ['127.0.0.1/32']));
$base = 'http://127.0.0.1:' . $port;
$request = new GatewayRequest('/v1/chat/completions', 'openai', 'text_generation', 'm', false, ['model' => 'm', 'messages' => []], ['input_tokens' => 1]);
$route = static fn (string $prefix, int $retries = 2): array => ['id' => 1, 'provider_id' => 1, 'protocol' => 'openai', 'base_url' => $base . $prefix, 'timeout_ms' => 1500, 'retry_count' => $retries, 'external_name' => 'm', 'default_headers' => null];

$flaky = $client->sendJson($route('/flaky'), $request, new OpenAIAdapter(), 'idem-1');
assert_same(true, $flaky['ok'], 'A 503 followed by success is retried within retry_count.');
assert_same(2, $flaky['attempts'], 'Both attempts are reported.');
assert_same(503, $flaky['attempt_log'][0]['status'], 'The attempt log keeps the failed try.');

$limited = $client->sendJson($route('/limited'), $request, new OpenAIAdapter());
assert_same('PROVIDER_RATE_LIMITED', $limited['error_code'] ?? null, '429 maps to PROVIDER_RATE_LIMITED.');
assert_same(0, $limited['retry_after'] ?? null, 'Retry-After is surfaced.');

$redirect = $client->sendJson($route('/redirect', 1), $request, new OpenAIAdapter());
assert_same(302, $redirect['status'], 'Redirects are not followed (they could bypass the outbound policy).');

$blockedClient = new ProviderHttpClient(new OutboundUrlPolicy(null, []));
$blocked = $blockedClient->sendJson(['provider_id' => 99] + $route('/flaky'), $request, new OpenAIAdapter());
assert_same('OUTBOUND_BLOCKED', $blocked['error_code'] ?? null, 'Loopback providers need an explicit allow rule.');
assert_same(1, $blocked['attempts'], 'Policy refusals are not retried.');

$streamRequest = new GatewayRequest('/v1/chat/completions', 'openai', 'text_generation', 'm', true, ['model' => 'm', 'stream' => true], ['input_tokens' => 1]);
$accumulator = new StreamAccumulator(UsageNormalizer::KIND_CHAT);
$forwarded = '';
ob_start();
$result = $client->stream($route('/stream', 1), $streamRequest, new OpenAIAdapter(), static function (string $chunk) use ($accumulator, &$forwarded): void {
    $accumulator->push($chunk);
    $forwarded .= $chunk;
}, static fn (): bool => false);
ob_end_clean();
$accumulator->finish();
assert_same(true, $result['ok'], 'Stream opens.');
assert_same('eof', $result['outcome'], 'Upstream close is reported as eof.');
assert_same('success', $accumulator->state(), 'Split network chunks still produce a complete stream.');
assert_same('Hello', $accumulator->text(), 'Text is reconstructed across chunk boundaries.');
assert_same(7, $accumulator->usage()['total_tokens'] ?? null, 'Final usage frame is measured.');
assert_true(str_contains($forwarded, '[DONE]'), 'Bytes are forwarded unchanged.');

$aborted = $client->stream($route('/stream', 1), $streamRequest, new OpenAIAdapter(), static function (string $chunk): void {
}, static fn (): bool => true);
assert_same('client_aborted', $aborted['outcome'] ?? null, 'Client disconnects stop reading upstream.');

$stall = new StreamAccumulator(UsageNormalizer::KIND_CHAT);
ob_start();
$stalled = $client->stream(['timeout_ms' => 1000] + $route('/stall', 1), $streamRequest, new OpenAIAdapter(), static fn (string $chunk) => $stall->push($chunk), static fn (): bool => false);
ob_end_clean();
$stall->finish();
assert_same('timeout', $stalled['outcome'] ?? null, 'A stalled stream ends with a timeout outcome.');
assert_same('incomplete', $stall->state(), 'Partial output without a terminal event is not success.');

// Timeouts: connect, idle and total are separate. A dead host must fail over
// after the connect timeout, not after the (much longer) read timeout.
$_ENV['GATEWAY_UPSTREAM_CONNECT_TIMEOUT_MS'] = '3000';
$_ENV['GATEWAY_UPSTREAM_MAX_DURATION_SECONDS'] = '1800';
$timeouts = $client->timeouts(60.0);
assert_same(3000, $timeouts['connect_ms'], 'The connect timeout is configured separately from the read timeout.');
assert_same(60.0, $timeouts['idle_s'], 'The channel timeout stays the idle (read) timeout.');
assert_same(1800, $timeouts['max_s'], 'A stream has a maximum total duration.');
assert_same(1500, $client->timeouts(1.5)['connect_ms'], 'The connect timeout never exceeds the channel timeout.');
if ($client->usesCurl()) {
    $options = $client->curlOptions(['url' => $base . '/ok', 'method' => 'POST', 'headers' => ['A' => 'b'], 'body' => '{}', 'timeout' => 60.0]);
    assert_same(3000, $options[CURLOPT_CONNECTTIMEOUT_MS] ?? null, 'cURL gets the connect timeout.');
    assert_same(false, $options[CURLOPT_FOLLOWLOCATION] ?? null, 'cURL never follows redirects (outbound policy).');
    assert_same('', $options[CURLOPT_PROXY] ?? null, 'Proxy environment variables do not change the egress path.');
    assert_true(in_array('Expect:', $options[CURLOPT_HTTPHEADER] ?? [], true), 'No 100-continue round trip before large bodies.');
}

// `php -S` serves one request at a time and is still sleeping in /stall.
usleep(2600000);
$_ENV['GATEWAY_UPSTREAM_MAX_DURATION_SECONDS'] = '1';
$startedTrickle = microtime(true);
ob_start();
$trickled = $client->stream(['timeout_ms' => 5000] + $route('/trickle', 1), $streamRequest, new OpenAIAdapter(), static function (string $chunk): void {
}, static fn (): bool => false);
ob_end_clean();
assert_same('timeout', $trickled['outcome'] ?? null, 'A slow-drip upstream is cut at the maximum duration.');
assert_true(microtime(true) - $startedTrickle < 4.0, 'The maximum duration bounds how long a worker is held.');
$_ENV['GATEWAY_UPSTREAM_MAX_DURATION_SECONDS'] = '1800';

$rejected = $client->stream($route('/badreq', 1), $streamRequest, new OpenAIAdapter(), static function (string $chunk): void {
}, static fn (): bool => false);
assert_same(false, $rejected['ok'], 'A 4xx before the first byte is not an opened stream.');
assert_same(400, $rejected['status'], 'The upstream status is kept.');
assert_true(str_contains((string) ($rejected['body'] ?? ''), 'context_length_exceeded'), 'The upstream error body is captured for the client.');

// Dead port: connection refused is a transport failure (status 0), retried
// and reported as PROVIDER_TIMEOUT like before.
$deadPort = $port + 1;
$dead = $client->sendJson(['base_url' => 'http://127.0.0.1:' . $deadPort . '/x'] + $route('/x', 1), $request, new OpenAIAdapter());
assert_same(0, $dead['status'], 'A refused connection has no HTTP status.');
assert_same('PROVIDER_TIMEOUT', $dead['error_code'] ?? null, 'Transport failures keep their error code.');

// The stream-wrapper fallback (no ext-curl) keeps the same contract.
$_ENV['GATEWAY_UPSTREAM_TRANSPORT'] = 'stream';
$fallback = new ProviderHttpClient(new OutboundUrlPolicy(null, ['127.0.0.1/32']));
assert_same(false, $fallback->usesCurl(), 'The transport can be switched back to the PHP stream wrapper.');
$fallbackOk = $fallback->sendJson($route('/flaky'), $request, new OpenAIAdapter());
assert_same(true, $fallbackOk['ok'], 'Fallback transport serves requests.');
$fallbackAcc = new StreamAccumulator(UsageNormalizer::KIND_CHAT);
ob_start();
$fallbackStream = $fallback->stream($route('/stream', 1), $streamRequest, new OpenAIAdapter(), static fn (string $chunk) => $fallbackAcc->push($chunk), static fn (): bool => false);
ob_end_clean();
$fallbackAcc->finish();
assert_same('success', $fallbackAcc->state(), 'Fallback transport streams complete responses.');
$fallbackRejected = $fallback->stream($route('/badreq', 1), $streamRequest, new OpenAIAdapter(), static function (string $chunk): void {
}, static fn (): bool => false);
assert_same(400, $fallbackRejected['status'], 'Fallback transport keeps the upstream status.');
unset($_ENV['GATEWAY_UPSTREAM_TRANSPORT'], $_ENV['GATEWAY_UPSTREAM_CONNECT_TIMEOUT_MS'], $_ENV['GATEWAY_UPSTREAM_MAX_DURATION_SECONDS']);

echo "ProviderHttpClientBehaviourTest OK\n";
