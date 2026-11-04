<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;

/**
 * Live updates for the TokenHub APP while it is in the foreground (Server-Sent
 * Events over an authenticated fetch): open alerts within ~2 s, today's usage
 * every 10 s, service health every 15 s. In the background the APP closes the
 * stream and Web Push takes over.
 *
 * A stream occupies one PHP-FPM worker, so streams are short (default 55 s,
 * the APP reconnects at once) and capped across the cluster and per device
 * with a Redis sorted set; over the cap the APP falls back to polling.
 */
final class AppRealtimeService
{
    private const SLOTS_KEY = 'tokenhub:app:streams';

    /** @var null|callable(): int */
    private $clock;

    /** @var null|callable(int): void */
    private $sleeper;

    public function __construct(?callable $clock = null, ?callable $sleeper = null)
    {
        $this->clock = $clock;
        $this->sleeper = $sleeper;
    }

    public function streamSeconds(): int
    {
        return max(15, min(300, (int) App::config()->get('app.app_client.stream_seconds', 55)));
    }

    /**
     * Reserves a stream slot; null when the cluster-wide or per-device limit
     * is reached (or Redis is unavailable).
     */
    public function acquireSlot(string $streamId, int $deviceId): ?string
    {
        $max = max(1, (int) App::config()->get('app.app_client.stream_max_connections', 8));
        $perDevice = max(1, (int) App::config()->get('app.app_client.stream_max_per_device', 2));
        $member = 'd' . $deviceId . ':' . $streamId;
        try {
            $redis = App::redis()->connection();
            $now = $this->now();
            $redis->zRemRangeByScore(self::SLOTS_KEY, '-inf', (string) $now);
            $members = $redis->zRange(self::SLOTS_KEY, 0, -1) ?: [];
            if (count($members) >= $max) {
                return null;
            }
            $mine = array_filter($members, static fn (string $item): bool => str_starts_with($item, 'd' . $deviceId . ':'));
            if (count($mine) >= $perDevice) {
                // The oldest stream of this device (e.g. a closed tab) gives way.
                $redis->zRem(self::SLOTS_KEY, (string) reset($mine));
            }
            $redis->zAdd(self::SLOTS_KEY, $now + $this->streamSeconds() + 30, $member);
            $redis->expire(self::SLOTS_KEY, $this->streamSeconds() + 60);

            return $member;
        } catch (Throwable) {
            return null;
        }
    }

    public function releaseSlot(?string $member): void
    {
        if ($member === null) {
            return;
        }
        try {
            App::redis()->connection()->zRem(self::SLOTS_KEY, $member);
        } catch (Throwable) {
            // expires on its own
        }
    }

    /** True while this stream's slot exists (a newer stream of the device may take it over). */
    private function slotHeld(?string $member): bool
    {
        if ($member === null) {
            return true;
        }
        try {
            return App::redis()->connection()->zScore(self::SLOTS_KEY, $member) !== false;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Runs one stream until it expires, the client goes away or the session ends.
     *
     * @param array<string, mixed> $user public admin user
     * @param callable(string, array<string, mixed>): void $emit sends one event
     * @param callable(): bool $alive false once the client disconnected
     * @param callable(): bool $sessionValid false once the access token/device is revoked
     */
    public function run(array $user, callable $emit, callable $alive, callable $sessionValid, ?string $slot = null): string
    {
        $permissions = new PermissionService();
        $canAlerts = $permissions->hasCapability($user, 'logs.read');
        $canUsage = $permissions->hasCapability($user, 'dashboard.read');
        $canHealth = $permissions->hasCapability($user, 'operations.read');
        $deadline = $this->now() + $this->streamSeconds();
        $emit('ready', ['alerts' => $canAlerts, 'usage' => $canUsage, 'health' => $canHealth, 'seconds' => $this->streamSeconds()]);

        $alertSignature = null;
        $lastAlertId = null;
        $next = ['alerts' => 0, 'usage' => 0, 'health' => 0, 'session' => $this->now() + 15, 'ping' => $this->now() + 15];
        while ($this->now() < $deadline) {
            $now = $this->now();
            if ($canAlerts && $now >= $next['alerts']) {
                $next['alerts'] = $now + 2;
                $summary = $this->alertSummary();
                $signature = $summary['count'] . '/' . $summary['max_id'] . '/' . $summary['changed_at'];
                if ($signature !== $alertSignature) {
                    $newIds = $lastAlertId === null ? [] : array_values(array_filter(
                        array_map(static fn (array $row): int => (int) $row['id'], $summary['latest']),
                        static fn (int $id): bool => $id > $lastAlertId
                    ));
                    $emit('alerts', ['count' => $summary['count'], 'latest' => $summary['latest'], 'new_ids' => $newIds]);
                    $alertSignature = $signature;
                    $lastAlertId = max((int) $lastAlertId, $summary['max_id']);
                }
            }
            if ($canUsage && $now >= $next['usage']) {
                $next['usage'] = $now + 10;
                try {
                    $emit('usage', (new DashboardService())->realtime([]));
                } catch (Throwable) {
                    // next round
                }
            }
            if ($canHealth && $now >= $next['health']) {
                $next['health'] = $now + 15;
                try {
                    $status = (new AdminOperationsService())->status();
                    $emit('health', ['health' => $status['health'] ?? [], 'queues' => $status['queues'] ?? [], 'usage_pipeline' => $status['usage_pipeline'] ?? []]);
                } catch (Throwable) {
                    // next round
                }
            }
            if ($now >= $next['session']) {
                $next['session'] = $now + 15;
                if (!$sessionValid()) {
                    $emit('end', ['reason' => 'signed_out']);
                    return 'signed_out';
                }
                if (!$this->slotHeld($slot)) {
                    $emit('end', ['reason' => 'replaced']);
                    return 'replaced';
                }
            }
            if ($now >= $next['ping']) {
                $next['ping'] = $now + 15;
                $emit('ping', ['t' => $now]);
            }
            if (!$alive()) {
                return 'disconnected';
            }
            $this->sleep(1);
        }
        $emit('end', ['reason' => 'rotate']);

        return 'rotate';
    }

    /**
     * @return array{count: int, max_id: int, changed_at: string, latest: list<array<string, mixed>>}
     */
    private function alertSummary(): array
    {
        $row = App::db()->first('SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_id, COALESCE(MAX(updated_at), "") AS changed_at FROM alert_events WHERE status = "open"') ?? [];
        $latest = App::db()->select('SELECT id, event_type, severity, message, status, created_at FROM alert_events WHERE status = "open" ORDER BY id DESC LIMIT 3');

        return [
            'count' => (int) ($row['total'] ?? 0),
            'max_id' => (int) ($row['max_id'] ?? 0),
            'changed_at' => (string) ($row['changed_at'] ?? ''),
            'latest' => array_map(static fn (array $alert): array => [
                'id' => (int) $alert['id'],
                'event_type' => (string) $alert['event_type'],
                'severity' => (string) $alert['severity'],
                'message' => mb_substr((string) $alert['message'], 0, 160),
                'created_at' => (string) $alert['created_at'],
            ], $latest),
        ];
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function sleep(int $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
            return;
        }
        sleep($seconds);
    }
}
