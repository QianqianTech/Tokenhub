<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ApiKeyService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Logger;

$service = new ApiKeyService();
$plain = 'tkh_test_plaintext_secret';
$hash = Crypto::hashKey($plain);

assert_same('api_key:' . $hash, $service->cacheKey($hash), 'API key cache key should be derived from key hash.');

$safe = $service->sanitizeForStorage([
    'name' => 'Test key',
    'key' => $plain,
    'key_hash' => $hash,
    'model_ids' => [1, 2],
    'allowed_ip_cidrs' => ['10.0.0.0/8'],
]);
assert_true(!array_key_exists('key', $safe), 'Plaintext key must not be retained in storage payloads.');
assert_true(!array_key_exists('key_hash', $safe), 'Key hash must not be retained in audit/storage payloads.');
assert_same([1, 2], $safe['model_ids'], 'Non-sensitive model permission payload should be retained.');

$audit = Logger::sanitize(['api_key' => $plain, 'key_hash' => $hash, 'display' => 'tkh_test****cret']);
assert_same('****', $audit['api_key'], 'Logger should redact API key values.');
assert_same('****', $audit['key_hash'], 'Logger should redact key hashes.');
assert_same('tkh_test****cret', $audit['display'], 'Masked display values should remain visible.');

App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin']);

$db = App::db();
$suffix = bin2hex(random_bytes(5));
$departmentId = null;
$userId = null;
$apiKeyIds = [];
$hashes = [];

try {
    $db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, "/", 0, "active")', ["batch-api-keys-{$suffix}"]);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute('UPDATE departments SET path = ? WHERE id = ?', ["/{$departmentId}/", $departmentId]);

    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")',
        [$departmentId, "batch-api-keys-{$suffix}", password_hash('secret', PASSWORD_DEFAULT)]
    );
    $userId = (int) $db->pdo()->lastInsertId();

    foreach (['alpha', 'beta'] as $name) {
        $plain = "batch-api-key-{$name}-{$suffix}";
        $hashes[] = Crypto::hashKey($plain);
        $db->execute(
            'INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, ?, ?, ?, "active")',
            [$userId, $departmentId, "batch {$name}", 'bt_' . $name, substr($suffix, 0, 4), $hashes[array_key_last($hashes)]]
        );
        $apiKeyIds[] = (int) $db->pdo()->lastInsertId();
    }

    App::redis()->set($service->cacheKey($hashes[0]), ['id' => $apiKeyIds[0]], 60);

    $disabled = $service->batchStatus('disable', [$apiKeyIds[0], $apiKeyIds[1], 999999999]);
    assert_same('disabled', $disabled['status'], 'Batch pause should return disabled status.');
    assert_same(2, $disabled['updated'], 'Batch pause should update matched API keys.');
    assert_same([999999999], $disabled['missing_ids'], 'Batch pause should report missing API keys.');
    assert_same('disabled', $db->first('SELECT status FROM api_keys WHERE id = ?', [$apiKeyIds[0]])['status'] ?? null, 'Batch pause should disable selected API keys.');
    assert_same(null, App::redis()->get($service->cacheKey($hashes[0])), 'Batch pause should clear cached API key authentication rows.');

    $enabled = $service->batchStatus('enable', $apiKeyIds);
    assert_same('active', $enabled['status'], 'Batch enable should return active status.');
    assert_same(2, $enabled['updated'], 'Batch enable should update matched API keys.');
    assert_same('active', $db->first('SELECT status FROM api_keys WHERE id = ?', [$apiKeyIds[1]])['status'] ?? null, 'Batch enable should activate selected API keys.');

    $deleted = $service->batchStatus('delete', $apiKeyIds);
    assert_same('deleted', $deleted['status'], 'Batch delete should return deleted status.');
    assert_same(2, $deleted['deleted'], 'Batch delete should count soft-deleted API keys.');
    assert_same('deleted', $db->first('SELECT status FROM api_keys WHERE id = ?', [$apiKeyIds[0]])['status'] ?? null, 'Batch delete should soft-delete selected API keys.');
} finally {
    foreach ($hashes as $hash) {
        App::redis()->delete($service->cacheKey($hash));
    }
    foreach ($apiKeyIds as $apiKeyId) {
        $db->execute('DELETE FROM api_keys WHERE id = ?', [$apiKeyId]);
    }
    if ($userId !== null) {
        $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
    if ($departmentId !== null) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
    }
}
