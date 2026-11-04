<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Support\App;

/**
 * Work that may run after the client received its response (PERF-010,
 * PERF-020): settlement, usage record, desensitization audit rows.
 *
 * Under php-fpm the runner is a shutdown function: the front controller
 * sends the response, the runner calls fastcgi_finish_request() so the
 * client connection is closed, then executes the queued jobs. Elsewhere (CLI,
 * tests, other SAPIs, GATEWAY_DEFER_SETTLEMENT=false) nothing is deferred and
 * callers run the work inline.
 *
 * arm() must be called before RequestLifecycle::guardShutdown() so the runner
 * is registered first: shutdown functions run in registration order, and the
 * lifecycle's safety net must only see what the runner left open.
 */
final class DeferredWork
{
    /** @var list<\Closure(): void> */
    private static array $jobs = [];
    private static bool $armed = false;
    private static bool $running = false;

    public static function enabled(): bool
    {
        return PHP_SAPI === 'fpm-fcgi'
            && function_exists('fastcgi_finish_request')
            && filter_var(env_value('GATEWAY_DEFER_SETTLEMENT', true), FILTER_VALIDATE_BOOL);
    }

    public static function arm(): void
    {
        if (self::$armed || !self::enabled()) {
            return;
        }
        self::$armed = true;
        register_shutdown_function(static function (): void {
            self::run();
        });
    }

    /**
     * Queues $job when deferral is available, otherwise runs it now.
     *
     * @param callable(): void $job
     */
    public static function add(callable $job): void
    {
        if (!self::$armed || self::$running) {
            $job();
            return;
        }
        self::$jobs[] = \Closure::fromCallable($job);
    }

    public static function pending(): bool
    {
        return self::$jobs !== [];
    }

    /**
     * Finishes the client response and runs every queued job.
     */
    public static function run(): void
    {
        if (self::$jobs === []) {
            return;
        }
        self::$running = true;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        while (self::$jobs !== []) {
            $job = array_shift(self::$jobs);
            try {
                $job();
            } catch (Throwable $throwable) {
                try {
                    App::logger()->error('gateway deferred work failed', ['request_id' => App::context()->requestId(), 'error' => $throwable->getMessage()]);
                } catch (Throwable) {
                }
            }
        }
        self::$running = false;
    }
}
