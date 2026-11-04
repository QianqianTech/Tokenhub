<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\AuthService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class CsrfMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        // APP requests authenticate with an explicit bearer token that a
        // cross-site page cannot attach, so they carry no CSRF token. This only
        // applies when AdminAuthMiddleware accepted exactly that bearer token.
        $auth = App::context()->adminAuth();
        $bearer = $request->bearerToken();
        if (($auth['channel'] ?? '') === 'app' && $bearer !== null && hash_equals((string) ($auth['token'] ?? ''), $bearer)) {
            return $next($request);
        }

        $token = $request->header('x-csrf-token', '');
        $session = $_COOKIE['tokenhub_admin_session'] ?? '';
        if (!(new AuthService())->validateCsrf($session, (string) $token)) {
            return Response::error('ADMIN_CSRF_INVALID', 'Invalid CSRF token', 419);
        }

        return $next($request);
    }
}
