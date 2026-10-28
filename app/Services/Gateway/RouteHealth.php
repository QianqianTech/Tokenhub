<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Support\App;

/**
 * Per-channel circuit breaker shared through Redis.
 *
 * closed     normal routing.
 * open       GATEWAY_CIRCUIT_FAILURES consecutive retryable failures (429,
 *            5xx, timeouts) opened it; the channel is skipped while another
 *            candidate exists, for GATEWAY_CIRCUIT_COOLDOWN_SECONDS.
 * half_open  cooldown elapsed: the next request is allowed as a probe; a
 *            success closes the circuit, a failure re-opens it.
 *
 * Client errors (400/401/403/422) never count: they say nothing about the
 * channel's health.
 */
final class RouteHealth
{
    private const PREFIX = 'route_health:';

    public function state(int $modelId, ?int $now = null): string
    {
        $now ??= time();
        $data = $this->read($modelId);
        $openUntil = (int) ($data['open_until'] ?? 0);
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
        return ['state' => $this->state($modelId)] + $this->read($modelId);
    }

    public function recordSuccess(int $modelId): void
    {
        if ($modelId <= 0) {
            return;
        }
        $this->write($modelId, ['failures' => 0, 'open_until' => 0, 'last_success_at' => time()] + $this->read($modelId));
    }

    public function recordFailure(int $modelId, string $reason, ?int $now = null): void
    {
        if ($modelId <= 0) {
            return;
        }
        $now ??= time();
        $data = $this->read($modelId);
        $failures = (int) ($data['failures'] ?? 0) + 1;
        $threshold = max(1, (int) env_value('GATEWAY_CIRCUIT_FAILURES', 5));
        $cooldown = max(1, (int) env_value('GATEWAY_CIRCUIT_COOLDOWN_SECONDS', 30));
        $wasHalfOpen = $this->state($modelId, $now) === 'half_open';
        $data['failures'] = $failures;
        $data['last_failure_at'] = $now;
        $data['last_failure_reason'] = $reason;
        if ($failures >= $threshold || $wasHalfOpen) {
            $data['open_until'] = $now + $cooldown;
            $data['opened_at'] = $now;
        }
        $this->write($modelId, $data);
    }

    /**
     * Orders candidates: closed and half-open channels keep their priority,
     * open channels move to the end so they are only used as a last resort.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public function order(array $candidates): array
    {
        $healthy = [];
        $open = [];
        foreach ($candidates as $candidate) {
            $state = $this->state((int) ($candidate['id'] ?? 0));
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

        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $modelId): array
    {
        try {
            $value = App::redis()->get(self::PREFIX . $modelId);
        } catch (Throwable) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(int $modelId, array $data): void
    {
        try {
            App::redis()->set(self::PREFIX . $modelId, $data, 86400);
        } catch (Throwable) {
        }
    }
}
