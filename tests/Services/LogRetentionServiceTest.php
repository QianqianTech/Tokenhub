<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\LogRetentionService;
use TokenHub\Services\LogRetentionPolicyService;
use TokenHub\Services\LogService;
use TokenHub\Support\Crypto;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tokenhub-log-retention-' . bin2hex(random_bytes(6));
mkdir($root, 0775, true);

$service = new LogService($root);
$service->write('worker', 'info', 'old worker log', ['request_id' => 'req_rotate']);
$active = $root . DIRECTORY_SEPARATOR . 'worker.jsonl';
touch($active, strtotime('2026-07-07 00:00:00'));

$retention = new LogRetentionService($root);
$rotated = $retention->rotate('worker', 1, strtotime('2026-07-08 00:00:00'));
assert_same(1, count($rotated), 'Daily rotation should rotate yesterday active log.');
assert_true(is_file($active), 'Active log file should exist after rotation.');
assert_same('', (string) file_get_contents($active), 'Active log file should be reopened empty after rotation.');

$archives = $retention->archive([
    [
        'log_type' => 'worker',
        'online_retention_days' => 0,
        'archive_retention_days' => 30,
        'compress_enabled' => 1,
        'encrypt_enabled' => 1,
    ],
], strtotime('2026-07-08 00:00:00'));

assert_same(1, count($archives), 'Archive should create one encrypted archive for rotated logs outside online retention.');
assert_true(str_ends_with($archives[0]['archive_path'], '.jsonl.gz.enc'), 'Archive path should indicate compressed encrypted JSONL.');
$encrypted = (string) file_get_contents($archives[0]['archive_path']);
$plainGz = Crypto::decrypt($encrypted);
assert_true(str_contains((string) gzdecode($plainGz), 'req_rotate'), 'Archive should decrypt and decompress to original log content.');

$deleted = [];
$count = $retention->cleanupArchives([
    ['archive_path' => $archives[0]['archive_path'], 'created_at' => '2026-01-01 00:00:00', 'archive_retention_days' => 1],
], strtotime('2026-07-08 00:00:00'), function (string $path) use (&$deleted): void {
    $deleted[] = $path;
});
assert_same(1, $count, 'Expired archive cleanup should count deleted archive files.');
assert_same([$archives[0]['archive_path']], $deleted, 'Expired archive cleanup should only delete archive file paths.');

$policy = (new LogRetentionPolicyService())->normalize('audit', [
    'online_retention_days' => 7,
    'archive_retention_days' => 10,
    'min_retention_days' => 180,
]);
assert_same(180, $policy['online_retention_days'], 'Audit online retention should not be lower than min_retention_days.');
assert_same(180, $policy['archive_retention_days'], 'Audit archive retention should not be lower than min_retention_days.');

$security = (new LogRetentionPolicyService())->normalize('security', [
    'online_retention_days' => 30,
    'archive_retention_days' => 90,
    'min_retention_days' => 180,
]);
assert_same(180, $security['online_retention_days'], 'Security online retention should not be lower than min_retention_days.');
assert_same(180, $security['archive_retention_days'], 'Security archive retention should not be lower than min_retention_days.');
