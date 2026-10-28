<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

/**
 * Guards /mcp: feature switch, Origin check (the MCP transport spec requires
 * servers to reject unexpected browser origins to stop DNS rebinding), then
 * the regular API key authentication and per-key rate limit.
 */
final class McpAuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (!self::enabled()) {
            return Response::error('MCP_DISABLED', 'The MCP endpoint is disabled on this TokenHub instance', 404);
        }
        if (!$this->originAllowed($request)) {
            return Response::error('MCP_ORIGIN_FORBIDDEN', 'Origin is not allowed to use the MCP endpoint', 403);
        }

        $response = (new ApiKeyAuthMiddleware())($request, $next);
        if ($response->status === 401) {
            return $response->withHeader('WWW-Authenticate', 'Bearer realm="tokenhub"');
        }

        return $response;
    }

    public static function enabled(): bool
    {
        $value = App::config()->get('app.mcp.enabled', true);

        return $value === true || in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = rtrim(trim((string) $request->header('origin', '')), '/');
        if ($origin === '') {
            // Desktop/CLI MCP clients do not send Origin.
            return true;
        }
        $allowed = array_map(static fn (mixed $item): string => rtrim(trim((string) $item), '/'), (array) App::config()->get('app.mcp.allowed_origins', []));
        if (in_array('*', $allowed, true) || in_array(strtolower($origin), array_map('strtolower', $allowed), true)) {
            return true;
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
            return false;
        }
        $originHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        $requestHost = strtolower(trim((string) $request->header('host', '')));

        return $requestHost !== '' && $originHost === $requestHost;
    }
}
