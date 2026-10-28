<?php

declare(strict_types=1);

namespace TokenHub\Console;

use TokenHub\Services\WorkerService;
use TokenHub\Support\Config;
use TokenHub\Support\Logger;
use TokenHub\Support\RedisClient;
use Throwable;

final class QueueWorker
{
    public function __construct(
        private readonly Config $config,
        private readonly RedisClient $redis,
        private readonly Logger $logger,
    ) {
    }

    public function run(bool $once = false): void
    {
        $queue = (string) $this->config->get('queue.name', 'tokenhub:jobs');
        $processingQueue = $queue . ':processing';
        $failedQueue = (string) $this->config->get('queue.failed_name', 'tokenhub:failed-jobs');
        $maxAttempts = (int) $this->config->get('queue.max_attempts', 3);
        $retryAfterSeconds = (int) $this->config->get('queue.retry_after_seconds', 30);
        $workerId = gethostname() . ':' . getmypid();
        $ragRecoveryInterval = (int) $this->config->get('rag.embedding.recovery_interval_seconds', 5);
        $lastRagRecoveryAt = 0;

        do {
            $this->recordHeartbeat();
            if (time() - $lastRagRecoveryAt >= $ragRecoveryInterval) {
                (new WorkerService())->recoverRagProviderJobs();
                $lastRagRecoveryAt = time();
            }
            $requeued = $this->redis->requeueExpired($processingQueue, $queue, $retryAfterSeconds);
            if ($requeued > 0) {
                $this->logger->warning('Queue expired jobs requeued', ['count' => $requeued]);
            }

            $job = $this->redis->reserve($queue, $processingQueue, 5, $workerId);
            if ($job === null) {
                $worker = new WorkerService();
                $worker->handle(['type' => 'webhook.enqueue-due']);
                continue;
            }

            $attempts = (int) ($job['attempts'] ?? 0) + 1;
            $job['attempts'] = $attempts;
            $reservedPayload = (string) ($job['__reserved_payload'] ?? '');
            unset($job['__reserved_payload']);

            try {
                (new WorkerService())->handle($job);
                if ($reservedPayload !== '') {
                    $this->redis->ackReserved($processingQueue, $reservedPayload);
                }
                $this->logger->info('Queue job processed', ['job' => $job]);
            } catch (Throwable $throwable) {
                $this->logger->error('Queue job failed', [
                    'job' => $job,
                    'error' => $throwable->getMessage(),
                ]);

                if ($attempts < $maxAttempts) {
                    $this->redis->releaseReserved($processingQueue, $reservedPayload, $queue, $job);
                } else {
                    if ($reservedPayload !== '') {
                        $this->redis->ackReserved($processingQueue, $reservedPayload);
                    }
                    $this->redis->push($failedQueue, [
                        'job' => $job,
                        'failed_at' => gmdate('c'),
                        'error' => $throwable->getMessage(),
                    ]);
                }
            }
        } while (!$once);
    }

    private function recordHeartbeat(): void
    {
        try {
            $this->redis->set('operations:worker:queue-worker', gmdate('c'), 30);
        } catch (Throwable) {
        }
    }

}
