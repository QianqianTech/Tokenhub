<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagPackageImportService;

$validZip = 'UEsDBBQAAAAIAFt76VxNn8W0sgAAAPwAAAAMAAAAbWFuaWZlc3QuY3N2TY0xjgIxDEV7TpEDeBlWdLSwW9LsAZBJrIxRiEe2AwJx+M0MDYUly9//Pb5iJpiaTmIEiSwqT85SwdnLfHHkQun0Gcn5QtENHLNBRKcs+gCTppFOTRkK1txmMFU89/aKZ40NuJ5qhl9sxYNSlFx5Af5FJarBRrlb+Nls4UA3jrSsfYK98zv72HspcE3cvaLwTl5REs2fr7QUwVVaF3egOHflFWvDshsG6sTn+LU/wvfqH1BLAwQUAAAACABbe+lcWalQkD8AAABEAAAADAAAAGltYWdlcy9hLnBuZ+sM8HPn5ZLiYmBg4PX0cAkC0owgzMECJLfK8DABKW5PF8eQilvJf/7LMzAzMzG837/8NlCYwdPVz2WdU0ITAFBLAQIUABQAAAAIAFt76VxNn8W0sgAAAPwAAAAMAAAAAAAAAAAAAACAAQAAAABtYW5pZmVzdC5jc3ZQSwECFAAUAAAACABbe+lcWalQkD8AAABEAAAADAAAAAAAAAAAAAAAgAHcAAAAaW1hZ2VzL2EucG5nUEsFBgAAAAACAAIAdAAAAEUBAAAAAA==';
$missingImageZip = 'UEsDBBQAAAAIAGZ76VzxU/XwNAAAADYAAAAMAAAAbWFuaWZlc3QuY3N2y8xNTE/VKSgtKsgvTtVJSS1OLsosKMnMz+PKBMkU6+dmFhdn5qXrFeSl64QClbgAlXABAFBLAQIUABQAAAAIAGZ76VzxU/XwNAAAADYAAAAMAAAAAAAAAAAAAACAAQAAAABtYW5pZmVzdC5jc3ZQSwUGAAAAAAEAAQA6AAAAXgAAAAAA';
$conflictZip = 'UEsDBBQAAAAIAGZ76VyklW1vLQAAADAAAAAMAAAAbWFuaWZlc3QuY3N2y8xNTE/VKSgtKsgvTtVJSS1OLsosKMnMz+PKBMkU6yfqFeSl64QCJV2AklwAUEsDBBQAAAAIAGZ76VwzWEU5CgAAAAgAAAANAAAAbWFuaWZlc3QueGxzeMvLL1GoyCmuAABQSwMEFAAAAAgAZnvpXFmpUJA/AAAARAAAAAwAAABpbWFnZXMvYS5wbmfrDPBz5+WS4mJgYOD19HAJAtKMIMzBAiS3yvAwASluTxfHkIpbyX/+yzMwMzMxvN+//DZQmMHT1c9lnVNCEwBQSwECFAAUAAAACABme+lcpJVtby0AAAAwAAAADAAAAAAAAAAAAAAAgAEAAAAAbWFuaWZlc3QuY3N2UEsBAhQAFAAAAAgAZnvpXDNYRTkKAAAACAAAAA0AAAAAAAAAAAAAAIABVwAAAG1hbmlmZXN0Lnhsc3hQSwECFAAUAAAACABme+lcWalQkD8AAABEAAAADAAAAAAAAAAAAAAAgAGMAAAAaW1hZ2VzL2EucG5nUEsFBgAAAAADAAMArwAAAPUAAAAAAA==';
$traversalZip = 'UEsDBBQAAAAIAGZ76VwMaSLoKwAAACwAAAAMAAAAbWFuaWZlc3QuY3N2y8xNTE/VKSgtKsgvTtVJSS1OLsosKMnMz+PS09NP1CvIS9cJBUq4ACW4AFBLAwQUAAAACABme+lcWalQkD8AAABEAAAADAAAAGltYWdlcy9hLnBuZ+sM8HPn5ZLiYmBg4PX0cAkC0owgzMECJLfK8DABKW5PF8eQilvJf/7LMzAzMzG837/8NlCYwdPVz2WdU0ITAFBLAQIUABQAAAAIAGZ76VwMaSLoKwAAACwAAAAMAAAAAAAAAAAAAACAAQAAAABtYW5pZmVzdC5jc3ZQSwECFAAUAAAACABme+lcWalQkD8AAABEAAAADAAAAAAAAAAAAAAAgAFVAAAAaW1hZ2VzL2EucG5nUEsFBgAAAAACAAIAdAAAAL4AAAAAAA==';

$writeZip = static function (string $base64, string $name): string {
    $path = storage_path('rag' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $name);
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, base64_decode($base64, true));

    return $path;
};

$service = new RagPackageImportService();

$valid = $service->parse($writeZip($validZip, 'pkg_valid.zip'), 'pkg_valid');
assert_same(null, $valid['error_code'], 'Valid image package should not produce a package error.');
assert_same('manifest.csv', $valid['manifest_name'], 'Valid image package should select manifest.csv.');
assert_same(1, count($valid['items']), 'Valid image package should parse one manifest row.');
assert_same('image', $valid['items'][0]['item_type'], 'Manifest row should become an image preview item.');
assert_same('valid', $valid['items'][0]['validation_status'], 'Existing package image should validate.');
assert_same('Fault recognition', $valid['items'][0]['purpose'], 'Human purpose annotation should be preserved.');
assert_same('screen|code', $valid['items'][0]['metadata']['objects'], 'Object annotations should be preserved in metadata.');
assert_true(is_file((string) $valid['items'][0]['metadata']['package_image_path']), 'Package image should be extracted to a safe local path.');

$missing = $service->parse($writeZip($missingImageZip, 'pkg_missing.zip'), 'pkg_missing');
assert_same(null, $missing['error_code'], 'Missing image should be a row-level error, not a package-level error.');
assert_same('error', $missing['items'][0]['validation_status'], 'Missing image row should be invalid.');
assert_true(in_array('RAG_PACKAGE_IMAGE_MISSING', $missing['items'][0]['validation_errors'], true), 'Missing image row should report RAG_PACKAGE_IMAGE_MISSING.');

$conflict = $service->parse($writeZip($conflictZip, 'pkg_conflict.zip'), 'pkg_conflict');
assert_same('RAG_PACKAGE_MANIFEST_CONFLICT', $conflict['error_code'], 'Multiple root manifests should be rejected.');

$traversal = $service->parse($writeZip($traversalZip, 'pkg_traversal.zip'), 'pkg_traversal');
assert_same('error', $traversal['items'][0]['validation_status'], 'Traversal image path should be invalid.');
assert_true(in_array('RAG_PACKAGE_PATH_INVALID', $traversal['items'][0]['validation_errors'], true), 'Traversal row should report RAG_PACKAGE_PATH_INVALID.');

