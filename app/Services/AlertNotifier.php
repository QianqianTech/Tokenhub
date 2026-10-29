<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\SchemaInspector;

/**
 * Delivers alert lifecycle notifications (triggered / escalated / resolved)
 * to operator webhooks with retry and an auditable delivery log.
 *
 * Targets: ALERT_WEBHOOK_URLS (comma separated). Payload is JSON with the
 * alert row; receivers such as Alertmanager adapters, Feishu/DingTalk/Slack
 * relays or an on-call system map it to their own format. Targets are
 * admin-configured, so private addresses are allowed only when
 * ALERT_WEBHOOK_ALLOW_PRIVATE=true or listed in OUTBOUND_PRIVATE_ALLOWLIST.
 *
 * Retry: exponential backoff (60s * 2^attempt, max 1h), up to
 * ALERT_NOTIFY_MAX_ATTEMPTS (default 5); then the delivery is marked "dead"
 * and a log entry records the failure so it shows in operations status.
 */
final class AlertNotifier
{
    /** @var null|callable(string, string): array{status: int, error?: string} */
    private $transport;

    /**
     * @param list<string>|null $targets
     */
    public function __construct(private readonly ?array $targets = null, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @return list<string>
     */
    public function targets(): array
    {
        return $this->targets ?? array_values(array_filter(array_map('trim', explode(',', (string) env_value('ALERT_WEBHOOK_URLS', '')))));
    }

    public function queue(int $alertEventId, string $kind): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $queued = 0;
        foreach ($this->targets() as $target) {
            App::db()->execute(
                'INSERT INTO alert_notifications (alert_event_id, channel, target, kind, status, next_attempt_at) VALUES (?, "webhook", ?, ?, "pending", UTC_TIMESTAMP())',
                [$alertEventId, substr($target, 0, 500), $kind]
            );
            $queued++;
        }

        return $queued;
    }

    public function deliverDue(int $limit = 50): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $maxAttempts = max(1, (int) env_value('ALERT_NOTIFY_MAX_ATTEMPTS', 5));
        $rows = App::db()->select(
            'SELECT n.*, e.event_type, e.severity, e.message, e.metadata, e.status AS alert_status, e.occurrence_count, e.first_seen_at, e.last_seen_at
             FROM alert_notifications n JOIN alert_events e ON e.id = n.alert_event_id
             WHERE n.status IN ("pending","retrying") AND (n.next_attempt_at IS NULL OR n.next_attempt_at <= UTC_TIMESTAMP())
             ORDER BY n.id ASC LIMIT ' . max(1, min(500, $limit))
        );
        $delivered = 0;
        foreach ($rows as $row) {
            $payload = json_encode([
                'source' => 'tokenhub',
                'kind' => $row['kind'],
                'alert' => [
                    'id' => (int) $row['alert_event_id'],
                    'event_type' => $row['event_type'],
                    'severity' => $row['severity'],
                    'status' => $row['alert_status'],
                    'message' => $row['message'],
                    'metadata' => json_decode((string) ($row['metadata'] ?? 'null'), true),
                    'occurrence_count' => (int) ($row['occurrence_count'] ?? 1),
                    'first_seen_at' => $row['first_seen_at'] ?? null,
                    'last_seen_at' => $row['last_seen_at'] ?? null,
                ],
                'sent_at' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $result = $this->send((string) $row['target'], (string) $payload);
            $attempts = (int) $row['attempts'] + 1;
            if ($result['status'] >= 200 && $result['status'] < 300) {
                App::db()->execute('UPDATE alert_notifications SET status = "delivered", attempts = ?, delivered_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?', [$attempts, (int) $row['id']]);
                $delivered++;
                continue;
            }
            $error = substr((string) ($result['error'] ?? ('HTTP ' . $result['status'])), 0, 255);
            if ($attempts >= $maxAttempts) {
                App::db()->execute('UPDATE alert_notifications SET status = "dead", attempts = ?, last_error = ? WHERE id = ?', [$attempts, $error, (int) $row['id']]);
                App::logger()->error('security', 'alert notification failed permanently', ['alert_event_id' => $row['alert_event_id'], 'target_host' => parse_url((string) $row['target'], PHP_URL_HOST), 'error' => $error]);
                continue;
            }
            $delay = min(3600, 60 * (2 ** ($attempts - 1)));
            App::db()->execute('UPDATE alert_notifications SET status = "retrying", attempts = ?, last_error = ?, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id = ?', [$attempts, $error, $delay, (int) $row['id']]);
        }

        return $delivered;
    }

    /**
     * @return array{status: int, error?: string}
     */
    private function send(string $url, string $payload): array
    {
        if (is_callable($this->transport)) {
            return ($this->transport)($url, $payload);
        }
        $check = (new OutboundUrlPolicy())->check($url, OutboundUrlPolicy::PURPOSE_PROVIDER, [
            'allow_private' => filter_var(env_value('ALERT_WEBHOOK_ALLOW_PRIVATE', false), FILTER_VALIDATE_BOOL),
        ]);
        if (!$check['ok']) {
            return ['status' => 0, 'error' => 'outbound_blocked:' . ($check['reason'] ?? 'denied')];
        }
        try {
            $result = PinnedHttpPost::send($url, (string) (($check['addresses'] ?? [])[0] ?? ''), $payload, ['Content-Type: application/json', 'User-Agent: TokenHub-Alerts/1'], 10);

            return $result['status'] === 0 ? ['status' => 0, 'error' => $result['error'] !== '' ? $result['error'] : 'connection_failed'] : ['status' => $result['status']];
        } catch (Throwable $throwable) {
            return ['status' => 0, 'error' => $throwable->getMessage()];
        }
    }

    private function enabled(): bool
    {
        return $this->targets() !== [] && SchemaInspector::hasColumn('alert_notifications', 'target');
    }
}
