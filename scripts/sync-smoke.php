<?php

declare(strict_types=1);

use TokenHub\Services\ApiKeyService;
use TokenHub\Services\UserSyncService;
use TokenHub\Support\App;

require __DIR__ . '/../vendor/autoload.php';

App::bootstrap(dirname(__DIR__));

$db = App::db();
$username = 'sync_smoke_' . bin2hex(random_bytes(4));
$db->execute(
    'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (1, ?, ?, "user", "active")',
    [$username, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]
);
$userId = (int) $db->pdo()->lastInsertId();
$key = (new ApiKeyService())->create(['user_id' => $userId, 'department_id' => 1, 'name' => 'sync smoke'], $userId);
$apiKey = $db->first('SELECT * FROM api_keys WHERE id = ?', [$key['id']]);
if ($apiKey === null) {
    throw new RuntimeException('Smoke API key was not created.');
}

$service = new UserSyncService();
$device = $service->registerDevice($apiKey, [
    'device_id' => 'dev_smoke_' . bin2hex(random_bytes(4)),
    'device_name' => 'Smoke Device',
    'platform' => 'test',
    'client_version' => 'smoke',
]);

$record = $service->upsert($apiKey, 'settings', 'default', [
    'device_id' => $device['device_id'],
    'payload' => ['theme' => 'dark'],
    'client_updated_at' => gmdate('c'),
]);

$list = $service->list($apiKey, ['cursor' => 'ver_0', 'limit' => 10]);
if (($list['records'][0]['payload']['theme'] ?? null) !== 'dark') {
    throw new RuntimeException('Smoke record was not returned by sync list.');
}

$batch = $service->batchUpsert($apiKey, [
    'device_id' => $device['device_id'],
    'records' => [[
        'namespace' => 'settings',
        'client_record_id' => 'default',
        'payload' => ['theme' => 'light'],
        'base_sync_version' => 0,
    ]],
]);
if (($batch['conflicted'] ?? 0) !== 1 || (($batch['records'][0]['status'] ?? null) !== 'conflict')) {
    throw new RuntimeException('Smoke batch conflict was not reported.');
}

$deleted = $service->delete($apiKey, 'settings', 'default', [
    'device_id' => $device['device_id'],
]);
if (($deleted['deleted'] ?? false) !== true) {
    throw new RuntimeException('Smoke record was not deleted.');
}

echo json_encode([
    'ok' => true,
    'user_id' => $userId,
    'api_key_display' => $key['display'],
    'device_id' => $device['device_id'],
    'record_id' => $record['record_id'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
