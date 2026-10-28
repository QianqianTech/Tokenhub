<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\ExternalFileService;
use TokenHub\Services\ExternalModelService;
use TokenHub\Services\RagPermissionService;
use TokenHub\Services\WebhookService;
use TokenHub\Support\App;
use TokenHub\Http\Controllers\ExternalController;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

$db = App::db();
$suffix = bin2hex(random_bytes(4));

try {
$db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, ?, 0, "active")', ['Gap Dept ' . $suffix, '/gap-' . $suffix . '/']);
$departmentId = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, "hash", "user", "active")', [$departmentId, 'gap_user_' . $suffix]);
$userId = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "gap", "test", ?, "active")', [$userId, $departmentId, 'Gap Key ' . $suffix, hash('sha256', 'gap-key-' . $suffix)]);
$apiKeyId = (int) $db->pdo()->lastInsertId();
$apiKey = ['id' => $apiKeyId, 'user_id' => $userId, 'department_id' => $departmentId];
App::context()->setApiKey($apiKey);

$providerName = 'Gap Provider ' . $suffix;
$db->execute('INSERT INTO providers (name, protocol, base_url, encrypted_secret, status, timeout_ms) VALUES (?, "openai", "https://provider.example/v1", "", "active", 1500)', [$providerName]);
$providerId = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO models (provider_id, name, external_name, capability, status, route_priority, context_length) VALUES (?, ?, ?, "text_generation", "active", 1, 8192)', [$providerId, 'gap-chat-' . $suffix, 'gap-chat-ext']);
$modelId = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO api_key_model_permissions (api_key_id, model_id) VALUES (?, ?)', [$apiKey['id'], $modelId]);

$modelService = new ExternalModelService();
$authorizedModels = $modelService->authorized($apiKey);
$firstModel = $authorizedModels['data'][0] ?? null;
assert_true(is_array($authorizedModels['data'] ?? null), 'Authorized model list should expose an OpenAI-compatible data array.');
assert_same('gap-chat-' . $suffix, $firstModel['id'] ?? null, 'OpenAI-compatible model id should be the TokenHub model name.');
assert_true(!isset($authorizedModels['models']), 'Authorized model list should not require clients to read data.models.');

$modelsResponse = (new ExternalController())->models(new Request('GET', '/v1/models', [], [], [], '', new RequestContext('req_gap_models_' . $suffix, 'trc_gap_models_' . $suffix)));
$modelsPayload = json_decode($modelsResponse->body, true);
assert_true(is_array($modelsPayload['data'] ?? null), 'GET /v1/models response should expose a top-level data array.');
assert_same('gap-chat-' . $suffix, $modelsPayload['data'][0]['id'] ?? null, 'GET /v1/models response should expose model name as id.');
assert_true(!isset($modelsPayload['ok']), 'GET /v1/models response should not use the TokenHub ok/data/request_id envelope.');

$detail = $modelService->detail($apiKey, 'gap-chat-' . $suffix);
assert_same('gap-chat-' . $suffix, $detail['model']['name'], 'Model detail should resolve authorized model by name.');
assert_same('active', $detail['model']['health']['status'], 'Model detail should expose active route health.');

$health = $modelService->health($apiKey);
assert_true(isset($health['models'][0]['model']), 'Model health should expose per-model entries.');
assert_true(isset($health['summary']['active']), 'Model health should include active summary count.');

$fileService = new ExternalFileService();
$uploaded = $fileService->upload($apiKey, [
    'file_name' => 'input-image.png',
    'mime_type' => 'image/png',
    'b64_data' => base64_encode('image-bytes'),
]);
$files = $fileService->list($apiKey, ['type' => 'file']);
assert_true(count($files['files']) >= 1, 'File list should return API-key owned files.');
assert_same($uploaded['file_id'], $files['files'][0]['file_id'], 'File list should include uploaded file id.');

$webhookService = new WebhookService();
$webhook = $webhookService->create($apiKey, ['url' => 'https://example.com/tokenhub-webhook', 'event_types' => ['request.completed']]);
$testDelivery = $webhookService->test($apiKey, (string) $webhook['id']);
assert_same('webhook.test', $testDelivery['event_type'], 'Webhook test should create a diagnostic delivery.');

$deliveries = $webhookService->deliveries($apiKey, (string) $webhook['id']);
assert_true(count($deliveries['deliveries']) >= 1, 'Webhook delivery list should expose delivery attempts.');
assert_same($testDelivery['delivery_id'], $deliveries['deliveries'][0]['delivery_id'], 'Webhook delivery list should include test delivery.');

$retried = $webhookService->retryDelivery($apiKey, (string) $testDelivery['delivery_id']);
assert_same((string) $testDelivery['delivery_id'], $retried['delivery_id'], 'Webhook retry should return delivery id.');
assert_same('pending', $retried['status'], 'Webhook retry should reset delivery to pending.');

$kbId = 'kb_gap_' . $suffix;
$datasetId = 'ds_gap_' . $suffix;
$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, visibility, status) VALUES (?, ?, ?, "shared", "active")', [$kbId, 'Gap KB ' . $suffix, $departmentId]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status, chunk_count, vector_count) VALUES (?, ?, ?, ?, "active", 3, 2)', [$datasetId, $kbPk, 'Gap DS ' . $suffix, 'gap_' . $suffix]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "read", 1)', [$kbPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "read", 1)', [$datasetPk, $apiKey['id']]);

$ragPermission = new RagPermissionService();
$knowledgeBases = $ragPermission->listKnowledgeBases($apiKey);
assert_same($kbId, $knowledgeBases['knowledge_bases'][0]['kb_id'], 'RAG knowledge-base discovery should expose authorized KBs.');

$datasets = $ragPermission->listDatasets($apiKey, $kbId);
assert_same($datasetId, $datasets['datasets'][0]['dataset_id'], 'RAG dataset discovery should expose authorized datasets.');
} finally {
    cleanup_synthetic_rag_test_data();
    App::context()->setApiKey(null);
}
