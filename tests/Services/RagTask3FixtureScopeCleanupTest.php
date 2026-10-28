<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$scopeA = ['kb' => 0, 'dataset' => 0, 'config' => 0];
$scopeB = ['kb' => 0, 'dataset' => 0, 'config' => 0];

$fallbackCleanup = static function (array $scope) use ($db): void {
    if ($scope['dataset'] > 0) {
        $db->execute('DELETE FROM rag_dataset_bindings WHERE dataset_id = ?', [$scope['dataset']]);
        $db->execute('DELETE FROM rag_vector_datasets WHERE id = ?', [$scope['dataset']]);
    }
    if ($scope['kb'] > 0) {
        $db->execute('DELETE FROM rag_knowledge_base_bindings WHERE kb_id = ?', [$scope['kb']]);
        $db->execute('DELETE FROM rag_knowledge_bases WHERE id = ?', [$scope['kb']]);
    }
    if ($scope['config'] > 0) {
        $db->execute('DELETE FROM rag_vector_configs WHERE id = ?', [$scope['config']]);
    }
};

try {
    foreach (['a' => &$scopeA, 'b' => &$scopeB] as $label => &$scope) {
        $db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, 1, "active")', ['kb_content_chunk_scope_' . $label . '_' . $suffix, 'Scope ' . strtoupper($label) . ' ' . $suffix]);
        $scope['kb'] = (int) $db->pdo()->lastInsertId();
        $db->execute('INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, ?, "text", "text-embedding", 3, "mysql_fallback", ?, 1)', ['cfg_content_chunk_scope_' . $label . '_' . $suffix, 'Scope config ' . strtoupper($label), 'vec_scope_' . $label . '_' . $suffix]);
        $scope['config'] = (int) $db->pdo()->lastInsertId();
        $db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")', ['ds_content_chunk_scope_' . $label . '_' . $suffix, $scope['kb'], 'Scope dataset ' . strtoupper($label), 'scope_' . $label . '_' . $suffix, $scope['config']]);
        $scope['dataset'] = (int) $db->pdo()->lastInsertId();
    }
    unset($scope);

    assert_true(function_exists('cleanup_rag_test_scope'), 'Task3 fixtures require an explicit scope cleanup helper instead of prefix-wide cleanup.');
    $bootstrapSource = file_get_contents(__DIR__ . '/../bootstrap.php');
    assert_true(is_string($bootstrapSource), 'Bootstrap source must be readable for the global cleanup contract.');
    assert_true(!str_contains($bootstrapSource, '|content_chunk)'), 'Global synthetic cleanup must not own the content_chunk prefix.');

    cleanup_rag_test_scope([$scopeA['kb']], [$scopeA['config']]);
    assert_same(0, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_knowledge_bases WHERE id = ?', [$scopeA['kb']])['c'] ?? 0), 'Cleaning scope A must remove only scope A knowledge-base rows.');
    assert_same(1, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_knowledge_bases WHERE id = ?', [$scopeB['kb']])['c'] ?? 0), 'Cleaning scope A must not remove concurrent scope B knowledge-base rows.');
    assert_same(0, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_vector_configs WHERE id = ?', [$scopeA['config']])['c'] ?? 0), 'Cleaning scope A must remove its vector config.');
    assert_same(1, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_vector_configs WHERE id = ?', [$scopeB['config']])['c'] ?? 0), 'Cleaning scope A must not remove scope B vector config.');

    cleanup_rag_test_scope([$scopeB['kb']], [$scopeB['config']]);
} finally {
    if (function_exists('cleanup_rag_test_scope')) {
        cleanup_rag_test_scope([$scopeA['kb']], [$scopeA['config']]);
        cleanup_rag_test_scope([$scopeB['kb']], [$scopeB['config']]);
    } else {
        $fallbackCleanup($scopeA);
        $fallbackCleanup($scopeB);
    }
}
