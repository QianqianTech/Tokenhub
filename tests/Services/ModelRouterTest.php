<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\ModelRouter;
use TokenHub\Support\App;

$router = new ModelRouter();

$text = new GatewayRequest(
    endpoint: '/v1/chat/completions',
    protocol: 'openai',
    capability: 'text_generation',
    model: 'gpt-demo',
    stream: false,
    payload: ['model' => 'gpt-demo'],
    estimatedUsage: ['input_tokens' => 1],
);

$textQuery = $router->querySpec($text);
assert_true(str_contains($textQuery['sql'], 'm.status = "active"'), 'Router must ignore disabled models.');
assert_true(str_contains($textQuery['sql'], 'p.status = "active"'), 'Router must ignore disabled providers.');
assert_true(str_contains($textQuery['sql'], 'p.protocol = ?'), 'Text route should require the requested protocol.');
assert_same(['gpt-demo', 'text_generation', 'openai', 'openai'], $textQuery['params'], 'Text route params should bind model, capability, protocol and priority protocol.');

$tts = new GatewayRequest(
    endpoint: '/v1/audio/speech',
    protocol: 'openai',
    capability: 'tts',
    model: 'tts-demo',
    stream: false,
    payload: ['model' => 'tts-demo'],
    estimatedUsage: ['tts_chars' => 3],
);

$ttsQuery = $router->querySpec($tts);
assert_true(str_contains($ttsQuery['sql'], 'p.protocol IN (?, "native")'), 'Task route should allow native provider fallback.');

$claudeToKimi = new GatewayRequest(
    endpoint: '/v1/messages',
    protocol: 'anthropic',
    capability: 'text_generation',
    model: 'kimi-codex',
    stream: false,
    payload: ['model' => 'kimi-codex'],
    estimatedUsage: ['input_tokens' => 1],
);

$claudeToKimiQuery = $router->querySpec($claudeToKimi);
assert_true(str_contains($claudeToKimiQuery['sql'], 'LOWER(p.base_url) LIKE "%api.kimi.com/coding%"'), 'Claude Code Kimi route should allow Kimi Code OpenAI-compatible providers.');
assert_true(str_contains($claudeToKimiQuery['sql'], 'LOWER(m.external_name) = "kimi-for-coding"'), 'Claude Code Kimi route should allow the fixed Kimi Code model id.');
assert_true(!str_contains($claudeToKimiQuery['sql'], 'LOWER(p.base_url) LIKE "%moonshot%"'), 'Claude Code Kimi route should not select normal Moonshot Kimi API only by provider URL.');

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$providerName = 'Router Cache Provider ' . $suffix;
$modelName = 'router-cache-' . $suffix;
$cleanup = static function () use ($db, $providerName, $modelName): void {
    $db->execute('DELETE FROM models WHERE name = ?', [$modelName]);
    $db->execute('DELETE FROM providers WHERE name = ?', [$providerName]);
};
$cleanup();
register_shutdown_function($cleanup);

$db->execute(
    'INSERT INTO providers (name, protocol, base_url, status, timeout_ms, retry_count) VALUES (?, "openai", "https://router-cache.example", "active", 60000, 1)',
    [$providerName]
);
$providerId = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO models (provider_id, name, external_name, capability, status, route_priority) VALUES (?, ?, ?, "text_generation", "active", 1)',
    [$providerId, $modelName, $modelName]
);

$cachedRequest = new GatewayRequest(
    endpoint: '/v1/chat/completions',
    protocol: 'openai',
    capability: 'text_generation',
    model: $modelName,
    stream: false,
    payload: ['model' => $modelName],
    estimatedUsage: ['input_tokens' => 1],
);
App::redis()->delete($router->cacheKey($cachedRequest));
$firstRoute = $router->route($cachedRequest);
assert_same($modelName, (string) ($firstRoute['name'] ?? ''), 'First model route should read the active model.');

$db->execute('UPDATE models SET status = "disabled" WHERE name = ?', [$modelName]);
$cachedRoute = $router->route($cachedRequest);
assert_same($modelName, (string) ($cachedRoute['name'] ?? ''), 'Warm model route should be served from the short route cache.');
