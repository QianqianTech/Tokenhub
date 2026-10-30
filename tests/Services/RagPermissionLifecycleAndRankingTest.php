<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagPermissionService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999)];
$kbPk = 0;
$datasetPk = 0;

try {
    $kbId = 'kb_content_chunk_permission_' . $suffix;
    $datasetId = 'ds_content_chunk_permission_' . $suffix;
    $db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, 1, "active")', [$kbId, 'Permission KB ' . $suffix]);
    $kbPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Permission DS ' . $suffix, 'permission_' . $suffix]);
    $datasetPk = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "read")', [$kbPk, $apiKey['id']]);
    $db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "user", ?, "admin")', [$kbPk, $apiKey['user_id']]);
    $db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "read", 1)', [$datasetPk, $apiKey['id']]);
    $db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission) VALUES (?, "user", ?, "write")', [$datasetPk, $apiKey['user_id']]);

    $permission = new RagPermissionService();
    // Restriction mode (D-RAG-1): a key with explicit grants sees only its own grants.
    $knowledgeBases = $permission->listKnowledgeBases($apiKey);
    assert_same('read', $knowledgeBases['knowledge_bases'][0]['permission'] ?? null, 'A key with explicit grants is limited to them, not raised by its user\'s admin grant.');
    $datasets = $permission->listDatasets($apiKey, $kbId);
    assert_same('read', $datasets['datasets'][0]['permission'] ?? null, 'Dataset permission of a restricted key comes from the key grant only.');
    // Without key grants the highest of the applicable grants wins (explicit read < write < admin ranking);
    // an inherit-mode dataset takes the knowledge-base level when it is higher than its own grant.
    $userCaller = ['id' => 0, 'user_id' => $apiKey['user_id']];
    assert_same('admin', $permission->listKnowledgeBases($userCaller)['knowledge_bases'][0]['permission'] ?? null, 'Knowledge-base list permission must use explicit read/write/admin ranking across bindings.');
    assert_same('admin', $permission->listDatasets($userCaller, $kbId)['datasets'][0]['permission'] ?? null, 'Dataset list permission must use explicit read/write/admin ranking across bindings.');

    $source = file_get_contents(__DIR__ . '/../../app/Services/RagPermissionService.php');
    assert_true(is_string($source), 'Permission service source should be readable for lifecycle query contracts.');
    $knowledgeMethod = substr($source, strpos($source, 'public function knowledgeBase'), strpos($source, 'public function datasetByExternalId') - strpos($source, 'public function knowledgeBase'));
    assert_true(str_contains($knowledgeMethod, 'status != "disabled"'), 'Direct knowledge-base lookup must reject disabled resources.');
    $datasetMethod = substr($source, strpos($source, 'public function datasetByExternalId'), strpos($source, 'public function resolveDatasets') - strpos($source, 'public function datasetByExternalId'));
    assert_true(str_contains($datasetMethod, 'k.deleted_at IS NULL'), 'Direct dataset lookup must reject datasets whose parent knowledge base is deleted.');
    assert_true(str_contains($datasetMethod, 'k.status != "disabled"'), 'Direct dataset lookup must reject datasets whose parent knowledge base is disabled.');

    assert_same($datasetId, $permission->resolveDatasets($apiKey)[0]['dataset_id'] ?? null, 'An active parent knowledge base should expose its default dataset.');
    $db->execute('UPDATE rag_knowledge_bases SET status = "disabled" WHERE id = ?', [$kbPk]);
    assert_same([], $permission->listKnowledgeBases($apiKey)['knowledge_bases'], 'Disabled knowledge bases must disappear from resource lists.');
    assert_same([], $permission->resolveDatasets($apiKey), 'Datasets under a disabled knowledge base must disappear from default dataset resolution.');
} finally {
    if ($datasetPk > 0) {
        $db->execute('DELETE FROM rag_dataset_bindings WHERE dataset_id = ?', [$datasetPk]);
        $db->execute('DELETE FROM rag_vector_datasets WHERE id = ?', [$datasetPk]);
    }
    if ($kbPk > 0) {
        $db->execute('DELETE FROM rag_knowledge_base_bindings WHERE kb_id = ?', [$kbPk]);
        $db->execute('DELETE FROM rag_knowledge_bases WHERE id = ?', [$kbPk]);
    }
}
