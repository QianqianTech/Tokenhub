<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\ScreenService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class ScreenAuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $session = $_COOKIE['screen_session'] ?? '';
        $screenSession = (new ScreenService())->session($session, $request->clientIp());
        if ($screenSession === null) {
            return Response::error('SCREEN_UNAUTHENTICATED', 'Screen login required', 401);
        }

        App::context()->setScreenSession($screenSession);
        return $next($request);
    }
}
