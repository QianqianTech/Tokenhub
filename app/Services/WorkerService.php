<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class WorkerService
{
    /** @param null|callable(array<string, mixed>): void $queuePublisher */
    public function __construct(
        private readonly mixed $queuePublisher = null,
        private readonly ?RagEmbeddingJobService $ragEmbeddingJobs = null,
    )
    {
    }

    public function handle(array $job): void
    {
        match ($job['type'] ?? '') {
            'test.log' => App::logger()->info('worker', 'Test queue job', ['payload' => $job['payload'] ?? []]),
            'usage.write' => (new UsageService())->write($job['record'] ?? []),
            'export' => $this->runExport((string) ($job['export_id'] ?? '')),
            'waf.import' => (new WafService())->ingestSecurityLog(isset($job['file']) ? (string) $job['file'] : null, is_array($job['options'] ?? null) ? $job['options'] : []),
            'waf.auto_disposition' => (new WafService())->autoDisposition(is_array($job['options'] ?? null) ? $job['options'] : []),
            'cleanup.retention' => $this->cleanupRetention(),
            'cleanup.exports' => $this->cleanupExports(),
            'cleanup.media' => $this->cleanupMedia(),
            'cleanup.external-events' => $this->cleanupExternalEvents(),
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
            default => App::logger()->warning('worker', 'Unknown job type', $job),
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
               AND (locked_at IS NULL OR TIMESTAMPDIFF(SECOND, locked_at, NOW()) > ?)',
            [$leaseSeconds]
        );
        $rows = App::db()->select(
            'SELECT u.upload_id, MIN(u.id) AS upload_pk
             FROM rag_embedding_jobs j
             JOIN rag_uploads u ON u.id = j.upload_id
             WHERE j.execution_backend = "php_provider"
               AND j.status IN ("queued","retrying")
               AND (j.next_run_at IS NULL OR j.next_run_at <= NOW())
               AND (j.dispatched_at IS NULL OR TIMESTAMPDIFF(SECOND, j.dispatched_at, NOW()) > ?)
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
                'UPDATE rag_embedding_jobs SET dispatched_at = NOW() WHERE upload_id = ? AND execution_backend = "php_provider" AND status IN ("queued","retrying") AND (next_run_at IS NULL OR next_run_at <= NOW())',
                [$row['upload_pk']]
            );
        }

        return count($rows);
    }

    private function exportCsv(array $task): string
    {
        $type = $task['export_type'];
        $rows = match ($type) {
            'logs' => App::db()->select('SELECT id, actor_type, action, target_type, target_id, request_id, client_ip, created_at FROM audit_logs ORDER BY id DESC LIMIT 10000'),
            'session_archive' => App::db()->select('SELECT r.id, r.request_id, r.user_id, r.department_id, r.model_name, r.status, c.retention_mode, c.cleaned_at, r.created_at FROM usage_records r LEFT JOIN usage_contents c ON c.usage_record_id = r.id ORDER BY r.id DESC LIMIT 10000'),
            default => App::db()->select('SELECT id, request_id, user_id, department_id, api_key_id, model_name, status, input_tokens, output_tokens, total_tokens, cost_amount, created_at FROM usage_records ORDER BY id DESC LIMIT 10000'),
        };
        if ($rows === []) {
            return "empty\n";
        }
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value), $row));
        }
        rewind($out);
        return stream_get_contents($out) ?: '';
    }

    private function cleanupRetention(): void
    {
        App::db()->execute($this->expiredContentCleanupSql());
        App::logger()->info('worker', 'content retention cleanup completed');
    }

    public function expiredContentCleanupSql(): string
    {
        return 'UPDATE usage_contents SET encrypted_input = NULL, encrypted_output = NULL, redacted_input = NULL, redacted_output = NULL, cleaned_at = NOW() WHERE cleaned_at IS NULL AND expires_at IS NOT NULL AND expires_at < NOW()';
    }

    public function exportDirectory(): string
    {
        return (string) App::config()->get('paths.exports', storage_path('exports'));
    }

    private function cleanupExports(): void
    {
        $expired = App::db()->select('SELECT id, file_path FROM export_tasks WHERE status = "succeeded" AND expires_at IS NOT NULL AND expires_at < NOW()');
        foreach ($expired as $task) {
            if (!empty($task['file_path']) && is_file($task['file_path'])) {
                unlink($task['file_path']);
            }
            App::db()->execute('UPDATE export_tasks SET status = "expired" WHERE id = ?', [$task['id']]);
        }
        App::logger()->info('worker', 'export cleanup completed', ['count' => count($expired)]);
    }

    private function cleanupMedia(): void
    {
        $expired = App::db()->select('SELECT id, storage_path FROM media_assets WHERE expires_at IS NOT NULL AND expires_at < NOW()');
        $count = $this->cleanupExpiredMediaFilesForRows(
            $expired,
            static function (string $file): void {
                if (is_file($file)) {
                    unlink($file);
                }
            },
            static function (int $id): void {
                App::db()->execute('UPDATE media_assets SET storage_path = "" WHERE id = ?', [$id]);
                (new AuditService())->write('asset.cleanup', 'media_assets', $id);
            },
        );
        App::logger()->info('worker', 'media cleanup completed', ['count' => $count]);
    }

    private function cleanupExternalEvents(): void
    {
        $days = max(1, (int) App::config()->get('app.external_event_retention_days', 90));
        $deleted = App::db()->execute('DELETE FROM external_api_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
        App::logger()->info('worker', 'external event cleanup completed', ['deleted' => $deleted, 'retention_days' => $days]);
    }

    public function cleanupExpiredMediaFilesForRows(array $rows, callable $deleteFile, callable $clearStoragePath): int
    {
        $count = 0;
        foreach ($rows as $asset) {
            if (!empty($asset['storage_path'])) {
                $deleteFile((string) $asset['storage_path']);
            }
            $clearStoragePath((int) $asset['id']);
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
            App::db()->execute('UPDATE log_archive_files SET deleted_at = NOW() WHERE id = ?', [$id]);
        }
        App::logger()->info('worker', 'log cleanup completed', ['count' => $count]);
    }
}
