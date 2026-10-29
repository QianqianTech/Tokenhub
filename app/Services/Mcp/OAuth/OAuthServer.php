<?php

declare(strict_types=1);

namespace TokenHub\Services\Mcp\OAuth;

use TokenHub\Services\ApiKeyService;
use TokenHub\Services\AuditService;
use TokenHub\Services\ExternalRateLimitService;
use TokenHub\Services\PermissionService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

/**
 * Built-in OAuth 2.1 authorization server for the MCP endpoint.
 *
 * - Discovery: RFC 9728 protected resource metadata, RFC 8414 server metadata.
 * - Clients: RFC 7591 dynamic registration, or Client ID Metadata Documents
 *   (an https client_id that serves the client metadata).
 * - Authorization code + PKCE S256 only; RFC 8707 resource binding (tokens
 *   are only valid for this TokenHub's /mcp); RFC 9207 iss in the response;
 *   RFC 8252 loopback redirects on any port.
 * - Every grant is bound to one API key chosen at sign-in (password login,
 *   or by pasting the key); tokens carry exactly that key's permissions and
 *   stop working when the key, its user or department is disabled.
 * - Access tokens 1 h, refresh tokens 30 days, rotated on use; a concurrent
 *   refresh within the grace period still succeeds, a later replay of a used
 *   refresh token revokes the whole grant (token theft detection).
 * - Only HMAC hashes of codes, tokens and client secrets are stored.
 */
final class OAuthServer
{
    public const SCOPE = 'mcp';
    private const ACCESS_PREFIX = 'thmcp_at_';
    private const REFRESH_PREFIX = 'thmcp_rt_';
    private const CODE_PREFIX = 'thmcp_ac_';
    private const REQUEST_TTL_SECONDS = 600;
    private const METADATA_REFRESH_SECONDS = 3600;
    /** Callback URLs of hosted MCP clients, accepted even with an allowlist. */
    private const HOSTED_CALLBACKS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
        'https://chatgpt.com/connector_platform_oauth_redirect',
    ];
    private const FORBIDDEN_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss'];

    private static ?string $dummyHash = null;

    /** @var (callable(): int)|null */
    private $clock;

    public function __construct(
        private readonly string $issuer,
        private readonly ?ClientMetadataFetcher $fetcher = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * Issuer = configured public URL, then APP_URL, then the request origin.
     */
    public static function issuerFor(string $requestOrigin): string
    {
        $configured = trim((string) App::config()->get('app.mcp.public_url', ''));
        if ($configured === '') {
            // APP_URL unless it is still the local default.
            $appUrl = trim((string) App::config()->get('app.url', ''));
            $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
            $configured = in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true) ? '' : $appUrl;
        }

        return rtrim($configured !== '' ? $configured : $requestOrigin, '/');
    }

    public static function enabled(): bool
    {
        return self::flag('enabled', true);
    }

    public function resource(): string
    {
        return $this->issuer . '/mcp';
    }

    // ------------------------------------------------------------------ discovery

    /** RFC 9728: /.well-known/oauth-protected-resource/mcp */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'TokenHub MCP',
        ];
    }

    /** RFC 8414: /.well-known/oauth-authorization-server */
    public function authorizationServerMetadata(): array
    {
        $metadata = [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->issuer . '/oauth/authorize',
            'token_endpoint' => $this->issuer . '/oauth/token',
            'revocation_endpoint' => $this->issuer . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => [self::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => self::flag('client_metadata_documents', true),
        ];
        if (self::flag('dynamic_registration', true)) {
            $metadata['registration_endpoint'] = $this->issuer . '/oauth/register';
        }

        return $metadata;
    }

    /** WWW-Authenticate challenge for a 401 from /mcp (RFC 9728 section 5.1). */
    public function challenge(?string $error = null, ?string $description = null): string
    {
        $parts = ['realm="tokenhub"', 'resource_metadata="' . $this->issuer . '/.well-known/oauth-protected-resource/mcp"', 'scope="' . self::SCOPE . '"'];
        if ($error !== null) {
            $parts[] = 'error="' . $error . '"';
        }
        if ($description !== null) {
            $parts[] = 'error_description="' . str_replace('"', "'", $description) . '"';
        }

        return 'Bearer ' . implode(', ', $parts);
    }

    // ------------------------------------------------------------------ clients

    /**
     * RFC 7591 dynamic client registration.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function register(array $metadata): array
    {
        if (!self::flag('dynamic_registration', true)) {
            throw new OAuthException('access_denied', 'Dynamic client registration is disabled on this TokenHub', 403);
        }
        $redirects = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirects) || $redirects === [] || count($redirects) > 10) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris must list 1 to 10 URIs');
        }
        $redirects = array_values(array_unique(array_map('strval', $redirects)));
        foreach ($redirects as $redirect) {
            $this->assertRegistrableRedirect($redirect);
        }
        $authMethod = (string) ($metadata['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            throw new OAuthException('invalid_client_metadata', 'Unsupported token_endpoint_auth_method');
        }
        foreach ((array) ($metadata['grant_types'] ?? ['authorization_code', 'refresh_token']) as $grantType) {
            if (!in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
                throw new OAuthException('invalid_client_metadata', 'Unsupported grant type ' . (string) $grantType);
            }
        }
        foreach ((array) ($metadata['response_types'] ?? ['code']) as $responseType) {
            if ($responseType !== 'code') {
                throw new OAuthException('invalid_client_metadata', 'Only the code response type is supported');
            }
        }
        $name = mb_substr(trim((string) ($metadata['client_name'] ?? '')), 0, 120);
        $clientUri = $this->optionalHttpsUrl($metadata['client_uri'] ?? null);

        $clientId = 'thmcp_client_' . bin2hex(random_bytes(12));
        $secret = $authMethod === 'none' ? null : 'thmcp_cs_' . bin2hex(random_bytes(24));
        $stored = array_filter([
            'client_name' => $name !== '' ? $name : null,
            'client_uri' => $clientUri,
            'software_id' => isset($metadata['software_id']) ? mb_substr((string) $metadata['software_id'], 0, 120) : null,
            'software_version' => isset($metadata['software_version']) ? mb_substr((string) $metadata['software_version'], 0, 40) : null,
        ], static fn ($value) => $value !== null);
        App::db()->execute(
            'INSERT INTO oauth_clients (client_id, client_type, client_name, client_uri, redirect_uris, token_endpoint_auth_method, client_secret_hash, metadata, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'dynamic', $name !== '' ? $name : null, $clientUri, json_encode($redirects, JSON_UNESCAPED_SLASHES), $authMethod, $secret === null ? null : Crypto::hashKey($secret), json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'active', $this->now(), $this->now()]
        );

        return array_filter([
            'client_id' => $clientId,
            'client_secret' => $secret,
            'client_id_issued_at' => $this->time(),
            'client_secret_expires_at' => $secret === null ? null : 0,
            'client_name' => $name !== '' ? $name : null,
            'client_uri' => $clientUri,
            'redirect_uris' => $redirects,
            'token_endpoint_auth_method' => $authMethod,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => self::SCOPE,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array{client_id: string, client_name: ?string, client_uri: ?string, redirect_uris: list<string>, token_endpoint_auth_method: string, client_secret_hash: ?string, client_type: string}
     */
    public function client(string $clientId): array
    {
        $clientId = trim($clientId);
        if ($clientId === '' || strlen($clientId) > 512) {
            throw new OAuthException('invalid_client', 'client_id is missing or invalid', 401);
        }
        if (str_starts_with($clientId, 'https://')) {
            return $this->metadataDocumentClient($clientId);
        }
        $row = App::db()->first('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]);
        if (!$row || ($row['status'] ?? '') !== 'active') {
            throw new OAuthException('invalid_client', 'Unknown client_id', 401);
        }

        return $this->clientFromRow($row);
    }

    private function metadataDocumentClient(string $clientId): array
    {
        if (!self::flag('client_metadata_documents', true)) {
            throw new OAuthException('invalid_client', 'Client ID metadata documents are disabled on this TokenHub; register the client instead', 401);
        }
        $row = App::db()->first('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]);
        $fresh = $row && $row['metadata_fetched_at'] !== null && strtotime((string) $row['metadata_fetched_at']) > $this->time() - self::METADATA_REFRESH_SECONDS;
        if ($row && ($row['status'] ?? '') !== 'active') {
            throw new OAuthException('invalid_client', 'This client is disabled', 401);
        }
        if ($fresh) {
            return $this->clientFromRow($row);
        }
        $result = ($this->fetcher ?? new ClientMetadataFetcher())->fetch($clientId);
        if (!$result['ok']) {
            if ($row) {
                return $this->clientFromRow($row);
            }
            throw new OAuthException('invalid_client', 'Could not load the client metadata document: ' . ($result['error'] ?? 'unknown error'), 401);
        }
        $document = $result['document'];
        if (($document['client_id'] ?? null) !== $clientId) {
            throw new OAuthException('invalid_client', 'The client metadata document must contain its own URL as client_id', 401);
        }
        $redirects = $document['redirect_uris'] ?? null;
        if (!is_array($redirects) || $redirects === []) {
            throw new OAuthException('invalid_client', 'The client metadata document has no redirect_uris', 401);
        }
        $redirects = array_values(array_unique(array_map('strval', array_slice($redirects, 0, 20))));
        foreach ($redirects as $redirect) {
            $this->assertRegistrableRedirect($redirect, false);
        }
        $authMethod = (string) ($document['token_endpoint_auth_method'] ?? 'none');
        if ($authMethod !== 'none') {
            throw new OAuthException('invalid_client', 'Metadata document clients must be public clients (token_endpoint_auth_method none)', 401);
        }
        $name = mb_substr(trim((string) ($document['client_name'] ?? '')), 0, 120);
        $clientUri = $this->optionalHttpsUrl($document['client_uri'] ?? null);
        $values = [$name !== '' ? $name : null, $clientUri, json_encode($redirects, JSON_UNESCAPED_SLASHES), json_encode(['client_name' => $name, 'client_uri' => $clientUri], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->now(), $this->now()];
        if ($row) {
            App::db()->execute('UPDATE oauth_clients SET client_name = ?, client_uri = ?, redirect_uris = ?, metadata = ?, metadata_fetched_at = ?, updated_at = ? WHERE client_id = ?', [...$values, $clientId]);
        } else {
            App::db()->execute(
                "INSERT INTO oauth_clients (client_id, client_type, client_name, client_uri, redirect_uris, metadata, metadata_fetched_at, updated_at, token_endpoint_auth_method, status, created_at) VALUES (?, 'metadata_document', ?, ?, ?, ?, ?, ?, 'none', 'active', ?)",
                [$clientId, ...$values, $this->now()]
            );
        }

        return $this->clientFromRow(App::db()->first('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]) ?: []);
    }

    private function clientFromRow(array $row): array
    {
        $redirects = json_decode((string) ($row['redirect_uris'] ?? '[]'), true);

        return [
            'client_id' => (string) $row['client_id'],
            'client_type' => (string) ($row['client_type'] ?? 'dynamic'),
            'client_name' => $row['client_name'] !== null && $row['client_name'] !== '' ? (string) $row['client_name'] : null,
            'client_uri' => $row['client_uri'] ?? null,
            'redirect_uris' => is_array($redirects) ? array_values(array_map('strval', $redirects)) : [],
            'token_endpoint_auth_method' => (string) ($row['token_endpoint_auth_method'] ?? 'none'),
            'client_secret_hash' => $row['client_secret_hash'] ?? null,
        ];
    }

    /**
     * Authenticates the client at the token/revocation endpoint.
     *
     * @param array<string, mixed> $params form parameters
     */
    public function authenticateClient(array $params, ?string $basicUser, ?string $basicPassword): array
    {
        $clientId = $basicUser !== null ? rawurldecode($basicUser) : (string) ($params['client_id'] ?? '');
        $client = $this->client($clientId);
        if ($client['token_endpoint_auth_method'] === 'none') {
            return $client;
        }
        $secret = $basicPassword !== null ? rawurldecode($basicPassword) : (string) ($params['client_secret'] ?? '');
        if ($secret === '' || $client['client_secret_hash'] === null || !hash_equals((string) $client['client_secret_hash'], Crypto::hashKey($secret))) {
            throw new OAuthException('invalid_client', 'Client authentication failed', 401);
        }

        return $client;
    }

    public function redirectAllowed(array $client, string $redirectUri): bool
    {
        foreach ($client['redirect_uris'] as $registered) {
            if (hash_equals($registered, $redirectUri)) {
                return true;
            }
            // RFC 8252 section 7.3: loopback redirects may use any port.
            if ($this->isLoopback($registered) && $this->isLoopback($redirectUri) && $this->withoutPort($registered) === $this->withoutPort($redirectUri)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ authorization

    /**
     * Validates GET /oauth/authorize and stores the pending request.
     *
     * @param array<string, mixed> $query
     * @return array{request_id: string, client: array<string, mixed>, redirect_uri: string, state: ?string}
     */
    public function startAuthorization(array $query, string $browserToken, string $clientIp): array
    {
        $this->throttle('oauth-authorize', $clientIp, (int) App::config()->get('app.mcp.oauth.authorize_per_minute', 30));
        // Until client_id and redirect_uri are verified, errors are shown on
        // the page and never redirected (open redirect protection).
        $client = $this->client((string) ($query['client_id'] ?? ''));
        $redirectUri = (string) ($query['redirect_uri'] ?? '');
        if ($redirectUri === '' && count($client['redirect_uris']) === 1) {
            $redirectUri = $client['redirect_uris'][0];
        }
        if ($redirectUri === '' || !$this->redirectAllowed($client, $redirectUri)) {
            throw new OAuthException('invalid_request', 'redirect_uri is not registered for this client');
        }

        $state = isset($query['state']) ? mb_substr((string) $query['state'], 0, 1024) : null;
        if (($query['response_type'] ?? '') !== 'code') {
            throw new OAuthException('unsupported_response_type', 'response_type must be code', 400, true);
        }
        $challenge = (string) ($query['code_challenge'] ?? '');
        if (($query['code_challenge_method'] ?? '') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) !== 1) {
            throw new OAuthException('invalid_request', 'PKCE with code_challenge_method S256 is required', 400, true);
        }
        $resource = $this->requestedResource($query['resource'] ?? null, true);
        $scope = trim((string) ($query['scope'] ?? ''));
        if ($scope !== '' && !in_array(self::SCOPE, preg_split('/\s+/', $scope) ?: [], true)) {
            throw new OAuthException('invalid_scope', 'The only supported scope is mcp', 400, true);
        }

        $requestId = 'thmcp_rq_' . bin2hex(random_bytes(24));
        App::db()->execute(
            'INSERT INTO oauth_authorization_requests (request_hash, browser_hash, client_id, redirect_uri, state, scope, resource, code_challenge, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Crypto::hashKey($requestId), Crypto::hashKey($browserToken), $client['client_id'], $redirectUri, $state, self::SCOPE, $resource, $challenge, $this->at(self::REQUEST_TTL_SECONDS), $this->now()]
        );

        return ['request_id' => $requestId, 'client' => $client, 'redirect_uri' => $redirectUri, 'state' => $state];
    }

    /**
     * The pending request for the sign-in page (only from the same browser).
     */
    public function pendingRequest(string $requestId, string $browserToken): array
    {
        $row = $requestId === '' ? null : App::db()->first('SELECT * FROM oauth_authorization_requests WHERE request_hash = ?', [Crypto::hashKey($requestId)]);
        if (!$row || !hash_equals((string) $row['browser_hash'], Crypto::hashKey($browserToken)) || strtotime((string) $row['expires_at']) < $this->time()) {
            throw new OAuthException('invalid_request', 'This sign-in link has expired or was opened in another browser. Start the connection again from your MCP client.');
        }
        $row['client'] = $this->client((string) $row['client_id']);

        return $row;
    }

    /**
     * Signs the user in and, once an API key is chosen, issues the code.
     *
     * @param array<string, mixed> $form method=api_key&api_key=... | method=password&username=&password= [&api_key_id=]
     * @return array{status: 'redirect', redirect: string}|array{status: 'choose_key', keys: list<array<string, mixed>>, username: string}|array{status: 'denied', redirect: string}
     */
    public function completeAuthorization(string $requestId, string $browserToken, array $form, string $clientIp): array
    {
        $pending = $this->pendingRequest($requestId, $browserToken);
        if (($form['decision'] ?? 'allow') === 'deny') {
            $this->deleteRequest($requestId);
            return ['status' => 'denied', 'redirect' => $this->redirectWith((string) $pending['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user denied the request', 'state' => $pending['state']])];
        }

        $method = (string) ($form['method'] ?? '');
        if ($method === 'api_key') {
            if (!self::flag('api_key_login', true)) {
                throw new OAuthException('access_denied', 'Signing in with an API key is disabled', 403);
            }
            $this->throttle('oauth-login-ip', $clientIp, (int) App::config()->get('app.mcp.oauth.login_attempts_per_minute', 10));
            $result = (new ApiKeyService())->authenticate(trim((string) ($form['api_key'] ?? '')), $clientIp, null);
            if (!$result['ok']) {
                throw new OAuthException('access_denied', $result['code'] === 'API_KEY_EXPIRED' ? 'This API key has expired.' : ($result['code'] === 'IP_FORBIDDEN' ? 'This API key may not be used from your network.' : 'The API key is invalid or disabled.'), 401);
            }
            $key = $result['data'];

            return $this->approve($requestId, $pending, (int) $key['id'], (int) $key['user_id'], 'api_key');
        }

        if ($method !== 'password') {
            throw new OAuthException('invalid_request', 'Choose how to sign in');
        }
        if (!self::flag('password_login', true)) {
            throw new OAuthException('access_denied', 'Signing in with a password is disabled', 403);
        }

        // Second step: the user already signed in on this request and picks a key.
        if (!empty($pending['pending_user_id']) && isset($form['api_key_id'])) {
            $userId = (int) $pending['pending_user_id'];
            $keyId = (int) $form['api_key_id'];
            $choices = $this->userKeys($userId, $clientIp);
            if (!in_array($keyId, array_map(static fn (array $key): int => (int) $key['id'], $choices), true)) {
                throw new OAuthException('access_denied', 'Choose one of your active API keys.', 403);
            }

            return $this->approve($requestId, $pending, $keyId, $userId, 'password');
        }

        $this->throttle('oauth-login-ip', $clientIp, (int) App::config()->get('app.mcp.oauth.login_attempts_per_minute', 10));
        $username = trim((string) ($form['username'] ?? ''));
        $user = $username === '' ? null : App::db()->first('SELECT u.id, u.username, u.password_hash, u.status, d.status AS department_status FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.username = ?', [$username]);
        if ($user) {
            // Per account, so spreading guesses over many addresses does not help.
            $this->throttle('oauth-login-user', (int) $user['id'], (int) App::config()->get('app.mcp.oauth.login_attempts_per_minute', 10));
        }
        $hash = is_string($user['password_hash'] ?? null) && $user['password_hash'] !== '' ? (string) $user['password_hash'] : self::dummyHash();
        // Always run password_verify so unknown usernames take as long as known ones.
        $valid = password_verify((string) ($form['password'] ?? ''), $hash) && $user !== null && $hash !== self::dummyHash();
        if (!$valid || ($user['status'] ?? '') !== 'active' || ($user['department_status'] ?? 'active') !== 'active') {
            throw new OAuthException('access_denied', 'Incorrect username or password.', 401);
        }

        $keys = $this->userKeys((int) $user['id'], $clientIp);
        if ($keys === []) {
            throw new OAuthException('access_denied', 'Your account has no active API key. Ask an administrator to issue one, then try again.', 403);
        }
        if (count($keys) === 1) {
            return $this->approve($requestId, $pending, (int) $keys[0]['id'], (int) $user['id'], 'password');
        }
        App::db()->execute('UPDATE oauth_authorization_requests SET pending_user_id = ? WHERE request_hash = ?', [(int) $user['id'], Crypto::hashKey($requestId)]);

        return ['status' => 'choose_key', 'keys' => $keys, 'username' => (string) $user['username']];
    }

    /**
     * Active, unexpired API keys of a user that may be used from this address.
     *
     * @return list<array{id: int, name: string, display: string}>
     */
    private function userKeys(int $userId, string $clientIp): array
    {
        $rows = App::db()->select("SELECT id, name, key_prefix, key_suffix, expires_at, allowed_ip_cidrs FROM api_keys WHERE user_id = ? AND status = 'active' ORDER BY id DESC", [$userId]);
        $service = new ApiKeyService();
        $keys = [];
        foreach ($rows as $row) {
            if ($row['expires_at'] && strtotime((string) $row['expires_at']) < $this->time()) {
                continue;
            }
            if (!$service->ipAllowed($clientIp, json_decode((string) ($row['allowed_ip_cidrs'] ?? '[]'), true) ?: [])) {
                continue;
            }
            $keys[] = ['id' => (int) $row['id'], 'name' => (string) ($row['name'] ?: 'API Key #' . $row['id']), 'display' => $row['key_prefix'] . '…' . $row['key_suffix']];
        }

        return $keys;
    }

    private function approve(string $requestId, array $pending, int $apiKeyId, int $userId, string $loginMethod): array
    {
        $grantId = 'grt_' . bin2hex(random_bytes(12));
        $code = self::CODE_PREFIX . bin2hex(random_bytes(32));
        $codeTtl = max(30, (int) App::config()->get('app.mcp.oauth.code_ttl_seconds', 300));
        App::db()->transaction(function () use ($grantId, $code, $codeTtl, $pending, $apiKeyId, $userId, $loginMethod, $requestId): void {
            App::db()->execute(
                "INSERT INTO oauth_grants (grant_id, client_id, api_key_id, user_id, scope, resource, login_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)",
                [$grantId, $pending['client_id'], $apiKeyId, $userId, self::SCOPE, $pending['resource'], $loginMethod, $this->now()]
            );
            App::db()->execute(
                'INSERT INTO oauth_authorization_codes (code_hash, grant_id, client_id, redirect_uri, code_challenge, resource, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [Crypto::hashKey($code), $grantId, $pending['client_id'], $pending['redirect_uri'], $pending['code_challenge'], $pending['resource'], $this->at($codeTtl), $this->now()]
            );
            $this->deleteRequest($requestId);
        });
        (new AuditService())->write('mcp_oauth.authorize', 'api_keys', $apiKeyId, ['grant_id' => $grantId, 'user_id' => $userId, 'client_id' => $pending['client_id'], 'client_name' => $pending['client']['client_name'] ?? null, 'login_method' => $loginMethod]);

        return ['status' => 'redirect', 'redirect' => $this->redirectWith((string) $pending['redirect_uri'], ['code' => $code, 'state' => $pending['state']])];
    }

    /** Builds a redirect to the client with RFC 9207 iss. */
    public function redirectWith(string $redirectUri, array $params): string
    {
        $params['iss'] = $this->issuer;
        $params = array_filter($params, static fn ($value) => $value !== null && $value !== '');
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function deleteRequest(string $requestId): void
    {
        App::db()->execute('DELETE FROM oauth_authorization_requests WHERE request_hash = ?', [Crypto::hashKey($requestId)]);
    }

    // ------------------------------------------------------------------ tokens

    /**
     * POST /oauth/token
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function token(array $params, ?string $basicUser, ?string $basicPassword, string $clientIp): array
    {
        $client = $this->authenticateClient($params, $basicUser, $basicPassword);
        $grantType = (string) ($params['grant_type'] ?? '');
        if ($grantType === 'authorization_code') {
            return $this->exchangeCode($client, $params, $clientIp);
        }
        if ($grantType === 'refresh_token') {
            return $this->refresh($client, $params, $clientIp);
        }

        throw new OAuthException('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token');
    }

    private function exchangeCode(array $client, array $params, string $clientIp): array
    {
        $code = (string) ($params['code'] ?? '');
        $row = $code === '' ? null : App::db()->first('SELECT * FROM oauth_authorization_codes WHERE code_hash = ?', [Crypto::hashKey($code)]);
        if (!$row || !hash_equals((string) $row['client_id'], $client['client_id'])) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid');
        }
        if ($row['used_at'] !== null) {
            // RFC 6749 section 4.1.2: a reused code revokes what it issued.
            $this->revokeGrant((string) $row['grant_id'], 'code_replay');
            throw new OAuthException('invalid_grant', 'The authorization code was already used');
        }
        if (strtotime((string) $row['expires_at']) < $this->time()) {
            throw new OAuthException('invalid_grant', 'The authorization code has expired');
        }
        if (!hash_equals((string) $row['redirect_uri'], (string) ($params['redirect_uri'] ?? ''))) {
            throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization request');
        }
        $verifier = (string) ($params['code_verifier'] ?? '');
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1 || !hash_equals((string) $row['code_challenge'], self::s256($verifier))) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed');
        }
        if (isset($params['resource']) && $params['resource'] !== '' && $this->normalizeResource((string) $params['resource']) !== $row['resource']) {
            throw new OAuthException('invalid_target', 'resource does not match the authorization request');
        }
        $claimed = App::db()->execute('UPDATE oauth_authorization_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL', [$this->now(), $row['code_hash']]);
        if ($claimed === 0) {
            $this->revokeGrant((string) $row['grant_id'], 'code_replay');
            throw new OAuthException('invalid_grant', 'The authorization code was already used');
        }

        $grant = $this->activeGrant((string) $row['grant_id'], $clientIp);

        return $this->issueTokens($grant);
    }

    private function refresh(array $client, array $params, string $clientIp): array
    {
        $token = (string) ($params['refresh_token'] ?? '');
        $row = str_starts_with($token, self::REFRESH_PREFIX) ? App::db()->first("SELECT * FROM oauth_tokens WHERE token_hash = ? AND token_type = 'refresh'", [Crypto::hashKey($token)]) : null;
        if (!$row || !hash_equals((string) $row['client_id'], $client['client_id']) || $row['revoked_at'] !== null || strtotime((string) $row['expires_at']) < $this->time()) {
            throw new OAuthException('invalid_grant', 'The refresh token is invalid or expired');
        }
        if (isset($params['resource']) && $params['resource'] !== '' && $this->normalizeResource((string) $params['resource']) !== $row['resource']) {
            throw new OAuthException('invalid_target', 'resource does not match the grant');
        }
        if ($row['rotated_at'] !== null) {
            $grace = max(0, (int) App::config()->get('app.mcp.oauth.refresh_grace_seconds', 60));
            if (strtotime((string) $row['rotated_at']) + $grace < $this->time()) {
                // A used refresh token came back after the grace period: treat
                // it as stolen and end the whole grant.
                $this->revokeGrant((string) $row['grant_id'], 'refresh_replay');
                throw new OAuthException('invalid_grant', 'The refresh token was already used; sign in again');
            }
        } else {
            $claimed = App::db()->execute('UPDATE oauth_tokens SET rotated_at = ? WHERE token_hash = ? AND rotated_at IS NULL', [$this->now(), $row['token_hash']]);
            if ($claimed === 0) {
                // A concurrent refresh rotated it a moment ago; within the grace period.
                $fresh = App::db()->first('SELECT rotated_at FROM oauth_tokens WHERE token_hash = ?', [$row['token_hash']]);
                $grace = max(0, (int) App::config()->get('app.mcp.oauth.refresh_grace_seconds', 60));
                if (!$fresh || $fresh['rotated_at'] === null || strtotime((string) $fresh['rotated_at']) + $grace < $this->time()) {
                    $this->revokeGrant((string) $row['grant_id'], 'refresh_replay');
                    throw new OAuthException('invalid_grant', 'The refresh token was already used; sign in again');
                }
            }
        }

        $grant = $this->activeGrant((string) $row['grant_id'], $clientIp);

        return $this->issueTokens($grant);
    }

    private function activeGrant(string $grantId, string $clientIp): array
    {
        $grant = App::db()->first('SELECT * FROM oauth_grants WHERE grant_id = ?', [$grantId]);
        if (!$grant || $grant['status'] !== 'active') {
            throw new OAuthException('invalid_grant', 'This authorization was revoked; sign in again');
        }
        $key = (new ApiKeyService())->authenticateById((int) $grant['api_key_id'], $clientIp);
        if (!$key['ok']) {
            throw new OAuthException('invalid_grant', 'The API key behind this authorization is no longer usable (' . $key['code'] . ')');
        }

        return $grant;
    }

    private function issueTokens(array $grant): array
    {
        $access = self::ACCESS_PREFIX . bin2hex(random_bytes(32));
        $refresh = self::REFRESH_PREFIX . bin2hex(random_bytes(32));
        $accessTtl = max(60, (int) App::config()->get('app.mcp.oauth.access_token_ttl_seconds', 3600));
        $refreshTtl = max($accessTtl, (int) App::config()->get('app.mcp.oauth.refresh_token_ttl_seconds', 2592000));
        foreach ([[$access, 'access', $accessTtl], [$refresh, 'refresh', $refreshTtl]] as [$token, $type, $ttl]) {
            App::db()->execute(
                'INSERT INTO oauth_tokens (token_hash, token_type, grant_id, client_id, resource, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [Crypto::hashKey($token), $type, $grant['grant_id'], $grant['client_id'], $grant['resource'], $this->at($ttl), $this->now()]
            );
        }

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ];
    }

    /**
     * Resolves a bearer access token presented to /mcp to its API key row.
     *
     * @return array{ok: bool, data?: array<string, mixed>, grant_id?: string, code?: string, message?: string, status?: int}
     */
    public function authenticateAccessToken(string $token, string $clientIp): array
    {
        $row = App::db()->first("SELECT t.expires_at, t.revoked_at, t.resource, g.grant_id, g.status AS grant_status, g.api_key_id, g.last_used_at FROM oauth_tokens t JOIN oauth_grants g ON g.grant_id = t.grant_id WHERE t.token_hash = ? AND t.token_type = 'access'", [Crypto::hashKey($token)]);
        if (!$row || $row['revoked_at'] !== null || $row['grant_status'] !== 'active') {
            return ['ok' => false, 'code' => 'invalid_token', 'message' => 'The access token is invalid or was revoked', 'status' => 401];
        }
        if (strtotime((string) $row['expires_at']) < $this->time()) {
            return ['ok' => false, 'code' => 'invalid_token', 'message' => 'The access token expired', 'status' => 401];
        }
        // RFC 8707: tokens are only accepted by the resource they were issued for.
        if (!hash_equals((string) $row['resource'], $this->resource())) {
            return ['ok' => false, 'code' => 'invalid_token', 'message' => 'The access token was issued for another resource', 'status' => 401];
        }
        $key = (new ApiKeyService())->authenticateById((int) $row['api_key_id'], $clientIp);
        if (!$key['ok']) {
            return ['ok' => false, 'code' => $key['code'] === 'IP_FORBIDDEN' ? 'insufficient_scope' : 'invalid_token', 'message' => $key['message'] ?? 'API key unusable', 'status' => (int) ($key['status'] ?? 401)];
        }
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < $this->time() - 300) {
            App::db()->execute('UPDATE oauth_grants SET last_used_at = ? WHERE grant_id = ?', [$this->now(), $row['grant_id']]);
        }

        return ['ok' => true, 'data' => $key['data'], 'grant_id' => (string) $row['grant_id']];
    }

    public static function looksLikeAccessToken(string $token): bool
    {
        return str_starts_with($token, self::ACCESS_PREFIX);
    }

    /**
     * RFC 7009. Unknown tokens and tokens of other clients are ignored (200).
     */
    public function revokeToken(array $client, string $token): void
    {
        if ($token === '') {
            throw new OAuthException('invalid_request', 'token is required');
        }
        $row = App::db()->first('SELECT grant_id, client_id, token_type FROM oauth_tokens WHERE token_hash = ?', [Crypto::hashKey($token)]);
        if (!$row || !hash_equals((string) $row['client_id'], $client['client_id'])) {
            return;
        }
        if ($row['token_type'] === 'refresh') {
            $this->revokeGrant((string) $row['grant_id'], 'client_revoked');
            return;
        }
        App::db()->execute('UPDATE oauth_tokens SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL', [$this->now(), Crypto::hashKey($token)]);
    }

    public function revokeGrant(string $grantId, string $reason): bool
    {
        $changed = App::db()->execute("UPDATE oauth_grants SET status = 'revoked', revoked_reason = ?, revoked_at = ? WHERE grant_id = ? AND status = 'active'", [mb_substr($reason, 0, 64), $this->now(), $grantId]);
        App::db()->execute('UPDATE oauth_tokens SET revoked_at = ? WHERE grant_id = ? AND revoked_at IS NULL', [$this->now(), $grantId]);

        return $changed > 0;
    }

    /**
     * Password changed or account taken over: MCP sign-ins of that user end.
     * No-op on databases that have not run the OAuth migration yet.
     */
    public static function revokeUserGrantsAfterPasswordChange(int $userId): void
    {
        if ($userId <= 0 || !\TokenHub\Support\SchemaInspector::hasColumn('oauth_grants', 'grant_id')) {
            return;
        }
        $count = (new self(''))->revokeForUser($userId, 'password_changed');
        if ($count > 0) {
            (new AuditService())->write('mcp_oauth.grant.revoke', 'users', $userId, ['reason' => 'password_changed', 'grants' => $count]);
        }
    }

    /** Ends every grant of a user (password change, account disabled). */
    public function revokeForUser(int $userId, string $reason): int
    {
        $count = 0;
        foreach (App::db()->select("SELECT grant_id FROM oauth_grants WHERE user_id = ? AND status = 'active'", [$userId]) as $grant) {
            $count += $this->revokeGrant((string) $grant['grant_id'], $reason) ? 1 : 0;
        }

        return $count;
    }

    // ------------------------------------------------------------------ administration

    /**
     * Grants visible to the administrator (department scope of the API key).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query api_key_id, status, limit, offset
     * @return list<array<string, mixed>>
     */
    public function listGrants(array $actor, array $query): array
    {
        [$scopeWhere, $bindings] = (new PermissionService())->tableScopeSql($actor, 'api_keys');
        $conditions = [];
        $scopeWhere = trim((string) $scopeWhere);
        if ($scopeWhere !== '') {
            $scoped = preg_replace('/^WHERE\s+/i', '', $scopeWhere) ?: $scopeWhere;
            // Scope SQL refers to api_keys columns; qualify the bare ones.
            $conditions[] = preg_replace('/(?<![.\w])(department_id|user_id|id)\b/', 'k.$1', $scoped) ?: $scoped;
        }
        if (isset($query['api_key_id']) && (int) $query['api_key_id'] > 0) {
            $conditions[] = 'g.api_key_id = ?';
            $bindings[] = (int) $query['api_key_id'];
        }
        $status = (string) ($query['status'] ?? 'active');
        if (in_array($status, ['active', 'revoked'], true)) {
            $conditions[] = 'g.status = ?';
            $bindings[] = $status;
        }
        $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return array_map(static fn (array $row): array => [
            'grant_id' => (string) $row['grant_id'],
            'client_id' => (string) $row['client_id'],
            'client_name' => $row['client_name'] ?: null,
            'api_key_id' => (int) $row['api_key_id'],
            'api_key_name' => $row['api_key_name'],
            'department_id' => $row['department_id'] === null ? null : (int) $row['department_id'],
            'user_id' => (int) $row['user_id'],
            'username' => $row['username'],
            'login_method' => (string) $row['login_method'],
            'status' => (string) $row['status'],
            'revoked_reason' => $row['revoked_reason'],
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'],
            'revoked_at' => $row['revoked_at'],
        ], App::db()->select(
            "SELECT g.*, c.client_name, k.name AS api_key_name, k.department_id, u.username FROM oauth_grants g JOIN api_keys k ON k.id = g.api_key_id LEFT JOIN oauth_clients c ON c.client_id = g.client_id LEFT JOIN users u ON u.id = g.user_id{$where} ORDER BY g.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bindings
        ));
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function adminRevoke(array $actor, string $grantId): array
    {
        $grant = $this->listGrantsForId($actor, $grantId);
        if ($grant === null) {
            $exists = App::db()->first('SELECT grant_id FROM oauth_grants WHERE grant_id = ?', [$grantId]);
            abort_json($exists ? 'ADMIN_FORBIDDEN' : 'ADMIN_NOT_FOUND', $exists ? 'This authorization belongs to another department' : 'Authorization not found', $exists ? 403 : 404);
        }
        $changed = $this->revokeGrant($grantId, 'admin');
        (new AuditService())->write('mcp_oauth.grant.revoke', 'api_keys', $grant['api_key_id'], ['grant_id' => $grantId, 'client_id' => $grant['client_id']]);

        return ['grant_id' => $grantId, 'revoked' => $changed, 'status' => 'revoked'];
    }

    private function listGrantsForId(array $actor, string $grantId): ?array
    {
        [$scopeWhere, $bindings] = (new PermissionService())->tableScopeSql($actor, 'api_keys');
        $scopeWhere = trim((string) $scopeWhere);
        $condition = $scopeWhere === '' ? '' : ' AND ' . (preg_replace('/(?<![.\w])(department_id|user_id|id)\b/', 'k.$1', preg_replace('/^WHERE\s+/i', '', $scopeWhere) ?: $scopeWhere) ?: '');
        $row = App::db()->first("SELECT g.grant_id, g.client_id, g.api_key_id FROM oauth_grants g JOIN api_keys k ON k.id = g.api_key_id WHERE g.grant_id = ?{$condition}", [$grantId, ...$bindings]);

        return $row ? ['grant_id' => (string) $row['grant_id'], 'client_id' => (string) $row['client_id'], 'api_key_id' => (int) $row['api_key_id']] : null;
    }

    /** Scheduled cleanup (cleanup.mcp-oauth). */
    public function cleanup(): array
    {
        $now = $this->now();
        $dayAgo = $this->at(-86400);

        return [
            'requests' => App::db()->execute('DELETE FROM oauth_authorization_requests WHERE expires_at < ?', [$now]),
            'codes' => App::db()->execute('DELETE FROM oauth_authorization_codes WHERE expires_at < ?', [$dayAgo]),
            'tokens' => App::db()->execute('DELETE FROM oauth_tokens WHERE expires_at < ?', [$dayAgo]),
            'grants' => App::db()->execute("DELETE FROM oauth_grants WHERE status = 'revoked' AND revoked_at < ?", [$this->at(-30 * 86400)]),
        ];
    }

    // ------------------------------------------------------------------ helpers

    public static function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function requestedResource(mixed $resource, bool $redirectable): string
    {
        if ($resource === null || $resource === '') {
            return $this->resource();
        }
        if (is_array($resource)) {
            throw new OAuthException('invalid_target', 'Only one resource may be requested', 400, $redirectable);
        }
        $normalized = $this->normalizeResource((string) $resource);
        if ($normalized !== $this->resource()) {
            throw new OAuthException('invalid_target', 'resource must be ' . $this->resource(), 400, $redirectable);
        }

        return $normalized;
    }

    private function normalizeResource(string $resource): string
    {
        $resource = rtrim(trim($resource), '/');
        $parts = parse_url($resource);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $resource;
        }
        $port = isset($parts['port']) && !in_array([$parts['scheme'], $parts['port']], [['https', 443], ['http', 80]], true) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . $port . ($parts['path'] ?? '');
    }

    private function assertRegistrableRedirect(string $uri, bool $applyAllowlist = true): void
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme']) || isset($parts['fragment']) || strlen($uri) > 2048) {
            throw new OAuthException('invalid_redirect_uri', 'Invalid redirect URI: ' . mb_substr($uri, 0, 200));
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (in_array($scheme, self::FORBIDDEN_SCHEMES, true)) {
            throw new OAuthException('invalid_redirect_uri', 'Redirect URI scheme is not allowed: ' . $scheme);
        }
        if ($this->isLoopback($uri) || in_array($uri, self::HOSTED_CALLBACKS, true)) {
            return;
        }
        if ($scheme === 'http') {
            throw new OAuthException('invalid_redirect_uri', 'Redirect URIs must use https (plain http only for 127.0.0.1 / [::1] / localhost)');
        }
        $allowlist = (array) App::config()->get('app.mcp.oauth.redirect_uri_allowlist', []);
        if ($applyAllowlist && $allowlist !== []) {
            foreach ($allowlist as $prefix) {
                if ($prefix !== '' && str_starts_with($uri, (string) $prefix)) {
                    return;
                }
            }
            throw new OAuthException('invalid_redirect_uri', 'Redirect URI is not on this TokenHub\'s allowlist (MCP_OAUTH_REDIRECT_URI_ALLOWLIST)');
        }
        if ($scheme === 'https' && empty($parts['host'])) {
            throw new OAuthException('invalid_redirect_uri', 'Redirect URI has no host');
        }
        // https, or a private-use scheme of a native app (cursor://, vscode://, com.example.app:/).
    }

    private function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'http') {
            return false;
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        return in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    }

    private function withoutPort(string $uri): string
    {
        $parts = parse_url($uri) ?: [];

        return strtolower((string) ($parts['scheme'] ?? '')) . '://' . strtolower(trim((string) ($parts['host'] ?? ''), '[]')) . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function optionalHttpsUrl(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false ? mb_substr($value, 0, 512) : null;
    }

    private function throttle(string $scope, string|int $identity, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }
        $result = (new ExternalRateLimitService())->attempt($scope, $identity, $limit);
        if (($result['allowed'] ?? true) !== true) {
            throw new OAuthException('temporarily_unavailable', 'Too many attempts; wait a minute and try again', 429);
        }
    }

    private static function flag(string $name, bool $default): bool
    {
        $value = App::config()->get('app.mcp.oauth.' . $name, $default);

        return $value === true || in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash('tokenhub-oauth-dummy-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }

    private function time(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', $this->time());
    }

    private function at(int $offsetSeconds): string
    {
        return date('Y-m-d H:i:s', $this->time() + $offsetSeconds);
    }
}
