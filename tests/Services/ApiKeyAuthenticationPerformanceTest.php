<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ApiKeyService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$departmentName = 'Perf Dept ' . $suffix;
$departmentPath = '/perf-' . $suffix . '/';
$username = 'perf_user_' . $suffix;

$cleanup = static function () use ($db, $departmentName, $username): void {
    $apiKeyIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $db->select('SELECT k.id FROM api_keys k JOIN users u ON u.id = k.user_id WHERE u.username = ?', [$username])
    );
    if ($apiKeyIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($apiKeyIds), '?'));
        $db->execute("DELETE FROM api_key_model_permissions WHERE api_key_id IN ({$placeholders})", $apiKeyIds);
        $db->execute("DELETE FROM api_key_model_quotas WHERE api_key_id IN ({$placeholders})", $apiKeyIds);
        $db->execute("DELETE FROM api_keys WHERE id IN ({$placeholders})", $apiKeyIds);
    }
    $db->execute('DELETE FROM users WHERE username = ?', [$username]);
    $db->execute('DELETE FROM departments WHERE name = ?', [$departmentName]);
};

$cleanup();
register_shutdown_function($cleanup);

$db->execute(
    'INSERT INTO departments (name, path, level, sort_order, status) VALUES (?, ?, 1, 0, "active")',
    [$departmentName, $departmentPath]
);
$departmentId = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")',
    [$departmentId, $username, password_hash('secret', PASSWORD_DEFAULT)]
);
$userId = (int) $db->pdo()->lastInsertId();

$service = new ApiKeyService();
$created = $service->create([
    'user_id' => $userId,
    'department_id' => $departmentId,
    'name' => 'Perf Key ' . $suffix,
    'allowed_ip_cidrs' => [],
], $userId);
$plain = (string) $created['key'];
$hash = Crypto::hashKey($plain);
App::redis()->delete($service->cacheKey($hash));
App::redis()->delete($service->lastUsedCacheKey((int) $created['id']));

$first = $service->authenticate($plain, '127.0.0.1', null);
assert_true($first['ok'], 'First authentication should succeed.');

$db->execute('UPDATE api_keys SET last_used_at = "2000-01-01 00:00:00" WHERE id = ?', [(int) $created['id']]);
$second = $service->authenticate($plain, '127.0.0.1', null);
assert_true($second['ok'], 'Second authentication should succeed from the warm path.');

$row = $db->first('SELECT last_used_at FROM api_keys WHERE id = ?', [(int) $created['id']]);
assert_same('2000-01-01 00:00:00', (string) ($row['last_used_at'] ?? ''), 'Warm API key authentication should throttle last_used_at writes.');
