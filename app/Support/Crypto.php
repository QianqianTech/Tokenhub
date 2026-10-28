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
 * Ciphertext formats
 * - "v2:{kid}:{base64(iv|tag|cipher)}"  current, key id is explicit.
 * - "{base64(iv|tag|cipher)}"           legacy, tried against every known key.
 */
final class Crypto
{
    private const DEVELOPMENT_KEY = 'development-tokenhub-key-change-me';
    private const PREFIX = 'v2:';

    public static function encrypt(string $plainText): string
    {
        [$keyId, $key] = self::primary();
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

        [$keyId, $payload] = self::split($encoded);
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        foreach (self::candidateKeys($keyId) as $key) {
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
        [$keyId] = self::split($encoded);

        return $keyId !== self::primary()[0];
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
     * @return array{0: string, 1: string}
     */
    private static function primary(): array
    {
        $raw = (string) env_value('APP_KEY', '');
        if ($raw === '') {
            if (self::isProduction()) {
                throw new RuntimeException('APP_KEY is required in production');
            }
            $raw = self::DEVELOPMENT_KEY;
        }

        return [self::primaryKeyId(), hash('sha256', $raw, true)];
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
     * @return array{0: ?string, 1: string}
     */
    private static function split(string $encoded): array
    {
        if (str_starts_with($encoded, self::PREFIX)) {
            $parts = explode(':', $encoded, 3);
            if (count($parts) === 3) {
                return [$parts[1], $parts[2]];
            }
        }

        return [null, $encoded];
    }

    /**
     * @return list<string>
     */
    private static function candidateKeys(?string $keyId): array
    {
        [$primaryId, $primaryKey] = self::primary();
        $keys = [$primaryId => $primaryKey];
        foreach (self::previousKeys() as $id => $raw) {
            $keys[$id] ??= hash('sha256', $raw, true);
        }

        if ($keyId !== null) {
            return isset($keys[$keyId]) ? [$keys[$keyId]] : [];
        }

        $keys[] = hash('sha256', self::DEVELOPMENT_KEY, true);

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
