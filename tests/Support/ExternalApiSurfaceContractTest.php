<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$docs = file_get_contents(__DIR__ . '/../../docs/external-api-sync.md') ?: '';
$openapiPath = __DIR__ . '/../../public/openapi.json';
$migration = file_get_contents(__DIR__ . '/../../database/migrations/2026_07_08_000015_external_api_integrations.sql') ?: '';

$routes = [
    "get('/v1/me'",
    "get('/v1/models'",
    "get('/v1/quota'",
    "get('/v1/usage/records'",
    "get('/v1/requests/{request_id}'",
    "get('/v1/sync/devices'",
    "delete('/v1/sync/devices/{device_id}'",
    "get('/v1/sync/namespaces'",
    "get('/v1/sync/records/{namespace}/{client_record_id}'",
    "post('/v1/sync/records/{namespace}/{client_record_id}:resolve-conflict'",
    "post('/v1/files'",
    "get('/v1/files/{file_id}/download-url'",
    "delete('/v1/files/{file_id}'",
    "get('/v1/assets'",
    "get('/v1/capabilities'",
    "get('/v1/openapi.json'",
    "get('/v1/events'",
    "post('/v1/webhooks'",
    "get('/v1/webhooks'",
    "get('/v1/webhooks/{id}/deliveries'",
    "post('/v1/webhooks/{id}:test'",
    "post('/v1/webhook-deliveries/{delivery_id}:retry'",
    "delete('/v1/webhooks/{id}'",
    "post('/v1/feedback'",
];

foreach ($routes as $route) {
    assert_true(str_contains($kernel, $route), 'Kernel should register ' . $route . '.');
}

assert_true(str_contains($kernel, 'new ApiKeyAuthMiddleware()'), 'External routes should use API key middleware.');
assert_true(str_contains($kernel, 'ExternalController'), 'Kernel should mount ExternalController.');
assert_true(str_contains($kernel, 'FileController'), 'Kernel should mount FileController.');
assert_true(str_contains($kernel, 'WebhookController'), 'Kernel should mount WebhookController.');

foreach ([
    'ExternalIdentityService',
    'ExternalModelService',
    'ExternalQuotaService',
    'ExternalRequestService',
    'ExternalFileService',
    'ExternalEventService',
    'WebhookService',
    'ExternalFeedbackService',
] as $service) {
    assert_true(is_file(__DIR__ . '/../../app/Services/' . $service . '.php'), $service . ' should exist.');
}

foreach ([
    'GET /v1/me',
    'GET /v1/models',
    'GET /v1/quota',
    'GET /v1/usage/records',
    'GET /v1/requests/{request_id}',
    'GET /v1/sync/devices',
    'DELETE /v1/sync/devices/{device_id}',
    'GET /v1/sync/namespaces',
    'GET /v1/sync/records/{namespace}/{client_record_id}',
    'POST /v1/sync/records/{namespace}/{client_record_id}:resolve-conflict',
    'POST /v1/files',
    'GET /v1/files/{file_id}/download-url',
    'DELETE /v1/files/{file_id}',
    'GET /v1/assets',
    'GET /v1/capabilities',
    'GET /v1/openapi.json',
    'GET /v1/events',
    'POST /v1/webhooks',
    'GET /v1/webhooks',
    'GET /v1/webhooks/{id}/deliveries',
    'POST /v1/webhooks/{id}:test',
    'POST /v1/webhook-deliveries/{delivery_id}:retry',
    'DELETE /v1/webhooks/{id}',
    'POST /v1/feedback',
] as $endpoint) {
    assert_true(str_contains($docs, $endpoint), 'External API docs should document ' . $endpoint . '.');
}

assert_true(str_contains($migration, 'external_feedback'), 'Migration should create external_feedback.');
assert_true(str_contains($migration, 'external_webhooks'), 'Migration should create external_webhooks.');
assert_true(str_contains($migration, 'external_webhook_deliveries'), 'Migration should create external_webhook_deliveries.');
assert_true(str_contains($migration, 'media_assets') && str_contains($migration, 'deleted_at'), 'Migration should add media_assets.deleted_at.');

assert_true(is_file($openapiPath), 'public/openapi.json should exist.');
$openapi = json_decode((string) file_get_contents($openapiPath), true);
assert_true(is_array($openapi), 'OpenAPI document should be valid JSON.');
foreach ([
    '/v1/chat/completions',
    '/v1/responses',
    '/v1/embeddings',
    '/v1/messages',
    '/v1/messages/count_tokens',
    '/v1/audio/transcriptions',
    '/v1/audio/speech',
    '/v1/images/generations',
    '/v1/videos/generations',
    '/v1/tasks/{id}',
    '/v1/tasks/{id}:refresh',
    '/v1/assets/{id}/download-url',
    '/v1/me',
    '/v1/models',
    '/v1/quota',
    '/v1/usage/summary',
    '/v1/usage/trends',
    '/v1/usage/records',
    '/v1/requests/{request_id}',
    '/v1/files',
    '/v1/assets',
    '/v1/webhooks',
    '/v1/webhooks/{id}/deliveries',
    '/v1/webhooks/{id}:test',
    '/v1/webhook-deliveries/{delivery_id}:retry',
    '/v1/feedback',
] as $path) {
    assert_true(isset($openapi['paths'][$path]), 'OpenAPI document should expose ' . $path . '.');
}
