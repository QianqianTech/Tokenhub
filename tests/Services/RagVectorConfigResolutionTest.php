<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagVectorConfigService;
use TokenHub\Support\App;

register_rag_test_cleanup();

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbPk = null;
$configIds = [];

try {
    $db->execute(
        'INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)',
        ['cfg_global_' . $suffix, 'Global text ' . $suffix, 'global_text_' . $suffix, json_encode(['allow_local_fallback' => true])],
    );
    $globalConfigPk = (int) $db->pdo()->lastInsertId();
    $configIds[] = $globalConfigPk;

    $db->execute(
        'INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)',
        ['cfg_kb_' . $suffix, 'KB text ' . $suffix, 'kb_text_' . $suffix, json_encode(['allow_local_fallback' => true])],
    );
    $kbConfigPk = (int) $db->pdo()->lastInsertId();
    $configIds[] = $kbConfigPk;

    $db->execute(
        'INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "text", "local-dev", 3, "mysql_fallback", ?, 1, ?)',
        ['cfg_dataset_' . $suffix, 'Dataset text ' . $suffix, 'dataset_text_' . $suffix, json_encode(['allow_local_fallback' => true])],
    );
    $datasetConfigPk = (int) $db->pdo()->lastInsertId();
    $configIds[] = $datasetConfigPk;

    $db->execute(
        'INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled, config_json) VALUES (?, ?, "image", "local-dev", 3, "mysql_fallback", ?, 1, ?)',
        ['cfg_image_' . $suffix, 'Image config ' . $suffix, 'image_' . $suffix, json_encode(['allow_local_fallback' => true])],
    );
    $imageConfigPk = (int) $db->pdo()->lastInsertId();
    $configIds[] = $imageConfigPk;

    $db->execute(
        'INSERT INTO rag_knowledge_bases (kb_id, name, status, default_text_vector_config_id) VALUES (?, ?, "active", ?)',
        ['kb_config_resolution_' . $suffix, 'Config resolution KB ' . $suffix, $kbConfigPk],
    );
    $kbPk = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")',
        ['ds_config_resolution_' . $suffix, $kbPk, 'Config resolution dataset ' . $suffix, 'resolution_' . $suffix, $datasetConfigPk],
    );
    $datasetPk = (int) $db->pdo()->lastInsertId();

    $service = new RagVectorConfigService();
    $dataset = $db->first('SELECT * FROM rag_vector_datasets WHERE id = ?', [$datasetPk]);
    assert_same($datasetConfigPk, (int) ($service->textConfig($dataset)['id'] ?? 0), 'Dataset text config should override the knowledge-base default.');

    $db->execute('UPDATE rag_vector_datasets SET text_vector_config_id = ? WHERE id = ?', [$imageConfigPk, $datasetPk]);
    $dataset = $db->first('SELECT * FROM rag_vector_datasets WHERE id = ?', [$datasetPk]);
    assert_same($kbConfigPk, (int) ($service->textConfig($dataset)['id'] ?? 0), 'A mismatched dataset modality should fall through to the knowledge-base text default.');

    $db->execute('UPDATE rag_knowledge_bases SET default_text_vector_config_id = NULL WHERE id = ?', [$kbPk]);
    $dataset = $db->first('SELECT * FROM rag_vector_datasets WHERE id = ?', [$datasetPk]);
    $expectedGlobal = (int) (($db->first('SELECT id FROM rag_vector_configs WHERE modality = "text" AND enabled = 1 ORDER BY id LIMIT 1')['id'] ?? 0));
    assert_same($expectedGlobal, (int) ($service->textConfig($dataset)['id'] ?? 0), 'Absent dataset and knowledge-base text configs should fall through to the global text config.');
} finally {
    cleanup_rag_test_scope($kbPk === null ? [] : [$kbPk], $configIds);
}
