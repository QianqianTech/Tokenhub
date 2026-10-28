<?php

declare(strict_types=1);

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = $_SERVER['TOKENHUB_BASE_PATH'] ?? dirname(__DIR__, 2);

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR)));
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, mixed $default = null): mixed
    {
        return env($key, $default);
    }
}

if (!function_exists('abort_json')) {
    /**
     * Abort the current request with a JSON error.
     *
     * Throws instead of calling exit so request-scoped cleanup (quota
     * reservations, concurrency slots, usage records) can run. The front
     * controller renders the exception as the same JSON error response.
     */
    function abort_json(string $code, string $message, int $status = 400): never
    {
        throw new \TokenHub\Support\HttpAbortException($code, $message, $status);
    }
}
