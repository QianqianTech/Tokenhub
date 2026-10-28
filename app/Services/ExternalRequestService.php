<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ExternalRequestService
{
    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function records(array $apiKey, array $query): array
    {
        $limit = min(500, max(1, (int) ($query['limit'] ?? 100)));
        $clauses = ['api_key_id = ?'];
        $bindings = [$apiKey['id'] ?? 0];

        foreach (['model_id', 'status', 'error_code', 'capability'] as $field) {
            if (isset($query[$field]) && (string) $query[$field] !== '') {
                $clauses[] = $field . ' = ?';
                $bindings[] = (string) $query[$field];
            }
        }
        if (isset($query['from']) && (string) $query['from'] !== '') {
            $clauses[] = 'created_at >= ?';
            $bindings[] = $this->timestamp((string) $query['from']);
        }
        if (isset($query['to']) && (string) $query['to'] !== '') {
            $clauses[] = 'created_at <= ?';
            $bindings[] = $this->timestamp((string) $query['to']);
        }
        if (isset($query['cursor']) && preg_match('/^id_(\d+)$/', (string) $query['cursor'], $matches) === 1) {
            $clauses[] = 'id < ?';
            $bindings[] = (int) $matches[1];
        }

        $rows = App::db()->select(
            'SELECT * FROM usage_records WHERE ' . implode(' AND ', $clauses) . ' ORDER BY id DESC LIMIT ' . ($limit + 1),
            $bindings
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);

        return [
            'records' => array_map(fn (array $row): array => $this->publicRecord($row), $rows),
            'next_cursor' => $hasMore && is_array($last) ? 'id_' . (int) $last['id'] : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function detail(array $apiKey, string $requestId): array
    {
        $row = App::db()->first('SELECT * FROM usage_records WHERE api_key_id = ? AND request_id = ?', [$apiKey['id'] ?? 0, $requestId]);
        if ($row === null) {
            abort_json('REQUEST_NOT_FOUND', 'Request not found', 404);
        }

        $tasks = App::db()->select(
            'SELECT id, task_id, task_type, status, error_code, error_message, latency_ms, created_at, updated_at FROM multimodal_tasks WHERE api_key_id = ? AND usage_record_id = ? ORDER BY id DESC LIMIT 10',
            [$apiKey['id'] ?? 0, $row['id']]
        );
        $taskIds = array_values(array_filter(array_map(static fn (array $task): int => (int) ($task['id'] ?? 0), $tasks)));
        $assetClauses = ['api_key_id = ?', '(deleted_at IS NULL OR deleted_at = 0)', '(usage_record_id = ?'];
        $assetBindings = [$apiKey['id'] ?? 0, $row['id']];
        if ($taskIds !== []) {
            $assetClauses[2] .= ' OR task_id IN (' . implode(',', array_fill(0, count($taskIds), '?')) . ')';
            foreach ($taskIds as $taskId) {
                $assetBindings[] = $taskId;
            }
        }
        $assetClauses[2] .= ')';
        $assets = App::db()->select(
            'SELECT asset_id, media_type, mime_type, size_bytes, expires_at, created_at FROM media_assets WHERE ' . implode(' AND ', $assetClauses) . ' ORDER BY id DESC LIMIT 20',
            $assetBindings
        );

        return [
            ...$this->publicRecord($row),
            'tasks' => array_map(static function (array $task): array {
                unset($task['id']);
                return $task;
            }, $tasks),
            'assets' => $assets,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRecord(array $row): array
    {
        return [
            'request_id' => (string) $row['request_id'],
            'trace_id' => (string) $row['trace_id'],
            'model_id' => $row['model_id'] === null ? null : (int) $row['model_id'],
            'model_name' => $row['model_name'] ?? null,
            'protocol' => (string) $row['protocol'],
            'endpoint' => (string) $row['endpoint'],
            'capability' => (string) $row['capability'],
            'status' => (string) $row['status'],
            'http_status' => $row['http_status'] === null ? null : (int) $row['http_status'],
            'error_code' => $row['error_code'] ?? null,
            'usage' => [
                'input_tokens' => (int) $row['input_tokens'],
                'output_tokens' => (int) $row['output_tokens'],
                'total_tokens' => (int) $row['total_tokens'],
                'cache_read_tokens' => (int) $row['cache_read_tokens'],
                'cache_write_tokens' => (int) $row['cache_write_tokens'],
                'audio_seconds' => (int) $row['audio_seconds'],
                'tts_chars' => (int) $row['tts_chars'],
                'image_count' => (int) $row['image_count'],
                'video_seconds' => (int) $row['video_seconds'],
                'cost_amount' => $row['cost_amount'] === null ? null : (float) $row['cost_amount'],
            ],
            'latency_ms' => $row['latency_ms'] === null ? null : (int) $row['latency_ms'],
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    private function timestamp(string $value): string
    {
        $time = strtotime($value);
        return $time === false ? $value : gmdate('Y-m-d H:i:s', $time);
    }
}
