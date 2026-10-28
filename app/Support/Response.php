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

    /**
     * Error envelope with extra fields: $error is merged into "error" (for
     * example the upstream type/param), $top into the document (for example
     * "type": "error" on Anthropic-protocol endpoints). The message gets the
     * same redaction as error().
     *
     * @param array<string, mixed> $error
     * @param array<string, mixed> $top
     */
    public static function errorWith(string $code, string $message, int $status, array $error = [], array $top = []): self
    {
        $context = App::context();
        return self::json([
            ...$top,
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => self::safeMessage($message),
                ...$error,
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
            // The writer receives this response so it can still turn into a
            // regular error (real status code, JSON body) when it fails before
            // writing its first byte; see replaceUncommittedStream().
            ($this->streamWriter)($this);
            return;
        }

        echo $this->body;
    }

    /**
     * Replaces a stream that has not written anything yet with $replacement.
     * PHP sends the status line and headers with the first output byte, so as
     * long as nothing was echoed the client gets the replacement's status and
     * headers instead of "200 text/event-stream" followed by an error event.
     * Returns false when output already started (the caller then reports the
     * error in-band).
     */
    public function replaceUncommittedStream(self $replacement): bool
    {
        if (headers_sent()) {
            return false;
        }
        http_response_code($replacement->status);
        foreach (array_keys(self::sseHeaders()) as $name) {
            header_remove($name);
        }
        foreach ($replacement->headers as $name => $value) {
            header($name . ': ' . $value, strtolower($name) !== 'set-cookie');
        }
        $this->status = $replacement->status;
        foreach (array_keys(self::sseHeaders()) as $name) {
            unset($this->headers[$name]);
        }
        $this->headers = [...$this->headers, ...$replacement->headers];
        echo $replacement->body;

        return true;
    }

    /**
     * The redaction applied to every error message, for messages that are
     * written outside an error() envelope (for example an SSE error event).
     */
    public static function redactMessage(string $message): string
    {
        return self::safeMessage($message);
    }

    private static function safeMessage(string $message): string
    {
        // Whole words only: quota messages such as "total_tokens exceeded"
        // must stay readable ("total_[redacted]s exceeded" helped nobody).
        return preg_replace('/(DB_PASSWORD|REDIS_PASSWORD|APP_KEY|ADMIN_CSRF_SECRET|\bpassword\b|\bsecret\b|\btoken\b|\bapi[_-]?key\b)/i', '[redacted]', $message) ?? 'Request failed';
    }
}
