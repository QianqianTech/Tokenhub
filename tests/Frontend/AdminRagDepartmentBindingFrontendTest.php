<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$table = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';

$workbenchStart = strpos($table, 'async function renderRagUploadWorkbenchPage');
$workbenchEnd = strpos($table, 'function readFileAsBase64', $workbenchStart);
assert_true($workbenchStart !== false && $workbenchEnd !== false, 'RAG upload workbench source should be inspectable.');
$workbenchSource = substr($table, $workbenchStart, $workbenchEnd - $workbenchStart);

assert_true(str_contains($workbenchSource, 'departmentOptions'), 'RAG upload workbench should load department selector options.');
assert_true(str_contains($workbenchSource, 'field({ name: "department_id"'), 'RAG upload workbench should submit department_id.');
assert_true(!str_contains($workbenchSource, 'lookupRows("api-keys")'), 'RAG upload workbench should not load API keys.');
assert_true(!str_contains($workbenchSource, 'name: "api_key_id"'), 'RAG upload workbench should not render or submit api_key_id.');
assert_true(!str_contains($workbenchSource, '归属 API Key'), 'RAG upload workbench should not label uploads as belonging to an API key.');

$datasetBindingStart = strpos($resources, '"rag-dataset-bindings": {');
$datasetBindingEnd = strpos($resources, '"rag-vector-configs": {', $datasetBindingStart);
assert_true($datasetBindingStart !== false && $datasetBindingEnd !== false, 'RAG dataset binding resource config should be inspectable.');
$datasetBindingResource = substr($resources, $datasetBindingStart, $datasetBindingEnd - $datasetBindingStart);

assert_true(str_contains($datasetBindingResource, 'label: "数据集授权"'), 'RAG dataset bindings should be labeled as dataset authorization, not API Key binding.');
assert_true(str_contains($datasetBindingResource, 'subject_type'), 'RAG dataset binding resource should use subject_type.');
assert_true(str_contains($datasetBindingResource, 'subject_id'), 'RAG dataset binding resource should use subject_id.');
assert_true(!str_contains($datasetBindingResource, 'target_type'), 'RAG dataset binding resource should not use target_type.');
assert_true(!str_contains($datasetBindingResource, 'target_id'), 'RAG dataset binding resource should not use target_id.');

$knowledgeBindingStart = strpos($resources, '"rag-knowledge-base-bindings": {');
$knowledgeBindingEnd = strpos($resources, '"rag-vector-datasets": {', $knowledgeBindingStart);
assert_true($knowledgeBindingStart !== false && $knowledgeBindingEnd !== false, 'RAG knowledge binding resource config should be inspectable.');
$knowledgeBindingResource = substr($resources, $knowledgeBindingStart, $knowledgeBindingEnd - $knowledgeBindingStart);

assert_true(str_contains($knowledgeBindingResource, 'subject_type'), 'RAG knowledge base binding resource should use subject_type.');
assert_true(str_contains($knowledgeBindingResource, 'subject_id'), 'RAG knowledge base binding resource should use subject_id.');
