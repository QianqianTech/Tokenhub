<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\LogService;

$tmp = sys_get_temp_dir() . '/tokenhub-log-failure-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
mkdir($tmp . '/audit.jsonl');

$service = new LogService($tmp);
ob_start();
$written = $service->write('audit', 'info', 'write failure should not leak warning');
$output = ob_get_clean();

assert_same(false, $written, 'LogService should report write failure.');
assert_same('', $output, 'LogService write failure should not emit PHP warnings to the HTTP response.');

rmdir($tmp . '/audit.jsonl');
rmdir($tmp);

