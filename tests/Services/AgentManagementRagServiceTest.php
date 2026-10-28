<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AgentManagementRagService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(5));
$departmentId = null;
$userId = null;
$apiKeyId = null;
$kbId = null;
$datasetId = null;

try {
    $db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, "/", 0, "active")', ["agent-rag-{$suffix}"]);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute('UPDATE departments SET path = ? WHERE id = ?', ["/{$departmentId}/", $departmentId]);
    $db->execute('INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")', [$departmentId, "agent-rag-{$suffix}", password_hash('secret', PASSWORD_DEFAULT)]);
    $userId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, ?, ?, ?, "active")', [$userId, $departmentId, 'agent-rag', 'tkh_test', 'test', hash('sha256', $suffix)]);
    $apiKeyId = (int) $db->pdo()->lastInsertId();
    $apiKey = ['id' => $apiKeyId, 'user_id' => $userId, 'department_id' => $departmentId];

    $service = new AgentManagementRagService();
    $kb = $service->createKnowledgeBase($apiKey, ['name' => 'Agent test KB']);
    $kbId = (int) $kb['id'];
    assert_same($userId, (int) ($kb['owner_user_id'] ?? 0), 'A management-created knowledge base should default to the managing API key user as owner.');
    $kbBinding = $db->first('SELECT permission FROM rag_knowledge_base_bindings WHERE kb_id = ? AND subject_type = "api_key" AND subject_id = ?', [$kbId, $apiKeyId]);
    assert_same('admin', $kbBinding['permission'] ?? null, 'Creating a knowledge base should grant the managing API key admin access.');

    $dataset = $service->createDataset($apiKey, ['kb_id' => $kbId, 'name' => 'Agent test dataset']);
    $datasetId = (int) $dataset['id'];
    $datasetBinding = $db->first('SELECT permission FROM rag_dataset_bindings WHERE dataset_id = ? AND subject_type = "api_key" AND subject_id = ?', [$datasetId, $apiKeyId]);
    assert_same('admin', $datasetBinding['permission'] ?? null, 'Creating a dataset should grant the managing API key admin access.');

    $service->deleteDataset($datasetId, $apiKey);
    assert_same(0, $service->datasets($apiKey, ['limit' => 100])['pagination']['total'], 'Deleted datasets must not remain in management lists.');
} finally {
    if ($datasetId !== null) $db->execute('DELETE FROM rag_dataset_bindings WHERE dataset_id = ?', [$datasetId]);
    if ($datasetId !== null) $db->execute('DELETE FROM rag_vector_datasets WHERE id = ?', [$datasetId]);
    if ($kbId !== null) $db->execute('DELETE FROM rag_knowledge_base_bindings WHERE kb_id = ?', [$kbId]);
    if ($kbId !== null) $db->execute('DELETE FROM rag_knowledge_bases WHERE id = ?', [$kbId]);
    if ($apiKeyId !== null) $db->execute('DELETE FROM api_keys WHERE id = ?', [$apiKeyId]);
    if ($userId !== null) $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    if ($departmentId !== null) $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
}
