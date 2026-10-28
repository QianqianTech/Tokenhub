<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagUploadService;
use TokenHub\Support\App;

$db = App::db();
$reviewSetting = $db->first('SELECT setting_value, description FROM system_settings WHERE setting_key = "rag_upload_review"');
$kbPk = 0;
$textConfigPk = 0;
try {
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$kbId = 'kb_content_chunk_' . $suffix;
$datasetId = 'ds_content_chunk_' . $suffix;
$profile = [
    'target_chars' => 200,
    'max_chars' => 240,
    'min_tail_chars' => 20,
    'overlap_chars' => 24,
];

$db->execute('DELETE FROM system_settings WHERE setting_key = "rag_upload_review"');
$db->execute(
    'INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status, chunking_profile) VALUES (?, ?, 1, "active", ?)',
    [$kbId, 'Content Chunk KB ' . $suffix, json_encode($profile, JSON_UNESCAPED_UNICODE)]
);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_vector_configs (config_id, name, modality, model_name, dimension, vector_store, collection_name, enabled) VALUES (?, "Content Chunk Text Config", "text", "text-embedding", 3, "mysql_fallback", ?, 1)',
    ['cfg_content_chunk_' . $suffix, 'vec_content_chunk_' . $suffix]
);
$textConfigPk = (int) $db->pdo()->lastInsertId();
$db->execute(
    'INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, text_vector_config_id, status) VALUES (?, ?, ?, ?, ?, "active")',
    [$datasetId, $kbPk, 'Content Chunk DS ' . $suffix, 'content_chunk_' . $suffix, $textConfigPk]
);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "write")', [$kbPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "write", 1)', [$datasetPk, $apiKey['id']]);

$uploadId = 'upl_content_chunk_' . $suffix;
$db->execute(
    'INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, owner_user_id, department_id, api_key_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status, confirm_required, review_status)
     VALUES (?, ?, ?, ?, 1, ?, "api", "text", ?, ?, "ready", "not_started", "waiting_confirm", 1, "not_required")',
    [$uploadId, $kbPk, $datasetPk, $apiKey['user_id'], $apiKey['id'], str_repeat('c', 64), str_repeat('d', 64)]
);
$uploadPk = (int) $db->pdo()->lastInsertId();
$previewItemId = 'pvi_content_chunk_' . $suffix;
$content = str_repeat('冷却系统运行稳定，温度传感器持续记录设备状态。', 40);
assert_true(mb_strlen($content) > 700, 'The integration fixture must contain more than 700 Unicode characters.');
$db->execute(
    'INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors, metadata)
     VALUES (?, ?, ?, ?, "text", "Content-aware chunking", ?, "valid", "[]", ?)',
    [$previewItemId, $uploadPk, $kbPk, $datasetPk, $content, json_encode(['origin' => 'integration'], JSON_UNESCAPED_UNICODE)]
);

$service = new RagUploadService();
$service->confirm($apiKey, $uploadId);

$loadChunks = static function () use ($db, $uploadPk): array {
    return $db->select(
        'SELECT c.id, c.chunk_id, c.ordinal, c.content, c.metadata, c.enabled, c.lifecycle_status
         FROM rag_chunks c
         JOIN rag_documents d ON d.id = c.document_id
         WHERE d.upload_id = ?
         ORDER BY c.ordinal, c.id',
        [$uploadPk]
    );
};

$firstChunks = $loadChunks();
assert_true(count($firstChunks) >= 3, 'A long text preview should create at least three content-aware chunks.');
$firstIds = array_column($firstChunks, 'chunk_id');
assert_same(count($firstIds), count(array_unique($firstIds)), 'Chunk IDs must be unique within the upload.');

foreach ($firstChunks as $index => $chunk) {
    $segmentOrdinal = $index + 1;
    assert_same(
        'chk_' . substr(hash('sha256', $previewItemId . ':' . $segmentOrdinal), 0, 24),
        $chunk['chunk_id'],
        'Chunk IDs must be stable hashes of the preview item and segment ordinal.'
    );
    $metadata = json_decode((string) $chunk['metadata'], true) ?: [];
    assert_same('integration', $metadata['origin'] ?? null, 'Segment metadata should retain the preview item metadata.');
    assert_same($previewItemId, $metadata['source_preview_item_id'] ?? null, 'Segment metadata should identify the source preview item.');
    assert_same($segmentOrdinal, (int) ($metadata['segment_ordinal'] ?? 0), 'Segment metadata should persist its one-based ordinal.');
    assert_true(isset($metadata['start']) && is_int($metadata['start']), 'Segment metadata should persist a Unicode start offset.');
    assert_true(isset($metadata['end']) && is_int($metadata['end']), 'Segment metadata should persist a Unicode end offset.');
    assert_true($metadata['end'] > $metadata['start'], 'Segment metadata offsets should describe a non-empty source slice.');
    assert_same(
        $chunk['content'],
        mb_substr($content, $metadata['start'], $metadata['end'] - $metadata['start']),
        'Segment offsets should identify the exact Unicode-safe source slice.'
    );
    assert_true(in_array($metadata['boundary'] ?? null, ['heading', 'paragraph', 'sentence', 'hard'], true), 'Segment metadata should persist its boundary type.');
    assert_same('p1-v1', $metadata['chunking_profile']['version'] ?? null, 'Segment metadata should persist the effective chunking profile version.');
}

$firstJobCount = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk])['c'] ?? 0);
assert_same(count($firstChunks), $firstJobCount, 'Each text segment should have one durable embedding job.');

$service->confirm($apiKey, $uploadId);
$repeatedChunks = $loadChunks();
assert_same($firstIds, array_column($repeatedChunks, 'chunk_id'), 'Repeated confirmation must preserve chunk IDs and count.');
assert_same($firstJobCount, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk])['c'] ?? 0), 'Repeated confirmation must preserve embedding job count.');

$missingChunk = $firstChunks[count($firstChunks) - 1];
$db->execute('DELETE FROM rag_embedding_jobs WHERE upload_id = ? AND chunk_id = ?', [$uploadPk, $missingChunk['id']]);
$db->execute('DELETE FROM rag_chunks WHERE id = ?', [$missingChunk['id']]);

$service->confirm($apiKey, $uploadId);
$restoredChunks = $loadChunks();
assert_same($firstIds, array_column($restoredChunks, 'chunk_id'), 'Retrying confirmation should restore only the missing deterministic chunk identity.');
assert_same(count($firstChunks), count($restoredChunks), 'Retrying confirmation should restore the original chunk count.');
assert_same(count($restoredChunks), (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk])['c'] ?? 0), 'Retrying confirmation should restore the missing segment job without duplicating existing jobs.');

$firstChunkPk = (int) $restoredChunks[0]['id'];
$obsoleteChunkPks = array_map(static fn (array $chunk): int => (int) $chunk['id'], array_slice($restoredChunks, 1));
$db->pdo()->beginTransaction();
$reconciliationCommitted = false;
try {
$db->select('SELECT id FROM rag_embedding_jobs WHERE upload_id = ? FOR UPDATE', [$uploadPk]);
assert_true($db->pdo()->inTransaction(), 'Changed-chunk invalidation assertions must hold the embedding job rows in a transaction so workers cannot claim them first.');
$db->execute(
    'UPDATE rag_embedding_jobs SET status = "succeeded", attempts = 3, next_run_at = DATE_ADD(NOW(), INTERVAL 1 HOUR), dispatched_at = NOW(), locked_at = NOW(), lock_token = "stale-lock", error_code = "STALE_EMBEDDING", error_message = "stale vector", processed_items = 1, progress_percent = 100 WHERE upload_id = ? AND chunk_id = ?',
    [$uploadPk, $firstChunkPk]
);
$changedVectorId = 'vec_content_chunk_changed_' . $suffix;
$db->execute(
    'INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, vector_config_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, ?, "text", 3, "[0,0,0]", ?, "indexed", "indexed", NOW())',
    [$changedVectorId, 'vec_content_chunk_' . $suffix, $kbPk, $datasetPk, $firstChunkPk, $textConfigPk, hash('sha256', $restoredChunks[0]['content'])]
);
$updatedContent = str_repeat('更新后的冷却系统状态稳定。', 6);
$db->execute('UPDATE rag_upload_preview_items SET content = ? WHERE preview_item_id = ?', [$updatedContent, $previewItemId]);

$service->confirm($apiKey, $uploadId);
$reconciledChunks = $loadChunks();
$activeChunks = array_values(array_filter($reconciledChunks, static fn (array $chunk): bool => (int) $chunk['enabled'] === 1 && $chunk['lifecycle_status'] === 'active'));
assert_same(1, count($activeChunks), 'Shrinking preview content must supersede deterministic segments that are no longer generated.');
assert_same($firstIds[0], $activeChunks[0]['chunk_id'], 'The surviving first deterministic segment should retain its identity.');
assert_same($updatedContent, $activeChunks[0]['content'], 'The surviving segment should update its content.');
$survivingJob = $db->first('SELECT status, attempts, next_run_at, dispatched_at, locked_at, lock_token, error_code, error_message, processed_items, progress_percent FROM rag_embedding_jobs WHERE upload_id = ? AND chunk_id = ?', [$uploadPk, $firstChunkPk]);
assert_same('queued', $survivingJob['status'] ?? null, 'Changing an embedded segment must invalidate and requeue its succeeded embedding job.');
assert_same(0, (int) ($survivingJob['attempts'] ?? -1), 'A requeued changed segment must reset prior attempts.');
assert_same(null, $survivingJob['next_run_at'] ?? null, 'A requeued changed segment must clear delayed retry scheduling.');
assert_same(null, $survivingJob['dispatched_at'] ?? null, 'A requeued changed segment must clear prior dispatch state.');
assert_same(null, $survivingJob['locked_at'] ?? null, 'A requeued changed segment must clear its prior lease timestamp.');
assert_same(null, $survivingJob['lock_token'] ?? null, 'A requeued changed segment must clear its prior lease token.');
assert_same(null, $survivingJob['error_code'] ?? null, 'A requeued changed segment must clear its prior error code.');
assert_same(null, $survivingJob['error_message'] ?? null, 'A requeued changed segment must clear its prior error message.');
assert_same(0, (int) ($survivingJob['processed_items'] ?? -1), 'A requeued changed segment must reset processed progress.');
assert_same(0.0, (float) ($survivingJob['progress_percent'] ?? -1), 'A requeued changed segment must reset progress percent.');
$changedVector = $db->first('SELECT index_status, status, indexed_at FROM rag_vector_records WHERE vector_id = ?', [$changedVectorId]);
assert_same('stale', $changedVector['index_status'] ?? null, 'Changing embedded content must make the previously indexed vector unavailable for retrieval.');
assert_same('stale', $changedVector['status'] ?? null, 'Changing embedded content must persist stale vector status.');
assert_same(null, $changedVector['indexed_at'] ?? null, 'Changing embedded content must clear the old vector indexing timestamp.');
foreach ($obsoleteChunkPks as $obsoleteChunkPk) {
    $obsoleteJob = $db->first('SELECT status FROM rag_embedding_jobs WHERE upload_id = ? AND chunk_id = ?', [$uploadPk, $obsoleteChunkPk]);
    assert_same('cancelled', $obsoleteJob['status'] ?? null, 'Superseded segment jobs must be cancelled so they cannot be processed again.');
}
$interruptedObsoleteChunkPk = $obsoleteChunkPks[0];
$db->execute('UPDATE rag_embedding_jobs SET status = "queued" WHERE upload_id = ? AND chunk_id = ?', [$uploadPk, $interruptedObsoleteChunkPk]);
$service->confirm($apiKey, $uploadId);
assert_same(
    'cancelled',
    $db->first('SELECT status FROM rag_embedding_jobs WHERE upload_id = ? AND chunk_id = ?', [$uploadPk, $interruptedObsoleteChunkPk])['status'] ?? null,
    'Repeated reconciliation must finish cancelling an obsolete job after an interrupted prior attempt.'
);
$db->pdo()->commit();
$reconciliationCommitted = true;
} finally {
    if (!$reconciliationCommitted && $db->pdo()->inTransaction()) {
        $db->pdo()->rollBack();
    }
}

$whitespacePreviewItemId = 'pvi_content_chunk_whitespace_' . $suffix;
$whitespaceContent = "\n \t　" . str_repeat('前导空白后的内容必须保持原始字符偏移。', 18);
$db->execute(
    'INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors, metadata) VALUES (?, ?, ?, ?, "text", "Whitespace offsets", ?, "valid", "[]", "{}")',
    [$whitespacePreviewItemId, $uploadPk, $kbPk, $datasetPk, $whitespaceContent]
);
$service->confirm($apiKey, $uploadId);
$whitespaceChunks = $db->select(
    'SELECT c.id, c.content, c.metadata FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ? AND d.source_preview_item_id = ? AND c.lifecycle_status = "active" ORDER BY c.ordinal',
    [$uploadPk, $whitespacePreviewItemId]
);
assert_true($whitespaceChunks !== [], 'Leading-whitespace preview content should still create chunks.');
foreach ($whitespaceChunks as $chunk) {
    $metadata = json_decode((string) $chunk['metadata'], true) ?: [];
    assert_same(
        $chunk['content'],
        mb_substr($whitespaceContent, (int) $metadata['start'], (int) $metadata['end'] - (int) $metadata['start']),
        'Chunk metadata offsets must reproduce content from the original untrimmed Unicode preview text.'
    );
}
$db->execute('UPDATE rag_upload_preview_items SET validation_status = "error", validation_errors = ? WHERE preview_item_id = ?', [json_encode(['invalidated']), $whitespacePreviewItemId]);
$service->confirm($apiKey, $uploadId);
$errorItemActiveChunks = (int) ($db->first('SELECT COUNT(*) AS c FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ? AND d.source_preview_item_id = ? AND c.lifecycle_status = "active"', [$uploadPk, $whitespacePreviewItemId])['c'] ?? 0);
assert_same(0, $errorItemActiveChunks, 'Chunks for a preview item that becomes invalid must be retired.');
foreach ($whitespaceChunks as $chunk) {
    assert_same('cancelled', $db->first('SELECT status FROM rag_embedding_jobs WHERE chunk_id = ?', [(int) $chunk['id']])['status'] ?? null, 'Jobs for an invalidated preview item must be cancelled.');
}

$deletedPreviewItemId = 'pvi_content_chunk_deleted_' . $suffix;
$db->execute(
    'INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, validation_status, validation_errors, metadata) VALUES (?, ?, ?, ?, "text", "Deleted item", "This item will be deleted", "valid", "[]", "{}")',
    [$deletedPreviewItemId, $uploadPk, $kbPk, $datasetPk]
);
$service->confirm($apiKey, $uploadId);
$deletedItemDocument = $db->first('SELECT id FROM rag_documents WHERE upload_id = ? AND source_preview_item_id = ?', [$uploadPk, $deletedPreviewItemId]);
assert_true($deletedItemDocument !== null, 'A valid preview item should create its document before deletion.');
$db->execute('DELETE FROM rag_upload_preview_items WHERE preview_item_id = ?', [$deletedPreviewItemId]);
$service->confirm($apiKey, $uploadId);
assert_same(0, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_chunks WHERE document_id = ? AND lifecycle_status = "active"', [(int) $deletedItemDocument['id']])['c'] ?? 0), 'Chunks for a deleted preview item must be retired.');
assert_same(0, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE document_id = ? AND status != "cancelled"', [(int) $deletedItemDocument['id']])['c'] ?? 0), 'Jobs for a deleted preview item must be cancelled.');

$db->execute('DELETE FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk]);
$db->execute('DELETE c FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?', [$uploadPk]);
$db->execute('UPDATE rag_vector_datasets SET status = "disabled" WHERE id = ?', [$datasetPk]);
$service->confirm($apiKey, $uploadId);
assert_same(0, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ?', [$uploadPk])['c'] ?? 0), 'Confirmed upload recovery must not recreate chunks through a disabled dataset.');
assert_same(0, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ?', [$uploadPk])['c'] ?? 0), 'Confirmed upload recovery must not recreate jobs through a disabled dataset.');
} finally {
    cleanup_rag_test_scope([$kbPk], [$textConfigPk]);
    $db->execute('DELETE FROM system_settings WHERE setting_key = "rag_upload_review"');
    if ($reviewSetting !== null) {
        $db->execute(
            'INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("rag_upload_review", ?, ?)',
            [$reviewSetting['setting_value'], $reviewSetting['description']]
        );
    }
}
