<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ApiKeyManagementScopeService;
use TokenHub\Services\AgentManagementApiKeyService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(5));
$departmentId = $userId = $parentId = $childId = $managedChildId = null;

try {
    $db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, "/", 0, "active")', ["delegation-{$suffix}"]);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute('UPDATE departments SET path = ? WHERE id = ?', ["/{$departmentId}/", $departmentId]);
    $db->execute('INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")', [$departmentId, "delegation-{$suffix}", password_hash('secret', PASSWORD_DEFAULT)]);
    $userId = (int) $db->pdo()->lastInsertId();
    foreach (['parent', 'child'] as $name) {
        $db->execute('INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, ?, ?, ?, "active")', [$userId, $departmentId, $name, 'tkh_test', $name, hash('sha256', "{$suffix}:{$name}")]);
        ${$name . 'Id'} = (int) $db->pdo()->lastInsertId();
    }
    $scopes = new ApiKeyManagementScopeService();
    $scopes->replace($parentId, ['users:write']);
    $scopes->replace($childId, ['rag:write']);
    $overDelegationRejected = false;
    try {
        $scopes->delegate($childId, $parentId);
    } catch (InvalidArgumentException) {
        $overDelegationRejected = true;
    }
    assert_true($overDelegationRejected, 'A child key cannot retain management scopes that its parent does not hold.');
    $scopes->replace($childId, ['users:write']);
    $scopes->delegate($childId, $parentId);
    assert_true($scopes->hasScope($childId, 'users:write'), 'Active delegated child key should retain granted scope.');

    $cycleRejected = false;
    try {
        $scopes->delegate($parentId, $childId);
    } catch (InvalidArgumentException) {
        $cycleRejected = true;
    }
    assert_true($cycleRejected, 'Management-key delegation must reject parent-child cycles.');

    $managedChild = (new AgentManagementApiKeyService())->create(
        ['id' => $parentId, 'user_id' => $userId, 'department_id' => $departmentId],
        ['user_id' => $userId, 'name' => 'managed-child', 'management_scopes' => ['users:read']]
    );
    $managedChildId = (int) $managedChild['id'];
    assert_true(
        $db->first('SELECT child_api_key_id FROM api_key_management_delegations WHERE child_api_key_id = ? AND parent_api_key_id = ?', [$managedChildId, $parentId]) !== null,
        'Creating a scoped management key must record its delegating parent key.'
    );

    $db->execute('UPDATE api_keys SET status = "deleted" WHERE id = ?', [$parentId]);
    assert_true(!$scopes->hasScope($childId, 'users:write'), 'Child management scope must become unusable when its parent key is revoked.');
    assert_true(!$scopes->hasScope($managedChildId, 'users:read'), 'Automatically delegated child scopes must become unusable when their parent key is revoked.');
} finally {
    if ($managedChildId !== null) $db->execute('DELETE FROM api_key_management_delegations WHERE child_api_key_id = ? OR parent_api_key_id = ?', [$managedChildId, $managedChildId]);
    if ($childId !== null) $db->execute('DELETE FROM api_key_management_delegations WHERE child_api_key_id = ? OR parent_api_key_id = ?', [$childId, $childId]);
    if ($parentId !== null) $db->execute('DELETE FROM api_key_management_delegations WHERE child_api_key_id = ? OR parent_api_key_id = ?', [$parentId, $parentId]);
    if ($managedChildId !== null) $db->execute('DELETE FROM api_keys WHERE id = ?', [$managedChildId]);
    if ($childId !== null) $db->execute('DELETE FROM api_keys WHERE id = ?', [$childId]);
    if ($parentId !== null) $db->execute('DELETE FROM api_keys WHERE id = ?', [$parentId]);
    if ($userId !== null) $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    if ($departmentId !== null) $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
}
