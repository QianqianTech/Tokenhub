<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class RequestContext
{
    private ?array $adminUser = null;
    private ?array $apiKey = null;
    private ?array $screenSession = null;
    /** How the admin request authenticated: ['channel' => 'web'|'app', 'device_id' => ?int, ...]. */
    private ?array $adminAuth = null;

    public function __construct(
        public string $requestId,
        public string $traceId,
    ) {
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * Temporarily run under another request/trace id (a queue worker adopts
     * the ids carried by the job). Returns the previous pair for restore().
     *
     * @return array{request_id: string, trace_id: string}
     */
    public function adopt(?string $requestId, ?string $traceId): array
    {
        $previous = ['request_id' => $this->requestId, 'trace_id' => $this->traceId];
        $requestId = self::clean($requestId);
        $traceId = self::clean($traceId);
        if ($requestId !== null) {
            $this->requestId = $requestId;
        }
        if ($traceId !== null) {
            $this->traceId = $traceId;
        }

        return $previous;
    }

    /** @param array{request_id?: string, trace_id?: string} $previous */
    public function restore(array $previous): void
    {
        $this->requestId = (string) ($previous['request_id'] ?? $this->requestId);
        $this->traceId = (string) ($previous['trace_id'] ?? $this->traceId);
    }

    /**
     * Ids to embed in queued jobs / outbound calls so one request can be
     * followed through workers and vendors (BIZ-008).
     *
     * @return array{request_id: string, trace_id: string}
     */
    public function propagation(): array
    {
        return ['request_id' => $this->requestId, 'trace_id' => $this->traceId];
    }

    /**
     * W3C traceparent for outbound requests. Non-hex trace ids are hashed to
     * the 32 hex digits the format requires; the span id is random.
     */
    public function traceparent(): string
    {
        $trace = strtolower($this->traceId);
        if (preg_match('/^(?:trc_)?([0-9a-f]{32})$/', $trace, $m) === 1) {
            $traceHex = $m[1];
        } else {
            $traceHex = substr(hash('sha256', $this->traceId), 0, 32);
        }
        if ($traceHex === str_repeat('0', 32)) {
            $traceHex = substr(hash('sha256', 'tokenhub'), 0, 32);
        }

        return '00-' . $traceHex . '-' . bin2hex(random_bytes(8)) . '-01';
    }

    public function setAdminUser(?array $adminUser): void
    {
        $this->adminUser = $adminUser;
    }

    public function adminUser(): ?array
    {
        return $this->adminUser;
    }

    public function setAdminAuth(?array $adminAuth): void
    {
        $this->adminAuth = $adminAuth;
    }

    public function adminAuth(): ?array
    {
        return $this->adminAuth;
    }

    public function adminChannel(): string
    {
        return (string) ($this->adminAuth['channel'] ?? 'web');
    }

    public function setApiKey(?array $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function apiKey(): ?array
    {
        return $this->apiKey;
    }

    public function setScreenSession(?array $screenSession): void
    {
        $this->screenSession = $screenSession;
    }

    public function screenSession(): ?array
    {
        return $this->screenSession;
    }

    /**
     * @param array<string, mixed> $headers
     */
    public static function fromHeaders(array $headers): self
    {
        $requestId = self::clean(self::header($headers, 'HTTP_X_REQUEST_ID') ?: self::header($headers, 'X-Request-Id'));
        $traceId = self::clean(self::header($headers, 'HTTP_X_TRACE_ID') ?: self::header($headers, 'X-Trace-Id'));
        if ($traceId === null) {
            // W3C trace context: version-traceid-parentid-flags.
            $traceparent = self::header($headers, 'HTTP_TRACEPARENT') ?: self::header($headers, 'traceparent');
            if ($traceparent !== null && preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/i', trim($traceparent), $m) === 1) {
                $traceId = strtolower($m[1]);
            }
        }

        return new self(
            $requestId ?: self::id('req'),
            $traceId ?: self::id('trc'),
        );
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0 && is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /** Ids end up in logs and headers: keep them short and printable. */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 128 || preg_match('/^[A-Za-z0-9._:\-]+$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private static function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }
}
