<?php

declare(strict_types=1);

use TokenHub\Services\ApiKeyService;
use TokenHub\Services\WebhookService;
use TokenHub\Support\App;

require __DIR__ . '/../vendor/autoload.php';

App::bootstrap(dirname(__DIR__));

$baseUrl = rtrim((string) ($_SERVER['BASE_URL'] ?? getenv('BASE_URL') ?: 'http://caddy'), '/');
$db = App::db();
$username = 'webhook_smoke_' . bin2hex(random_bytes(4));
$db->execute(
    'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (1, ?, ?, "user", "active")',
    [$username, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]
);
$userId = (int) $db->pdo()->lastInsertId();
$key = (new ApiKeyService())->create(['user_id' => $userId, 'department_id' => 1, 'name' => 'webhook smoke'], $userId);
$token = $key['key'];

$created = request_json($baseUrl, 'POST', '/v1/webhooks', $token, [
    'url' => 'http://caddy/health',
    'event_types' => ['smoke.test'],
]);
assert_response($created, 201, 'webhook create');
$webhookId = (string) ($created['json']['data']['id'] ?? '');
$secret = (string) ($created['json']['data']['secret'] ?? '');
if ($webhookId === '' || !str_starts_with($secret, 'whsec_')) {
    throw new RuntimeException('Webhook create did not return id and one-time secret.');
}

$listed = request_json($baseUrl, 'GET', '/v1/webhooks', $token);
assert_response($listed, 200, 'webhook list');
if (($listed['json']['data']['webhooks'][0]['id'] ?? null) !== $webhookId || isset($listed['json']['data']['webhooks'][0]['secret'])) {
    throw new RuntimeException('Webhook list did not return scoped webhook without secret.');
}

$service = new WebhookService();
$payload = json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$signature = $service->signature($secret, $payload);
if (!str_starts_with($signature, 'sha256=')) {
    throw new RuntimeException('Webhook signature was not generated with sha256 prefix.');
}

$row = $db->first('SELECT * FROM external_webhooks WHERE webhook_id = ? AND api_key_id = ?', [$webhookId, $key['id']]);
if ($row === null) {
    throw new RuntimeException('Webhook row was not stored.');
}
$deliveryId = $service->createDelivery($row, 'smoke.test', ['ok' => true]);
$service->processDelivery($deliveryId);
$delivery = $db->first('SELECT * FROM external_webhook_deliveries WHERE delivery_id = ?', [$deliveryId]);
if ($delivery === null || (int) ($delivery['attempt_count'] ?? 0) < 1) {
    throw new RuntimeException('Webhook delivery attempt was not logged.');
}

$deleted = request_json($baseUrl, 'DELETE', '/v1/webhooks/' . rawurlencode($webhookId), $token);
assert_response($deleted, 200, 'webhook delete');

echo json_encode([
    'ok' => true,
    'webhook_id' => $webhookId,
    'delivery_id' => $deliveryId,
    'delivery_status' => $delivery['status'] ?? null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

/**
 * @return array{status: int, body: string, json: array<string, mixed>|null}
 */
function request_json(string $baseUrl, string $method, string $path, string $token, ?array $payload = null): array
{
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    $body = '';
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $responseBody = file_get_contents($baseUrl . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }
    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;

    return [
        'status' => $status,
        'body' => is_string($responseBody) ? $responseBody : '',
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

/**
 * @param array{status: int, body: string, json: array<string, mixed>|null} $response
 */
function assert_response(array $response, int $status, string $label): void
{
    if ($response['status'] !== $status || ($response['json']['ok'] ?? null) !== true) {
        throw new RuntimeException($label . ' failed: HTTP ' . $response['status'] . ' ' . $response['body']);
    }
}

