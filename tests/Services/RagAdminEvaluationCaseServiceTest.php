<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$source = file_get_contents(__DIR__ . '/../../app/Services/RagAdminEvaluationCaseService.php') ?: '';

assert_true($source !== '', 'RAG admin evaluation case service should exist.');
foreach (['function create', 'function updateStatus', 'function import', 'function createCorrectionFromFailure', 'PermissionService', 'RAG_EVAL_EXPECTED_CHUNKS_REQUIRED'] as $token) {
    assert_true(str_contains($source, $token), 'RAG admin evaluation case service should expose ' . $token . '.');
}

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
foreach ([
    "post('/admin-api/rag/eval-cases'",
    "patch('/admin-api/rag/eval-cases/{case_id}/status'",
    "post('/admin-api/rag/eval-cases/import'",
    "post('/admin-api/rag/eval-runs/{run_id}/failures/{case_id}/correction'",
] as $route) {
    assert_true(str_contains($kernel, $route), 'Kernel should register RAG evaluation workflow route ' . $route . '.');
}
