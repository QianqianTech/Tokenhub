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

App::bootstrap($basePath);
$type = $argv[1] ?? '';
if (!in_array($type, ['cleanup.retention', 'cleanup.exports', 'cleanup.media', 'cleanup.external-events'], true)) {
    fwrite(STDERR, "Usage: php scripts/enqueue.php cleanup.retention|cleanup.exports|cleanup.media|cleanup.external-events\n");
    exit(1);
}

App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), ['type' => $type, 'queued_at' => gmdate('c')]);
echo "queued {$type}\n";
