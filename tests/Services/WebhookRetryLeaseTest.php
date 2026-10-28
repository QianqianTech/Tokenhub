<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\WebhookService;
use TokenHub\Support\App;

$service = new WebhookService();
$now = strtotime('2026-07-10 12:00:00 UTC');
assert_same(true, $service->isDeliveryDue(['status' => 'pending', 'next_attempt_at' => '2026-07-10 11:59:59'], $now), 'A pending due webhook should be claimable.');
assert_same(false, $service->isDeliveryDue(['status' => 'failed', 'next_attempt_at' => '2026-07-10 12:00:10'], $now), 'A backoff-delayed webhook must not be retried early.');
assert_same(false, $service->isDeliveryDue(['status' => 'succeeded', 'next_attempt_at' => null], $now), 'Completed webhook deliveries must never be retried.');

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$departmentId = $userId = $apiKeyId = 0;
try {
    $db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, ?, 0, "active")', ['Webhook Queue Dept ' . $suffix, '/webhook-queue-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, "hash", "user", "active")', [$departmentId, 'webhook_queue_' . $suffix]);
    $userId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "whq", "test", ?, "active")', [$userId, $departmentId, 'Webhook Queue ' . $suffix, hash('sha256', $suffix)]);
    $apiKeyId = (int) $db->pdo()->lastInsertId();
    $apiKey = ['id' => $apiKeyId, 'user_id' => $userId, 'department_id' => $departmentId];

    $published = [];
    $service = new WebhookService(static function (string $queue, array $payload) use (&$published): void {
        $published[] = [$queue, $payload];
    });
    $webhook = $service->create($apiKey, ['url' => 'https://example.com/tokenhub-webhook', 'event_types' => ['request.success']]);
    $deliveryIds = $service->emit($apiKey, 'request.success', ['request_id' => 'req_' . $suffix]);

    assert_same(1, count($deliveryIds), 'Matching webhook events should create one delivery.');
    assert_same((string) App::config()->get('queue.name', 'tokenhub:jobs'), $published[0][0] ?? null, 'Webhook jobs must use the configured worker queue.');
    assert_same('webhook.deliver', $published[0][1]['type'] ?? null, 'Webhook jobs must retain their delivery type.');
    assert_same($deliveryIds[0], $published[0][1]['delivery_id'] ?? null, 'Webhook jobs must retain their delivery ID.');
} finally {
    if ($apiKeyId > 0) {
        $db->execute('DELETE d FROM external_webhook_deliveries d JOIN external_webhooks w ON w.id = d.webhook_id WHERE w.api_key_id = ?', [$apiKeyId]);
        $db->execute('DELETE FROM external_webhooks WHERE api_key_id = ?', [$apiKeyId]);
        $db->execute('DELETE FROM api_keys WHERE id = ?', [$apiKeyId]);
    }
    if ($userId > 0) $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    if ($departmentId > 0) $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
}
