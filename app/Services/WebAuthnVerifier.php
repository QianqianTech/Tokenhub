<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;

/**
 * Minimal WebAuthn (passkey) verification for TokenHub APP step-up:
 * registration with attestation "none" (the attestation statement is not
 * evaluated; the credential is bound to an already signed-in APP device) and
 * assertions with ES256 or RS256. User verification (biometrics / device PIN)
 * is required in both ceremonies.
 */
final class WebAuthnVerifier
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    /**
     * @param array<string, mixed> $credential PublicKeyCredential as JSON (base64url fields)
     * @return array{credential_id: string, public_key_pem: string, algorithm: int, sign_count: int}
     */
    public static function verifyRegistration(array $credential, string $challenge, string $origin, string $rpId): array
    {
        $clientDataJson = self::unb64((string) ($credential['response']['clientDataJSON'] ?? ''));
        self::checkClientData($clientDataJson, 'webauthn.create', $challenge, $origin);

        $attestation = self::cbor(self::unb64((string) ($credential['response']['attestationObject'] ?? '')));
        if (!is_array($attestation) || !isset($attestation['authData']) || !is_string($attestation['authData'])) {
            throw new RuntimeException('Invalid attestation object');
        }
        $auth = self::authData($attestation['authData'], $rpId, true);
        if ($auth['credential_id'] === '' || $auth['credential_public_key'] === null) {
            throw new RuntimeException('The authenticator returned no credential');
        }
        $rawId = self::unb64((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
        if (!hash_equals($auth['credential_id'], $rawId)) {
            throw new RuntimeException('Credential id mismatch');
        }
        [$pem, $algorithm] = self::coseToPem($auth['credential_public_key']);

        return [
            'credential_id' => self::b64($auth['credential_id']),
            'public_key_pem' => $pem,
            'algorithm' => $algorithm,
            'sign_count' => $auth['sign_count'],
        ];
    }

    /**
     * Verifies an assertion and returns the authenticator's new signature counter.
     *
     * @param array<string, mixed> $credential
     */
    public static function verifyAssertion(array $credential, string $challenge, string $origin, string $rpId, string $publicKeyPem, int $storedSignCount): int
    {
        $clientDataJson = self::unb64((string) ($credential['response']['clientDataJSON'] ?? ''));
        self::checkClientData($clientDataJson, 'webauthn.get', $challenge, $origin);
        $authData = self::unb64((string) ($credential['response']['authenticatorData'] ?? ''));
        $auth = self::authData($authData, $rpId, false);
        $signature = self::unb64((string) ($credential['response']['signature'] ?? ''));
        $verified = openssl_verify($authData . hash('sha256', $clientDataJson, true), $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('Invalid passkey signature');
        }
        // A counter that does not grow means a cloned authenticator (counters of 0 are allowed: many passkeys never count).
        if ($auth['sign_count'] !== 0 && $auth['sign_count'] <= $storedSignCount) {
            throw new RuntimeException('Passkey signature counter went backwards');
        }

        return $auth['sign_count'];
    }

    /** Relying party id for a host: WebAuthn does not accept IP addresses. */
    public static function rpIdFor(string $host): ?string
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $host;
    }

    private static function checkClientData(string $json, string $type, string $challenge, string $origin): void
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['type'] ?? '') !== $type) {
            throw new RuntimeException('Unexpected client data type');
        }
        if (!hash_equals($challenge, (string) ($data['challenge'] ?? ''))) {
            throw new RuntimeException('Challenge mismatch');
        }
        if (!hash_equals(rtrim($origin, '/'), (string) ($data['origin'] ?? ''))) {
            throw new RuntimeException('Origin mismatch');
        }
        if (($data['crossOrigin'] ?? false) === true) {
            throw new RuntimeException('Cross-origin ceremonies are not accepted');
        }
    }

    /**
     * @return array{sign_count: int, credential_id: string, credential_public_key: mixed}
     */
    private static function authData(string $data, string $rpId, bool $expectCredential): array
    {
        if (strlen($data) < 37) {
            throw new RuntimeException('Authenticator data too short');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($data, 0, 32))) {
            throw new RuntimeException('Relying party mismatch');
        }
        $flags = ord($data[32]);
        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
            throw new RuntimeException('User presence and verification are required');
        }
        $signCount = unpack('N', substr($data, 33, 4))[1];
        $credentialId = '';
        $publicKey = null;
        if ($expectCredential) {
            if (($flags & 0x40) === 0 || strlen($data) < 55) {
                throw new RuntimeException('No attested credential data');
            }
            $length = unpack('n', substr($data, 53, 2))[1];
            $credentialId = substr($data, 55, $length);
            $offset = 55 + $length;
            $publicKey = self::cbor(substr($data, $offset));
        }

        return ['sign_count' => (int) $signCount, 'credential_id' => $credentialId, 'credential_public_key' => $publicKey];
    }

    /**
     * @param mixed $cose
     * @return array{0: string, 1: int}
     */
    private static function coseToPem($cose): array
    {
        if (!is_array($cose)) {
            throw new RuntimeException('Invalid credential public key');
        }
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === self::ALG_ES256 && ($cose[-1] ?? null) === 1) {
            $x = (string) ($cose[-2] ?? '');
            $y = (string) ($cose[-3] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new RuntimeException('Invalid P-256 key');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;

            return [self::pem($der), self::ALG_ES256];
        }
        if ($kty === 3 && $alg === self::ALG_RS256) {
            $n = (string) ($cose[-1] ?? '');
            $e = (string) ($cose[-2] ?? '');
            if (strlen($n) < 256 || $e === '') {
                throw new RuntimeException('RSA keys must be at least 2048 bits');
            }
            $rsa = self::derSequence(self::derInteger($n) . self::derInteger($e));
            $algorithm = hex2bin('300d06092a864886f70d0101010500');
            $der = self::derSequence($algorithm . "\x03" . self::derLength(strlen($rsa) + 1) . "\x00" . $rsa);

            return [self::pem($der), self::ALG_RS256];
        }

        throw new RuntimeException('Unsupported passkey algorithm');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    // ------------------------------------------------------------------ CBOR

    /** Decodes the first CBOR item of $data (maps keep integer and string keys). */
    public static function cbor(string $data): mixed
    {
        $offset = 0;

        return self::cborItem($data, $offset);
    }

    private static function cborItem(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('Truncated CBOR');
        }
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1f;
        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new RuntimeException('Unsupported CBOR simple value'),
            };
        }
        $value = self::cborArgument($data, $offset, $info);

        switch ($major) {
            case 0:
                return $value;
            case 1:
                return -1 - $value;
            case 2:
            case 3:
                $bytes = substr($data, $offset, $value);
                if (strlen($bytes) !== $value) {
                    throw new RuntimeException('Truncated CBOR string');
                }
                $offset += $value;
                return $bytes;
            case 4:
                $items = [];
                for ($i = 0; $i < $value; $i++) {
                    $items[] = self::cborItem($data, $offset);
                }
                return $items;
            case 5:
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $key = self::cborItem($data, $offset);
                    $map[is_int($key) ? $key : (string) $key] = self::cborItem($data, $offset);
                }
                return $map;
            default:
                throw new RuntimeException('Unsupported CBOR type');
        }
    }

    private static function cborArgument(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $length = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new RuntimeException('Unsupported CBOR length'),
        };
        $bytes = substr($data, $offset, $length);
        if (strlen($bytes) !== $length) {
            throw new RuntimeException('Truncated CBOR');
        }
        $offset += $length;
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
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
