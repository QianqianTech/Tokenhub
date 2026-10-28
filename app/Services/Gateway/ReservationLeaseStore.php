<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\RedisClient;

/**
 * Crash recovery for request reservations.
 *
 * Each in-flight gateway request writes a lease (its concurrency keys and
 * reservations) with a deadline. Normal finalization deletes it. The queue
 * worker calls sweepExpired() periodically: expired leases belong to
 * processes that died without finalizing, so their concurrency slots are
 * released. Token/cost reservations are left in place as the charged
 * estimate (the upstream may have consumed them) and the lease is logged for
 * reconciliation.
 */
final class ReservationLeaseStore
{
    private const INDEX = 'quota:leases';
    private const PREFIX = 'quota:lease:';

    public function __construct(private readonly ?RedisClient $redis = null)
    {
    }

    /**
     * @param array<string, mixed> $lease
     */
    public function put(string $requestId, array $lease, int $ttlSeconds): void
    {
        $redis = $this->redis ?? App::redis();
        if (!$redis->available()) {
            return;
        }
        $ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : (int) env_value('GATEWAY_RESERVATION_LEASE_SECONDS', 900);
        $deadline = time() + $ttlSeconds;
        $lease['deadline'] = $deadline;
        $redis->set(self::PREFIX . $requestId, $lease, $ttlSeconds + 86400);
        $redis->connection()->zAdd(self::INDEX, $deadline, $requestId);
    }

    public function forget(string $requestId): void
    {
        $redis = $this->redis ?? App::redis();
        if (!$redis->available()) {
            return;
        }
        $redis->delete(self::PREFIX . $requestId);
        $redis->connection()->zRem(self::INDEX, $requestId);
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
            // zRem first: only the worker that removes the member releases it.
            if ((int) $redis->connection()->zRem(self::INDEX, $requestId) !== 1) {
                continue;
            }
            $lease = $redis->get(self::PREFIX . $requestId);
            $redis->delete(self::PREFIX . $requestId);
            if (!is_array($lease)) {
                continue;
            }
            foreach (($lease['concurrency_keys'] ?? []) as $key) {
                $redis->decrement((string) $key);
            }
            $released++;
            App::logger()->warning('quota', 'expired request reservation released', [
                'request_id' => $requestId,
                'api_key_id' => $lease['api_key_id'] ?? null,
                'concurrency_keys' => count($lease['concurrency_keys'] ?? []),
                'kept_reservations' => true,
            ]);
        }

        return $released;
    }
}
