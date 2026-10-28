<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagVectorDeletionService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbId = 'kb_del_' . $suffix;
$datasetId = 'ds_del_' . $suffix;

$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")', [$kbId, 'Delete KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Delete DS ' . $suffix, 'del_' . $suffix]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, source_type, content_type, sha256, file_hash, preview_status, vectorize_status, status) VALUES (?, ?, ?, "api", "text", ?, ?, "confirmed", "indexed", "indexed")', ['upl_del_' . $suffix, $kbPk, $datasetPk, str_repeat('a', 64), str_repeat('b', 64)]);
$uploadPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, parse_status, index_status) VALUES (?, ?, ?, "Delete Doc", "plain_text", "parsed", "indexed")', ['doc_del_' . $suffix, $kbPk, $uploadPk]);
$documentPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, "Delete Chunk", "delete content", "{}", ?, 1, "active")', ['chk_del_' . $suffix, $kbPk, $documentPk, str_repeat('c', 64)]);
$chunkPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at) VALUES (?, ?, ?, ?, ?, "text", 3, ?, ?, "indexed", "indexed", NOW())', ['vec_del_' . $suffix, 'missing_qdrant_' . $suffix, $kbPk, $datasetPk, $chunkPk, json_encode([1, 0, 0]), str_repeat('d', 64)]);

$deleted = (new RagVectorDeletionService())->deleteChunks([$chunkPk]);
assert_same(1, $deleted, 'deleteChunks should report one deleted chunk.');

$vector = $db->first('SELECT index_status, status, deleted_at FROM rag_vector_records WHERE chunk_id = ?', [$chunkPk]);
assert_same('deleted', $vector['index_status'], 'Vector record index_status should be deleted.');
assert_same('deleted', $vector['status'], 'Vector record status should be deleted.');
assert_true($vector['deleted_at'] !== null, 'Vector record deleted_at should be set.');

$chunk = $db->first('SELECT enabled, lifecycle_status FROM rag_chunks WHERE id = ?', [$chunkPk]);
assert_same(0, (int) $chunk['enabled'], 'Chunk should be disabled after vector deletion.');
assert_same('deleted', $chunk['lifecycle_status'], 'Chunk lifecycle_status should be deleted.');
