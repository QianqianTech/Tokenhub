<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class WebhookService
{
    private const MAX_ATTEMPTS = 5;

    /** @param null|callable(string, array<string, mixed>): void $queuePublisher */
    public function __construct(private readonly mixed $queuePublisher = null)
    {
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $apiKey, array $data): array
    {
        $url = trim((string) ($data['url'] ?? ''));
        if (!(new WebhookTargetValidator())->isAllowed($url)) {
            abort_json('WEBHOOK_INVALID_URL', 'Webhook URL must use HTTPS and resolve only to public addresses', 422);
        }
        $eventTypes = $this->eventTypes($data['event_types'] ?? ['*']);
        $secret = Crypto::token('whsec_');
        $webhookId = Crypto::token('wh_');

        App::db()->execute(
            'INSERT INTO external_webhooks (webhook_id, api_key_id, user_id, department_id, url, event_types, secret, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "active")',
            [
                $webhookId,
                $apiKey['id'] ?? null,
                $apiKey['user_id'] ?? null,
                $apiKey['department_id'] ?? null,
                $url,
                json_encode($eventTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                Crypto::encrypt($secret),
            ]
        );

        return [
            'id' => $webhookId,
            'url' => $url,
            'event_types' => $eventTypes,
            'status' => 'active',
            'secret' => $secret,
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function list(array $apiKey): array
    {
        $rows = App::db()->select(
            'SELECT webhook_id, url, event_types, status, last_delivery_at, created_at, updated_at FROM external_webhooks WHERE api_key_id = ? AND deleted_at IS NULL ORDER BY id DESC',
            [$apiKey['id'] ?? 0]
        );

        return [
            'webhooks' => array_map(fn (array $row): array => [
                'id' => (string) $row['webhook_id'],
                'url' => (string) $row['url'],
                'event_types' => $this->decodeTypes($row['event_types'] ?? null),
                'status' => (string) $row['status'],
                'last_delivery_at' => $row['last_delivery_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ], $rows),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function delete(array $apiKey, string $id): array
    {
        $count = App::db()->execute(
            'UPDATE external_webhooks SET status = "disabled", deleted_at = NOW() WHERE webhook_id = ? AND api_key_id = ? AND deleted_at IS NULL',
            [$id, $apiKey['id'] ?? 0]
        );
        if ($count <= 0) {
            abort_json('WEBHOOK_NOT_FOUND', 'Webhook not found', 404);
        }

        return ['id' => $id, 'deleted' => true, 'deleted_at' => gmdate('Y-m-d H:i:s')];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function deliveries(array $apiKey, string $webhookId, array $query = []): array
    {
        $webhook = $this->webhook($apiKey, $webhookId);
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $rows = App::db()->select(
            'SELECT delivery_id, event_type, status, attempt_count, response_status, error_summary, next_attempt_at, delivered_at, created_at, updated_at
             FROM external_webhook_deliveries
             WHERE webhook_id = ?
             ORDER BY id DESC
             LIMIT ' . $limit,
            [(int) $webhook['id']]
        );

        return [
            'webhook_id' => (string) $webhook['webhook_id'],
            'deliveries' => array_map(fn (array $row): array => $this->publicDelivery($row), $rows),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function test(array $apiKey, string $webhookId): array
    {
        $webhook = $this->webhook($apiKey, $webhookId);
        $deliveryId = $this->createDelivery($webhook, 'webhook.test', [
            'event' => 'webhook.test',
            'webhook_id' => $webhookId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->enqueueDelivery($deliveryId);

        $delivery = App::db()->first('SELECT * FROM external_webhook_deliveries WHERE delivery_id = ?', [$deliveryId]) ?? ['delivery_id' => $deliveryId, 'event_type' => 'webhook.test', 'status' => 'pending'];
        return $this->publicDelivery($delivery);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function retryDelivery(array $apiKey, string $deliveryId): array
    {
        $delivery = App::db()->first(
            'SELECT d.*, w.webhook_id, w.api_key_id
             FROM external_webhook_deliveries d
             JOIN external_webhooks w ON w.id = d.webhook_id
             WHERE d.delivery_id = ? AND w.api_key_id = ? AND w.deleted_at IS NULL',
            [$deliveryId, $apiKey['id'] ?? 0]
        );
        if ($delivery === null) {
            abort_json('WEBHOOK_DELIVERY_NOT_FOUND', 'Webhook delivery not found', 404);
        }
        if ((string) ($delivery['status'] ?? '') === 'succeeded') {
            abort_json('WEBHOOK_DELIVERY_ALREADY_SUCCEEDED', 'Succeeded webhook delivery cannot be retried', 409);
        }

        App::db()->execute(
            'UPDATE external_webhook_deliveries SET status = "pending", next_attempt_at = NOW(), error_summary = NULL WHERE delivery_id = ?',
            [$deliveryId]
        );
        $this->enqueueDelivery($deliveryId);

        return $this->publicDelivery(App::db()->first('SELECT * FROM external_webhook_deliveries WHERE delivery_id = ?', [$deliveryId]) ?? ['delivery_id' => $deliveryId, 'status' => 'pending']);
    }

    /** @param array<string, mixed> $apiKey */
    public function rotateSecret(array $apiKey, string $webhookId): array
    {
        $webhook = $this->webhook($apiKey, $webhookId);
        $secret = Crypto::token('whsec_');
        App::db()->execute('UPDATE external_webhooks SET previous_secret = ?, previous_secret_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), secret = ? WHERE id = ?', [$webhook['secret'], Crypto::encrypt($secret), $webhook['id']]);
        return ['id' => $webhookId, 'secret' => $secret, 'previous_secret_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public function emit(array $apiKey, string $eventType, array $payload): array
    {
        $webhooks = App::db()->select('SELECT * FROM external_webhooks WHERE api_key_id = ? AND status = "active" AND deleted_at IS NULL', [$apiKey['id'] ?? 0]);
        $deliveries = [];
        foreach ($webhooks as $webhook) {
            $types = $this->decodeTypes($webhook['event_types'] ?? null);
            if (!in_array('*', $types, true) && !in_array($eventType, $types, true)) {
                continue;
            }
            $deliveryId = $this->createDelivery($webhook, $eventType, $payload);
            $deliveries[] = $deliveryId;
            $this->enqueueDelivery($deliveryId);
        }
        return $deliveries;
    }

    public function processDelivery(string $deliveryId): void
    {
        $delivery = App::db()->first(
            'SELECT d.*, w.url, w.secret, w.previous_secret, w.previous_secret_expires_at, w.api_key_id
             FROM external_webhook_deliveries d
             JOIN external_webhooks w ON w.id = d.webhook_id
             WHERE d.delivery_id = ?',
            [$deliveryId]
        );
        if ($delivery === null || (int) $delivery['attempt_count'] >= self::MAX_ATTEMPTS || !$this->isDeliveryDue($delivery)) {
            return;
        }

        $claimed = App::db()->execute(
            'UPDATE external_webhook_deliveries SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ? AND status IN ("pending", "failed") AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())',
            [$delivery['id']]
        );
        if ($claimed <= 0) {
            return;
        }

        $payload = (string) $delivery['payload_json'];
        $signature = $this->signature($this->decryptSecret((string) $delivery['secret']), $payload);
        $headers = [
            'Content-Type: application/json',
            'X-TokenHub-Event: ' . (string) $delivery['event_type'],
            'X-TokenHub-Delivery: ' . (string) $delivery['delivery_id'],
            'X-TokenHub-Signature: ' . $signature,
        ];
        if (!empty($delivery['previous_secret']) && !empty($delivery['previous_secret_expires_at']) && strtotime((string) $delivery['previous_secret_expires_at']) > time()) {
            $headers[] = 'X-TokenHub-Signature-Previous: ' . $this->signature($this->decryptSecret((string) $delivery['previous_secret']), $payload);
        }
        $response = $this->post((string) $delivery['url'], $payload, $headers);
        $status = $response['status'] >= 200 && $response['status'] < 300 ? 'succeeded' : 'failed';
        $attempts = (int) $delivery['attempt_count'] + 1;
        $finalStatus = $status === 'succeeded' ? 'succeeded' : ($attempts >= self::MAX_ATTEMPTS ? 'dead' : 'failed');

        App::db()->execute(
            'UPDATE external_webhook_deliveries SET status = ?, attempt_count = ?, response_status = ?, error_summary = ?, delivered_at = ?, next_attempt_at = ? WHERE delivery_id = ?',
            [
                $finalStatus,
                $attempts,
                $response['status'] > 0 ? $response['status'] : null,
                substr($response['error'] ?? '', 0, 500) ?: null,
                $status === 'succeeded' ? gmdate('Y-m-d H:i:s') : null,
                $status === 'succeeded' || $attempts >= self::MAX_ATTEMPTS ? null : gmdate('Y-m-d H:i:s', time() + min(3600, 2 ** $attempts)),
                $deliveryId,
            ]
        );
        App::db()->execute('UPDATE external_webhooks SET last_delivery_at = NOW() WHERE id = ?', [$delivery['webhook_id']]);
        (new ExternalEventOutboxService())->append((int) ($delivery['api_key_id'] ?? 0), 'webhook.' . $finalStatus, 'webhook', $deliveryId, $finalStatus, ['event_type' => $delivery['event_type'] ?? null, 'response_status' => $response['status'] ?? null]);
    }

    /** @param array<string, mixed> $delivery */
    public function isDeliveryDue(array $delivery, ?int $now = null): bool
    {
        if (!in_array((string) ($delivery['status'] ?? ''), ['pending', 'failed'], true)) {
            return false;
        }
        $next = $delivery['next_attempt_at'] ?? null;
        if ($next === null || $next === '') {
            return true;
        }
        $now ??= time();
        return (strtotime((string) $next) ?: PHP_INT_MAX) <= $now;
    }

    public function enqueueDue(int $limit = 100): int
    {
        $rows = App::db()->select(
            'SELECT delivery_id FROM external_webhook_deliveries WHERE status IN ("pending", "failed") AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW() ORDER BY id ASC LIMIT ' . max(1, min(100, $limit))
        );
        foreach ($rows as $row) {
            $this->enqueueDelivery((string) $row['delivery_id']);
        }
        return count($rows);
    }

    /**
     * @param array<string, mixed> $webhook
     * @param array<string, mixed> $payload
     */
    public function createDelivery(array $webhook, string $eventType, array $payload): string
    {
        $deliveryId = Crypto::token('whd_');
        App::db()->execute(
            'INSERT INTO external_webhook_deliveries (delivery_id, webhook_id, event_type, payload_json, status, next_attempt_at)
             VALUES (?, ?, ?, ?, "pending", NOW())',
            [$deliveryId, $webhook['id'], $eventType, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );
        return $deliveryId;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    private function webhook(array $apiKey, string $webhookId): array
    {
        $webhook = App::db()->first(
            'SELECT * FROM external_webhooks WHERE webhook_id = ? AND api_key_id = ? AND deleted_at IS NULL',
            [$webhookId, $apiKey['id'] ?? 0]
        );
        if ($webhook === null) {
            abort_json('WEBHOOK_NOT_FOUND', 'Webhook not found', 404);
        }

        return $webhook;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicDelivery(array $row): array
    {
        return [
            'delivery_id' => (string) ($row['delivery_id'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'attempt_count' => isset($row['attempt_count']) ? (int) $row['attempt_count'] : 0,
            'response_status' => isset($row['response_status']) ? (int) $row['response_status'] : null,
            'error_summary' => $row['error_summary'] ?? null,
            'next_attempt_at' => $row['next_attempt_at'] ?? null,
            'delivered_at' => $row['delivered_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public function signature(string $secret, string $payload): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    private function decryptSecret(string $stored): string
    {
        return Crypto::decrypt($stored) ?: $stored;
    }

    private function enqueueDelivery(string $deliveryId): void
    {
        $payload = ['type' => 'webhook.deliver', 'delivery_id' => $deliveryId];
        $queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
        if (is_callable($this->queuePublisher)) {
            ($this->queuePublisher)($queue, $payload);
            return;
        }

        App::redis()->push($queue, $payload);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, error?: string}
     */
    private function post(string $url, string $payload, array $headers): array
    {
        // Re-check at delivery time (DNS may have changed since registration)
        // and connect to exactly the checked address (no DNS rebinding).
        $check = (new OutboundUrlPolicy())->check($url, OutboundUrlPolicy::PURPOSE_WEBHOOK);
        if (!($check['ok'] ?? false)) {
            return ['status' => 0, 'error' => 'outbound_blocked:' . ($check['reason'] ?? 'denied')];
        }
        $result = PinnedHttpPost::send($url, (string) (($check['addresses'] ?? [])[0] ?? ''), $payload, $headers, 10);
        $status = $result['status'];

        return ['status' => $status, 'error' => $status >= 200 && $status < 300 ? '' : substr($result['body'] !== '' ? $result['body'] : ($result['error'] !== '' ? $result['error'] : 'delivery failed'), 0, 500)];
    }

    /**
     * @return list<string>
     */
    private function eventTypes(mixed $value): array
    {
        $types = is_array($value) ? $value : ['*'];
        $types = array_values(array_unique(array_filter(array_map(static fn (mixed $item): string => substr(trim((string) $item), 0, 120), $types))));
        return array_slice($types === [] ? ['*'] : $types, 0, 50);
    }

    /**
     * @return list<string>
     */
    private function decodeTypes(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : ['*'];
    }
}
