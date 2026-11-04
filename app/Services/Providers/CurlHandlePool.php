<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

/**
 * PERF-003: upstream connection reuse for the cURL transport (decision D4,
 * cURL option).
 *
 * - Handles are pooled per scheme://host:port for the life of the PHP process
 *   and reset (curl_reset keeps the live connection, DNS and TLS session
 *   caches) before reuse. A handle is checked out while a transfer runs and
 *   only returned afterwards, so it is never used by two transfers at once
 *   (a stream that triggers another upstream call simply gets another handle).
 * - One share handle per process carries the DNS cache and TLS sessions across
 *   handles (curl_share_init). On PHP 8.5+ curl_share_init_persistent() keeps
 *   DNS, TLS sessions and connections alive across requests served by the same
 *   php-fpm child; on PHP 8.2-8.4 everything is released at request end, so
 *   reuse there covers retries, failover, task polling and downloads within
 *   one request and every call made by long-running CLI workers.
 * - HTTP/2 is negotiated over TLS (CURL_HTTP_VERSION_2TLS) when libcurl
 *   supports it and GATEWAY_UPSTREAM_HTTP2 is not false; plain-http upstreams
 *   stay on HTTP/1.1.
 *
 * GATEWAY_UPSTREAM_REUSE_CONNECTIONS=false restores one fresh handle per call.
 */
final class CurlHandlePool
{
    private const MAX_IDLE_PER_HOST = 4;
    private const MAX_HOSTS = 64;

    /** @var array<string, list<\CurlHandle>> */
    private static array $idle = [];
    private static mixed $share = null;
    private static ?bool $http2Supported = null;

    public static function enabled(): bool
    {
        return filter_var(env_value('GATEWAY_UPSTREAM_REUSE_CONNECTIONS', true), FILTER_VALIDATE_BOOL);
    }

    public static function http2Enabled(): bool
    {
        if (!filter_var(env_value('GATEWAY_UPSTREAM_HTTP2', true), FILTER_VALIDATE_BOOL) || !defined('CURL_HTTP_VERSION_2TLS')) {
            return false;
        }
        if (self::$http2Supported === null) {
            $version = function_exists('curl_version') ? curl_version() : false;
            self::$http2Supported = is_array($version) && defined('CURL_VERSION_HTTP2') && (((int) ($version['features'] ?? 0)) & CURL_VERSION_HTTP2) !== 0;
        }

        return self::$http2Supported;
    }

    public static function httpVersion(): int
    {
        return self::http2Enabled() ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1;
    }

    /**
     * A handle for one transfer to $url; give it back with release().
     */
    public static function acquire(string $url): \CurlHandle
    {
        if (!self::enabled()) {
            return curl_init();
        }
        $key = self::hostKey($url);
        $handle = isset(self::$idle[$key]) ? array_pop(self::$idle[$key]) : null;
        if (!$handle instanceof \CurlHandle) {
            $handle = curl_init();
        }
        $share = self::share();
        if ($share !== null) {
            curl_setopt($handle, CURLOPT_SHARE, $share);
        }

        return $handle;
    }

    public static function release(string $url, \CurlHandle $handle): void
    {
        if (!self::enabled()) {
            return;
        }
        // Drops every option and callback (closures holding request state)
        // but keeps the connection cache.
        curl_reset($handle);
        $key = self::hostKey($url);
        if (!isset(self::$idle[$key]) && count(self::$idle) >= self::MAX_HOSTS) {
            return;
        }
        self::$idle[$key] ??= [];
        if (count(self::$idle[$key]) < self::MAX_IDLE_PER_HOST) {
            self::$idle[$key][] = $handle;
        }
    }

    /** Idle handles per host (diagnostics and tests). */
    public static function idleCount(?string $url = null): int
    {
        if ($url !== null) {
            return count(self::$idle[self::hostKey($url)] ?? []);
        }

        return array_sum(array_map('count', self::$idle));
    }

    public static function flush(): void
    {
        self::$idle = [];
    }

    private static function share(): mixed
    {
        if (self::$share === false) {
            return null;
        }
        if (self::$share !== null) {
            return self::$share;
        }
        try {
            if (function_exists('curl_share_init_persistent')) {
                // PHP 8.5+: survives the request inside this worker process.
                $options = [CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_SSL_SESSION];
                if (defined('CURL_LOCK_DATA_CONNECT')) {
                    $options[] = CURL_LOCK_DATA_CONNECT;
                }
                self::$share = \call_user_func('curl_share_init_persistent', $options);
                return self::$share;
            }
            if (function_exists('curl_share_init')) {
                $share = curl_share_init();
                curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
                curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
                self::$share = $share;
                return $share;
            }
        } catch (\Throwable) {
        }
        self::$share = false;

        return null;
    }

    private static function hostKey(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

        return $scheme . '://' . $host . ':' . $port;
    }
}
