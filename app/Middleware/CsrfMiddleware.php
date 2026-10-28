<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\AuthService;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class CsrfMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $token = $request->header('x-csrf-token', '');
        $session = $_COOKIE['tokenhub_admin_session'] ?? '';
        if (!(new AuthService())->validateCsrf($session, (string) $token)) {
            return Response::error('ADMIN_CSRF_INVALID', 'Invalid CSRF token', 419);
        }

        return $next($request);
    }
}
