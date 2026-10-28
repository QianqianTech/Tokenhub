<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AdminUserService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

$serviceSource = file_get_contents(__DIR__ . '/../../app/Services/AdminUserService.php') ?: '';

assert_true(str_contains($serviceSource, 'final class AdminUserService'), 'AdminUserService should centralize administrator account management.');
assert_true(str_contains($serviceSource, 'adminRoles'), 'AdminUserService should expose a role normalizer for admin-only roles.');
assert_true(str_contains($serviceSource, 'requireSuperAdminActor'), 'AdminUserService writes should require a super admin actor.');
assert_true(str_contains($serviceSource, 'revokeSessions'), 'AdminUserService should support explicit admin session revocation.');
assert_true(str_contains($serviceSource, 'assertManagedDepartmentsAllowed'), 'AdminUserService should validate managed department grants.');

assert_same(['admin', 'super_admin'], AdminUserService::adminRoles(), 'Admin user service should only manage admin roles.');

$username = 'admin_user_test_' . bin2hex(random_bytes(4));
$createdId = null;

try {
    App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin', 'department_id' => 1, 'managed_department_ids' => '[1]']);

    $created = (new AdminUserService())->create([
        'department_id' => 1,
        'username' => $username,
        'password' => 'test-password',
        'role' => 'admin',
        'managed_department_ids' => [1],
        'admin_type' => 'rag_admin',
        'admin_scope' => 'departments',
        'permissions' => ['rag.read', 'rag.manage'],
    ]);
    $createdId = (int) $created['id'];

    assert_same('admin', $created['role'], 'Created administrator should keep the requested admin role.');
    assert_same('rag_admin', $created['admin_type'], 'Created administrator should persist the selected preset type.');
    assert_same('departments', $created['admin_scope'], 'Created administrator should persist the selected data scope.');
    assert_same('custom', $created['admin_permission_mode'], 'An explicit permission selection should enable custom permission mode.');
    assert_same(['rag.read', 'rag.manage'], $created['permissions'], 'Created administrator should expose the selected effective permissions.');
    assert_true(!array_key_exists('password_hash', $created), 'Created administrator response must not expose password_hash.');

    App::db()->execute(
        'INSERT INTO admin_sessions (user_id, session_hash, csrf_token_hash, client_ip, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))',
        [$createdId, Crypto::hashKey('admin-user-test-session'), Crypto::hashKey('admin-user-test-csrf'), '127.0.0.1', 'test']
    );
    $result = (new AdminUserService())->revokeSessions($createdId);
    assert_same(1, (int) $result['revoked'], 'AdminUserService should revoke active admin sessions.');
} finally {
    if ($createdId !== null) {
        App::db()->execute('DELETE FROM admin_sessions WHERE user_id = ?', [$createdId]);
        App::db()->execute('DELETE FROM audit_logs WHERE target_type = ? AND target_id = ?', ['users', $createdId]);
        App::db()->execute('DELETE FROM users WHERE id = ?', [$createdId]);
    }
}
