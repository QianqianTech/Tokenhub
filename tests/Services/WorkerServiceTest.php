<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\WorkerService;
use TokenHub\Support\App;

$service = new WorkerService();
$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tokenhub-expired-media-test.txt';
file_put_contents($path, 'expired');

$rows = [['id' => 1, 'storage_path' => $path]];
$deleted = [];
$updates = [];
$count = $service->cleanupExpiredMediaFilesForRows(
    $rows,
    static function (string $file) use (&$deleted): void {
        $deleted[] = $file;
        if (is_file($file)) {
            unlink($file);
        }
    },
    static function (int $id) use (&$updates): void {
        $updates[] = $id;
    },
);

assert_same(1, $count, 'Cleanup helper should report one expired media asset.');
assert_same([$path], $deleted, 'Cleanup helper should delete the expired file.');
assert_same([1], $updates, 'Cleanup helper should preserve row and only clear storage path.');
assert_true(!is_file($path), 'Expired media file should be removed from disk.');

