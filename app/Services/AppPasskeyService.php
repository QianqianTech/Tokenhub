<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Request;

/**
 * Passkeys (WebAuthn) as a step-up method of a TokenHub APP device, next to the
 * PIN. Each passkey is bound to one APP device; using it marks the calling
 * access session as recently verified. Needs a secure context and a host name:
 * WebAuthn does not work on IP addresses, so https://IP deployments use the PIN.
 */
final class AppPasskeyService
{
    private const CHALLENGE_SECONDS = 300;

    public function available(Request $request): bool
    {
        return $this->secureContext($request) && WebAuthnVerifier::rpIdFor($this->host($request)) !== null && $this->ready();
    }

    /** HTTPS, or http://localhost (a secure context for browsers, used in development). */
    private function secureContext(Request $request): bool
    {
        return AppDeviceAuthService::secureRequest($request) || $this->host($request) === 'localhost';
    }

    public function ready(): bool
    {
        return \TokenHub\Support\SchemaInspector::hasColumn('admin_app_passkeys', 'credential_hash');
    }

    public function count(int $deviceId): int
    {
        if (!$this->ready()) {
            return 0;
        }

        return (int) (App::db()->first('SELECT COUNT(*) AS total FROM admin_app_passkeys WHERE device_id = ?', [$deviceId])['total'] ?? 0);
    }

    /**
     * PublicKeyCredentialCreationOptions / RequestOptions (base64url binary fields).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function options(Request $request, int $deviceId, array $user, string $purpose): array
    {
        $rpId = $this->rpId($request);
        $challenge = WebAuthnVerifier::b64(random_bytes(32));
        App::db()->execute(
            'UPDATE admin_app_devices SET webauthn_challenge = ?, webauthn_challenge_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id = ?',
            [$challenge, self::CHALLENGE_SECONDS, $deviceId]
        );
        $existing = array_map(
            static fn (array $row): array => ['type' => 'public-key', 'id' => (string) $row['credential_id']],
            App::db()->select('SELECT credential_id FROM admin_app_passkeys WHERE device_id = ?', [$deviceId])
        );
        if ($purpose === 'register') {
            return [
                'challenge' => $challenge,
                'rp' => ['name' => 'TokenHub', 'id' => $rpId],
                'user' => [
                    'id' => WebAuthnVerifier::b64(hash('sha256', 'tokenhub-admin:' . (int) $user['id'] . ':' . Crypto::hashKey((string) $user['id']), true)),
                    'name' => (string) ($user['username'] ?? 'admin'),
                    'displayName' => (string) ($user['display_name'] ?? $user['username'] ?? 'admin'),
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => WebAuthnVerifier::ALG_ES256],
                    ['type' => 'public-key', 'alg' => WebAuthnVerifier::ALG_RS256],
                ],
                'authenticatorSelection' => ['userVerification' => 'required', 'residentKey' => 'discouraged'],
                'attestation' => 'none',
                'timeout' => 60000,
                'excludeCredentials' => $existing,
            ];
        }
        if ($existing === []) {
            abort_json('APP_PASSKEY_NOT_SET', 'No passkey is registered on this device', 409);
        }

        return [
            'challenge' => $challenge,
            'rpId' => $rpId,
            'allowCredentials' => $existing,
            'userVerification' => 'required',
            'timeout' => 60000,
        ];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    public function register(Request $request, int $deviceId, int $userId, int $sessionId, array $credential, string $name = ''): array
    {
        $challenge = $this->takeChallenge($deviceId);
        try {
            $verified = WebAuthnVerifier::verifyRegistration($credential, $challenge, $this->origin($request), $this->rpId($request));
        } catch (Throwable $throwable) {
            HttpAbortException::rethrowIfAbort($throwable);
            abort_json('APP_PASSKEY_INVALID', 'The passkey could not be verified', 422);
        }
        $hash = hash('sha256', $verified['credential_id']);
        if (App::db()->first('SELECT id FROM admin_app_passkeys WHERE credential_hash = ?', [$hash]) !== null) {
            abort_json('APP_PASSKEY_INVALID', 'This passkey is already registered', 409);
        }
        App::db()->execute(
            'INSERT INTO admin_app_passkeys (user_id, device_id, credential_id, credential_hash, public_key_pem, algorithm, sign_count, name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $deviceId, $verified['credential_id'], $hash, $verified['public_key_pem'], $verified['algorithm'], $verified['sign_count'], mb_substr(trim($name), 0, 100) ?: null]
        );
        App::db()->execute('UPDATE admin_sessions SET step_up_at = UTC_TIMESTAMP() WHERE id = ? AND device_id = ?', [$sessionId, $deviceId]);
        (new AuditService())->write('admin.app_device.passkey_add', 'admin_app_devices', $deviceId, ['algorithm' => $verified['algorithm']]);

        return ['registered' => true, 'passkeys' => $this->count($deviceId)];
    }

    /**
     * Step-up with a passkey.
     *
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    public function verify(Request $request, int $deviceId, int $sessionId, array $credential): array
    {
        $challenge = $this->takeChallenge($deviceId);
        $rawId = WebAuthnVerifier::unb64((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
        $passkey = App::db()->first(
            'SELECT * FROM admin_app_passkeys WHERE credential_hash = ? AND device_id = ?',
            [hash('sha256', WebAuthnVerifier::b64($rawId)), $deviceId]
        );
        if ($passkey === null) {
            abort_json('APP_PASSKEY_INVALID', 'This passkey is not registered on this device', 403);
        }
        try {
            $count = WebAuthnVerifier::verifyAssertion($credential, $challenge, $this->origin($request), $this->rpId($request), (string) $passkey['public_key_pem'], (int) $passkey['sign_count']);
        } catch (Throwable $throwable) {
            HttpAbortException::rethrowIfAbort($throwable);
            (new AuditService())->write('admin.app_device.passkey_failed', 'admin_app_devices', $deviceId, ['reason' => substr($throwable->getMessage(), 0, 120)]);
            abort_json('APP_PASSKEY_INVALID', 'The passkey could not be verified', 403);
        }
        App::db()->execute('UPDATE admin_app_passkeys SET sign_count = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?', [$count, $passkey['id']]);
        App::db()->execute('UPDATE admin_sessions SET step_up_at = UTC_TIMESTAMP() WHERE id = ? AND device_id = ?', [$sessionId, $deviceId]);

        return ['ok' => true, 'step_up_window_seconds' => (new AppStepUpPolicy())->windowSeconds()];
    }

    public function removeAll(int $deviceId): int
    {
        $removed = $this->ready() ? App::db()->execute('DELETE FROM admin_app_passkeys WHERE device_id = ?', [$deviceId]) : 0;
        if ($removed > 0) {
            (new AuditService())->write('admin.app_device.passkey_remove', 'admin_app_devices', $deviceId, ['removed' => $removed]);
        }

        return $removed;
    }

    private function takeChallenge(int $deviceId): string
    {
        $row = App::db()->first(
            'SELECT webauthn_challenge, (webauthn_challenge_expires_at > UTC_TIMESTAMP()) AS live FROM admin_app_devices WHERE id = ?',
            [$deviceId]
        );
        // One use only, whatever the outcome.
        App::db()->execute('UPDATE admin_app_devices SET webauthn_challenge = NULL, webauthn_challenge_expires_at = NULL WHERE id = ?', [$deviceId]);
        if ($row === null || (string) ($row['webauthn_challenge'] ?? '') === '' || (int) ($row['live'] ?? 0) !== 1) {
            abort_json('APP_PASSKEY_CHALLENGE', 'The passkey request expired; try again', 409);
        }

        return (string) $row['webauthn_challenge'];
    }

    private function rpId(Request $request): string
    {
        $rpId = WebAuthnVerifier::rpIdFor($this->host($request));
        if ($rpId === null || !$this->secureContext($request)) {
            abort_json('APP_PASSKEY_UNAVAILABLE', 'Passkeys need HTTPS and a domain name; use the PIN', 409);
        }

        return $rpId;
    }

    private function origin(Request $request): string
    {
        $host = trim((string) $request->header('host', ''));

        return (AppDeviceAuthService::secureRequest($request) ? 'https' : 'http') . '://' . strtolower($host);
    }

    private function host(Request $request): string
    {
        $host = trim((string) $request->header('host', ''));
        if (str_starts_with($host, '[')) {
            return substr($host, 0, (int) strpos($host, ']') + 1);
        }

        return explode(':', $host)[0];
    }
}
