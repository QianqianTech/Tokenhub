<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * RAG-001: department-scoped administrators cannot write RAG rows owned by
 * other departments through the generic admin CRUD API, cannot grant to
 * subjects outside their scope, and cannot change platform RAG config.
 */

use TokenHub\Services\CrudService;
use TokenHub\Services\RagAdminScopeGuard;
use TokenHub\Support\App;

$pdo = rag_sqlite_fixture();
$pdo->exec("INSERT INTO departments (id, parent_id, name, path, level) VALUES (1, NULL, '研发', '/1/', 0), (2, NULL, '财务', '/2/', 0), (3, 1, '平台', '/1/3/', 1)");
$pdo->exec("INSERT INTO users (id, department_id, username, role, managed_department_ids, admin_scope) VALUES (1, 1, 'dept-admin', 'admin', '[1]', 'departments'), (9, 2, 'root', 'super_admin', NULL, 'global'), (11, 3, 'dev', 'user', NULL, NULL), (21, 2, 'fin', 'user', NULL, NULL)");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (110, 11, 3, 'dev'), (210, 21, 2, 'fin')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility) VALUES (1, 'kb_rd', '研发库', 1, 'private'), (2, 'kb_fin', '财务库', 2, 'private'), (3, 'kb_pub', '公共库', 2, 'shared')");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name) VALUES (11, 'ds_rd', 1, 'rd'), (21, 'ds_fin', 2, 'fin')");
$pdo->exec("INSERT INTO rag_documents (id, document_id, kb_id, upload_id, title) VALUES (201, 'doc_fin', 2, 1, 'f')");
$pdo->exec("INSERT INTO rag_chunks (id, chunk_id, kb_id, document_id, content) VALUES (2001, 'chk_fin', 2, 201, 'secret')");
$pdo->exec("INSERT INTO rag_knowledge_base_bindings (id, kb_id, subject_type, subject_id, permission) VALUES (900, 2, 'department', 2, 'read')");

$deptAdmin = App::db()->first('SELECT * FROM users WHERE id = 1');
$guard = new RagAdminScopeGuard();

rag_expect_abort(static fn () => $guard->assertWrite($deptAdmin, 'rag_knowledge_base_bindings', ['kb_id' => 2, 'subject_type' => 'department', 'subject_id' => 1, 'permission' => 'read']), 'ADMIN_PERMISSION_DENIED', 'Granting another department\'s knowledge base to my department is refused.');
rag_expect_abort(static fn () => $guard->assertWrite($deptAdmin, 'rag_knowledge_base_bindings', ['kb_id' => 1, 'subject_type' => 'department', 'subject_id' => 2, 'permission' => 'read']), 'ADMIN_PERMISSION_DENIED', 'Granting my knowledge base to a department outside my scope is refused.');
rag_expect_abort(static fn () => $guard->assertWrite($deptAdmin, 'rag_knowledge_base_bindings', ['kb_id' => 1, 'subject_type' => 'api_key', 'subject_id' => 210, 'permission' => 'read']), 'ADMIN_PERMISSION_DENIED', 'Granting to a foreign API key is refused.');
$guard->assertWrite($deptAdmin, 'rag_knowledge_base_bindings', ['kb_id' => 1, 'subject_type' => 'department', 'subject_id' => 3, 'permission' => 'write']);
$guard->assertWrite($deptAdmin, 'rag_knowledge_base_bindings', ['kb_id' => 1, 'subject_type' => 'user', 'subject_id' => 11, 'permission' => 'read']);
$guard->assertWrite($deptAdmin, 'rag_knowledge_base_bindings', ['kb_id' => 3, 'subject_type' => 'api_key', 'subject_id' => 110, 'permission' => 'read']);
rag_expect_abort(static fn () => $guard->assertWrite($deptAdmin, 'rag_knowledge_base_bindings', ['kb_id' => 3, 'subject_type' => 'api_key', 'subject_id' => 110, 'permission' => 'write']), 'ADMIN_PERMISSION_DENIED', 'A shared knowledge base may only be granted read by an outside admin.');
rag_expect_abort(static fn () => $guard->assertWrite($deptAdmin, 'rag_dataset_bindings', ['dataset_id' => 21, 'subject_type' => 'department', 'subject_id' => 1, 'permission' => 'read']), 'ADMIN_PERMISSION_DENIED', 'Dataset grants resolve the owning knowledge base through the dataset.');
rag_expect_abort(static fn () => $guard->assertWrite($deptAdmin, 'rag_vector_datasets', ['kb_id' => 2, 'name' => 'x']), 'ADMIN_PERMISSION_DENIED', 'Creating a dataset under a foreign knowledge base is refused.');
rag_expect_abort(static fn () => $guard->assertWrite($deptAdmin, 'rag_vector_configs', ['name' => 'x']), 'ADMIN_PERMISSION_DENIED', 'Platform vector configs need a global admin.');
rag_expect_abort(static fn () => $guard->assertExisting($deptAdmin, 'rag_chunks', 2001), 'ADMIN_PERMISSION_DENIED', 'Editing a foreign chunk is refused.');
rag_expect_abort(static fn () => $guard->assertExisting($deptAdmin, 'rag_knowledge_base_bindings', 900), 'ADMIN_PERMISSION_DENIED', 'Changing a foreign grant is refused.');
rag_expect_abort(static fn () => $guard->assertCreateHasOwner($deptAdmin, 'rag_retention_policies', ['name' => 'global']), 'ADMIN_PERMISSION_DENIED', 'A scoped admin cannot create an ownerless (platform-wide) retention policy.');
rag_expect_abort(static fn () => $guard->assertWrite($deptAdmin, 'rag_knowledge_bases', ['name' => 'x', 'department_id' => null]), 'ADMIN_PERMISSION_DENIED', 'A scoped admin cannot create an ownerless knowledge base.');

$root = App::db()->first('SELECT * FROM users WHERE id = 9');
$guard->assertWrite($root, 'rag_knowledge_base_bindings', ['kb_id' => 2, 'subject_type' => 'department', 'subject_id' => 1, 'permission' => 'admin']);
$guard->assertExisting($root, 'rag_chunks', 2001);

// Through the CRUD service: the revoke path deletes the grant row instead of a no-op "disable".
App::context()->setAdminUser($root);
(new CrudService())->disable('rag_knowledge_base_bindings', 900);
assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM rag_knowledge_base_bindings WHERE id = 900')->fetchColumn(), 'Disabling a grant through the CRUD API removes it.');

App::context()->setAdminUser($deptAdmin);
rag_expect_abort(static fn () => (new CrudService())->create('rag_knowledge_base_bindings', ['kb_id' => 2, 'subject_type' => 'department', 'subject_id' => 1, 'permission' => 'read']), 'ADMIN_PERMISSION_DENIED', 'CRUD create path enforces the guard.');
rag_expect_abort(static fn () => (new CrudService())->update('rag_chunks', 2001, ['content' => 'tampered']), 'ADMIN_PERMISSION_DENIED', 'CRUD update path enforces the guard.');
assert_same('secret', (string) $pdo->query('SELECT content FROM rag_chunks WHERE id = 2001')->fetchColumn(), 'The foreign chunk is unchanged.');

echo "PASS\n";
