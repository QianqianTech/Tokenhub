<?php

declare(strict_types=1);

namespace TokenHub\Support;

use RuntimeException;
use Throwable;

final class RedisClient
{
    private ?\Redis $redis = null;
    private array $memory = [];

    public function __construct(private readonly Config $config)
    {
    }

    public function connection(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('Redis extension is not installed');
        }

        if ($this->redis !== null) {
            return $this->redis;
        }

        $redis = new \Redis();
        $host = (string) $this->config->get('redis.host', '127.0.0.1');
        $port = (int) $this->config->get('redis.port', 6379);
        $timeout = (float) $this->config->get('redis.timeout', 2.0);
        $password = (string) $this->config->get('redis.password', '');
        $database = (int) $this->config->get('redis.database', 0);

        if ($this->persistentEnabled()) {
            // PERF-011: one socket per FPM child instead of a TCP handshake +
            // AUTH per request. The id includes host/port/db so two configs in
            // one process never share a socket.
            $persistentId = (string) $this->config->get('redis.persistent_id', 'tokenhub') . ':' . $host . ':' . $port . ':' . $database;
            $redis->pconnect($host, $port, $timeout, $persistentId);
        } else {
            $redis->connect($host, $port, $timeout);
        }

        if ($password !== '') {
            $redis->auth($password);
        }

        if ($database > 0) {
            $redis->select($database);
        }

        $this->redis = $redis;

        return $this->redis;
    }

    private function persistentEnabled(): bool
    {
        $value = $this->config->get('redis.persistent', false);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /** @var array<string, string> script source => sha1 */
    private static array $scriptShas = [];

    /**
     * Runs a Lua script by SHA (EVALSHA) so the body is sent once per server,
     * not on every call. On NOSCRIPT (server restarted / SCRIPT FLUSH) the
     * script is loaded again and retried; EVAL is the last resort.
     *
     * @param list<mixed> $args keys first, then argv
     */
    public function evalScript(string $script, array $args, int $numKeys): mixed
    {
        $redis = $this->connection();
        $sha = self::$scriptShas[$script] ??= sha1($script);
        $redis->clearLastError();
        $result = $redis->evalSha($sha, $args, $numKeys);
        if ($result !== false) {
            return $result;
        }
        $error = (string) $redis->getLastError();
        if ($error === '') {
            return $result; // the script itself returned nil / false
        }
        if (str_contains($error, 'NOSCRIPT')) {
            $redis->clearLastError();
            $loaded = $redis->script('load', $script);
            if (is_string($loaded) && $loaded !== '') {
                self::$scriptShas[$script] = $loaded;
                $result = $redis->evalSha($loaded, $args, $numKeys);
                if ($result !== false || (string) $redis->getLastError() === '') {
                    return $result;
                }
                $redis->clearLastError();
            }

            return $redis->eval($script, $args, $numKeys);
        }

        // Same contract as EVAL: false, with the error left in getLastError().
        return $result;
    }

    public function available(): bool
    {
        return class_exists(\Redis::class);
    }

    public function get(string $key): mixed
    {
        if (!$this->available()) {
            return $this->memoryGet($key);
        }
        $value = $this->connection()->get($key);

        return $value === false ? null : (json_decode($value, true) ?? $value);
    }

    /**
     * Values of several keys in one round trip (MGET), keyed like the input.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        if (!$this->available()) {
            $values = [];
            foreach ($keys as $key) {
                $values[$key] = $this->memoryGet($key);
            }
            return $values;
        }
        $raw = $this->connection()->mGet($keys);
        $values = [];
        foreach ($keys as $index => $key) {
            $value = is_array($raw) ? ($raw[$index] ?? false) : false;
            $values[$key] = $value === false ? null : (json_decode((string) $value, true) ?? $value);
        }

        return $values;
    }

    /** SET key value NX EX ttl: true when this caller created the key. */
    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        if (!$this->available()) {
            if ($this->memoryGet($key) !== null) {
                return false;
            }
            $this->memory[$key] = ['value' => $value, 'expires_at' => time() + max(1, $ttlSeconds)];
            return true;
        }

        return (bool) $this->connection()->set($key, $value, ['nx', 'ex' => max(1, $ttlSeconds)]);
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        if (!$this->available()) {
            $this->memory[$key] = [
                'value' => $value,
                'expires_at' => $ttlSeconds > 0 ? time() + $ttlSeconds : null,
            ];
            return true;
        }
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($ttlSeconds > 0) {
            return (bool) $this->connection()->setex($key, $ttlSeconds, (string) $encoded);
        }

        return (bool) $this->connection()->set($key, (string) $encoded);
    }

    public function delete(string $key): void
    {
        if (!$this->available()) {
            unset($this->memory[$key]);
            return;
        }
        $this->connection()->del($key);
    }

    public function increment(string $key, int $limitOrTtlSeconds = 0, ?int $ttlSeconds = null): int|array
    {
        if ($ttlSeconds !== null) {
            if (!$this->available()) {
                $count = (int) ($this->memoryGet($key) ?? 0) + 1;
                $this->memory[$key] = [
                    'value' => $count,
                    'expires_at' => $ttlSeconds > 0 ? time() + $ttlSeconds : null,
                ];
                return ['allowed' => $count <= $limitOrTtlSeconds, 'count' => $count, 'limit' => $limitOrTtlSeconds];
            }
            $script = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then redis.call('EXPIRE', KEYS[1], ARGV[2]) end
return {current <= tonumber(ARGV[1]) and 1 or 0, current, tonumber(ARGV[1])}
LUA;
            $result = $this->evalScript($script, [$key, $limitOrTtlSeconds, $ttlSeconds], 1);
            return ['allowed' => (int) $result[0] === 1, 'count' => (int) $result[1], 'limit' => (int) $result[2]];
        }

        if (!$this->available()) {
            $value = (int) ($this->memoryGet($key) ?? 0) + 1;
            $this->memory[$key] = [
                'value' => $value,
                'expires_at' => $limitOrTtlSeconds > 0 ? time() + $limitOrTtlSeconds : null,
            ];
            return $value;
        }

        $value = (int) $this->connection()->incr($key);

        if ($limitOrTtlSeconds > 0 && $value === 1) {
            $this->connection()->expire($key, $limitOrTtlSeconds);
        }

        return $value;
    }

    public function decrement(string $key): int
    {
        if (!$this->available()) {
            $value = max(0, (int) ($this->memoryGet($key) ?? 0) - 1);
            $this->memory[$key] = [
                'value' => $value,
                'expires_at' => $this->memoryExpiresAt($key),
            ];
            return $value;
        }

        $value = (int) $this->connection()->decr($key);
        if ($value < 0) {
            $this->connection()->set($key, '0');
            return 0;
        }

        return $value;
    }

    public function incrementByFloat(string $key, int|float $amount, int $ttlSeconds = 0): float
    {
        if (!$this->available()) {
            $value = (float) ($this->memoryGet($key) ?? 0) + (float) $amount;
            $this->memory[$key] = [
                'value' => (string) $value,
                'expires_at' => $ttlSeconds > 0 ? time() + $ttlSeconds : $this->memoryExpiresAt($key),
            ];
            return $value;
        }

        $script = <<<'LUA'
local current = redis.call('INCRBYFLOAT', KEYS[1], ARGV[1])
if tonumber(ARGV[2]) > 0 then redis.call('EXPIRE', KEYS[1], ARGV[2]) end
return current
LUA;
        return (float) $this->evalScript($script, [$key, (string) $amount, $ttlSeconds], 1);
    }

    public function decrementBy(string $key, int|float $amount): int|float
    {
        if (!$this->available()) {
            $next = max(0, (float) ($this->memoryGet($key) ?? 0) - (float) $amount);
            $this->memory[$key] = [
                'value' => (string) $next,
                'expires_at' => $this->memoryExpiresAt($key),
            ];
            return $next;
        }

        if (is_int($amount)) {
            $value = (int) $this->connection()->decrBy($key, $amount);
            if ($value < 0) {
                $this->connection()->set($key, '0');
                return 0;
            }
            return $value;
        }

        $next = $this->incrementByFloat($key, -1 * (float) $amount);
        if ($next < 0) {
            $this->connection()->set($key, '0');
            return 0.0;
        }
        return $next;
    }

    /**
     * Atomically checks all limits before reserving every supplied counter.
     *
     * @param list<array{key: string, amount: int|float, limit: int|float|null, ttl: int}> $counters
     * @return array{allowed: bool, rejected_index?: int}
     */
    public function reserveCounters(array $counters): array
    {
        if ($counters === []) {
            return ['allowed' => true];
        }

        if (!$this->available()) {
            foreach ($counters as $index => $counter) {
                $next = (float) ($this->memoryGet($counter['key']) ?? 0) + (float) $counter['amount'];
                if (($counter['limit'] ?? null) !== null && (float) $counter['limit'] > 0 && $next > (float) $counter['limit']) {
                    return ['allowed' => false, 'rejected_index' => $index];
                }
            }
            foreach ($counters as $counter) {
                $this->memory[$counter['key']] = ['value' => (float) ($this->memoryGet($counter['key']) ?? 0) + (float) $counter['amount'], 'expires_at' => time() + max(1, (int) $counter['ttl'])];
            }
            return ['allowed' => true];
        }

        $keys = array_map(static fn (array $counter): string => $counter['key'], $counters);
        $args = [];
        foreach ($counters as $counter) {
            $args[] = (string) $counter['amount'];
            $args[] = (string) ($counter['limit'] ?? 0);
            $args[] = (string) max(1, (int) $counter['ttl']);
        }
        $script = <<<'LUA'
for i = 1, #KEYS do
  local base = (i - 1) * 3
  local amount = tonumber(ARGV[base + 1])
  local limit = tonumber(ARGV[base + 2])
  local current = tonumber(redis.call('GET', KEYS[i]) or '0')
  if limit > 0 and current + amount > limit then return {0, i} end
end
for i = 1, #KEYS do
  local base = (i - 1) * 3
  redis.call('INCRBYFLOAT', KEYS[i], ARGV[base + 1])
  redis.call('EXPIRE', KEYS[i], tonumber(ARGV[base + 3]))
end
return {1, 0}
LUA;
        $result = $this->evalScript($script, [...$keys, ...$args], count($keys));
        return ['allowed' => (int) ($result[0] ?? 0) === 1, ...((int) ($result[0] ?? 0) === 1 ? [] : ['rejected_index' => max(0, (int) ($result[1] ?? 1) - 1)])];
    }

    public function push(string $queue, array $payload): int
    {
        // PERF-015: jobs sent to the main queue go to their routed sub-queue.
        $queue = (new QueueRouter($this->config))->route($queue, $payload);
        $payload = $this->queuePayload($payload);
        if (!$this->available()) {
            $items = $this->memoryGet('queue:' . $queue) ?: [];
            $items[] = $payload;
            $this->memory['queue:' . $queue] = ['value' => $items, 'expires_at' => null];
            return count($items);
        }
        // LPUSH the job and ring the queue's wake-up list in one round trip,
        // so a worker blocked in reserve() starts at once (PERF-022). The
        // wake-up list is trimmed: it only signals, it never holds jobs.
        $script = <<<'LUA'
local size = redis.call('LPUSH', KEYS[1], ARGV[1])
redis.call('LPUSH', KEYS[2], '1')
redis.call('LTRIM', KEYS[2], 0, 63)
redis.call('EXPIRE', KEYS[2], 3600)
return size
LUA;

        return (int) $this->evalScript($script, [
            $queue,
            QueueRouter::notify($queue),
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], 2);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reserve(string $queue, string $processingQueue, int $timeout = 5, ?string $workerId = null): ?array
    {
        $reserved = $this->reserveAny([$queue => $processingQueue], $timeout, $workerId);
        if ($reserved !== null) {
            unset($reserved['__queue']);
        }

        return $reserved;
    }

    /**
     * Reserves the first available job from several queues (priority order).
     * When all are empty the call blocks on their wake-up lists (BLPOP) for
     * up to $timeout seconds instead of polling with sleep(1) (PERF-022).
     *
     * @param array<string, string> $queues ready queue => processing queue
     * @return array<string, mixed>|null payload with '__queue' = the source queue
     */
    public function reserveAny(array $queues, int $timeout = 5, ?string $workerId = null): ?array
    {
        if ($queues === []) {
            return null;
        }
        $deadline = time() + max(0, $timeout);
        do {
            foreach ($queues as $queue => $processingQueue) {
                $reserved = $this->reserveOnce((string) $queue, $processingQueue, $workerId);
                if ($reserved !== null) {
                    $reserved['__queue'] = (string) $queue;
                    return $reserved;
                }
            }

            $remaining = $deadline - time();
            if ($timeout <= 0 || $remaining <= 0) {
                return null;
            }

            if (!$this->available()) {
                sleep(1);
                continue;
            }
            $this->waitForWork(array_map('strval', array_keys($queues)), $remaining);
        } while (time() < $deadline || $timeout <= 0);

        return null;
    }

    /**
     * Blocks until one of the queues was pushed to (or the timeout passed).
     * Missing a signal is harmless: the caller re-checks the queues and the
     * wait is bounded by $timeout.
     *
     * @param list<string> $queues
     */
    public function waitForWork(array $queues, int $timeout): void
    {
        if ($queues === [] || $timeout <= 0) {
            return;
        }
        $redis = $this->connection();
        $keys = array_map(static fn (string $queue): string => QueueRouter::notify($queue), $queues);
        $previous = (float) $redis->getOption(\Redis::OPT_READ_TIMEOUT);
        if ($previous <= 0) {
            // 0 means "default_socket_timeout" at connect time, but "no wait"
            // when set explicitly: restore the effective value instead.
            $previous = (float) (ini_get('default_socket_timeout') ?: 60);
        }
        try {
            // The socket must wait longer than the blocking command itself.
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, (string) ($timeout + 5));
            $redis->blPop($keys, $timeout);
        } catch (Throwable) {
            usleep(200000);
        } finally {
            try {
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, (string) $previous);
            } catch (Throwable) {
            }
        }
    }

    /**
     * BIZ-005: moves a reserved job that failed into "<queue>:delayed" (a
     * sorted set scored by the time it may run again) in one atomic step.
     */
    public function delayReserved(string $processingQueue, string $reservedPayload, string $queue, array $payload, int $delaySeconds, ?int $now = null): bool
    {
        unset($payload['__reserved_payload'], $payload['__queue'], $payload['reserved_at'], $payload['worker_id'], $payload['lease_until']);
        $payload = $this->queuePayload($payload);
        $runAt = ($now ?? time()) + max(0, $delaySeconds);
        $payload['available_at'] = $runAt;
        $encoded = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $delayedKey = QueueRouter::delayed($queue);
        if (!$this->available()) {
            if ($reservedPayload !== '' && $this->ackReserved($processingQueue, $reservedPayload) !== 1) {
                return false;
            }
            $delayed = $this->memoryGet('zset:' . $delayedKey) ?: [];
            $delayed[$encoded] = $runAt;
            $this->memory['zset:' . $delayedKey] = ['value' => $delayed, 'expires_at' => null];
            return true;
        }
        if ($reservedPayload === '') {
            $this->connection()->zAdd($delayedKey, $runAt, $encoded);
            return true;
        }
        $script = <<<'LUA'
local removed = redis.call('LREM', KEYS[1], 1, ARGV[1])
if removed == 1 then
  redis.call('ZADD', KEYS[2], ARGV[3], ARGV[2])
end
return removed
LUA;

        return (int) $this->evalScript($script, [$processingQueue, $delayedKey, $reservedPayload, $encoded, (string) $runAt], 2) === 1;
    }

    /**
     * Moves delayed jobs whose time has come back to the ready queue.
     */
    public function promoteDelayed(string $queue, ?int $now = null, int $limit = 100): int
    {
        $now ??= time();
        $delayedKey = QueueRouter::delayed($queue);
        if (!$this->available()) {
            $delayed = $this->memoryGet('zset:' . $delayedKey) ?: [];
            $moved = 0;
            asort($delayed);
            foreach ($delayed as $encoded => $score) {
                if ($score > $now || $moved >= $limit) {
                    break;
                }
                unset($delayed[$encoded]);
                $job = json_decode((string) $encoded, true);
                if (is_array($job)) {
                    $items = $this->memoryGet('queue:' . $queue) ?: [];
                    $items[] = $job;
                    $this->memory['queue:' . $queue] = ['value' => $items, 'expires_at' => null];
                    $moved++;
                }
            }
            $this->memory['zset:' . $delayedKey] = ['value' => $delayed, 'expires_at' => null];
            return $moved;
        }
        $script = <<<'LUA'
local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, tonumber(ARGV[2]))
local moved = 0
for _, raw in ipairs(due) do
  if redis.call('ZREM', KEYS[1], raw) == 1 then
    redis.call('LPUSH', KEYS[2], raw)
    moved = moved + 1
  end
end
if moved > 0 then
  redis.call('LPUSH', KEYS[3], '1')
  redis.call('LTRIM', KEYS[3], 0, 63)
end
return moved
LUA;

        return (int) $this->evalScript($script, [$delayedKey, $queue, QueueRouter::notify($queue), (string) $now, (string) max(1, $limit)], 3);
    }

    public function delayedLength(string $queue): int
    {
        $key = QueueRouter::delayed($queue);
        if (!$this->available()) {
            return count($this->memoryGet('zset:' . $key) ?: []);
        }

        return (int) $this->connection()->zCard($key);
    }

    public function ackReserved(string $processingQueue, string $reservedPayload): int
    {
        if (!$this->available()) {
            $items = $this->memoryGet('queue:' . $processingQueue) ?: [];
            $removed = 0;
            $remaining = [];
            foreach ($items as $item) {
                if ($removed === 0 && $this->encodeQueuePayload($item) === $reservedPayload) {
                    $removed = 1;
                    continue;
                }
                $remaining[] = $item;
            }
            $this->memory['queue:' . $processingQueue] = ['value' => $remaining, 'expires_at' => null];
            return $removed;
        }

        return (int) $this->connection()->lRem($processingQueue, $reservedPayload, 1);
    }

    /**
     * Atomically moves a reserved job back to the ready queue (retry).
     *
     * The previous implementation acked first and pushed second, so a crash
     * between the two calls lost the job. Both steps now run in one Lua
     * script: the job is pushed only if this worker still owned the reserved
     * copy, so a concurrent requeueExpired() cannot duplicate it.
     */
    public function releaseReserved(string $processingQueue, string $reservedPayload, string $queue, array $payload): bool
    {
        unset($payload['__reserved_payload'], $payload['__queue'], $payload['reserved_at'], $payload['worker_id'], $payload['lease_until']);

        return $this->moveReserved($processingQueue, $reservedPayload, $queue, $this->queuePayload($payload));
    }

    /**
     * Atomically moves a reserved job to another list (e.g. the failed queue).
     */
    public function moveReserved(string $processingQueue, string $reservedPayload, string $targetQueue, array $targetPayload): bool
    {
        $encoded = json_encode($targetPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!$this->available()) {
            if ($reservedPayload !== '' && $this->ackReserved($processingQueue, $reservedPayload) !== 1) {
                return false;
            }
            $this->push($targetQueue, $targetPayload);
            return true;
        }
        if ($reservedPayload === '') {
            $this->connection()->lPush($targetQueue, (string) $encoded);
            return true;
        }

        $script = <<<'LUA'
local removed = redis.call('LREM', KEYS[1], 1, ARGV[1])
if removed == 1 then
  redis.call('LPUSH', KEYS[2], ARGV[2])
end
return removed
LUA;

        return (int) $this->evalScript($script, [$processingQueue, $targetQueue, $reservedPayload, (string) $encoded], 2) === 1;
    }

    /**
     * Extends the lease of a job that is still running. Returns the new raw
     * reserved payload (needed for ack), or null if the job is no longer
     * owned by this worker (it was requeued after an expired lease).
     */
    public function renewReserved(string $processingQueue, string $reservedPayload, ?int $now = null): ?string
    {
        $job = json_decode($reservedPayload, true);
        if (!is_array($job)) {
            return null;
        }
        $job['reserved_at'] = $now ?? time();
        $renewed = (string) json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!$this->available()) {
            if ($this->ackReserved($processingQueue, $reservedPayload) !== 1) {
                return null;
            }
            $processing = $this->memoryGet('queue:' . $processingQueue) ?: [];
            array_unshift($processing, $job);
            $this->memory['queue:' . $processingQueue] = ['value' => $processing, 'expires_at' => null];
            return $this->encodeQueuePayload($job);
        }

        $script = <<<'LUA'
local removed = redis.call('LREM', KEYS[1], 1, ARGV[1])
if removed == 1 then
  redis.call('LPUSH', KEYS[1], ARGV[2])
end
return removed
LUA;

        return (int) $this->evalScript($script, [$processingQueue, $reservedPayload, $renewed], 1) === 1 ? $renewed : null;
    }

    public function requeueExpired(string $processingQueue, string $queue, int $retryAfterSeconds, ?int $now = null): int
    {
        $now ??= time();
        $moved = 0;

        foreach ($this->queueItems($processingQueue) as $raw => $job) {
            $reservedAt = (int) ($job['reserved_at'] ?? 0);
            $lease = max($retryAfterSeconds, (int) ($job['lease_seconds'] ?? 0));
            if ($reservedAt <= 0 || ($now - $reservedAt) < $lease) {
                continue;
            }

            unset($job['__reserved_payload'], $job['reserved_at'], $job['worker_id']);
            $job['requeued_count'] = (int) ($job['requeued_count'] ?? 0) + 1;
            if ($this->moveReserved($processingQueue, (string) $raw, $queue, $job)) {
                $moved++;
            }
        }

        return $moved;
    }

    public function length(string $queue): int
    {
        if (!$this->available()) {
            return count($this->memoryGet('queue:' . $queue) ?: []);
        }

        return (int) $this->connection()->lLen($queue);
    }

    public function pop(string $queue, int $timeout = 5): ?array
    {
        if (!$this->available()) {
            $items = $this->memoryGet('queue:' . $queue) ?: [];
            $payload = array_pop($items) ?: null;
            $this->memory['queue:' . $queue] = ['value' => $items, 'expires_at' => null];
            return $payload;
        }
        $result = $this->connection()->brPop([$queue], $timeout);

        if (!is_array($result) || !isset($result[1])) {
            return null;
        }

        $payload = json_decode((string) $result[1], true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reserveOnce(string $queue, string $processingQueue, ?string $workerId): ?array
    {
        $workerId ??= gethostname() . ':' . getmypid();

        if (!$this->available()) {
            $items = $this->memoryGet('queue:' . $queue) ?: [];
            $payload = array_pop($items);
            $this->memory['queue:' . $queue] = ['value' => $items, 'expires_at' => null];
            if (!is_array($payload)) {
                return null;
            }

            $payload = $this->reservedPayload($payload, $workerId);
            $processing = $this->memoryGet('queue:' . $processingQueue) ?: [];
            array_unshift($processing, $payload);
            $this->memory['queue:' . $processingQueue] = ['value' => $processing, 'expires_at' => null];
            $payload['__reserved_payload'] = $this->encodeQueuePayload($payload);
            return $payload;
        }

        $script = <<<'LUA'
local raw = redis.call('RPOP', KEYS[1])
if not raw then
  return nil
end
local ok, job = pcall(cjson.decode, raw)
if not ok or type(job) ~= 'table' then
  job = {payload = raw}
end
if not job['job_id'] then
  job['job_id'] = ARGV[2]
end
if not job['attempts'] then
  job['attempts'] = 0
end
if not job['queued_at'] then
  job['queued_at'] = ARGV[1]
end
job['reserved_at'] = tonumber(ARGV[1])
job['worker_id'] = ARGV[3]
local encoded = cjson.encode(job)
redis.call('LPUSH', KEYS[2], encoded)
return encoded
LUA;

        $reserved = $this->evalScript($script, [
            $queue,
            $processingQueue,
            time(),
            $this->newJobId(),
            $workerId,
        ], 2);

        if (!is_string($reserved) || $reserved === '') {
            return null;
        }

        $payload = json_decode($reserved, true);
        if (!is_array($payload)) {
            return null;
        }
        $payload['__reserved_payload'] = $reserved;
        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function reservedPayload(array $payload, string $workerId): array
    {
        $payload = $this->queuePayload($payload);
        $payload['reserved_at'] = time();
        $payload['worker_id'] = $workerId;
        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function queuePayload(array $payload): array
    {
        if (!isset($payload['request_context']) || !is_array($payload['request_context'])) {
            // BIZ-008: the job carries the ids of the request that queued it;
            // the worker adopts them while it runs, so logs line up.
            try {
                $payload['request_context'] = App::context()->propagation();
            } catch (Throwable) {
            }
        }
        $payload['job_id'] ??= $this->newJobId();
        $payload['attempts'] ??= 0;
        $payload['queued_at'] ??= time();
        return $payload;
    }

    private function newJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function encodeQueuePayload(array $payload): string
    {
        unset($payload['__reserved_payload']);
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function queueItems(string $queue): array
    {
        if (!$this->available()) {
            $out = [];
            foreach (($this->memoryGet('queue:' . $queue) ?: []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $raw = $this->encodeQueuePayload($item);
                $item['__reserved_payload'] = $raw;
                $out[$raw] = $item;
            }
            return $out;
        }

        $rows = $this->connection()->lRange($queue, 0, -1);
        $out = [];
        foreach ($rows === false ? [] : $rows as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                continue;
            }
            $payload['__reserved_payload'] = $raw;
            $out[$raw] = $payload;
        }
        return $out;
    }

    public function health(): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'error' => 'redis_extension_missing'];
        }
        try {
            $pong = $this->connection()->ping();

            return ['ok' => $pong === true || $pong === '+PONG' || $pong === 'PONG'];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'redis_unavailable'];
        }
    }

    public function isHealthy(): bool
    {
        return (bool) ($this->health()['ok'] ?? false);
    }

    public function healthy(): array
    {
        return $this->health();
    }

    private function memoryGet(string $key): mixed
    {
        if (!array_key_exists($key, $this->memory)) {
            return null;
        }

        $entry = $this->memory[$key];
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return $entry;
        }

        if (($entry['expires_at'] ?? null) !== null && (int) $entry['expires_at'] <= time()) {
            unset($this->memory[$key]);
            return null;
        }

        return $entry['value'];
    }

    private function memoryExpiresAt(string $key): ?int
    {
        $entry = $this->memory[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $expiresAt = $entry['expires_at'] ?? null;
        return $expiresAt === null ? null : (int) $expiresAt;
    }
}
