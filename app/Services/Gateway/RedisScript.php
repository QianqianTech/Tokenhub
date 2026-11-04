<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\App;
use TokenHub\Support\RedisClient;

/**
 * Runs a Lua script through RedisClient::evalScript (EVALSHA with NOSCRIPT
 * fallback) when the client offers it, plain EVAL otherwise.
 */
final class RedisScript
{
    /**
     * @param list<mixed> $args keys first, then argv
     */
    public static function run(string $script, array $args, int $numKeys, ?RedisClient $redis = null): mixed
    {
        $redis ??= App::redis();
        if (method_exists($redis, 'evalScript')) {
            return $redis->evalScript($script, $args, $numKeys);
        }

        return $redis->connection()->eval($script, $args, $numKeys);
    }
}
