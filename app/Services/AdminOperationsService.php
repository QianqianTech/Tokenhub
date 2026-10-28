<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class AdminOperationsService
{
    /** @return array<string, mixed> */
    public function status(): array
    {
        $queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
        $failed = (string) App::config()->get('queue.failed_name', 'tokenhub:failed-jobs');
        $health = (new HealthCheckService(App::db(), App::redis()))->check();
        $health['services']['qdrant'] = ['healthy' => $this->qdrantHealthy()];
        $health['services']['queue-worker'] = ['healthy' => $this->workerHeartbeatHealthy('queue-worker')];
        $health['services']['rag-worker'] = ['healthy' => $this->workerHeartbeatHealthy('rag-worker')];
        $health['healthy'] = !in_array(false, array_map(
            static fn (array $service): bool => (bool) ($service['healthy'] ?? false),
            $health['services'],
        ), true);

        return [
            'health' => $health,
            // null means "could not be read", never "empty".
            'queues' => [
                'pending' => $this->queueLength($queue),
                'processing' => $this->queueLength($queue . ':processing'),
                'failed' => $this->queueLength($failed),
                'readable' => $this->queueLength($queue) !== null,
            ],
            'reservations' => $this->reservationLeases(),
            'alert_notifications' => $this->alertNotificationBacklog(),
            'usage_pipeline' => $this->usagePipeline(),
            'recent_worker_logs' => (new LogService())->query(['log_type' => 'worker', 'limit' => 20]),
        ];
    }

    private function queueLength(string $queue): ?int
    {
        try { return (int) App::redis()->connection()->lLen($queue); } catch (\Throwable) { return null; }
    }

    /** @return array<string, int|null> */
    private function reservationLeases(): array
    {
        try {
            $redis = App::redis()->connection();
            return [
                'active' => (int) $redis->zCard('quota:leases'),
                'expired' => (int) $redis->zCount('quota:leases', '-inf', (string) time()),
            ];
        } catch (\Throwable) {
            return ['active' => null, 'expired' => null];
        }
    }

    /** @return array<string, int|null> */
    private function alertNotificationBacklog(): array
    {
        try {
            if (!\TokenHub\Support\SchemaInspector::hasColumn('alert_notifications', 'status')) {
                return ['pending' => null, 'dead' => null];
            }
            $row = App::db()->first('SELECT SUM(status IN ("pending","retrying")) pending, SUM(status = "dead") dead FROM alert_notifications');
            return ['pending' => (int) ($row['pending'] ?? 0), 'dead' => (int) ($row['dead'] ?? 0)];
        } catch (\Throwable) {
            return ['pending' => null, 'dead' => null];
        }
    }

    /** @return array<string, int|null> */
    private function usagePipeline(): array
    {
        try {
            if (!\TokenHub\Support\SchemaInspector::hasColumn('usage_records', 'aggregated_at')) {
                return ['unaggregated' => null, 'events_pending' => null];
            }
            $row = App::db()->first('SELECT SUM(aggregated_at IS NULL) unaggregated, SUM(events_emitted_at IS NULL) events_pending FROM usage_records WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
            return ['unaggregated' => (int) ($row['unaggregated'] ?? 0), 'events_pending' => (int) ($row['events_pending'] ?? 0)];
        } catch (\Throwable) {
            return ['unaggregated' => null, 'events_pending' => null];
        }
    }

    private function qdrantHealthy(): bool
    {
        try { return (new QdrantVectorStore())->healthy(); } catch (\Throwable) { return false; }
    }

    private function workerHeartbeatHealthy(string $worker): bool
    {
        try { return App::redis()->get('operations:worker:' . $worker) !== null; } catch (\Throwable) { return false; }
    }
}
