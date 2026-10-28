<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Http\Controllers\GatewayController;
use TokenHub\Services\ApiKeyService;
use TokenHub\Services\ExternalRateLimitService;
use TokenHub\Services\IdempotencyService;
use TokenHub\Services\TrustedProxyService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class ApiKeyAuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $clientIp = (new TrustedProxyService())->clientIp($request);
        $limiter = new ExternalRateLimitService();
        $token = $request->apiKeyToken();
        if ($token === null) {
            if (($response = $this->rateLimitResponse($limiter, 'invalid-auth', $clientIp, (int) App::config()->get('app.external_api_rate_limits.invalid_auth_per_minute', 30))) !== null) {
                return $response;
            }
            return Response::error('API_KEY_INVALID', 'Bearer API key required', 401);
        }

        // An address that already burned its invalid-auth budget may still use
        // keys that are in the cache, but unknown keys from it no longer reach
        // the database (garbage keys are otherwise a cheap way to load MySQL).
        $invalidLimit = (int) App::config()->get('app.external_api_rate_limits.invalid_auth_per_minute', 30);
        $allowLookup = !$limiter->exhausted('invalid-auth', $clientIp, $invalidLimit);
        $apiKey = (new ApiKeyService())->authenticate($token, $clientIp, null, $allowLookup);
        if (!$apiKey['ok'] && ($apiKey['code'] ?? '') === 'RATE_LIMITED') {
            return Response::error('RATE_LIMITED', 'Too many requests', 429)
                ->withHeader('Retry-After', (string) $limiter->retryAfter());
        }
        if (!$apiKey['ok']) {
            if (($response = $this->rateLimitResponse($limiter, 'invalid-auth', $clientIp, (int) App::config()->get('app.external_api_rate_limits.invalid_auth_per_minute', 30))) !== null) {
                return $response;
            }
            return Response::error($apiKey['code'], $apiKey['message'], $apiKey['status']);
        }

        App::context()->setApiKey($apiKey['data']);
        $isModelEndpoint = in_array($request->path, GatewayController::ENDPOINTS, true) || str_contains($request->path, 'GenerateContent') || str_contains($request->path, ':generateContent');
        $limitKey = $isModelEndpoint ? 'model_per_minute' : 'other_per_minute';
        $scope = $isModelEndpoint ? 'model' : 'other';
        $default = $isModelEndpoint ? 600 : 120;
        if (($response = $this->rateLimitResponse($limiter, $scope, (int) ($apiKey['data']['id'] ?? 0), (int) App::config()->get('app.external_api_rate_limits.' . $limitKey, $default))) !== null) {
            return $response;
        }
        return (new IdempotencyService())->handle($request, $apiKey['data'], $next);
    }

    private function rateLimitResponse(ExternalRateLimitService $limiter, string $scope, string|int $identity, int $limit): ?Response
    {
        $result = $limiter->attempt($scope, $identity, $limit);
        if (($result['allowed'] ?? false) === true) {
            return null;
        }

        $retryAfter = (int) ($result['retry_after'] ?? 1);
        return Response::error('RATE_LIMITED', 'Too many requests', 429)
            ->withHeader('Retry-After', (string) $retryAfter);
    }
}
