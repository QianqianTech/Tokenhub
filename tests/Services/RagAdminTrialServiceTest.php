<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$source = file_get_contents(__DIR__ . '/../../app/Services/RagAdminTrialService.php') ?: '';

assert_true($source !== '', 'RAG admin trial retrieval service should exist.');
assert_true(str_contains($source, 'function retrieve'), 'RAG admin trial service should expose retrieve.');
assert_true(str_contains($source, 'searchSourcesForDatasets'), 'RAG admin trial service should use the real retrieval path.');
assert_true(str_contains($source, 'PermissionService'), 'RAG admin trial service should enforce admin scope.');
assert_true(str_contains($source, 'RAG_QUERY_REQUIRED'), 'RAG admin trial service should reject an empty query.');
