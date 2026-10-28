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
        (new LogService($this->logPath))->write($channel, $level, $message, $context);
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
