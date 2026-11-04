<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Request;

/**
 * Sign-in for the TokenHub APP (installable PWA, later native shells).
 *
 * Credentials (only HMAC hashes are stored):
 * - link code   thl_…  one-time, short-lived; generated in the web console and
 *                      carried in the link fragment (/app/link#c=…).
 * - device      thr_…  long-lived refresh credential, rotated on every use. The
 *                      PWA keeps it in an HttpOnly cookie scoped to
 *                      /admin-api/app-auth; native shells keep it in the OS
 *                      keychain. A rotated credential stays valid for a short
 *                      grace period (parallel tabs); replaying it later means it
 *                      was copied, and the whole device is revoked.
 * - access      tha_…  short-lived bearer token, an admin_sessions row with
 *                      channel = 'app'. Lives only in memory on the device, so
 *                      a crash or kill loses it and the APP silently refreshes.
 */
final class AppDeviceAuthService
{
    public const LINK_PREFIX = 'thl_';
    public const REFRESH_PREFIX = 'thr_';
    public const ACCESS_PREFIX = 'tha_';
    public const REFRESH_COOKIE = 'tokenhub_app_device';
    public const REFRESH_COOKIE_PATH = '/admin-api/app-auth';
    public const LINK_PATH = '/app/link';

    /** Minutes a new device may wait for approval in the web console. */
    public const APPROVAL_MINUTES = 10;
    private const PLATFORMS = ['ios', 'android', 'macos', 'windows', 'linux', 'web'];

    // ------------------------------------------------------------------ links

    /**
     * One-time sign-in link for the acting administrator's own new device.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $data note
     * @return array<string, mixed>
     */
    public function createLink(array $actor, array $data, string $clientIp, string $origin = '', ?int $targetUserId = null): array
    {
        $actorId = (int) ($actor['id'] ?? 0);
        $userId = $targetUserId ?? $actorId;
        $user = $this->activeAdmin($userId);
        if ($user === null) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Only an active administrator can link APP devices', 403);
        }
        $this->assertDeviceSlotAvailable($userId);

        $ttl = max(1, min(60, $this->setting('link_ttl_minutes', 10)));
        $note = $this->cleanText((string) ($data['note'] ?? ''), 100);
        $code = Crypto::token(self::LINK_PREFIX);

        // Only the newest link of a user stays usable.
        App::db()->execute('UPDATE admin_app_link_codes SET expires_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()', [$userId]);
        App::db()->execute(
            'INSERT INTO admin_app_link_codes (user_id, created_by, code_hash, note, expires_at, created_ip) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), ?)',
            [$userId, $actorId, Crypto::hashKey($code), $note === '' ? null : $note, $ttl, $this->cleanText($clientIp, 64)]
        );
        $id = (int) App::db()->pdo()->lastInsertId();
        $row = App::db()->first('SELECT expires_at FROM admin_app_link_codes WHERE id = ?', [$id]);
        (new AuditService())->write('admin.app_link.create', 'users', $userId, ['link_id' => $id, 'ttl_minutes' => $ttl, 'for_other_admin' => $userId !== $actorId]);

        $path = self::LINK_PATH . '#c=' . $code;

        return [
            'id' => $id,
            'code' => $code,
            'path' => $path,
            'link' => $origin !== '' ? rtrim($origin, '/') . $path : $path,
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'ttl_seconds' => $ttl * 60,
            'server_time' => $this->now(),
        ];
    }

    /**
     * Exchanges a link code for a new device credential and a first access token.
     *
     * @param array<string, mixed> $device name, platform
     * @return array<string, mixed>
     */
    public function exchange(string $code, array $device, string $clientType, string $clientIp, string $userAgent, bool $secureTransport): array
    {
        $code = trim($code);
        if (!$this->wellFormed($code, self::LINK_PREFIX)) {
            abort_json('APP_LINK_INVALID', 'The sign-in link is invalid or has expired', 401);
        }
        $clientType = $clientType === 'native' ? 'native' : 'pwa';
        $platform = strtolower(trim((string) ($device['platform'] ?? '')));
        $platform = in_array($platform, self::PLATFORMS, true) ? $platform : 'web';
        $name = $this->cleanText((string) ($device['name'] ?? ''), 100);
        $idleDays = max(1, $secureTransport ? $this->setting('device_idle_days', 30) : $this->setting('http_device_idle_days', 7));
        $maxDays = max(1, $this->setting('device_max_days', 180));
        $refresh = Crypto::token(self::REFRESH_PREFIX);
        $approval = $this->approvalRequired();

        $result = App::db()->transaction(function () use ($code, $clientType, $platform, $name, $idleDays, $maxDays, $refresh, $clientIp, $userAgent, $secureTransport, $approval): array {
            $link = App::db()->first(
                'SELECT l.*, (l.expires_at > UTC_TIMESTAMP()) AS live FROM admin_app_link_codes l WHERE l.code_hash = ? FOR UPDATE',
                [Crypto::hashKey($code)]
            );
            if ($link === null || $link['used_at'] !== null || (int) $link['live'] !== 1) {
                return ['error' => 'APP_LINK_INVALID'];
            }
            $cidrs = json_decode((string) ($link['allowed_ip_cidrs'] ?? '[]'), true);
            if (is_array($cidrs) && $cidrs !== [] && !$this->ipAllowed($clientIp, $cidrs)) {
                return ['error' => 'APP_LINK_IP_FORBIDDEN'];
            }
            $user = $this->activeAdmin((int) $link['user_id']);
            if ($user === null) {
                return ['error' => 'APP_LINK_INVALID'];
            }
            if ($this->activeDeviceCount((int) $user['id']) >= $this->maxDevices()) {
                return ['error' => 'APP_DEVICE_LIMIT'];
            }
            $claimed = App::db()->execute('UPDATE admin_app_link_codes SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL', [$link['id']]);
            if ($claimed !== 1) {
                return ['error' => 'APP_LINK_INVALID'];
            }
            App::db()->execute(
                'INSERT INTO admin_app_devices (user_id, name, platform, client_type, user_agent, refresh_hash, bound_over_http, link_code_id, last_seen_at, last_ip, idle_expires_at, absolute_expires_at, created_ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, LEAST(DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), ?)',
                [
                    $user['id'],
                    $name !== '' ? $name : ($link['note'] ?: $this->defaultDeviceName($platform)),
                    $platform,
                    $clientType,
                    substr($userAgent, 0, 255),
                    Crypto::hashKey($refresh),
                    $secureTransport ? 0 : 1,
                    $link['id'],
                    $this->cleanText($clientIp, 64),
                    $idleDays,
                    $maxDays,
                    $maxDays,
                    $this->cleanText($clientIp, 64),
                ]
            );
            $deviceId = (int) App::db()->pdo()->lastInsertId();
            App::db()->execute('UPDATE admin_app_link_codes SET used_device_id = ? WHERE id = ?', [$deviceId, $link['id']]);
            if ($approval) {
                // New-device approval: the device waits (without any access) until
                // the administrator confirms it in the web console.
                App::db()->execute(
                    'UPDATE admin_app_devices SET status = "pending", approval_code = ?, idle_expires_at = LEAST(DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), absolute_expires_at) WHERE id = ?',
                    [str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT), self::APPROVAL_MINUTES, $deviceId]
                );
            }

            return ['user' => $user, 'device_id' => $deviceId];
        });

        if (isset($result['error'])) {
            match ($result['error']) {
                'APP_DEVICE_LIMIT' => abort_json('APP_DEVICE_LIMIT', 'Too many APP devices are linked to this account; revoke one first', 409),
                'APP_LINK_IP_FORBIDDEN' => abort_json('APP_LINK_IP_FORBIDDEN', 'This sign-in link cannot be used from this network', 403),
                default => abort_json('APP_LINK_INVALID', 'The sign-in link is invalid or has expired', 401),
            };
        }

        $user = $result['user'];
        $device = $this->deviceRow((int) $result['device_id']);
        if (($device['status'] ?? '') === 'pending') {
            App::context()->setAdminUser(['id' => (int) $user['id']]);
            App::context()->setAdminAuth(['channel' => 'app', 'device_id' => (int) $device['id']]);
            (new AuditService())->write('admin.app_device.link_pending', 'admin_app_devices', $device['id'], [
                'platform' => $device['platform'],
                'client_type' => $device['client_type'],
            ]);
            App::context()->setAdminUser(null);

            return [
                'pending' => true,
                'refresh_token' => $refresh,
                'device' => $this->publicDevice($device),
                'username' => (string) $user['username'],
                'match_code' => (string) $device['approval_code'],
                'approval_expires_at' => (string) $device['idle_expires_at'],
                'insecure_transport' => !$secureTransport,
            ];
        }
        $access = $this->issueAccess($device, $clientIp, $userAgent);
        $publicUser = (new AuthService())->publicUser($user);
        App::context()->setAdminUser($publicUser);
        App::context()->setAdminAuth(['channel' => 'app', 'device_id' => (int) $device['id']]);
        (new AuditService())->write('admin.app_device.link', 'admin_app_devices', $device['id'], [
            'platform' => $device['platform'],
            'client_type' => $device['client_type'],
            'bound_over_http' => (bool) $device['bound_over_http'],
        ]);

        return [
            'refresh_token' => $refresh,
            'access' => $access,
            'device' => $this->publicDevice($device),
            'user' => $publicUser,
            'insecure_transport' => !$secureTransport,
        ];
    }

    /**
     * After a successful exchange, signs out the device this browser was
     * linked as before (its credential cookie came with the request), so
     * re-linking the same browser does not leave an orphaned device behind.
     */
    public function replacePreviousDevice(string $previousRefreshToken, int $userId, int $newDeviceId): bool
    {
        if (!$this->wellFormed(trim($previousRefreshToken), self::REFRESH_PREFIX)) {
            return false;
        }
        $hash = Crypto::hashKey(trim($previousRefreshToken));
        $row = App::db()->first(
            'SELECT id FROM admin_app_devices WHERE (refresh_hash = ? OR prev_refresh_hash = ?) AND user_id = ? AND id <> ? AND status = "active"',
            [$hash, $hash, $userId, $newDeviceId]
        );

        return $row !== null && $this->revokeDevice((int) $row['id'], 'replaced');
    }

    // ---------------------------------------------------------------- refresh

    /**
     * Rotates a device credential and issues a new access token. Within the
     * grace period after a rotation the previous credential still yields an
     * access token (refresh_token is then null and the client keeps the newer
     * one it already received); after it, the replay revokes the device.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken, string $clientIp, string $userAgent): array
    {
        $refreshToken = trim($refreshToken);
        if (!$this->wellFormed($refreshToken, self::REFRESH_PREFIX)) {
            abort_json('APP_DEVICE_INVALID', 'This device is signed out; open a new sign-in link', 401);
        }
        $hash = Crypto::hashKey($refreshToken);

        $device = $this->deviceByCredential('refresh_hash', $hash);
        if ($device !== null && ($device['status'] ?? '') === 'pending') {
            return $this->pendingState($device);
        }
        if ($device !== null && ($device['status'] ?? '') === 'revoked' && ($device['revoke_reason'] ?? '') === 'rejected') {
            abort_json('APP_DEVICE_REJECTED', 'This device was rejected in the web console; open a new sign-in link', 401);
        }
        if ($device !== null && ($device['status'] ?? '') === 'expired' && ($device['revoke_reason'] ?? '') === 'approval_expired') {
            abort_json('APP_DEVICE_APPROVAL_EXPIRED', 'This device was not approved in time; open a new sign-in link', 401);
        }
        if ($device !== null) {
            $this->assertDeviceUsable($device);
            $next = Crypto::token(self::REFRESH_PREFIX);
            $idleDays = max(1, (int) $device['bound_over_http'] === 1 ? $this->setting('http_device_idle_days', 7) : $this->setting('device_idle_days', 30));
            $rotated = App::db()->execute(
                'UPDATE admin_app_devices
                    SET prev_refresh_hash = refresh_hash,
                        prev_valid_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                        refresh_hash = ?,
                        last_seen_at = UTC_TIMESTAMP(),
                        last_ip = ?,
                        idle_expires_at = LEAST(DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), absolute_expires_at)
                  WHERE id = ? AND refresh_hash = ? AND status = "active"',
                [max(0, $this->setting('refresh_grace_seconds', 30)), Crypto::hashKey($next), $this->cleanText($clientIp, 64), $idleDays, $device['id'], $hash]
            );
            if ($rotated === 1) {
                $this->rememberClient((int) $device['id'], $userAgent);
                $device = $this->deviceRow((int) $device['id']);
                return $this->refreshResult($device, $next, $clientIp, $userAgent);
            }
            // Lost a race with a parallel refresh of the same credential: it is
            // now the previous credential, handled by the grace path below.
        }

        $device = $this->deviceByCredential('prev_refresh_hash', $hash);
        if ($device === null || ($device['status'] ?? '') !== 'active') {
            abort_json('APP_DEVICE_INVALID', 'This device is signed out; open a new sign-in link', 401);
        }
        if ((int) ($device['prev_live'] ?? 0) === 1) {
            $this->assertDeviceUsable($device);
            return $this->refreshResult($device, null, $clientIp, $userAgent);
        }

        // The previous credential after the grace period. Its successor has not
        // been used yet (using it would have replaced the previous hash), so
        // this is either a client that lost the rotation response (killed or
        // offline mid-refresh) or a copied credential. The same client software
        // recovers with a fresh credential so an abnormal exit never forces a
        // new sign-in; anything else is treated as a stolen credential. The
        // same client must also come from the same network (/24 for IPv4,
        // /48 for IPv6) as the device's last refresh: a copied credential
        // replayed with a spoofed User-Agent from elsewhere is a replay.
        $sameClient = (string) ($device['user_agent'] ?? '') !== '' && hash_equals((string) $device['user_agent'], substr($userAgent, 0, 255))
            && self::sameNetwork((string) ($device['last_ip'] ?? ''), $clientIp);
        if ($sameClient) {
            $this->assertDeviceUsable($device);
            $next = Crypto::token(self::REFRESH_PREFIX);
            $idleDays = max(1, (int) $device['bound_over_http'] === 1 ? $this->setting('http_device_idle_days', 7) : $this->setting('device_idle_days', 30));
            $recovered = App::db()->execute(
                'UPDATE admin_app_devices
                    SET refresh_hash = ?, prev_refresh_hash = NULL, prev_valid_until = NULL,
                        last_seen_at = UTC_TIMESTAMP(), last_ip = ?,
                        idle_expires_at = LEAST(DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), absolute_expires_at)
                  WHERE id = ? AND prev_refresh_hash = ? AND status = "active"',
                [Crypto::hashKey($next), $this->cleanText($clientIp, 64), $idleDays, $device['id'], $hash]
            );
            if ($recovered === 1) {
                App::context()->setAdminUser(['id' => (int) $device['user_id']]);
                App::context()->setAdminAuth(['channel' => 'app', 'device_id' => (int) $device['id']]);
                (new AuditService())->write('admin.app_device.refresh_recovered', 'admin_app_devices', $device['id'], ['client_ip' => $clientIp]);
                App::context()->setAdminUser(null);
                // The APP tells the user once that the sign-in was recovered.
                return ['recovered' => true] + $this->refreshResult($this->deviceRow((int) $device['id']), $next, $clientIp, $userAgent);
            }
            abort_json('APP_DEVICE_INVALID', 'This device is signed out; open a new sign-in link', 401);
        }

        $this->revokeDevice((int) $device['id'], 'refresh_reuse');
        App::context()->setAdminUser(['id' => (int) $device['user_id']]);
        App::context()->setAdminAuth(['channel' => 'app', 'device_id' => (int) $device['id']]);
        (new AuditService())->write('admin.app_device.reuse_detected', 'admin_app_devices', $device['id'], ['client_ip' => $clientIp, 'last_ip' => $device['last_ip'] ?? null]);
        App::context()->setAdminUser(null);
        abort_json('APP_DEVICE_INVALID', 'This device is signed out; open a new sign-in link', 401);
    }

    /**
     * True when both addresses are in the same /24 (IPv4) or /48 (IPv6)
     * network. Empty, invalid or mixed-family addresses never match.
     */
    public static function sameNetwork(string $knownIp, string $clientIp): bool
    {
        $a = @inet_pton(trim($knownIp));
        $b = @inet_pton(trim($clientIp));
        if ($a === false || $b === false) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) compares as IPv4.
        $a = self::unmapIpv4($a);
        $b = self::unmapIpv4($b);
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $prefixBytes = strlen($a) === 4 ? 3 : 6;

        return hash_equals(substr($a, 0, $prefixBytes), substr($b, 0, $prefixBytes));
    }

    private static function unmapIpv4(string $packed): string
    {
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    // ------------------------------------------------------- access sessions

    /**
     * Access session behind a bearer token, or null when the token, its
     * device or its administrator is no longer valid.
     *
     * @return array<string, mixed>|null
     */
    public function accessSession(string $token): ?array
    {
        if (!$this->wellFormed($token, self::ACCESS_PREFIX)) {
            return null;
        }
        $row = App::db()->first(
            'SELECT u.*, s.id AS app_session_id, s.device_id AS app_device_id, s.expires_at AS app_session_expires_at, s.step_up_at AS app_step_up_at,
                    (dv.pin_hash IS NOT NULL) AS app_pin_set
               FROM admin_sessions s
               JOIN admin_app_devices dv ON dv.id = s.device_id
               JOIN users u ON u.id = s.user_id
               JOIN departments d ON d.id = u.department_id
              WHERE s.session_hash = ? AND s.channel = "app" AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP()
                AND dv.status = "active" AND dv.user_id = s.user_id AND dv.idle_expires_at > UTC_TIMESTAMP() AND dv.absolute_expires_at > UTC_TIMESTAMP()
                AND u.status = "active" AND u.role IN ("admin", "super_admin") AND d.status = "active"',
            [Crypto::hashKey($token)]
        );
        if ($row === null) {
            return null;
        }

        $session = [
            'session_id' => (int) $row['app_session_id'],
            'device_id' => (int) $row['app_device_id'],
            'expires_at' => (string) $row['app_session_expires_at'],
            'step_up_at' => $row['app_step_up_at'],
            'pin_set' => (int) $row['app_pin_set'] === 1,
        ];
        unset($row['app_session_id'], $row['app_device_id'], $row['app_session_expires_at'], $row['app_step_up_at'], $row['app_pin_set']);

        return ['user' => (new AuthService())->publicUser($row), 'session' => $session];
    }

    // ------------------------------------------------------ device management

    /** @return list<array<string, mixed>> */
    public function listDevices(int $userId, ?int $currentDeviceId = null): array
    {
        $rows = App::db()->select(
            'SELECT * FROM admin_app_devices WHERE user_id = ? AND status IN ("active", "pending") AND idle_expires_at > UTC_TIMESTAMP() AND absolute_expires_at > UTC_TIMESTAMP() ORDER BY COALESCE(last_seen_at, created_at) DESC, id DESC',
            [$userId]
        );

        return array_map(function (array $row) use ($currentDeviceId): array {
            $device = $this->publicDevice($row);
            $device['current'] = $currentDeviceId !== null && (int) $row['id'] === $currentDeviceId;
            $device['pending'] = ($row['status'] ?? '') === 'pending';
            if ($device['pending']) {
                $device['approval_code'] = (string) ($row['approval_code'] ?? '');
                $device['approval_expires_at'] = (string) $row['idle_expires_at'];
            }
            $device['risk'] = self::riskSignals($row);
            return $device;
        }, $rows);
    }

    /**
     * Device risk signals (APP-043): the device is now used from another
     * network (/16 for IPv4, /48 for IPv6) or by other client software than
     * when it was linked. Informational: shown next to the device.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function riskSignals(array $row): array
    {
        $signals = [];
        $first = (string) ($row['created_ip'] ?? '');
        $last = (string) ($row['last_ip'] ?? '');
        if ($first !== '' && $last !== '' && self::networkOf($first) !== self::networkOf($last)) {
            $signals[] = 'network_changed';
        }
        $linkedWith = self::clientFamily((string) ($row['user_agent'] ?? ''));
        $usedWith = self::clientFamily((string) ($row['last_user_agent'] ?? ''));
        if ($linkedWith !== '' && $usedWith !== '' && $linkedWith !== $usedWith) {
            $signals[] = 'client_changed';
        }

        return $signals;
    }

    private static function networkOf(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        return strlen($packed) === 4 ? bin2hex(substr($packed, 0, 2)) : bin2hex(substr($packed, 0, 6));
    }

    /** Operating system + browser family, ignoring versions. */
    private static function clientFamily(string $userAgent): string
    {
        if ($userAgent === '') {
            return '';
        }
        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $userAgent) => 'ios',
            str_contains($userAgent, 'Android') => 'android',
            (bool) preg_match('/Mac OS X|Macintosh/', $userAgent) => 'macos',
            str_contains($userAgent, 'Windows') => 'windows',
            str_contains($userAgent, 'Linux') => 'linux',
            default => 'other',
        };
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'edge',
            (bool) preg_match('/Chrome\/|CriOS\//', $userAgent) => 'chrome',
            (bool) preg_match('/Firefox\/|FxiOS\//', $userAgent) => 'firefox',
            str_contains($userAgent, 'Safari/') => 'safari',
            default => 'other',
        };

        return $os . '/' . $browser;
    }

    private function rememberClient(int $deviceId, string $userAgent): void
    {
        if ($userAgent !== '' && \TokenHub\Support\SchemaInspector::hasColumn('admin_app_devices', 'last_user_agent')) {
            App::db()->execute('UPDATE admin_app_devices SET last_user_agent = ? WHERE id = ?', [substr($userAgent, 0, 255), $deviceId]);
        }
    }

    /** @return array<string, mixed> */
    public function revokeOwnDevice(int $userId, int $deviceId): array
    {
        $row = App::db()->first('SELECT id FROM admin_app_devices WHERE id = ? AND user_id = ?', [$deviceId, $userId]);
        if ($row === null) {
            abort_json('ADMIN_NOT_FOUND', 'APP device not found', 404);
        }
        $revoked = $this->revokeDevice($deviceId, 'revoked_by_owner');
        if ($revoked) {
            (new AuditService())->write('admin.app_device.revoke', 'admin_app_devices', $deviceId);
        }

        return ['id' => $deviceId, 'revoked' => $revoked];
    }

    public function revokeDevice(int $deviceId, string $reason): bool
    {
        $changed = App::db()->execute(
            'UPDATE admin_app_devices SET status = "revoked", revoked_at = UTC_TIMESTAMP(), revoke_reason = ? WHERE id = ? AND status IN ("active", "pending")',
            [substr($reason, 0, 64), $deviceId]
        );
        App::db()->execute('UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE device_id = ? AND revoked_at IS NULL', [$deviceId]);
        if (\TokenHub\Support\SchemaInspector::hasColumn('admin_app_push_subscriptions', 'endpoint_hash')) {
            App::db()->execute('DELETE FROM admin_app_push_subscriptions WHERE device_id = ?', [$deviceId]);
        }
        if (\TokenHub\Support\SchemaInspector::hasColumn('admin_app_passkeys', 'credential_hash')) {
            App::db()->execute('DELETE FROM admin_app_passkeys WHERE device_id = ?', [$deviceId]);
        }

        return $changed > 0;
    }

    // ------------------------------------------------------- new-device approval

    /** True when new APP devices must be confirmed in the web console (APP_NEW_DEVICE_APPROVAL). */
    public function approvalRequired(): bool
    {
        return (bool) App::config()->get('app.app_client.new_device_approval', false)
            && \TokenHub\Support\SchemaInspector::hasColumn('admin_app_devices', 'approval_code');
    }

    /**
     * Refresh answer of a device that still waits for approval (no rotation,
     * no access). A device whose approval window passed is expired.
     *
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function pendingState(array $device): array
    {
        if ((int) ($device['live'] ?? 0) !== 1) {
            App::db()->execute('UPDATE admin_app_devices SET status = "expired", revoke_reason = "approval_expired", approval_code = NULL WHERE id = ? AND status = "pending"', [$device['id']]);
            abort_json('APP_DEVICE_APPROVAL_EXPIRED', 'This device was not approved in time; open a new sign-in link', 401);
        }

        return [
            'pending' => true,
            'match_code' => (string) ($device['approval_code'] ?? ''),
            'approval_expires_at' => (string) $device['idle_expires_at'],
            'device' => $this->publicDevice($device),
        ];
    }

    /**
     * Confirms a pending device of $userId (web console of the owner or of a
     * super administrator). From now on the device signs in like any other.
     *
     * @return array<string, mixed>
     */
    public function approveDevice(int $userId, int $deviceId, int $approverId): array
    {
        $row = App::db()->first(
            'SELECT dv.*, (dv.idle_expires_at > UTC_TIMESTAMP()) AS live FROM admin_app_devices dv WHERE dv.id = ? AND dv.user_id = ?',
            [$deviceId, $userId]
        );
        if ($row === null) {
            abort_json('ADMIN_NOT_FOUND', 'APP device not found', 404);
        }
        if (($row['status'] ?? '') !== 'pending') {
            abort_json('APP_DEVICE_NOT_PENDING', 'This device is not waiting for approval', 409);
        }
        if ((int) $row['live'] !== 1) {
            App::db()->execute('UPDATE admin_app_devices SET status = "expired", revoke_reason = "approval_expired", approval_code = NULL WHERE id = ? AND status = "pending"', [$deviceId]);
            abort_json('APP_DEVICE_APPROVAL_EXPIRED', 'The approval window of this device has passed; send a new sign-in link', 409);
        }
        if ($this->activeAdmin($userId) === null) {
            abort_json('APP_LINK_INVALID', 'This administrator cannot sign in', 409);
        }
        $idleDays = max(1, (int) $row['bound_over_http'] === 1 ? $this->setting('http_device_idle_days', 7) : $this->setting('device_idle_days', 30));
        $approved = App::db()->execute(
            'UPDATE admin_app_devices
                SET status = "active", approval_code = NULL, approved_at = UTC_TIMESTAMP(), approved_by = ?,
                    idle_expires_at = LEAST(DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), absolute_expires_at)
              WHERE id = ? AND user_id = ? AND status = "pending"',
            [$approverId, $idleDays, $deviceId, $userId]
        );
        if ($approved !== 1) {
            abort_json('APP_DEVICE_NOT_PENDING', 'This device is not waiting for approval', 409);
        }
        (new AuditService())->write('admin.app_device.approve', 'admin_app_devices', $deviceId, ['user_id' => $userId, 'by_other_admin' => $approverId !== $userId]);

        return ['id' => $deviceId, 'approved' => true];
    }

    /** @return array<string, mixed> */
    public function rejectDevice(int $userId, int $deviceId): array
    {
        $row = App::db()->first('SELECT id, status FROM admin_app_devices WHERE id = ? AND user_id = ?', [$deviceId, $userId]);
        if ($row === null) {
            abort_json('ADMIN_NOT_FOUND', 'APP device not found', 404);
        }
        if (($row['status'] ?? '') !== 'pending') {
            abort_json('APP_DEVICE_NOT_PENDING', 'This device is not waiting for approval', 409);
        }
        $rejected = $this->revokeDevice($deviceId, 'rejected');
        if ($rejected) {
            App::db()->execute('UPDATE admin_app_devices SET approval_code = NULL WHERE id = ?', [$deviceId]);
            (new AuditService())->write('admin.app_device.reject', 'admin_app_devices', $deviceId, ['user_id' => $userId]);
        }

        return ['id' => $deviceId, 'rejected' => $rejected];
    }

    // ------------------------------------------------------------ PIN / step-up

    /**
     * Sets (or changes) the device PIN. Changing an existing PIN needs the
     * current one; a wrong current PIN counts as a failed attempt. Setting the
     * PIN also counts as a fresh verification of the calling session.
     *
     * @return array<string, mixed>
     */
    public function setPin(int $deviceId, int $sessionId, string $pin, ?string $currentPin = null): array
    {
        if (preg_match('/^\d{6,12}$/', $pin) !== 1) {
            abort_json('APP_PIN_FORMAT', 'The PIN must be 6 to 12 digits', 422);
        }
        $device = $this->deviceRow($deviceId);
        if (!empty($device['pin_hash'])) {
            $check = $this->verifyPin($device, (string) $currentPin);
            if (!$check['ok']) {
                return $check;
            }
        }
        App::db()->execute('UPDATE admin_app_devices SET pin_hash = ?, pin_failures = 0 WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $deviceId]);
        App::db()->execute('UPDATE admin_sessions SET step_up_at = UTC_TIMESTAMP() WHERE id = ? AND device_id = ?', [$sessionId, $deviceId]);
        (new AuditService())->write(empty($device['pin_hash']) ? 'admin.app_device.pin_set' : 'admin.app_device.pin_change', 'admin_app_devices', $deviceId);

        return ['ok' => true, 'pin_set' => true, 'step_up_window_seconds' => (new AppStepUpPolicy())->windowSeconds()];
    }

    /**
     * Verifies the device PIN for the calling access session (step-up).
     *
     * @return array<string, mixed> ok, or ok=false with remaining attempts / revoked
     */
    public function stepUp(int $deviceId, int $sessionId, string $pin): array
    {
        $device = $this->deviceRow($deviceId);
        if (empty($device['pin_hash'])) {
            return ['ok' => false, 'code' => 'APP_PIN_NOT_SET', 'pin_set' => false];
        }
        $check = $this->verifyPin($device, $pin);
        if (!$check['ok']) {
            return $check;
        }
        App::db()->execute('UPDATE admin_sessions SET step_up_at = UTC_TIMESTAMP() WHERE id = ? AND device_id = ?', [$sessionId, $deviceId]);

        return ['ok' => true, 'step_up_window_seconds' => (new AppStepUpPolicy())->windowSeconds()];
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function verifyPin(array $device, string $pin): array
    {
        if ($pin !== '' && password_verify($pin, (string) $device['pin_hash'])) {
            App::db()->execute('UPDATE admin_app_devices SET pin_failures = 0 WHERE id = ?', [$device['id']]);
            return ['ok' => true];
        }
        App::db()->execute('UPDATE admin_app_devices SET pin_failures = pin_failures + 1 WHERE id = ?', [$device['id']]);
        $failures = (int) (App::db()->first('SELECT pin_failures FROM admin_app_devices WHERE id = ?', [$device['id']])['pin_failures'] ?? 0);
        $max = max(1, $this->setting('pin_max_failures', 5));
        if ($failures >= $max) {
            $this->revokeDevice((int) $device['id'], 'pin_failures');
            (new AuditService())->write('admin.app_device.pin_locked', 'admin_app_devices', $device['id'], ['failures' => $failures]);
            return ['ok' => false, 'code' => 'APP_PIN_LOCKED', 'revoked' => true, 'remaining' => 0];
        }

        return ['ok' => false, 'code' => 'APP_PIN_INVALID', 'remaining' => $max - $failures];
    }

    /** Revokes every APP device of an administrator (optionally keeping one). */
    public function revokeUserDevices(int $userId, string $reason, ?int $exceptDeviceId = null): int
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            App::db()->select('SELECT id FROM admin_app_devices WHERE user_id = ? AND status IN ("active", "pending")', [$userId])
        );
        $count = 0;
        foreach ($ids as $id) {
            if ($id !== $exceptDeviceId && $this->revokeDevice($id, $reason)) {
                $count++;
            }
        }
        // Outstanding sign-in links of the user die with the devices.
        if ($exceptDeviceId === null) {
            App::db()->execute('UPDATE admin_app_link_codes SET expires_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()', [$userId]);
        }

        return $count;
    }

    /**
     * Scheduler housekeeping: marks devices past their idle/absolute expiry,
     * revokes their sessions and drops old link codes.
     *
     * @return array<string, int>
     */
    public function cleanup(): array
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            App::db()->select('SELECT id FROM admin_app_devices WHERE status IN ("active", "pending") AND (idle_expires_at <= UTC_TIMESTAMP() OR absolute_expires_at <= UTC_TIMESTAMP()) LIMIT 500')
        );
        $clearCode = \TokenHub\Support\SchemaInspector::hasColumn('admin_app_devices', 'approval_code') ? ', approval_code = NULL' : '';
        foreach ($ids as $id) {
            App::db()->execute('UPDATE admin_app_devices SET revoke_reason = IF(status = "pending", "approval_expired", "expired")' . $clearCode . ', status = "expired" WHERE id = ? AND status IN ("active", "pending")', [$id]);
            App::db()->execute('UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE device_id = ? AND revoked_at IS NULL', [$id]);
        }
        $links = App::db()->execute('DELETE FROM admin_app_link_codes WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)');

        return ['devices_expired' => count($ids), 'link_codes_deleted' => $links];
    }

    // --------------------------------------------------------------- helpers

    /** True when the request reached TokenHub over HTTPS (directly or via a trusted proxy). */
    public static function secureRequest(Request $request): bool
    {
        if (strtolower((string) ($request->server['HTTPS'] ?? '')) === 'on'
            || strtolower((string) ($request->server['REQUEST_SCHEME'] ?? '')) === 'https'
            || (string) ($request->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return (new TrustedProxyService())->forwardedHttps($request);
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    public function publicDevice(array $device): array
    {
        return [
            'id' => (int) $device['id'],
            'name' => (string) $device['name'],
            'platform' => (string) $device['platform'],
            'client_type' => (string) $device['client_type'],
            'bound_over_http' => (int) ($device['bound_over_http'] ?? 0) === 1,
            'last_seen_at' => $device['last_seen_at'] ?? null,
            'last_ip' => $device['last_ip'] ?? null,
            'created_at' => $device['created_at'] ?? null,
            'idle_expires_at' => $device['idle_expires_at'] ?? null,
            'absolute_expires_at' => $device['absolute_expires_at'] ?? null,
            'pin_set' => !empty($device['pin_hash']),
            'status' => (string) ($device['status'] ?? 'active'),
        ];
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function refreshResult(array $device, ?string $refreshToken, string $clientIp, string $userAgent): array
    {
        $user = $this->activeAdmin((int) $device['user_id']);
        if ($user === null) {
            $this->revokeDevice((int) $device['id'], 'user_inactive');
            abort_json('APP_DEVICE_INVALID', 'This device is signed out; open a new sign-in link', 401);
        }

        return [
            'refresh_token' => $refreshToken,
            'access' => $this->issueAccess($device, $clientIp, $userAgent),
            'device' => $this->publicDevice($device),
            'user' => (new AuthService())->publicUser($user),
            'insecure_transport' => (int) $device['bound_over_http'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $device
     */
    private function assertDeviceUsable(array $device): void
    {
        if (($device['status'] ?? '') !== 'active') {
            abort_json('APP_DEVICE_INVALID', 'This device is signed out; open a new sign-in link', 401);
        }
        if ((int) ($device['live'] ?? 0) !== 1) {
            App::db()->execute('UPDATE admin_app_devices SET status = "expired", revoke_reason = "expired" WHERE id = ? AND status = "active"', [$device['id']]);
            App::db()->execute('UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE device_id = ? AND revoked_at IS NULL', [$device['id']]);
            abort_json('APP_DEVICE_EXPIRED', 'This device has not been used for too long; open a new sign-in link', 401);
        }
        if ($this->activeAdmin((int) $device['user_id']) === null) {
            $this->revokeDevice((int) $device['id'], 'user_inactive');
            abort_json('APP_DEVICE_INVALID', 'This device is signed out; open a new sign-in link', 401);
        }
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function issueAccess(array $device, string $clientIp, string $userAgent): array
    {
        $ttl = max(5, $this->setting('access_ttl_minutes', 60));
        $token = Crypto::token(self::ACCESS_PREFIX);
        App::db()->execute(
            'INSERT INTO admin_sessions (user_id, session_hash, csrf_token_hash, client_ip, user_agent, channel, device_id, expires_at) VALUES (?, ?, ?, ?, ?, "app", ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))',
            [$device['user_id'], Crypto::hashKey($token), Crypto::hashKey(Crypto::token('csrf_')), $this->cleanText($clientIp, 64), substr($userAgent, 0, 255), $device['id'], $ttl]
        );
        $row = App::db()->first('SELECT expires_at FROM admin_sessions WHERE session_hash = ?', [Crypto::hashKey($token)]);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl * 60,
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'server_time' => $this->now(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function deviceByCredential(string $column, string $hash): ?array
    {
        $column = $column === 'prev_refresh_hash' ? 'prev_refresh_hash' : 'refresh_hash';

        return App::db()->first(
            "SELECT dv.*, (dv.idle_expires_at > UTC_TIMESTAMP() AND dv.absolute_expires_at > UTC_TIMESTAMP()) AS live,
                    (dv.prev_valid_until IS NOT NULL AND dv.prev_valid_until > UTC_TIMESTAMP()) AS prev_live
               FROM admin_app_devices dv WHERE dv.{$column} = ?",
            [$hash]
        );
    }

    /** @return array<string, mixed> */
    private function deviceRow(int $id): array
    {
        $row = App::db()->first(
            'SELECT dv.*, (dv.idle_expires_at > UTC_TIMESTAMP() AND dv.absolute_expires_at > UTC_TIMESTAMP()) AS live FROM admin_app_devices dv WHERE dv.id = ?',
            [$id]
        );
        if ($row === null) {
            abort_json('APP_DEVICE_INVALID', 'This device is signed out; open a new sign-in link', 401);
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function activeAdmin(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return App::db()->first(
            'SELECT u.* FROM users u JOIN departments d ON d.id = u.department_id WHERE u.id = ? AND u.status = "active" AND u.role IN ("admin", "super_admin") AND d.status = "active"',
            [$userId]
        );
    }

    private function assertDeviceSlotAvailable(int $userId): void
    {
        if ($this->activeDeviceCount($userId) >= $this->maxDevices()) {
            abort_json('APP_DEVICE_LIMIT', 'Too many APP devices are linked to this account; revoke one first', 409);
        }
    }

    private function activeDeviceCount(int $userId): int
    {
        $row = App::db()->first(
            'SELECT COUNT(*) AS total FROM admin_app_devices WHERE user_id = ? AND status IN ("active", "pending") AND idle_expires_at > UTC_TIMESTAMP() AND absolute_expires_at > UTC_TIMESTAMP()',
            [$userId]
        );

        return (int) ($row['total'] ?? 0);
    }

    private function maxDevices(): int
    {
        return max(1, $this->setting('max_devices_per_user', 5));
    }

    private function setting(string $key, int $default): int
    {
        return (int) App::config()->get('app.app_client.' . $key, $default);
    }

    private function wellFormed(string $token, string $prefix): bool
    {
        return preg_match('/^' . preg_quote($prefix, '/') . '[0-9a-f]{64}$/', $token) === 1;
    }

    /**
     * @param list<mixed> $cidrs
     */
    private function ipAllowed(string $ip, array $cidrs): bool
    {
        $policy = new OutboundUrlPolicy();
        foreach ($cidrs as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr !== '' && $policy->inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function defaultDeviceName(string $platform): string
    {
        return match ($platform) {
            'ios' => 'iPhone / iPad',
            'android' => 'Android',
            'macos' => 'Mac',
            'windows' => 'Windows',
            'linux' => 'Linux',
            default => 'TokenHub APP',
        };
    }

    private function cleanText(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, $max);
    }

    private function now(): string
    {
        return (string) (App::db()->first('SELECT UTC_TIMESTAMP() AS now')['now'] ?? date('Y-m-d H:i:s'));
    }
}
