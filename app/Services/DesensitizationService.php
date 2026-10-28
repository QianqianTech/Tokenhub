<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class DesensitizationService
{
    private const POLICY_CACHE_TTL_SECONDS = 30;

    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'password',
        'passwd',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'apikey',
        'secret_key',
        'private_key',
        'key',
        'connection_string',
    ];

    public function outbound(array $payload, array $route): array
    {
        $apiKey = App::context()->apiKey() ?? [];
        $policies = $this->policiesFor($apiKey, $route);
        if ($policies === []) {
            return $payload;
        }

        $policy = $policies[0];
        $result = $this->applyPolicy($payload, $policy);
        App::db()->execute(
            'INSERT INTO desensitization_events (policy_id, request_id, target_type, action, match_count, summary) VALUES (?, ?, "outbound", ?, ?, ?)',
            [
                $policy['id'],
                App::context()->requestId(),
                $result['action'],
                $result['match_count'],
                json_encode(['route_model' => $route['name'] ?? null, ...($result['summary'] ?? [])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );

        if ($result['blocked']) {
            abort_json('DESENSITIZATION_BLOCKED', 'Outbound content matched a blocking desensitization policy', 422);
        }

        if ($result['action'] === 'summary') {
            return ['redacted_summary' => $result['summary']];
        }

        return is_array($result['payload']) ? $result['payload'] : [];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $route
     * @return list<array<string, mixed>>
     */
    public function policiesFor(array $apiKey, array $route): array
    {
        $cacheKey = $this->policyCacheKey($apiKey, $route);
        $cached = App::redis()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $policies = App::db()->select(
            'SELECT * FROM desensitization_policies
             WHERE status = "active"
               AND (
                 scope_type = "system"
                 OR (scope_type = "department" AND scope_id = ?)
                 OR (scope_type = "api_key" AND scope_id = ?)
                 OR (scope_type = "model" AND scope_id = ?)
                 OR (scope_type = "provider" AND scope_id = ?)
               )
             ORDER BY FIELD(scope_type, "api_key", "department", "model", "provider", "system"), id ASC',
            [$apiKey['department_id'] ?? 0, $apiKey['id'] ?? 0, $route['id'] ?? 0, $route['provider_id'] ?? 0]
        );
        App::redis()->set($cacheKey, $policies, self::POLICY_CACHE_TTL_SECONDS);

        return $policies;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $route
     */
    public function policyCacheKey(array $apiKey, array $route): string
    {
        return 'desensitization_policies:' . (int) ($apiKey['department_id'] ?? 0)
            . ':' . (int) ($apiKey['id'] ?? 0)
            . ':' . (int) ($route['id'] ?? 0)
            . ':' . (int) ($route['provider_id'] ?? 0);
    }

    private int $lastMatchCount = 0;

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    public function applyPolicy(mixed $payload, array $policy): array
    {
        $action = in_array(($policy['action'] ?? 'replace'), ['replace', 'block', 'summary'], true)
            ? (string) $policy['action']
            : 'replace';
        $redacted = $this->redact($payload);
        $matchCount = $this->lastMatchCount;
        $summary = [
            'sha256' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'content_types' => $this->contentTypes($payload, $policy['parsers'] ?? []),
            'redaction_failed' => false,
        ];

        if ($action === 'block') {
            return [
                'action' => 'block',
                'blocked' => $matchCount > 0,
                'match_count' => $matchCount,
                'payload' => null,
                'summary' => $summary,
            ];
        }

        if ($action === 'summary') {
            return [
                'action' => 'summary',
                'blocked' => false,
                'match_count' => $matchCount,
                'payload' => null,
                'summary' => $summary,
            ];
        }

        return [
            'action' => 'replace',
            'blocked' => false,
            'match_count' => $matchCount,
            'payload' => $redacted,
            'summary' => $summary,
        ];
    }

    public function redact(mixed $value): mixed
    {
        $this->lastMatchCount = 0;
        return $this->redactValue($value);
    }

    private function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            $this->lastMatchCount++;
            return '****';
        }

        if (is_string($value)) {
            $redacted = $value;
            $patterns = [
                '/Bearer\s+[A-Za-z0-9._~+\-\/]+=*/i' => 'Bearer ****',
                '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '****',
                '/("?(?:(?:api|access|secret|private)[_-]?key|token)"?\s*[:=]\s*)["\']?[^"\'\s,}]+/i' => '$1****',
                '/(\b(?:password|passwd|secret|token|api[_-]?key)\b\s*:\s*)["\']?[^"\'\r\n,}]+/i' => '$1****',
                '/(password\s*=\s*)["\']?[^"\'\s,}]+/i' => '$1****',
                '/(<[^>]+\bname=["\']?(?:token|secret|api[_-]?key|password)["\']?[^>]*\bvalue=["\']?)[^"\'\s>]+/i' => '$1****',
                '/1[3-9]\d{9}/' => '****',
                '/(DB_PASSWORD\s*=\s*)[^\r\n]+/i' => '$1****',
                '/\b(?:mysql|postgres|postgresql|redis):\/\/[^\s"\']+/i' => 'connection=****',
            ];
            foreach ($patterns as $pattern => $replacement) {
                $count = 0;
                $next = preg_replace($pattern, $replacement, $redacted, -1, $count);
                if ($next !== null) {
                    $redacted = $next;
                    $this->lastMatchCount += $count;
                }
            }
            return $redacted;
        }

        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $childKey => $childValue) {
            $out[$childKey] = $this->redactValue($childValue, (string) $childKey);
        }
        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return str_contains($normalized, 'api_key')
            || str_contains($normalized, 'apikey')
            || str_ends_with($normalized, '_secret')
            || str_ends_with($normalized, '_password')
            || str_ends_with($normalized, '_passwd')
            || str_ends_with($normalized, '_token')
            || str_ends_with($normalized, '_private_key')
            || str_ends_with($normalized, '_secret_key');
    }

    /**
     * @return list<string>
     */
    private function contentTypes(mixed $value, mixed $configured): array
    {
        $types = [];
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }
        foreach (is_array($configured) ? $configured : [] as $parser) {
            if (is_string($parser) && $parser !== '') {
                $types[] = $parser;
            }
        }

        $scan = function (mixed $item) use (&$scan, &$types): void {
            if (is_array($item)) {
                foreach ($item as $child) {
                    $scan($child);
                }
                return;
            }
            if (!is_string($item)) {
                return;
            }
            $trimmed = trim($item);
            if ($trimmed === '') {
                return;
            }
            if (json_decode($trimmed, true) !== null) {
                $types[] = 'json';
            }
            if (preg_match('/^[A-Z0-9_]+\s*=/mi', $trimmed) === 1) {
                $types[] = 'env';
            }
            if (preg_match('/\b(select|insert|update|delete)\b/i', $trimmed) === 1) {
                $types[] = 'sql';
            }
            if (preg_match('/<[^>]+>/', $trimmed) === 1) {
                $types[] = 'html';
            }
            if (preg_match('/\b(const|let|var|function|interface|type)\b/', $trimmed) === 1) {
                $types[] = 'javascript';
            }
            if (preg_match('/^\s*[\w.-]+\s*:\s*.+$/m', $trimmed) === 1) {
                $types[] = 'yaml';
            }
        };
        $scan($value);

        return array_values(array_unique($types ?: ['text']));
    }
}
