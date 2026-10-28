<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

final readonly class GatewayRequest
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, int|float|string|null> $estimatedUsage
     */
    public function __construct(
        public string $endpoint,
        public string $protocol,
        public string $capability,
        public string $model,
        public bool $stream,
        public array $payload,
        public array $estimatedUsage,
    ) {
    }
}
