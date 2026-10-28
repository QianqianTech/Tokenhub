<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class JsonLogger
{
    public function __construct(private readonly string $path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $channel, string $message, array $context = []): void
    {
        $this->write($channel, 'info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $channel, string $message, array $context = []): void
    {
        $this->write($channel, 'error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $channel, string $level, string $message, array $context): void
    {
        $record = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $this->sanitize($message),
            'context' => $this->sanitizeContext($context),
        ];

        file_put_contents(
            $this->path . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $channel) . '.jsonl',
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/authorization|cookie|api[_-]?key|key_hash|password|passwd|secret|token|connection/i', (string) $key)) {
                $context[$key] = '****';
                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
            } elseif (is_string($value)) {
                $context[$key] = $this->sanitize($value);
            }
        }

        return $context;
    }

    private function sanitize(string $value): string
    {
        return preg_replace([
            '/(Bearer\s+)[A-Za-z0-9._~+\-\/]+=*/i',
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            '/\b1[3-9]\d{9}\b/',
            '/(password|passwd|secret|token|api[_-]?key|key_hash)=([^\\s&]+)/i',
            '/\b(?:mysql|postgres|postgresql|redis):\/\/[^\s"\']+/i',
        ], [
            '$1****',
            '****',
            '****',
            '$1=****',
            'connection=****',
        ], $value) ?? $value;
    }
}
