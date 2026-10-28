<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Services\ApiKeyModelQuotaService;
use TokenHub\Services\QuotaService;
use TokenHub\Support\App;

/**
 * Owns every budget reservation a gateway request holds and guarantees exactly
 * one terminal transition.
 *
 * States: open -> rejected   (never reached the upstream: everything refunded,
 *                             including the request counter)
 *         open -> settled    (upstream was attempted: reservations replaced by
 *                             actual usage, request counter kept)
 * Both transitions release concurrency slots. Repeated calls are no-ops, so a
 * finally block, a shutdown function and the normal path can all call
 * finalize without double refunds or negative counters.
 *
 * Process death: reservations are mirrored to a Redis lease (see
 * ReservationLeaseStore). If the PHP process is killed before finalizing, the
 * queue worker's sweeper releases concurrency after the lease deadline and
 * keeps the token/cost reservation as the charged estimate.
 */
final class RequestLifecycle
{
    public const STATE_OPEN = 'open';
    public const STATE_REJECTED = 'rejected';
    public const STATE_SETTLED = 'settled';

    private string $state = self::STATE_OPEN;
    private bool $upstreamStarted = false;
    /** @var null|callable(): array<string, mixed> */
    private $usageOnAbort = null;
    private bool $shutdownRegistered = false;

    /**
     * @param array<string, mixed> $apiKey
     * @param list<string> $concurrencyKeys
     * @param list<list<array<string, mixed>>> $quotaReservations
     * @param list<list<array<string, mixed>>> $modelQuotaReservations
     */
    public function __construct(
        private readonly QuotaService $quota,
        private readonly ApiKeyModelQuotaService $modelQuota,
        private readonly array $apiKey,
        private array $concurrencyKeys = [],
        private array $quotaReservations = [],
        private ?array $route = null,
        private array $modelQuotaReservations = [],
        private readonly ?ReservationLeaseStore $leases = null,
        private readonly string $requestId = '',
        private readonly int $leaseSeconds = 0,
    ) {
        $this->persistLease();
    }

    /**
     * @param array<string, mixed> $route
     * @param list<list<array<string, mixed>>> $modelQuotaReservations
     */
    public function attachModelQuota(array $route, array $modelQuotaReservations): void
    {
        $this->route = $route;
        $this->modelQuotaReservations = self::merge($this->modelQuotaReservations, $modelQuotaReservations);
        $this->persistLease();
    }

    /**
     * Failover to another channel: the previous channel's model quota
     * reservation is refunded and the new one takes its place.
     *
     * @param array<string, mixed> $route
     * @param list<list<array<string, mixed>>> $modelQuotaReservations
     */
    public function switchModelQuota(array $route, array $modelQuotaReservations): void
    {
        if ($this->route !== null) {
            $this->modelQuota->rollbackReservations($this->modelQuotaReservations);
        }
        $this->route = $route;
        $this->modelQuotaReservations = $modelQuotaReservations;
        $this->persistLease();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function route(): ?array
    {
        return $this->route;
    }

    /**
     * @param list<list<array<string, mixed>>> $quotaDelta
     * @param list<list<array<string, mixed>>> $modelQuotaDelta
     */
    public function addReservations(array $quotaDelta, array $modelQuotaDelta = []): void
    {
        $this->quotaReservations = self::merge($this->quotaReservations, $quotaDelta);
        $this->modelQuotaReservations = self::merge($this->modelQuotaReservations, $modelQuotaDelta);
        $this->persistLease();
    }

    public function markUpstreamStarted(): void
    {
        $this->upstreamStarted = true;
    }

    public function upstreamStarted(): bool
    {
        return $this->upstreamStarted;
    }

    /**
     * Usage to charge if the process ends (client abort, fatal error) before
     * the normal path settles, e.g. the partial usage observed in a stream.
     *
     * @param callable(): array<string, mixed> $resolver
     */
    public function onAbortUsage(callable $resolver): void
    {
        $this->usageOnAbort = $resolver;
    }

    /**
     * Registers a shutdown hook so exit, fatal errors and client disconnects
     * still finalize. Safe to call more than once.
     */
    public function guardShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            if ($this->state !== self::STATE_OPEN) {
                return;
            }
            try {
                $this->finalizeAbandoned();
            } catch (Throwable) {
            }
        });
    }

    public function state(): string
    {
        return $this->state;
    }

    /**
     * Request ended before any upstream call: refund everything.
     */
    public function reject(): void
    {
        if ($this->state !== self::STATE_OPEN) {
            return;
        }
        $this->state = self::STATE_REJECTED;
        $this->quota->rollbackReservations($this->quotaReservations);
        if ($this->route !== null) {
            $this->modelQuota->rollbackReservations($this->modelQuotaReservations);
        }
        $this->quota->releaseConcurrency($this->concurrencyKeys);
        $this->dropLease();
    }

    /**
     * Request reached the upstream: charge actual usage (possibly zero).
     *
     * @param array<string, mixed> $usage
     */
    public function settle(array $usage): void
    {
        if ($this->state !== self::STATE_OPEN) {
            return;
        }
        $this->state = self::STATE_SETTLED;
        try {
            $this->quota->recordUsage($this->apiKey, $usage, $this->quotaReservations);
            if ($this->route !== null) {
                $this->modelQuota->recordUsage($this->apiKey, $this->route, $usage, $this->modelQuotaReservations);
            }
        } finally {
            $this->quota->releaseConcurrency($this->concurrencyKeys);
            $this->dropLease();
        }
    }

    /**
     * Normal-path fallback used by finally blocks: decide between reject and
     * settle from what is known so far.
     */
    public function finalizeAbandoned(): void
    {
        if ($this->state !== self::STATE_OPEN) {
            return;
        }
        if (!$this->upstreamStarted) {
            $this->reject();
            return;
        }
        $usage = [];
        if (is_callable($this->usageOnAbort)) {
            try {
                $usage = ($this->usageOnAbort)();
            } catch (Throwable) {
                $usage = [];
            }
        }
        $this->settle($usage);
    }

    /**
     * Merges reservation lists so one Redis key appears once with the summed
     * amount; recordUsage computes actual-minus-reserved per counter and would
     * otherwise apply the actual value twice.
     *
     * @param list<list<array<string, mixed>>> $base
     * @param list<list<array<string, mixed>>> $delta
     * @return list<list<array<string, mixed>>>
     */
    public static function merge(array $base, array $delta): array
    {
        if ($delta === []) {
            return $base;
        }
        $index = [];
        foreach ($base as $listIndex => $counters) {
            foreach ($counters as $counterIndex => $counter) {
                if (isset($counter['key'])) {
                    $index[(string) $counter['key']] = [$listIndex, $counterIndex];
                }
            }
        }
        $extra = [];
        foreach ($delta as $counters) {
            foreach ($counters as $counter) {
                $key = (string) ($counter['key'] ?? '');
                if ($key !== '' && isset($index[$key])) {
                    [$listIndex, $counterIndex] = $index[$key];
                    $base[$listIndex][$counterIndex]['amount'] = (float) $base[$listIndex][$counterIndex]['amount'] + (float) $counter['amount'];
                    continue;
                }
                $extra[] = $counter;
            }
        }
        if ($extra !== []) {
            $base[] = $extra;
        }

        return $base;
    }

    private function persistLease(): void
    {
        if ($this->leases === null || $this->requestId === '') {
            return;
        }
        try {
            $this->leases->put($this->requestId, [
                'concurrency_keys' => $this->concurrencyKeys,
                'quota_reservations' => $this->quotaReservations,
                'model_quota_reservations' => $this->modelQuotaReservations,
                'api_key_id' => $this->apiKey['id'] ?? null,
            ], $this->leaseSeconds);
        } catch (Throwable $throwable) {
            App::logger()->warning('quota', 'reservation lease write failed', ['error' => $throwable->getMessage()]);
        }
    }

    private function dropLease(): void
    {
        if ($this->leases === null || $this->requestId === '') {
            return;
        }
        try {
            $this->leases->forget($this->requestId);
        } catch (Throwable) {
        }
    }
}
