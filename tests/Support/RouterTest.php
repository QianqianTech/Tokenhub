<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\Router;

$router = new Router();
$router->get('/health', fn () => 'healthy');
$router->post('/admin-api/items/{id}', fn (array $params) => $params['id']);

$health = $router->dispatch('GET', '/health');
assert_same('healthy', $health, 'GET route should dispatch exact path.');

$item = $router->dispatch('POST', '/admin-api/items/42');
assert_same('42', $item, 'POST route should expose path parameters.');

$router->post('/v1/sync/records/{namespace}/{client_record_id}:resolve-conflict', fn (array $params) => $params);
$conflict = $router->dispatch('POST', '/v1/sync/records/settings/default:resolve-conflict');
assert_same('settings', $conflict['namespace'] ?? null, 'Route should expose prefix parameters before a suffix.');
assert_same('default', $conflict['client_record_id'] ?? null, 'Route should expose suffix parameters before a literal colon action.');

$guardedRouter = new Router();
$guardedRouter->middleware(static fn ($request, $next) => 'blocked-by-global-middleware');
$guardedRouter->get('/guarded', fn () => 'handler-reached');
$guarded = $guardedRouter->dispatch('GET', '/guarded');
assert_same('blocked-by-global-middleware', $guarded, 'Global middleware should run before route handlers.');

$missing = $router->dispatch('DELETE', '/health');
assert_same(null, $missing, 'Unsupported method should not match route.');
