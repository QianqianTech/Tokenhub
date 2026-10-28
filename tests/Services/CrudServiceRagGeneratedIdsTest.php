<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\CrudService;
use TokenHub\Support\App;

App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin']);

$db = App::db();
$suffix = bin2hex(random_bytes(5));
$kbId = null;
$datasetId = null;

try {
    $service = new CrudService();
    $kb = $service->create('rag_knowledge_bases', [
        'name' => 'Generated KB ' . $suffix,
    ]);
    $kbId = (int) $kb['id'];

    assert_true((bool) preg_match('/^kb_[a-f0-9]{24}$/', (string) ($kb['kb_id'] ?? '')), 'Knowledge base create should generate kb_id when omitted.');
    assert_same('private', $kb['visibility'] ?? null, 'Knowledge base create should use the default visibility when omitted.');
    assert_same('active', $kb['status'] ?? null, 'Knowledge base create should use the default status when omitted.');

    $dataset = $service->create('rag_vector_datasets', [
        'kb_id' => $kbId,
        'name' => 'Generated Dataset ' . $suffix,
        'dataset_type' => 'mixed',
        'status' => 'active',
    ]);
    $datasetId = (int) $dataset['id'];

    assert_true((bool) preg_match('/^ds_[a-f0-9]{24}$/', (string) ($dataset['dataset_id'] ?? '')), 'Dataset create should generate dataset_id when omitted.');
    assert_same('tokenhub_rag_' . $dataset['dataset_id'], $dataset['collection_prefix'] ?? null, 'Dataset create should derive collection_prefix from the generated dataset_id.');
} finally {
    if ($datasetId !== null) {
        $db->execute('DELETE FROM rag_vector_datasets WHERE id = ?', [$datasetId]);
    }
    if ($kbId !== null) {
        $db->execute('DELETE FROM rag_knowledge_bases WHERE id = ?', [$kbId]);
    }
}
