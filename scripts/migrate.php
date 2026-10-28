<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$autoload = $basePath . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require $basePath . '/app/Support/helpers.php';
    spl_autoload_register(function (string $class) use ($basePath): void {
        $prefix = 'TokenHub\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $basePath . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

use TokenHub\Support\App;
use TokenHub\Support\MigrationRunner;

App::bootstrap($basePath);
$ran = (new MigrationRunner(App::db(), $basePath . '/database/migrations'))->run();
echo json_encode(['ok' => true, 'ran' => $ran], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
