<?php

declare(strict_types=1);

namespace TokenHub\Services\Mcp;

use Throwable;
use TokenHub\Support\HttpAbortException;

/**
 * Tool catalog and executor. A handler receives the validated-object
 * arguments and returns either structured data (array) or an McpToolResult
 * when it wants to control the text shown to the model.
 */
final class McpToolRegistry
{
    /** @var array<string, array{definition: array<string, mixed>, handler: \Closure}> */
    private array $tools = [];

    /**
     * @param \Closure|null $observer fn(string $tool, bool $isError, int $latencyMs, ?Throwable $failure): void
     */
    public function __construct(private readonly ?\Closure $observer = null)
    {
    }

    /**
     * @param array<string, mixed> $definition MCP Tool object (name, title, description, inputSchema, annotations)
     */
    public function register(array $definition, callable $handler): void
    {
        $name = (string) ($definition['name'] ?? '');
        if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid MCP tool name: ' . $name);
        }
        $this->tools[$name] = ['definition' => $definition, 'handler' => \Closure::fromCallable($handler)];
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_map(static fn (array $tool): array => $tool['definition'], $this->tools));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> CallToolResult
     */
    public function call(string $name, array $arguments): array
    {
        $started = microtime(true);
        $failure = null;
        try {
            $output = ($this->tools[$name]['handler'])($arguments);
            $result = $output instanceof McpToolResult ? $output->toArray() : McpToolResult::structured(is_array($output) ? $output : ['value' => $output])->toArray();
        } catch (McpToolException $exception) {
            $result = McpToolResult::error($exception->getMessage())->toArray();
        } catch (HttpAbortException $abort) {
            // Service-level denials (403/404/422 ...) are meaningful to the model.
            $result = McpToolResult::error('[' . $abort->errorCode . '] ' . $abort->getMessage())->toArray();
        } catch (Throwable $throwable) {
            $failure = $throwable;
            $result = McpToolResult::error('Tool execution failed; see TokenHub logs for the request id.')->toArray();
        }

        if ($this->observer !== null) {
            ($this->observer)($name, ($result['isError'] ?? false) === true, (int) ((microtime(true) - $started) * 1000), $failure);
        }

        return $result;
    }
}
