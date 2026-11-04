<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Support\App;

/**
 * MT-003: channel-level capacity limits and in-flight accounting.
 *
 * A channel is one model row (provider + upstream model). Optional limits on
 * the row (migration 2026_10_31_000040):
 *   max_in_flight  concurrent upstream requests on the channel
 *   rpm_limit      requests per minute
 *   tpm_limit      estimated tokens (input + reserved output) per minute
 * A request that would exceed a limit skips the channel and fails over to
 * the next candidate; when every channel is full the client gets 429
 * CHANNEL_CAPACITY_EXCEEDED.
 *
 * In-flight requests are members of a sorted set scored by an expiry, so a
 * killed PHP process cannot leak a slot for longer than the maximum upstream
 * duration. The set is also what "least in-flight" balancing reads
 * (ModelRouter, GATEWAY_ROUTE_BALANCE=least_inflight).
 *
 * All checks of one acquisition run in a single Lua script.
 */
final class ChannelLimiter
{
    private const INFLIGHT = 'channel:inflight:';
    private const RPM = 'channel:rpm:';
    private const TPM = 'channel:tpm:';

    private const ACQUIRE_SCRIPT = <<<'LUA'
local now = tonumber(ARGV[1])
local member = ARGV[2]
local expires = tonumber(ARGV[3])
local maxInFlight = tonumber(ARGV[4])
local rpm = tonumber(ARGV[5])
local tpm = tonumber(ARGV[6])
local tokens = tonumber(ARGV[7])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now)
if maxInFlight > 0 and redis.call('ZCARD', KEYS[1]) >= maxInFlight then return {0, 1} end
if rpm > 0 and tonumber(redis.call('GET', KEYS[2]) or '0') + 1 > rpm then return {0, 2} end
if tpm > 0 and tonumber(redis.call('GET', KEYS[3]) or '0') + tokens > tpm then return {0, 3} end
redis.call('ZADD', KEYS[1], expires, member)
redis.call('EXPIRE', KEYS[1], math.max(60, expires - now + 60))
if rpm > 0 then redis.call('INCR', KEYS[2]); redis.call('EXPIRE', KEYS[2], 120) end
if tpm > 0 then redis.call('INCRBY', KEYS[3], tokens); redis.call('EXPIRE', KEYS[3], 120) end
return {1, 0}
LUA;

    /** @var array<string, array{model_id: int, member: string, minute: string, tokens: int}> */
    private array $held = [];

    /**
     * @param array<string, mixed> $route
     * @return array{ok: bool, reason?: string}
     */
    public function acquire(array $route, string $requestId, int $estimatedTokens = 0): array
    {
        $modelId = (int) ($route['id'] ?? 0);
        $maxInFlight = max(0, (int) ($route['max_in_flight'] ?? 0));
        $rpm = max(0, (int) ($route['rpm_limit'] ?? 0));
        $tpm = max(0, (int) ($route['tpm_limit'] ?? 0));
        if ($modelId <= 0 || ($maxInFlight === 0 && $rpm === 0 && $tpm === 0 && !self::tracksInFlight())) {
            return ['ok' => true];
        }
        $now = time();
        $minute = gmdate('YmdHi', $now);
        $member = $requestId . ':' . bin2hex(random_bytes(4));
        $expires = $now + max(60, (int) env_value('GATEWAY_UPSTREAM_MAX_DURATION_SECONDS', 1800) + 60);
        try {
            $result = RedisScript::run(self::ACQUIRE_SCRIPT, [
                self::INFLIGHT . $modelId,
                self::RPM . $modelId . ':' . $minute,
                self::TPM . $modelId . ':' . $minute,
                (string) $now,
                $member,
                (string) $expires,
                (string) $maxInFlight,
                (string) $rpm,
                (string) $tpm,
                (string) max(0, $estimatedTokens),
            ], 3);
        } catch (Throwable) {
            // Limits are best effort without Redis.
            return ['ok' => true];
        }
        if (!is_array($result) || (int) ($result[0] ?? 1) === 1) {
            $this->held[$this->slot($route)] = ['model_id' => $modelId, 'member' => $member, 'minute' => $minute, 'tokens' => $tpm > 0 ? max(0, $estimatedTokens) : 0];
            return ['ok' => true];
        }

        return ['ok' => false, 'reason' => match ((int) ($result[1] ?? 0)) {
            1 => 'max_in_flight',
            2 => 'rpm_limit',
            default => 'tpm_limit',
        }];
    }

    /**
     * Frees the in-flight slot; corrects the minute's token count to the
     * measured tokens when known. Safe to call more than once.
     *
     * @param array<string, mixed> $route
     */
    public function release(array $route, ?int $actualTokens = null): void
    {
        $slot = $this->slot($route);
        $held = $this->held[$slot] ?? null;
        unset($this->held[$slot]);
        if ($held === null) {
            return;
        }
        try {
            $redis = App::redis()->connection();
            $redis->zRem(self::INFLIGHT . $held['model_id'], $held['member']);
            if ($actualTokens !== null && $held['tokens'] > 0 && $held['minute'] === gmdate('YmdHi')) {
                $delta = $actualTokens - $held['tokens'];
                if ($delta !== 0) {
                    $redis->incrBy(self::TPM . $held['model_id'] . ':' . $held['minute'], $delta);
                }
            }
        } catch (Throwable) {
        }
    }

    public function releaseAll(): void
    {
        foreach (array_keys($this->held) as $slot) {
            $this->release(['id' => (int) $slot]);
        }
    }

    /**
     * Current in-flight requests per model id.
     *
     * @param list<int> $modelIds
     * @return array<int, int>
     */
    public static function inFlight(array $modelIds): array
    {
        $modelIds = array_values(array_filter(array_unique($modelIds), static fn (int $id): bool => $id > 0));
        if ($modelIds === []) {
            return [];
        }
        $now = (string) time();
        try {
            $pipe = App::redis()->connection()->pipeline();
            foreach ($modelIds as $id) {
                $pipe->zCount(self::INFLIGHT . $id, '(' . $now, '+inf');
            }
            $counts = $pipe->exec();
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($modelIds as $index => $id) {
            $out[$id] = is_array($counts) ? (int) ($counts[$index] ?? 0) : 0;
        }

        return $out;
    }

    public static function tracksInFlight(): bool
    {
        return strtolower((string) env_value('GATEWAY_ROUTE_BALANCE', 'weighted')) === 'least_inflight';
    }

    /**
     * @param array<string, mixed> $route
     */
    private function slot(array $route): string
    {
        return (string) (int) ($route['id'] ?? 0);
    }
}
