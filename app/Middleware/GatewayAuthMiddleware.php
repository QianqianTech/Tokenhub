<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\ApiKeyService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class GatewayAuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        return (new ApiKeyAuthMiddleware())($request, function (Request $request) use ($next): Response {
            $model = $request->json()['model'] ?? null;
            if (is_scalar($model) && (string) $model !== '') {
                $allowed = (new ApiKeyService())->assertModelAllowed(App::context()->apiKey() ?? [], (string) $model);
                if (!$allowed['ok']) {
                    return Response::error($allowed['code'], $allowed['message'], $allowed['status']);
                }
            }

            return $next($request);
        });
    }
}
