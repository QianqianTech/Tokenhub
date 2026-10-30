<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Logger;
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

    public function evaluate(): void
    {
        $this->raised = [];
        $this->providerErrors();
        $this->queueBacklog();
        $this->configuredCertificateExpiry();
        $this->ragIndexHealth();
        $this->resolveCleared(['provider_error_rate_high', 'queue_backlog_high', 'queue_metrics_unavailable', 'certificate_expiring', 'rag_embedding_model_missing', 'rag_embedding_jobs_failed', 'rag_index_drift']);
        (new AlertNotifier())->deliverDue();
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
        $rows = App::db()->select(
            'SELECT provider_id, COUNT(*) total_count, SUM(CASE WHEN status != "success" THEN 1 ELSE 0 END) error_count FROM usage_records WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) GROUP BY provider_id HAVING total_count >= 5'
        );
        foreach ($rows as $row) {
            $rate = ((int) $row['error_count']) / max(1, (int) $row['total_count']);
            if ($rate >= 0.3) {
                $this->record('provider_error_rate_high', 'warning', 'Provider error rate is high', [
                    'provider_id' => $row['provider_id'],
                    'error_rate' => $rate,
                    'window_minutes' => 5,
                ]);
            }
        }
    }

    private function queueBacklog(): void
    {
        try {
            $queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
            $count = (int) App::redis()->connection()->lLen($queue);
            if ($count >= 1000) {
                $this->record('queue_backlog_high', 'warning', 'Queue backlog is high', ['queue' => $queue, 'count' => $count]);
            }
        } catch (\Throwable $throwable) {
            // Unknown is not "zero backlog": surface the monitoring gap itself.
            $this->record('queue_metrics_unavailable', 'warning', 'Queue length could not be read', ['queue' => $queue ?? null, 'error' => mb_substr($throwable->getMessage(), 0, 200)]);
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
            $failed = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE status = "failed" AND updated_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)')['c'] ?? 0);
            if ($failed > 0) {
                $this->record('rag_embedding_jobs_failed', 'warning', 'RAG vectorization jobs failed in the last hour', ['count' => $failed]);
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
