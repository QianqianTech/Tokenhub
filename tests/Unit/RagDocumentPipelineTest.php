<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * RAG-020..RAG-025 end to end on SQLite + fake Qdrant: a DOCX upload is parsed
 * into Markdown, chunked by section (p2), embedded with its document/section
 * context, duplicates are refused, identical inputs reuse vectors, QA chunks
 * index similar questions, and a new version supersedes the old one once it
 * is indexed.
 */

use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagUploadService;
use TokenHub\Services\RagVectorConfigService;

$pdo = rag_sqlite_fixture();
$qdrant = new RagFakeQdrant();
RagVectorConfigService::useQdrantFactory(static fn (): QdrantVectorStore => new QdrantVectorStore($qdrant));
$storage = sys_get_temp_dir() . '/rag-pipeline-' . bin2hex(random_bytes(4));
putenv('STORAGE_PATH=' . $storage);
$_ENV['STORAGE_PATH'] = $storage;

$pdo->exec("INSERT INTO departments (id, name, path) VALUES (1, 'D', '/1/')");
$pdo->exec("INSERT INTO users (id, department_id, username) VALUES (10, 1, 'u')");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (100, 10, 1, 'k')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility) VALUES (1, 'kb_1', 'KB', 1, 'private')");
$pdo->exec("INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (1, 'user', 10, 'write')");
$pdo->exec("INSERT INTO rag_vector_configs (id, config_id, name, modality, provider_id, model_id, model_name, dimension, vector_store, collection_name, batch_size) VALUES (1, 'cfg', 'text', 'text', 1, 1, 'm', 4, 'qdrant', 'col_text', 16)");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name, text_vector_config_id) VALUES (1, 'ds_1', 1, 'DS', 1)");

$embedded = [];
$embedder = new RagEmbeddingProviderService(static function (string $modality, array $input) use (&$embedded): array {
    $embedded[] = (string) $input['text'];
    $hash = crc32((string) $input['text']);
    return [1.0, ($hash % 97) / 97, ($hash % 89) / 89, 0.5];
});
$published = [];
$jobs = new RagEmbeddingJobService($embedder, static function (array $message) use (&$published): void {
    $published[] = $message;
});
$uploads = new RagUploadService($jobs);
$apiKey = ['id' => 100, 'user_id' => 10, 'department_id' => 1];

$docx = static function (string $temperature): string {
    $dir = sys_get_temp_dir() . '/rag-docx-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir . '/manual.docx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $p = static fn (string $text, string $style = ''): string => '<w:p>' . ($style !== '' ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '') . '<w:r><w:t>' . htmlspecialchars($text) . '</w:t></w:r></w:p>';
    $body = $p('设备维护手册', 'Title') . $p('第一章 总则', 'Heading1') . $p('本手册适用于 X200 设备。') . $p('第二章 温度告警', 'Heading1') . $p("温度超过 {$temperature} 度时必须停机检查冷却单元。");
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document ' . $w . '><w:body>' . $body . '</w:body></w:document>');
    $zip->close();
    return (string) file_get_contents($path);
};

$upload = $uploads->upload($apiKey, 'kb_1', [
    'filename' => '设备维护手册.docx',
    'content_base64' => base64_encode($docx('80')),
    'processing' => ['target_dataset_id' => 'ds_1'],
]);
assert_same('document', $pdo->query("SELECT content_type FROM rag_uploads WHERE upload_id = '{$upload['upload_id']}'")->fetchColumn(), 'A .docx upload is imported as a document, not a template.');
$preview = $pdo->query("SELECT title, content, metadata FROM rag_upload_preview_items")->fetch();
assert_true(str_contains((string) $preview['content'], '## 第二章 温度告警'), 'Headings survive parsing.');
assert_same('设备维护手册', $preview['title'], 'Title comes from the Word title paragraph.');

rag_expect_abort(static fn () => $uploads->upload($apiKey, 'kb_1', ['filename' => 'copy.docx', 'content_base64' => base64_encode($docx('80')), 'processing' => ['target_dataset_id' => 'ds_1']]), 'RAG_UPLOAD_DUPLICATE', 'The same file is refused a second time.');

$uploads->confirm($apiKey, (string) $upload['upload_id'], ['dataset_id' => 'ds_1']);
$jobs->processUpload(1, $pdo->query("SELECT d.*, k.kb_id AS external_kb_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = 1")->fetch());

$chunks = $pdo->query('SELECT chunk_id, dataset_id, content, token_count, metadata FROM rag_chunks WHERE lifecycle_status = "active" ORDER BY id')->fetchAll();
assert_same(2, count($chunks), 'One chunk per section (sections never merge across headings).');
$meta = json_decode((string) $chunks[1]['metadata'], true);
assert_same(['设备维护手册', '第二章 温度告警'], $meta['heading_path'], 'Chunk carries its heading path.');
assert_same('p2-v1', $meta['chunking_profile']['version'], 'p2 chunker was used.');
assert_same(1, (int) $chunks[1]['dataset_id'], 'dataset_id is stored on the chunk.');
assert_true((int) $chunks[1]['token_count'] >= 15 && (int) $chunks[1]['token_count'] <= 40, 'Token estimate counts CJK characters.');
assert_true(in_array("设备维护手册 > 第二章 温度告警\n温度超过 80 度时必须停机检查冷却单元。", $embedded, true), 'Embedding input is prefixed with document and section context.');
assert_same('indexed', $pdo->query("SELECT vectorize_status FROM rag_uploads WHERE id = 1")->fetchColumn(), 'Upload is indexed.');
assert_same('file:设备维护手册.docx', $pdo->query('SELECT document_key FROM rag_documents WHERE upload_id = 1')->fetchColumn(), 'Document key defaults to the original file name.');
assert_same(2, count($qdrant->collections['col_text']['points']), 'Two points written.');

// New version of the same document: only the changed section is embedded again; the old version is superseded.
$before = count($embedded);
$v2 = $uploads->upload($apiKey, 'kb_1', ['filename' => '设备维护手册.docx', 'content_base64' => base64_encode($docx('75')), 'processing' => ['target_dataset_id' => 'ds_1']]);
$uploads->confirm($apiKey, (string) $v2['upload_id'], ['dataset_id' => 'ds_1']);
$jobs->processUpload(2, $pdo->query("SELECT d.*, k.kb_id AS external_kb_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = 1")->fetch());
assert_same(1, count($embedded) - $before, 'Unchanged section reuses its vector; only the edited one is embedded.');
assert_true($pdo->query('SELECT deleted_at FROM rag_documents WHERE upload_id = 1')->fetchColumn() !== null, 'Old version is superseded after the new one is indexed.');
assert_same(2, (int) $pdo->query('SELECT version FROM rag_documents WHERE upload_id = 2')->fetchColumn(), 'Version number increments.');
assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = 1 AND c.lifecycle_status = 'active'")->fetchColumn(), 'Old chunks left retrieval.');
assert_same(2, count($qdrant->collections['col_text']['points']), 'Old points were deleted, new ones written.');

// QA chunk: question + every similar question are indexed.
$pdo->exec("INSERT INTO rag_documents (id, document_id, kb_id, dataset_id, upload_id, title, document_key) VALUES (50, 'doc_qa', 1, 1, 2, 'FAQ', 'upload:x')");
$pdo->exec("INSERT INTO rag_chunks (id, chunk_id, kb_id, dataset_id, document_id, chunk_type, title, content, question, answer, metadata) VALUES (500, 'chk_qa', 1, 1, 50, 'qa', '如何重置密码', 'x', '如何重置密码', '在设置页点击重置。', '{\"similar_questions\": \"忘记密码怎么办|密码找回\"}')");
$chunk = $pdo->query('SELECT * FROM rag_chunks WHERE id = 500')->fetch();
$config = $pdo->query('SELECT * FROM rag_vector_configs WHERE id = 1')->fetch();
$dataset = $pdo->query("SELECT d.*, k.kb_id AS external_kb_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = 1")->fetch();
$jobs->indexChunk(new QdrantVectorStore($qdrant), 'col_text', $config, $dataset, $chunk, 'text');
assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM rag_vector_records WHERE chunk_id = 500")->fetchColumn(), 'Question and two similar questions each get a vector.');
assert_true(in_array('问题：忘记密码怎么办', $embedded, true), 'Similar questions are embedded on their own.');

// Python worker path (python_local): the upload is marked indexed outside PHP; the scheduled
// rag.publish-versions job supersedes the earlier version.
$pdo->exec("INSERT INTO rag_uploads (id, upload_id, kb_id, dataset_id, vectorize_status, status) VALUES (3, 'upl_py', 1, 1, 'indexed', 'indexed')");
$pdo->exec("INSERT INTO rag_documents (id, document_id, kb_id, dataset_id, upload_id, title, document_key) VALUES (60, 'doc_py', 1, 1, 3, 'v3', 'file:设备维护手册.docx')");
$pdo->exec("INSERT INTO rag_chunks (id, chunk_id, kb_id, dataset_id, document_id, content) VALUES (600, 'chk_py', 1, 1, 60, '温度超过 70 度时必须停机。')");
$versions = new TokenHub\Services\RagDocumentVersionService();
assert_same(0, $versions->publish(2), 'An older upload never supersedes a newer version.');
$pending = $versions->publishPending();
assert_same(['uploads' => 1, 'superseded' => 1], $pending, 'The worker-indexed upload replaces the previous version.');
assert_same(60, (int) $pdo->query("SELECT superseded_by_document_id FROM rag_documents WHERE upload_id = 2 AND document_key LIKE 'file:%'")->fetchColumn(), 'Version 2 is superseded by version 3.');
assert_same(3, (int) $pdo->query('SELECT version FROM rag_documents WHERE id = 60')->fetchColumn(), 'Version 3.');
assert_same(['uploads' => 0, 'superseded' => 0], $versions->publishPending(), 'Idempotent.');

echo "PASS\n";
