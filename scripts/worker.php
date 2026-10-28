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

use TokenHub\Services\WorkerService;
use TokenHub\Support\App;

App::bootstrap($basePath);
$queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
$failedQueue = (string) App::config()->get('queue.failed_name', 'tokenhub:failed-jobs');
$worker = new WorkerService();

while (true) {
    $job = App::redis()->pop($queue, 5);
    if ($job === null) {
        continue;
    }

    try {
        $worker->handle($job);
    } catch (Throwable $e) {
        App::logger()->error('worker', 'Job failed', ['job' => $job, 'exception' => $e::class, 'message' => $e->getMessage()]);
        App::redis()->push($failedQueue, ['job' => $job, 'error' => $e->getMessage(), 'failed_at' => gmdate('c')]);
    }
}
