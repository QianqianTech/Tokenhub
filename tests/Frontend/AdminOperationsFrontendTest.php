<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableSource = admin_js_bundle() ?: '';
$cssSource = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$rendererSourceOnly = admin_js_function($tableSource, 'renderOperationsStatusPage');
$rendererStart = $rendererSourceOnly === '' ? false : 0;
$rendererEnd = $rendererSourceOnly === '' ? false : strlen($rendererSourceOnly);

assert_true($rendererStart !== false && $rendererEnd !== false, 'Operations status renderer should be inspectable.');
$operationsRenderer = $rendererSourceOnly;

assert_true(!str_contains($operationsRenderer, 'renderDataDetails(result.data || {})'), 'Operations status must not render raw nested response data.');
foreach (['operations-status-overview', 'operations-health-grid', 'operations-queue-grid', 'operations-worker-log-list'] as $hook) {
    assert_true(str_contains($operationsRenderer, $hook), 'Operations status should expose ' . $hook . '.');
}
foreach (['Qdrant', '队列 Worker', 'RAG Worker'] as $label) {
    assert_true(str_contains($operationsRenderer, $label), 'Operations status should label ' . $label . '.');
}
foreach (['.operations-health-grid', '.operations-queue-grid', '.operations-worker-log-list'] as $selector) {
    assert_true(str_contains($cssSource, $selector), 'Operations status should style ' . $selector . '.');
}
