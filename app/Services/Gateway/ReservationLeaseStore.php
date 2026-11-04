<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Services\ApiKeyModelQuotaService;
use TokenHub\Services\QuotaService;
use TokenHub\Support\App;
use TokenHub\Support\RedisClient;

/**
 * Crash recovery for request reservations.
 *
 * Each in-flight gateway request writes a lease (its concurrency keys and
 * reservations) with a deadline. A live request renews the deadline while it
 * runs (MT-002: RequestLifecycle::renewLease every
 * GATEWAY_LEASE_RENEW_SECONDS, also during a long stream), so the deadline
 * only passes for a process that died or hung. The queue worker calls
 * sweepExpired() periodically: an expired lease is claimed atomically (only
 * if its deadline is still in the past, so a renewal racing the sweep wins)
 * and ALL its reservations are rolled back: concurrency slots, the request
 * counter and the token/cost reservations of both the general and the model
 * quota. The lost upstream usage is logged for reconciliation.
 *
 * Exactly one party releases a lease: the owner's finalization and the
 * sweeper both go through ZREM on the index and only the one that removed
 * the member acts. An owner that finds its lease already swept (claim()
 * false) must neither release concurrency again nor settle against the
 * rolled-back reservations (RequestLifecycle handles that), so counters are
 * never decremented twice.
 */
final class ReservationLeaseStore
{
    private const INDEX = 'quota:leases';
    private const PREFIX = 'quota:lease:';

    private const CLAIM_EXPIRED_SCRIPT = <<<'LUA'
local score = redis.call('ZSCORE', KEYS[1], ARGV[1])
if not score or tonumber(score) > tonumber(ARGV[2]) then return 0 end
redis.call('ZREM', KEYS[1], ARGV[1])
return 1
LUA;

    private const RENEW_SCRIPT = <<<'LUA'
if not redis.call('ZSCORE', KEYS[1], ARGV[1]) then return 0 end
redis.call('ZADD', KEYS[1], ARGV[2], ARGV[1])
return 1
LUA;

    public function __construct(private readonly ?RedisClient $redis = null)
    {
    }

    /**
     * @param array<string, mixed> $lease
     */
    public function put(string $requestId, array $lease, int $ttlSeconds): bool
    {
        $redis = $this->redis ?? App::redis();
        if (!$redis->available()) {
            return false;
        }
        $ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : (int) env_value('GATEWAY_RESERVATION_LEASE_SECONDS', 900);
        $deadline = time() + $ttlSeconds;
        $lease['deadline'] = $deadline;
        $redis->set(self::PREFIX . $requestId, $lease, $ttlSeconds + 86400);
        $redis->connection()->zAdd(self::INDEX, $deadline, $requestId);

        return true;
    }

    /**
     * Moves the deadline of a live lease. Returns false when the lease is gone
     * (already swept): the caller must not resurrect it.
     */
    public function renew(string $requestId, int $ttlSeconds): bool
    {
        $redis = $this->redis ?? App::redis();
        if (!$redis->available()) {
            return true;
        }
        $result = RedisScript::run(self::RENEW_SCRIPT, [self::INDEX, $requestId, (string) (time() + max(1, $ttlSeconds))], 1, $redis);

        return $result === false ? true : (int) $result === 1;
    }

    /**
     * Takes the lease away from the sweeper. True when this caller removed it
     * (it owns the release), false when it was already swept or never stored.
     */
    public function claim(string $requestId): bool
    {
        $redis = $this->redis ?? App::redis();
        if (!$redis->available()) {
            return true;
        }
        $removed = (int) $redis->connection()->zRem(self::INDEX, $requestId);
        $redis->delete(self::PREFIX . $requestId);

        return $removed === 1;
    }

    public function forget(string $requestId): void
    {
        $this->claim($requestId);
    }

    /**
     * Amounts currently reserved by in-flight requests, per counter key. Lets
     * quota views separate "reserved" from "consumed".
     *
     * @return array<string, float>
     */
    public function inFlightByKey(int $limit = 2000): array
    {
        $redis = $this->redis ?? App::redis();
        if (!$redis->available()) {
            return [];
        }
        try {
            $ids = $redis->connection()->zRangeByScore(self::INDEX, (string) time(), '+inf', ['limit' => [0, $limit]]);
        } catch (Throwable) {
            return [];
        }
        $totals = [];
        foreach ($ids ?: [] as $requestId) {
            $lease = $redis->get(self::PREFIX . (string) $requestId);
            if (!is_array($lease)) {
                continue;
            }
            foreach (['quota_reservations', 'model_quota_reservations'] as $group) {
                foreach (($lease[$group] ?? []) as $counters) {
                    foreach ((array) $counters as $counter) {
                        if (isset($counter['key'], $counter['amount']) && ($counter['metric'] ?? '') !== 'concurrency') {
                            $totals[(string) $counter['key']] = ($totals[(string) $counter['key']] ?? 0.0) + (float) $counter['amount'];
                        }
                    }
                }
            }
        }

        return $totals;
    }

    /**
     * @return int number of expired leases released
     */
    public function sweepExpired(?int $now = null, int $limit = 200): int
    {
        $redis = $this->redis ?? App::redis();
        if (!$redis->available()) {
            return 0;
        }
        $now ??= time();
        $released = 0;
        try {
            $ids = $redis->connection()->zRangeByScore(self::INDEX, '-inf', (string) $now, ['limit' => [0, $limit]]);
        } catch (Throwable) {
            return 0;
        }
        foreach ($ids ?: [] as $requestId) {
            $requestId = (string) $requestId;
            // Atomic: only remove a lease that is still expired (a renewal
            // in between keeps it alive) and only the remover releases it.
            if ((int) RedisScript::run(self::CLAIM_EXPIRED_SCRIPT, [self::INDEX, $requestId, (string) $now], 1, $redis) !== 1) {
                continue;
            }
            $lease = $redis->get(self::PREFIX . $requestId);
            $redis->delete(self::PREFIX . $requestId);
            if (!is_array($lease)) {
                continue;
            }
            $this->rollback($lease);
            $released++;
            App::logger()->warning('quota', 'expired request reservation rolled back', [
                'request_id' => $requestId,
                'api_key_id' => $lease['api_key_id'] ?? null,
                'concurrency_keys' => count($lease['concurrency_keys'] ?? []),
                'quota_reservations' => $lease['quota_reservations'] ?? [],
                'model_quota_reservations' => $lease['model_quota_reservations'] ?? [],
                'rolled_back' => true,
            ]);
        }

        return $released;
    }

    /**
     * @param array<string, mixed> $lease
     */
    private function rollback(array $lease): void
    {
        foreach (($lease['concurrency_keys'] ?? []) as $key) {
            try {
                ($this->redis ?? App::redis())->decrement((string) $key);
            } catch (Throwable) {
            }
        }
        try {
            if (is_array($lease['quota_reservations'] ?? null)) {
                (new QuotaService())->rollbackReservations($lease['quota_reservations']);
            }
            if (is_array($lease['model_quota_reservations'] ?? null)) {
                (new ApiKeyModelQuotaService())->rollbackReservations($lease['model_quota_reservations']);
            }
        } catch (Throwable $throwable) {
            App::logger()->warning('quota', 'expired reservation rollback failed', ['error' => $throwable->getMessage()]);
        }
    }
}
