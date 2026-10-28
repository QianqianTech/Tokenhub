<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

use TokenHub\Services\Gateway\GatewayRequest;

interface ProviderAdapterInterface
{
    public function protocol(): string;

    public function buildRequest(GatewayRequest $request, array $route): array;

    public function normalizeResponse(GatewayRequest $request, array $route, array $providerResponse): array;

    public function normalizeError(int $status, string $body): array;

    public function usage(GatewayRequest $request, array $response): array;

    public function accumulateStreamUsage(GatewayRequest $request, string $chunk, array $state): array;
}
