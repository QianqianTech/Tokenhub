<?php

declare(strict_types=1);

use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\RagTemplateService;
use TokenHub\Support\App;

require __DIR__ . '/../vendor/autoload.php';

App::bootstrap(dirname(__DIR__));

$requiredTables = [
    'rag_knowledge_bases',
    'rag_vector_datasets',
    'rag_uploads',
    'rag_upload_preview_items',
    'rag_documents',
    'rag_chunks',
    'rag_image_assets',
    'rag_embedding_jobs',
    'rag_vector_records',
    'rag_retrieval_logs',
    'rag_answer_feedback',
    'rag_correction_tasks',
    'rag_retention_policies',
];

foreach ($requiredTables as $table) {
    $row = App::db()->first('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    if ((int) ($row['total'] ?? 0) !== 1) {
        throw new RuntimeException("Missing RAG table: {$table}");
    }
}

$templates = (new RagTemplateService())->index();
$templateIds = array_column($templates['templates'] ?? [], 'template_id');
foreach (['tpl_text_v1', 'tpl_qa_v1', 'tpl_image_v1'] as $templateId) {
    if (!in_array($templateId, $templateIds, true)) {
        throw new RuntimeException("Missing RAG template: {$templateId}");
    }
}

$qdrant = new QdrantVectorStore();
if (!$qdrant->healthy()) {
    throw new RuntimeException('Qdrant health check failed. Run: docker compose up -d qdrant');
}

$openapi = json_decode((string) file_get_contents(base_path('public/openapi.json')), true);
if (!is_array($openapi) || !isset($openapi['paths']['/v1/rag/import-templates'])) {
    throw new RuntimeException('OpenAPI RAG paths are missing.');
}

fwrite(STDOUT, "RAG smoke passed: schema, templates, Qdrant, OpenAPI.\n");
