<?php

declare(strict_types=1);

namespace TokenHub\Support;

use Throwable;

/**
 * Cluster-wide configuration generation counter.
 *
 * Read paths (routes, API key rows, quota and desensitization policies) embed
 * the current generation in their Redis cache keys. Any admin write that can
 * change authorization or routing bumps the generation, so every PHP-FPM
 * worker and queue worker sharing the same Redis stops using stale entries on
 * its next request instead of waiting for the TTL to expire.
 *
 * Effective-time contract: a change is visible to new requests as soon as the
 * bump completes. Requests already in flight keep the route they resolved.
 * If Redis is unavailable the generation falls back to 0 and the existing
 * short TTLs remain the upper bound.
 */
final class ConfigVersion
{
    public const KEY = 'tokenhub:config_version';

    /** @var array<string, int> */
    private static array $memo = [];

    public static function current(string $scope = 'global'): int
    {
        if (isset(self::$memo[$scope])) {
            return self::$memo[$scope];
        }

        try {
            $value = App::redis()->get(self::key($scope));
        } catch (Throwable) {
            $value = null;
        }

        return self::$memo[$scope] = is_numeric($value) ? (int) $value : 0;
    }

    public static function bump(string $scope = 'global', string $reason = ''): int
    {
        unset(self::$memo[$scope]);
        try {
            $next = App::redis()->increment(self::key($scope));
            $next = is_array($next) ? (int) ($next['count'] ?? 0) : (int) $next;
            App::redis()->set(self::key($scope) . ':changed_at', ['at' => gmdate('c'), 'reason' => $reason, 'version' => $next]);
        } catch (Throwable) {
            return 0;
        }

        return $next;
    }

    /**
     * Forget memoized versions. Long-running workers call this once per job so
     * a bump made by the admin plane is observed without restarting.
     */
    public static function refresh(): void
    {
        self::$memo = [];
    }

    public static function cacheSuffix(string $scope = 'global'): string
    {
        return ':v' . self::current($scope);
    }

    private static function key(string $scope): string
    {
        return $scope === 'global' ? self::KEY : self::KEY . ':' . $scope;
    }
}
