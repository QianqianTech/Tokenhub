<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Ingress hardening without changing what legitimate callers see:
 * - X-Forwarded-For is read from the right, so a client cannot choose its
 *   own address (API key IP allow-lists, invalid-auth throttling);
 * - unknown keys are remembered briefly and stop reaching the database;
 * - an address that exhausted its invalid-auth budget no longer triggers
 *   database lookups, while keys that are already cached keep working.
 */

use TokenHub\Middleware\ApiKeyAuthMiddleware;
use TokenHub\Services\ApiKeyService;
use TokenHub\Services\ExternalRateLimitService;
use TokenHub\Services\TrustedProxyService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Database;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Support\Response;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}
$context = new RequestContext('req_auth_hardening', 'trc_auth_hardening');

// 1. X-Forwarded-For: right-to-left, skipping our own proxies.
$resolver = new TrustedProxyService(['10.0.0.0/8', '172.18.0.0/16']);
$ip = static fn (string $peer, string $xff): string => $resolver->clientIp(new Request('GET', '/v1/me', ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $xff], [], [], '', $context));
assert_same('198.51.100.7', $ip('172.18.0.2', '6.6.6.6, 198.51.100.7'), 'A spoofed left-most entry is ignored; the proxy-appended address wins.');
assert_same('198.51.100.7', $ip('172.18.0.2', '6.6.6.6, 198.51.100.7, 10.0.0.5'), 'Every trusted hop is skipped from the right.');
assert_same('198.51.100.7', $ip('172.18.0.2', 'garbage, 198.51.100.7'), 'Malformed entries are ignored.');
assert_same('10.0.0.9', $ip('172.18.0.2', '10.0.0.9, 10.0.0.5'), 'Purely internal chains resolve to the furthest hop.');
assert_same('172.18.0.2', $ip('172.18.0.2', ''), 'Without the header the trusted peer itself is the client.');
assert_same('203.0.113.1', $ip('203.0.113.1', '6.6.6.6'), 'Untrusted peers can never set their address.');

// 2. SQLite stand-in for the key tables.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE departments (id INTEGER PRIMARY KEY, status TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, status TEXT)');
$pdo->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, department_id INT, name TEXT, key_hash TEXT, allowed_ip_cidrs TEXT, status TEXT, expires_at TEXT, last_used_at TEXT)');
$pdo->exec('CREATE TABLE models (id INTEGER PRIMARY KEY, name TEXT, status TEXT)');
$pdo->exec('CREATE TABLE api_key_model_permissions (api_key_id INT, model_id INT)');
$pdo->exec("INSERT INTO departments VALUES (1, 'active')");
$pdo->exec("INSERT INTO users VALUES (1, 1, 'active')");
$plain = 'tkh_' . bin2hex(random_bytes(16));
$insert = $pdo->prepare("INSERT INTO api_keys (id, user_id, department_id, name, key_hash, allowed_ip_cidrs, status) VALUES (?, 1, 1, 'k', ?, '[]', 'active')");
$insert->execute([random_int(100000, 999999), Crypto::hashKey($plain)]);
$appDatabase = new ReflectionProperty(App::class, 'database');
$originalDatabase = $appDatabase->getValue();
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
$appDatabase->setValue(null, $database);

$service = new ApiKeyService();
$garbage = 'tkh_garbage_' . bin2hex(random_bytes(8));
App::redis()->delete($service->missCacheKey(Crypto::hashKey($garbage)));
App::redis()->delete($service->cacheKey(Crypto::hashKey($plain)));

assert_same('API_KEY_INVALID', $service->authenticate($garbage, '198.51.100.7', null)['code'] ?? null, 'Unknown keys are rejected.');
// Remove the tables: a second lookup of the same unknown key must not query.
$pdo->exec('ALTER TABLE api_keys RENAME TO api_keys_hidden');
assert_same('API_KEY_INVALID', $service->authenticate($garbage, '198.51.100.7', null)['code'] ?? null, 'A repeated unknown key is answered from the miss cache, without a database query.');
$pdo->exec('ALTER TABLE api_keys_hidden RENAME TO api_keys');

$throttled = $service->authenticate('tkh_other_' . bin2hex(random_bytes(8)), '198.51.100.7', null, false);
assert_same('RATE_LIMITED', $throttled['code'] ?? null, 'A throttled address cannot make the gateway look up new keys.');
assert_same(429, $throttled['status'] ?? null, 'The refusal is a 429.');
assert_same(true, $service->authenticate($plain, '198.51.100.7', null)['ok'] ?? false, 'A real key authenticates (and is cached).');
assert_same(true, $service->authenticate($plain, '198.51.100.7', null, false)['ok'] ?? false, 'Cached keys keep working even from a throttled address.');

// 3. Middleware: budget exhausted -> unknown keys get 429 without a lookup,
//    the cached real key still passes.
$clientIp = '198.51.100.' . random_int(20, 220);
$limit = (int) App::config()->get('app.external_api_rate_limits.invalid_auth_per_minute', 30);
$limiter = new ExternalRateLimitService();
$counter = $limiter->counterKey('invalid-auth', $clientIp, 60, $limit);
App::redis()->set($counter, (string) $limit, 60);
assert_same(true, $limiter->exhausted('invalid-auth', $clientIp, $limit), 'The read-only check sees an exhausted budget.');
assert_same(false, $limiter->exhausted('invalid-auth', '198.51.100.250', $limit), 'Other addresses are unaffected.');
$middleware = new ApiKeyAuthMiddleware();
$through = static function (string $token) use ($middleware, $clientIp, $context): Response {
    (new ReflectionProperty(App::class, 'context'))->setValue(null, $context);
    $request = new Request('GET', '/v1/me', ['REMOTE_ADDR' => $clientIp, 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], [], [], '', $context);
    return $middleware($request, static fn (Request $request): Response => Response::json(['reached' => true]));
};
$pdo->exec('ALTER TABLE api_keys RENAME TO api_keys_hidden');
$blocked = $through('tkh_unknown_' . bin2hex(random_bytes(8)));
assert_same(429, $blocked->status, 'Unknown keys from an exhausted address are refused before any lookup.');
$pdo->exec('ALTER TABLE api_keys_hidden RENAME TO api_keys');
assert_same(200, $through($plain)->status, 'A valid cached key from the same address is still served.');

App::redis()->delete($counter);
App::redis()->delete($service->missCacheKey(Crypto::hashKey($garbage)));
App::redis()->delete($service->cacheKey(Crypto::hashKey($plain)));
$appDatabase->setValue(null, $originalDatabase);

echo "ApiKeyAuthHardeningTest OK\n";
