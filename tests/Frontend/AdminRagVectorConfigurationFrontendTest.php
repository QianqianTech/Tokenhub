<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$table = admin_js_bundle() ?: '';

$vectorStart = strpos($resources, '"rag-vector-configs": {');
$vectorEnd = strpos($resources, '"rag-import-templates": {', $vectorStart);
assert_true($vectorStart !== false && $vectorEnd !== false, 'Vector config resource metadata should be inspectable.');
$vectorConfigResource = substr($resources, $vectorStart, $vectorEnd - $vectorStart);
foreach ([
    'config_id', 'provider_id', 'model_id', 'modality', 'dimension', 'distance_metric', 'vector_store', 'collection_name', 'enabled',
] as $field) {
    assert_true(str_contains($vectorConfigResource, 'name: "' . $field . '"'), 'Vector config editor should include ' . $field . '.');
}
assert_true(!str_contains($vectorConfigResource, 'config_key'), 'Vector config table should not use the stale config_key field.');

$knowledgeBaseStart = strpos($resources, '"rag-knowledge-bases": {');
$knowledgeBaseEnd = strpos($resources, '"rag-knowledge-base-bindings": {', $knowledgeBaseStart);
assert_true($knowledgeBaseStart !== false && $knowledgeBaseEnd !== false, 'Knowledge base resource metadata should be inspectable.');
$knowledgeBaseResource = substr($resources, $knowledgeBaseStart, $knowledgeBaseEnd - $knowledgeBaseStart);
foreach (['default_text_vector_config_id', 'default_image_vector_config_id', 'default_multimodal_vector_config_id'] as $field) {
    assert_true(str_contains($knowledgeBaseResource, 'name: "' . $field . '"'), 'Knowledge base editor should include ' . $field . '.');
}

$datasetStart = strpos($resources, '"rag-vector-datasets": {');
$datasetEnd = strpos($resources, '"rag-dataset-bindings": {', $datasetStart);
assert_true($datasetStart !== false && $datasetEnd !== false, 'Dataset resource metadata should be inspectable.');
$datasetResource = substr($resources, $datasetStart, $datasetEnd - $datasetStart);
foreach (['text_vector_config_id', 'image_vector_config_id', 'multimodal_vector_config_id'] as $field) {
    assert_true(str_contains($datasetResource, 'name: "' . $field . '"'), 'Dataset editor should include ' . $field . '.');
}

assert_true(str_contains($table, '/admin-api/rag-vector-configs/test'), 'Vector config editor should call the server-side probe endpoint.');
assert_true(str_contains($table, 'vectorConfigs'), 'Lookup-aware resource fields should resolve vector configuration options.');
