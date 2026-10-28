<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests'));
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);
$failures = 0;

foreach ($files as $file) {
    try {
        require $file;
        fwrite(STDOUT, 'PASS ' . str_replace($root . DIRECTORY_SEPARATOR, '', $file) . PHP_EOL);
    } catch (Throwable $throwable) {
        $failures++;
        fwrite(STDERR, 'FAIL ' . str_replace($root . DIRECTORY_SEPARATOR, '', $file) . PHP_EOL);
        fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . ' test file(s) failed.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, count($files) . ' test file(s) passed.' . PHP_EOL);
