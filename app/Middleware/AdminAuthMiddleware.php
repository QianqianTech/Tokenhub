<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\AuthService;
use TokenHub\Services\PermissionService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class AdminAuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $session = $_COOKIE['tokenhub_admin_session'] ?? '';
        $user = (new AuthService())->userFromSession($session);
        if ($user === null) {
            return Response::error('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }

        App::context()->setAdminUser($user);
        $capability = (new PermissionService())->capabilityForAdminRequest($request);
        if ($capability !== null && !(new PermissionService())->hasCapability($user, $capability)) {
            return Response::error('ADMIN_PERMISSION_DENIED', 'Administrator capability denied', 403);
        }
        return $next($request);
    }
}
