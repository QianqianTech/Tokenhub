<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * RAG-012: admin ACL API. Replace keeps grants outside the acting admin's
 * scope, rejects out-of-scope subjects and knowledge bases, records the
 * granting admin, and the access check reports the effective result.
 */

use TokenHub\Services\RagAclService;
use TokenHub\Support\App;

$pdo = rag_sqlite_fixture();
$pdo->exec("INSERT INTO departments (id, parent_id, name, path, level) VALUES (1, NULL, '研发', '/1/', 0), (2, 1, '平台', '/1/2/', 1), (3, NULL, '财务', '/3/', 0)");
$pdo->exec("INSERT INTO users (id, department_id, username, role, managed_department_ids, admin_scope) VALUES (1, 1, 'rd-admin', 'admin', '[1]', 'departments'), (2, 3, 'root', 'super_admin', NULL, 'global'), (10, 2, 'dev', 'user', NULL, NULL), (30, 3, 'fin', 'user', NULL, NULL)");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (100, 10, 2, 'dev-key')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility) VALUES (1, 'kb_rd', '研发库', 1, 'private'), (2, 'kb_fin', '财务库', 3, 'private')");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name) VALUES (11, 'ds_a', 1, '公开资料'), (12, 'ds_b', 1, '核心设计')");
// A global admin already granted 财务 read on the R&D knowledge base.
$pdo->exec("INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (1, 'department', 3, 'read')");

$rdAdmin = App::db()->first('SELECT * FROM users WHERE id = 1');
$acl = new RagAclService();

$result = $acl->replaceKnowledgeBaseAcl($rdAdmin, 1, [
    'visibility' => 'department',
    'grants' => [
        ['subject_type' => 'department', 'subject_id' => 2, 'permission' => 'write', 'include_descendants' => false],
        ['subject_type' => 'user', 'subject_id' => 10, 'permission' => 'admin', 'expires_at' => '2099-01-01T00:00:00Z'],
    ],
]);
$grants = array_map(static fn (array $g): string => $g['subject_type'] . ':' . $g['subject_id'] . ':' . $g['permission'] . ':' . ($g['editable'] ? 'e' : 'ro'), $result['grants']);
sort($grants);
assert_same(['department:2:write:e', 'department:3:read:ro', 'user:10:admin:e'], $grants, 'Out-of-scope grant (财务) is kept and shown read-only; new grants are written.');
assert_same('department', $result['knowledge_base']['visibility'], 'Visibility is updated.');
assert_same(1, (int) $pdo->query("SELECT granted_by_user_id FROM rag_knowledge_base_bindings WHERE subject_type = 'user' AND subject_id = 10")->fetchColumn(), 'Granting admin is recorded.');
assert_same(0, (int) $pdo->query("SELECT include_descendants FROM rag_knowledge_base_bindings WHERE subject_type = 'department' AND subject_id = 2")->fetchColumn(), 'include_descendants=false is stored.');
assert_same('2099-01-01 00:00:00', (string) $pdo->query("SELECT expires_at FROM rag_knowledge_base_bindings WHERE subject_type = 'user'")->fetchColumn(), 'Expiry is normalised to UTC.');

// Removing a grant from the list deletes it (only in-scope ones).
$acl->replaceKnowledgeBaseAcl($rdAdmin, 1, ['grants' => [['subject_type' => 'department', 'subject_id' => 2, 'permission' => 'read']]]);
assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM rag_knowledge_base_bindings WHERE kb_id = 1')->fetchColumn(), 'User grant removed, 财务 grant kept.');

rag_expect_abort(static fn () => $acl->replaceKnowledgeBaseAcl($rdAdmin, 1, ['grants' => [['subject_type' => 'user', 'subject_id' => 30, 'permission' => 'read']]]), 'ADMIN_PERMISSION_DENIED', 'Granting to a user outside the scope is refused.');
rag_expect_abort(static fn () => $acl->replaceKnowledgeBaseAcl($rdAdmin, 2, ['grants' => []]), 'ADMIN_PERMISSION_DENIED', 'Editing another department\'s knowledge base is refused.');
rag_expect_abort(static fn () => $acl->replaceKnowledgeBaseAcl($rdAdmin, 1, ['grants' => [['subject_type' => 'department', 'subject_id' => 999, 'permission' => 'read']]]), 'RAG_ACL_INVALID', 'Unknown subjects are rejected.');
rag_expect_abort(static fn () => $acl->replaceKnowledgeBaseAcl($rdAdmin, 1, ['visibility' => 'public', 'grants' => []]), 'RAG_ACL_INVALID', 'Unknown visibility is rejected.');

// Dataset custom mode: the core design dataset only for user 10.
$acl->replaceDatasetAcl($rdAdmin, 12, ['acl_mode' => 'custom', 'grants' => [['subject_type' => 'user', 'subject_id' => 10, 'permission' => 'read', 'is_default' => true, 'priority' => 1]]]);
assert_same('custom', (string) $pdo->query('SELECT acl_mode FROM rag_vector_datasets WHERE id = 12')->fetchColumn(), 'acl_mode is updated.');

// Access check: user 10 (平台, under 研发) sees ds_a via department visibility/grant and ds_b via own grant.
$check = $acl->accessCheck($rdAdmin, ['user_id' => 10]);
assert_same(['研发', '平台'], array_column($check['department_chain'], 'name'), 'Department chain is reported root first.');
$rd = array_values(array_filter($check['knowledge_bases'], static fn (array $kb): bool => $kb['kb_id'] === 'kb_rd'))[0] ?? null;
assert_true($rd !== null, 'The R&D knowledge base is accessible.');
assert_same(['ds_a', 'ds_b'], array_column($rd['datasets'], 'dataset_id'), 'Both datasets are reachable for user 10.');

$keyCheck = $acl->accessCheck($rdAdmin, ['api_key_id' => 100]);
assert_true($keyCheck['restricted'] === false, 'A key without explicit grants is not restricted.');

rag_expect_abort(static fn () => $acl->accessCheck($rdAdmin, ['user_id' => 30]), 'ADMIN_PERMISSION_DENIED', 'Checking a user outside the scope is refused.');

echo "PASS\n";
