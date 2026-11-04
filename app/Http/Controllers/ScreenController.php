<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\DashboardService;
use TokenHub\Services\MediaAssetService;
use TokenHub\Services\ScreenService;
use TokenHub\Services\SystemSettingsService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class ScreenController
{
    public function shareLogin(Request $request): Response
    {
        $data = $request->json();
        $service = new ScreenService();
        $shareCode = (string) ($data['share_code'] ?? '');
        if (($limited = $this->throttled($service, $request, 'code:' . $shareCode)) !== null) {
            return $limited;
        }
        $result = $service->loginByShareCode($shareCode, $request->clientIp(), $request->header('user-agent', '') ?? '');
        if (($result['ok'] ?? false) !== true) {
            return Response::error((string) ($result['code'] ?? 'SCREEN_UNAUTHENTICATED'), (string) ($result['message'] ?? 'Invalid screen link'), (int) ($result['status'] ?? 401));
        }
        return $this->withSessionCookie(Response::ok(['share' => $result['share']]), $result['session'], time() + $service->sessionIdleSeconds());
    }

    public function login(Request $request): Response
    {
        $data = $request->json();
        $service = new ScreenService();
        $shareCode = (string) ($data['share_code'] ?? '');
        $identity = $shareCode !== '' ? 'code:' . $shareCode : 'user:' . (string) ($data['username'] ?? '');
        if (($limited = $this->throttled($service, $request, $identity)) !== null) {
            return $limited;
        }
        $result = $shareCode === ''
            ? $service->loginByCredentials((string) ($data['username'] ?? ''), (string) ($data['password'] ?? ''), $request->clientIp(), $request->header('user-agent', '') ?? '')
            : $service->login($shareCode, (string) ($data['username'] ?? ''), (string) ($data['password'] ?? ''), $request->clientIp(), $request->header('user-agent', '') ?? '');
        if (($result['ok'] ?? false) !== true) {
            return Response::error((string) ($result['code'] ?? 'SCREEN_UNAUTHENTICATED'), (string) ($result['message'] ?? 'Invalid screen credentials'), (int) ($result['status'] ?? 401));
        }
        return $this->withSessionCookie(Response::ok(['share' => $result['share']]), $result['session'], time() + $service->sessionIdleSeconds());
    }

    private function throttled(ScreenService $service, Request $request, string $identity): ?Response
    {
        $limited = $service->loginThrottle($request->clientIp(), $identity);
        if ($limited === null) {
            return null;
        }

        return Response::error('RATE_LIMITED', 'Too many screen login attempts', 429)
            ->withHeader('Retry-After', (string) max(1, (int) $limited['retry_after']));
    }

    public function logout(Request $request): Response
    {
        (new ScreenService())->logout($_COOKIE['screen_session'] ?? '', $request->clientIp());
        return $this->withSessionCookie(Response::ok(['logged_out' => true]), '', time() - 3600);
    }

    public function me(Request $request): Response
    {
        return Response::ok(['screen' => App::context()->screenSession()]);
    }

    public function brandIdentity(Request $request): Response
    {
        $service = new SystemSettingsService();
        try {
            $settings = $service->loadSection('brand_identity');
        } catch (\Throwable) {
            $settings = $service->defaults()['brand_identity'];
        }

        return Response::ok([
            'product_name' => $settings['product_name'] ?? 'TokenHub',
            'screen_title' => $settings['screen_title'] ?? 'TokenHub 运营大屏',
            'logo_url' => $settings['logo_url'] ?? '',
            'favicon_url' => $settings['favicon_url'] ?? '',
        ]);
    }

    public function dashboard(Request $request, string $metric): Response
    {
        return Response::ok((new DashboardService())->screenMetric($metric, App::context()->screenSession()));
    }

    public function assetDownloadUrl(Request $request): Response
    {
        $service = new MediaAssetService();
        $screenSession = App::context()->screenSession();
        $asset = $service->findAuthorizedForScreen((string) $request->params['id'], $screenSession);
        if ($asset === null || $screenSession === null) {
            return Response::error('CONTENT_FORBIDDEN', 'Asset not found or outside screen scope', 404);
        }

        return Response::ok($service->createScreenDownloadUrl($asset, $screenSession));
    }

    /** Sliding session: every authenticated screen request pushes the cookie expiry forward. */
    public static function slideSessionCookie(Response $response, string $session): Response
    {
        if ($session === '' || isset($response->headers['Set-Cookie'])) {
            return $response;
        }

        return $response->withCookie(
            'screen_session',
            $session,
            time() + (new ScreenService())->sessionIdleSeconds(),
            secure: (bool) App::config()->get('app.session_secure', true)
        );
    }

    private function withSessionCookie(Response $response, string $value, int $expires): Response
    {
        return $response->withCookie(
            'screen_session',
            $value,
            $expires,
            secure: (bool) App::config()->get('app.session_secure', true)
        );
    }
}
