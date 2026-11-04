<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Logger;
use TokenHub\Support\QueueRouter;
use TokenHub\Support\SchemaInspector;

final class AlertService
{
    /**
     * @param null|callable(array<string, mixed>): void $writer
     */
    public function __construct(private readonly mixed $writer = null)
    {
    }

    /** @var array<string, true> fingerprints raised during the current evaluate() run */
    private array $raised = [];

    /**
     * Records an alert occurrence.
     *
     * Lifecycle: an occurrence with the same fingerprint as an open or
     * acknowledged event updates that event (occurrence_count, last_seen_at,
     * severity can only rise) instead of creating a new row, so a persisting
     * failure does not flood the table or the on-call channel. New events and
     * severity escalations are notified unless the event is silenced.
     *
     * @param array<string, mixed> $metadata
     */
    public function record(string $eventType, string $severity, string $message, array $metadata = [], ?string $fingerprint = null): void
    {
        $fingerprint ??= $this->fingerprint($eventType, $metadata);
        $this->raised[$fingerprint] = true;
        $event = [
            'event_type' => $eventType,
            'severity' => $severity,
            'message' => Logger::sanitize($message),
            'metadata' => Logger::sanitize($metadata),
            'status' => 'open',
            'fingerprint' => $fingerprint,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        if (is_callable($this->writer)) {
            ($this->writer)($event);
            return;
        }

        $lifecycle = SchemaInspector::hasColumn('alert_events', 'fingerprint');
        if ($lifecycle) {
            $existing = App::db()->first(
                'SELECT id, severity, status, silenced_until FROM alert_events WHERE fingerprint = ? AND status IN ("open","acknowledged") ORDER BY id DESC LIMIT 1',
                [$fingerprint]
            );
            if ($existing !== null) {
                $escalated = $this->rank($severity) > $this->rank((string) $existing['severity']);
                App::db()->execute(
                    'UPDATE alert_events SET occurrence_count = occurrence_count + 1, last_seen_at = UTC_TIMESTAMP(), metadata = ?, message = ?, severity = ? WHERE id = ?',
                    [json_encode($event['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $event['message'], $escalated ? $severity : $existing['severity'], (int) $existing['id']]
                );
                if ($escalated && !$this->silenced($existing)) {
                    (new AlertNotifier())->queue((int) $existing['id'], 'escalated');
                }
                return;
            }
        }

        $columns = ['event_type', 'severity', 'message', 'metadata', 'status'];
        $values = [$event['event_type'], $event['severity'], $event['message'], json_encode($event['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $event['status']];
        if ($lifecycle) {
            array_push($columns, 'fingerprint', 'first_seen_at', 'last_seen_at');
            array_push($values, $fingerprint, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'));
        }
        App::db()->execute(
            'INSERT INTO alert_events (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            $values,
        );
        $id = (int) App::db()->pdo()->lastInsertId();
        App::logger()->info('security', 'alert event recorded', ['event_type' => $eventType, 'severity' => $severity]);
        if ($lifecycle && $this->silencedFingerprint($fingerprint) === false) {
            (new AlertNotifier())->queue($id, 'triggered');
        }
    }

    /**
     * Stable identity of "the same problem": event type plus the subject
     * fields an evaluator reports (provider, queue, certificate, model...).
     *
     * @param array<string, mixed> $metadata
     */
    public function fingerprint(string $eventType, array $metadata): string
    {
        $subject = [];
        foreach (['provider_id', 'model_id', 'queue', 'name', 'dependency', 'rule_code'] as $key) {
            if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                $subject[] = $key . '=' . $metadata[$key];
            }
        }

        return substr($eventType . ($subject === [] ? '' : '|' . implode('|', $subject)), 0, 190);
    }

    /**
     * Operator actions: acknowledge (stop escalation), silence (no
     * notifications until a time), resolve (closed manually).
     *
     * @return array<string, mixed>
     */
    public function transition(int $id, string $action, string $note, ?int $actorId = null, ?string $silenceUntil = null): array
    {
        $row = App::db()->first('SELECT * FROM alert_events WHERE id = ?', [$id]);
        if ($row === null) {
            abort_json('ALERT_NOT_FOUND', 'Alert not found', 404);
        }
        $lifecycle = SchemaInspector::hasColumn('alert_events', 'fingerprint');
        switch ($action) {
            case 'acknowledged':
                App::db()->execute('UPDATE alert_events SET status = "acknowledged"' . ($lifecycle ? ', acknowledged_by = ?, acknowledged_at = UTC_TIMESTAMP()' : '') . ' WHERE id = ?', $lifecycle ? [$actorId, $id] : [$id]);
                break;
            case 'resolved':
                App::db()->execute('UPDATE alert_events SET status = "resolved"' . ($lifecycle ? ', resolved_at = UTC_TIMESTAMP(), resolution = "manual"' : '') . ' WHERE id = ?', [$id]);
                if ($lifecycle) {
                    (new AlertNotifier())->queue($id, 'resolved');
                }
                break;
            case 'silenced':
                if (!$lifecycle) {
                    abort_json('ALERT_SILENCE_UNAVAILABLE', 'Run database migrations to enable alert silencing', 409);
                }
                $until = strtotime((string) $silenceUntil . ' UTC');
                if ($until === false || $until <= time() || $until > time() + 30 * 86400) {
                    abort_json('ALERT_STATUS_INVALID', 'silence_until must be within the next 30 days', 422);
                }
                App::db()->execute('UPDATE alert_events SET silenced_until = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', $until), $id]);
                break;
            default:
                abort_json('ALERT_STATUS_INVALID', 'Unsupported alert action', 422);
        }
        (new AuditService())->write('alert.status.update', 'alert_events', $id, ['status' => $action, 'note' => $note, 'silence_until' => $silenceUntil]);

        return App::db()->first('SELECT * FROM alert_events WHERE id = ?', [$id]) ?: [];
    }

    /**
     * Closes open alerts of evaluated types whose condition did not fire in
     * this evaluation round, and escalates unacknowledged warnings that stay
     * open longer than ALERT_ESCALATE_AFTER_MINUTES (default 30).
     *
     * @param list<string> $evaluatedTypes
     */
    public function resolveCleared(array $evaluatedTypes): int
    {
        if (is_callable($this->writer) || !SchemaInspector::hasColumn('alert_events', 'fingerprint') || $evaluatedTypes === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($evaluatedTypes), '?'));
        $open = App::db()->select("SELECT id, fingerprint FROM alert_events WHERE status IN (\"open\",\"acknowledged\") AND event_type IN ({$placeholders})", $evaluatedTypes);
        $resolved = 0;
        foreach ($open as $row) {
            if (isset($this->raised[(string) $row['fingerprint']])) {
                continue;
            }
            $changed = App::db()->execute('UPDATE alert_events SET status = "resolved", resolved_at = UTC_TIMESTAMP(), resolution = "auto_recovered" WHERE id = ? AND status IN ("open","acknowledged")', [(int) $row['id']]);
            if ($changed === 1) {
                $resolved++;
                (new AlertNotifier())->queue((int) $row['id'], 'resolved');
            }
        }

        $minutes = max(1, (int) env_value('ALERT_ESCALATE_AFTER_MINUTES', 30));
        $stale = App::db()->select(
            'SELECT id FROM alert_events WHERE status = "open" AND severity = "warning" AND escalated_at IS NULL AND (silenced_until IS NULL OR silenced_until < UTC_TIMESTAMP()) AND COALESCE(first_seen_at, created_at) <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)',
            [$minutes]
        );
        foreach ($stale as $row) {
            if (App::db()->execute('UPDATE alert_events SET severity = "critical", escalated_at = UTC_TIMESTAMP() WHERE id = ? AND escalated_at IS NULL', [(int) $row['id']]) === 1) {
                (new AlertNotifier())->queue((int) $row['id'], 'escalated');
            }
        }

        return $resolved;
    }

    private function rank(string $severity): int
    {
        return match ($severity) {
            'critical' => 3,
            'warning' => 2,
            default => 1,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function silenced(array $row): bool
    {
        return !empty($row['silenced_until']) && (strtotime((string) $row['silenced_until'] . ' UTC') ?: 0) > time();
    }

    private function silencedFingerprint(string $fingerprint): bool
    {
        $row = App::db()->first('SELECT silenced_until FROM alert_events WHERE fingerprint = ? AND silenced_until > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1', [$fingerprint]);

        return $row !== null;
    }

    /**
     * @param list<array{name: string, expires_at: string}> $certificates
     * @return list<array<string, mixed>>
     */
    public function certificateExpiryAlerts(array $certificates, ?int $now = null, int $thresholdDays = 14): array
    {
        $now ??= time();
        $events = [];
        foreach ($certificates as $certificate) {
            $expiresAt = strtotime((string) ($certificate['expires_at'] ?? '')) ?: 0;
            if ($expiresAt <= 0 || $expiresAt - $now > $thresholdDays * 86400) {
                continue;
            }
            $event = [
                'event_type' => 'certificate_expiring',
                'severity' => $expiresAt < $now ? 'critical' : 'warning',
                'message' => 'Certificate is expiring soon',
                'metadata' => [
                    'name' => $certificate['name'] ?? 'unknown',
                    'expires_at' => $certificate['expires_at'] ?? null,
                    'days_remaining' => (int) floor(($expiresAt - $now) / 86400),
                ],
            ];
            $events[] = $event;
            $this->record($event['event_type'], $event['severity'], $event['message'], $event['metadata']);
        }
        return $events;
    }

    /** Alert types decided by evaluate(); open ones that did not fire again are auto-resolved. */
    public const EVALUATED_TYPES = [
        'provider_error_rate_high', 'queue_backlog_high', 'queue_metrics_unavailable', 'certificate_expiring',
        'rag_embedding_model_missing', 'rag_embedding_jobs_failed', 'rag_index_drift', 'rag_vector_retrieval_degraded',
        // BIZ-003
        'queue_dead_letter', 'worker_heartbeat_missing', 'usage_aggregation_backlog', 'webhook_delivery_dead', 'quota_nearly_exhausted',
    ];

    public function evaluate(): void
    {
        $this->raised = [];
        $this->providerErrors();
        $this->queueBacklog();
        $this->deadLetters();
        $this->heartbeats();
        $this->usageAggregationBacklog();
        $this->webhookDeadDeliveries();
        $this->quotaNearlyExhausted();
        $this->configuredCertificateExpiry();
        $this->ragIndexHealth();
        $this->resolveCleared(self::EVALUATED_TYPES);
        (new AlertNotifier())->deliverDue();
    }

    /**
     * Local rejections (quota, rate limit, permissions, client cancel) are
     * not vendor failures and must not raise provider_error_rate_high (BIZ-016).
     */
    public const LOCAL_ERROR_CODES = [
        'QUOTA_EXCEEDED', 'MODEL_QUOTA_EXCEEDED', 'CONCURRENCY_EXCEEDED', 'RATE_LIMITED', 'RATE_LIMIT_EXCEEDED',
        'MODEL_FORBIDDEN', 'CONTENT_FORBIDDEN', 'CLIENT_CANCELLED', 'PRICE_UNKNOWN', 'CAPABILITY_UNAVAILABLE',
        'OUTBOUND_BLOCKED', 'PROVIDER_REJECTED', 'BUDGET_EXCEEDED', 'ROUTING_PARAMETER_FORBIDDEN',
    ];

    /** SQL condition: the usage row is a vendor-side failure. */
    public static function providerFailureCondition(): string
    {
        $codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::LOCAL_ERROR_CODES));

        return "status IN ('failed','timeout') AND (error_code IS NULL OR (error_code NOT IN ({$codes}) AND error_code NOT LIKE 'QUOTA%' AND error_code NOT LIKE 'API_KEY%'))";
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function evaluateCertificateSettings(array $settings, ?int $now = null): void
    {
        $certificates = $settings['certificates'] ?? [];
        if (!is_array($certificates)) {
            return;
        }
        $thresholdDays = (int) ($settings['threshold_days'] ?? 14);
        $this->certificateExpiryAlerts(array_values(array_filter($certificates, 'is_array')), $now, $thresholdDays);
    }

    /**
     * @return array<string, mixed>
     */
    private function alertThresholds(): array
    {
        try {
            return (new SystemSettingsService())->loadSection('alert_thresholds');
        } catch (\Throwable) {
            return (new SystemSettingsService())->defaults()['alert_thresholds'];
        }
    }

    private function providerErrors(): void
    {
        $thresholds = $this->alertThresholds();
        $window = max(1, (int) ($thresholds['provider_error_window_minutes'] ?? 5));
        $minRequests = max(1, (int) ($thresholds['provider_min_requests'] ?? 5));
        $threshold = (float) ($thresholds['provider_error_rate'] ?? 0.3);
        // Only requests that reached a vendor count: locally rejected rows
        // have no provider or carry a local error code (BIZ-016).
        $rows = App::db()->select(
            'SELECT provider_id, COUNT(*) total_count, SUM(CASE WHEN ' . self::providerFailureCondition() . ' THEN 1 ELSE 0 END) error_count
             FROM usage_records
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $window . ' MINUTE) AND provider_id IS NOT NULL
               AND (error_code IS NULL OR (error_code NOT IN (' . implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::LOCAL_ERROR_CODES)) . ") AND error_code NOT LIKE 'QUOTA%' AND error_code NOT LIKE 'API_KEY%'))
             GROUP BY provider_id HAVING total_count >= " . $minRequests
        );
        foreach ($rows as $row) {
            $rate = ((int) $row['error_count']) / max(1, (int) $row['total_count']);
            if ($rate >= $threshold) {
                $this->record('provider_error_rate_high', 'warning', 'Provider error rate is high', [
                    'provider_id' => $row['provider_id'],
                    'error_rate' => $rate,
                    'window_minutes' => $window,
                ]);
            }
        }
    }

    private function queueBacklog(): void
    {
        $queue = null;
        try {
            $threshold = max(1, (int) ($this->alertThresholds()['queue_backlog_count'] ?? 1000));
            // PERF-015: every routed sub-queue is checked, not only the main one.
            foreach ((new QueueRouter(App::config()))->allQueues() as $queue) {
                $count = (int) App::redis()->connection()->lLen($queue);
                if ($count >= $threshold) {
                    $this->record('queue_backlog_high', 'warning', 'Queue backlog is high', ['queue' => $queue, 'count' => $count]);
                }
            }
        } catch (\Throwable $throwable) {
            // Unknown is not "zero backlog": surface the monitoring gap itself.
            $this->record('queue_metrics_unavailable', 'warning', 'Queue length could not be read', ['queue' => $queue, 'error' => mb_substr($throwable->getMessage(), 0, 200)]);
        }
    }

    /**
     * BIZ-003 / BIZ-005: the dead-letter list grew since the last round.
     * The alert stays open (and is re-raised) while it keeps growing.
     */
    private function deadLetters(): void
    {
        try {
            $failedQueue = (string) App::config()->get('queue.failed_name', 'tokenhub:failed-jobs');
            $count = (int) App::redis()->connection()->lLen($failedQueue);
            $previous = App::redis()->get('alerts:dead-letter:last-length');
            App::redis()->set('alerts:dead-letter:last-length', (string) $count, 7 * 86400);
            $grown = $previous === null ? 0 : $count - (int) $previous;
            if ($grown > 0) {
                $this->record('queue_dead_letter', $grown >= 10 ? 'critical' : 'warning', 'Jobs were moved to the dead-letter queue', ['queue' => $failedQueue, 'count' => $count, 'new' => $grown]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * BIZ-003: queue worker and scheduler must publish heartbeats; the RAG
     * worker only when Python-backed RAG jobs are waiting for it.
     */
    private function heartbeats(): void
    {
        try {
            $redis = App::redis();
            foreach (['queue-worker', 'scheduler'] as $name) {
                if ($redis->get('operations:worker:' . $name) === null) {
                    $this->record('worker_heartbeat_missing', 'critical', 'Background process heartbeat is missing', ['name' => $name]);
                }
            }
            if ($redis->get('operations:worker:rag-worker') === null && $this->pythonRagJobsWaiting()) {
                $this->record('worker_heartbeat_missing', 'critical', 'Background process heartbeat is missing', ['name' => 'rag-worker']);
            }
        } catch (\Throwable) {
        }
    }

    private function pythonRagJobsWaiting(): bool
    {
        try {
            $row = App::db()->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE execution_backend <> "php_provider" AND status IN ("queued","retrying") AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)');
            return (int) ($row['c'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** BIZ-003: usage rows that were written but not folded into the aggregates. */
    private function usageAggregationBacklog(): void
    {
        try {
            if (!SchemaInspector::hasColumn('usage_records', 'aggregated_at')) {
                return;
            }
            $row = App::db()->first('SELECT COUNT(*) AS c FROM usage_records WHERE aggregated_at IS NULL AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)');
            $count = (int) ($row['c'] ?? 0);
            if ($count >= max(1, (int) env_value('ALERT_USAGE_BACKLOG_COUNT', 100))) {
                $this->record('usage_aggregation_backlog', 'warning', 'Usage records are waiting to be aggregated', ['count' => $count]);
            }
        } catch (\Throwable) {
        }
    }

    /** BIZ-003: webhook deliveries that exhausted their retries in the last day. */
    private function webhookDeadDeliveries(): void
    {
        try {
            $row = App::db()->first('SELECT COUNT(*) AS c FROM external_webhook_deliveries WHERE status = "dead" AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)');
            $count = (int) ($row['c'] ?? 0);
            if ($count > 0) {
                $this->record('webhook_delivery_dead', 'warning', 'Webhook deliveries failed permanently', ['count' => $count]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * BIZ-003: day / week / month quota bindings that used at least
     * ALERT_QUOTA_RATIO (default 0.9) of a token, request or cost limit.
     */
    private function quotaNearlyExhausted(): void
    {
        try {
            $ratio = (float) env_value('ALERT_QUOTA_RATIO', 0.9);
            if ($ratio <= 0 || $ratio > 1) {
                return;
            }
            $rows = App::db()->select(
                'SELECT p.id, p.name, p.period, p.request_limit, p.total_token_limit, p.cost_limit, b.target_type, b.target_id
                 FROM quota_policies p JOIN quota_bindings b ON b.quota_policy_id = p.id
                 WHERE p.status = "active" AND p.period IN ("day","week","month")
                 ORDER BY p.id LIMIT 500'
            );
            $quota = new QuotaService();
            foreach ($rows as $row) {
                foreach (['total_token_limit' => 'total_tokens', 'request_limit' => 'request_count', 'cost_limit' => 'cost_amount'] as $limitColumn => $metric) {
                    $limit = (float) ($row[$limitColumn] ?? 0);
                    if ($limit <= 0) {
                        continue;
                    }
                    $counter = $quota->usageCounter((int) $row['id'], (string) $row['target_type'], (int) $row['target_id'], (string) $row['period'], $metric);
                    $used = (float) (App::redis()->get($counter['key']) ?? 0) / max(1, (int) $counter['scale']);
                    if ($used >= $limit * $ratio) {
                        $this->record('quota_nearly_exhausted', $used >= $limit ? 'critical' : 'warning', 'Quota is nearly exhausted', [
                            'name' => (string) $row['name'] . ':' . $row['target_type'] . ':' . $row['target_id'] . ':' . $metric,
                            'used' => $used,
                            'limit' => $limit,
                        ]);
                    }
                }
            }
        } catch (\Throwable) {
        }
    }

    /** RAG index alerts (RAG-043): unbound embedding models, failing jobs, vector-store drift. */
    private function ragIndexHealth(): void
    {
        try {
            $unbound = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_vector_configs WHERE enabled = 1 AND (provider_id IS NULL OR model_id IS NULL)')['c'] ?? 0);
            if ($unbound > 0) {
                $this->record('rag_embedding_model_missing', 'warning', 'RAG vector configs without an embedding model', ['count' => $unbound]);
            }
            $failed = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE status = "failed" AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 MINUTE)')['c'] ?? 0);
            if ($failed > 0) {
                $this->record('rag_embedding_jobs_failed', 'warning', 'RAG vectorization jobs failed in the last hour', ['count' => $failed]);
            }
            if (\TokenHub\Support\SchemaInspector::hasColumn('rag_retrieval_logs', 'vector_status')) {
                // V-04: the embedding model or vector store failed during retrieval; answers fell back to keywords.
                $degraded = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_retrieval_logs WHERE vector_status IN ("degraded","error") AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 MINUTE)')['c'] ?? 0);
                if ($degraded > 0) {
                    $this->record('rag_vector_retrieval_degraded', 'warning', 'RAG retrievals ran without vector results in the last hour (embedding model or vector store failing)', ['count' => $degraded]);
                }
            }
            $report = storage_path('rag' . DIRECTORY_SEPARATOR . 'maintenance' . DIRECTORY_SEPARATOR . 'last-reconcile.json');
            if (is_file($report)) {
                $last = json_decode((string) file_get_contents($report), true);
                $drift = (int) ($last['orphans'] ?? 0) + (int) ($last['missing'] ?? 0);
                if (is_array($last) && empty($last['fix']) && $drift > 0) {
                    $this->record('rag_index_drift', 'warning', 'RAG vector store differs from the database', ['orphans' => (int) ($last['orphans'] ?? 0), 'missing' => (int) ($last['missing'] ?? 0)]);
                }
            }
        } catch (\Throwable) {
            // RAG tables absent (fresh install before migrations): nothing to report.
        }
    }

    private function configuredCertificateExpiry(): void
    {
        try {
            $row = App::db()->first('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['tls_certificates']);
            if (!$row) {
                return;
            }
            $settings = is_string($row['setting_value'] ?? null) ? json_decode((string) $row['setting_value'], true) : $row['setting_value'];
            if (is_array($settings)) {
                $this->evaluateCertificateSettings($settings);
            }
        } catch (\Throwable) {
        }
    }
}
