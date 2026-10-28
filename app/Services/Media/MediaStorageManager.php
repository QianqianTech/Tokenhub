<?php

declare(strict_types=1);

namespace TokenHub\Services\Media;

/**
 * Resolves the configured media storage driver (MEDIA_STORAGE_DRIVER, default
 * "local"). Further drivers (S3/OSS/MinIO) register here without touching the
 * gateway or download code.
 */
final class MediaStorageManager
{
    /** @var array<string, callable(): MediaStorage> */
    private static array $drivers = [];

    public static function register(string $name, callable $factory): void
    {
        self::$drivers[strtolower($name)] = $factory;
    }

    public static function driver(?string $name = null): MediaStorage
    {
        $name = strtolower(trim((string) ($name ?? env_value('MEDIA_STORAGE_DRIVER', 'local'))));
        if ($name !== '' && $name !== 'local' && isset(self::$drivers[$name])) {
            return (self::$drivers[$name])();
        }

        return new LocalMediaStorage();
    }

    /**
     * Driver able to read a stored path (local file or a registered scheme).
     */
    public static function forPath(string $storagePath): ?MediaStorage
    {
        foreach (self::$drivers as $factory) {
            $driver = $factory();
            if ($driver->handles($storagePath)) {
                return $driver;
            }
        }
        $local = new LocalMediaStorage();

        return $local->handles($storagePath) ? $local : null;
    }
}
