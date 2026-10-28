<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class Router
{
    /**
     * @var list<array{method: string, pattern: string, regex: string, handler: callable, middleware: list<object|callable>}>
     */
    private array $routes = [];

    /**
     * @var list<object|callable>
     */
    private array $middleware = [];

    public function middleware(object|callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function dispatch(string $method, string $path): mixed
    {
        $method = strtoupper($method);
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $params = [];

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            $request = Request::fromGlobals($params);
            $request->method = $method;
            $request->path = $path;
            $handler = fn (Request $request): mixed => $this->callHandler($route['handler'], $request, $params);

            foreach (array_reverse([...$this->middleware, ...$route['middleware']]) as $middleware) {
                $next = $handler;
                $handler = static fn (Request $request): mixed => $middleware($request, $next);
            }

            return $handler($request);
        }

        return null;
    }

    private function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $segments = array_map(static function (string $segment): string {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches) === 1) {
                return '(?P<' . $matches[1] . '>[^/]+)';
            }
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}:(.+)$/', $segment, $matches) === 1) {
                return '(?P<' . $matches[1] . '>[^/:]+):' . preg_quote($matches[2], '#');
            }

            return preg_quote($segment, '#');
        }, explode('/', trim($path, '/')));
        $regex = $path === '/' ? '#^/$#' : '#^/' . implode('/', $segments) . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $path,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => array_values($middleware),
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function callHandler(callable $handler, Request $request, array $params): mixed
    {
        $reflection = is_array($handler)
            ? new \ReflectionMethod($handler[0], (string) $handler[1])
            : new \ReflectionFunction(\Closure::fromCallable($handler));
        $first = $reflection->getParameters()[0] ?? null;
        if ($first === null) {
            return $handler();
        }

        $type = $first?->getType();

        if ($type instanceof \ReflectionNamedType && $type->getName() === 'array') {
            return $handler($params);
        }

        return $handler($request);
    }
}
