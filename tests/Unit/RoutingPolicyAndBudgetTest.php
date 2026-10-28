<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Gateway\AggregatorPayloadPolicy;
use TokenHub\Services\Gateway\BudgetEstimator;
use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\ModelCapabilityContract;
use TokenHub\Services\Gateway\ModelRouter;
use TokenHub\Services\Gateway\MultipartUpload;
use TokenHub\Services\PriceService;
use TokenHub\Support\Decimal;

// --- channel-level authorization in the router query (TH-002) ---
$router = new ModelRouter();
$request = new GatewayRequest('/v1/chat/completions', 'openai', 'text_generation', 'shared-name', false, ['model' => 'shared-name'], ['input_tokens' => 1]);
$spec = $router->querySpec($request, [11, 12], true, 8);
assert_true(str_contains($spec['sql'], 'm.id IN (?, ?)'), 'Routing is restricted to the key\'s granted channels.');
assert_same(['shared-name', 'text_generation', 'text_generation', 'openai', 11, 12, 'openai'], $spec['params'], 'Authorized ids are bound as parameters.');
assert_true(str_contains($spec['sql'], 'JSON_CONTAINS'), 'Capability tags let one model serve several capabilities.');
assert_true(str_contains($spec['sql'], 'LIMIT 8'), 'Several candidates are loaded for failover.');
assert_same([], $router->candidates($request, []), 'A key with no grants has no candidates (and never reaches the database).');
assert_true($router->cacheKey($request, [11]) !== $router->cacheKey($request, [12]), 'Route cache is partitioned by grant set.');

// --- aggregator second-hop routing (TH-003) ---
$policy = new AggregatorPayloadPolicy();
$route = ['provider_id' => 7, 'external_name' => 'openai/gpt-4o', 'name' => 'gpt-4o'];
$siblings = [
    'claude' => ['provider_id' => 7, 'name' => 'claude', 'external_name' => 'anthropic/claude-sonnet'],
    'elsewhere' => ['provider_id' => 9, 'name' => 'elsewhere', 'external_name' => 'x/y'],
];
$lookup = static fn (string $name): ?array => $siblings[$name] ?? null;

$denied = $policy->apply(['model' => 'gpt-4o', 'provider' => ['allow_fallbacks' => true]], $route, [], $lookup);
assert_same('ROUTING_PARAMETER_FORBIDDEN', $denied['code'] ?? null, 'Client provider routing is denied by default.');
$fallback = $policy->apply(['model' => 'gpt-4o', 'models' => ['claude'], 'route' => 'fallback'], $route, [], $lookup);
assert_same(['anthropic/claude-sonnet'], $fallback['payload']['models'] ?? null, 'Authorized fallback models on the same channel are rewritten to upstream ids.');
assert_same('MODEL_FORBIDDEN', $policy->apply(['models' => ['elsewhere']], $route, [], $lookup)['code'] ?? null, 'Fallback to another channel is refused.');
assert_same('MODEL_FORBIDDEN', $policy->apply(['models' => ['unknown']], $route, [], $lookup)['code'] ?? null, 'Unauthorized fallback models are refused before any upstream call.');
assert_same('ROUTING_AUTO_ROUTER_FORBIDDEN', $policy->apply([], ['provider_id' => 7, 'external_name' => 'openrouter/auto'], [], $lookup)['code'] ?? null, 'Auto routers are disabled unless explicitly enabled.');
assert_same('ROUTING_PARAMETER_FORBIDDEN', $policy->apply(['plugins' => [['id' => 'web']]], $route, [], $lookup)['code'] ?? null, 'Plugins are denied by default.');

$controls = ['tokenhub_client_routing' => 'restricted', 'tokenhub_provider_policy' => ['data_collection' => 'deny', 'allow_fallbacks' => false, 'only' => ['openai', 'azure'], 'max_price' => ['prompt' => 5]]];
$enforced = $policy->apply(['model' => 'gpt-4o'], $route, $controls, $lookup);
assert_same('deny', $enforced['payload']['provider']['data_collection'] ?? null, 'Server data policy is injected even when the client sends nothing.');
$narrowed = $policy->apply(['provider' => ['only' => ['azure'], 'max_price' => ['prompt' => 9]]], $route, $controls, $lookup);
assert_same(['azure'], $narrowed['payload']['provider']['only'] ?? null, 'Clients may narrow the vendor list.');
assert_same(5.0, $narrowed['payload']['provider']['max_price']['prompt'] ?? null, 'Clients cannot raise the server price ceiling.');
assert_same('ROUTING_PARAMETER_FORBIDDEN', $policy->apply(['provider' => ['only' => ['deepinfra']]], $route, $controls, $lookup)['code'] ?? null, 'Clients cannot add vendors outside the server list.');
assert_same('ROUTING_PARAMETER_FORBIDDEN', $policy->apply(['provider' => ['data_collection' => 'allow']], $route, $controls, $lookup)['code'] ?? null, 'Clients cannot relax data collection.');
assert_same('ROUTING_PARAMETER_FORBIDDEN', $policy->apply(['provider' => ['allow_fallbacks' => true]], $route, $controls, $lookup)['code'] ?? null, 'Clients cannot re-enable fallbacks.');

// --- capability contracts (TH-014) ---
$legacy = ModelCapabilityContract::fromRoute(['capabilities' => null]);
assert_same([], $legacy->unmet(['features' => ['tools'], 'input_modalities' => ['text']]), 'Legacy models without a contract keep previous behaviour.');
$contract = ModelCapabilityContract::fromRoute(['capabilities' => json_encode([
    'upstream' => ['input_modalities' => ['text', 'image'], 'features' => ['tools' => true, 'json_schema' => false, 'streaming' => true]],
    'admin' => ['features' => ['json_schema' => true]],
])]);
assert_same(true, $contract->supports('tools'), 'Upstream declared features are supported.');
assert_same(true, $contract->supports('json_schema'), 'Admin override wins over upstream.');
assert_same(null, $contract->supports('reasoning'), 'Undeclared features are unknown.');
$requirements = ModelCapabilityContract::requirements(['tools' => [['type' => 'function']], 'reasoning_effort' => 'high'], true, 'vision_understanding');
assert_same(['reasoning'], $contract->unmet($requirements), 'Unknown is not support once a contract exists.');

// --- budget (TH-008) ---
$budget = new BudgetEstimator(static fn (int $modelId, array $usage): array => $modelId === 1
    ? (new PriceService())->applyPrice($usage, ['id' => 1, 'input_token_price' => '0.00000100', 'output_token_price' => '0.00000400', 'currency' => 'USD'])
    : ['cost_amount' => null, 'price_snapshot' => null]);
$estimate = $budget->estimate(['input_tokens' => 10], ['model' => 'm', 'messages' => [['role' => 'user', 'content' => str_repeat('x', 400)]], 'max_tokens' => 500], 'text_generation');
assert_same(500, $estimate['output_tokens'], 'Declared max_tokens is reserved.');
assert_same($estimate['input_tokens'] + 500, $estimate['total_tokens'], 'Total reservation includes output.');
$_ENV['GATEWAY_DEFAULT_OUTPUT_RESERVE_TOKENS'] = '256';
assert_same(256, $budget->estimate(['input_tokens' => 1], ['model' => 'm'], 'text_generation')['output_tokens'], 'Undeclared output uses the configured default reserve.');
unset($_ENV['GATEWAY_DEFAULT_OUTPUT_RESERVE_TOKENS']);
$priced = $budget->withCost(['id' => 1], ['input_tokens' => 1000, 'output_tokens' => 500]);
assert_same(0.003, $priced['estimate']['cost_amount'], 'Cost reservation prices the token bound.');
$_ENV['GATEWAY_UNKNOWN_PRICE_POLICY'] = 'deny';
assert_same('PRICE_UNKNOWN', $budget->withCost(['id' => 2], ['input_tokens' => 1])['code'] ?? null, 'Unknown price can be configured to block cost-limited keys.');
unset($_ENV['GATEWAY_UNKNOWN_PRICE_POLICY']);
assert_same(true, $budget->withCost(['id' => 2], ['input_tokens' => 1])['estimate']['cost_unknown'] ?? false, 'Unknown price is marked, not guessed.');
assert_same(['input_tokens' => 300, 'total_tokens' => 300], $budget->delta(['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150], ['input_tokens' => 400, 'output_tokens' => 50, 'total_tokens' => 450]), 'RAG growth produces a positive top-up delta only.');

// --- cache-aware exact pricing (TH-023) ---
$price = ['id' => 5, 'input_token_price' => '0.00000300', 'output_token_price' => '0.00001500', 'cache_read_token_price' => '0.00000030', 'cache_write_token_price' => '0.00000375', 'currency' => 'usd'];
$cost = (new PriceService())->applyPrice(['input_tokens' => 1000, 'cache_read_tokens' => 800, 'cache_write_tokens' => 100, 'output_tokens' => 200], $price);
// 100 uncached * 3e-6 + 800 * 3e-7 + 100 * 3.75e-6 + 200 * 1.5e-5
assert_same('0.00391500', $cost['cost_amount'], 'Cache reads/writes use their own prices without float drift.');
assert_same('USD', $cost['price_snapshot']['currency'], 'Currency is normalised.');
assert_same('0.30000000', Decimal::add('0.1', '0.2'), 'Decimal addition is exact.');
assert_same('1.85185050', Decimal::mul(1234567, '0.0000015'), 'Decimal multiplication is exact.');

// --- multipart forwarding (TH-032) ---
$encoded = MultipartUpload::encode(['model' => 'whisper-1', 'language' => 'zh', MultipartUpload::FILES_KEY => ['file' => ['contents' => 'RIFF', 'filename' => 'a.wav', 'mime' => 'audio/wav']]], 'BOUNDARY');
assert_same('multipart/form-data; boundary=BOUNDARY', $encoded['content_type'], 'Multipart content type carries the boundary.');
assert_true(str_contains($encoded['body'], "name=\"file\"; filename=\"a.wav\"\r\nContent-Type: audio/wav\r\n\r\nRIFF"), 'File parts are forwarded with filename and MIME type.');
assert_true(str_contains($encoded['body'], "name=\"model\"\r\n\r\nwhisper-1"), 'Form fields are forwarded.');
assert_true(MultipartUpload::mimeAllowed('audio/mpeg') && !MultipartUpload::mimeAllowed('application/x-php'), 'Only audio/video uploads are accepted.');
assert_same(['model' => 'x', 'files' => ['file' => ['filename' => 'a.wav', 'mime' => 'audio/wav', 'size' => 4]]], MultipartUpload::describe(['model' => 'x', MultipartUpload::FILES_KEY => ['file' => ['path' => '/tmp/x', 'filename' => 'a.wav', 'mime' => 'audio/wav', 'size' => 4]]]), 'Logs and retention only see file metadata.');
