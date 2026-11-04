<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Gateway resilience, multi-tenant fairness and protocol fidelity
 * (QUAL-001..007, PERF-003/010/012/013b/020/021/030, MT-001..004,
 * PROTO-001/003, BIZ-008/012/014).
 */

use TokenHub\Services\ApiKeyModelQuotaService;
use TokenHub\Services\Gateway\BudgetEstimator;
use TokenHub\Services\Gateway\ChannelLimiter;
use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\GatewayUsageRecorder;
use TokenHub\Services\Gateway\MediaPayload;
use TokenHub\Services\Gateway\ModelRouter;
use TokenHub\Services\Gateway\OutboundHeaders;
use TokenHub\Services\Gateway\OutputTokenStats;
use TokenHub\Services\Gateway\RequestLifecycle;
use TokenHub\Services\Gateway\ReservationLeaseStore;
use TokenHub\Services\Gateway\RouteHealth;
use TokenHub\Services\Gateway\Stream\GeminiJsonArrayStream;
use TokenHub\Services\GatewayService;
use TokenHub\Services\IdempotencyService;
use TokenHub\Services\OutboundUrlPolicy;
use TokenHub\Services\ProviderHttpClient;
use TokenHub\Services\Providers\AnthropicAdapter;
use TokenHub\Services\Providers\CurlHandlePool;
use TokenHub\Services\Providers\GeminiAdapter;
use TokenHub\Services\Providers\OpenAIAdapter;
use TokenHub\Services\QuotaService;
use TokenHub\Support\App;
use TokenHub\Support\BusinessTime;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

$redis = App::redis();
try {
    $redis->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}
if (!function_exists('proc_open')) {
    echo "SKIP proc_open unavailable\n";
    return;
}
$suffix = bin2hex(random_bytes(4));

// ---------------------------------------------------------------- pure units

// PROTO-003: Gemini JSON array framing.
$array = new GeminiJsonArrayStream();
$framed = $array->push("data: {\"a\":1}\r\n\r\ndata: {\"b\"") . $array->push(":2}\n\n") . $array->finish();
assert_same([['a' => 1], ['b' => 2]], json_decode($framed, true), 'SSE events become one JSON array.');
assert_same('[]', (new GeminiJsonArrayStream())->finish(), 'An empty stream is an empty array.');
$withError = new GeminiJsonArrayStream();
$errored = $withError->push("data: {\"a\":1}\n\n") . $withError->error(['code' => 504, 'message' => 'timeout']);
assert_same(504, json_decode($errored, true)[1]['error']['code'] ?? null, 'A late error is the last array element.');

// BIZ-008: W3C traceparent.
$incoming = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
assert_true(str_starts_with(OutboundHeaders::traceparent('trc_x', $incoming), '00-0af7651916cd43dd8448eb211c80319c-'), 'A client traceparent is continued.');
assert_true(preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', OutboundHeaders::traceparent('trc_abc')) === 1, 'Without one a valid traceparent is derived.');
assert_same(substr(OutboundHeaders::traceparent('trc_abc'), 0, 35), substr(OutboundHeaders::traceparent('trc_abc'), 0, 35), 'The trace id is stable per request trace id.');

// PROTO-001: header allowlist.
$clientRequest = new Request('POST', '/v1/messages', [
    'HTTP_ANTHROPIC_BETA' => 'context-1m-2025-08-07,interleaved-thinking-2025-05-14',
    'HTTP_X_STAINLESS_RETRY_COUNT' => '0',
    'HTTP_OPENAI_BETA' => 'assistants=v2',
    'HTTP_AUTHORIZATION' => 'Bearer sk-client',
    'HTTP_COOKIE' => 'a=b',
    'HTTP_X_EVIL' => "x\r\nInjected: 1",
], [], [], '', RequestContext::fromHeaders([]));
$forward = OutboundHeaders::fromClient($clientRequest);
assert_same(['anthropic-beta', 'openai-beta', 'x-stainless-retry-count'], array_keys($forward), 'Only allowlisted client headers are kept.');
assert_true(!isset(OutboundHeaders::forProtocol($forward, 'anthropic')['openai-beta']), 'OpenAI-Beta never goes to an Anthropic upstream.');
assert_true(!isset(OutboundHeaders::forProtocol($forward, 'openai')['anthropic-beta']), 'anthropic-beta never goes to an OpenAI upstream.');
assert_same([], OutboundHeaders::forProtocol($forward, 'gemini'), 'Other protocols get none.');

// PERF-021: estimates are memoized per payload.
$payload = ['model' => 'm', 'messages' => [['role' => 'user', 'content' => str_repeat('x', 4000)]]];
assert_same(MediaPayload::estimate($payload), MediaPayload::estimate($payload), 'Repeated estimates agree.');
$payload['messages'][] = ['role' => 'user', 'content' => 'more'];
assert_true(MediaPayload::estimate($payload)['tokens'] > 1000, 'A changed payload is estimated again.');

// MT-001: output reservation = min(declared, p95 x1.2 or cap).
$_ENV['GATEWAY_OUTPUT_RESERVE_CAP_TOKENS'] = '8192';
$budget = new BudgetEstimator(null, static fn (int $id): ?int => $id === 7 ? 500 : null);
$big = ['model' => 'm', 'max_tokens' => 64000, 'messages' => []];
assert_same(600, $budget->estimate(['input_tokens' => 1], $big, 'text_generation', ['id' => 7])['output_tokens'], 'History caps a 64k max_tokens at p95 x1.2.');
assert_same('p95', $budget->estimate(['input_tokens' => 1], $big, 'text_generation', ['id' => 7])['output_bound'], 'The source of the bound is recorded.');
assert_same(8192, $budget->estimate(['input_tokens' => 1], $big, 'text_generation', ['id' => 8])['output_tokens'], 'Without history the configured cap applies.');
assert_same(100, $budget->estimate(['input_tokens' => 1], [...$big, 'max_tokens' => 100], 'text_generation', ['id' => 7])['output_tokens'], 'A small declared bound is kept.');
assert_same(95, OutputTokenStats::percentile(range(1, 100), 0.95), 'p95 of 1..100 is 95.');
assert_same(null, OutputTokenStats::percentile([1, 2], 0.95, 50), 'Too few samples give no p95.');
unset($_ENV['GATEWAY_OUTPUT_RESERVE_CAP_TOKENS']);

// MT-003: same-priority channels share load by weight; priorities stay ordered.
$router = new ModelRouter();
$rows = [
    ['id' => 1, 'protocol' => 'openai', 'route_priority' => 1, 'route_weight' => 300],
    ['id' => 2, 'protocol' => 'openai', 'route_priority' => 1, 'route_weight' => 100],
    ['id' => 3, 'protocol' => 'openai', 'route_priority' => 1, 'route_weight' => 0],
    ['id' => 4, 'protocol' => 'openai', 'route_priority' => 2],
];
$first = [1 => 0, 2 => 0, 3 => 0];
mt_srand(42);
for ($i = 0; $i < 2000; $i++) {
    $ordered = $router->balance($rows, 'openai', 'weighted');
    $first[$ordered[0]['id']]++;
    assert_same(4, $ordered[3]['id'], 'A lower priority never jumps ahead.');
    assert_same(3, $ordered[2]['id'], 'Weight 0 is only a last resort within its priority.');
}
assert_true($first[1] > 1300 && $first[1] < 1700 && $first[3] === 0, 'Traffic follows the 3:1 weights (got ' . json_encode($first) . ').');
assert_same([1, 2, 3, 4], array_column($router->balance($rows, 'openai', 'priority'), 'id'), 'GATEWAY_ROUTE_BALANCE=priority keeps the old order.');

// MT-004: task endpoints accept Idempotency-Key, streaming chat does not.
$idem = new IdempotencyService();
$ctx = RequestContext::fromHeaders([]);
assert_true($idem->isEligible(new Request('POST', '/v1/videos/generations', [], [], ['model' => 'v'], '{}', $ctx)), 'Video submissions are deduplicated.');
assert_true($idem->isEligible(new Request('POST', '/v1/images/generations', [], [], ['model' => 'i'], '{}', $ctx)), 'Image submissions are deduplicated.');
assert_true(!$idem->isEligible(new Request('POST', '/v1/chat/completions', [], [], ['model' => 'c'], '{}', $ctx)), 'Chat completions are not stored.');

// BIZ-014: exact micro-units.
assert_same(1, QuotaService::toMicro('0.0000005'), 'Half a micro-unit rounds up.');
assert_same(1234567, QuotaService::toMicro('1.2345670'), 'Decimal strings convert exactly.');
assert_same(-500000, QuotaService::toMicro('-0.5'), 'Refunds convert too.');
assert_same(300000, QuotaService::toMicro(0.3), 'Floats are rounded, not truncated.');

// BIZ-012: day buckets follow the business time zone.
BusinessTime::reset('Asia/Shanghai');
$evening = (int) gmmktime(20, 0, 0, 10, 31, 2026); // 04:00 on Nov 1 in Shanghai
assert_same('20261101', QuotaService::window('day', $evening)['bucket'], 'The day quota resets at local midnight.');
assert_same('202611', (new ApiKeyModelQuotaService())->periodWindow('month', $evening)['bucket'], 'Model quotas use the same calendar.');
assert_same(4 * 3600 + 16 * 3600 + 86400, QuotaService::window('day', $evening)['ttl'], 'Keys live one day past the end of their local day.');
assert_same('202610312000', QuotaService::window('minute', $evening)['bucket'], 'Minute buckets stay UTC.');
BusinessTime::reset();

// ---------------------------------------------------------------- Redis-backed

// QUAL-006: sliding-window error rate, atomic updates.
$_ENV['GATEWAY_CIRCUIT_FAILURES'] = '3';
$_ENV['GATEWAY_CIRCUIT_ERROR_RATE'] = '0.5';
$health = new RouteHealth();
$modelId = random_int(500000, 599999);
$redis->delete('route_health:' . $modelId);
$redis->set('route_health:' . $modelId, ['failures' => 9, 'open_until' => 0], 60);
$health->recordSuccess($modelId);
assert_same('closed', $health->state($modelId), 'A pre-upgrade string value is replaced, not an error.');
foreach (range(1, 6) as $i) {
    $health->recordSuccess($modelId);
}
foreach (range(1, 3) as $i) {
    $health->recordFailure($modelId, 'PROVIDER_ERROR');
}
assert_same('closed', $health->state($modelId), '3 failures among 10 outcomes stay below a 50% error rate.');
foreach (range(1, 4) as $i) {
    $health->recordFailure($modelId, 'PROVIDER_ERROR');
}
assert_same('open', $health->state($modelId), '7 of 14 outcomes failing opens the circuit.');
assert_same(7, $health->snapshot($modelId)['window']['failures'], 'The snapshot reports the window.');
$aged = random_int(600000, 699999);
$redis->delete('route_health:' . $aged);
foreach (range(1, 2) as $i) {
    $health->recordFailure($aged, 'PROVIDER_ERROR', time() - 600);
}
$health->recordFailure($aged, 'PROVIDER_ERROR');
assert_same('closed', $health->state($aged), 'Failures outside the window no longer count.');
// QUAL-004: a 429 cools the channel down for its Retry-After.
$limitedId = random_int(700000, 799999);
$redis->delete('route_health:' . $limitedId);
$health->cooldown($limitedId, 30);
assert_same('open', $health->state($limitedId), 'A rate-limited channel leaves the rotation.');
assert_same('half_open', $health->state($limitedId, time() + 31), 'It returns after Retry-After.');
$health->recordSuccess($limitedId);
assert_same('open', $health->state($limitedId), 'A late success does not end a rate-limit cooldown early.');
assert_true(RouteHealth::countsAsFailure(401, 'PROVIDER_REJECTED'), 'A rejected channel credential counts (QUAL-003).');
assert_true(!RouteHealth::countsAsFailure(400, 'PROVIDER_REJECTED'), 'Client errors still do not count.');
foreach ([$modelId, $aged, $limitedId] as $id) {
    $redis->delete('route_health:' . $id);
}
unset($_ENV['GATEWAY_CIRCUIT_FAILURES'], $_ENV['GATEWAY_CIRCUIT_ERROR_RATE']);

// MT-002: renewals keep a live lease; the sweeper rolls back everything once;
// a late owner never decrements again.
$key = static fn (string $metric): string => "quota:lease-unit:{$suffix}:{$metric}";
$counter = static fn (string $metric): float => (float) ($redis->get($key($metric)) ?? 0);
$reserve = static function (array $amounts) use ($redis, $key): array {
    $counters = [];
    foreach ($amounts as $metric => $amount) {
        $counters[] = ['metric' => $metric, 'usage' => $metric === 'requests' ? 'request_count' : $metric, 'amount' => $amount, 'key' => $key($metric), 'limit' => 0, 'ttl' => 300];
    }
    $redis->reserveCounters($counters);
    return [$counters];
};
$leases = new ReservationLeaseStore($redis);
$redis->increment($key('concurrency'), 300);
$_ENV['GATEWAY_LEASE_RENEW_SECONDS'] = '1';
$lifecycle = new RequestLifecycle(new QuotaService(), new ApiKeyModelQuotaService(), ['id' => 1], [$key('concurrency')], $reserve(['requests' => 1, 'input_tokens' => 100]), null, [], $leases, 'req-lease-' . $suffix, 5);
$lifecycle->markUpstreamStarted();
assert_same(0, $leases->sweepExpired(time() + 2), 'A fresh lease is not swept.');
$lifecycle->renewLease(true);
assert_same(0, $leases->sweepExpired(time() + 4), 'A renewed lease outlives its original deadline.');
assert_same(1, $leases->sweepExpired(time() + 3600), 'A lease that stopped renewing is swept.');
assert_same(0.0, $counter('concurrency'), 'The sweeper frees the concurrency slot.');
assert_same(0.0, $counter('requests') + $counter('input_tokens'), 'The sweeper rolls back every reservation.');
$lifecycle->settle(['input_tokens' => 40]);
assert_same(0.0, $counter('concurrency'), 'The late owner does not decrement concurrency a second time.');
assert_same(40.0, $counter('input_tokens'), 'The late owner charges its measured usage in full.');
assert_same(1.0, $counter('requests'), 'The request is counted again.');
unset($_ENV['GATEWAY_LEASE_RENEW_SECONDS']);
foreach (['concurrency', 'requests', 'input_tokens'] as $metric) {
    $redis->delete($key($metric));
}

// BIZ-014: cost reservations and settlement in integer micro-units, legacy
// float counters carried over once.
$policyKey = static fn (string $metric, string $bucket): string => "quota:{$suffix}1:api_key:{$suffix}2:{$metric}:{$bucket}";
$quota = new QuotaService();
$window = $quota->periodWindow('day');
$legacy = "quota:9{$suffix}:api_key:77:cost_amount:" . $window['bucket'];
$redis->set($legacy, '0.25', 600);
$micro = $quota->usageCounter((int) ('9' . hexdec(substr($suffix, 0, 4))), 'api_key', 77, 'day', 'cost_amount');
assert_same(1000000, $micro['scale'], 'Cost counters are scaled to micro-units.');
assert_true(str_ends_with($micro['key'], ':cost_amount_u6:' . $window['bucket']), 'Cost counters use a versioned key name.');
$counters = [[['metric' => QuotaService::COST_METRIC, 'usage' => 'cost_amount', 'amount' => 300000, 'scale' => 1000000, 'key' => 'quota:u6unit:' . $suffix, 'ttl' => 300, 'limit' => 0]]];
$redis->reserveCounters($counters[0]);
$quota->recordUsage(['id' => 1], ['cost_amount' => '0.1234567'], $counters);
assert_same('123457', (string) $redis->connection()->get('quota:u6unit:' . $suffix), 'Settlement leaves an exact integer micro-unit counter.');
$redis->delete('quota:u6unit:' . $suffix);
$redis->delete($legacy);

// MT-003: channel capacity.
$limiter = new ChannelLimiter();
$channel = ['id' => random_int(800000, 899999), 'max_in_flight' => 1];
assert_true($limiter->acquire($channel, 'r1')['ok'], 'The first request gets the slot.');
assert_same('max_in_flight', (new ChannelLimiter())->acquire($channel, 'r2')['reason'] ?? null, 'A full channel is skipped.');
assert_same([$channel['id'] => 1], ChannelLimiter::inFlight([$channel['id']]), 'In-flight requests are visible for balancing.');
$limiter->release($channel);
assert_true((new ChannelLimiter())->acquire($channel, 'r3')['ok'], 'Releasing frees the slot.');
$redis->delete('channel:inflight:' . $channel['id']);

// QUAL-002: a failed queue push is spooled and re-queued later.
$queue = 'tokenhub:spooltest:' . $suffix;
$_ENV['GATEWAY_USAGE_SPOOL_DIR'] = sys_get_temp_dir() . '/tokenhub-spool-' . $suffix;
$spool = new ReflectionMethod(GatewayUsageRecorder::class, 'spool');
$spool->invoke(null, ['type' => 'usage.write', 'record' => ['request_id' => 'req-spool-' . $suffix]], 'redis down');
assert_true(glob(GatewayUsageRecorder::spoolDirectory() . '/usage-*.jsonl') !== [], 'The job is written to the local spool.');
assert_true(GatewayUsageRecorder::drainSpool($queue) >= 1, 'The spool is pushed back to the queue.');
assert_same([], glob(GatewayUsageRecorder::spoolDirectory() . '/usage-*.jsonl') ?: [], 'Drained spool files are removed.');
$queued = array_map(static fn (string $raw): ?string => json_decode($raw, true)['record']['request_id'] ?? null, $redis->connection()->lRange($queue, 0, -1) ?: []);
assert_true(in_array('req-spool-' . $suffix, $queued, true), 'The spooled record reaches the queue.');
$redis->delete($queue);
@unlink($_ENV['GATEWAY_USAGE_SPOOL_DIR'] . '/.drain.lock');
@rmdir($_ENV['GATEWAY_USAGE_SPOOL_DIR']);
unset($_ENV['GATEWAY_USAGE_SPOOL_DIR']);

// QUAL-007: error shapes per protocol.
$service = new GatewayService();
$clientError = new ReflectionMethod(GatewayService::class, 'clientError');
$openaiRequest = new GatewayRequest('/v1/chat/completions', 'openai', 'text_generation', 'm', false, [], []);
$anthropicRequest = new GatewayRequest('/v1/messages', 'anthropic', 'text_generation', 'm', false, [], []);
$quotaError = json_decode($clientError->invoke($service, $openaiRequest, 'QUOTA_EXCEEDED', 'total_tokens exceeded', 429)->body, true);
assert_same(['QUOTA_EXCEEDED', 'insufficient_quota'], [$quotaError['error']['code'] ?? null, $quotaError['error']['type'] ?? null], 'OpenAI errors carry error.type.');
$anthropicError = json_decode($clientError->invoke($service, $anthropicRequest, 'QUOTA_EXCEEDED', 'total_tokens exceeded', 429)->body, true);
assert_same(['error', 'rate_limit_error'], [$anthropicError['type'] ?? null, $anthropicError['error']['type'] ?? null], 'Anthropic errors use the Anthropic envelope.');
$streamError = new ReflectionMethod(GatewayService::class, 'streamErrorEvent');
$event = $streamError->invoke($service, $openaiRequest, 'PROVIDER_TIMEOUT', ['response' => null, 'message' => 'Upstream provider timed out', 'type' => 'upstream_error'], 504);
assert_true(str_starts_with($event, 'data: {"error":{') && str_ends_with($event, "data: [DONE]\n\n") && !str_contains($event, 'event:'), 'OpenAI in-stream errors are data:{"error"} plus [DONE].');
$anthropicEvent = $streamError->invoke($service, $anthropicRequest, 'PROVIDER_TIMEOUT', ['response' => null, 'message' => 'x', 'type' => 'api_error'], 504);
assert_true(str_starts_with($anthropicEvent, "event: error\n"), 'Anthropic in-stream errors keep the error event.');

// ---------------------------------------------------------------- live upstream

$port = random_int(50001, 60000);
@unlink(sys_get_temp_dir() . '/tokenhub-fake-upstream-' . $port . '.json');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/upstream/server.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static fn () => proc_terminate($server));
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($socket) {
        fclose($socket);
        break;
    }
    usleep(50000);
}
$base = 'http://127.0.0.1:' . $port;
$_ENV['PROVIDER_RETRY_MAX_BACKOFF_MS'] = '10';
$client = new ProviderHttpClient(new OutboundUrlPolicy(null, ['127.0.0.1/32']));
$route = static fn (string $prefix, int $retries = 2, string $protocol = 'openai'): array => ['id' => 1, 'provider_id' => 1, 'protocol' => $protocol, 'base_url' => $base . $prefix, 'timeout_ms' => 1500, 'retry_count' => $retries, 'external_name' => 'm', 'default_headers' => null];
$chat = new GatewayRequest('/v1/chat/completions', 'openai', 'text_generation', 'm', false, ['model' => 'm', 'messages' => []], ['input_tokens' => 1]);
(new ReflectionProperty(App::class, 'context'))->setValue(null, new RequestContext('req_trace_' . $suffix, 'trc_' . $suffix));

// QUAL-004: no same-channel retry on 429; long Retry-After is not shortened.
$limited = $client->sendJson($route('/limited'), $chat, new OpenAIAdapter());
assert_same([1, 'PROVIDER_RATE_LIMITED'], [$limited['attempts'], $limited['error_code'] ?? null], 'A 429 fails over instead of retrying the same channel.');
assert_true($client->failoverable($limited), 'A 429 is failed over.');
$busy = $client->sendJson($route('/busy'), $chat, new OpenAIAdapter());
assert_same([1, 30], [$busy['attempts'], $busy['retry_after'] ?? null], 'A 503 asking for 30 s is not retried after a 10 ms nap; Retry-After is kept intact.');
// QUAL-003: credential failures fail over.
$denied = $client->sendJson($route('/denied', 1), $chat, new OpenAIAdapter());
assert_true($client->failoverable($denied), 'A 401 from the upstream fails over to the next channel.');
assert_true(!$client->failoverable(['status' => 400, 'error_code' => 'PROVIDER_REJECTED']), 'A 400 is the request\'s fault and is not failed over.');

// BIZ-008 + PROTO-001: trace headers on every call, client protocol headers
// only to the same protocol.
$messages = new GatewayRequest('/v1/messages', 'anthropic', 'text_generation', 'm', false, ['model' => 'm', 'max_tokens' => 1, 'messages' => []], ['input_tokens' => 1], ['forward_headers' => $forward]);
$echo = $client->sendJson($route('/echoheaders', 1, 'anthropic'), $messages, new AnthropicAdapter());
$seen = $echo['json']['headers'] ?? [];
assert_same('req_trace_' . $suffix, $seen['x-request-id'] ?? null, 'Upstreams receive the gateway request id.');
assert_true(preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', (string) ($seen['traceparent'] ?? '')) === 1, 'Upstreams receive a W3C traceparent.');
assert_same('context-1m-2025-08-07,interleaved-thinking-2025-05-14', $seen['anthropic-beta'] ?? null, 'anthropic-beta reaches Anthropic upstreams.');
assert_same('0', $seen['x-stainless-retry-count'] ?? null, 'x-stainless-* headers are passed through.');
assert_true(!isset($seen['openai-beta']) && !isset($seen['cookie']) && ($seen['x-api-key'] ?? '') !== 'Bearer sk-client', 'Nothing else from the client is forwarded.');
$bridged = $client->sendJson($route('/echoheaders', 1, 'openai'), $messages, new OpenAIAdapter());
assert_true(!isset(($bridged['json']['headers'] ?? [])['anthropic-beta']), 'A cross-protocol (bridged) upstream gets no client protocol headers.');

// PERF-003: handles are reused per host.
if ($client->usesCurl() && CurlHandlePool::enabled()) {
    CurlHandlePool::flush();
    $client->sendJson($route('/echoheaders', 1), $chat, new OpenAIAdapter());
    assert_same(1, CurlHandlePool::idleCount($base . '/x'), 'A finished transfer returns its handle to the pool.');
    $client->sendJson($route('/echoheaders', 1), $chat, new OpenAIAdapter());
    assert_same(1, CurlHandlePool::idleCount($base . '/x'), 'The next transfer reuses it.');
    $options = $client->curlOptions(['url' => 'https://example.com/v1', 'method' => 'POST', 'headers' => [], 'body' => '{}', 'timeout' => 10.0]);
    $expected = CurlHandlePool::http2Enabled() ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1;
    assert_same($expected, $options[CURLOPT_HTTP_VERSION], 'HTTP/2 is negotiated over TLS when available.');
    $_ENV['GATEWAY_UPSTREAM_HTTP2'] = 'false';
    assert_same(CURL_HTTP_VERSION_1_1, $client->curlOptions(['url' => 'https://example.com/v1', 'method' => 'POST', 'headers' => [], 'body' => '{}', 'timeout' => 10.0])[CURLOPT_HTTP_VERSION], 'HTTP/2 can be switched off.');
    unset($_ENV['GATEWAY_UPSTREAM_HTTP2']);
}

// PROTO-003 end to end: the upstream is asked for SSE, the client gets JSON.
$geminiRequest = new GatewayRequest('/v1beta/models/g:streamGenerateContent', 'gemini', 'text_generation', 'g', true, ['contents' => []], ['input_tokens' => 1], ['gemini_json_array' => true]);
$geminiArray = new GeminiJsonArrayStream();
$geminiOut = '';
ob_start();
$geminiResult = $client->stream(['external_name' => 'g'] + $route('', 1, 'gemini'), $geminiRequest, new GeminiAdapter(), static function (string $chunk) use ($geminiArray, &$geminiOut): void {
    $geminiOut .= $geminiArray->push($chunk);
}, static fn (): bool => false);
ob_end_clean();
$geminiOut .= $geminiArray->finish();
assert_same(true, $geminiResult['ok'], 'The Gemini stream opens.');
assert_same('lo', json_decode($geminiOut, true)[1]['candidates'][0]['content']['parts'][0]['text'] ?? null, 'Gemini events arrive as array elements.');

// PERF-013b + MT-002: an idle open stream gets keep-alives and lease ticks.
$beats = 0;
$ticks = 0;
$streamClient = (new ProviderHttpClient(new OutboundUrlPolicy(null, ['127.0.0.1/32'])))
    ->onIdle(static function () use (&$beats): void {
        $beats++;
    }, 1.0)
    ->onTick(static function () use (&$ticks): void {
        $ticks++;
    });
$streamRequest = new GatewayRequest('/v1/chat/completions', 'openai', 'text_generation', 'm', true, ['model' => 'm', 'stream' => true], ['input_tokens' => 1]);
ob_start();
$sleepy = $streamClient->stream(['timeout_ms' => 5000] + $route('/sleepy', 1), $streamRequest, new OpenAIAdapter(), static function (string $chunk): void {
}, static fn (): bool => false);
ob_end_clean();
assert_same('eof', $sleepy['outcome'] ?? null, 'The idle stream still completes.');
assert_true($beats >= 1, 'A 2.6 s pause produced a keep-alive (got ' . $beats . ').');
assert_true($ticks >= 2, 'The lease was ticked while the stream was idle (got ' . $ticks . ').');

// QUAL-005: a read timeout after the request left is not replayed.
$slow = $client->sendJson(['timeout_ms' => 800] + $route('/slow', 3), $chat, new OpenAIAdapter());
assert_same(0, $slow['status'], 'The slow upstream timed out.');
assert_same(1, $slow['attempts'], 'The POST is not sent again after a read timeout.');
assert_same(true, $slow['request_sent'] ?? null, 'The result says the request had been sent.');
assert_true(!$client->failoverable($slow), 'Nor is it replayed on another channel by default.');
usleep(1500000);
$dead = $client->sendJson(['base_url' => 'http://127.0.0.1:' . ($port + 1) . '/x'] + $route('/x', 2), $chat, new OpenAIAdapter());
assert_same(2, $dead['attempts'], 'A refused connection (request never sent) is still retried.');
unset($_ENV['PROVIDER_RETRY_MAX_BACKOFF_MS']);

// ---------------------------------------------------------------- through GatewayService

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $queue = 'tokenhub:resiltest:' . $suffix;
    $_ENV['QUEUE_NAME'] = $queue;
    $_ENV['QUEUE_ROUTING'] = 'false';
    $_ENV['OUTBOUND_PRIVATE_ALLOWLIST'] = '127.0.0.1/32';
    $_ENV['APP_KEY'] = 'gateway-resilience-test-key-0123456789ab';
    (new ReflectionProperty(App::class, 'config'))->setValue(null, \TokenHub\Support\Config::default());
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
    $pdo->exec('CREATE TABLE desensitization_policies (id INTEGER PRIMARY KEY, status TEXT, scope_type TEXT, scope_id INT, action TEXT, parsers TEXT)');
    $pdo->exec("INSERT INTO providers VALUES (1, 'Claude', 'anthropic', '{$base}/echoheaders', NULL, 'active', 2000, 1, NULL)");
    $pdo->exec("INSERT INTO providers VALUES (2, 'Gemini', 'gemini', '{$base}', NULL, 'active', 2000, 1, NULL)");
    $pdo->exec("INSERT INTO models VALUES (91, 1, 'claude-x', 'claude-up', 'text_generation', NULL, 'active', 1)");
    $pdo->exec("INSERT INTO models VALUES (92, 2, 'g', 'g', 'text_generation', NULL, 'active', 1)");
    foreach ([91, 92] as $id) {
        $redis->delete('route_health:' . $id);
    }
    \TokenHub\Support\ConfigVersion::bump('global', 'gateway-resilience-test');
    \TokenHub\Support\ConfigVersion::refresh();
    $database = new \TokenHub\Support\Database(App::config());
    (new ReflectionProperty(\TokenHub\Support\Database::class, 'pdo'))->setValue($database, $pdo);
    (new ReflectionProperty(App::class, 'database'))->setValue(null, $database);
    $gateway = static function (string $path, array $body, array $server = [], array $query = [], array $params = []): array {
        $context = RequestContext::fromHeaders([]);
        (new ReflectionProperty(App::class, 'context'))->setValue(null, $context);
        $context->setApiKey(['id' => 9101, 'user_id' => 1, 'department_id' => 1, 'allowed_model_ids' => [91, 92], 'allowed_model_names' => []]);
        $request = new Request('POST', $path, ['REMOTE_ADDR' => '127.0.0.1', ...$server], $query, $body, (string) json_encode($body), $context);
        $request->params = $params;
        $response = (new GatewayService())->handle($request);
        ob_start();
        ob_start();
        $response->send();
        $inner = (string) ob_get_clean();
        $output = (string) ob_get_clean() . $inner;
        return ['status' => $response->status, 'headers' => $response->headers, 'body' => $output, 'json' => json_decode($output, true)];
    };
    $claude = $gateway('/v1/messages', ['model' => 'claude-x', 'max_tokens' => 8, 'messages' => [['role' => 'user', 'content' => 'hi']]], ['HTTP_ANTHROPIC_BETA' => 'context-1m-2025-08-07']);
    assert_same(200, $claude['status'], 'The Anthropic request is served.');
    assert_same('context-1m-2025-08-07', $claude['json']['headers']['anthropic-beta'] ?? null, 'anthropic-beta survives the whole gateway pipeline (PROTO-001).');
    assert_true(isset($claude['json']['headers']['traceparent']), 'The gateway sends traceparent upstream (BIZ-008).');
    assert_true(preg_match('/^gateway;dur=\d+, upstream;dur=\d+, total;dur=\d+$/', (string) ($claude['headers']['Server-Timing'] ?? '')) === 1, 'Server-Timing splits gateway and upstream time (PERF-030).');
    $missing = $gateway('/v1/messages', ['model' => 'nope', 'max_tokens' => 8, 'messages' => []]);
    assert_same(['error', 'not_found_error'], [$missing['json']['type'] ?? null, $missing['json']['error']['type'] ?? null], 'Gateway errors on /v1/messages use the Anthropic envelope (QUAL-007).');
    $gemini = $gateway('/v1beta/models/g:streamGenerateContent', ['contents' => [['parts' => [['text' => 'hi']]]]], [], [], ['model' => 'g']);
    assert_same('lo', json_decode($gemini['body'], true)[1]['candidates'][0]['content']['parts'][0]['text'] ?? null, 'Without alt=sse the Gemini client gets a JSON array (PROTO-003).');
    $geminiSse = $gateway('/v1beta/models/g:streamGenerateContent', ['contents' => [['parts' => [['text' => 'hi']]]]], [], ['alt' => 'sse'], ['model' => 'g']);
    assert_true(str_starts_with($geminiSse['body'], 'data: '), 'With alt=sse the stream stays SSE.');
    $redis->delete($queue);
    $redis->delete($queue . ':usage');
}

echo "GatewayResilienceTest OK\n";
