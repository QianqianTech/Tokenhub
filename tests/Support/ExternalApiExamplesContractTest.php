<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$openapiPath = $root . '/public/openapi.json';
$guidePath = $root . '/docs/external-api-complete.md';
$examplesDir = $root . '/examples/external-api';

assert_true(is_file($openapiPath), 'External OpenAPI file should exist.');
$openapi = json_decode((string) file_get_contents($openapiPath), true);
assert_true(is_array($openapi), 'External OpenAPI file should be valid JSON.');
assert_same('3.1.0', $openapi['openapi'] ?? null, 'OpenAPI version should be 3.1.0.');

foreach ([
    '/v1/me',
    '/v1/models',
    '/v1/quota',
    '/v1/chat/completions',
    '/v1/responses',
    '/v1/messages',
    '/v1/embeddings',
    '/v1/audio/transcriptions',
    '/v1/audio/speech',
    '/v1/images/generations',
    '/v1/videos/generations',
    '/v1/tasks/{id}',
    '/v1/tasks/{id}:refresh',
    '/v1/assets/{id}/download-url',
    '/v1/sync/devices',
    '/v1/sync/manifest',
    '/v1/sync/records',
    '/v1/sync/records:batch',
    '/v1/sync/records/{namespace}/{client_record_id}',
    '/v1/files',
    '/v1/assets',
    '/v1/usage/summary',
    '/v1/usage/trends',
    '/v1/usage/records',
    '/v1/requests/{request_id}',
    '/v1/capabilities',
    '/v1/events',
    '/v1/webhooks',
    '/v1/webhooks/{id}/deliveries',
    '/v1/webhooks/{id}:test',
    '/v1/webhook-deliveries/{delivery_id}:retry',
    '/v1/feedback',
    '/v1/rag/import-templates',
    '/v1/rag/import-templates/{template_id}/download',
    '/v1/rag/knowledge-bases',
    '/v1/rag/knowledge-bases/{kb_id}/datasets',
    '/v1/rag/knowledge-bases/{kb_id}/uploads',
    '/v1/rag/uploads/{upload_id}',
    '/v1/rag/uploads/{upload_id}/preview',
    '/v1/rag/uploads/{upload_id}/confirm-vectorization',
    '/v1/rag/retrievals/{retrieval_id}',
    '/v1/rag/feedback',
] as $path) {
    assert_true(isset($openapi['paths'][$path]), 'OpenAPI should document ' . $path . '.');
}

foreach ([
    'ErrorEnvelope',
    'ChatCompletionRequest',
    'ResponseRequest',
    'EmbeddingRequest',
    'VideoGenerationRequest',
    'TaskStatus',
    'SyncRecordUpsertRequest',
    'FileUploadRequest',
    'WebhookCreateRequest',
    'RagUploadRequest',
    'RagUploadStatus',
    'RagPreviewPage',
    'RagRetrieval',
    'RagFeedbackRequest',
] as $schema) {
    assert_true(isset($openapi['components']['schemas'][$schema]), 'OpenAPI should define schema ' . $schema . '.');
}

$videoExamples = json_encode($openapi['paths']['/v1/videos/generations']['post'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
assert_true(str_contains((string) $videoExamples, 'seedance-video'), 'Video generation OpenAPI should include a Seedance example.');
assert_true(str_contains((string) $videoExamples, 'image_url'), 'Video generation OpenAPI should include image-to-video input.');

assert_true(is_file($guidePath), 'Complete external API guide should exist.');
$guide = (string) file_get_contents($guidePath);
foreach ([
    'TokenHub External API Complete Guide',
    'POST /v1/videos/generations',
    'Seedance',
    'GET /v1/tasks/{id}',
    'POST /v1/tasks/{id}:refresh',
    'POST /v1/sync/records:batch',
    'POST /v1/files',
    'GET /v1/assets',
    'POST /v1/webhooks',
    'GET /v1/webhooks/{id}/deliveries',
    'X-TokenHub-Signature',
    'RAG API',
    'GET /v1/rag/knowledge-bases',
    'POST /v1/rag/knowledge-bases/{kb_id}/uploads',
    'POST /v1/rag/uploads/{upload_id}/confirm-vectorization',
    'GET /v1/rag/retrievals/{retrieval_id}',
    'examples/external-api/curl.sh',
    'examples/external-api/python_client.py',
    'examples/external-api/node_client.mjs',
    'examples/external-api/php_client.php',
] as $needle) {
    assert_true(str_contains($guide, $needle), 'Complete guide should include ' . $needle . '.');
}

foreach ([
    'curl.sh' => ['TOKENHUB_BASE_URL', '/v1/videos/generations', '/v1/tasks/', '/v1/sync/records:batch', '/v1/webhooks', '/v1/rag/knowledge-bases/', '/v1/rag/retrievals/'],
    'python_client.py' => ['class TokenHubClient', 'generate_seedance_video', 'poll_task', 'sync_batch', 'create_webhook', 'rag_upload', 'rag_confirm_vectorization', 'rag_retrieval'],
    'node_client.mjs' => ['class TokenHubClient', 'generateSeedanceVideo', 'pollTask', 'syncBatch', 'createWebhook', 'ragUpload', 'ragConfirmVectorization', 'ragRetrieval'],
    'php_client.php' => ['final class TokenHubClient', 'generateSeedanceVideo', 'pollTask', 'syncBatch', 'createWebhook', 'ragUpload', 'ragConfirmVectorization', 'ragRetrieval'],
] as $file => $needles) {
    $path = $examplesDir . '/' . $file;
    assert_true(is_file($path), 'Example file should exist: ' . $file . '.');
    $source = (string) file_get_contents($path);
    foreach ($needles as $needle) {
        assert_true(str_contains($source, $needle), $file . ' should include ' . $needle . '.');
    }
    assert_true(!str_contains($source, 'sk-') && !str_contains($source, 'tkh_live_'), $file . ' should not contain real-looking secrets.');
}
