<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class MultimodalTaskService
{
    private const TASK_CAPABILITIES = ['asr', 'tts', 'image_generation', 'video_generation'];

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
        if ($apiKey === null) {
            return false;
        }

        if (($task['api_key_id'] ?? null) !== null && (int) $task['api_key_id'] === (int) ($apiKey['id'] ?? 0)) {
            return true;
        }

        if (($task['user_id'] ?? null) !== null && (int) $task['user_id'] === (int) ($apiKey['user_id'] ?? 0)) {
            return true;
        }

        if (($task['department_id'] ?? null) !== null && (int) $task['department_id'] === (int) ($apiKey['department_id'] ?? 0)) {
            return true;
        }

        return false;
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
