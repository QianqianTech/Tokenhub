<?php

declare(strict_types=1);

namespace TokenHub\Support;

use RuntimeException;

/**
 * Symmetric encryption and keyed hashing for stored secrets.
 *
 * Key material
 * - APP_KEY            primary encryption key (required in production).
 * - APP_KEY_ID         identifier written into new ciphertexts (default "k1").
 * - APP_PREVIOUS_KEYS  comma separated "id:key" pairs still accepted for
 *                      decryption during a rotation, e.g. "k0:old-secret".
 * - APP_HASH_KEY       optional pinned key for keyed hashes (API keys, admin
 *                      sessions). Defaults to APP_KEY for backward
 *                      compatibility. Pin it to the old APP_KEY before rotating
 *                      APP_KEY so stored API key hashes stay valid.
 *
 * Key separation (SEC-112)
 * - New ciphertexts use an encryption key derived with HKDF-SHA256 from the
 *   key material (info "tokenhub:encryption"), so the AES key is never the
 *   key used for HMACs.
 * - hashKey() keeps its historical key (sha256 of APP_HASH_KEY / APP_KEY):
 *   stored API key and session hashes are deterministic lookups and must keep
 *   matching. New keyed MACs use hmac($purpose, ...) with their own HKDF key.
 *
 * Ciphertext formats
 * - "v3:{kid}:{base64(iv|tag|cipher)}"  current, HKDF-derived key, key id explicit.
 * - "v2:{kid}:{base64(iv|tag|cipher)}"  previous, sha256(key) as AES key; still decrypted.
 * - "{base64(iv|tag|cipher)}"           legacy, tried against every known key
 *                                       (the development key only outside production).
 */
final class Crypto
{
    private const DEVELOPMENT_KEY = 'development-tokenhub-key-change-me';
    private const PREFIX = 'v3:';
    private const LEGACY_PREFIX = 'v2:';
    private const ENCRYPTION_INFO = 'tokenhub:encryption';

    public static function encrypt(string $plainText): string
    {
        [$keyId, $raw] = self::primaryRaw();
        $key = self::deriveKey($raw, self::ENCRYPTION_INFO);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }

        return self::PREFIX . $keyId . ':' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        [$keyId, $payload, $version] = self::split($encoded);
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        foreach (self::candidateKeys($keyId, $version) as $key) {
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain !== false) {
                return $plain;
            }
        }

        return '';
    }

    /**
     * True when the value was not written with the current primary key and
     * should be re-encrypted by the rotation script.
     */
    public static function needsReencryption(string $encoded): bool
    {
        if ($encoded === '') {
            return false;
        }
        [$keyId, , $version] = self::split($encoded);

        // Rewritten when under an older key id or in a pre-HKDF format (v2, legacy).
        return $keyId !== self::primaryKeyId() || $version !== 3;
    }

    public static function keyIdOf(string $encoded): ?string
    {
        return self::split($encoded)[0];
    }

    public static function hashKey(string $plainText): string
    {
        return hash_hmac('sha256', $plainText, self::hashSecret());
    }

    /**
     * Hashes of the value under the pinned hash key and every previous key.
     * Used by lookups that must keep working across an APP_KEY rotation.
     *
     * @return list<string>
     */
    public static function hashKeyCandidates(string $plainText): array
    {
        $hashes = [self::hashKey($plainText)];
        foreach (self::previousRawKeys() as $raw) {
            $hashes[] = hash_hmac('sha256', $plainText, hash('sha256', $raw, true));
        }

        return array_values(array_unique($hashes));
    }

    /**
     * Keyed MAC under a purpose-specific key derived with HKDF from the
     * primary key material (SEC-112), e.g. hmac('task-callback', ...).
     */
    public static function hmac(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $data, self::derivedKey($purpose));
    }

    /**
     * 32-byte key for one purpose, derived with HKDF-SHA256 from APP_KEY.
     */
    public static function derivedKey(string $purpose): string
    {
        return self::deriveKey(self::primaryRaw()[1], 'tokenhub:' . $purpose);
    }

    public static function token(string $prefix = ''): string
    {
        return $prefix . bin2hex(random_bytes(32));
    }

    /**
     * Throws when production would silently run on a missing or development
     * key. Called during bootstrap so misconfiguration fails at start-up
     * instead of encrypting customer secrets with a public default.
     */
    public static function assertProductionReady(?string $environment = null): void
    {
        if (!self::isProduction($environment)) {
            return;
        }

        $problem = self::keyProblem((string) env_value('APP_KEY', ''));
        if ($problem !== null) {
            throw new RuntimeException('APP_KEY is not production ready: ' . $problem);
        }
    }

    public static function keyProblem(string $appKey): ?string
    {
        if ($appKey === '') {
            return 'missing';
        }
        if ($appKey === self::DEVELOPMENT_KEY || str_contains(strtolower($appKey), 'change-me') || str_contains(strtolower($appKey), 'changeme')) {
            return 'development default';
        }
        if (strlen($appKey) < 32) {
            return 'shorter than 32 characters';
        }

        return null;
    }

    /**
     * Only an explicitly configured production environment is enforced, so
     * local tooling and tests that never set APP_ENV keep working.
     */
    public static function isProduction(?string $environment = null): bool
    {
        $environment ??= (string) env_value('APP_ENV', '');

        return in_array(strtolower(trim($environment)), ['production', 'prod'], true);
    }

    /**
     * Primary key id and the legacy (sha256) form of its key.
     *
     * @return array{0: string, 1: string}
     */
    private static function primary(): array
    {
        [$id, $raw] = self::primaryRaw();

        return [$id, hash('sha256', $raw, true)];
    }

    /**
     * Primary key id and raw key material.
     *
     * @return array{0: string, 1: string}
     */
    private static function primaryRaw(): array
    {
        $raw = (string) env_value('APP_KEY', '');
        if ($raw === '') {
            if (self::isProduction()) {
                throw new RuntimeException('APP_KEY is required in production');
            }
            $raw = self::DEVELOPMENT_KEY;
        }

        return [self::primaryKeyId(), $raw];
    }

    private static function deriveKey(string $raw, string $info): string
    {
        return hash_hkdf('sha256', $raw, 32, $info, 'tokenhub-hkdf-v1');
    }

    private static function primaryKeyId(): string
    {
        $id = trim((string) env_value('APP_KEY_ID', 'k1'));

        return preg_match('/^[A-Za-z0-9_-]{1,16}$/', $id) === 1 ? $id : 'k1';
    }

    private static function hashSecret(): string
    {
        $pinned = (string) env_value('APP_HASH_KEY', '');
        if ($pinned !== '') {
            return hash('sha256', $pinned, true);
        }

        return self::primary()[1];
    }

    /**
     * @return array{0: ?string, 1: string, 2: int} key id, payload, format version (3, 2 or 1 = legacy)
     */
    private static function split(string $encoded): array
    {
        foreach ([self::PREFIX => 3, self::LEGACY_PREFIX => 2] as $prefix => $version) {
            if (str_starts_with($encoded, $prefix)) {
                $parts = explode(':', $encoded, 3);
                if (count($parts) === 3) {
                    return [$parts[1], $parts[2], $version];
                }
            }
        }

        return [null, $encoded, 1];
    }

    /**
     * AES keys to try for a ciphertext of the given key id and format.
     *
     * @return list<string>
     */
    private static function candidateKeys(?string $keyId, int $version): array
    {
        [$primaryId, $primaryRaw] = self::primaryRaw();
        $raws = [$primaryId => $primaryRaw];
        foreach (self::previousKeys() as $id => $raw) {
            $raws[$id] ??= $raw;
        }
        $derive = static fn (string $raw): string => $version === 3
            ? self::deriveKey($raw, self::ENCRYPTION_INFO)
            : hash('sha256', $raw, true);

        if ($keyId !== null) {
            return isset($raws[$keyId]) ? [$derive($raws[$keyId])] : [];
        }

        $keys = array_map($derive, array_values($raws));
        if (!self::isProduction()) {
            // Values written by an unconfigured development install; never tried in production.
            $keys[] = $derive(self::DEVELOPMENT_KEY);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, string>
     */
    private static function previousKeys(): array
    {
        $configured = (string) env_value('APP_PREVIOUS_KEYS', '');
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $configured))) as $index => $entry) {
            if (str_contains($entry, ':')) {
                [$id, $raw] = explode(':', $entry, 2);
            } else {
                [$id, $raw] = ['prev' . $index, $entry];
            }
            if ($raw !== '') {
                $keys[trim($id)] = $raw;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function previousRawKeys(): array
    {
        return array_values(self::previousKeys());
    }
}
