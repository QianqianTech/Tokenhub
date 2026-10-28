<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class App
{
    private static ?Config $config = null;
    private static ?RequestContext $context = null;
    private static ?Database $database = null;
    private static ?RedisClient $redis = null;
    private static ?Logger $logger = null;

    public static function bootstrap(string $basePath): void
    {
        $_SERVER['TOKENHUB_BASE_PATH'] = $basePath;
        self::$config = new Config($basePath);
        self::$context = RequestContext::fromHeaders($_SERVER);
        // Fail fast instead of encrypting provider secrets with a public default key.
        Crypto::assertProductionReady();
        self::ensureStorage();
    }

    public static function config(): Config
    {
        return self::$config ??= new Config(base_path());
    }

    public static function context(): RequestContext
    {
        return self::$context ??= RequestContext::fromHeaders($_SERVER);
    }

    public static function db(): Database
    {
        return self::$database ??= new Database(self::config());
    }

    public static function redis(): RedisClient
    {
        return self::$redis ??= new RedisClient(self::config());
    }

    public static function logger(): Logger
    {
        return self::$logger ??= new Logger((string) self::config()->get('app.log_path', self::config()->get('logging.path', storage_path('logs'))));
    }

    private static function ensureStorage(): void
    {
        foreach (['logs', 'exports', 'media', 'cache'] as $dir) {
            $path = storage_path($dir);
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }
}
