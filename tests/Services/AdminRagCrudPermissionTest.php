<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$crud = file_get_contents(__DIR__ . '/../../app/Services/CrudService.php') ?: '';
$permission = file_get_contents(__DIR__ . '/../../app/Services/PermissionService.php') ?: '';

$ragAdminTables = [
    'rag_knowledge_bases',
    'rag_knowledge_base_bindings',
    'rag_vector_datasets',
    'rag_dataset_bindings',
    'rag_vector_configs',
    'rag_import_templates',
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

foreach ($ragAdminTables as $table) {
    assert_true(str_contains($crud, "'" . $table . "'"), 'CrudService should allow admin RAG table ' . $table . '.');
}

foreach ([
    'rag_knowledge_bases',
    'rag_uploads',
    'rag_retrieval_logs',
    'rag_answer_feedback',
] as $table) {
    assert_true(str_contains($permission, "'" . $table . "'"), 'PermissionService should scope department-owned RAG table ' . $table . '.');
}

foreach ([
    'rag_vector_datasets',
    'rag_documents',
    'rag_chunks',
    'rag_image_assets',
    'rag_embedding_jobs',
    'rag_vector_records',
] as $table) {
    assert_true(str_contains($permission, "'" . $table . "'"), 'PermissionService should scope KB-owned RAG table ' . $table . '.');
}
