<?php

declare(strict_types=1);

namespace TokenHub\Console;

use TokenHub\Services\Gateway\ReservationLeaseStore;
use TokenHub\Services\WorkerService;
use TokenHub\Support\App;
use TokenHub\Support\Config;
use TokenHub\Support\ConfigVersion;
use TokenHub\Support\Logger;
use TokenHub\Support\QueueRouter;
use TokenHub\Support\RedisClient;
use Throwable;

/**
 * The single reliable queue consumer (bin/queue-worker.php and the legacy
 * scripts/worker.php both run this loop).
 *
 * Contract
 * - queues: one worker consumes the sub-queues in queue.worker_queues
 *   (usage, main, tasks, export, maintenance by default: all of them).
 * - reserve: RPOP + LPUSH processing in one Lua script (RedisClient::reserve);
 *   an idle worker blocks on the queues' wake-up lists instead of polling.
 * - success: LREM from processing (ack).
 * - failure: retry after an exponential back-off (delayed sorted set) or
 *   dead-letter, each via one atomic script, so a crash between "ack" and
 *   "push" can no longer lose the job.
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

    public function run(bool $once = false, ?string $queuesSpec = null): void
    {
        $router = new QueueRouter($this->config);
        $mainQueue = $router->mainQueue();
        $queues = [];
        foreach ($router->consumedQueues($queuesSpec) as $name) {
            $queues[$name] = QueueRouter::processing($name);
        }
        $failedQueue = (string) $this->config->get('queue.failed_name', 'tokenhub:failed-jobs');
        $maxAttempts = (int) $this->config->get('queue.max_attempts', 3);
        $retryAfterSeconds = (int) $this->config->get('queue.retry_after_seconds', 30);
        $blockTimeout = max(1, (int) $this->config->get('queue.block_timeout_seconds', 5));
        // PERF-022: the processing lists are scanned every 15-30 s, not on every job.
        $requeueInterval = max(15, min(30, (int) $this->config->get('queue.requeue_interval_seconds', 20)));
        $workerId = gethostname() . ':' . getmypid();
        $ragRecoveryInterval = (int) $this->config->get('rag.embedding.recovery_interval_seconds', 5);
        $consumesMain = array_key_exists($mainQueue, $queues);
        $lastRagRecoveryAt = 0;
        $lastLeaseSweepAt = 0;
        $lastMaintenanceAt = 0;
        $lastRequeueAt = 0;
        $lastPromoteAt = 0;
        $this->logger->info('Queue worker started', ['queues' => array_keys($queues), 'worker_id' => $workerId]);

        do {
            ConfigVersion::refresh();
            $this->recordHeartbeat();
            if ($consumesMain && time() - $lastRagRecoveryAt >= $ragRecoveryInterval) {
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
                    $this->redis->push($mainQueue, $maintenanceJob);
                }
                $lastMaintenanceAt = time();
            }
            if (time() - $lastPromoteAt >= 1) {
                // BIZ-005: retries whose back-off elapsed become ready again.
                foreach (array_keys($queues) as $name) {
                    $this->guard(fn () => $this->redis->promoteDelayed((string) $name));
                }
                $lastPromoteAt = time();
            }
            if ($once || time() - $lastRequeueAt >= $requeueInterval) {
                foreach ($queues as $name => $processing) {
                    $requeued = $this->redis->requeueExpired($processing, (string) $name, $retryAfterSeconds);
                    if ($requeued > 0) {
                        $this->logger->warning('Queue expired jobs requeued', ['count' => $requeued, 'queue' => $name]);
                    }
                }
                $lastRequeueAt = time();
            }

            $job = $this->redis->reserveAny($queues, $once ? 0 : $blockTimeout, $workerId);
            if ($job === null) {
                if ($consumesMain) {
                    $this->guard(fn () => ($this->worker ?? new WorkerService())->handle(['type' => 'webhook.enqueue-due']));
                }
                continue;
            }

            $source = (string) ($job['__queue'] ?? $mainQueue);
            unset($job['__queue']);
            $this->process($job, $source, $failedQueue, $maxAttempts, $retryAfterSeconds);
        } while (!$once);
    }

    /**
     * Back-off before attempt n+1 (BIZ-005): base * 2^(attempts-1), capped.
     */
    public function backoffSeconds(int $attempts): int
    {
        $base = max(0, (int) $this->config->get('queue.backoff_base_seconds', 30));
        $cap = max($base, (int) $this->config->get('queue.backoff_max_seconds', 3600));
        if ($base === 0) {
            return 0;
        }

        return (int) min($cap, $base * (2 ** max(0, min(20, $attempts - 1))));
    }

    /**
     * @param array<string, mixed> $job
     */
    public function process(array $job, string $queue, string $failedQueue, int $maxAttempts, int $retryAfterSeconds): void
    {
        $this->processingQueue = QueueRouter::processing($queue);
        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $job['attempts'] = $attempts;
        $this->currentReserved = (string) ($job['__reserved_payload'] ?? '');
        unset($job['__reserved_payload'], $job['__queue']);
        $lease = max($retryAfterSeconds, (int) ($job['lease_seconds'] ?? 0));
        $this->startLeaseRenewal($lease);
        // BIZ-008: logs written while the job runs carry the ids of the
        // request that queued it.
        $carried = is_array($job['request_context'] ?? null) ? $job['request_context'] : [];
        $previousContext = App::context()->adopt(
            isset($carried['request_id']) ? (string) $carried['request_id'] : 'job_' . (string) ($job['job_id'] ?? ''),
            isset($carried['trace_id']) ? (string) $carried['trace_id'] : null,
        );

        try {
            ($this->worker ?? new WorkerService())->handle($job);
            $this->stopLeaseRenewal();
            if ($this->currentReserved !== '') {
                $this->redis->ackReserved($this->processingQueue, $this->currentReserved);
            }
            $this->logger->info('Queue job processed', ['job_id' => $job['job_id'] ?? null, 'type' => $job['type'] ?? null, 'attempts' => $attempts, 'queue' => $queue]);
        } catch (Throwable $throwable) {
            $this->stopLeaseRenewal();
            $permanent = str_starts_with($throwable->getMessage(), 'Unsupported job type');
            $this->logger->error('Queue job failed', [
                'job_id' => $job['job_id'] ?? null,
                'type' => $job['type'] ?? null,
                'attempts' => $attempts,
                'queue' => $queue,
                'error' => $throwable->getMessage(),
            ]);

            if ($attempts < $maxAttempts && !$permanent) {
                $job['last_error'] = mb_substr($throwable->getMessage(), 0, 500);
                $delay = $this->backoffSeconds($attempts);
                if ($delay > 0) {
                    $this->redis->delayReserved($this->processingQueue, $this->currentReserved, $queue, $job, $delay);
                } else {
                    $this->redis->releaseReserved($this->processingQueue, $this->currentReserved, $queue, $job);
                }
            } else {
                $this->redis->moveReserved($this->processingQueue, $this->currentReserved, $failedQueue, [
                    'job' => $job,
                    'queue' => $queue,
                    'failed_at' => gmdate('c'),
                    'error' => mb_substr($throwable->getMessage(), 0, 1000),
                    'exception' => $throwable::class,
                ]);
                // Dead letters are alerted on (AlertService: queue_dead_letter).
                $this->guard(fn () => $this->redis->increment('queue:dead-letter:total'));
            }
        } finally {
            $this->currentReserved = '';
            App::context()->restore($previousContext);
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
            // A long job (export, large RAG upload) must not look like a dead worker.
            $this->recordHeartbeat();
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
