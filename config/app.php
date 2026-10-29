<?php

declare(strict_types=1);

return [
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'key' => env('APP_KEY', ''),
    'url' => env('APP_URL', 'http://localhost'),
    'admin_session_ttl_minutes' => (int) env('ADMIN_SESSION_TTL_MINUTES', 120),
    'session_secure' => (bool) env('SESSION_SECURE', true),
    'admin_csrf_secret' => env('ADMIN_CSRF_SECRET', ''),
    'trusted_proxy_cidrs' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXY_CIDRS', '127.0.0.1/32,::1/128'))))),
    'external_api_rate_limits' => [
        'invalid_auth_per_minute' => (int) env('EXTERNAL_API_INVALID_AUTH_PER_MINUTE', 30),
        'model_per_minute' => (int) env('EXTERNAL_API_MODEL_PER_MINUTE', 600),
        'other_per_minute' => (int) env('EXTERNAL_API_OTHER_PER_MINUTE', 120),
    ],
    'mcp' => [
        // POST /mcp: Model Context Protocol endpoint (Streamable HTTP) authenticated with API keys.
        'enabled' => env('MCP_ENABLED', true),
        // Browser origins allowed besides this site's own host; desktop/CLI clients send no Origin.
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_ALLOWED_ORIGINS', ''))))),
        // Public base URL of this TokenHub (issuer and resource of the built-in
        // OAuth server). Empty = APP_URL, then the request's own host.
        'public_url' => (string) env('MCP_PUBLIC_URL', ''),
        'oauth' => [
            'enabled' => env('MCP_OAUTH_ENABLED', true),
            // RFC 7591 dynamic client registration (POST /oauth/register).
            'dynamic_registration' => env('MCP_OAUTH_DYNAMIC_REGISTRATION', true),
            // Client ID Metadata Documents: an https client_id is fetched (needs outbound access).
            'client_metadata_documents' => env('MCP_OAUTH_CLIENT_METADATA_DOCUMENTS', true),
            // Sign-in methods offered on the authorization page.
            'password_login' => env('MCP_OAUTH_PASSWORD_LOGIN', true),
            'api_key_login' => env('MCP_OAUTH_API_KEY_LOGIN', true),
            'access_token_ttl_seconds' => (int) env('MCP_OAUTH_ACCESS_TOKEN_TTL_SECONDS', 3600),
            'refresh_token_ttl_seconds' => (int) env('MCP_OAUTH_REFRESH_TOKEN_TTL_SECONDS', 2592000),
            'refresh_grace_seconds' => (int) env('MCP_OAUTH_REFRESH_GRACE_SECONDS', 60),
            'code_ttl_seconds' => (int) env('MCP_OAUTH_CODE_TTL_SECONDS', 300),
            // Extra allowed redirect URIs or prefixes for dynamically registered
            // clients (comma separated). Loopback and claude.ai / chatgpt.com
            // callbacks are always accepted; everything else must use https.
            'redirect_uri_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_OAUTH_REDIRECT_URI_ALLOWLIST', ''))))),
            'login_attempts_per_minute' => (int) env('MCP_OAUTH_LOGIN_ATTEMPTS_PER_MINUTE', 10),
            'authorize_per_minute' => (int) env('MCP_OAUTH_AUTHORIZE_PER_MINUTE', 30),
        ],
    ],
    // Console sign-in throttling (per client IP and per username).
    'admin_login_attempts_per_minute' => (int) env('ADMIN_LOGIN_ATTEMPTS_PER_MINUTE', 10),
    'external_event_retention_days' => (int) env('EXTERNAL_EVENT_RETENTION_DAYS', 90),
    'caddy_config_path' => env('CADDY_CONFIG_PATH', base_path('docker/caddy/Caddyfile')),
    'caddy_tls_fragment_path' => env('CADDY_TLS_FRAGMENT_PATH', storage_path('caddy/tls-current.caddy')),
    'caddy_validate_command' => env('CADDY_VALIDATE_COMMAND', 'caddy validate --config {config}'),
    'caddy_reload_command' => env('CADDY_RELOAD_COMMAND', 'caddy reload --config {config}'),
];
