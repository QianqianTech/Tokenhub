<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class UsageService
{
    /**
     * @param array<string, mixed> $record
     */
    public function write(array $record): void
    {
        if (!empty($record['request_id'])) {
            $existing = App::db()->first('SELECT id FROM usage_records WHERE request_id = ?', [$record['request_id']]);
            if ($existing !== null) {
                return;
            }
        }

        $modelId = isset($record['model_id']) ? (int) $record['model_id'] : 0;
        $priced = $modelId > 0
            ? (new PriceService())->priceUsage($modelId, $record, $record['created_at'] ?? null)
            : ['cost_amount' => null, 'price_snapshot' => null];

        $record['cost_amount'] = $priced['cost_amount'];
        $record['price_snapshot'] = $priced['price_snapshot'] === null
            ? null
            : json_encode($priced['price_snapshot'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        App::db()->execute(
            'INSERT INTO usage_records (request_id, trace_id, user_id, department_id, api_key_id, provider_id, model_id, model_name, protocol, endpoint, capability, status, http_status, error_code, input_tokens, output_tokens, total_tokens, cache_read_tokens, cache_write_tokens, audio_seconds, tts_chars, image_count, video_seconds, cost_amount, price_snapshot, latency_ms, client_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $record['request_id'],
                $record['trace_id'],
                $record['user_id'] ?? null,
                $record['department_id'] ?? null,
                $record['api_key_id'] ?? null,
                $record['provider_id'] ?? null,
                $record['model_id'] ?? null,
                $record['model_name'] ?? null,
                $record['protocol'],
                $record['endpoint'],
                $record['capability'] ?? 'text_generation',
                $record['status'],
                $record['http_status'] ?? null,
                $record['error_code'] ?? null,
                (int) ($record['input_tokens'] ?? 0),
                (int) ($record['output_tokens'] ?? 0),
                (int) ($record['total_tokens'] ?? 0),
                (int) ($record['cache_read_tokens'] ?? 0),
                (int) ($record['cache_write_tokens'] ?? 0),
                (int) ($record['audio_seconds'] ?? 0),
                (int) ($record['tts_chars'] ?? 0),
                (int) ($record['image_count'] ?? 0),
                (int) ($record['video_seconds'] ?? 0),
                $record['cost_amount'],
                $record['price_snapshot'],
                $record['latency_ms'] ?? null,
                $record['client_ip'] ?? null,
            ]
        );

        $usageRecord = App::db()->first('SELECT id FROM usage_records WHERE request_id = ?', [$record['request_id']]);
        if ($usageRecord && !empty($record['task_id'])) {
            App::db()->execute('UPDATE multimodal_tasks SET usage_record_id = ? WHERE task_id = ?', [(int) $usageRecord['id'], (string) $record['task_id']]);
            App::db()->execute(
                'UPDATE media_assets a JOIN multimodal_tasks t ON t.id = a.task_id SET a.usage_record_id = ? WHERE t.task_id = ?',
                [(int) $usageRecord['id'], (string) $record['task_id']]
            );
        }
        if ($usageRecord && isset($record['content']) && is_array($record['content'])) {
            (new ContentRetentionService())->writeForRecord((int) $usageRecord['id'], $record);
        }

        $this->aggregate($record);
        $this->emitExternalEvents($record, $usageRecord);
        $logRecord = $record;
        unset($logRecord['content']);
        App::logger()->info('usage', 'usage record written', $logRecord);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function aggregate(array $record): void
    {
        $createdAt = strtotime((string) ($record['created_at'] ?? 'now')) ?: time();
        $latencyBuckets = $this->latencyBuckets((int) ($record['latency_ms'] ?? 0));
        foreach ([
            'usage_aggregates_minute' => gmdate('Y-m-d H:i:00', $createdAt),
            'usage_aggregates_hour' => gmdate('Y-m-d H:00:00', $createdAt),
            'usage_aggregates_day' => gmdate('Y-m-d 00:00:00', $createdAt),
        ] as $table => $bucket) {
            App::db()->execute(
                "INSERT INTO {$table} (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, total_cost, latency_ms_sum, latency_bucket_100, latency_bucket_300, latency_bucket_1000, latency_bucket_3000, latency_bucket_over_3000) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE request_count = request_count + 1, success_count = success_count + VALUES(success_count), failed_count = failed_count + VALUES(failed_count), input_tokens = input_tokens + VALUES(input_tokens), output_tokens = output_tokens + VALUES(output_tokens), cache_read_tokens = cache_read_tokens + VALUES(cache_read_tokens), cache_write_tokens = cache_write_tokens + VALUES(cache_write_tokens), total_cost = IF(total_cost IS NULL, VALUES(total_cost), total_cost + IFNULL(VALUES(total_cost), 0)), latency_ms_sum = latency_ms_sum + VALUES(latency_ms_sum), latency_bucket_100 = latency_bucket_100 + VALUES(latency_bucket_100), latency_bucket_300 = latency_bucket_300 + VALUES(latency_bucket_300), latency_bucket_1000 = latency_bucket_1000 + VALUES(latency_bucket_1000), latency_bucket_3000 = latency_bucket_3000 + VALUES(latency_bucket_3000), latency_bucket_over_3000 = latency_bucket_over_3000 + VALUES(latency_bucket_over_3000)",
                [
                    $bucket,
                    $record['department_id'] ?? null,
                    $record['user_id'] ?? null,
                    $record['api_key_id'] ?? null,
                    $record['provider_id'] ?? null,
                    $record['model_id'] ?? null,
                    $record['capability'] ?? 'text_generation',
                    ($record['status'] ?? '') === 'success' ? 1 : 0,
                    ($record['status'] ?? '') === 'success' ? 0 : 1,
                    (int) ($record['input_tokens'] ?? 0),
                    (int) ($record['output_tokens'] ?? 0),
                    (int) ($record['cache_read_tokens'] ?? 0),
                    (int) ($record['cache_write_tokens'] ?? 0),
                    $record['cost_amount'],
                    (int) ($record['latency_ms'] ?? 0),
                    $latencyBuckets['latency_bucket_100'],
                    $latencyBuckets['latency_bucket_300'],
                    $latencyBuckets['latency_bucket_1000'],
                    $latencyBuckets['latency_bucket_3000'],
                    $latencyBuckets['latency_bucket_over_3000'],
                ]
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function latencyBuckets(int $latencyMs): array
    {
        return [
            'latency_bucket_100' => $latencyMs <= 100 ? 1 : 0,
            'latency_bucket_300' => $latencyMs > 100 && $latencyMs <= 300 ? 1 : 0,
            'latency_bucket_1000' => $latencyMs > 300 && $latencyMs <= 1000 ? 1 : 0,
            'latency_bucket_3000' => $latencyMs > 1000 && $latencyMs <= 3000 ? 1 : 0,
            'latency_bucket_over_3000' => $latencyMs > 3000 ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $usageRecord
     */
    private function emitExternalEvents(array $record, ?array $usageRecord): void
    {
        if (empty($record['api_key_id'])) {
            return;
        }

        $apiKey = [
            'id' => (int) $record['api_key_id'],
            'user_id' => $record['user_id'] ?? null,
            'department_id' => $record['department_id'] ?? null,
        ];
        $payload = [
            'request_id' => $record['request_id'] ?? null,
            'trace_id' => $record['trace_id'] ?? null,
            'usage_record_id' => $usageRecord['id'] ?? null,
            'status' => $record['status'] ?? null,
            'http_status' => $record['http_status'] ?? null,
            'error_code' => $record['error_code'] ?? null,
            'model_id' => $record['model_id'] ?? null,
            'model_name' => $record['model_name'] ?? null,
            'capability' => $record['capability'] ?? 'text_generation',
            'usage' => [
                'input_tokens' => (int) ($record['input_tokens'] ?? 0),
                'output_tokens' => (int) ($record['output_tokens'] ?? 0),
                'total_tokens' => (int) ($record['total_tokens'] ?? 0),
                'audio_seconds' => (int) ($record['audio_seconds'] ?? 0),
                'tts_chars' => (int) ($record['tts_chars'] ?? 0),
                'image_count' => (int) ($record['image_count'] ?? 0),
                'video_seconds' => (int) ($record['video_seconds'] ?? 0),
            ],
            'created_at' => gmdate('c'),
        ];

        $webhooks = new WebhookService();
        $webhooks->emit($apiKey, 'request.' . (string) ($record['status'] ?? 'unknown'), $payload);
        (new ExternalEventOutboxService())->append((int) $apiKey['id'], 'request.' . (string) ($record['status'] ?? 'unknown'), 'request', isset($record['request_id']) ? (string) $record['request_id'] : null, (string) ($record['status'] ?? 'unknown'), ['error_code' => $record['error_code'] ?? null, 'model_name' => $record['model_name'] ?? null]);

        if (!empty($record['task_id'])) {
            $task = App::db()->first(
                'SELECT task_id, task_type, status, error_code, error_message, progress, provider_task_id, created_at, finished_at FROM multimodal_tasks WHERE task_id = ?',
                [(string) $record['task_id']]
            );
            if ($task !== null) {
                $webhooks->emit($apiKey, 'task.' . (string) ($task['status'] ?? 'unknown'), [
                    ...$payload,
                    'task' => $task,
                ]);
            }
        }
    }
}
