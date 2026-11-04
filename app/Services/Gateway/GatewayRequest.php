<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

final readonly class GatewayRequest
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, int|float|string|null> $estimatedUsage
     * @param array<string, mixed> $options request-scoped transport hints that
     *        never go into the upstream body: "forward_headers" (allowlisted
     *        client headers such as anthropic-beta, see ClientHeaderForwarding)
     *        and "gemini_json_array" (client asked :streamGenerateContent
     *        without alt=sse and expects a JSON array stream).
     */
    public function __construct(
        public string $endpoint,
        public string $protocol,
        public string $capability,
        public string $model,
        public bool $stream,
        public array $payload,
        public array $estimatedUsage,
        public array $options = [],
    ) {
    }
}
