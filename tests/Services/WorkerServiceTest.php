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

$wafTempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tokenhub-worker-waf-' . bin2hex(random_bytes(6));
mkdir($wafTempRoot, 0775, true);
$wafLogFile = $wafTempRoot . DIRECTORY_SEPARATOR . 'caddy-security.jsonl';
$wafOffsetFile = $wafTempRoot . DIRECTORY_SEPARATOR . 'caddy-security.offset';
$wafRequestPrefix = 'req_worker_waf_' . bin2hex(random_bytes(4));
$wafIp = '203.0.113.' . random_int(51, 99);
$wafLines = [];
for ($i = 1; $i <= 2; $i++) {
    $wafLines[] = json_encode([
        'request_id' => $wafRequestPrefix . '_' . $i,
        'request' => ['remote_ip' => $wafIp, 'method' => 'GET', 'uri' => '/.env'],
        'vars' => ['waf_rule' => 'worker_import_rule', 'waf_action' => 'block'],
        'status' => 403,
    ], JSON_UNESCAPED_SLASHES);
}
file_put_contents($wafLogFile, implode("\n", $wafLines) . "\n");

try {
    $service->handle([
        'type' => 'waf.import',
        'file' => $wafLogFile,
        'options' => [
            'offset_file' => $wafOffsetFile,
            'threshold' => 2,
            'window_minutes' => 60,
        ],
    ]);
    assert_true((new \TokenHub\Services\LoginIpBanService())->isBanned($wafIp), 'WAF import worker job should forward options and auto-ban repeated blocked IPs.');
} finally {
    App::db()->execute('DELETE FROM waf_events WHERE request_id LIKE ?', [$wafRequestPrefix . '%']);
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$wafIp]);
}
