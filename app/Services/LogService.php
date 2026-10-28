<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Logger;

final class LogService
{
    public const LOG_TYPES = [
        'caddy-access',
        'caddy-security',
        'gateway-access',
        'provider',
        'usage',
        'audit',
        'security',
        'performance',
        'worker',
        'export',
    ];

    private const STANDARD_FIELDS = [
        'timestamp',
        'level',
        'message',
        'request_id',
        'trace_id',
        'user_id',
        'department_id',
        'api_key_id',
        'provider_id',
        'model_id',
        'client_ip',
        'status',
        'latency_ms',
        'error_code',
    ];

    private const CONTENT_KEYS = [
        'body',
        'content',
        'input',
        'messages',
        'output',
        'prompt',
        'request_body',
        'response',
        'response_body',
    ];

    public function __construct(private readonly ?string $logPath = null)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function write(string $logType, string $level, string $message, array $context = []): bool
    {
        $type = $this->normalizeType($logType);
        $record = $this->normalizeRecord($type, $level, $message, $context);
        $path = $this->activePath($type);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->recordWriteFailure($type, 'log_directory_unavailable');
            return false;
        }

        try {
            $written = @file_put_contents(
                $path,
                json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
            if ($written === false) {
                $this->recordWriteFailure($type, 'file_put_contents_failed');
                return false;
            }
            return true;
        } catch (\Throwable $throwable) {
            $this->recordWriteFailure($type, $throwable->getMessage());
            return false;
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function query(array $filters): array
    {
        $types = isset($filters['log_type']) && (string) $filters['log_type'] !== ''
            ? [$this->normalizeType((string) $filters['log_type'])]
            : self::LOG_TYPES;
        $limit = min(1000, max(1, (int) ($filters['limit'] ?? 100)));
        $rows = [];

        foreach ($types as $type) {
            $path = $this->activePath($type);
            if (!is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $row = $this->normalizeExternalRow($type, $row);
                if (!$this->matches($row, $filters)) {
                    continue;
                }
                $rows[] = $row;
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));
        return array_slice($rows, 0, $limit);
    }

    public function activePath(string $logType): string
    {
        return rtrim($this->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->normalizeType($logType) . '.jsonl';
    }

    public function basePath(): string
    {
        return $this->logPath ?? (string) App::config()->get('logging.path', storage_path('logs'));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function normalizeRecord(string $logType, string $level, string $message, array $context): array
    {
        $context = $this->redact($context);
        $record = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => Logger::sanitize($message),
            'log_type' => $this->normalizeType($logType),
        ];

        foreach (self::STANDARD_FIELDS as $field) {
            if ($field === 'timestamp' || $field === 'level' || $field === 'message') {
                continue;
            }
            $record[$field] = $context[$field] ?? $this->contextDefault($field);
            unset($context[$field]);
        }

        $context = array_filter($context, static fn (mixed $value): bool => $value !== null);
        if ($context !== []) {
            $record['context'] = $context;
        }

        return $record;
    }

    public function normalizeType(string $logType): string
    {
        $type = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($logType)) ?: 'app';
        return in_array($type, self::LOG_TYPES, true) ? $type : 'app';
    }

    public function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return Logger::sanitize($value);
        }

        $out = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower(str_replace(['-', '_'], '', (string) $key));
            if (!in_array($keyString, ['apikeyid'], true) && preg_match('/authorization|cookie|apikey|keyhash|password|passwd|secret|token|connectionstring/i', $keyString) === 1) {
                $out[$key] = '****';
                continue;
            }
            if (in_array($keyString, self::CONTENT_KEYS, true)) {
                $out[$key] = $this->contentSummary($item);
                continue;
            }
            $out[$key] = Logger::sanitize($this->redact($item));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeExternalRow(string $logType, array $row): array
    {
        $row['log_type'] ??= $logType;

        if (str_starts_with($logType, 'caddy-')) {
            $request = is_array($row['request'] ?? null) ? $row['request'] : [];
            $timestamp = $row['timestamp'] ?? null;
            if ($timestamp === null && isset($row['ts'])) {
                $timestamp = is_numeric($row['ts']) ? gmdate('c', (int) floor((float) $row['ts'])) : (string) $row['ts'];
            }

            $duration = $row['duration'] ?? null;
            $normalized = [
                'timestamp' => $timestamp ?? gmdate('c'),
                'level' => $row['level'] ?? 'info',
                'message' => $row['msg'] ?? 'caddy access',
                'log_type' => $logType,
                'request_id' => $row['request_id'] ?? $row['request>headers>X-Request-Id'][0] ?? null,
                'trace_id' => $row['trace_id'] ?? $row['request>headers>X-Trace-Id'][0] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'department_id' => $row['department_id'] ?? null,
                'api_key_id' => $row['api_key_id'] ?? null,
                'provider_id' => $row['provider_id'] ?? null,
                'model_id' => $row['model_id'] ?? null,
                'client_ip' => $row['client_ip'] ?? $request['remote_ip'] ?? $row['remote_ip'] ?? null,
                'status' => isset($row['status']) ? (int) $row['status'] : null,
                'latency_ms' => is_numeric($duration) ? (int) round(((float) $duration) * 1000) : ($row['latency_ms'] ?? null),
                'error_code' => $row['error_code'] ?? null,
                'context' => [
                    'method' => $request['method'] ?? null,
                    'path' => isset($request['uri']) && is_string($request['uri'])
                        ? (parse_url($request['uri'], PHP_URL_PATH) ?: $request['uri'])
                        : null,
                    'size' => $row['size'] ?? null,
                    'waf_rule' => $row['vars']['waf_rule'] ?? $row['waf_rule'] ?? null,
                    'waf_action' => $row['vars']['waf_action'] ?? $row['waf_action'] ?? null,
                ],
            ];

            return $this->redact($normalized);
        }

        return $this->redact($row);
    }

    private function contextDefault(string $field): mixed
    {
        if (!class_exists(App::class)) {
            return null;
        }

        try {
            $context = App::context();
            return match ($field) {
                'request_id' => $context->requestId(),
                'trace_id' => $context->traceId(),
                'user_id' => $context->apiKey()['user_id'] ?? $context->adminUser()['id'] ?? null,
                'department_id' => $context->apiKey()['department_id'] ?? $context->adminUser()['department_id'] ?? null,
                'api_key_id' => $context->apiKey()['id'] ?? null,
                'client_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function contentSummary(mixed $value): array
    {
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = is_string($encoded) ? $encoded : '';
        return [
            'redacted' => true,
            'bytes' => strlen($encoded),
            'sha256' => hash('sha256', $encoded),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filters
     */
    private function matches(array $row, array $filters): bool
    {
        foreach (['request_id', 'trace_id', 'user_id', 'department_id', 'api_key_id', 'provider_id', 'model_id', 'status', 'error_code'] as $field) {
            if (isset($filters[$field]) && (string) $filters[$field] !== '' && (string) ($row[$field] ?? '') !== (string) $filters[$field]) {
                return false;
            }
        }

        if (isset($filters['security_event']) && (string) $filters['security_event'] !== '') {
            $context = is_array($row['context'] ?? null) ? $row['context'] : [];
            $needle = (string) $filters['security_event'];
            if (!in_array($needle, [(string) ($context['waf_rule'] ?? ''), (string) ($context['waf_action'] ?? '')], true)) {
                return false;
            }
        }

        if (isset($filters['from']) && (string) $filters['from'] !== '' && strtotime((string) ($row['timestamp'] ?? '')) < strtotime((string) $filters['from'])) {
            return false;
        }
        if (isset($filters['to']) && (string) $filters['to'] !== '' && strtotime((string) ($row['timestamp'] ?? '')) > strtotime((string) $filters['to'])) {
            return false;
        }

        $scope = $filters['_scope'] ?? null;
        if (is_array($scope) && !$this->matchesScope($row, $scope)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $scope
     */
    private function matchesScope(array $row, array $scope): bool
    {
        if (($scope['role'] ?? '') === 'super_admin') {
            return true;
        }
        if (($scope['role'] ?? '') === 'user') {
            return (int) ($row['user_id'] ?? $row['actor_user_id'] ?? 0) === (int) ($scope['id'] ?? 0);
        }

        $departmentIds = $scope['department_ids'] ?? [];
        if (!is_array($departmentIds) || $departmentIds === []) {
            return false;
        }

        return in_array((int) ($row['department_id'] ?? 0), array_map('intval', $departmentIds), true);
    }

    private function recordWriteFailure(string $logType, string $error): void
    {
        try {
            (new AlertService())->record('log_write_failed', 'critical', 'Log write failed', [
                'log_type' => $logType,
                'error' => $error,
            ]);
        } catch (\Throwable) {
        }
    }
}
