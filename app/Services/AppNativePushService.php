<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use Throwable;
use TokenHub\Support\App;

/**
 * Native push for the TokenHub APP install packages: APNs (iOS) over HTTP/2
 * with a token-based (.p8) key, and Firebase Cloud Messaging HTTP v1
 * (Android) with a service account. Each deployment brings its own
 * credentials (private deployments, see docs/app-admin.md); without them the
 * install packages simply rely on live updates while open.
 *
 * Like Web Push, messages carry no alert details.
 */
final class AppNativePushService
{
    private const APNS_PRODUCTION = 'https://api.push.apple.com';
    private const APNS_SANDBOX = 'https://api.sandbox.push.apple.com';
    private const FCM_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var null|callable(string, string, list<string>): array{status: int, body?: string, error?: string} */
    private $transport;

    /** @var array<string, array{token: string, expires: int}> */
    private static array $tokens = [];

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function configured(string $platform): bool
    {
        return match ($platform) {
            'ios' => $this->apnsConfig() !== null,
            'android' => $this->fcmConfig() !== null,
            default => false,
        };
    }

    /**
     * @param array{title: string, body: string, url: string, tag: string, severity: string} $message
     * @return array{status: int, error?: string, gone?: bool}
     */
    public function send(string $platform, string $deviceToken, array $message): array
    {
        try {
            return match ($platform) {
                'ios' => $this->sendApns($deviceToken, $message),
                'android' => $this->sendFcm($deviceToken, $message),
                default => ['status' => 0, 'error' => 'unsupported_platform'],
            };
        } catch (Throwable $throwable) {
            return ['status' => 0, 'error' => substr($throwable->getMessage(), 0, 200)];
        }
    }

    // ------------------------------------------------------------------ APNs

    /**
     * @param array{title: string, body: string, url: string, tag: string, severity: string} $message
     * @return array{status: int, error?: string, gone?: bool}
     */
    private function sendApns(string $deviceToken, array $message): array
    {
        $config = $this->apnsConfig();
        if ($config === null) {
            return ['status' => 0, 'error' => 'apns_not_configured'];
        }
        if (preg_match('/^[0-9a-fA-F]{32,200}$/', $deviceToken) !== 1) {
            return ['status' => 400, 'error' => 'invalid_device_token', 'gone' => true];
        }
        $jwt = $this->cachedToken('apns:' . $config['key_id'], 3000, fn (): string => self::apnsJwt($config['key_id'], $config['team_id'], $config['private_key']));
        $payload = (string) json_encode(self::apnsPayload($message), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result = $this->post(
            ($config['production'] ? self::APNS_PRODUCTION : self::APNS_SANDBOX) . '/3/device/' . strtolower($deviceToken),
            $payload,
            [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . $config['bundle_id'],
                'apns-push-type: alert',
                'apns-priority: ' . ($message['severity'] === 'critical' ? '10' : '5'),
                'apns-collapse-id: ' . substr($message['tag'], 0, 64),
                'content-type: application/json',
            ],
            true
        );
        $reason = (string) (json_decode((string) ($result['body'] ?? ''), true)['reason'] ?? '');
        if ($result['status'] === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            return ['status' => $result['status'], 'error' => $reason !== '' ? $reason : 'unregistered', 'gone' => true];
        }

        return ['status' => $result['status'], 'error' => $reason !== '' ? $reason : ($result['error'] ?? null)];
    }

    /**
     * @param array{title: string, body: string, url: string, tag: string, severity: string} $message
     * @return array<string, mixed>
     */
    public static function apnsPayload(array $message): array
    {
        return [
            'aps' => [
                'alert' => ['title' => $message['title'], 'body' => $message['body']],
                'sound' => 'default',
                'thread-id' => 'tokenhub-alerts',
                'interruption-level' => $message['severity'] === 'critical' ? 'time-sensitive' : 'active',
            ],
            'url' => $message['url'],
        ];
    }

    /** ES256 provider token for APNs (valid up to an hour; reused for 50 minutes). */
    public static function apnsJwt(string $keyId, string $teamId, string $privateKeyPem, ?int $issuedAt = null): string
    {
        $header = AppPushService::b64((string) json_encode(['alg' => 'ES256', 'kid' => $keyId]));
        $claims = AppPushService::b64((string) json_encode(['iss' => $teamId, 'iat' => $issuedAt ?? time()]));
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || !openssl_sign($header . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign the APNs token (check APNS_PRIVATE_KEY_PATH)');
        }

        return $header . '.' . $claims . '.' . AppPushService::b64(AppPushService::derSignatureToRaw((string) $der));
    }

    // ------------------------------------------------------------------ FCM

    /**
     * @param array{title: string, body: string, url: string, tag: string, severity: string} $message
     * @return array{status: int, error?: string, gone?: bool}
     */
    private function sendFcm(string $deviceToken, array $message): array
    {
        $config = $this->fcmConfig();
        if ($config === null) {
            return ['status' => 0, 'error' => 'fcm_not_configured'];
        }
        if (preg_match('/^[A-Za-z0-9_:\-\.]{20,4096}$/', $deviceToken) !== 1) {
            return ['status' => 400, 'error' => 'invalid_device_token', 'gone' => true];
        }
        $accessToken = $this->cachedToken('fcm:' . $config['client_email'], 3000, function () use ($config): string {
            $assertion = self::googleAssertion($config['client_email'], $config['private_key'], $config['token_uri']);
            $result = $this->post($config['token_uri'], http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]), ['content-type: application/x-www-form-urlencoded'], false);
            $token = (string) (json_decode((string) ($result['body'] ?? ''), true)['access_token'] ?? '');
            if ($token === '') {
                throw new RuntimeException('FCM authorization failed (HTTP ' . $result['status'] . ')');
            }
            return $token;
        });
        $result = $this->post(
            'https://fcm.googleapis.com/v1/projects/' . rawurlencode($config['project_id']) . '/messages:send',
            (string) json_encode(self::fcmPayload($deviceToken, $message), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['authorization: Bearer ' . $accessToken, 'content-type: application/json; charset=utf-8'],
            false
        );
        $status = (string) (json_decode((string) ($result['body'] ?? ''), true)['error']['status'] ?? '');
        if ($result['status'] === 404 || $status === 'NOT_FOUND' || $status === 'UNREGISTERED') {
            return ['status' => $result['status'], 'error' => 'unregistered', 'gone' => true];
        }

        return ['status' => $result['status'], 'error' => $status !== '' ? $status : ($result['error'] ?? null)];
    }

    /**
     * @param array{title: string, body: string, url: string, tag: string, severity: string} $message
     * @return array<string, mixed>
     */
    public static function fcmPayload(string $deviceToken, array $message): array
    {
        return [
            'message' => [
                'token' => $deviceToken,
                'notification' => ['title' => $message['title'], 'body' => $message['body']],
                'data' => ['url' => $message['url'], 'severity' => $message['severity']],
                'android' => [
                    'priority' => $message['severity'] === 'critical' ? 'HIGH' : 'NORMAL',
                    'collapse_key' => substr($message['tag'], 0, 64),
                    'notification' => ['channel_id' => 'tokenhub_alerts', 'tag' => substr($message['tag'], 0, 64)],
                ],
            ],
        ];
    }

    /** RS256 JWT assertion for a Google service account (OAuth 2.0 JWT bearer grant). */
    public static function googleAssertion(string $clientEmail, string $privateKeyPem, string $tokenUri = self::FCM_TOKEN_URL, ?int $issuedAt = null): string
    {
        $now = $issuedAt ?? time();
        $header = AppPushService::b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = AppPushService::b64((string) json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || !openssl_sign($header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign the FCM assertion (check FCM_SERVICE_ACCOUNT_PATH)');
        }

        return $header . '.' . $claims . '.' . AppPushService::b64((string) $signature);
    }

    // ----------------------------------------------------------- plumbing

    /** @return array{key_id: string, team_id: string, bundle_id: string, private_key: string, production: bool}|null */
    private function apnsConfig(): ?array
    {
        $keyId = trim((string) App::config()->get('app.app_client.apns_key_id', ''));
        $teamId = trim((string) App::config()->get('app.app_client.apns_team_id', ''));
        $bundleId = trim((string) App::config()->get('app.app_client.apns_bundle_id', ''));
        $path = trim((string) App::config()->get('app.app_client.apns_private_key_path', ''));
        if ($keyId === '' || $teamId === '' || $bundleId === '' || $path === '' || !is_readable($path)) {
            return null;
        }

        return [
            'key_id' => $keyId,
            'team_id' => $teamId,
            'bundle_id' => $bundleId,
            'private_key' => (string) file_get_contents($path),
            'production' => (bool) App::config()->get('app.app_client.apns_production', true),
        ];
    }

    /** @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null */
    private function fcmConfig(): ?array
    {
        $path = trim((string) App::config()->get('app.app_client.fcm_service_account_path', ''));
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        $account = json_decode((string) file_get_contents($path), true);
        if (!is_array($account) || empty($account['client_email']) || empty($account['private_key']) || empty($account['project_id'])) {
            return null;
        }

        return [
            'project_id' => (string) $account['project_id'],
            'client_email' => (string) $account['client_email'],
            'private_key' => (string) $account['private_key'],
            'token_uri' => (string) ($account['token_uri'] ?? self::FCM_TOKEN_URL),
        ];
    }

    private function cachedToken(string $name, int $ttl, callable $issue): string
    {
        $cached = self::$tokens[$name] ?? null;
        if ($cached !== null && $cached['expires'] > time()) {
            return $cached['token'];
        }
        $token = (string) $issue();
        self::$tokens[$name] = ['token' => $token, 'expires' => time() + $ttl];

        return $token;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body?: string, error?: string}
     */
    private function post(string $url, string $body, array $headers, bool $http2): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $body, $headers);
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'error' => 'curl_unavailable'];
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTP_VERSION => $http2 ? CURL_HTTP_VERSION_2_0 : CURL_HTTP_VERSION_1_1,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $error !== '' ? $error : null];
    }
}
