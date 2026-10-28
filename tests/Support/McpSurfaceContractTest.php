<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$kernel = (string) file_get_contents($root . '/app/Bootstrap/Kernel.php');
assert_true(str_contains($kernel, "\$router->post('/mcp', [\$mcp, 'handle'], [new McpAuthMiddleware()]);"), 'POST /mcp must be authenticated by McpAuthMiddleware.');
assert_true(str_contains($kernel, "\$router->get('/mcp', [\$mcp, 'methodNotAllowed']);"), 'GET /mcp must answer 405 (no SSE stream).');

$middleware = (string) file_get_contents($root . '/app/Middleware/McpAuthMiddleware.php');
assert_true(str_contains($middleware, 'new ApiKeyAuthMiddleware()'), 'MCP must reuse API key authentication and rate limits.');
assert_true(str_contains($middleware, 'originAllowed'), 'MCP must validate the Origin header.');

foreach (['docker/caddy/Caddyfile', 'docker/release/caddy/Caddyfile'] as $file) {
    $caddy = (string) file_get_contents($root . '/' . $file);
    assert_true(str_contains($caddy, '@php path /health /ready /admin-api/* /screen-api/* /v1/* /download/* /mcp'), "{$file} must forward /mcp to PHP.");
}
assert_true(str_contains((string) file_get_contents($root . '/Caddyfile.example'), 'handle /mcp {'), 'Source-install Caddyfile must forward /mcp to PHP.');
assert_true(str_contains((string) file_get_contents($root . '/.env.example'), 'MCP_ENABLED=true'), '.env.example documents MCP_ENABLED.');
assert_true(is_file($root . '/docs/mcp.md'), 'MCP client setup is documented.');
