<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Built-in MCP OAuth server end to end against an in-memory SQLite stand-in
 * for MySQL: discovery, dynamic registration and metadata-document clients,
 * redirect validation, the sign-in page (API key and password, key choice),
 * PKCE code exchange, refresh rotation with grace period and replay
 * detection, revocation, /mcp authentication with the access token, and the
 * revocation hooks (password change, admin revoke).
 */

use TokenHub\Http\Controllers\OAuthController;
use TokenHub\Middleware\McpAuthMiddleware;
use TokenHub\Services\Mcp\OAuth\ClientMetadataFetcher;
use TokenHub\Services\Mcp\OAuth\OAuthException;
use TokenHub\Services\Mcp\OAuth\OAuthServer;
use TokenHub\Services\OutboundUrlPolicy;
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
$_ENV['APP_KEY'] = 'mcp-oauth-test-key-0123456789abcdef0123';
$_ENV['MCP_PUBLIC_URL'] = 'https://hub.example.com';
(new ReflectionProperty(App::class, 'config'))->setValue(null, \TokenHub\Support\Config::default());
try {
    App::redis()->connection()->ping();
    App::redis()->connection()->flushDB();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

final class OAuthTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query);
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        }
        if (str_contains($query, 'FROM information_schema.columns') && str_contains($query, 'table_name = ?') && str_contains($query, 'column_name = ?')) {
            $query = 'SELECT 1 AS present FROM pragma_table_info(?) WHERE name = ? LIMIT 1';
        }

        return parent::prepare($query, $options);
    }
}

$pdo = new OAuthTestPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
foreach ([
    'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT "active")',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, username TEXT, password_hash TEXT, role TEXT, status TEXT DEFAULT "active")',
    'CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, department_id INT, name TEXT, key_prefix TEXT, key_suffix TEXT, key_hash TEXT UNIQUE, allowed_ip_cidrs TEXT, status TEXT DEFAULT "active", expires_at TEXT, last_used_at TEXT)',
    'CREATE TABLE models (id INTEGER PRIMARY KEY, name TEXT, status TEXT)',
    'CREATE TABLE api_key_model_permissions (api_key_id INT, model_id INT)',
    'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)',
] as $ddl) {
    $pdo->exec($ddl);
}
// The real migration, minus MySQL-only syntax.
$migration = (string) file_get_contents(__DIR__ . '/../../database/migrations/2026_10_29_000004_mcp_oauth.sql');
$migration = preg_replace('/--[^\n]*\n/', "\n", $migration);
$migration = preg_replace('/BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $migration);
$migration = preg_replace('/ENUM\([^)]*\)/', 'TEXT', $migration);
$migration = preg_replace('/ ON UPDATE CURRENT_TIMESTAMP/', '', $migration);
$migration = preg_replace('/^\s*(UNIQUE KEY|KEY) [^\n]*\n/m', '', $migration);
$migration = preg_replace('/,(\s*\n\))/', '$1', $migration);
$migration = preg_replace('/\) ENGINE=[^;]+;/', ');', $migration);
$migration = str_replace(['BIGINT UNSIGNED', 'JSON', '(255)'], ['INTEGER', 'TEXT', ''], $migration);
$pdo->exec($migration);

$pdo->exec("INSERT INTO departments (id, name) VALUES (1, '研发部'), (2, '财务部')");
$pdo->exec("INSERT INTO models (id, name, status) VALUES (1, 'gpt-a', 'active')");
$pdo->prepare('INSERT INTO users (id, department_id, username, password_hash, role) VALUES (?, ?, ?, ?, ?)')->execute([10, 1, 'alice', password_hash('correct horse', PASSWORD_DEFAULT), 'user']);
$pdo->prepare('INSERT INTO users (id, department_id, username, password_hash, role) VALUES (?, ?, ?, ?, ?)')->execute([11, 1, 'bob', password_hash('bob-pass', PASSWORD_DEFAULT), 'user']);
$pdo->prepare('INSERT INTO users (id, department_id, username, password_hash, role) VALUES (?, ?, ?, ?, ?)')->execute([20, 2, 'carol', password_hash('carol-pass', PASSWORD_DEFAULT), 'user']);
$plainKeys = [];
$addKey = static function (int $id, int $userId, int $departmentId, string $status = 'active') use ($pdo, &$plainKeys): void {
    $plain = 'tkh_test_' . $id . '_' . bin2hex(random_bytes(8));
    $plainKeys[$id] = $plain;
    $pdo->prepare("INSERT INTO api_keys (id, user_id, department_id, name, key_prefix, key_suffix, key_hash, allowed_ip_cidrs, status) VALUES (?, ?, ?, ?, 'tkh_test', ?, ?, '[]', ?)")
        ->execute([$id, $userId, $departmentId, 'key-' . $id, substr($plain, -4), Crypto::hashKey($plain), $status]);
};
$addKey(100, 10, 1);
$addKey(101, 10, 1);
$addKey(102, 11, 1);
$addKey(103, 11, 1, 'disabled');
$addKey(200, 20, 2);

$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);
(new ReflectionProperty(App::class, 'context'))->setValue(null, RequestContext::fromHeaders([]));

$now = time();
$clock = static function () use (&$now): int {
    return $now;
};
$documents = [];
$fetcher = new ClientMetadataFetcher(
    new OutboundUrlPolicy(static fn (string $host): array => ['93.184.216.34']),
    static function (string $url, string $address) use (&$documents): array {
        assert_same('93.184.216.34', $address, 'Metadata fetches are pinned to the checked address.');
        return isset($documents[$url]) ? ['status' => 200, 'body' => json_encode($documents[$url]), 'content_type' => 'application/json'] : ['status' => 404, 'body' => '', 'content_type' => null];
    }
);
$server = new OAuthServer('https://hub.example.com', $fetcher, $clock);
$expectOAuth = static function (callable $fn, string $error, string $message, ?bool $redirectable = null): OAuthException {
    try {
        $fn();
    } catch (OAuthException $exception) {
        assert_same($error, $exception->error, $message . ' (' . $exception->description . ')');
        if ($redirectable !== null) {
            assert_same($redirectable, $exception->redirectable, $message . ' (redirectable)');
        }
        return $exception;
    }
    throw new RuntimeException($message . ' Expected OAuth error ' . $error);
};
$verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
$challenge = OAuthServer::s256($verifier);
$query = static fn (array $client, string $redirect, array $extra = []): array => array_merge([
    'response_type' => 'code',
    'client_id' => $client['client_id'],
    'redirect_uri' => $redirect,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'state' => 'st-123',
    'resource' => 'https://hub.example.com/mcp',
    'scope' => 'mcp',
], $extra);
$codeFrom = static function (string $redirect): string {
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);
    assert_same('https://hub.example.com', $params['iss'] ?? null, 'Authorization responses carry iss (RFC 9207).');
    return (string) ($params['code'] ?? '');
};

// ---------------------------------------------------------------- discovery
$resourceMeta = $server->protectedResourceMetadata();
assert_same('https://hub.example.com/mcp', $resourceMeta['resource'], 'Protected resource metadata names /mcp.');
assert_same(['https://hub.example.com'], $resourceMeta['authorization_servers'], 'TokenHub is its own authorization server.');
$asMeta = $server->authorizationServerMetadata();
assert_same(['S256'], $asMeta['code_challenge_methods_supported'], 'Only S256 PKCE is offered.');
assert_same('https://hub.example.com/oauth/register', $asMeta['registration_endpoint'], 'DCR endpoint is advertised.');
assert_true($asMeta['authorization_response_iss_parameter_supported'] && $asMeta['client_id_metadata_document_supported'], 'iss and CIMD support are advertised.');
assert_true(str_contains($server->challenge(), 'resource_metadata="https://hub.example.com/.well-known/oauth-protected-resource/mcp"'), 'The 401 challenge points at the resource metadata.');

// ---------------------------------------------------------------- dynamic registration
$public = $server->register(['client_name' => 'Codex CLI', 'redirect_uris' => ['http://127.0.0.1:1455/auth/callback'], 'token_endpoint_auth_method' => 'none']);
assert_true(str_starts_with($public['client_id'], 'thmcp_client_') && !isset($public['client_secret']), 'Public clients get an id and no secret.');
$confidential = $server->register(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'client_secret_post']);
assert_true(str_starts_with($confidential['client_secret'] ?? '', 'thmcp_cs_'), 'Confidential clients get a secret.');
assert_same(Crypto::hashKey($confidential['client_secret']), $pdo->query("SELECT client_secret_hash FROM oauth_clients WHERE client_id = '{$confidential['client_id']}'")->fetchColumn(), 'Only the secret hash is stored.');
$expectOAuth(fn () => $server->register(['redirect_uris' => ['http://evil.example.com/cb']]), 'invalid_redirect_uri', 'Plain http redirects are refused outside loopback.');
$expectOAuth(fn () => $server->register(['redirect_uris' => ['javascript:alert(1)']]), 'invalid_redirect_uri', 'Script redirects are refused.');
$expectOAuth(fn () => $server->register(['redirect_uris' => []]), 'invalid_redirect_uri', 'At least one redirect is required.');
$expectOAuth(fn () => $server->register(['redirect_uris' => ['https://ok.example.com/cb'], 'grant_types' => ['client_credentials']]), 'invalid_client_metadata', 'Only code + refresh grants.');
$native = $server->register(['redirect_uris' => ['cursor://anysphere.cursor-mcp/oauth/callback']]);
assert_true($native['client_id'] !== '', 'Native app private-use schemes are accepted.');

// ---------------------------------------------------------------- authorization: validation before redirecting
$browser = bin2hex(random_bytes(32));
$expectOAuth(fn () => $server->startAuthorization($query(['client_id' => 'thmcp_client_unknown'], 'http://127.0.0.1:1455/auth/callback'), $browser, '10.0.0.5'), 'invalid_client', 'Unknown clients are rejected', false);
$expectOAuth(fn () => $server->startAuthorization($query($public, 'https://attacker.example.com/cb'), $browser, '10.0.0.5'), 'invalid_request', 'Unregistered redirect URIs are never redirected to', false);
$expectOAuth(fn () => $server->startAuthorization($query($public, 'http://127.0.0.1:1455/auth/callback', ['code_challenge_method' => 'plain']), $browser, '10.0.0.5'), 'invalid_request', 'Plain PKCE is refused', true);
$expectOAuth(fn () => $server->startAuthorization($query($public, 'http://127.0.0.1:1455/auth/callback', ['resource' => 'https://other.example.com/mcp']), $browser, '10.0.0.5'), 'invalid_target', 'Tokens are only issued for this resource (RFC 8707)', true);
$expectOAuth(fn () => $server->startAuthorization($query($public, 'http://127.0.0.1:1455/auth/callback', ['scope' => 'admin']), $browser, '10.0.0.5'), 'invalid_scope', 'Unknown scopes are refused', true);
// RFC 8252: a loopback redirect may come back on another port.
$started = $server->startAuthorization($query($public, 'http://127.0.0.1:53111/auth/callback'), $browser, '10.0.0.5');
assert_same('http://127.0.0.1:53111/auth/callback', $started['redirect_uri'], 'Loopback redirects match on any port.');
$expectOAuth(fn () => $server->pendingRequest($started['request_id'], bin2hex(random_bytes(32))), 'invalid_request', 'A sign-in link cannot be completed from another browser');

// ---------------------------------------------------------------- sign in with an API key
$expectOAuth(fn () => $server->completeAuthorization($started['request_id'], $browser, ['method' => 'api_key', 'api_key' => 'tkh_wrong'], '10.0.0.5'), 'access_denied', 'Wrong API keys are refused');
$approved = $server->completeAuthorization($started['request_id'], $browser, ['method' => 'api_key', 'api_key' => $plainKeys[102]], '10.0.0.5');
assert_same('redirect', $approved['status'], 'A valid key approves the request.');
assert_true(str_starts_with($approved['redirect'], 'http://127.0.0.1:53111/auth/callback?'), 'The code goes to the requested redirect URI.');
assert_true(str_contains($approved['redirect'], 'state=st-123'), 'state is returned.');
$code = $codeFrom($approved['redirect']);
assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'mcp_oauth.authorize'")->fetchColumn(), 'Authorizations are audited.');
$expectOAuth(fn () => $server->pendingRequest($started['request_id'], $browser), 'invalid_request', 'A pending request is single use');

// ---------------------------------------------------------------- code exchange
$tokenParams = ['grant_type' => 'authorization_code', 'client_id' => $public['client_id'], 'code' => $code, 'redirect_uri' => 'http://127.0.0.1:53111/auth/callback', 'code_verifier' => $verifier];
$expectOAuth(fn () => $server->token(['code_verifier' => str_repeat('x', 50)] + $tokenParams, null, null, '10.0.0.5'), 'invalid_grant', 'A wrong PKCE verifier is refused');
$expectOAuth(fn () => $server->token(['redirect_uri' => 'http://127.0.0.1:1/other'] + $tokenParams, null, null, '10.0.0.5'), 'invalid_grant', 'The redirect URI must match exactly');
$expectOAuth(fn () => $server->token(['client_id' => $native['client_id']] + $tokenParams, null, null, '10.0.0.5'), 'invalid_grant', 'Codes are bound to their client');
$tokens = $server->token($tokenParams, null, null, '10.0.0.5');
assert_true(str_starts_with($tokens['access_token'], 'thmcp_at_') && str_starts_with($tokens['refresh_token'], 'thmcp_rt_'), 'Access and refresh tokens are issued.');
assert_same(3600, $tokens['expires_in'], 'Access tokens live one hour.');
assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM oauth_tokens WHERE token_hash = '" . $tokens['access_token'] . "'")->fetchColumn(), 'Tokens are stored hashed.');

// /mcp accepts the access token as the bound API key
$auth = $server->authenticateAccessToken($tokens['access_token'], '10.0.0.5');
assert_true($auth['ok'] && (int) $auth['data']['id'] === 102, 'The access token authenticates as the chosen API key.');
assert_same(false, $server->authenticateAccessToken($tokens['refresh_token'], '10.0.0.5')['ok'], 'Refresh tokens cannot call /mcp.');
$elsewhere = new OAuthServer('https://other-hub.example.com', $fetcher, $clock);
assert_same(false, $elsewhere->authenticateAccessToken($tokens['access_token'], '10.0.0.5')['ok'], 'Tokens are bound to the issuing resource (audience).');

// replaying the code revokes what it issued
$expectOAuth(fn () => $server->token($tokenParams, null, null, '10.0.0.5'), 'invalid_grant', 'A code works once');
assert_same(false, $server->authenticateAccessToken($tokens['access_token'], '10.0.0.5')['ok'], 'Code replay revokes the tokens it issued.');

// ---------------------------------------------------------------- password login with a key choice, confidential client
$started = $server->startAuthorization($query($confidential, 'https://claude.ai/api/mcp/auth_callback'), $browser, '10.0.0.6');
$expectOAuth(fn () => $server->completeAuthorization($started['request_id'], $browser, ['method' => 'password', 'username' => 'alice', 'password' => 'wrong'], '10.0.0.6'), 'access_denied', 'Wrong passwords are refused');
$expectOAuth(fn () => $server->completeAuthorization($started['request_id'], $browser, ['method' => 'password', 'username' => 'nobody', 'password' => 'x'], '10.0.0.6'), 'access_denied', 'Unknown users get the same error');
$choice = $server->completeAuthorization($started['request_id'], $browser, ['method' => 'password', 'username' => 'alice', 'password' => 'correct horse'], '10.0.0.6');
assert_same('choose_key', $choice['status'], 'A user with several keys chooses one.');
assert_same([101, 100], array_map(static fn (array $key): int => $key['id'], $choice['keys']), 'Only the user\'s own active keys are offered.');
$expectOAuth(fn () => $server->completeAuthorization($started['request_id'], $browser, ['method' => 'password', 'api_key_id' => 200], '10.0.0.6'), 'access_denied', 'Another user\'s key cannot be chosen');
$approved = $server->completeAuthorization($started['request_id'], $browser, ['method' => 'password', 'api_key_id' => 100], '10.0.0.6');
$code = $codeFrom($approved['redirect']);
$secretParams = ['grant_type' => 'authorization_code', 'client_id' => $confidential['client_id'], 'code' => $code, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'code_verifier' => $verifier];
$expectOAuth(fn () => $server->token($secretParams + ['client_secret' => 'wrong'], null, null, '10.0.0.6'), 'invalid_client', 'Confidential clients must authenticate');
$tokens = $server->token($secretParams, $confidential['client_id'], $confidential['client_secret'], '10.0.0.6');
assert_true((int) $server->authenticateAccessToken($tokens['access_token'], '10.0.0.6')['data']['id'] === 100, 'Basic client authentication works and the chosen key is bound.');

// ---------------------------------------------------------------- refresh rotation, grace and replay
$refreshParams = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $confidential['client_id'], 'client_secret' => $confidential['client_secret']];
$rotated = $server->token($refreshParams, null, null, '10.0.0.6');
assert_true($rotated['refresh_token'] !== $tokens['refresh_token'], 'Refresh tokens rotate.');
$now += 30;
$concurrent = $server->token($refreshParams, null, null, '10.0.0.6');
assert_true($concurrent['access_token'] !== '', 'A concurrent refresh inside the grace period still succeeds.');
$now += 120;
$expectOAuth(fn () => $server->token($refreshParams, null, null, '10.0.0.6'), 'invalid_grant', 'A used refresh token after the grace period is refused');
assert_same(false, $server->authenticateAccessToken($rotated['access_token'], '10.0.0.6')['ok'], 'Refresh replay revokes the whole grant.');
assert_same('refresh_replay', $pdo->query("SELECT revoked_reason FROM oauth_grants WHERE api_key_id = 100 ORDER BY id DESC LIMIT 1")->fetchColumn(), 'The revocation reason is recorded.');

// ---------------------------------------------------------------- expiry, key status, revocation endpoint
$started = $server->startAuthorization($query($public, 'http://127.0.0.1:1455/auth/callback'), $browser, '10.0.0.7');
$code = $codeFrom($server->completeAuthorization($started['request_id'], $browser, ['method' => 'api_key', 'api_key' => $plainKeys[101]], '10.0.0.7')['redirect']);
$tokens = $server->token(['grant_type' => 'authorization_code', 'client_id' => $public['client_id'], 'code' => $code, 'redirect_uri' => 'http://127.0.0.1:1455/auth/callback', 'code_verifier' => $verifier], null, null, '10.0.0.7');
$now += 3601;
assert_same(false, $server->authenticateAccessToken($tokens['access_token'], '10.0.0.7')['ok'], 'Access tokens expire.');
$fresh = $server->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $public['client_id']], null, null, '10.0.0.7');
$pdo->exec("UPDATE api_keys SET status = 'disabled' WHERE id = 101");
App::redis()->connection()->flushDB();
assert_same(false, $server->authenticateAccessToken($fresh['access_token'], '10.0.0.7')['ok'], 'Disabling the API key ends the token.');
$pdo->exec("UPDATE api_keys SET status = 'active' WHERE id = 101");
App::redis()->connection()->flushDB();
assert_true($server->authenticateAccessToken($fresh['access_token'], '10.0.0.7')['ok'], 'Re-enabling the key restores it (the grant was not revoked).');
$server->revokeToken($server->client($native['client_id']), $fresh['refresh_token']);
assert_true($server->authenticateAccessToken($fresh['access_token'], '10.0.0.7')['ok'], 'Another client cannot revoke the tokens.');
$server->revokeToken($server->client($public['client_id']), $fresh['refresh_token']);
assert_same(false, $server->authenticateAccessToken($fresh['access_token'], '10.0.0.7')['ok'], 'Revoking the refresh token ends the grant (RFC 7009).');

// ---------------------------------------------------------------- password change ends grants
$started = $server->startAuthorization($query($public, 'http://127.0.0.1:1455/auth/callback'), $browser, '10.0.0.8');
$code = $codeFrom($server->completeAuthorization($started['request_id'], $browser, ['method' => 'password', 'username' => 'bob', 'password' => 'bob-pass'], '10.0.0.8')['redirect']);
$bobTokens = $server->token(['grant_type' => 'authorization_code', 'client_id' => $public['client_id'], 'code' => $code, 'redirect_uri' => 'http://127.0.0.1:1455/auth/callback', 'code_verifier' => $verifier], null, null, '10.0.0.8');
assert_same(102, (int) $server->authenticateAccessToken($bobTokens['access_token'], '10.0.0.8')['data']['id'], 'A user with one active key is signed in without a choice.');
OAuthServer::revokeUserGrantsAfterPasswordChange(11);
assert_same(false, $server->authenticateAccessToken($bobTokens['access_token'], '10.0.0.8')['ok'], 'Changing the password revokes the user\'s MCP sign-ins.');

// ---------------------------------------------------------------- admin listing and revocation respect the department scope
$started = $server->startAuthorization($query($public, 'http://127.0.0.1:1455/auth/callback'), $browser, '10.0.0.9');
$code = $codeFrom($server->completeAuthorization($started['request_id'], $browser, ['method' => 'api_key', 'api_key' => $plainKeys[200]], '10.0.0.9')['redirect']);
$carolTokens = $server->token(['grant_type' => 'authorization_code', 'client_id' => $public['client_id'], 'code' => $code, 'redirect_uri' => 'http://127.0.0.1:1455/auth/callback', 'code_verifier' => $verifier], null, null, '10.0.0.9');
$superAdmin = ['id' => 1, 'role' => 'super_admin', 'department_id' => 1];
$all = $server->listGrants($superAdmin, ['status' => 'all']);
assert_true(count($all) >= 5, 'Super admins see every grant.');
$active = $server->listGrants($superAdmin, ['api_key_id' => 200]);
assert_same(1, count($active), 'Grants can be filtered by API key.');
assert_same('Codex CLI', $active[0]['client_name'], 'Listings show the client name.');
$revoked = $server->adminRevoke($superAdmin, $active[0]['grant_id']);
assert_true($revoked['revoked'], 'Admins can revoke a grant.');
assert_same(false, $server->authenticateAccessToken($carolTokens['access_token'], '10.0.0.9')['ok'], 'Admin revocation ends the token.');

// ---------------------------------------------------------------- client ID metadata documents
$cimd = 'https://claude.ai/oauth/claude-code-client-metadata';
$documents[$cimd] = ['client_id' => $cimd, 'client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'], 'token_endpoint_auth_method' => 'none'];
$started = $server->startAuthorization(array_merge($query(['client_id' => $cimd], 'http://localhost:49152/callback')), $browser, '10.0.0.10');
assert_same('Claude Code', $started['client']['client_name'], 'Metadata documents provide the client name.');
$documents['https://bad.example.com/meta'] = ['client_id' => 'https://other.example.com/meta', 'redirect_uris' => ['http://127.0.0.1/cb']];
$expectOAuth(fn () => $server->client('https://bad.example.com/meta'), 'invalid_client', 'A document must name its own URL as client_id');
$expectOAuth(fn () => $server->client('https://missing.example.com/meta'), 'invalid_client', 'Unreachable documents are refused');
$privateFetcher = new ClientMetadataFetcher(new OutboundUrlPolicy(static fn (string $host): array => ['10.1.2.3']), static fn (): array => ['status' => 200, 'body' => '{}', 'content_type' => 'application/json']);
assert_same(false, $privateFetcher->fetch('https://intranet.example.com/meta')['ok'], 'Metadata documents on private addresses are refused (SSRF).');
assert_same(false, $privateFetcher->fetch('http://example.com/meta')['ok'], 'Metadata documents must use https.');

// ---------------------------------------------------------------- /mcp middleware and sign-in page
$request = static function (string $method, string $path, array $headers = [], array $body = [], array $query = []): Request {
    $server = ['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '10.0.0.20', 'HTTPS' => 'on', 'HTTP_HOST' => 'hub.example.com'];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    return new Request($method, $path, $server, $query, $body, json_encode($body) ?: '', RequestContext::fromHeaders([]));
};
$next = static fn (Request $request): Response => Response::ok(['api_key_id' => (int) (App::context()->apiKey()['id'] ?? 0)]);
$unauthenticated = (new McpAuthMiddleware())($request('POST', '/mcp'), $next);
assert_same(401, $unauthenticated->status, 'Missing credentials get 401.');
assert_true(str_contains($unauthenticated->headers['WWW-Authenticate'] ?? '', 'resource_metadata="https://hub.example.com/.well-known/oauth-protected-resource/mcp"'), '401 from /mcp advertises the OAuth metadata.');
$realServer = new OAuthServer('https://hub.example.com');
$started = $realServer->startAuthorization($query($public, 'http://127.0.0.1:1455/auth/callback'), $browser, '10.0.0.20');
$code = $codeFrom($realServer->completeAuthorization($started['request_id'], $browser, ['method' => 'api_key', 'api_key' => $plainKeys[100]], '10.0.0.20')['redirect']);
$live = $realServer->token(['grant_type' => 'authorization_code', 'client_id' => $public['client_id'], 'code' => $code, 'redirect_uri' => 'http://127.0.0.1:1455/auth/callback', 'code_verifier' => $verifier], null, null, '10.0.0.20');
$ok = (new McpAuthMiddleware())($request('POST', '/mcp', ['Authorization' => 'Bearer ' . $live['access_token']]), $next);
assert_same(200, $ok->status, 'The middleware accepts OAuth access tokens.');
assert_same(100, json_decode($ok->body, true)['data']['api_key_id'], 'The bound API key becomes the request\'s key.');
$bad = (new McpAuthMiddleware())($request('POST', '/mcp', ['Authorization' => 'Bearer thmcp_at_' . str_repeat('0', 64)]), $next);
assert_same(401, $bad->status, 'Unknown access tokens get 401.');
assert_true(str_contains($bad->headers['WWW-Authenticate'] ?? '', 'error="invalid_token"'), 'Invalid tokens are reported as invalid_token.');

$controller = new OAuthController();
$_COOKIE = [];
$page = $controller->authorize($request('GET', '/oauth/authorize', [], [], $query($public, 'http://127.0.0.1:1455/auth/callback')));
assert_same(200, $page->status, 'The sign-in page renders.');
assert_true(str_contains($page->body, 'Codex CLI') && str_contains($page->body, '127.0.0.1') && str_contains($page->body, '粘贴 API Key') && str_contains($page->body, '用账号密码登录'), 'The page names the client, its redirect host and both sign-in methods.');
assert_true(str_contains($page->headers['Content-Security-Policy'] ?? '', "frame-ancestors 'none'") && !str_contains($page->headers['Content-Security-Policy'] ?? '', 'script-src'), 'The consent page cannot be framed and runs no script.');
assert_true(str_starts_with($page->headers['Set-Cookie'] ?? '', '__Host-tokenhub_oauth='), 'The browser binding cookie uses the __Host- prefix over https.');
$evil = $controller->authorize($request('GET', '/oauth/authorize', [], [], $query($public, 'https://attacker.example.com/cb')));
assert_same(400, $evil->status, 'An unregistered redirect shows an error page.');
assert_true(!isset($evil->headers['Location']), 'An unregistered redirect is never followed (open redirect).');
$redirected = $controller->authorize($request('GET', '/oauth/authorize', [], [], $query($public, 'http://127.0.0.1:1455/auth/callback', ['code_challenge_method' => 'plain'])));
assert_same(303, $redirected->status, 'Errors after redirect verification go back to the client.');
assert_true(str_contains($redirected->headers['Location'] ?? '', 'error=invalid_request') && str_contains($redirected->headers['Location'] ?? '', 'iss='), 'The error redirect carries error and iss.');

// ---------------------------------------------------------------- console sign-in throttling
App::redis()->connection()->flushDB();
$admin = new \TokenHub\Http\Controllers\AdminController();
$statuses = [];
for ($i = 0; $i < 11; $i++) {
    $loginRequest = new Request('POST', '/admin-api/login', ['REMOTE_ADDR' => '10.9.9.' . $i], [], ['username' => 'alice', 'password' => 'guess-' . $i], '', RequestContext::fromHeaders([]));
    $statuses[] = $admin->login($loginRequest)->status;
}
assert_same(array_fill(0, 10, 401), array_slice($statuses, 0, 10), 'Wrong console passwords get 401 up to the limit.');
assert_same(429, $statuses[10], 'Guessing one username from many addresses is throttled per username.');

echo "PASS mcp oauth flow\n";
