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
        $failed = (string) App::config()->get('queue.failed_name', 'tokenhub:failed');
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
            'queues' => [
                'pending' => $this->queueLength($queue),
                'processing' => $this->queueLength($queue . ':processing'),
                'failed' => $this->queueLength($failed),
            ],
            'recent_worker_logs' => (new LogService())->query(['log_type' => 'worker', 'limit' => 20]),
        ];
    }

    private function queueLength(string $queue): int
    {
        try { return (int) App::redis()->connection()->lLen($queue); } catch (\Throwable) { return 0; }
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
