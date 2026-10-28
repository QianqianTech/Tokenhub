<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/RagController.php') ?: '';
$openapiPath = __DIR__ . '/../../public/openapi.json';
$docs = file_get_contents(__DIR__ . '/../../docs/external-api-sync.md') ?: '';
$migration = file_get_contents(__DIR__ . '/../../database/migrations/2026_07_09_000002_rag_knowledge_base.sql') ?: '';
$composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

foreach ([
    "get('/v1/rag/import-templates'",
    "get('/v1/rag/import-templates/{template_id}/download'",
    "get('/v1/rag/knowledge-bases'",
    "get('/v1/rag/knowledge-bases/{kb_id}/datasets'",
    "post('/v1/rag/knowledge-bases/{kb_id}/uploads'",
    "get('/v1/rag/uploads/{upload_id}'",
    "get('/v1/rag/uploads/{upload_id}/preview'",
    "post('/v1/rag/uploads/{upload_id}/confirm-vectorization'",
    "get('/v1/rag/retrievals/{retrieval_id}'",
    "post('/v1/rag/feedback'",
    "get('/admin-api/rag/uploads/{upload_id}/preview'",
    "get('/admin-api/rag/uploads/{upload_id}'",
    "get('/admin-api/rag/import-templates'",
    "get('/admin-api/rag/import-templates/{template_id}/download'",
    "post('/admin-api/rag/knowledge-bases/{kb_id}/uploads'",
    "post('/admin-api/rag/image-assets/{image_id}/reprocess-ai'",
    "post('/admin-api/rag/uploads/{upload_id}/confirm-vectorization'",
    "post('/admin-api/rag-vector-configs/test'",
    "get('/admin-api/rag/summary'",
    "get('/admin-api/rag/vectorization-metrics'",
    "get('/admin-api/rag/evaluation-summary'",
    "post('/admin-api/rag/evaluation-runs'",
    "get('/admin-api/rag/retrieval-quality'",
] as $route) {
    assert_true(str_contains($kernel, $route), 'Kernel should register RAG route ' . $route . '.');
}

assert_true(str_contains($kernel, 'RagController'), 'Kernel should mount RagController.');
assert_true(str_contains($kernel, 'new ApiKeyAuthMiddleware()'), 'RAG external routes should use API key middleware.');
assert_true(str_contains($controller, 'function retrievalQuality'), 'RAG controller should expose the retrieval-quality summary action.');
assert_true(str_contains($controller, 'function testVectorConfig'), 'RAG controller should expose the vector configuration probe action.');

foreach ([
    'RagPermissionService',
    'RagTemplateService',
    'RagUploadService',
    'RagExcelImportService',
    'RagImageProcessingService',
    'RagChunkingService',
    'RagEmbeddingJobService',
    'RagVectorConfigService',
    'RagRetrievalService',
    'RagPromptInjector',
    'RagFeedbackService',
    'RagCorrectionService',
    'RagRetentionPolicyService',
    'RagVectorDeletionService',
    'RagAiImageAnnotationService',
    'RagEmbeddingProviderService',
    'RagVectorizationMetricsService',
    'RagRetrievalEvaluationService',
    'RagRetrievalQualityService',
] as $service) {
    assert_true(is_file(__DIR__ . '/../../app/Services/' . $service . '.php'), $service . ' should exist.');
}

foreach ([
    'rag_knowledge_bases',
    'rag_vector_datasets',
    'rag_dataset_bindings',
    'rag_uploads',
    'rag_upload_preview_items',
    'rag_chunks',
    'rag_image_assets',
    'rag_embedding_jobs',
    'rag_vector_records',
    'rag_retrieval_logs',
    'rag_answer_feedback',
    'rag_correction_tasks',
    'rag_retention_policies',
    'rag_eval_cases',
    'rag_eval_runs',
    'rag_eval_results',
] as $table) {
    assert_true(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), 'RAG migration should create ' . $table . '.');
}

assert_true(isset($composer['require']['phpoffice/phpspreadsheet']), 'composer.json should require phpoffice/phpspreadsheet.');

$openapi = json_decode((string) file_get_contents($openapiPath), true);
assert_true(is_array($openapi), 'OpenAPI document should be valid JSON.');
foreach ([
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
    assert_true(isset($openapi['paths'][$path]), 'OpenAPI document should expose ' . $path . '.');
}

$openapiJson = file_get_contents($openapiPath) ?: '';
foreach ([
    'tpl_image_package_v1',
    'manifest_zip',
    'RAG_PACKAGE_INVALID',
    'RAG_PACKAGE_MANIFEST_MISSING',
    'RAG_PACKAGE_MANIFEST_CONFLICT',
    'RAG_PACKAGE_PATH_INVALID',
    'RAG_PACKAGE_IMAGE_MISSING',
    'RAG_IMAGE_UNSUPPORTED_FORMAT',
    'RAG_IMAGE_ANNOTATION_REQUIRED',
    'RAG_AI_ANNOTATION_FAILED',
    'RAG_IMAGE_EMBEDDING_FAILED',
    'RAG_MULTIMODAL_EMBEDDING_FAILED',
    'RAG_VECTOR_SUCCESS_RATE_LOW',
    'RAG_MODEL_NOT_CONFIGURED',
    'RAG_EVAL_NO_CASES',
] as $ragPhaseTwoToken) {
    assert_true(str_contains($openapiJson, $ragPhaseTwoToken), 'OpenAPI document should describe RAG phase-two token ' . $ragPhaseTwoToken . '.');
}

foreach ([
    'GET /v1/rag/import-templates',
    'GET /v1/rag/knowledge-bases',
    'GET /v1/rag/knowledge-bases/{kb_id}/datasets',
    'POST /v1/rag/knowledge-bases/{kb_id}/uploads',
    'GET /v1/rag/uploads/{upload_id}/preview',
    'POST /v1/rag/uploads/{upload_id}/confirm-vectorization',
    'POST /v1/rag/feedback',
    'image_package',
    'manifest_zip',
    'RAG_PACKAGE_IMAGE_MISSING',
] as $endpoint) {
    assert_true(str_contains($docs, $endpoint), 'External API docs should document ' . $endpoint . '.');
}
