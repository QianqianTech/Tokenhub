<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

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
        $token = $request->bearerToken();
        if ($token === null) {
            if (($response = $this->rateLimitResponse($limiter, 'invalid-auth', $clientIp, (int) App::config()->get('app.external_api_rate_limits.invalid_auth_per_minute', 30))) !== null) {
                return $response;
            }
            return Response::error('API_KEY_INVALID', 'Bearer API key required', 401);
        }

        $apiKey = (new ApiKeyService())->authenticate($token, $clientIp, null);
        if (!$apiKey['ok']) {
            if (($response = $this->rateLimitResponse($limiter, 'invalid-auth', $clientIp, (int) App::config()->get('app.external_api_rate_limits.invalid_auth_per_minute', 30))) !== null) {
                return $response;
            }
            return Response::error($apiKey['code'], $apiKey['message'], $apiKey['status']);
        }

        App::context()->setApiKey($apiKey['data']);
        $isModelEndpoint = in_array($request->path, [
            '/v1/chat/completions', '/v1/responses', '/v1/embeddings', '/v1/messages', '/v1/messages/count_tokens',
            '/v1/audio/transcriptions', '/v1/audio/speech', '/v1/images/generations', '/v1/videos/generations',
        ], true);
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
