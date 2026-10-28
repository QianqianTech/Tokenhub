<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Services\RagUploadService;
use TokenHub\Support\App;

$validZip = 'UEsDBBQAAAAIAFt76VxNn8W0sgAAAPwAAAAMAAAAbWFuaWZlc3QuY3N2TY0xjgIxDEV7TpEDeBlWdLSwW9LsAZBJrIxRiEe2AwJx+M0MDYUly9//Pb5iJpiaTmIEiSwqT85SwdnLfHHkQun0Gcn5QtENHLNBRKcs+gCTppFOTRkK1txmMFU89/aKZ40NuJ5qhl9sxYNSlFx5Af5FJarBRrlb+Nls4UA3jrSsfYK98zv72HspcE3cvaLwTl5REs2fr7QUwVVaF3egOHflFWvDshsG6sTn+LU/wvfqH1BLAwQUAAAACABbe+lcWalQkD8AAABEAAAADAAAAGltYWdlcy9hLnBuZ+sM8HPn5ZLiYmBg4PX0cAkC0owgzMECJLfK8DABKW5PF8eQilvJf/7LMzAzMzG837/8NlCYwdPVz2WdU0ITAFBLAQIUABQAAAAIAFt76VxNn8W0sgAAAPwAAAAMAAAAAAAAAAAAAACAAQAAAABtYW5pZmVzdC5jc3ZQSwECFAAUAAAACABbe+lcWalQkD8AAABEAAAADAAAAAAAAAAAAAAAgAHcAAAAaW1hZ2VzL2EucG5nUEsFBgAAAAACAAIAdAAAAEUBAAAAAA==';

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$apiKey = ['id' => random_int(100000, 999999), 'user_id' => random_int(100000, 999999), 'department_id' => 1];
$kbId = 'kb_pkg_' . $suffix;
$datasetId = 'ds_pkg_' . $suffix;

$db->execute('DELETE FROM system_settings WHERE setting_key IN ("rag_upload_review", "rag_package_upload")');
$db->execute('INSERT INTO rag_knowledge_bases (kb_id, name, department_id, status) VALUES (?, ?, 1, "active")', [$kbId, 'Package KB ' . $suffix]);
$kbPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")', [$datasetId, $kbPk, 'Package DS ' . $suffix, 'pkg_' . $suffix]);
$datasetPk = (int) $db->pdo()->lastInsertId();
$db->execute('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, "api_key", ?, "write")', [$kbPk, $apiKey['id']]);
$db->execute('INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default) VALUES (?, "api_key", ?, "write", 1)', [$datasetPk, $apiKey['id']]);

$service = new RagUploadService();
$upload = $service->upload($apiKey, $kbId, [
    'filename' => 'image-package.zip',
    'content_type' => 'zip',
    'content_base64' => $validZip,
    'processing' => [
        'package_mode' => 'manifest_zip',
        'template' => 'image_package',
        'target_dataset_id' => $datasetId,
        'auto_vectorize' => false,
        'confirm_required' => true,
    ],
]);

assert_same('waiting_confirm', $upload['status'], 'Image package upload should wait for confirmation after preview.');
assert_same(1, $upload['summary']['image_items'], 'Image package upload should count image preview rows.');
assert_same(0, $upload['summary']['text_items'], 'Image package upload should not count image rows as text.');

$preview = $service->preview($apiKey, (string) $upload['upload_id'], ['type' => 'image', 'page' => 1, 'page_size' => 20]);
assert_same(1, $preview['total'], 'Image preview should return the package row.');
$item = $preview['items'][0];
assert_same('valid', $item['validation_status'], 'Package image preview row should be valid.');
assert_same('Fault recognition', $item['purpose'], 'Preview should expose human purpose annotation.');
assert_same('screen|code', $item['metadata']['objects'], 'Preview should expose object annotations.');
assert_same('human_provided', $item['metadata']['annotation_status'], 'Preview should mark human annotation status.');
assert_true(in_array('caption_model_not_configured', $item['metadata']['quality_warnings'], true), 'Preview should expose AI caption warning.');
assert_true(is_array($item['image_asset']), 'Preview should include created image asset details.');
assert_true(in_array('ocr_not_configured', $item['image_asset']['warnings'], true), 'Image asset should record OCR warning.');

