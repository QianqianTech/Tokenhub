<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Support\App;

/**
 * Per-channel circuit breaker shared through Redis.
 *
 * closed     normal routing.
 * open       within the sliding window (GATEWAY_CIRCUIT_WINDOW_SECONDS,
 *            default 60) at least GATEWAY_CIRCUIT_FAILURES failures (default
 *            5) were seen and they are at least GATEWAY_CIRCUIT_ERROR_RATE
 *            (default 0.5) of the channel's outcomes; the channel is skipped
 *            while another candidate exists, for
 *            GATEWAY_CIRCUIT_COOLDOWN_SECONDS. A 429 opens it immediately for
 *            the upstream's Retry-After (cooldown(), QUAL-004).
 * half_open  cooldown elapsed: one request at a time is let through as a
 *            probe (a short Redis claim); others keep treating the channel as
 *            open. A success closes the circuit, a failure re-opens it.
 *
 * QUAL-006: every outcome is recorded by one Lua script on a Redis hash
 * (route_health:<model id>: open_until, failures, last_* fields and 10-second
 * buckets "b:<n>:ok" / "b:<n>:fail"), so concurrent PHP workers can no longer
 * lose updates with a read-modify-write, and old failures age out of the
 * window instead of accumulating forever.
 *
 * Client errors (400/404/413/422) never count: they say nothing about the
 * channel's health. 401/402/403 count (QUAL-003): the channel credential is
 * broken.
 */
final class RouteHealth
{
    private const PREFIX = 'route_health:';
    private const BUCKET_SECONDS = 10;

    private const RECORD_SCRIPT = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local outcome = ARGV[2]
local window = tonumber(ARGV[4])
local bsize = tonumber(ARGV[5])
local minFailures = tonumber(ARGV[6])
local rate = tonumber(ARGV[7])
local cooldown = tonumber(ARGV[8])
local ttl = tonumber(ARGV[9])
local override = tonumber(ARGV[10])
local kind = redis.call('TYPE', key)['ok']
if kind ~= 'hash' and kind ~= 'none' then redis.call('DEL', key) end
local bucket = math.floor(now / bsize)
local minBucket = bucket - math.floor(window / bsize) + 1
local openUntil = tonumber(redis.call('HGET', key, 'open_until') or '0') or 0
local halfOpen = openUntil > 0 and now >= openUntil
local fails, oks = 0, 0
local fields = redis.call('HKEYS', key)
for _, field in ipairs(fields) do
  local n, k = string.match(field, '^b:(%-?%d+):(%a+)$')
  if n then
    if tonumber(n) < minBucket then
      redis.call('HDEL', key, field)
    else
      local v = tonumber(redis.call('HGET', key, field) or '0') or 0
      if k == 'fail' then fails = fails + v else oks = oks + v end
    end
  end
end
if outcome == 'ok' then
  redis.call('HINCRBY', key, 'b:' .. bucket .. ':ok', 1)
  redis.call('HSET', key, 'failures', 0, 'last_success_at', now)
  -- A success closes the circuit, except a rate-limit cooldown that has
  -- not elapsed yet (an older in-flight request may finish during it).
  local reason = redis.call('HGET', key, 'open_reason') or ''
  if openUntil > 0 and (halfOpen or reason ~= 'rate_limit') then
    for _, field in ipairs(redis.call('HKEYS', key)) do
      if string.sub(field, 1, 2) == 'b:' then redis.call('HDEL', key, field) end
    end
    redis.call('HSET', key, 'open_until', 0)
    openUntil = 0
  end
  oks = oks + 1
else
  redis.call('HINCRBY', key, 'b:' .. bucket .. ':fail', 1)
  redis.call('HINCRBY', key, 'failures', 1)
  redis.call('HSET', key, 'last_failure_at', now, 'last_failure_reason', ARGV[3])
  fails = fails + 1
  local total = fails + oks
  local trip = halfOpen or (fails >= minFailures and total > 0 and (fails / total) >= rate)
  local untilTs = 0
  if override > 0 then untilTs = now + override end
  if trip and now + cooldown > untilTs then untilTs = now + cooldown end
  if untilTs > 0 and (openUntil <= now or untilTs > openUntil) then
    local why = 'errors'
    if override > 0 and not trip then why = 'rate_limit' end
    redis.call('HSET', key, 'open_until', untilTs, 'opened_at', now, 'open_reason', why)
    openUntil = untilTs
  end
end
redis.call('EXPIRE', key, ttl)
return {openUntil, fails, oks}
LUA;

    public function state(int $modelId, ?int $now = null): string
    {
        $now ??= time();
        $openUntil = (int) ($this->read($modelId)['open_until'] ?? 0);
        if ($openUntil === 0) {
            return 'closed';
        }

        return $now < $openUntil ? 'open' : 'half_open';
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(int $modelId): array
    {
        $data = $this->read($modelId);
        $window = ['failures' => 0, 'successes' => 0];
        $minBucket = intdiv(time(), self::BUCKET_SECONDS) - intdiv($this->windowSeconds(), self::BUCKET_SECONDS) + 1;
        foreach ($data as $field => $value) {
            if (preg_match('/^b:(-?\d+):(ok|fail)$/', (string) $field, $match) === 1) {
                unset($data[$field]);
                if ((int) $match[1] >= $minBucket) {
                    $window[$match[2] === 'fail' ? 'failures' : 'successes'] += (int) $value;
                }
            }
        }
        foreach (['open_until', 'failures', 'last_failure_at', 'last_success_at', 'opened_at'] as $intField) {
            if (isset($data[$intField])) {
                $data[$intField] = (int) $data[$intField];
            }
        }

        return ['state' => $this->state($modelId)] + $data + ['window' => $window];
    }

    public function recordSuccess(int $modelId): void
    {
        if ($modelId <= 0) {
            return;
        }
        $wasHalfOpen = $this->state($modelId) === 'half_open';
        $this->record($modelId, 'ok', '', time(), 0);
        if ($wasHalfOpen) {
            $this->releaseProbe($modelId);
        }
    }

    public function recordFailure(int $modelId, string $reason, ?int $now = null): void
    {
        if ($modelId <= 0) {
            return;
        }
        $now ??= time();
        $wasHalfOpen = $this->state($modelId, $now) === 'half_open';
        $this->record($modelId, 'fail', $reason, $now, 0);
        if ($wasHalfOpen) {
            $this->releaseProbe($modelId);
        }
    }

    /**
     * QUAL-004: the upstream rate-limited this channel. It is counted as a
     * failure and kept out of rotation for the upstream's Retry-After
     * (GATEWAY_RATE_LIMIT_COOLDOWN_SECONDS when absent, capped by
     * GATEWAY_RATE_LIMIT_COOLDOWN_MAX_SECONDS).
     */
    public function cooldown(int $modelId, ?int $retryAfterSeconds, string $reason = 'PROVIDER_RATE_LIMITED', ?int $now = null): void
    {
        if ($modelId <= 0) {
            return;
        }
        $default = max(1, (int) env_value('GATEWAY_RATE_LIMIT_COOLDOWN_SECONDS', 10));
        $max = max(1, (int) env_value('GATEWAY_RATE_LIMIT_COOLDOWN_MAX_SECONDS', 300));
        $seconds = $retryAfterSeconds !== null && $retryAfterSeconds > 0 ? min($max, $retryAfterSeconds) : $default;
        $this->record($modelId, 'fail', $reason, $now ?? time(), $seconds);
    }

    /**
     * Orders candidates: closed and half-open channels keep their priority,
     * open channels move to the end so they are only used as a last resort.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public function order(array $candidates, ?int $now = null): array
    {
        $now ??= time();
        $healthy = [];
        $open = [];
        $ids = array_values(array_unique(array_map(static fn (array $candidate): int => (int) ($candidate['id'] ?? 0), $candidates)));
        $openUntil = $this->openUntilMany($ids);
        foreach ($candidates as $candidate) {
            $id = (int) ($candidate['id'] ?? 0);
            $until = $openUntil[$id] ?? 0;
            $state = $until === 0 ? 'closed' : ($now < $until ? 'open' : 'half_open');
            if ($state === 'half_open' && !$this->claimProbe($id)) {
                // Another request is already probing this channel.
                $state = 'open';
            }
            $candidate['circuit_state'] = $state;
            if ($state === 'open') {
                $open[] = $candidate;
            } else {
                $healthy[] = $candidate;
            }
        }

        return [...$healthy, ...$open];
    }

    public static function countsAsFailure(int $status, ?string $errorCode): bool
    {
        if (in_array($errorCode, ['OUTBOUND_BLOCKED', 'PROVIDER_ENDPOINT_UNSUPPORTED'], true)) {
            return false;
        }

        return $status === 0 || $status === 401 || $status === 402 || $status === 403 || $status === 408 || $status === 429 || $status >= 500;
    }

    private function record(int $modelId, string $outcome, string $reason, int $now, int $override): void
    {
        $threshold = max(1, (int) env_value('GATEWAY_CIRCUIT_FAILURES', 5));
        $rate = (float) env_value('GATEWAY_CIRCUIT_ERROR_RATE', 0.5);
        $rate = $rate > 0 && $rate <= 1 ? $rate : 0.5;
        $cooldown = max(1, (int) env_value('GATEWAY_CIRCUIT_COOLDOWN_SECONDS', 30));
        try {
            RedisScript::run(self::RECORD_SCRIPT, [
                self::PREFIX . $modelId,
                (string) $now,
                $outcome,
                mb_substr($reason, 0, 120),
                (string) $this->windowSeconds(),
                (string) self::BUCKET_SECONDS,
                (string) $threshold,
                (string) $rate,
                (string) $cooldown,
                '86400',
                (string) max(0, $override),
            ], 1);
        } catch (Throwable) {
            // Health tracking is best effort; routing keeps working without it.
        }
    }

    private function windowSeconds(): int
    {
        return max(self::BUCKET_SECONDS, (int) env_value('GATEWAY_CIRCUIT_WINDOW_SECONDS', 60));
    }

    private function claimProbe(int $modelId): bool
    {
        try {
            $ttl = max(5, (int) env_value('GATEWAY_CIRCUIT_COOLDOWN_SECONDS', 30));
            return App::redis()->setIfAbsent(self::PREFIX . 'probe:' . $modelId, (string) time(), $ttl);
        } catch (Throwable) {
            return true;
        }
    }

    private function releaseProbe(int $modelId): void
    {
        try {
            App::redis()->delete(self::PREFIX . 'probe:' . $modelId);
        } catch (Throwable) {
        }
    }

    /**
     * @param list<int> $modelIds
     * @return array<int, int>
     */
    private function openUntilMany(array $modelIds): array
    {
        $modelIds = array_values(array_filter($modelIds, static fn (int $id): bool => $id > 0));
        if ($modelIds === []) {
            return [];
        }
        $result = [];
        try {
            $redis = App::redis()->connection();
            $pipe = $redis->pipeline();
            foreach ($modelIds as $id) {
                $pipe->hGet(self::PREFIX . $id, 'open_until');
            }
            $values = $pipe->exec();
        } catch (Throwable) {
            // Pre-upgrade string values (or no Redis): treat as closed.
            return $this->openUntilSequential($modelIds);
        }
        foreach ($modelIds as $index => $id) {
            $value = is_array($values) ? ($values[$index] ?? false) : false;
            $result[$id] = is_numeric($value) ? (int) $value : 0;
        }

        return $result;
    }

    /**
     * @param list<int> $modelIds
     * @return array<int, int>
     */
    private function openUntilSequential(array $modelIds): array
    {
        $result = [];
        foreach ($modelIds as $id) {
            $result[$id] = (int) ($this->read($id)['open_until'] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $modelId): array
    {
        try {
            $value = App::redis()->connection()->hGetAll(self::PREFIX . $modelId);
        } catch (Throwable) {
            return [];
        }

        return is_array($value) ? $value : [];
    }
}
