<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ApiKeyManagementScopeService;
use TokenHub\Services\ApiKeyService;
use TokenHub\Support\App;

$scopes = new ApiKeyManagementScopeService();

assert_same(
    ['users:write', 'api_keys:read', 'rag:read'],
    $scopes->normalize(['rag:read', 'api_keys:read', 'users:write', 'rag:read']),
    'Management scopes should be validated, de-duplicated, and returned in canonical order.'
);

$invalidRejected = false;
try {
    $scopes->normalize(['users:delete']);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
assert_true($invalidRejected, 'Unknown management scopes must be rejected.');

$db = App::db();
$suffix = bin2hex(random_bytes(5));
$departmentId = null;
$userId = null;
$apiKeyId = null;

try {
    $db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, "/", 0, "active")', ["management-scopes-{$suffix}"]);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute('UPDATE departments SET path = ? WHERE id = ?', ["/{$departmentId}/", $departmentId]);
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")',
        [$departmentId, "management-scopes-{$suffix}", password_hash('secret', PASSWORD_DEFAULT)]
    );
    $userId = (int) $db->pdo()->lastInsertId();

    $created = (new ApiKeyService())->create([
        'user_id' => $userId,
        'department_id' => $departmentId,
        'name' => 'agent management scope test',
        'management_scopes' => ['users:write', 'rag:read'],
    ], 1);
    $apiKeyId = (int) $created['id'];

    assert_same(['users:write', 'rag:read'], $scopes->scopesFor($apiKeyId), 'API key creation should persist requested management scopes.');
    assert_true($scopes->hasScope($apiKeyId, 'users:read'), 'A write management scope should imply the matching read scope.');
    assert_true(!$scopes->hasScope($apiKeyId, 'api_keys:read'), 'An ungranted management scope must be denied.');
} finally {
    if ($apiKeyId !== null) {
        $db->execute('DELETE FROM api_keys WHERE id = ?', [$apiKeyId]);
    }
    if ($userId !== null) {
        $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
    if ($departmentId !== null) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
    }
}

$runner = file_get_contents(__DIR__ . '/../../app/Console/MigrationRunner.php') ?: '';
assert_true(str_contains($runner, '*api_key_management_scopes*.sql'), 'Migration runner should discover the management-scope migration family.');
assert_true(str_contains($runner, '*api_key_management_delegations*.sql'), 'Migration runner should discover the management-delegation migration family.');
