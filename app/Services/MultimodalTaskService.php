<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class MultimodalTaskService
{
    private const TASK_CAPABILITIES = ['asr', 'tts', 'image_generation', 'video_generation', 'music_generation'];

    public function isTaskCapability(string $capability): bool
    {
        return in_array($capability, self::TASK_CAPABILITIES, true);
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    public function create(GatewayRequest $request, array $route, string $status = 'queued', ?int $progress = 0): array
    {
        $apiKey = App::context()->apiKey() ?? [];
        $taskId = Crypto::token('task_');
        App::db()->execute(
            'INSERT INTO multimodal_tasks (task_id, task_type, status, progress, user_id, department_id, api_key_id, provider_id, model_id, submit_payload, result) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $taskId,
                $request->capability,
                $status,
                $progress,
                $apiKey['user_id'] ?? null,
                $apiKey['department_id'] ?? null,
                $apiKey['id'] ?? null,
                $route['provider_id'] ?? null,
                $route['id'] ?? null,
                json_encode($this->sanitizeSubmitPayload($request->payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                '{}',
            ]
        );

        return App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$taskId]) ?? ['task_id' => $taskId];
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $result
     */
    public function complete(array $task, array $result, ?string $providerTaskId = null, ?int $latencyMs = null): void
    {
        App::db()->execute(
            'UPDATE multimodal_tasks SET status = "succeeded", progress = 100, provider_task_id = COALESCE(?, provider_task_id), result = ?, latency_ms = COALESCE(?, latency_ms), finished_at = NOW() WHERE task_id = ?',
            [
                $providerTaskId,
                json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $latencyMs,
                $task['task_id'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $result
     */
    public function markProcessing(array $task, array $result, ?string $providerTaskId = null, ?int $progress = null): void
    {
        App::db()->execute(
            'UPDATE multimodal_tasks SET status = "processing", progress = COALESCE(?, progress), provider_task_id = COALESCE(?, provider_task_id), result = ? WHERE task_id = ?',
            [
                $progress,
                $providerTaskId,
                json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $task['task_id'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $task
     */
    public function fail(array $task, string $errorCode, string $errorMessage, ?int $latencyMs = null): void
    {
        App::db()->execute(
            'UPDATE multimodal_tasks SET status = "failed", error_code = ?, error_message = ?, latency_ms = COALESCE(?, latency_ms), finished_at = NOW() WHERE task_id = ?',
            [$errorCode, substr($errorMessage, 0, 255), $latencyMs, $task['task_id']]
        );
    }

    /**
     * Terminal-state guarantee for asynchronous tasks.
     *
     * - queued/processing without an upstream task id after
     *   TASK_STALE_MINUTES (default 30): nothing will ever execute it ->
     *   failed / TASK_EXECUTOR_MISSING.
     * - any non-terminal task older than TASK_MAX_AGE_HOURS (default 24) ->
     *   failed / TASK_TIMEOUT.
     *
     * @return array{executor_missing: int, timed_out: int}
     */
    public function expireStale(): array
    {
        $staleMinutes = max(1, (int) env_value('TASK_STALE_MINUTES', 30));
        $maxHours = max(1, (int) env_value('TASK_MAX_AGE_HOURS', 24));
        $missing = App::db()->execute(
            'UPDATE multimodal_tasks SET status = "failed", error_code = "TASK_EXECUTOR_MISSING", error_message = "No execution backend picked up the task", finished_at = NOW()
             WHERE status IN ("queued","processing") AND (provider_task_id IS NULL OR provider_task_id = "") AND created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$staleMinutes]
        );
        $timedOut = App::db()->execute(
            'UPDATE multimodal_tasks SET status = "failed", error_code = "TASK_TIMEOUT", error_message = "Task did not finish within the maximum age", finished_at = NOW()
             WHERE status IN ("queued","processing") AND created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)',
            [$maxHours]
        );

        return ['executor_missing' => $missing, 'timed_out' => $timedOut];
    }

    /**
     * Polls upstream for processing tasks (worker job "tasks.poll").
     */
    public function pollProcessing(int $limit = 20): int
    {
        $rows = App::db()->select(
            'SELECT * FROM multimodal_tasks WHERE status = "processing" AND provider_task_id IS NOT NULL AND provider_task_id <> "" AND updated_at <= DATE_SUB(NOW(), INTERVAL 15 SECOND) ORDER BY updated_at ASC LIMIT ' . max(1, min(200, $limit))
        );
        $gateway = new GatewayService();
        $refreshed = 0;
        foreach ($rows as $row) {
            try {
                if (($gateway->refreshTaskRow($row)['refreshed'] ?? false) === true) {
                    $refreshed++;
                }
            } catch (\Throwable $throwable) {
                App::logger()->warning('worker', 'task poll failed', ['task_id' => $row['task_id'] ?? null, 'error' => $throwable->getMessage()]);
            }
        }

        return $refreshed;
    }

    /**
     * @param array<string, mixed>|null $apiKey
     * @return array<string, mixed>|null
     */
    public function findAuthorized(string $taskId, ?array $apiKey): ?array
    {
        $task = App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$taskId]);
        if (!$task || !$this->canAccess($task, $apiKey)) {
            return null;
        }

        $assets = App::db()->select(
            'SELECT asset_id, media_type, mime_type, size_bytes, duration_seconds, width, height, expires_at FROM media_assets WHERE task_id = ? ORDER BY id ASC',
            [$task['id']]
        );

        return $this->publicTask($task, $assets);
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed>|null $apiKey
     */
    public function canAccess(array $task, ?array $apiKey): bool
    {
        return (new AssetAccessPolicy())->allows($task, $apiKey);
    }

    /**
     * @param array<string, mixed> $task
     * @param list<array<string, mixed>> $assets
     * @return array<string, mixed>
     */
    public function publicTask(array $task, array $assets = []): array
    {
        $result = [];
        if (isset($task['result']) && is_string($task['result']) && $task['result'] !== '') {
            $decoded = json_decode($task['result'], true);
            $result = is_array($decoded) ? $decoded : [];
        } elseif (isset($task['result']) && is_array($task['result'])) {
            $result = $task['result'];
        }

        return [
            'task_id' => $task['task_id'] ?? null,
            'status' => $task['status'] ?? 'not_found',
            'task_type' => $task['task_type'] ?? null,
            'provider_task_id' => $task['provider_task_id'] ?? null,
            'progress' => isset($task['progress']) ? (int) $task['progress'] : null,
            'result' => $result,
            'assets' => array_map(static fn (array $asset): array => (new MediaAssetService())->publicAsset($asset), $assets),
            'error_code' => $task['error_code'] ?? null,
            'error_message' => $task['error_message'] ?? null,
            'created_at' => $task['created_at'] ?? null,
            'finished_at' => $task['finished_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitizeSubmitPayload(array $payload): array
    {
        return $this->sanitizeValue((new DesensitizationService())->redact($payload));
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), ['data', 'b64_json', 'image_url', 'audio_url', 'video_url'], true)) {
            return '[omitted]';
        }

        if (is_string($value) && strlen($value) > 240) {
            return substr($value, 0, 240) . '...';
        }

        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $childKey => $childValue) {
            $out[$childKey] = $this->sanitizeValue($childValue, (string) $childKey);
        }
        return $out;
    }
}
