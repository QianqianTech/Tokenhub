<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class RequestContext
{
    private ?array $adminUser = null;
    private ?array $apiKey = null;
    private ?array $screenSession = null;

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

    public function setAdminUser(?array $adminUser): void
    {
        $this->adminUser = $adminUser;
    }

    public function adminUser(): ?array
    {
        return $this->adminUser;
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
        $requestId = self::header($headers, 'HTTP_X_REQUEST_ID') ?: self::header($headers, 'X-Request-Id');
        $traceId = self::header($headers, 'HTTP_X_TRACE_ID') ?: self::header($headers, 'X-Trace-Id');

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

    private static function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }
}
