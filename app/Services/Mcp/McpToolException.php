<?php

declare(strict_types=1);

namespace TokenHub\Services\Mcp;

/**
 * A tool failure the calling model should see and can act on (bad arguments,
 * missing permission). Reported as a CallToolResult with isError=true rather
 * than a JSON-RPC protocol error.
 */
final class McpToolException extends \RuntimeException
{
}
