<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ProviderHttpClient;
use TokenHub\Support\Crypto;

$_ENV['APP_KEY'] = 'provider-client-test-key';

$client = new ProviderHttpClient();
$request = $client->prepareRequest('/v1/chat/completions', [
    'base_url' => 'https://provider.example/base/',
    'protocol' => 'openai',
    'encrypted_secret' => Crypto::encrypt('provider-secret'),
    'default_headers' => '{"X-Org":"demo"}',
    'timeout_ms' => 1500,
], [
    'headers' => ['Content-Type' => 'application/json'],
    'json' => ['model' => 'demo'],
]);

assert_same('https://provider.example/base/v1/chat/completions', $request['url'], 'Provider URL should combine base URL and endpoint.');
assert_same('Bearer provider-secret', $request['headers']['Authorization'], 'Provider secret should be sent as bearer auth.');
assert_same('demo', $request['headers']['X-Org'], 'Default provider headers should be merged.');
assert_same(1.5, $request['timeout'], 'Timeout should be converted from milliseconds to seconds.');

$anthropic = $client->prepareRequest('/v1/messages', [
    'base_url' => 'https://anthropic.example/',
    'protocol' => 'anthropic',
    'encrypted_secret' => Crypto::encrypt('anthropic-secret'),
], [
    'headers' => ['Content-Type' => 'application/json'],
    'json' => ['model' => 'claude-demo'],
]);

assert_same('anthropic-secret', $anthropic['headers']['x-api-key'], 'Anthropic provider secret should be sent as x-api-key.');
assert_same('2023-06-01', $anthropic['headers']['anthropic-version'], 'Anthropic requests should include a default version header.');

$models = $client->prepareRequest('/v1/models', [
    'base_url' => 'https://provider.example/v1',
    'protocol' => 'openai',
    'encrypted_secret' => Crypto::encrypt('provider-secret'),
], [
    'method' => 'GET',
    'headers' => ['Accept' => 'application/json'],
]);

assert_same('GET', $models['method'], 'Provider model listing should support GET requests.');
assert_same('', $models['body'], 'GET model listing should not send a JSON body.');
assert_same('https://provider.example/v1/models', $models['url'], 'Provider model listing should call the models endpoint.');

$kimiCode = $client->prepareRequest('/v1/chat/completions', [
    'base_url' => 'https://api.kimi.com/coding/v1',
    'protocol' => 'openai',
    'encrypted_secret' => Crypto::encrypt('kimi-code-secret'),
], [
    'headers' => ['Content-Type' => 'application/json'],
    'json' => ['model' => 'kimi-for-coding'],
]);

assert_same('https://api.kimi.com/coding/v1/chat/completions', $kimiCode['url'], 'Kimi Code OpenAI-compatible base URL should keep the /coding/v1 path.');

$kimiApi = $client->prepareRequest('/v1/chat/completions', [
    'base_url' => 'https://api.moonshot.cn/v1',
    'protocol' => 'openai',
    'encrypted_secret' => Crypto::encrypt('kimi-api-secret'),
], [
    'headers' => ['Content-Type' => 'application/json'],
    'json' => ['model' => 'kimi-k2.7-code-highspeed'],
]);

assert_same('https://api.moonshot.cn/v1/chat/completions', $kimiApi['url'], 'Normal Kimi API base URL should keep using Moonshot.');

$ark = $client->prepareRequest('/v1/chat/completions', [
    'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
    'protocol' => 'openai',
    'encrypted_secret' => Crypto::encrypt('ark-secret'),
    'default_headers' => '{"tokenhub_adapter":"volcengine_ark","X-Request-Source":"tokenhub","X-Forwarded-For":"203.0.113.10","Forwarded":"for=203.0.113.10","CF-Connecting-IP":"203.0.113.10","True-Client-IP":"203.0.113.10"}',
], [
    'headers' => ['Content-Type' => 'application/json', 'X-Real-IP' => '203.0.113.10'],
    'json' => ['model' => 'doubao-demo'],
]);

assert_same('https://ark.cn-beijing.volces.com/api/v3/chat/completions', $ark['url'], 'Volcengine Ark api/v3 base URL should not receive an extra /v1 prefix.');
assert_true(!isset($ark['headers']['tokenhub_adapter']), 'Internal TokenHub adapter marker should not be sent upstream.');
assert_true(!isset($ark['headers']['X-Forwarded-For']), 'Client X-Forwarded-For must not be sent upstream.');
assert_true(!isset($ark['headers']['Forwarded']), 'Client Forwarded header must not be sent upstream.');
assert_true(!isset($ark['headers']['CF-Connecting-IP']), 'Client CF-Connecting-IP must not be sent upstream.');
assert_true(!isset($ark['headers']['True-Client-IP']), 'Client True-Client-IP must not be sent upstream.');
assert_true(!isset($ark['headers']['X-Real-IP']), 'Client X-Real-IP must not be sent upstream.');
assert_same('tokenhub', $ark['headers']['X-Request-Source'], 'Public default headers should still be sent upstream.');

$idempotent = $client->prepareRequest('/v1/chat/completions', [
    'base_url' => 'https://provider.example/v1',
    'protocol' => 'openai',
    'encrypted_secret' => Crypto::encrypt('provider-secret'),
], [
    'headers' => ['Content-Type' => 'application/json', 'Idempotency-Key' => 'req_stable_provider_key'],
    'json' => ['model' => 'demo'],
]);
assert_same('req_stable_provider_key', $idempotent['headers']['Idempotency-Key'] ?? null, 'Prepared provider requests must preserve a stable idempotency key.');

$providerSource = file_get_contents(__DIR__ . '/../../app/Services/ProviderHttpClient.php') ?: '';
$gatewaySource = file_get_contents(__DIR__ . '/../../app/Services/GatewayService.php') ?: '';
assert_true(str_contains($providerSource, 'ProviderAdapterInterface $adapter, ?string $idempotencyKey = null'), 'Non-streaming provider requests must accept an upstream idempotency key.');
assert_true(str_contains($providerSource, '\'Idempotency-Key\' => $idempotencyKey'), 'Non-streaming retries must forward the same upstream idempotency key.');
assert_true(str_contains($providerSource, 'retried only before the first byte'), 'Streaming provider requests must not retry after a possible partial response.');
assert_true(str_contains($providerSource, "'follow_location' => 0"), 'Provider requests must not follow redirects past the outbound policy.');
assert_true(str_contains($gatewaySource, 'upstreamIdempotencyKey($request)'), 'Gateway requests must derive one stable upstream idempotency key.');
