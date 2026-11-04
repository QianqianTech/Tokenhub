<?php

declare(strict_types=1);

namespace TokenHub\Support;

use TokenHub\Services\LogService;

final class Logger
{
    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'key_hash',
        'connection_string',
    ];

    private string $logPath;

    public function __construct(string|Config $logPathOrConfig, ?RequestContext $context = null)
    {
        $this->logPath = $logPathOrConfig instanceof Config
            ? (string) $logPathOrConfig->get('logging.path', storage_path('logs'))
            : $logPathOrConfig;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array|string $context = [], ?array $extra = null): void
    {
        if (is_string($context)) {
            $this->write($message, 'info', $context, $extra ?? []);
            return;
        }
        $this->write('app', 'info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array|string $context = [], ?array $extra = null): void
    {
        if (is_string($context)) {
            $this->write($message, 'error', $context, $extra ?? []);
            return;
        }
        $this->write('error', 'error', $message, $context);
    }

    public function warning(string $message, array|string $context = [], ?array $extra = null): void
    {
        if (is_string($context)) {
            $this->write($message, 'warning', $context, $extra ?? []);
            return;
        }
        $this->write('app', 'warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function audit(string $action, array $context = []): void
    {
        $this->write('audit', 'audit', $action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function write(string $channel, string $level, string $message, array $context = []): void
    {
        $service = new LogService($this->logPath);
        $service->write($channel, $level, $message, $context);
        if (self::stdoutEnabled()) {
            // BIZ-004: containers also emit every record on stdout/stderr so
            // `docker compose logs` and log shippers see it. request_id and
            // trace_id are filled from the current RequestContext (BIZ-008).
            self::mirror($service->normalizeRecord($channel, $level, $message, $context));
        }
    }

    private static ?bool $stdout = null;

    /**
     * logging.stdout: true / false / "auto" (default: on inside a container).
     */
    public static function stdoutEnabled(): bool
    {
        if (self::$stdout !== null) {
            return self::$stdout;
        }
        $value = 'auto';
        try {
            $value = App::config()->get('logging.stdout', 'auto');
        } catch (\Throwable) {
        }
        if (is_bool($value)) {
            return self::$stdout = $value;
        }
        $value = strtolower(trim((string) $value));
        if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
            return self::$stdout = true;
        }
        if (in_array($value, ['0', 'false', 'off', 'no', ''], true)) {
            return self::$stdout = false;
        }

        return self::$stdout = is_file('/.dockerenv');
    }

    /** Test hook: force stdout mirroring on/off (null = re-detect). */
    public static function setStdout(?bool $enabled): void
    {
        self::$stdout = $enabled;
    }

    /**
     * One JSON line on stdout (CLI) or stderr (FPM: catch_workers_output
     * forwards it to the container log).
     *
     * @param array<string, mixed> $record
     */
    public static function mirror(array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        $target = PHP_SAPI === 'cli' ? 'php://stdout' : 'php://stderr';
        $handle = @fopen($target, 'ab');
        if ($handle === false) {
            return;
        }
        @fwrite($handle, $line . PHP_EOL);
        @fclose($handle);
    }

    /**
     * Ids of the current request/job for log records (BIZ-008).
     *
     * @return array{request_id: ?string, trace_id: ?string}
     */
    public static function correlation(): array
    {
        try {
            $context = App::context();
            return ['request_id' => $context->requestId(), 'trace_id' => $context->traceId()];
        } catch (\Throwable) {
            return ['request_id' => null, 'trace_id' => null];
        }
    }

    public static function sanitize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) ? self::redactString($value) : $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string) $key);
            $out[$key] = self::isSensitiveKey($keyString) ? '****' : self::sanitize($item);
        }

        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function redactString(string $value): string
    {
        return preg_replace([
            '/(Bearer\s+)[A-Za-z0-9._~+\-\/]+=*/i',
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            '/\b1[3-9]\d{9}\b/',
            '/((?:password|passwd|secret|token|api[_-]?key|key_hash)\s*[=:]\s*)[^\s&,"\'}]+/i',
            '/\b(?:mysql|postgres|postgresql|redis):\/\/[^\s"\']+/i',
        ], [
            '$1****',
            '****',
            '****',
            '$1****',
            'connection=****',
        ], $value) ?? $value;
    }
}
