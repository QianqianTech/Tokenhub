<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\Config;
use TokenHub\Support\JsonLogger;
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
        $queue = (string) $this->config->get('queue.name', 'tokenhub:jobs');
        $processingQueue = $queue . ':processing';
        $retryAfterSeconds = (int) $this->config->get('queue.retry_after_seconds', 30);

        $this->redis->requeueExpired($processingQueue, $queue, $retryAfterSeconds);
        $job = $this->redis->reserve($queue, $processingQueue, 5, gethostname() . ':' . getmypid());

        if ($job === null) {
            return;
        }

        $reservedPayload = (string) ($job['__reserved_payload'] ?? '');
        unset($job['__reserved_payload']);
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
                $this->redis->releaseReserved($processingQueue, $reservedPayload, $queue, $job);
                return;
            }

            if ($reservedPayload !== '') {
                $this->redis->ackReserved($processingQueue, $reservedPayload);
            }
            $this->redis->push((string) $this->config->get('queue.failed_name', 'tokenhub:failed'), $job);
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function handle(array $job): void
    {
        $type = $job['type'] ?? null;

        if ($type === 'health_check_test') {
            $this->logger->info('worker', 'Health check test job consumed.', [
                'payload' => $job['payload'] ?? [],
            ]);
            return;
        }

        if ($type === 'usage.write' && isset($job['record']) && is_array($job['record'])) {
            (new UsageService())->write($job['record']);
            return;
        }

        if ($type === 'export' && isset($job['export_id'])) {
            (new ExportService())->process((string) $job['export_id']);
            return;
        }

        throw new \RuntimeException('Unsupported job type.');
    }

}
