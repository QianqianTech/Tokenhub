<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\ApiKeyManagementScopeService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class AgentManagementAuthMiddleware
{
    public function __construct(private readonly string $requiredScope)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        return (new ApiKeyAuthMiddleware())($request, function (Request $request) use ($next): Response {
            $apiKey = App::context()->apiKey() ?? [];
            $apiKeyId = (int) ($apiKey['id'] ?? 0);
            if (!(new ApiKeyManagementScopeService())->hasScope($apiKeyId, $this->requiredScope)) {
                return Response::error('MANAGEMENT_SCOPE_REQUIRED', 'API key lacks the required management scope', 403);
            }

            return $next($request);
        });
    }
}
