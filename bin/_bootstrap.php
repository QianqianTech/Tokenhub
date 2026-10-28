<?php

declare(strict_types=1);

// Shared bootstrap for maintenance CLIs in bin/.
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

\TokenHub\Support\App::bootstrap($basePath);

/**
 * @param list<string> $argv
 */
function cli_option(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen($name) + 3);
        }
        if ($argument === '--' . $name) {
            return '1';
        }
    }

    return $default;
}
