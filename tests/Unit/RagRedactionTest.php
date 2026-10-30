<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * RAG-022: optional masking of personal data before indexing. The shared
 * fixture (tests/fixtures/rag_redaction_cases.json) is also run by the
 * Python worker tests, so both parsers mask the same way.
 */

use TokenHub\Services\RagRedactionService;
use TokenHub\Services\RagUploadService;

$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/rag_redaction_cases.json'), true);
$redactor = new RagRedactionService();
foreach ($fixture['cases'] as $case) {
    $result = $redactor->redact($case['in'], $fixture['types']);
    assert_same($case['out'], $result['text'], 'Masked: ' . $case['in']);
    assert_same($case['counts'], $result['counts'] === [] ? [] : $result['counts'], 'Counts: ' . $case['in']);
}
assert_same('电话13812345678', $redactor->redact('电话13812345678', ['email'])['text'], 'Only the selected types are masked.');

assert_same([], RagRedactionService::resolveTypes([], null), 'Off by default.');
assert_same(['phone', 'id_card'], RagRedactionService::resolveTypes([], '{"redaction": {"types": ["id_card", "phone"]}}'), 'Knowledge base profile selects the types (canonical order).');
assert_same([], RagRedactionService::resolveTypes(['redaction' => false], '{"redaction": ["phone"]}'), 'The upload can switch it off.');
assert_same(RagRedactionService::TYPES, RagRedactionService::resolveTypes(['redaction' => true], null), 'true selects every type.');
rag_expect_abort(static fn () => RagRedactionService::normalizeTypes(['phone', 'passport']), 'RAG_REDACTION_INVALID', 'Unknown types are rejected.');

// Upload integration: a document upload in a knowledge base that masks phones and ID cards.
$pdo = rag_sqlite_fixture();
$storage = sys_get_temp_dir() . '/rag-redact-' . bin2hex(random_bytes(4));
putenv('STORAGE_PATH=' . $storage);
$_ENV['STORAGE_PATH'] = $storage;
$columns = array_column($pdo->query('PRAGMA table_info(rag_knowledge_bases)')->fetchAll(), 'name');
if (!in_array('chunking_profile', $columns, true)) {
    $pdo->exec('ALTER TABLE rag_knowledge_bases ADD COLUMN chunking_profile TEXT');
}
$pdo->exec("INSERT INTO departments (id, name, path) VALUES (1, 'D', '/1/')");
$pdo->exec("INSERT INTO users (id, department_id, username) VALUES (10, 1, 'u')");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (100, 10, 1, 'k')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility, chunking_profile) VALUES (1, 'kb_1', 'KB', 1, 'private', '{\"redaction\": {\"types\": [\"phone\", \"id_card\"]}}')");
$pdo->exec("INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (1, 'user', 10, 'write')");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name) VALUES (1, 'ds_1', 1, 'DS')");
$uploads = new RagUploadService();
$apiKey = ['id' => 100, 'user_id' => 10, 'department_id' => 1];
$text = "# 客户档案\n\n联系人电话 13812345678，身份证 11010519491231002X，邮箱 a@b.com。\n";
$upload = $uploads->upload($apiKey, 'kb_1', ['filename' => 'customers.md', 'content_base64' => base64_encode($text), 'processing' => ['target_dataset_id' => 'ds_1']]);
$item = $pdo->query('SELECT content, metadata FROM rag_upload_preview_items')->fetch();
assert_true(str_contains((string) $item['content'], '138****5678') && str_contains((string) $item['content'], '110105********002X'), 'Preview content is masked before chunking.');
assert_true(str_contains((string) $item['content'], 'a@b.com'), 'Types the knowledge base did not select stay.');
$meta = json_decode((string) $item['metadata'], true);
assert_same(['id_card' => 1, 'phone' => 1], $meta['redaction']['counts'], 'Counts are recorded on the preview item.');
$uploadMeta = json_decode((string) $pdo->query("SELECT metadata FROM rag_uploads WHERE upload_id = '{$upload['upload_id']}'")->fetchColumn(), true);
assert_same(['phone', 'id_card'], $uploadMeta['redaction_types'], 'Resolved types are stored on the upload (read by the Python worker).');

echo "PASS\n";
