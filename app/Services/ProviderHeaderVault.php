<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\Crypto;

/**
 * Keeps credential-bearing provider headers encrypted at rest and masked on read.
 *
 * providers.default_headers is a plain JSON column used both for routing hints
 * (tokenhub_* control keys) and for upstream HTTP headers. Some vendors put the
 * credential in a header (x-api-key, api-key, x-goog-api-key, Authorization),
 * so those values are stored as "enc:{ciphertext}", returned to the admin UI as
 * MASK, and only decrypted inside ProviderHttpClient right before sending.
 *
 * Saving MASK (or omitting the header) keeps the stored ciphertext; saving an
 * empty string removes the header.
 */
final class ProviderHeaderVault
{
    public const MASK = '****';
    private const ENCRYPTED_PREFIX = 'enc:';

    private const SENSITIVE_NAMES = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'x-goog-api-key',
        'cookie',
        'x-auth-token',
        'ocp-apim-subscription-key',
    ];

    public function isSensitive(string $name): bool
    {
        $key = strtolower(trim($name));
        if ($key === '' || str_starts_with($key, 'tokenhub_')) {
            return false;
        }
        if (in_array($key, self::SENSITIVE_NAMES, true)) {
            return true;
        }

        return preg_match('/(secret|token|password|api[-_]?key|access[-_]?key|credential)/', $key) === 1;
    }

    /**
     * @param array<string, mixed>|string|null $incoming headers submitted by an admin
     * @param array<string, mixed>|string|null $stored headers currently in the database
     * @return array<string, mixed>
     */
    public function protectForStorage(array|string|null $incoming, array|string|null $stored = null): array
    {
        $incoming = $this->decode($incoming);
        $stored = $this->decode($stored);
        $storedByLower = [];
        foreach ($stored as $name => $value) {
            $storedByLower[strtolower((string) $name)] = $value;
        }

        $result = [];
        foreach ($incoming as $name => $value) {
            $name = (string) $name;
            if (!$this->isSensitive($name)) {
                $result[$name] = $value;
                continue;
            }
            if (!is_scalar($value) || (string) $value === '') {
                continue;
            }
            $value = (string) $value;
            if ($value === self::MASK || str_starts_with($value, self::ENCRYPTED_PREFIX)) {
                $previous = $storedByLower[strtolower($name)] ?? null;
                if (is_string($previous) && $previous !== '') {
                    $result[$name] = str_starts_with($previous, self::ENCRYPTED_PREFIX)
                        ? $previous
                        : self::ENCRYPTED_PREFIX . Crypto::encrypt($previous);
                }
                continue;
            }
            $result[$name] = self::ENCRYPTED_PREFIX . Crypto::encrypt($value);
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|string|null $stored
     * @return array<string, mixed>
     */
    public function maskForDisplay(array|string|null $stored): array
    {
        $masked = [];
        foreach ($this->decode($stored) as $name => $value) {
            $masked[(string) $name] = $this->isSensitive((string) $name) && $value !== null && $value !== '' ? self::MASK : $value;
        }

        return $masked;
    }

    /**
     * @param array<string, mixed>|string|null $stored
     * @return array<string, mixed>
     */
    public function revealForUse(array|string|null $stored): array
    {
        $headers = [];
        foreach ($this->decode($stored) as $name => $value) {
            if (is_string($value) && str_starts_with($value, self::ENCRYPTED_PREFIX)) {
                $plain = Crypto::decrypt(substr($value, strlen(self::ENCRYPTED_PREFIX)));
                if ($plain === '') {
                    continue;
                }
                $value = $plain;
            }
            $headers[(string) $name] = $value;
        }

        return $headers;
    }

    /**
     * Names of sensitive headers still stored in plaintext (legacy rows).
     *
     * @param array<string, mixed>|string|null $stored
     * @return list<string>
     */
    public function plaintextSensitiveHeaders(array|string|null $stored): array
    {
        $names = [];
        foreach ($this->decode($stored) as $name => $value) {
            if ($this->isSensitive((string) $name) && is_string($value) && $value !== '' && !str_starts_with($value, self::ENCRYPTED_PREFIX)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed>|string|null $headers
     * @return array<string, mixed>
     */
    public function decode(array|string|null $headers): array
    {
        if (is_array($headers)) {
            return $headers;
        }
        if (!is_string($headers) || trim($headers) === '') {
            return [];
        }
        $decoded = json_decode($headers, true);

        return is_array($decoded) ? $decoded : [];
    }
}
