<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagVectorizationMetricsService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', ['kb_met_' . $suffix, 'Metrics KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")', ['ds_met_' . $suffix, $kbPk, 'Metrics DS ' . $suffix, 'met_' . $suffix]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status, image_items, total_items) VALUES (?, ?, ?, "api", "image", ?, ?, "confirmed", "partial_failed", "partial_failed", 10, 10)', ['upl_met_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();

foreach ([
    ['ocr', 'succeeded', null],
    ['caption', 'succeeded', null],
    ['text_embedding', 'succeeded', null],
    ['image_embedding', 'succeeded', null],
    ['multimodal_embedding', 'failed', 'RAG_MULTIMODAL_EMBEDDING_FAILED'],
] as $index => [$jobType, $status, $errorCode]) {
    $db->execute(
        'INSERT INTO rag_embedding_jobs (job_id, kb_id, dataset_id, upload_id, job_type, status, attempts, error_code, total_items, processed_items, progress_percent) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, ?, ?)',
        ['job_met_' . $index . '_' . $suffix, $kbPk, $datasetPk, $uploadPk, $jobType, $status, $errorCode, $status === 'succeeded' ? 1 : 0, $status === 'succeeded' ? 100 : 0]
    );
}

$metrics = (new RagVectorizationMetricsService())->summary(24, 95.0);
assert_true($metrics['total_jobs'] >= 5, 'Metrics should count recent RAG jobs.');
assert_true($metrics['overall_success_rate'] < 95.0, 'Metrics should report success rate below threshold.');
assert_same(true, $metrics['below_threshold'], 'Metrics should flag success rate below threshold.');
assert_true(isset($metrics['by_job_type']['multimodal_embedding']), 'Metrics should break down multimodal embedding jobs.');
assert_true(isset($metrics['top_failures']['RAG_MULTIMODAL_EMBEDDING_FAILED']), 'Metrics should aggregate top failure reasons.');

