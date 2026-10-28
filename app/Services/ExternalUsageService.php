<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ExternalUsageService
{
    private const DEFAULT_DAYS = 30;

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function summary(array $apiKey, array $query): array
    {
        [$where, $bindings] = $this->where($apiKey, $query, 'day');
        $row = App::db()->first(
            "SELECT COALESCE(SUM(request_count), 0) request_count,
                    COALESCE(SUM(success_count), 0) success_count,
                    COALESCE(SUM(failed_count), 0) failed_count,
                    COALESCE(SUM(input_tokens), 0) input_tokens,
                    COALESCE(SUM(output_tokens), 0) output_tokens,
                    COALESCE(SUM(cache_read_tokens), 0) cache_read_tokens,
                    COALESCE(SUM(cache_write_tokens), 0) cache_write_tokens,
                    COALESCE(SUM(total_cost), 0) total_cost,
                    COALESCE(SUM(latency_ms_sum), 0) latency_ms_sum
             FROM usage_aggregates_day
             {$where}",
            $bindings
        ) ?: [];

        $requestCount = (int) ($row['request_count'] ?? 0);
        $successCount = (int) ($row['success_count'] ?? 0);
        $inputTokens = (int) ($row['input_tokens'] ?? 0);
        $outputTokens = (int) ($row['output_tokens'] ?? 0);
        $cacheReadTokens = (int) ($row['cache_read_tokens'] ?? 0);
        $cacheWriteTokens = (int) ($row['cache_write_tokens'] ?? 0);

        return [
            'scope' => $this->scope($apiKey),
            'period' => $this->period($query),
            'totals' => [
                'request_count' => $requestCount,
                'success_count' => $successCount,
                'failed_count' => (int) ($row['failed_count'] ?? 0),
                'success_rate' => $requestCount > 0 ? round($successCount / $requestCount * 100, 2) : 0,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
                'cache_read_tokens' => $cacheReadTokens,
                'cache_write_tokens' => $cacheWriteTokens,
                'cache_tokens' => $cacheReadTokens + $cacheWriteTokens,
                'total_cost' => $row['total_cost'] === null ? null : (string) $row['total_cost'],
                'avg_latency_ms' => $requestCount > 0 ? round(((float) ($row['latency_ms_sum'] ?? 0)) / $requestCount, 2) : 0,
            ],
            'by_model' => $this->byDimension($apiKey, $query, 'model_id'),
            'by_capability' => $this->byDimension($apiKey, $query, 'capability'),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function trends(array $apiKey, array $query): array
    {
        $bucket = $this->bucket($query);
        [$where, $bindings] = $this->where($apiKey, $query, $bucket);
        $table = $bucket === 'day' ? 'usage_aggregates_day' : 'usage_aggregates_hour';
        $limit = min(200, max(1, (int) ($query['limit'] ?? ($bucket === 'day' ? 31 : 48))));
        $rows = App::db()->select(
            "SELECT bucket_at,
                    COALESCE(SUM(request_count), 0) request_count,
                    COALESCE(SUM(success_count), 0) success_count,
                    COALESCE(SUM(failed_count), 0) failed_count,
                    COALESCE(SUM(input_tokens), 0) input_tokens,
                    COALESCE(SUM(output_tokens), 0) output_tokens,
                    COALESCE(SUM(cache_read_tokens), 0) cache_read_tokens,
                    COALESCE(SUM(cache_write_tokens), 0) cache_write_tokens,
                    COALESCE(SUM(total_cost), 0) total_cost,
                    COALESCE(SUM(latency_ms_sum), 0) latency_ms_sum
             FROM {$table}
             {$where}
             GROUP BY bucket_at
             ORDER BY bucket_at ASC
             LIMIT {$limit}",
            $bindings
        );

        return [
            'scope' => $this->scope($apiKey),
            'period' => $this->period($query),
            'bucket' => $bucket,
            'buckets' => array_map(fn (array $row): array => $this->publicBucket($row), $rows),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $apiKey, array $query, string $bucket): array
    {
        $period = $this->period($query);
        $conditions = ['api_key_id = ?', 'bucket_at >= ?'];
        $bindings = [(int) ($apiKey['id'] ?? 0), $period['from']];
        if ($period['to'] !== null) {
            $conditions[] = 'bucket_at <= ?';
            $bindings[] = $period['to'];
        }
        foreach (['model_id', 'provider_id', 'capability'] as $key) {
            if (!array_key_exists($key, $query) || (string) $query[$key] === '') {
                continue;
            }
            $conditions[] = "{$key} = ?";
            $bindings[] = $key === 'capability' ? (string) $query[$key] : (int) $query[$key];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function byDimension(array $apiKey, array $query, string $dimension): array
    {
        [$where, $bindings] = $this->where($apiKey, $query, 'day');
        $rows = App::db()->select(
            "SELECT {$dimension} AS id,
                    COALESCE(SUM(request_count), 0) request_count,
                    COALESCE(SUM(success_count), 0) success_count,
                    COALESCE(SUM(failed_count), 0) failed_count,
                    COALESCE(SUM(input_tokens), 0) input_tokens,
                    COALESCE(SUM(output_tokens), 0) output_tokens,
                    COALESCE(SUM(cache_read_tokens), 0) cache_read_tokens,
                    COALESCE(SUM(cache_write_tokens), 0) cache_write_tokens,
                    COALESCE(SUM(total_cost), 0) total_cost
             FROM usage_aggregates_day
             {$where}
             GROUP BY {$dimension}
             ORDER BY request_count DESC
             LIMIT 20",
            $bindings
        );

        return array_map(function (array $row) use ($dimension): array {
            $input = (int) ($row['input_tokens'] ?? 0);
            $output = (int) ($row['output_tokens'] ?? 0);
            $cacheRead = (int) ($row['cache_read_tokens'] ?? 0);
            $cacheWrite = (int) ($row['cache_write_tokens'] ?? 0);
            return [
                $dimension => $row['id'],
                'request_count' => (int) ($row['request_count'] ?? 0),
                'success_count' => (int) ($row['success_count'] ?? 0),
                'failed_count' => (int) ($row['failed_count'] ?? 0),
                'input_tokens' => $input,
                'output_tokens' => $output,
                'total_tokens' => $input + $output,
                'cache_tokens' => $cacheRead + $cacheWrite,
                'total_cost' => $row['total_cost'] === null ? null : (string) $row['total_cost'],
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{from: string, to: string|null}
     */
    private function period(array $query): array
    {
        $from = $this->dateTime($query['from'] ?? null);
        if ($from === null) {
            $from = gmdate('Y-m-d 00:00:00', time() - (self::DEFAULT_DAYS - 1) * 86400);
        }
        $to = $this->dateTime($query['to'] ?? null);

        return ['from' => $from, 'to' => $to];
    }

    private function bucket(array $query): string
    {
        return (string) ($query['bucket'] ?? 'day') === 'hour' ? 'hour' : 'day';
    }

    private function dateTime(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        $time = strtotime((string) $value);
        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, int|null>
     */
    private function scope(array $apiKey): array
    {
        return [
            'api_key_id' => isset($apiKey['id']) ? (int) $apiKey['id'] : null,
            'user_id' => isset($apiKey['user_id']) ? (int) $apiKey['user_id'] : null,
            'department_id' => isset($apiKey['department_id']) ? (int) $apiKey['department_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicBucket(array $row): array
    {
        $requestCount = (int) ($row['request_count'] ?? 0);
        $successCount = (int) ($row['success_count'] ?? 0);
        $input = (int) ($row['input_tokens'] ?? 0);
        $output = (int) ($row['output_tokens'] ?? 0);
        $cacheRead = (int) ($row['cache_read_tokens'] ?? 0);
        $cacheWrite = (int) ($row['cache_write_tokens'] ?? 0);

        return [
            'bucket_at' => $row['bucket_at'],
            'request_count' => $requestCount,
            'success_count' => $successCount,
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'success_rate' => $requestCount > 0 ? round($successCount / $requestCount * 100, 2) : 0,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'cache_tokens' => $cacheRead + $cacheWrite,
            'total_cost' => $row['total_cost'] === null ? null : (string) $row['total_cost'],
            'avg_latency_ms' => $requestCount > 0 ? round(((float) ($row['latency_ms_sum'] ?? 0)) / $requestCount, 2) : 0,
        ];
    }
}

