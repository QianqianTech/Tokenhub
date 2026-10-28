<?php

declare(strict_types=1);

namespace TokenHub\Services\Mcp;

use Throwable;

/**
 * Model Context Protocol server core (JSON-RPC 2.0 over Streamable HTTP).
 *
 * Transport-agnostic and stateless: every POST /mcp body is handled on its
 * own, so no Mcp-Session-Id is issued and any PHP-FPM worker can answer.
 * Responses are plain application/json; the server never opens an SSE
 * stream because every tool here answers synchronously.
 *
 * Spec: https://modelcontextprotocol.io/specification
 */
final class McpServer
{
    /** Newest first. initialize echoes the client's version when listed, otherwise the newest. */
    public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    public function __construct(
        private readonly McpToolRegistry $tools,
        private readonly string $serverVersion = '1.0.0',
        private readonly ?\Closure $onError = null,
    ) {
    }

    /**
     * @return array{status: int, body: array<mixed>|null}
     */
    public function handle(string $rawBody, ?string $protocolVersionHeader = null): array
    {
        $header = trim((string) $protocolVersionHeader);
        if ($header !== '' && !in_array($header, self::SUPPORTED_VERSIONS, true)) {
            return ['status' => 400, 'body' => $this->error(null, self::INVALID_REQUEST, 'Unsupported MCP-Protocol-Version: ' . $header . '; supported: ' . implode(', ', self::SUPPORTED_VERSIONS))];
        }

        $decoded = json_decode($rawBody, true, 64);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return ['status' => 400, 'body' => $this->error(null, self::PARSE_ERROR, 'Parse error: body must be a JSON-RPC 2.0 message')];
        }
        if ($decoded === []) {
            return ['status' => 400, 'body' => $this->error(null, self::INVALID_REQUEST, 'Invalid Request: empty message')];
        }

        // JSON-RPC batches were part of 2025-03-26 and are still accepted for older clients.
        $isBatch = array_is_list($decoded);
        $responses = [];
        foreach ($isBatch ? $decoded : [$decoded] as $message) {
            $response = $this->dispatch($message);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if ($responses === []) {
            // Only notifications or client responses: acknowledge without a body.
            return ['status' => 202, 'body' => null];
        }

        return ['status' => 200, 'body' => $isBatch ? $responses : $responses[0]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dispatch(mixed $message): ?array
    {
        if (!is_array($message) || $message === [] || array_is_list($message)) {
            return $this->error(null, self::INVALID_REQUEST, 'Invalid Request: message must be a JSON object');
        }

        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        if ($hasId && !is_string($id) && !is_int($id)) {
            return $this->error(null, self::INVALID_REQUEST, 'Invalid Request: id must be a string or integer');
        }
        if (($message['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($hasId ? $id : null, self::INVALID_REQUEST, 'Invalid Request: jsonrpc must be "2.0"');
        }

        if (!array_key_exists('method', $message)) {
            // A response to a server request; this server never sends any, so just accept it.
            if ($hasId && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
                return null;
            }
            return $this->error($hasId ? $id : null, self::INVALID_REQUEST, 'Invalid Request: method is required');
        }

        $method = $message['method'];
        if (!is_string($method) || $method === '') {
            return $this->error($hasId ? $id : null, self::INVALID_REQUEST, 'Invalid Request: method must be a string');
        }

        if (!$hasId) {
            // Notifications (notifications/initialized, notifications/cancelled, ...) need no answer.
            return null;
        }

        $params = $message['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return $this->error($id, self::INVALID_PARAMS, 'Invalid params: params must be an object');
        }

        try {
            return match ($method) {
                'initialize' => $this->result($id, $this->initialize($params)),
                'ping' => $this->result($id, new \stdClass()),
                'tools/list' => $this->result($id, ['tools' => $this->tools->definitions()]),
                'tools/call' => $this->callTool($id, $params),
                default => $this->error($id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method),
            };
        } catch (Throwable $throwable) {
            if ($this->onError !== null) {
                ($this->onError)($throwable, $method);
            }
            return $this->error($id, self::INTERNAL_ERROR, 'Internal error');
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';

        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'tokenhub',
                'title' => 'TokenHub',
                'version' => $this->serverVersion,
            ],
            'instructions' => 'TokenHub enterprise knowledge and account tools. Use search_knowledge_base to look up internal documents '
                . 'before answering company-specific questions and cite the returned source ids. All tools act within the '
                . 'permissions of the API key used to connect.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(string|int $id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->error($id, self::INVALID_PARAMS, 'Invalid params: name is required');
        }
        if (!$this->tools->has($name)) {
            return $this->error($id, self::INVALID_PARAMS, 'Unknown tool: ' . $name);
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return $this->error($id, self::INVALID_PARAMS, 'Invalid params: arguments must be an object');
        }

        return $this->result($id, $this->tools->call($name, $arguments));
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string|int $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string|int|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
