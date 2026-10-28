<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagPermissionService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$rootDepartmentId = 0;
$childDepartmentId = 0;
$otherDepartmentId = 0;
$userId = 0;
$apiKeyId = 0;
$otherUserId = 0;
$otherApiKeyId = 0;
$kbId = 'kb_dept_scope_' . $suffix;
$datasetId = 'ds_dept_scope_' . $suffix;
$kbPk = 0;
$datasetPk = 0;

try {
    $rootPath = '/rag-dept-scope-' . $suffix . '/';
    $childPath = $rootPath . 'delivery/';
    $db->execute(
        'INSERT INTO departments (name, path, level, status) VALUES (?, ?, 1, "active")',
        ['RAG Scope Root ' . $suffix, $rootPath]
    );
    $rootDepartmentId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO departments (parent_id, name, path, level, status) VALUES (?, ?, ?, 2, "active")',
        [$rootDepartmentId, 'RAG Scope Child ' . $suffix, $childPath]
    );
    $childDepartmentId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO departments (name, path, level, status) VALUES (?, ?, 1, "active")',
        ['RAG Scope Other Root ' . $suffix, '/rag-dept-other-' . $suffix . '/']
    );
    $otherDepartmentId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, "hash", "user", "active")',
        [$childDepartmentId, 'rag_scope_user_' . $suffix]
    );
    $userId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "scope", "test", ?, "active")',
        [$userId, $childDepartmentId, 'RAG Scope Key ' . $suffix, hash('sha256', 'rag-scope-' . $suffix)]
    );
    $apiKeyId = (int) $db->pdo()->lastInsertId();
    $apiKey = ['id' => $apiKeyId, 'user_id' => $userId, 'department_id' => $childDepartmentId];
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, "hash", "user", "active")',
        [$otherDepartmentId, 'rag_scope_other_user_' . $suffix]
    );
    $otherUserId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "scope", "other", ?, "active")',
        [$otherUserId, $otherDepartmentId, 'RAG Scope Other Key ' . $suffix, hash('sha256', 'rag-scope-other-' . $suffix)]
    );
    $otherApiKeyId = (int) $db->pdo()->lastInsertId();
    $otherApiKey = ['id' => $otherApiKeyId, 'user_id' => $otherUserId, 'department_id' => $otherDepartmentId];

    $db->execute(
        'INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, ?, "active")',
        [$kbId, 'RAG Scope KB ' . $suffix, $rootDepartmentId]
    );
    $kbPk = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")',
        [$datasetId, $kbPk, 'RAG Scope Dataset ' . $suffix, 'rag_scope_' . $suffix]
    );
    $datasetPk = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "department", ?, "read")',
        [$kbPk, $rootDepartmentId]
    );
    $db->execute(
        'INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "department", ?, "read", 1)',
        [$datasetPk, $rootDepartmentId]
    );

    $permission = new RagPermissionService();
    assert_true(
        $permission->hasKnowledgeBasePermission($apiKey, $kbPk, 'read'),
        'An API key in a descendant department should inherit its ancestor knowledge-base binding.'
    );
    assert_true(
        $permission->hasDatasetPermission($apiKey, $datasetPk, 'read'),
        'An API key in a descendant department should inherit its ancestor dataset binding.'
    );
    assert_true(
        !$permission->hasKnowledgeBasePermission($otherApiKey, $kbPk, 'read'),
        'An API key in another department tree must not inherit this knowledge-base binding.'
    );
    assert_true(
        !$permission->hasDatasetPermission($otherApiKey, $datasetPk, 'read'),
        'An API key in another department tree must not inherit this dataset binding.'
    );
    $knowledgeBases = $permission->listKnowledgeBases($apiKey);
    assert_same($kbId, $knowledgeBases['knowledge_bases'][0]['kb_id'], 'Descendant department should discover the ancestor-authorized knowledge base.');
    $datasets = $permission->resolveDatasets($apiKey, [], [], [$kbId]);
    assert_same($datasetId, $datasets[0]['dataset_id'], 'Descendant department should resolve the ancestor-authorized dataset.');
} finally {
    if ($datasetPk > 0) {
        $db->execute('DELETE FROM rag_dataset_bindings WHERE dataset_id = ?', [$datasetPk]);
        $db->execute('DELETE FROM rag_vector_datasets WHERE id = ?', [$datasetPk]);
    }
    if ($kbPk > 0) {
        $db->execute('DELETE FROM rag_knowledge_base_bindings WHERE kb_id = ?', [$kbPk]);
        $db->execute('DELETE FROM rag_knowledge_bases WHERE id = ?', [$kbPk]);
    }
    if ($apiKeyId > 0) {
        $db->execute('DELETE FROM api_keys WHERE id = ?', [$apiKeyId]);
    }
    if ($otherApiKeyId > 0) {
        $db->execute('DELETE FROM api_keys WHERE id = ?', [$otherApiKeyId]);
    }
    if ($userId > 0) {
        $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
    if ($otherUserId > 0) {
        $db->execute('DELETE FROM users WHERE id = ?', [$otherUserId]);
    }
    if ($childDepartmentId > 0) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$childDepartmentId]);
    }
    if ($rootDepartmentId > 0) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$rootDepartmentId]);
    }
    if ($otherDepartmentId > 0) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$otherDepartmentId]);
    }
}
