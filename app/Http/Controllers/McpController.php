<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use Throwable;
use TokenHub\Services\Mcp\McpServer;
use TokenHub\Services\Mcp\McpToolRegistry;
use TokenHub\Services\Mcp\TokenHubMcpTools;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

/**
 * POST /mcp — MCP Streamable HTTP endpoint (stateless, JSON responses).
 * GET/DELETE /mcp answer 405: no server-initiated SSE stream and no sessions.
 */
final class McpController
{
    /**
     * @param \Closure|null $toolsFactory fn(array $apiKey): TokenHubMcpTools — test seam for fake backends
     */
    public function __construct(private readonly ?\Closure $toolsFactory = null)
    {
    }

    public function handle(Request $request): Response
    {
        $apiKey = App::context()->apiKey() ?? [];
        $apiKeyId = (int) ($apiKey['id'] ?? 0);

        $registry = new McpToolRegistry(static function (string $tool, bool $isError, int $latencyMs, ?Throwable $failure) use ($apiKeyId): void {
            $context = ['tool' => $tool, 'api_key_id' => $apiKeyId, 'is_error' => $isError, 'latency_ms' => $latencyMs, 'request_id' => App::context()->requestId()];
            if ($failure !== null) {
                App::logger()->error('mcp', 'mcp tool failed', $context + ['error' => $failure->getMessage()]);
                return;
            }
            App::logger()->info('mcp', 'mcp tool call', $context);
        });
        ($this->toolsFactory !== null ? ($this->toolsFactory)($apiKey) : new TokenHubMcpTools($apiKey))->registerInto($registry);

        $server = new McpServer(
            $registry,
            (string) App::config()->get('app.version', '1.0.0'),
            static function (Throwable $throwable, string $method) use ($apiKeyId): void {
                App::logger()->error('mcp', 'mcp request failed', ['method' => $method, 'api_key_id' => $apiKeyId, 'error' => $throwable->getMessage()]);
            }
        );

        $result = $server->handle($request->rawBody, $request->header('mcp-protocol-version'));
        if ($result['body'] === null) {
            return Response::raw('', 202);
        }

        return Response::json($result['body'], $result['status'], ['Cache-Control' => 'no-store']);
    }

    public function methodNotAllowed(Request $request): Response
    {
        return Response::json(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => McpServer::INVALID_REQUEST, 'message' => 'This MCP server is stateless: use POST /mcp (no SSE stream or session to open or close)']],
            405,
            ['Allow' => 'POST']
        );
    }
}
