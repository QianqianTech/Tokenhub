<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'TokenHub\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

