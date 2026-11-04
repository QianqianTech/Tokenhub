<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * SEC-102: stored file paths (media_assets.storage_path, rag_uploads.storage_path,
 * export / log-archive paths) are resolved with realpath() and must lie inside
 * the configured storage root, so a tampered path cannot read or delete .env.
 * SEC-107: uploaded ZIP containers are bounded (entries, per-entry and total
 * uncompressed size) before anything is inflated.
 */

use TokenHub\Services\Media\LocalMediaStorage;
use TokenHub\Services\Media\MediaStorageManager;
use TokenHub\Services\Media\PathConfinement;
use TokenHub\Services\Media\ZipLimits;
use TokenHub\Services\RagDocumentParserService;
use TokenHub\Services\RagPackageImportService;
use TokenHub\Support\HttpAbortException;

$base = sys_get_temp_dir() . '/th-confine-' . bin2hex(random_bytes(4));
$root = $base . '/media';
mkdir($root . '/2026/10', 0775, true);
file_put_contents($base . '/.env', "APP_KEY=secret\n");
file_put_contents($root . '/2026/10/ok.png', 'PNGDATA');
$outsideTarget = $base . '/outside.txt';
file_put_contents($outsideTarget, 'outside');
@symlink($outsideTarget, $root . '/2026/10/link.png');

try {
    $storage = new LocalMediaStorage($root);
    assert_same('PNGDATA', $storage->read($root . '/2026/10/ok.png'), 'A file inside the media root is readable.');
    assert_same(false, $storage->read($base . '/.env'), 'An absolute path outside the root (.env) is refused.');
    assert_same(false, $storage->read($root . '/2026/10/../../../.env'), 'storage_path traversal with ".." is refused.');
    assert_same(false, $storage->read('../.env'), 'A relative traversal path is refused.');
    assert_same(false, $storage->read('/etc/passwd'), 'System files are refused.');
    assert_same(false, $storage->read($root . "/2026/10/ok.png\0.txt"), 'NUL bytes are refused.');
    if (is_link($root . '/2026/10/link.png')) {
        assert_same(false, $storage->read($root . '/2026/10/link.png'), 'A symlink inside the root pointing outside is refused.');
    }
    assert_same(false, $storage->delete($base . '/.env'), 'Deleting outside the root is refused.');
    assert_true(is_file($base . '/.env'), '.env still exists after a refused delete.');
    assert_same(false, $storage->delete($root . '/2026/10/../../.env'), 'Deleting via traversal is refused.');
    assert_true(is_file($base . '/.env'), '.env still exists after a refused traversal delete.');
    $stored = $storage->put('NEWBYTES', 'png', 'image/png');
    assert_true(str_starts_with($stored['storage_path'], $root), 'put() writes inside the root.');
    assert_same('NEWBYTES', $storage->read($stored['storage_path']), 'A stored file reads back.');
    assert_same(true, $storage->delete($stored['storage_path']), 'A stored file can be deleted.');
    assert_true(!is_file($stored['storage_path']), 'The stored file is gone.');

    // Root itself must be a real directory; "/" never confines anything.
    assert_same(null, PathConfinement::resolve('/etc/hostname', ['/']), 'A "/" root is ignored.');
    assert_same(null, PathConfinement::resolve($root . '/2026/10/ok.png', [$base . '/missing']), 'A missing root holds nothing.');
    assert_same(realpath($root . '/2026/10/ok.png'), PathConfinement::resolve($root . '/2026/10/ok.png', [$root]), 'Resolve returns the real path.');
    assert_same(null, PathConfinement::resolve($base . '/media-evil/x', [$root]), 'A sibling directory sharing the root prefix is not inside the root.');

    // Default driver: storage/media roots only; the default .env of the app is never readable.
    $default = MediaStorageManager::forPath(dirname(__DIR__, 2) . '/.env.example');
    assert_true($default !== null, 'Local paths are handled by the local driver.');
    assert_same(false, $default->read(dirname(__DIR__, 2) . '/.env.example'), 'The default media driver refuses files outside storage/media.');
    assert_same(false, $default->read(storage_path('media') . '/../../.env.example'), 'The default media driver refuses traversal out of storage/media.');

    // RAG document parser: only files in storage/rag/uploads are parsed.
    $parser = new RagDocumentParserService();
    $refused = false;
    try {
        $parser->parse($base . '/.env', 'notes.txt', 'text/plain');
    } catch (HttpAbortException $exception) {
        $refused = $exception->errorCode === 'RAG_DOCUMENT_UNREADABLE';
    }
    assert_true($refused, 'The document parser refuses a storage_path outside the upload root.');
    $scoped = new RagDocumentParserService(null, [$base]);
    $parsed = $scoped->parse($root . '/2026/10/ok.png', 'notes.txt', 'text/plain');
    assert_true(str_contains((string) json_encode($parsed), 'PNGDATA'), 'A file inside the allowed root is parsed.');

    // RAG image package: only packages in storage/rag/uploads are opened.
    $package = (new RagPackageImportService())->parse($base . '/.env', 'pkg_outside_' . bin2hex(random_bytes(3)));
    assert_same('RAG_PACKAGE_INVALID', $package['error_code'], 'The package importer refuses a path outside the upload root.');
    @rmdir($package['package_dir']);

    // ---------------------------------------------------------------- SEC-107 zip limits
    if (class_exists(ZipArchive::class)) {
        $makeZip = static function (string $path, array $entries): string {
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
            return $path;
        };
        $open = static function (string $path): ZipArchive {
            $zip = new ZipArchive();
            $zip->open($path);
            return $zip;
        };
        $bomb = $makeZip($base . '/bomb.zip', ['a.txt' => str_repeat('0', 200000)]);
        assert_true(filesize($bomb) < 5000, 'The fixture compresses well (zip bomb shape).');
        assert_same('RAG_ZIP_ENTRY_TOO_LARGE', (new ZipLimits(10, 100000, 10000000))->violation($open($bomb)), 'An entry inflating past the per-entry limit is refused.');
        $many = $makeZip($base . '/many.zip', ['a.txt' => 'a', 'b.txt' => 'b', 'c.txt' => 'c']);
        assert_same('RAG_ZIP_TOO_MANY_ENTRIES', (new ZipLimits(2, 100, 1000))->violation($open($many)), 'Too many entries are refused.');
        $total = $makeZip($base . '/total.zip', ['a.txt' => str_repeat('a', 600), 'b.txt' => str_repeat('b', 600)]);
        assert_same('RAG_ZIP_TOO_LARGE', (new ZipLimits(10, 1000, 1000))->violation($open($total)), 'The total uncompressed size is bounded.');
        assert_same(null, (new ZipLimits(10, 1000, 2000))->violation($open($total)), 'Archives within the limits pass.');
        assert_same(false, (new ZipLimits(10, 100, 1000))->read($open($bomb), 'a.txt'), 'A single read never inflates past the per-entry limit.');
        assert_same(str_repeat('a', 600), (new ZipLimits(10, 1000, 2000))->read($open($total), 'a.txt'), 'Entries within the limit read normally.');

        // Package importer applies the limits before extracting (path inside storage/rag/uploads).
        $uploads = storage_path('rag' . DIRECTORY_SEPARATOR . 'uploads');
        if (!is_dir($uploads)) {
            mkdir($uploads, 0775, true);
        }
        $packageId = 'pkg_bomb_' . bin2hex(random_bytes(3));
        $packagePath = $makeZip($uploads . '/' . $packageId . '.zip', ['manifest.csv' => "image,title\n", 'images/a.png' => str_repeat('0', 200000)]);
        $result = (new RagPackageImportService(new ZipLimits(100, 100000, 10000000)))->parse($packagePath, $packageId);
        assert_same('RAG_ZIP_ENTRY_TOO_LARGE', $result['error_code'], 'The package importer refuses a zip bomb before extracting it.');
        @unlink($packagePath);
        @rmdir($result['package_dir']);

        // docx is a ZIP: the parser checks the limits before reading word/document.xml.
        $docx = $makeZip($base . '/big.docx', ['word/document.xml' => str_repeat('<w:t>x</w:t>', 50000)]);
        $refused = null;
        try {
            (new RagDocumentParserService(null, [$base], new ZipLimits(100, 1000, 100000)))->parse($docx, 'big.docx');
        } catch (HttpAbortException $exception) {
            $refused = $exception->errorCode;
        }
        assert_same('RAG_ZIP_ENTRY_TOO_LARGE', $refused, 'An oversized docx entry is refused before parsing.');
    }
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($base);
}

echo "PASS\n";
