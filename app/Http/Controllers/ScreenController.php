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
        $result = (new ScreenService())->loginByShareCode((string) ($data['share_code'] ?? ''), $request->clientIp(), $request->header('user-agent', '') ?? '');
        if (($result['ok'] ?? false) !== true) {
            return Response::error((string) ($result['code'] ?? 'SCREEN_UNAUTHENTICATED'), (string) ($result['message'] ?? 'Invalid screen link'), (int) ($result['status'] ?? 401));
        }
        return $this->withSessionCookie(Response::ok(['share' => $result['share']]), $result['session'], time() + 315360000);
    }

    public function login(Request $request): Response
    {
        $data = $request->json();
        $service = new ScreenService();
        $shareCode = (string) ($data['share_code'] ?? '');
        $result = $shareCode === ''
            ? $service->loginByCredentials((string) ($data['username'] ?? ''), (string) ($data['password'] ?? ''), $request->clientIp(), $request->header('user-agent', '') ?? '')
            : $service->login($shareCode, (string) ($data['username'] ?? ''), (string) ($data['password'] ?? ''), $request->clientIp(), $request->header('user-agent', '') ?? '');
        if (($result['ok'] ?? false) !== true) {
            return Response::error((string) ($result['code'] ?? 'SCREEN_UNAUTHENTICATED'), (string) ($result['message'] ?? 'Invalid screen credentials'), (int) ($result['status'] ?? 401));
        }
        return $this->withSessionCookie(Response::ok(['share' => $result['share']]), $result['session'], time() + 315360000);
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
