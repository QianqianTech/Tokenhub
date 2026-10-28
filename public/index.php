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
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $file = $basePath . '/app/' . $relative;
        if (is_file($file)) {
            require $file;
        }
    });
}

use TokenHub\Bootstrap\Kernel;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Response;

App::bootstrap($basePath);

try {
    $response = Kernel::router()->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');

    if ($response === null) {
        $response = Response::error('NOT_FOUND', 'Route not found', 404);
    }
} catch (HttpAbortException $abort) {
    $response = $abort->response();
} catch (Throwable $throwable) {
    App::logger()->error('Unhandled request exception', [
        'request_id' => App::context()->requestId(),
        'error' => $throwable->getMessage(),
    ]);
    $response = Response::error('INTERNAL_ERROR', 'Internal server error', 500);
}

try {
    $response
        ->withHeader('X-Request-Id', App::context()->requestId())
        ->withHeader('X-Trace-Id', App::context()->traceId())
        ->send();
} catch (HttpAbortException $abort) {
    // A streaming writer aborted after headers may already be sent.
    if (!headers_sent()) {
        $abort->response()->send();
    } else {
        echo 'event: error' . "\n" . 'data: ' . json_encode(['error' => ['code' => $abort->errorCode, 'message' => $abort->getMessage()]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
} catch (Throwable $throwable) {
    App::logger()->error('Unhandled response exception', [
        'request_id' => App::context()->requestId(),
        'error' => $throwable->getMessage(),
    ]);
}
