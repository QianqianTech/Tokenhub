<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\SchemaInspector;
use TokenHub\Support\UiLocale;

/**
 * Web Push for the TokenHub APP: VAPID keys (RFC 8292), message encryption
 * (RFC 8291, aes128gcm), subscriptions per APP device and alert delivery
 * through the alert_notifications queue (channel "web_push").
 *
 * Push messages never carry alert details: only "a new alert is waiting" with
 * its severity; the APP fetches the alert itself after the user taps it.
 */
final class AppPushService
{
    private const SETTING_KEY = 'app_push.vapid';
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    // ---------------------------------------------------------------- VAPID

    /** Public application server key (base64url, uncompressed P-256 point). */
    public function publicKey(): string
    {
        return $this->vapid()['public'];
    }

    /** @return array{public: string, private_pem: string} */
    private function vapid(): array
    {
        $row = App::db()->first('SELECT setting_value FROM system_settings WHERE setting_key = ?', [self::SETTING_KEY]);
        $value = $row ? json_decode((string) $row['setting_value'], true) : null;
        if (!is_array($value) || empty($value['public']) || empty($value['private'])) {
            $generated = self::generateKeyPair();
            App::db()->execute(
                'INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)',
                [self::SETTING_KEY, json_encode(['public' => $generated['public'], 'private' => Crypto::encrypt($generated['private_pem'])], JSON_UNESCAPED_SLASHES), 'TokenHub APP Web Push (VAPID) key pair']
            );
            $row = App::db()->first('SELECT setting_value FROM system_settings WHERE setting_key = ?', [self::SETTING_KEY]);
            $value = $row ? json_decode((string) $row['setting_value'], true) : null;
            if (!is_array($value)) {
                throw new RuntimeException('VAPID key pair could not be stored');
            }
        }
        $pem = Crypto::decrypt((string) $value['private']);
        if ($pem === '') {
            throw new RuntimeException('VAPID private key cannot be decrypted (APP_KEY changed?)');
        }

        return ['public' => (string) $value['public'], 'private_pem' => $pem];
    }

    /** @return array{public: string, private_pem: string} */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Cannot generate a P-256 key pair');
        }

        return ['public' => self::b64(self::rawPublicKey($key)), 'private_pem' => (string) $pem];
    }

    /**
     * "Authorization: vapid t=…, k=…" for one push endpoint.
     */
    public static function vapidAuthorization(string $endpoint, string $privatePem, string $publicKey, string $subject, ?int $expires = null): string
    {
        $parts = parse_url($endpoint);
        $audience = strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . strtolower((string) ($parts['host'] ?? '')) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $header = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64((string) json_encode(['aud' => $audience, 'exp' => $expires ?? time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false || !openssl_sign($header . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign the VAPID token');
        }

        return 'vapid t=' . $header . '.' . $claims . '.' . self::b64(self::derSignatureToRaw((string) $der)) . ', k=' . $publicKey;
    }

    // ----------------------------------------------------------- encryption

    /**
     * RFC 8291 message encryption (single aes128gcm record).
     *
     * @param string $userAgentPublic subscription keys.p256dh (base64url)
     * @param string $authSecret subscription keys.auth (base64url)
     * @param \OpenSSLAsymmetricKey|null $ephemeral test hook
     */
    public static function encrypt(string $payload, string $userAgentPublic, string $authSecret, $ephemeral = null, ?string $salt = null): string
    {
        $uaPublic = self::unb64($userAgentPublic);
        $auth = self::unb64($authSecret);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($auth) < 16) {
            throw new RuntimeException('Invalid push subscription keys');
        }
        $ephemeral ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($ephemeral === false) {
            throw new RuntimeException('Cannot generate an ephemeral key');
        }
        $asPublic = self::rawPublicKey($ephemeral);
        $peer = openssl_pkey_get_public(self::publicPem($uaPublic));
        $shared = $peer === false ? false : openssl_pkey_derive($peer, $ephemeral, 32);
        if ($shared === false || strlen($shared) !== 32) {
            throw new RuntimeException('ECDH failed');
        }
        $salt ??= random_bytes(16);

        $prkKey = hash_hmac('sha256', $shared, $auth, true);
        $ikm = hash_hmac('sha256', "WebPush: info\x00" . $uaPublic . $asPublic . "\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Push payload encryption failed');
        }

        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    // -------------------------------------------------------- subscriptions

    /**
     * @param array<string, mixed> $subscription PushSubscription.toJSON()
     * @return array<string, mixed>
     */
    public function subscribe(int $userId, int $deviceId, array $subscription, string $locale = 'zh-CN'): array
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $p256dh = trim((string) ($subscription['keys']['p256dh'] ?? ''));
        $auth = trim((string) ($subscription['keys']['auth'] ?? ''));
        if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 1000 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'A push subscription needs an https endpoint', 422);
        }
        if (strlen(self::unb64($p256dh)) !== 65 || strlen(self::unb64($auth)) < 16) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid push subscription keys', 422);
        }
        App::db()->transaction(function () use ($userId, $deviceId, $endpoint, $p256dh, $auth, $locale): void {
            App::db()->execute('DELETE FROM admin_app_push_subscriptions WHERE device_id = ? OR endpoint_hash = ?', [$deviceId, hash('sha256', $endpoint)]);
            App::db()->execute(
                'INSERT INTO admin_app_push_subscriptions (user_id, device_id, endpoint, endpoint_hash, p256dh, auth) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $deviceId, $endpoint, hash('sha256', $endpoint), $p256dh, $auth]
            );
            $this->saveLocale($deviceId, $locale);
        });

        return ['subscribed' => true];
    }

    private const SEVERITY_RANK = ['info' => 0, 'warning' => 1, 'critical' => 2];

    /** @return array{min_severity: string, quiet_start: ?string, quiet_end: ?string, time_zone: ?string} */
    public function preferences(int $deviceId): array
    {
        $defaults = ['min_severity' => 'warning', 'quiet_start' => null, 'quiet_end' => null, 'time_zone' => null];
        if (!$this->rulesReady()) {
            return $defaults;
        }
        $row = App::db()->first('SELECT min_severity, quiet_start, quiet_end, time_zone FROM admin_app_push_subscriptions WHERE device_id = ?', [$deviceId]);

        return $row === null ? $defaults : [
            'min_severity' => (string) $row['min_severity'],
            'quiet_start' => $row['quiet_start'] !== null ? substr((string) $row['quiet_start'], 0, 5) : null,
            'quiet_end' => $row['quiet_end'] !== null ? substr((string) $row['quiet_end'], 0, 5) : null,
            'time_zone' => $row['time_zone'] !== null ? (string) $row['time_zone'] : null,
        ];
    }

    /**
     * Push rules of one device (APP-037): lowest severity to deliver and an
     * optional quiet period (critical alerts still come through).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function savePreferences(int $deviceId, array $data): array
    {
        if (!$this->rulesReady()) {
            abort_json('APP_PUSH_UNAVAILABLE', 'Push rules need the latest database migration', 503);
        }
        if (!$this->subscribed($deviceId)) {
            abort_json('APP_PUSH_NOT_SUBSCRIBED', 'Turn on push notifications first', 409);
        }
        $severity = (string) ($data['min_severity'] ?? 'warning');
        if (!isset(self::SEVERITY_RANK[$severity])) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'min_severity must be info, warning or critical', 422);
        }
        $time = static function (mixed $value): ?string {
            $value = trim((string) ($value ?? ''));
            if ($value === '') {
                return null;
            }
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) !== 1) {
                abort_json('ADMIN_INVALID_PAYLOAD', 'Quiet hours use HH:MM', 422);
            }
            return $value . ':00';
        };
        $start = $time($data['quiet_start'] ?? null);
        $end = $time($data['quiet_end'] ?? null);
        if (($start === null) !== ($end === null) || ($start !== null && $start === $end)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Quiet hours need a start and a different end time', 422);
        }
        $zone = trim((string) ($data['time_zone'] ?? ''));
        if ($zone !== '' && !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Unknown time zone', 422);
        }
        App::db()->execute(
            'UPDATE admin_app_push_subscriptions SET min_severity = ?, quiet_start = ?, quiet_end = ?, time_zone = ? WHERE device_id = ?',
            [$severity, $start, $end, $zone !== '' ? $zone : null, $deviceId]
        );

        return $this->preferences($deviceId);
    }

    /** True when an alert of $severity should be pushed now under these rules. */
    public static function allowedByRules(string $severity, string $minSeverity, ?string $quietStart, ?string $quietEnd, ?string $timeZone, ?\DateTimeImmutable $now = null): bool
    {
        $rank = self::SEVERITY_RANK[$severity] ?? 1;
        if ($rank < (self::SEVERITY_RANK[$minSeverity] ?? 1)) {
            return false;
        }
        if ($severity === 'critical' || $quietStart === null || $quietEnd === null) {
            return true;
        }
        try {
            $zone = new \DateTimeZone($timeZone ?: date_default_timezone_get());
        } catch (Throwable) {
            $zone = new \DateTimeZone('UTC');
        }
        $clock = ($now ?? new \DateTimeImmutable('now'))->setTimezone($zone)->format('H:i:s');
        $start = strlen($quietStart) === 5 ? $quietStart . ':00' : $quietStart;
        $end = strlen($quietEnd) === 5 ? $quietEnd . ':00' : $quietEnd;
        $quiet = $start < $end ? ($clock >= $start && $clock < $end) : ($clock >= $start || $clock < $end);

        return !$quiet;
    }

    private function rulesReady(): bool
    {
        return \TokenHub\Support\SchemaInspector::hasColumn('admin_app_push_subscriptions', 'min_severity');
    }

    /**
     * Registers the APNs / FCM token of an install package (native push).
     *
     * @return array<string, mixed>
     */
    public function subscribeNative(int $userId, int $deviceId, string $platform, string $token, string $locale = 'zh-CN'): array
    {
        if (!in_array($platform, ['ios', 'android'], true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'platform must be ios or android', 422);
        }
        $token = trim($token);
        if ($token === '' || strlen($token) > 900 || preg_match('/^[A-Za-z0-9_:\-\.]+$/', $token) !== 1) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid device push token', 422);
        }
        if (!\TokenHub\Support\SchemaInspector::hasColumn('admin_app_push_subscriptions', 'kind')) {
            abort_json('APP_PUSH_UNAVAILABLE', 'Native push needs the latest database migration', 503);
        }
        if (!(new AppNativePushService())->configured($platform)) {
            abort_json('APP_PUSH_UNAVAILABLE', 'Native push is not configured on this server', 503);
        }
        $endpoint = $platform . '://' . $token;
        App::db()->transaction(function () use ($userId, $deviceId, $platform, $endpoint, $locale): void {
            App::db()->execute('DELETE FROM admin_app_push_subscriptions WHERE device_id = ? OR endpoint_hash = ?', [$deviceId, hash('sha256', $endpoint)]);
            App::db()->execute(
                'INSERT INTO admin_app_push_subscriptions (user_id, device_id, kind, endpoint, endpoint_hash, p256dh, auth) VALUES (?, ?, ?, ?, ?, "", "")',
                [$userId, $deviceId, $platform, $endpoint, hash('sha256', $endpoint)]
            );
            $this->saveLocale($deviceId, $locale);
        });

        return ['subscribed' => true, 'kind' => $platform];
    }

    public function unsubscribe(int $deviceId): int
    {
        return App::db()->execute('DELETE FROM admin_app_push_subscriptions WHERE device_id = ?', [$deviceId]);
    }

    public function subscribed(int $deviceId): bool
    {
        return App::db()->first('SELECT id FROM admin_app_push_subscriptions WHERE device_id = ?', [$deviceId]) !== null;
    }

    public function saveLocale(int $deviceId, string $locale): void
    {
        if (SchemaInspector::hasColumn('admin_app_push_subscriptions', 'locale')) {
            $locale = UiLocale::normalize($locale);
            App::db()->execute('UPDATE admin_app_push_subscriptions SET locale = ? WHERE device_id = ? AND locale <> ?',
                [$locale, $deviceId, $locale]);
        }
    }

    // -------------------------------------------------------- alert delivery

    /**
     * Queues one web_push notification per subscribed APP device of administrators who may see alerts.
     *
     * @param list<int>|null $onlyUserIds restricts the recipients (tests)
     */
    public function queueAlert(int $alertEventId, string $kind, ?array $onlyUserIds = null): int
    {
        if (!$this->ready() || $kind === 'resolved') {
            return 0;
        }
        $severity = (string) (App::db()->first('SELECT severity FROM alert_events WHERE id = ?', [$alertEventId])['severity'] ?? 'warning');
        $rules = $this->rulesReady()
            ? 's.min_severity AS push_min_severity, s.quiet_start AS push_quiet_start, s.quiet_end AS push_quiet_end, s.time_zone AS push_time_zone, '
            : '';
        $rows = App::db()->select(
            'SELECT ' . $rules . 's.id AS subscription_id, u.*
               FROM admin_app_push_subscriptions s
               JOIN admin_app_devices dv ON dv.id = s.device_id AND dv.status = "active" AND dv.idle_expires_at > UTC_TIMESTAMP() AND dv.absolute_expires_at > UTC_TIMESTAMP()
               JOIN users u ON u.id = s.user_id AND u.status = "active" AND u.role IN ("admin", "super_admin")'
        );
        $permissions = new PermissionService();
        $queued = 0;
        foreach ($rows as $row) {
            $subscriptionId = (int) $row['subscription_id'];
            $allowed = !array_key_exists('push_min_severity', $row) || self::allowedByRules(
                $severity,
                (string) $row['push_min_severity'],
                $row['push_quiet_start'] !== null ? (string) $row['push_quiet_start'] : null,
                $row['push_quiet_end'] !== null ? (string) $row['push_quiet_end'] : null,
                $row['push_time_zone'] !== null ? (string) $row['push_time_zone'] : null
            );
            unset($row['subscription_id'], $row['push_min_severity'], $row['push_quiet_start'], $row['push_quiet_end'], $row['push_time_zone']);
            if (!$allowed) {
                continue;
            }
            if ($onlyUserIds !== null && !in_array((int) $row['id'], $onlyUserIds, true)) {
                continue;
            }
            if (!$permissions->hasCapability($row, 'logs.read')) {
                continue;
            }
            App::db()->execute(
                'INSERT INTO alert_notifications (alert_event_id, channel, target, kind, status, next_attempt_at) VALUES (?, "web_push", ?, ?, "pending", UTC_TIMESTAMP())',
                [$alertEventId, 'subscription:' . $subscriptionId, $kind]
            );
            $queued++;
        }

        return $queued;
    }

    /**
     * Sends one queued web_push notification.
     *
     * @param array<string, mixed> $row alert_notifications row joined with alert_events
     * @param null|callable(string, string, list<string>): array{status: int, error?: string} $transport
     * @return array{status: int, error?: string, gone?: bool}
     */
    public function deliver(array $row, ?callable $transport = null): array
    {
        $subscriptionId = (int) preg_replace('/^subscription:/', '', (string) ($row['target'] ?? ''));
        $subscription = App::db()->first('SELECT * FROM admin_app_push_subscriptions WHERE id = ?', [$subscriptionId]);
        if ($subscription === null) {
            return ['status' => 410, 'error' => 'subscription_gone', 'gone' => true];
        }
        $severity = (string) ($row['severity'] ?? 'warning');
        $english = ($subscription['locale'] ?? null) === 'en-US';
        $message = [
            'title' => $row['kind'] === 'escalated'
                ? ($english ? 'TokenHub alert escalated' : 'TokenHub 告警升级')
                : ($english ? 'New TokenHub alert' : 'TokenHub 新告警'),
            'body' => match ($severity) {
                'critical' => $english ? 'Tap to review a critical alert.' : '有一条严重告警待处理，点按查看。',
                'info' => $english ? 'Tap to view an informational alert.' : '有一条提示类告警，点按查看。',
                default => $english ? 'Tap to review a new alert.' : '有一条告警待处理，点按查看。',
            },
            'url' => '/app/#/security/alert-events',
            'tag' => 'tokenhub-alert-' . (int) ($row['alert_event_id'] ?? 0),
            'severity' => $severity,
        ];
        $kind = (string) ($subscription['kind'] ?? 'webpush');
        if ($kind === 'ios' || $kind === 'android') {
            // Install packages: APNs / FCM with the deployment's own credentials.
            $result = (new AppNativePushService($transport))->send($kind, (string) preg_replace('#^[a-z]+://#', '', (string) $subscription['endpoint']), $message);
            if (!empty($result['gone'])) {
                App::db()->execute('DELETE FROM admin_app_push_subscriptions WHERE id = ?', [$subscriptionId]);
            } elseif ($result['status'] >= 200 && $result['status'] < 300) {
                App::db()->execute('UPDATE admin_app_push_subscriptions SET last_success_at = UTC_TIMESTAMP() WHERE id = ?', [$subscriptionId]);
            }

            return $result;
        }
        $payload = (string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $vapid = $this->vapid();
            $body = self::encrypt($payload, (string) $subscription['p256dh'], (string) $subscription['auth']);
            $headers = [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400',
                'Urgency: ' . ($severity === 'critical' ? 'high' : 'normal'),
                'Authorization: ' . self::vapidAuthorization((string) $subscription['endpoint'], $vapid['private_pem'], $vapid['public'], $this->subject()),
                'User-Agent: TokenHub-Push/1',
            ];
        } catch (Throwable $throwable) {
            return ['status' => 0, 'error' => substr($throwable->getMessage(), 0, 200)];
        }

        $result = $transport !== null
            ? $transport((string) $subscription['endpoint'], $body, $headers)
            : $this->post((string) $subscription['endpoint'], $body, $headers);
        if (in_array((int) $result['status'], [404, 410], true)) {
            // The browser dropped the subscription.
            App::db()->execute('DELETE FROM admin_app_push_subscriptions WHERE id = ?', [$subscriptionId]);
            $result['gone'] = true;
        } elseif ($result['status'] >= 200 && $result['status'] < 300) {
            App::db()->execute('UPDATE admin_app_push_subscriptions SET last_success_at = UTC_TIMESTAMP() WHERE id = ?', [$subscriptionId]);
        }

        return $result;
    }

    public function ready(): bool
    {
        return \TokenHub\Support\SchemaInspector::hasColumn('admin_app_push_subscriptions', 'endpoint_hash');
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, error?: string}
     */
    private function post(string $endpoint, string $body, array $headers): array
    {
        $check = (new OutboundUrlPolicy())->check($endpoint, OutboundUrlPolicy::PURPOSE_PROVIDER, ['allow_private' => false]);
        if (!$check['ok']) {
            return ['status' => 0, 'error' => 'outbound_blocked:' . ($check['reason'] ?? 'denied')];
        }
        try {
            $result = PinnedHttpPost::send($endpoint, (string) (($check['addresses'] ?? [])[0] ?? ''), $body, $headers, 10);

            return $result['status'] === 0 ? ['status' => 0, 'error' => $result['error'] !== '' ? $result['error'] : 'connection_failed'] : ['status' => $result['status']];
        } catch (Throwable $throwable) {
            return ['status' => 0, 'error' => $throwable->getMessage()];
        }
    }

    private function subject(): string
    {
        $configured = trim((string) App::config()->get('app.app_client.push_subject', ''));
        if ($configured !== '') {
            return $configured;
        }
        $url = (string) App::config()->get('app.url', '');

        return str_starts_with($url, 'https://') ? rtrim($url, '/') : 'mailto:admin@tokenhub.invalid';
    }

    // -------------------------------------------------------------- helpers

    /** @param \OpenSSLAsymmetricKey $key */
    private static function rawPublicKey($key): string
    {
        $details = openssl_pkey_get_details($key);
        $x = (string) ($details['ec']['x'] ?? '');
        $y = (string) ($details['ec']['y'] ?? '');
        if ($x === '' || $y === '') {
            throw new RuntimeException('Not a P-256 key');
        }

        return "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
    }

    private static function publicPem(string $rawPoint): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $rawPoint;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** ASN.1 DER ECDSA signature -> 64-byte r||s (JWS ES256). */
    public static function derSignatureToRaw(string $der): string
    {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new RuntimeException('Invalid ECDSA signature');
            }
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;
            $parts[] = str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }
}
