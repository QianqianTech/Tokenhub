<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\Config;
use TokenHub\Support\JsonLogger;
use TokenHub\Support\QueueRouter;
use TokenHub\Support\RedisClient;

final class QueueWorker
{
    public function __construct(
        private readonly Config $config,
        private readonly RedisClient $redis,
        private readonly JsonLogger $logger,
    ) {
    }

    public function runForever(): void
    {
        while (true) {
            $this->runOnce();
        }
    }

    public function runOnce(): void
    {
        $router = new QueueRouter($this->config);
        $queues = [];
        foreach ($router->consumedQueues() as $name) {
            $queues[$name] = QueueRouter::processing($name);
        }
        $retryAfterSeconds = (int) $this->config->get('queue.retry_after_seconds', 30);

        foreach ($queues as $name => $processing) {
            $this->redis->promoteDelayed($name);
            $this->redis->requeueExpired($processing, $name, $retryAfterSeconds);
        }
        $job = $this->redis->reserveAny($queues, max(1, (int) $this->config->get('queue.block_timeout_seconds', 5)), gethostname() . ':' . getmypid());

        if ($job === null) {
            return;
        }

        $queue = (string) ($job['__queue'] ?? $router->mainQueue());
        $processingQueue = QueueRouter::processing($queue);
        $reservedPayload = (string) ($job['__reserved_payload'] ?? '');
        unset($job['__reserved_payload'], $job['__queue']);
        $attempt = (int) ($job['attempts'] ?? $job['attempt'] ?? 0) + 1;
        $job['attempts'] = $attempt;
        $maxAttempts = (int) $this->config->get('queue.max_attempts', 3);

        try {
            $this->handle($job);
            if ($reservedPayload !== '') {
                $this->redis->ackReserved($processingQueue, $reservedPayload);
            }
            $this->logger->info('worker', 'Job processed.', [
                'job_type' => $job['type'] ?? 'unknown',
                'attempt' => $attempt,
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('worker', 'Job failed.', [
                'job_type' => $job['type'] ?? 'unknown',
                'attempt' => $attempt,
                'error' => $exception->getMessage(),
            ]);

            if ($attempt < $maxAttempts) {
                // Same exponential back-off as Console\QueueWorker (BIZ-005).
                $base = max(0, (int) $this->config->get('queue.backoff_base_seconds', 30));
                $delay = (int) min(max($base, (int) $this->config->get('queue.backoff_max_seconds', 3600)), $base * (2 ** max(0, min(20, $attempt - 1))));
                if ($delay > 0) {
                    $this->redis->delayReserved($processingQueue, $reservedPayload, $queue, $job, $delay);
                } else {
                    $this->redis->releaseReserved($processingQueue, $reservedPayload, $queue, $job);
                }
                return;
            }

            // Atomic dead-letter move (same contract as Console\QueueWorker).
            $this->redis->moveReserved($processingQueue, $reservedPayload, (string) $this->config->get('queue.failed_name', 'tokenhub:failed-jobs'), [
                'job' => $job,
                'queue' => $queue,
                'failed_at' => gmdate('c'),
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }
    }

    /**
     * Legacy entry point kept for embedding; dispatch is shared with
     * Console\QueueWorker through WorkerService so both accept the same jobs.
     *
     * @param array<string, mixed> $job
     */
    private function handle(array $job): void
    {
        if (($job['type'] ?? null) === 'health_check_test') {
            $this->logger->info('worker', 'Health check test job consumed.', [
                'payload' => $job['payload'] ?? [],
            ]);
            return;
        }

        (new WorkerService())->handle($job);
    }

}
