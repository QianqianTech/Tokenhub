<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\AppDeviceAuthService;
use TokenHub\Services\AppPasskeyService;
use TokenHub\Services\AppPushService;
use TokenHub\Services\AppRealtimeService;
use TokenHub\Services\AppStepUpPolicy;
use TokenHub\Services\ExternalRateLimitService;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

/**
 * TokenHub APP sign-in: link exchange, silent refresh, device logout, and the
 * administrator's own device list ("我的设备") in the console.
 */
final class AppAuthController
{
    /** POST /admin-api/app-auth/exchange — public, rate limited. */
    public function exchange(Request $request): Response
    {
        $limited = $this->rateLimited('app-link-exchange-ip', $request->clientIp(), (int) App::config()->get('app.app_client.exchange_attempts_per_minute', 10));
        if ($limited !== null) {
            return $limited;
        }
        $data = $request->json();
        $native = (string) ($data['client'] ?? '') === 'native';
        $result = (new AppDeviceAuthService())->exchange(
            (string) ($data['code'] ?? ''),
            is_array($data['device'] ?? null) ? $data['device'] : [],
            $native ? 'native' : 'pwa',
            $request->clientIp(),
            (string) $request->header('user-agent', ''),
            AppDeviceAuthService::secureRequest($request)
        );
        if (($result['pending'] ?? false) === true) {
            // Waiting for approval in the web console: the device keeps its
            // credential (so it can poll), the previous device of this browser
            // stays until the new one is approved.
            return $this->pendingResponse($result, $native, AppDeviceAuthService::secureRequest($request), 202);
        }
        $previous = (string) ($_COOKIE[AppDeviceAuthService::REFRESH_COOKIE] ?? '');
        if ($previous !== '') {
            (new AppDeviceAuthService())->replacePreviousDevice($previous, (int) ($result['user']['id'] ?? 0), (int) ($result['device']['id'] ?? 0));
        }

        return $this->signedIn($result, $native, AppDeviceAuthService::secureRequest($request));
    }

    /** POST /admin-api/app-auth/refresh — public; credential from the cookie (PWA) or the body (native). */
    public function refresh(Request $request): Response
    {
        $limited = $this->rateLimited('app-refresh-ip', $request->clientIp(), 120);
        if ($limited !== null) {
            return $limited;
        }
        $cookie = (string) ($_COOKIE[AppDeviceAuthService::REFRESH_COOKIE] ?? '');
        $body = (string) ($request->json()['refresh_token'] ?? '');
        $native = $cookie === '' && $body !== '';
        try {
            $result = (new AppDeviceAuthService())->refresh(
                $native ? $body : $cookie,
                $request->clientIp(),
                (string) $request->header('user-agent', '')
            );
        } catch (HttpAbortException $abort) {
            return $native ? $abort->response() : $this->clearDeviceCookie($abort->response(), AppDeviceAuthService::secureRequest($request));
        }
        if (($result['pending'] ?? false) === true) {
            // Not an error for the credential: the cookie stays, the APP keeps waiting.
            return $this->pendingResponse($result, $native, AppDeviceAuthService::secureRequest($request), 409);
        }

        return $this->signedIn($result, $native, AppDeviceAuthService::secureRequest($request));
    }

    /** POST /admin-api/app-auth/logout — APP bearer session; signs the device out for good. */
    public function logout(Request $request): Response
    {
        $auth = App::context()->adminAuth();
        $deviceId = (int) ($auth['device_id'] ?? 0);
        if (($auth['channel'] ?? '') !== 'app' || $deviceId <= 0) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP session can sign its device out', 400);
        }
        $service = new AppDeviceAuthService();
        $service->revokeDevice($deviceId, 'logout');
        (new \TokenHub\Services\AuditService())->write('admin.app_device.logout', 'admin_app_devices', $deviceId);

        return $this->clearDeviceCookie(Response::ok(['logged_out' => true]), AppDeviceAuthService::secureRequest($request));
    }

    /** GET /admin-api/my/app-devices */
    public function myDevices(Request $request): Response
    {
        $actor = App::context()->adminUser() ?? [];
        $auth = App::context()->adminAuth();
        $current = ($auth['channel'] ?? '') === 'app' ? (int) $auth['device_id'] : null;
        $limit = max(1, (int) App::config()->get('app.app_client.max_devices_per_user', 5));
        $service = new AppDeviceAuthService();
        $devices = $this->withoutApprovalCodes($service->listDevices((int) ($actor['id'] ?? 0), $current));

        return Response::ok([
            'devices' => $devices,
            'approval_required' => $service->approvalRequired(),
            'max_devices' => $limit,
            'current_device_id' => $current,
            'link_ttl_minutes' => (int) App::config()->get('app.app_client.link_ttl_minutes', 10),
            'secure_transport' => AppDeviceAuthService::secureRequest($request),
        ]);
    }

    /** POST /admin-api/my/app-devices/links — console only. */
    public function createMyLink(Request $request): Response
    {
        if (App::context()->adminChannel() !== 'web') {
            return Response::error('APP_LINK_CONSOLE_ONLY', 'Sign-in links can only be generated in the web console', 403);
        }
        $actor = App::context()->adminUser() ?? [];
        $origin = OAuthController::requestOrigin($request);
        $link = (new AppDeviceAuthService())->createLink($actor, $request->json(), $request->clientIp(), $origin);

        return Response::ok($link, 201);
    }

    /** DELETE /admin-api/my/app-devices/{id} */
    public function revokeMyDevice(Request $request): Response
    {
        $actor = App::context()->adminUser() ?? [];

        return Response::ok((new AppDeviceAuthService())->revokeOwnDevice((int) ($actor['id'] ?? 0), (int) ($request->params['id'] ?? 0)));
    }

    /** POST /admin-api/my/app-devices/{id}/approve — web console only (APP_NEW_DEVICE_APPROVAL). */
    public function approveMyDevice(Request $request): Response
    {
        if (App::context()->adminChannel() !== 'web') {
            return Response::error('APP_APPROVAL_CONSOLE_ONLY', 'New APP devices can only be approved in the web console', 403);
        }
        $actor = App::context()->adminUser() ?? [];

        return Response::ok((new AppDeviceAuthService())->approveDevice((int) ($actor['id'] ?? 0), (int) ($request->params['id'] ?? 0), (int) ($actor['id'] ?? 0)));
    }

    /** POST /admin-api/my/app-devices/{id}/reject — web console only. */
    public function rejectMyDevice(Request $request): Response
    {
        if (App::context()->adminChannel() !== 'web') {
            return Response::error('APP_APPROVAL_CONSOLE_ONLY', 'New APP devices can only be approved in the web console', 403);
        }
        $actor = App::context()->adminUser() ?? [];

        return Response::ok((new AppDeviceAuthService())->rejectDevice((int) ($actor['id'] ?? 0), (int) ($request->params['id'] ?? 0)));
    }

    /** POST /admin-api/my/app-devices/revoke-all — keeps the calling APP device. */
    public function revokeAllMyDevices(Request $request): Response
    {
        $actor = App::context()->adminUser() ?? [];
        $auth = App::context()->adminAuth();
        $keep = ($auth['channel'] ?? '') === 'app' ? (int) $auth['device_id'] : null;
        $userId = (int) ($actor['id'] ?? 0);
        $revoked = (new AppDeviceAuthService())->revokeUserDevices($userId, 'revoked_by_owner', $keep);
        (new \TokenHub\Services\AuditService())->write('admin.app_device.revoke_all', 'users', $userId, ['revoked' => $revoked]);

        return Response::ok(['revoked' => $revoked]);
    }

    // ------------------------------------------- other administrators (APP-008)

    /** GET /admin-api/admin-users/{id}/app-devices — super administrators only. */
    public function adminDevices(Request $request): Response
    {
        $target = $this->managedAdmin($request);
        $limit = max(1, (int) App::config()->get('app.app_client.max_devices_per_user', 5));

        return Response::ok([
            'user' => ['id' => (int) $target['id'], 'username' => (string) $target['username']],
            'devices' => $this->withoutApprovalCodes((new AppDeviceAuthService())->listDevices((int) $target['id'])),
            'approval_required' => (new AppDeviceAuthService())->approvalRequired(),
            'max_devices' => $limit,
            'link_ttl_minutes' => (int) App::config()->get('app.app_client.link_ttl_minutes', 10),
            'secure_transport' => AppDeviceAuthService::secureRequest($request),
        ]);
    }

    /** POST /admin-api/admin-users/{id}/app-devices/links — console only, audited. */
    public function createAdminLink(Request $request): Response
    {
        if (App::context()->adminChannel() !== 'web') {
            return Response::error('APP_LINK_CONSOLE_ONLY', 'Sign-in links can only be generated in the web console', 403);
        }
        $target = $this->managedAdmin($request);
        $actor = App::context()->adminUser() ?? [];
        $link = (new AppDeviceAuthService())->createLink($actor, $request->json(), $request->clientIp(), OAuthController::requestOrigin($request), (int) $target['id']);

        return Response::ok($link, 201);
    }

    /** DELETE /admin-api/admin-users/{id}/app-devices/{device} */
    public function revokeAdminDevice(Request $request): Response
    {
        $target = $this->managedAdmin($request);
        $result = (new AppDeviceAuthService())->revokeOwnDevice((int) $target['id'], (int) ($request->params['device'] ?? 0));

        return Response::ok($result);
    }

    /** POST /admin-api/admin-users/{id}/app-devices/{device}/approve — super administrators, web console only. */
    public function approveAdminDevice(Request $request): Response
    {
        if (App::context()->adminChannel() !== 'web') {
            return Response::error('APP_APPROVAL_CONSOLE_ONLY', 'New APP devices can only be approved in the web console', 403);
        }
        $target = $this->managedAdmin($request);
        $actor = App::context()->adminUser() ?? [];

        return Response::ok((new AppDeviceAuthService())->approveDevice((int) $target['id'], (int) ($request->params['device'] ?? 0), (int) ($actor['id'] ?? 0)));
    }

    /** POST /admin-api/admin-users/{id}/app-devices/{device}/reject */
    public function rejectAdminDevice(Request $request): Response
    {
        if (App::context()->adminChannel() !== 'web') {
            return Response::error('APP_APPROVAL_CONSOLE_ONLY', 'New APP devices can only be approved in the web console', 403);
        }
        $target = $this->managedAdmin($request);

        return Response::ok((new AppDeviceAuthService())->rejectDevice((int) $target['id'], (int) ($request->params['device'] ?? 0)));
    }

    /** POST /admin-api/admin-users/{id}/app-devices/revoke-all */
    public function revokeAllAdminDevices(Request $request): Response
    {
        $target = $this->managedAdmin($request);
        $revoked = (new AppDeviceAuthService())->revokeUserDevices((int) $target['id'], 'revoked_by_admin');
        (new \TokenHub\Services\AuditService())->write('admin.app_device.revoke_all', 'users', (int) $target['id'], ['revoked' => $revoked, 'by_other_admin' => true]);

        return Response::ok(['revoked' => $revoked]);
    }

    /**
     * Target administrator of /admin-api/admin-users/{id}/app-devices*. Same
     * rule as resetting an administrator's password: super administrators only.
     *
     * @return array<string, mixed>
     */
    private function managedAdmin(Request $request): array
    {
        $actor = App::context()->adminUser() ?? [];
        if (($actor['role'] ?? '') !== 'super_admin') {
            abort_json('ADMIN_PERMISSION_DENIED', 'Super admin permission required', 403);
        }
        $target = App::db()->first('SELECT id, username, role, status FROM users WHERE id = ? AND role IN ("admin", "super_admin")', [(int) ($request->params['id'] ?? 0)]);
        if ($target === null) {
            abort_json('ADMIN_NOT_FOUND', 'Administrator not found', 404);
        }

        return $target;
    }

    /** GET /admin-api/app-auth/status — APP session: PIN, step-up and push state. */
    public function status(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP session has an APP status', 400);
        }
        $policy = new AppStepUpPolicy();
        $push = new AppPushService();
        $pushReady = $push->ready();

        return Response::ok([
            'device_id' => (int) $auth['device_id'],
            'pin_set' => (bool) ($auth['pin_set'] ?? false),
            'step_up_required' => $policy->enabled(),
            'step_up_fresh' => $policy->recentlyVerified(isset($auth['step_up_at']) ? (string) $auth['step_up_at'] : null),
            'step_up_window_seconds' => $policy->windowSeconds(),
            'sensitive_operations' => array_values(array_unique(array_column($policy->rules(), 'label'))),
            'push' => [
                'available' => $pushReady,
                'subscribed' => $pushReady && $push->subscribed((int) $auth['device_id']),
                'preferences' => $pushReady ? $push->preferences((int) $auth['device_id']) : null,
                'native' => [
                    'ios' => (new \TokenHub\Services\AppNativePushService())->configured('ios'),
                    'android' => (new \TokenHub\Services\AppNativePushService())->configured('android'),
                ],
            ],
            'passkeys' => [
                'available' => (new AppPasskeyService())->available($request),
                'count' => (new AppPasskeyService())->count((int) $auth['device_id']),
            ],
            'secure_transport' => AppDeviceAuthService::secureRequest($request),
            'session_expires_at' => (string) ($auth['expires_at'] ?? ''),
        ]);
    }

    /**
     * GET /admin-api/app-auth/stream — live updates (Server-Sent Events) for an
     * APP device in the foreground. Short-lived (the APP reconnects), capped
     * per device and cluster-wide; 503 means "poll instead".
     */
    public function stream(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP session can open the live stream', 400);
        }
        $user = App::context()->adminUser() ?? [];
        $token = (string) ($auth['token'] ?? '');
        $deviceId = (int) $auth['device_id'];
        $realtime = new AppRealtimeService();
        $slot = $realtime->acquireSlot(App::context()->requestId(), $deviceId);
        if ($slot === null) {
            return Response::error('APP_STREAM_BUSY', 'Live updates are busy; polling instead', 503)->withHeader('Retry-After', '30');
        }

        return Response::stream(static function () use ($realtime, $slot, $user, $token): void {
            @set_time_limit(0);
            ignore_user_abort(true);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $send = static function (string $event, array $data): void {
                if ($event === 'ping') {
                    echo ": ping\n\n";
                } else {
                    echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                }
                @flush();
            };
            echo "retry: 3000\n\n";
            @flush();
            try {
                $realtime->run(
                    $user,
                    $send,
                    static fn (): bool => connection_aborted() === 0,
                    static fn (): bool => (new AppDeviceAuthService())->accessSession($token) !== null,
                    $slot
                );
            } finally {
                $realtime->releaseSlot($slot);
            }
        }, 200, [...Response::sseHeaders(), 'Cache-Control' => 'no-cache, no-store']);
    }

    /** POST /admin-api/app-auth/pin {pin, current_pin?} */
    public function setPin(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP session can set a PIN', 400);
        }
        $data = $request->json();
        $result = (new AppDeviceAuthService())->setPin(
            (int) $auth['device_id'],
            (int) $auth['session_id'],
            (string) ($data['pin'] ?? ''),
            isset($data['current_pin']) ? (string) $data['current_pin'] : null
        );

        return $this->pinResponse($result, $request);
    }

    /** POST /admin-api/app-auth/step-up {pin} */
    public function stepUp(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP session can verify a PIN', 400);
        }
        $result = (new AppDeviceAuthService())->stepUp((int) $auth['device_id'], (int) $auth['session_id'], (string) ($request->json()['pin'] ?? ''));

        return $this->pinResponse($result, $request);
    }

    /** POST /admin-api/app-auth/passkeys/options {purpose: register|verify} */
    public function passkeyOptions(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP device can use passkeys', 400);
        }
        $purpose = (string) ($request->json()['purpose'] ?? 'verify') === 'register' ? 'register' : 'verify';

        return Response::ok((new AppPasskeyService())->options($request, (int) $auth['device_id'], App::context()->adminUser() ?? [], $purpose));
    }

    /** POST /admin-api/app-auth/passkeys {credential, name?} — adding a passkey needs a fresh verification once the device has a PIN or passkey. */
    public function registerPasskey(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP device can register a passkey', 400);
        }
        $passkeys = new AppPasskeyService();
        $policy = new AppStepUpPolicy();
        $protected = (bool) ($auth['pin_set'] ?? false) || $passkeys->count((int) $auth['device_id']) > 0;
        if ($protected && !$policy->recentlyVerified(isset($auth['step_up_at']) ? (string) $auth['step_up_at'] : null)) {
            return Response::errorWith('ADMIN_STEP_UP_REQUIRED', 'Verify with your APP PIN to continue', 403, [
                'details' => ['operation' => '添加 Passkey', 'pin_set' => (bool) ($auth['pin_set'] ?? false), 'window_seconds' => $policy->windowSeconds()],
            ]);
        }
        $data = $request->json();
        $actor = App::context()->adminUser() ?? [];

        return Response::ok($passkeys->register(
            $request,
            (int) $auth['device_id'],
            (int) ($actor['id'] ?? 0),
            (int) $auth['session_id'],
            is_array($data['credential'] ?? null) ? $data['credential'] : [],
            (string) ($data['name'] ?? '')
        ));
    }

    /** DELETE /admin-api/app-auth/passkeys — removes this device's passkeys. */
    public function removePasskeys(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP device can remove its passkeys', 400);
        }

        return Response::ok(['removed' => (new AppPasskeyService())->removeAll((int) $auth['device_id'])]);
    }

    /** POST /admin-api/app-auth/step-up/passkey {credential} */
    public function stepUpPasskey(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP device can verify with a passkey', 400);
        }
        $data = $request->json();

        return Response::ok((new AppPasskeyService())->verify(
            $request,
            (int) $auth['device_id'],
            (int) $auth['session_id'],
            is_array($data['credential'] ?? null) ? $data['credential'] : []
        ));
    }

    /** GET /admin-api/app-auth/push/key — VAPID application server key. */
    public function pushKey(Request $request): Response
    {
        $push = new AppPushService();
        if (!$push->ready()) {
            return Response::error('APP_PUSH_UNAVAILABLE', 'Push notifications are not set up on this server', 503);
        }

        return Response::ok(['public_key' => $push->publicKey()]);
    }

    /** POST /admin-api/app-auth/push/subscription {endpoint, keys: {p256dh, auth}} */
    public function pushSubscribe(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP device can subscribe to push', 400);
        }
        $actor = App::context()->adminUser() ?? [];
        $result = (new AppPushService())->subscribe((int) ($actor['id'] ?? 0), (int) $auth['device_id'], $request->json());
        (new \TokenHub\Services\AuditService())->write('admin.app_device.push_subscribe', 'admin_app_devices', (int) $auth['device_id']);

        return Response::ok($result);
    }

    /** PUT /admin-api/app-auth/push/preferences {min_severity, quiet_start, quiet_end, time_zone} */
    public function pushPreferences(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP device has push rules', 400);
        }

        return Response::ok((new AppPushService())->savePreferences((int) $auth['device_id'], $request->json()));
    }

    /** POST /admin-api/app-auth/push/native {platform: ios|android, token} — install packages (APNs / FCM). */
    public function pushNative(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP device can subscribe to push', 400);
        }
        $data = $request->json();
        $actor = App::context()->adminUser() ?? [];
        $result = (new AppPushService())->subscribeNative((int) ($actor['id'] ?? 0), (int) $auth['device_id'], (string) ($data['platform'] ?? ''), (string) ($data['token'] ?? ''));
        (new \TokenHub\Services\AuditService())->write('admin.app_device.push_subscribe', 'admin_app_devices', (int) $auth['device_id'], ['kind' => $result['kind']]);

        return Response::ok($result);
    }

    /** DELETE /admin-api/app-auth/push/subscription */
    public function pushUnsubscribe(Request $request): Response
    {
        $auth = $this->appSession();
        if ($auth === null) {
            return Response::error('APP_SESSION_REQUIRED', 'Only an APP device can unsubscribe from push', 400);
        }

        return Response::ok(['removed' => (new AppPushService())->unsubscribe((int) $auth['device_id'])]);
    }

    /**
     * GET /admin-api/app-auth/ca.crt and /admin-api/app-auth/ca.mobileconfig —
     * public root certificate of Caddy's internal CA (https://IP deployments),
     * so phones can trust it and the APP becomes installable. Never a key.
     */
    public function caCertificate(Request $request): Response
    {
        $pem = $this->internalRootCertificate();
        if ($pem === null) {
            return Response::error('APP_CA_UNAVAILABLE', 'This server does not use the internal certificate authority', 404);
        }

        return Response::raw($pem, 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="tokenhub-root-ca.crt"',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function caMobileconfig(Request $request): Response
    {
        $pem = $this->internalRootCertificate();
        if ($pem === null) {
            return Response::error('APP_CA_UNAVAILABLE', 'This server does not use the internal certificate authority', 404);
        }
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true) ?: '';
        $fingerprint = strtoupper(hash('sha256', $der));
        $uuid = static function (string $seed): string {
            $h = strtoupper(hash('sha256', $seed));
            return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
        };
        $certUuid = $uuid('cert' . $fingerprint);
        $profileUuid = $uuid('profile' . $fingerprint);
        $data = $this->base64Lines($der);
        $host = htmlspecialchars((string) (parse_url(OAuthController::requestOrigin($request), PHP_URL_HOST) ?: 'TokenHub'), ENT_XML1);
        $plist = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>PayloadContent</key>
  <array>
    <dict>
      <key>PayloadCertificateFileName</key><string>tokenhub-root-ca.cer</string>
      <key>PayloadContent</key><data>{$data}</data>
      <key>PayloadDescription</key><string>TokenHub internal root certificate</string>
      <key>PayloadDisplayName</key><string>TokenHub 根证书 ({$host})</string>
      <key>PayloadIdentifier</key><string>com.tokenhub.ca.{$fingerprint}</string>
      <key>PayloadType</key><string>com.apple.security.root</string>
      <key>PayloadUUID</key><string>{$certUuid}</string>
      <key>PayloadVersion</key><integer>1</integer>
    </dict>
  </array>
  <key>PayloadDisplayName</key><string>TokenHub 根证书 ({$host})</string>
  <key>PayloadIdentifier</key><string>com.tokenhub.profile.{$fingerprint}</string>
  <key>PayloadRemovalDisallowed</key><false/>
  <key>PayloadType</key><string>Configuration</string>
  <key>PayloadUUID</key><string>{$profileUuid}</string>
  <key>PayloadVersion</key><integer>1</integer>
</dict>
</plist>
XML;

        return Response::raw($plist, 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="tokenhub-root-ca.mobileconfig"',
            'Cache-Control' => 'no-cache',
        ]);
    }

    private function internalRootCertificate(): ?string
    {
        $base = rtrim((string) App::config()->get('paths.caddy_data', ''), '/');
        $candidates = array_filter([
            $base !== '' ? $base . '/caddy/pki/authorities/local/root.crt' : null,
            $base !== '' ? $base . '/pki/authorities/local/root.crt' : null,
            '/data/caddy/pki/authorities/local/root.crt',
            '/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $pem = (string) file_get_contents($path);
                // Only ever a certificate, never key material.
                if (str_contains($pem, '-----BEGIN CERTIFICATE-----') && !str_contains($pem, 'PRIVATE KEY')) {
                    return $pem;
                }
            }
        }

        return null;
    }

    private function base64Lines(string $der): string
    {
        return "\n" . trim(chunk_split(base64_encode($der), 52, "\n")) . "\n      ";
    }

    /** @return array<string, mixed>|null */
    private function appSession(): ?array
    {
        $auth = App::context()->adminAuth();

        return ($auth['channel'] ?? '') === 'app' && (int) ($auth['device_id'] ?? 0) > 0 ? $auth : null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function pinResponse(array $result, Request $request): Response
    {
        if ($result['ok'] ?? false) {
            return Response::ok($result);
        }
        $code = (string) ($result['code'] ?? 'APP_PIN_INVALID');
        $status = match ($code) {
            'APP_PIN_LOCKED' => 401,
            'APP_PIN_NOT_SET' => 409,
            default => 403,
        };
        $message = match ($code) {
            'APP_PIN_LOCKED' => 'Too many wrong PINs; this device has been signed out',
            'APP_PIN_NOT_SET' => 'Set a PIN for this device first',
            default => 'Wrong PIN',
        };
        $response = Response::errorWith($code, $message, $status, ['details' => array_diff_key($result, ['ok' => 1, 'code' => 1])]);

        return $code === 'APP_PIN_LOCKED' ? $this->clearDeviceCookie($response, AppDeviceAuthService::secureRequest($request)) : $response;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function signedIn(array $result, bool $native, bool $secureRequest): Response
    {
        $payload = [
            ...$result['access'],
            'device' => $result['device'],
            'user' => $result['user'],
            'insecure_transport' => (bool) $result['insecure_transport'],
        ];
        if (($result['recovered'] ?? false) === true) {
            $payload['recovered'] = true;
        }
        $refresh = $result['refresh_token'] ?? null;
        if ($native) {
            // Null during the rotation grace period: keep the stored credential.
            $payload['refresh_token'] = $refresh;
            return Response::ok($payload);
        }
        $response = Response::ok($payload);
        if (is_string($refresh) && $refresh !== '') {
            $expires = strtotime((string) ($result['device']['absolute_expires_at'] ?? '')) ?: time() + 86400 * 30;
            $response = $response->withCookie(
                AppDeviceAuthService::REFRESH_COOKIE,
                $refresh,
                $expires,
                AppDeviceAuthService::REFRESH_COOKIE_PATH,
                // Secure whenever the request itself arrived over HTTPS; a Secure
                // cookie set over plain HTTP would be dropped and the device
                // could never stay signed in.
                secure: $secureRequest
            );
        }

        return $response;
    }

    /**
     * 202 after the link exchange / 409 APP_DEVICE_PENDING on refresh: the
     * device waits for approval. Carries the credential like a sign-in (cookie
     * or native body) but no access token.
     *
     * @param array<string, mixed> $result
     */
    private function pendingResponse(array $result, bool $native, bool $secureRequest, int $status): Response
    {
        $details = [
            'pending' => true,
            'match_code' => (string) ($result['match_code'] ?? ''),
            'approval_expires_at' => (string) ($result['approval_expires_at'] ?? ''),
            'device' => $result['device'] ?? null,
        ];
        if (isset($result['username'])) {
            $details['username'] = (string) $result['username'];
        }
        if ($status === 409) {
            return Response::errorWith('APP_DEVICE_PENDING', 'This device is waiting for approval in the web console', 409, ['details' => $details]);
        }
        $refresh = $result['refresh_token'] ?? null;
        if ($native) {
            return Response::ok([...$details, 'refresh_token' => $refresh, 'insecure_transport' => (bool) ($result['insecure_transport'] ?? false)], 202);
        }
        $response = Response::ok([...$details, 'insecure_transport' => (bool) ($result['insecure_transport'] ?? false)], 202);
        if (is_string($refresh) && $refresh !== '') {
            $expires = strtotime((string) ($result['device']['absolute_expires_at'] ?? '')) ?: time() + 86400 * 30;
            $response = $response->withCookie(AppDeviceAuthService::REFRESH_COOKIE, $refresh, $expires, AppDeviceAuthService::REFRESH_COOKIE_PATH, secure: $secureRequest);
        }

        return $response;
    }

    /**
     * Match codes are only shown in the web console (where devices are approved).
     *
     * @param list<array<string, mixed>> $devices
     * @return list<array<string, mixed>>
     */
    private function withoutApprovalCodes(array $devices): array
    {
        if (App::context()->adminChannel() === 'web') {
            return $devices;
        }

        return array_map(static function (array $device): array {
            unset($device['approval_code']);
            return $device;
        }, $devices);
    }

    private function clearDeviceCookie(Response $response, bool $secureRequest): Response
    {
        return $response->withCookie(
            AppDeviceAuthService::REFRESH_COOKIE,
            '',
            time() - 3600,
            AppDeviceAuthService::REFRESH_COOKIE_PATH,
            secure: $secureRequest
        );
    }

    private function rateLimited(string $scope, string $identity, int $limit): ?Response
    {
        if ($limit <= 0 || $identity === '') {
            return null;
        }
        try {
            $attempt = (new ExternalRateLimitService())->attempt($scope, $identity, $limit);
        } catch (\Throwable) {
            return null;
        }
        if (($attempt['allowed'] ?? true) !== true) {
            return Response::error('APP_AUTH_RATE_LIMITED', 'Too many attempts, try again in a minute', 429)
                ->withHeader('Retry-After', (string) ($attempt['retry_after'] ?? 60));
        }

        return null;
    }
}
