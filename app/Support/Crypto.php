<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class Crypto
{
    public static function encrypt(string $plainText): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    public static function hashKey(string $plainText): string
    {
        return hash_hmac('sha256', $plainText, self::key());
    }

    public static function token(string $prefix = ''): string
    {
        return $prefix . bin2hex(random_bytes(32));
    }

    private static function key(): string
    {
        $appKey = (string) env_value('APP_KEY', 'development-tokenhub-key-change-me');
        return hash('sha256', $appKey, true);
    }
}
