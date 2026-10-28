<?php

declare(strict_types=1);

namespace TokenHub\Console;

use TokenHub\Services\Gateway\ReservationLeaseStore;
use TokenHub\Services\WorkerService;
use TokenHub\Support\Config;
use TokenHub\Support\ConfigVersion;
use TokenHub\Support\Logger;
use TokenHub\Support\RedisClient;
use Throwable;

/**
 * The single reliable queue consumer (bin/queue-worker.php and the legacy
 * scripts/worker.php both run this loop).
 *
 * Contract
 * - reserve: RPOP + LPUSH processing in one Lua script (RedisClient::reserve).
 * - success: LREM from processing (ack).
 * - failure: retry or dead-letter via one atomic LREM+LPUSH script, so a
 *   crash between "ack" and "push" can no longer lose the job.
 * - lease: reserved_at + max(queue.retry_after_seconds, job.lease_seconds).
 *   While a job runs, the lease is renewed every lease/3 seconds (SIGALRM
 *   when pcntl is available); an expired lease means the worker died and
 *   requeueExpired() hands the job to another worker.
 * - jobs must be idempotent by business key (usage.write by request_id,
 *   tasks by task_id, webhooks by delivery_id): redelivery is at-least-once.
 */
final class QueueWorker
{
    private string $currentReserved = '';
    private string $processingQueue = '';

    public function __construct(
        private readonly Config $config,
        private readonly RedisClient $redis,
        private readonly Logger $logger,
        private readonly ?WorkerService $worker = null,
    ) {
    }

    public function run(bool $once = false): void
    {
        $queue = (string) $this->config->get('queue.name', 'tokenhub:jobs');
        $this->processingQueue = $queue . ':processing';
        $failedQueue = (string) $this->config->get('queue.failed_name', 'tokenhub:failed-jobs');
        $maxAttempts = (int) $this->config->get('queue.max_attempts', 3);
        $retryAfterSeconds = (int) $this->config->get('queue.retry_after_seconds', 30);
        $workerId = gethostname() . ':' . getmypid();
        $ragRecoveryInterval = (int) $this->config->get('rag.embedding.recovery_interval_seconds', 5);
        $lastRagRecoveryAt = 0;
        $lastLeaseSweepAt = 0;
        $lastMaintenanceAt = 0;

        do {
            ConfigVersion::refresh();
            $this->recordHeartbeat();
            if (time() - $lastRagRecoveryAt >= $ragRecoveryInterval) {
                $this->guard(fn () => ($this->worker ?? new WorkerService())->recoverRagProviderJobs());
                $lastRagRecoveryAt = time();
            }
            if (time() - $lastLeaseSweepAt >= 15) {
                $this->guard(fn () => (new ReservationLeaseStore($this->redis))->sweepExpired());
                $lastLeaseSweepAt = time();
            }
            if (time() - $lastMaintenanceAt >= (int) $this->config->get('queue.maintenance_interval_seconds', 60) && $this->claimMaintenance()) {
                // Periodic closure of open loops: async task polling/expiry,
                // usage stage recovery, OpenRouter reconciliation, alerts.
                foreach ([['type' => 'tasks.poll'], ['type' => 'tasks.expire'], ['type' => 'tasks.settle'], ['type' => 'usage.reconcile', 'minutes' => 120], ['type' => 'openrouter.reconcile'], ['type' => 'alerts.evaluate']] as $maintenanceJob) {
                    $this->redis->push($queue, $maintenanceJob);
                }
                $lastMaintenanceAt = time();
            }
            $requeued = $this->redis->requeueExpired($this->processingQueue, $queue, $retryAfterSeconds);
            if ($requeued > 0) {
                $this->logger->warning('Queue expired jobs requeued', ['count' => $requeued]);
            }

            $job = $this->redis->reserve($queue, $this->processingQueue, $once ? 0 : 5, $workerId);
            if ($job === null) {
                $this->guard(fn () => ($this->worker ?? new WorkerService())->handle(['type' => 'webhook.enqueue-due']));
                continue;
            }

            $this->process($job, $queue, $failedQueue, $maxAttempts, $retryAfterSeconds);
        } while (!$once);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function process(array $job, string $queue, string $failedQueue, int $maxAttempts, int $retryAfterSeconds): void
    {
        $this->processingQueue = $queue . ':processing';
        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $job['attempts'] = $attempts;
        $this->currentReserved = (string) ($job['__reserved_payload'] ?? '');
        unset($job['__reserved_payload']);
        $lease = max($retryAfterSeconds, (int) ($job['lease_seconds'] ?? 0));
        $this->startLeaseRenewal($lease);

        try {
            ($this->worker ?? new WorkerService())->handle($job);
            $this->stopLeaseRenewal();
            if ($this->currentReserved !== '') {
                $this->redis->ackReserved($this->processingQueue, $this->currentReserved);
            }
            $this->logger->info('Queue job processed', ['job_id' => $job['job_id'] ?? null, 'type' => $job['type'] ?? null, 'attempts' => $attempts]);
        } catch (Throwable $throwable) {
            $this->stopLeaseRenewal();
            $this->logger->error('Queue job failed', [
                'job_id' => $job['job_id'] ?? null,
                'type' => $job['type'] ?? null,
                'attempts' => $attempts,
                'error' => $throwable->getMessage(),
            ]);

            if ($attempts < $maxAttempts) {
                $job['last_error'] = mb_substr($throwable->getMessage(), 0, 500);
                $this->redis->releaseReserved($this->processingQueue, $this->currentReserved, $queue, $job);
            } else {
                $this->redis->moveReserved($this->processingQueue, $this->currentReserved, $failedQueue, [
                    'job' => $job,
                    'failed_at' => gmdate('c'),
                    'error' => mb_substr($throwable->getMessage(), 0, 1000),
                    'exception' => $throwable::class,
                ]);
            }
        } finally {
            $this->currentReserved = '';
        }
    }

    private function startLeaseRenewal(int $leaseSeconds): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_alarm') || $this->currentReserved === '') {
            return;
        }
        $interval = max(1, intdiv($leaseSeconds, 3));
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($interval): void {
            if ($this->currentReserved === '') {
                return;
            }
            try {
                $renewed = $this->redis->renewReserved($this->processingQueue, $this->currentReserved);
                if ($renewed !== null) {
                    $this->currentReserved = $renewed;
                } else {
                    $this->logger->warning('Queue lease lost while job was running', []);
                }
            } catch (Throwable) {
            }
            pcntl_alarm($interval);
        });
        pcntl_alarm($interval);
    }

    private function stopLeaseRenewal(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }

    /**
     * Only one worker in the cluster enqueues maintenance per interval.
     */
    private function claimMaintenance(): bool
    {
        try {
            $interval = max(10, (int) $this->config->get('queue.maintenance_interval_seconds', 60));
            return (bool) $this->redis->connection()->set('queue:maintenance:lock', gethostname() . ':' . getmypid(), ['nx', 'ex' => $interval]);
        } catch (Throwable) {
            return false;
        }
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            $this->logger->warning('Queue maintenance step failed', ['error' => $throwable->getMessage()]);
        }
    }

    private function recordHeartbeat(): void
    {
        // Local file for the container health check (docker/php/healthcheck.sh worker).
        @touch((string) (getenv('TOKENHUB_WORKER_HEARTBEAT_FILE') ?: sys_get_temp_dir() . '/tokenhub-queue-worker.heartbeat'));

        try {
            $this->redis->set('operations:worker:queue-worker', gmdate('c'), 30);
        } catch (Throwable) {
        }
    }
}
