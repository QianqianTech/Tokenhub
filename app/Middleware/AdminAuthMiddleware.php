<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\AppDeviceAuthService;
use TokenHub\Services\AppStepUpPolicy;
use TokenHub\Services\AuthService;
use TokenHub\Services\PermissionService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

/**
 * Authenticates an admin API request either by the console session cookie
 * (channel "web") or by a TokenHub APP bearer access token (channel "app").
 * A request that carries a bearer token is judged by that token alone; the
 * console cookie the same browser may also send is ignored, so the two
 * identities never mix.
 */
final class AdminAuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        App::context()->setAdminAuth(null);
        App::context()->setAdminUser(null);

        $bearer = $request->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            $access = (new AppDeviceAuthService())->accessSession($bearer);
            if ($access === null) {
                return Response::error('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
            }
            $user = $access['user'];
            App::context()->setAdminAuth(['channel' => 'app', 'token' => $bearer, ...$access['session']]);
        } else {
            $session = $_COOKIE['tokenhub_admin_session'] ?? '';
            $user = (new AuthService())->userFromSession($session);
            if ($user === null) {
                return Response::error('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
            }
            App::context()->setAdminAuth(['channel' => 'web', 'device_id' => null]);
        }

        App::context()->setAdminUser($user);
        $capability = (new PermissionService())->capabilityForAdminRequest($request);
        if ($capability !== null && !(new PermissionService())->hasCapability($user, $capability)) {
            return Response::error('ADMIN_PERMISSION_DENIED', 'Administrator capability denied', 403);
        }
        $auth = App::context()->adminAuth() ?? [];
        if (($auth['channel'] ?? '') === 'app') {
            // Sensitive operations from the APP need a recent PIN / passkey check.
            $policy = new AppStepUpPolicy();
            $operation = $policy->requestNeedsStepUp($request, (int) ($auth['device_id'] ?? 0));
            if ($operation !== null && !$policy->recentlyVerified(isset($auth['step_up_at']) ? (string) $auth['step_up_at'] : null)) {
                return Response::errorWith('ADMIN_STEP_UP_REQUIRED', 'Verify with your APP PIN to continue', 403, [
                    'details' => [
                        'operation' => $operation,
                        'pin_set' => (bool) ($auth['pin_set'] ?? false),
                        'window_seconds' => $policy->windowSeconds(),
                    ],
                ]);
            }
        }
        return $next($request);
    }
}
