<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class WorkerService
{
    /**
     * @param null|callable(array<string, mixed>): void $queuePublisher
     * @param array<string, callable(array<string, mixed>): void> $handlers extra/overriding job handlers by type (tests, plugins)
     */
    public function __construct(
        private readonly mixed $queuePublisher = null,
        private readonly ?RagEmbeddingJobService $ragEmbeddingJobs = null,
        private readonly array $handlers = [],
    )
    {
    }

    public function handle(array $job): void
    {
        $type = (string) ($job['type'] ?? '');
        if (isset($this->handlers[$type])) {
            ($this->handlers[$type])($job);
            return;
        }
        match ($job['type'] ?? '') {
            'admin.password-reset.email' => (new AdminPasswordResetService())->deliver($job),
            'cleanup.admin-password-resets' => (new AdminPasswordResetService())->cleanup(),
            'test.log' => App::logger()->info('worker', 'Test queue job', ['payload' => $job['payload'] ?? []]),
            'usage.write' => (new UsageService())->write($job['record'] ?? []),
            'export' => $this->runExport((string) ($job['export_id'] ?? '')),
            'cleanup.retention' => $this->cleanupRetention(),
            'cleanup.exports' => $this->cleanupExports(),
            'cleanup.media' => $this->cleanupMedia(),
            'cleanup.external-events' => $this->cleanupExternalEvents(),
            'cleanup.mcp-oauth' => \TokenHub\Support\SchemaInspector::hasColumn('oauth_grants', 'grant_id') ? (new \TokenHub\Services\Mcp\OAuth\OAuthServer(''))->cleanup() : null,
            'cleanup.app-devices' => \TokenHub\Support\SchemaInspector::hasColumn('admin_app_devices', 'refresh_hash') ? (new AppDeviceAuthService())->cleanup() : null,
            'logs.rotate' => $this->rotateLogs($job),
            'logs.archive' => $this->archiveLogs(),
            'logs.cleanup' => $this->cleanupLogs(),
            'alerts.evaluate' => (new AlertService())->evaluate(),
            'webhook.deliver' => (new WebhookService())->processDelivery((string) ($job['delivery_id'] ?? '')),
            'webhook.enqueue-due' => (new WebhookService())->enqueueDue(),
            'rag.parse' => $this->runRagParse($job),
            'rag.vectorize' => $this->runRagVectorize($job),
            'rag.index' => $this->runRagVectorize($job),
            'rag.cleanup' => $this->runRagCleanup(),
            'rag.publish-versions' => App::logger()->info('worker', 'rag document versions published', (new RagDocumentVersionService())->publishPending((int) ($job['limit'] ?? 50))),
            'rag.reconcile' => App::logger()->info('worker', 'rag reconcile completed', array_intersect_key((new RagIndexMaintenanceService())->reconcile(null, true), array_flip(['orphans', 'missing', 'finished_at']))),
            'alerts.notify' => (new AlertNotifier())->deliverDue(),
            'tasks.poll' => (new MultimodalTaskService())->pollProcessing((int) ($job['limit'] ?? 20)),
            'tasks.expire' => (new MultimodalTaskService())->expireStale(),
            // Refunds / true-ups of finished asynchronous tasks (TaskBillingService).
            'tasks.settle' => (new \TokenHub\Services\Billing\TaskBillingService())->settlePending((int) ($job['limit'] ?? 50)),
            'usage.reconcile' => (new UsageReconciliationService())->reconcileRecent((int) ($job['minutes'] ?? 60)),
            'openrouter.reconcile' => (new OpenRouterGenerationService())->reconcilePending((int) ($job['limit'] ?? 50)),
            'catalog.sync' => (new ProviderModelSyncService())->syncProvider((int) ($job['provider_id'] ?? 0), (bool) ($job['apply'] ?? false)),
            // BIZ-018: daily dry-run of every provider's catalog / prices; alerts on drift.
            'catalog.dry-run' => (new ProviderModelSyncService())->dryRunAll(),
            // BIZ-009: batched purges / optional archive of high-volume tables.
            'retention.purge' => (new DataRetentionService())->purge(),
            'retention.archive' => (new DataRetentionService())->archive(),
            // BIZ-010: cost/token drift between usage_records and aggregates.
            'usage.drift-check' => (new UsageReconciliationService())->checkDriftAndAlert((int) ($job['hours'] ?? 24)),
            // BIZ-020: ask the vendor to cancel an expired task.
            'tasks.cancel' => (new MultimodalTaskService())->cancelUpstream((string) ($job['task_id'] ?? '')),
            // Unknown jobs are dead-lettered instead of silently acknowledged.
            default => throw new \RuntimeException('Unsupported job type: ' . (string) ($job['type'] ?? '')),
        };
    }

    private function runExport(string $exportId): void
    {
        (new ExportService())->process($exportId);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function runRagParse(array $job): void
    {
        $uploadId = isset($job['upload_id']) ? (string) $job['upload_id'] : '';
        App::logger()->info('worker', 'rag parse job acknowledged', ['upload_id' => $uploadId]);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function runRagVectorize(array $job): void
    {
        $uploadId = isset($job['upload_id']) ? (string) $job['upload_id'] : '';
        if ($uploadId === '') {
            App::logger()->warning('worker', 'rag vectorize job missing upload_id');
            return;
        }
        $upload = App::db()->first('SELECT * FROM rag_uploads WHERE upload_id = ?', [$uploadId]);
        if ($upload === null) {
            App::logger()->warning('worker', 'rag vectorize upload not found', ['upload_id' => $uploadId]);
            return;
        }
        if (empty($upload['dataset_id'])) {
            App::logger()->warning('worker', 'rag vectorize upload missing dataset_id', ['upload_id' => $uploadId]);
            return;
        }
        $dataset = App::db()->first(
            'SELECT d.*, k.kb_id AS external_kb_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = ?',
            [$upload['dataset_id']]
        );
        if ($dataset === null) {
            App::logger()->warning('worker', 'rag vectorize dataset not found', ['upload_id' => $uploadId, 'dataset_id' => $upload['dataset_id']]);
            return;
        }
        ($this->ragEmbeddingJobs ?? new RagEmbeddingJobService())->processUpload((int) $upload['id'], $dataset);
    }

    private function runRagCleanup(): void
    {
        $summary = (new RagRetentionPolicyService())->cleanupExpired();
        App::logger()->info('worker', 'rag cleanup completed', $summary);
    }

    public function recoverRagProviderJobs(int $limit = 100): int
    {
        $leaseSeconds = (int) App::config()->get('rag.embedding.job_lease_seconds', 900);
        $dispatchLeaseSeconds = (int) App::config()->get('rag.embedding.dispatch_lease_seconds', 60);
        App::db()->execute(
            'UPDATE rag_embedding_jobs SET status = "retrying", locked_at = NULL, lock_token = NULL, dispatched_at = NULL, next_run_at = NULL
             WHERE execution_backend = "php_provider"
               AND status = "running"
               AND (locked_at IS NULL OR TIMESTAMPDIFF(SECOND, locked_at, UTC_TIMESTAMP()) > ?)',
            [$leaseSeconds]
        );
        $rows = App::db()->select(
            'SELECT u.upload_id, MIN(u.id) AS upload_pk
             FROM rag_embedding_jobs j
             JOIN rag_uploads u ON u.id = j.upload_id
             WHERE j.execution_backend = "php_provider"
               AND j.status IN ("queued","retrying")
               AND (j.next_run_at IS NULL OR j.next_run_at <= UTC_TIMESTAMP())
               AND (j.dispatched_at IS NULL OR TIMESTAMPDIFF(SECOND, j.dispatched_at, UTC_TIMESTAMP()) > ?)
             GROUP BY u.upload_id
             ORDER BY upload_pk
             LIMIT ' . max(1, min(1000, $limit)),
            [$dispatchLeaseSeconds]
        );
        foreach ($rows as $row) {
            $message = ['type' => 'rag.vectorize', 'upload_id' => (string) $row['upload_id']];
            if (is_callable($this->queuePublisher)) {
                ($this->queuePublisher)($message);
            } else {
                App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), $message);
            }
            App::db()->execute(
                'UPDATE rag_embedding_jobs SET dispatched_at = UTC_TIMESTAMP() WHERE upload_id = ? AND execution_backend = "php_provider" AND status IN ("queued","retrying") AND (next_run_at IS NULL OR next_run_at <= UTC_TIMESTAMP())',
                [$row['upload_pk']]
            );
        }

        return count($rows);
    }

    private function cleanupRetention(): void
    {
        // Batched (BIZ-025): a large backlog is cleared over several runs
        // instead of one long UPDATE that locks usage_contents.
        $cleaned = 0;
        for ($i = 0; $i < 20; $i++) {
            $changed = App::db()->execute($this->expiredContentCleanupSql());
            $cleaned += $changed;
            if ($changed < 2000) {
                break;
            }
        }
        App::logger()->info('worker', 'content retention cleanup completed', ['cleaned' => $cleaned]);
    }

    public function expiredContentCleanupSql(): string
    {
        return 'UPDATE usage_contents SET encrypted_input = NULL, encrypted_output = NULL, redacted_input = NULL, redacted_output = NULL, cleaned_at = UTC_TIMESTAMP() WHERE cleaned_at IS NULL AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP() LIMIT 2000';
    }

    public function exportDirectory(): string
    {
        return (string) App::config()->get('paths.exports', storage_path('exports'));
    }

    private function cleanupExports(): void
    {
        $expired = App::db()->select('SELECT id, file_path FROM export_tasks WHERE status = "succeeded" AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()');
        foreach ($expired as $task) {
            if (!empty($task['file_path']) && is_file($task['file_path'])) {
                unlink($task['file_path']);
            }
            App::db()->execute('UPDATE export_tasks SET status = "expired" WHERE id = ?', [$task['id']]);
        }
        App::logger()->info('worker', 'export cleanup completed', ['count' => count($expired)]);
    }

    /**
     * BIZ-006: only rows that still point at a file are selected (the
     * storage_path <> '' filter), in batches, and an audit row is written
     * only when a file was really removed. Previously every run re-processed
     * every expired asset ever and audited each one again.
     */
    private function cleanupMedia(): void
    {
        $batch = max(1, (int) App::config()->get('app.media_cleanup_batch', 500));
        $maxBatches = 20;
        $count = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $expired = App::db()->select(
                "SELECT id, storage_path FROM media_assets WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP() AND storage_path <> '' ORDER BY id LIMIT " . $batch
            );
            if ($expired === []) {
                break;
            }
            $count += $this->cleanupExpiredMediaFilesForRows(
                $expired,
                static function (string $file): bool {
                    $storage = null;
                    try {
                        $storage = \TokenHub\Services\Media\MediaStorageManager::forPath($file);
                    } catch (\Throwable) {
                    }
                    if ($storage !== null) {
                        return $storage->delete($file);
                    }
                    if (is_file($file)) {
                        return @unlink($file);
                    }
                    return false;
                },
                static function (int $id, bool $fileDeleted): void {
                    App::db()->execute("UPDATE media_assets SET storage_path = '' WHERE id = ?", [$id]);
                    if ($fileDeleted) {
                        (new AuditService())->write('asset.cleanup', 'media_assets', $id);
                    }
                },
            );
            if (count($expired) < $batch) {
                break;
            }
        }
        App::logger()->info('worker', 'media cleanup completed', ['count' => $count]);
    }

    private function cleanupExternalEvents(): void
    {
        $days = max(1, (int) App::config()->get('app.external_event_retention_days', 90));
        // BIZ-025: batched, index-backed delete instead of one huge DELETE.
        $deleted = (new DataRetentionService())->deleteInBatches('external_api_events', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        App::logger()->info('worker', 'external event cleanup completed', ['deleted' => $deleted, 'retention_days' => $days]);
    }

    /**
     * @param callable(string): (bool|null|void) $deleteFile returns false when nothing was deleted
     * @param callable(int, bool): void $clearStoragePath receives the id and whether a file was deleted
     */
    public function cleanupExpiredMediaFilesForRows(array $rows, callable $deleteFile, callable $clearStoragePath): int
    {
        $count = 0;
        foreach ($rows as $asset) {
            $fileDeleted = false;
            if (!empty($asset['storage_path'])) {
                $fileDeleted = $deleteFile((string) $asset['storage_path']) !== false;
            }
            $clearStoragePath((int) $asset['id'], $fileDeleted);
            $count++;
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function rotateLogs(array $job): void
    {
        $maxSize = (int) ($job['max_size_bytes'] ?? App::config()->get('logging.max_size_bytes', 104857600));
        $rotated = (new LogRetentionService())->rotate(isset($job['log_type']) ? (string) $job['log_type'] : null, $maxSize);
        App::logger()->info('worker', 'log rotation completed', ['count' => count($rotated), 'files' => $rotated]);
    }

    private function archiveLogs(): void
    {
        $policies = App::db()->select('SELECT * FROM log_retention_policies');
        $archives = (new LogRetentionService())->archive($policies);
        foreach ($archives as $archive) {
            App::db()->execute(
                'INSERT INTO log_archive_files (log_type, source_path, archive_path, compressed, encrypted, size_bytes) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE size_bytes = VALUES(size_bytes), deleted_at = NULL',
                [
                    $archive['log_type'],
                    $archive['source_path'],
                    $archive['archive_path'],
                    $archive['compressed'],
                    $archive['encrypted'],
                    $archive['size_bytes'],
                ],
            );
        }
        App::logger()->info('worker', 'log archive completed', ['count' => count($archives)]);
    }

    private function cleanupLogs(): void
    {
        $archives = App::db()->select(
            'SELECT f.id, f.archive_path, f.created_at, p.archive_retention_days
             FROM log_archive_files f
             JOIN log_retention_policies p ON p.log_type = f.log_type
             WHERE f.deleted_at IS NULL'
        );
        $deletedIds = [];
        $count = (new LogRetentionService())->cleanupArchives($archives, null, function (string $path) use ($archives, &$deletedIds): void {
            if (is_file($path)) {
                unlink($path);
            }
            foreach ($archives as $archive) {
                if ((string) $archive['archive_path'] === $path) {
                    $deletedIds[] = (int) $archive['id'];
                }
            }
        });
        foreach (array_unique($deletedIds) as $id) {
            App::db()->execute('UPDATE log_archive_files SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        }
        App::logger()->info('worker', 'log cleanup completed', ['count' => $count]);
    }
}
