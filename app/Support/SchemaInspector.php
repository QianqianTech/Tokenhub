<?php

declare(strict_types=1);

namespace TokenHub\Support;

use Throwable;

/**
 * Cached column existence checks.
 *
 * New columns ship with migrations; code paths that use them check here so a
 * release deployed before `migrate` runs keeps the previous behaviour instead
 * of failing every request with "unknown column".
 */
final class SchemaInspector
{
    /** @var array<string, bool> */
    private static array $memo = [];

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $cacheKey = 'schema:column:' . $key;
        try {
            $cached = App::redis()->get($cacheKey);
            if ($cached === '1' || $cached === 1 || $cached === true) {
                return self::$memo[$key] = true;
            }
            if ($cached === '0' || $cached === 0 || $cached === false) {
                return self::$memo[$key] = false;
            }
        } catch (Throwable) {
        }

        try {
            $row = App::db()->first(
                'SELECT 1 AS present FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
                [$table, $column]
            );
            $present = $row !== null;
        } catch (Throwable) {
            return self::$memo[$key] = false;
        }
        try {
            App::redis()->set($cacheKey, $present ? '1' : '0', 300);
        } catch (Throwable) {
        }

        return self::$memo[$key] = $present;
    }

    public static function forget(): void
    {
        self::$memo = [];
    }
}
