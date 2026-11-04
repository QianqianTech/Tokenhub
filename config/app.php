<?php

declare(strict_types=1);

return [
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'key' => env('APP_KEY', ''),
    'url' => env('APP_URL', 'http://localhost'),
    'admin_session_ttl_minutes' => (int) env('ADMIN_SESSION_TTL_MINUTES', 120),
    'session_secure' => (bool) env('SESSION_SECURE', true),
    // Business time zone: "today / this week / this month" on dashboards and the screen,
    // and the day/month boundaries of quota windows. Storage stays UTC. Unset = UTC
    // (the behaviour before this setting existed).
    'business_timezone' => (string) env('BUSINESS_TIMEZONE', 'UTC'),
    // TokenHub APP (PWA / mobile / desktop): administrators sign in on a device
    // with a one-time link from the web console, then stay signed in through a
    // rotating device credential until they log out, are revoked, or stop using it.
    'app_client' => [
        'link_ttl_minutes' => (int) env('APP_LINK_TTL_MINUTES', 10),
        'access_ttl_minutes' => (int) env('APP_ACCESS_TTL_MINUTES', 60),
        'device_idle_days' => (int) env('APP_DEVICE_IDLE_DAYS', 30),
        'http_device_idle_days' => (int) env('APP_HTTP_DEVICE_IDLE_DAYS', 7),
        'device_max_days' => (int) env('APP_DEVICE_MAX_DAYS', 180),
        'max_devices_per_user' => (int) env('APP_MAX_DEVICES_PER_USER', 5),
        'refresh_grace_seconds' => (int) env('APP_REFRESH_GRACE_SECONDS', 30),
        'exchange_attempts_per_minute' => (int) env('APP_LINK_EXCHANGE_ATTEMPTS_PER_MINUTE', 10),
        'step_up_required' => (bool) env('APP_STEP_UP_REQUIRED', true),
        'step_up_window_seconds' => (int) env('APP_STEP_UP_WINDOW_SECONDS', 300),
        'pin_max_failures' => (int) env('APP_PIN_MAX_FAILURES', 5),
        // Web Push contact (VAPID "sub"): mailto: or https URL; empty = APP_URL when it is https.
        'push_subject' => (string) env('APP_PUSH_SUBJECT', ''),
        // Live updates (SSE) while the APP is in the foreground. Each stream holds one
        // PHP-FPM worker for stream_seconds; keep stream_max_connections well below
        // PHP_FPM_MAX_CHILDREN. Over the limit the APP polls instead.
        'stream_seconds' => (int) env('APP_STREAM_SECONDS', 55),
        'stream_max_connections' => (int) env('APP_STREAM_MAX_CONNECTIONS', 8),
        'stream_max_per_device' => (int) env('APP_STREAM_MAX_PER_DEVICE', 2),
        // Native push for the install packages, with this deployment's own credentials.
        // APNs: token-based key (.p8) from the Apple developer account; FCM: service account JSON.
        'apns_key_id' => (string) env('APNS_KEY_ID', ''),
        'apns_team_id' => (string) env('APNS_TEAM_ID', ''),
        'apns_bundle_id' => (string) env('APNS_BUNDLE_ID', 'com.qianqian.tokenhub'),
        'apns_private_key_path' => (string) env('APNS_PRIVATE_KEY_PATH', ''),
        'apns_production' => (bool) env('APNS_PRODUCTION', true),
        'fcm_service_account_path' => (string) env('FCM_SERVICE_ACCOUNT_PATH', ''),
        // Optional: a new APP device must be confirmed in the web console (match code) before it can sign in.
        'new_device_approval' => (bool) env('APP_NEW_DEVICE_APPROVAL', false),
    ],
    'admin_csrf_secret' => env('ADMIN_CSRF_SECRET', ''),
    'trusted_proxy_cidrs' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXY_CIDRS', '127.0.0.1/32,::1/128'))))),
    'external_api_rate_limits' => [
        'invalid_auth_per_minute' => (int) env('EXTERNAL_API_INVALID_AUTH_PER_MINUTE', 30),
        'model_per_minute' => (int) env('EXTERNAL_API_MODEL_PER_MINUTE', 600),
        'other_per_minute' => (int) env('EXTERNAL_API_OTHER_PER_MINUTE', 120),
        // BIZ-022: separate bucket for POST/PUT/PATCH/DELETE on non-model endpoints.
        'write_per_minute' => (int) env('EXTERNAL_API_WRITE_PER_MINUTE', 60),
    ],
    // Operations screen (/screen): login throttling, sliding session lifetime, metric cache.
    'screen' => [
        'login_per_ip_per_minute' => (int) env('SCREEN_LOGIN_PER_IP_PER_MINUTE', 20),
        'login_per_code_per_minute' => (int) env('SCREEN_LOGIN_PER_CODE_PER_MINUTE', 10),
        'session_idle_seconds' => (int) env('SCREEN_SESSION_IDLE_SECONDS', 86400),
        'refresh_log_interval_seconds' => (int) env('SCREEN_REFRESH_LOG_INTERVAL_SECONDS', 300),
        'metric_cache_seconds' => (int) env('SCREEN_METRIC_CACHE_SECONDS', 5),
        'access_log_retention_days' => (int) env('SCREEN_ACCESS_LOG_RETENTION_DAYS', 90),
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
            // SEC-109: unauthenticated /oauth/register and /oauth/token, per client IP.
            'register_per_minute' => (int) env('MCP_OAUTH_REGISTER_PER_MINUTE', 10),
            'token_per_minute' => (int) env('MCP_OAUTH_TOKEN_PER_MINUTE', 60),
            'idle_client_ttl_seconds' => (int) env('MCP_OAUTH_IDLE_CLIENT_TTL_SECONDS', 86400),
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
