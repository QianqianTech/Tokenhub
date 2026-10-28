<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class Response
{
    private ?\Closure $streamWriter = null;

    public function __construct(
        public string $body,
        public int $status = 200,
        public array $headers = []
    ) {
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            ...$headers,
        ]);
    }

    public static function ok(mixed $data = null, int $status = 200): self
    {
        $context = App::context();
        return self::json([
            'ok' => true,
            'data' => $data,
            'request_id' => $context->requestId(),
        ], $status, [
            'X-Request-Id' => $context->requestId(),
        ]);
    }

    public static function error(string $code, string $message, int $status = 400): self
    {
        $context = App::context();
        return self::json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => self::safeMessage($message),
            ],
            'request_id' => $context->requestId(),
        ], $status, [
            'X-Request-Id' => $context->requestId(),
        ]);
    }

    public static function raw(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers);
    }

    public static function stream(callable $writer, int $status = 200, array $headers = []): self
    {
        $response = new self('', $status, $headers);
        $response->streamWriter = \Closure::fromCallable($writer);
        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $response = new self($this->body, $this->status, array_merge($this->headers, [$name => $value]));
        $response->streamWriter = $this->streamWriter;
        return $response;
    }

    public function withCookie(
        string $name,
        string $value,
        int $expires,
        string $path = '/',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Strict'
    ): self {
        $parts = [
            rawurlencode($name) . '=' . rawurlencode($value),
            'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expires),
            'Max-Age=' . max(0, $expires - time()),
            'Path=' . $path,
        ];

        if ($secure) {
            $parts[] = 'Secure';
        }

        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }

        if ($sameSite !== '') {
            $parts[] = 'SameSite=' . $sameSite;
        }

        return $this->withHeader('Set-Cookie', implode('; ', $parts));
    }

    public static function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, strtolower($name) !== 'set-cookie');
        }

        if ($this->streamWriter !== null) {
            ($this->streamWriter)();
            return;
        }

        echo $this->body;
    }

    private static function safeMessage(string $message): string
    {
        return preg_replace('/(DB_PASSWORD|REDIS_PASSWORD|APP_KEY|ADMIN_CSRF_SECRET|password|secret|token|api[_-]?key)/i', '[redacted]', $message) ?? 'Request failed';
    }
}
