<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class Request
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $server,
        public array $query,
        public array $body,
        public string $rawBody,
        public RequestContext $context,
    ) {
    }

    public static function capture(): self
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            $_SERVER,
            $_GET,
            is_array($decoded) ? $decoded : $_POST,
            $raw,
            RequestContext::fromHeaders($_SERVER),
        );
    }

    public static function fromGlobals(array $params = []): self
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $request = new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            $_SERVER,
            $_GET,
            is_array($decoded) ? $decoded : $_POST,
            $raw,
            RequestContext::fromHeaders($_SERVER),
        );
        $request->params = $params;
        return $request;
    }

    public array $params = [];

    public function json(): array
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$serverKey]) ? (string) $this->server[$serverKey] : $default;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->header('authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', (string) $authorization, $match)) {
            return trim($match[1]);
        }
        return null;
    }

    /**
     * API key from the header each SDK family uses: Authorization: Bearer
     * (OpenAI), x-api-key (Anthropic SDK / Claude Code with ANTHROPIC_API_KEY),
     * x-goog-api-key (Google GenAI SDK); ?key= only on Gemini-native paths.
     */
    public function apiKeyToken(): ?string
    {
        $bearer = $this->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            return $bearer;
        }
        foreach (['x-api-key', 'x-goog-api-key'] as $header) {
            $value = trim((string) $this->header($header, ''));
            if ($value !== '') {
                return $value;
            }
        }
        if (str_starts_with($this->path, '/v1beta/') && is_string($this->query['key'] ?? null) && $this->query['key'] !== '') {
            return (string) $this->query['key'];
        }

        return null;
    }

    public function clientIp(): string
    {
        return $this->ip();
    }

    public function ip(): string
    {
        return (new \TokenHub\Services\TrustedProxyService())->clientIp($this);
    }
}
