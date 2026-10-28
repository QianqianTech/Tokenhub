<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$controller = __DIR__ . '/../../app/Http/Controllers/AgentManagementController.php';
$middleware = __DIR__ . '/../../app/Middleware/AgentManagementAuthMiddleware.php';

assert_true(is_file($controller), 'Agent management controller should exist.');
assert_true(is_file($middleware), 'Agent management routes should use dedicated scope middleware.');

foreach ([
    "get('/v1/management/users'",
    "post('/v1/management/users'",
    "patch('/v1/management/users/{id}'",
    "delete('/v1/management/users/{id}'",
    "get('/v1/management/api-keys'",
    "post('/v1/management/api-keys'",
    "put('/v1/management/api-keys/{id}/models'",
    "delete('/v1/management/api-keys/{id}'",
    "get('/v1/management/rag/knowledge-bases'",
    "post('/v1/management/rag/knowledge-bases'",
    "patch('/v1/management/rag/knowledge-bases/{id}'",
    "delete('/v1/management/rag/knowledge-bases/{id}'",
    "get('/v1/management/rag/datasets'",
    "post('/v1/management/rag/datasets'",
    "patch('/v1/management/rag/datasets/{id}'",
    "delete('/v1/management/rag/datasets/{id}'",
] as $route) {
    assert_true(str_contains($kernel, $route), 'Kernel should register agent management route ' . $route . '.');
}

assert_true(str_contains($kernel, 'new AgentManagementAuthMiddleware('), 'Management routes should require scoped API Key authentication.');
$controllerSource = file_get_contents($controller) ?: '';
assert_true(str_contains($controllerSource, 'model_ids must be a non-empty array'), 'Model permission replacement must reject missing or malformed model_ids instead of clearing permissions.');
assert_true(str_contains($controllerSource, '((int) $id) <= 0'), 'Model permission replacement must reject zero and negative model IDs.');
$apiKeyServiceSource = file_get_contents(__DIR__ . '/../../app/Services/AgentManagementApiKeyService.php') ?: '';
assert_true(str_contains($apiKeyServiceSource, 'FROM models WHERE status ='), 'Model permission replacement must reject unknown or inactive models before replacing existing permissions.');
$externalApiGuide = file_get_contents(__DIR__ . '/../../docs/external-api-sync.md') ?: '';
assert_true(str_contains($externalApiGuide, 'agent-management-api.md'), 'The main external API guide must link to the agent-management API guide.');
