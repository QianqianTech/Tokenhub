<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagVectorContractService;

$dataset = [
    'id' => 42,
    'dataset_id' => 'ds_contract',
    'external_kb_id' => 'kb_contract',
    'name' => 'Contract Dataset',
];
$config = [
    'id' => 7,
    'collection_name' => 'contract_text',
    'dimension' => 768,
];
$chunk = [
    'chunk_id' => 'chk_contract',
    'title' => 'Contract Chunk',
    'content' => 'contract content',
];

$service = new RagVectorContractService();
$contract = $service->forChunk($dataset, $config, $chunk, 'text');

assert_same('contract_text', $contract['collection'], 'Configured collection should own the Qdrant collection name.');
assert_same('vec_text_chk_contract', $contract['vector_id'], 'Business vector id should be stable across stores.');
assert_same(768, $contract['dimension'], 'Contract should preserve the configured vector dimension.');
assert_same('ds_contract', $contract['payload']['dataset_id'], 'Qdrant payload should use external dataset id.');
assert_same('kb_contract', $contract['payload']['kb_id'], 'Qdrant payload should use external knowledge-base id.');
assert_same('chk_contract', $contract['payload']['chunk_id'], 'Qdrant payload should use external chunk id.');
assert_same('vec_text_chk_contract', $contract['payload']['vector_id'], 'Qdrant payload should retain the business vector id.');
assert_true(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $contract['point_id']) === 1, 'Qdrant point id should be a deterministic UUID v5.');
assert_same($contract['point_id'], $service->pointId($contract['vector_id']), 'Point id mapping should be deterministic.');

$fallback = $service->collection(['dataset_id' => 'ds fallback/中文'], [], 'multimodal');
assert_same('tokenhub_rag_ds_fallback__multimodal', $fallback, 'Fallback collection should sanitize the external dataset id.');
