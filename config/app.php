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
    ],
    'external_event_retention_days' => (int) env('EXTERNAL_EVENT_RETENTION_DAYS', 90),
    'caddy_config_path' => env('CADDY_CONFIG_PATH', base_path('docker/caddy/Caddyfile')),
    'caddy_tls_fragment_path' => env('CADDY_TLS_FRAGMENT_PATH', storage_path('caddy/tls-current.caddy')),
    'caddy_validate_command' => env('CADDY_VALIDATE_COMMAND', 'caddy validate --config {config}'),
    'caddy_reload_command' => env('CADDY_RELOAD_COMMAND', 'caddy reload --config {config}'),
];
