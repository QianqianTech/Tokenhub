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
        $redis->connect(
            (string) $this->config->get('redis.host', '127.0.0.1'),
            (int) $this->config->get('redis.port', 6379),
            (float) $this->config->get('redis.timeout', 2.0),
        );

        $password = (string) $this->config->get('redis.password', '');
        if ($password !== '') {
            $redis->auth($password);
        }

        $database = (int) $this->config->get('redis.database', 0);
        if ($database > 0) {
            $redis->select($database);
        }

        $this->redis = $redis;

        return $this->redis;
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
            $result = $this->connection()->eval($script, [$key, $limitOrTtlSeconds, $ttlSeconds], 1);
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
        return (float) $this->connection()->eval($script, [$key, (string) $amount, $ttlSeconds], 1);
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
        $result = $this->connection()->eval($script, [...$keys, ...$args], count($keys));
        return ['allowed' => (int) ($result[0] ?? 0) === 1, ...((int) ($result[0] ?? 0) === 1 ? [] : ['rejected_index' => max(0, (int) ($result[1] ?? 1) - 1)])];
    }

    public function push(string $queue, array $payload): int
    {
        $payload = $this->queuePayload($payload);
        if (!$this->available()) {
            $items = $this->memoryGet('queue:' . $queue) ?: [];
            $items[] = $payload;
            $this->memory['queue:' . $queue] = ['value' => $items, 'expires_at' => null];
            return count($items);
        }
        return (int) $this->connection()->lPush($queue, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reserve(string $queue, string $processingQueue, int $timeout = 5, ?string $workerId = null): ?array
    {
        $deadline = time() + max(0, $timeout);
        do {
            $reserved = $this->reserveOnce($queue, $processingQueue, $workerId);
            if ($reserved !== null) {
                return $reserved;
            }

            if ($timeout <= 0 || time() >= $deadline) {
                return null;
            }

            sleep(1);
        } while (true);
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

    public function releaseReserved(string $processingQueue, string $reservedPayload, string $queue, array $payload): void
    {
        $this->ackReserved($processingQueue, $reservedPayload);
        unset($payload['__reserved_payload'], $payload['reserved_at'], $payload['worker_id']);
        $this->push($queue, $payload);
    }

    public function requeueExpired(string $processingQueue, string $queue, int $retryAfterSeconds, ?int $now = null): int
    {
        $now ??= time();
        $moved = 0;

        foreach ($this->queueItems($processingQueue) as $raw => $job) {
            $reservedAt = (int) ($job['reserved_at'] ?? 0);
            if ($reservedAt <= 0 || ($now - $reservedAt) < $retryAfterSeconds) {
                continue;
            }

            $this->ackReserved($processingQueue, $raw);
            unset($job['__reserved_payload'], $job['reserved_at'], $job['worker_id']);
            $this->push($queue, $job);
            $moved++;
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

        $reserved = $this->connection()->eval($script, [
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
