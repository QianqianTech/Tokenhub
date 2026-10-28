<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\LoginIpBanService;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class SystemIpBanMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if ((new LoginIpBanService())->isBanned($request->clientIp())) {
            return Response::error('SYSTEM_IP_BANNED', 'IP blocked by system security policy', 403);
        }

        return $next($request);
    }
}
