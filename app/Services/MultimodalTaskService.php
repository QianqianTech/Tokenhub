<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\SchemaInspector;

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
            'UPDATE multimodal_tasks SET status = "succeeded", progress = 100, provider_task_id = COALESCE(?, provider_task_id), result = ?, latency_ms = COALESCE(?, latency_ms), finished_at = UTC_TIMESTAMP() WHERE task_id = ?',
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
            'UPDATE multimodal_tasks SET status = "failed", error_code = ?, error_message = ?, latency_ms = COALESCE(?, latency_ms), finished_at = UTC_TIMESTAMP() WHERE task_id = ?',
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
            'UPDATE multimodal_tasks SET status = "failed", error_code = "TASK_EXECUTOR_MISSING", error_message = "No execution backend picked up the task", finished_at = UTC_TIMESTAMP()
             WHERE status IN ("queued","processing") AND (provider_task_id IS NULL OR provider_task_id = "") AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)',
            [$staleMinutes]
        );
        // BIZ-020: tasks that exist at the vendor are cancelled there too, or
        // the vendor keeps running (and billing) a task the user was refunded for.
        $withUpstream = App::db()->select(
            'SELECT task_id FROM multimodal_tasks WHERE status IN ("queued","processing") AND provider_task_id IS NOT NULL AND provider_task_id <> "" AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR) LIMIT 500',
            [$maxHours]
        );
        $timedOut = App::db()->execute(
            'UPDATE multimodal_tasks SET status = "failed", error_code = "TASK_TIMEOUT", error_message = "Task did not finish within the maximum age", finished_at = UTC_TIMESTAMP()
             WHERE status IN ("queued","processing") AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)',
            [$maxHours]
        );
        foreach ($withUpstream as $row) {
            try {
                App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), ['type' => 'tasks.cancel', 'task_id' => (string) $row['task_id']]);
            } catch (\Throwable $throwable) {
                App::logger()->warning('worker', 'task cancel not queued', ['task_id' => $row['task_id'], 'error' => $throwable->getMessage()]);
            }
        }

        return ['executor_missing' => $missing, 'timed_out' => $timedOut];
    }

    /**
     * BIZ-020: asks the vendor to cancel a task TokenHub gave up on (timeout)
     * when the vendor supports cancelling, and records what the vendor said:
     * upstream_final_status (status seen by a last poll) and
     * upstream_cancel_status (cancelled / refused / unsupported / error).
     * A task the vendor finished anyway raises "task_billed_after_timeout".
     *
     * @return array{task_id: string, cancel: string, upstream_status: ?string}
     */
    public function cancelUpstream(string $taskId, ?callable $send = null): array
    {
        $task = App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$taskId]);
        $providerTaskId = (string) ($task['provider_task_id'] ?? '');
        if ($task === null || $providerTaskId === '' || empty($task['model_id'])) {
            return ['task_id' => $taskId, 'cancel' => 'skipped', 'upstream_status' => null];
        }
        $route = App::db()->first(
            'SELECT m.*, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m JOIN providers p ON p.id = m.provider_id WHERE m.id = ? LIMIT 1',
            [(int) $task['model_id']]
        );
        if ($route === null) {
            return $this->recordUpstreamOutcome($task, 'unsupported', null);
        }
        $capability = (string) ($task['task_type'] ?? 'video_generation');
        $adapter = self::cancellableAdapter($route, $capability);
        $send ??= static fn (string $endpoint, array $route, array $options): array => (new ProviderHttpClient())->send($endpoint, $route, $options);

        $upstreamStatus = null;
        if ($adapter instanceof \TokenHub\Services\Providers\AsyncTaskAdapter) {
            try {
                $result = json_decode((string) ($task['result'] ?? ''), true);
                $poll = $adapter->pollRequest($route, $capability, $providerTaskId, is_array($result) ? $result : []);
                if (is_array($poll) && isset($poll['endpoint'])) {
                    $pollOptions = ['method' => (string) ($poll['method'] ?? 'GET'), 'headers' => (array) ($poll['headers'] ?? ['Accept' => 'application/json'])];
                    if (isset($poll['json'])) {
                        $pollOptions['json'] = $poll['json'];
                    }
                    $response = $send((string) $poll['endpoint'], $route, $pollOptions);
                    $json = is_array($response['json'] ?? null) ? $response['json'] : (json_decode((string) ($response['body'] ?? ''), true) ?: []);
                    $raw = $json['status'] ?? $json['data']['status'] ?? $json['task_status'] ?? $json['output']['task_status'] ?? null;
                    $upstreamStatus = is_scalar($raw) ? substr(strtolower((string) $raw), 0, 40) : null;
                }
            } catch (\Throwable) {
            }
        }
        if ($upstreamStatus !== null && in_array($upstreamStatus, ['succeeded', 'success', 'completed', 'done', 'finished'], true)) {
            (new AlertService())->record('task_billed_after_timeout', 'warning', 'A timed-out task finished at the vendor after it was refunded', ['name' => $taskId, 'provider_id' => $task['provider_id'] ?? null, 'upstream_status' => $upstreamStatus]);
            return $this->recordUpstreamOutcome($task, 'not_needed', $upstreamStatus);
        }
        $cancel = $adapter !== null && method_exists($adapter, 'cancelRequest') ? $adapter->cancelRequest($route, $capability, $providerTaskId) : null;
        if (!is_array($cancel) || !isset($cancel['endpoint'])) {
            return $this->recordUpstreamOutcome($task, 'unsupported', $upstreamStatus);
        }
        try {
            $options = ['method' => (string) ($cancel['method'] ?? 'DELETE'), 'headers' => (array) ($cancel['headers'] ?? ['Accept' => 'application/json'])];
            if (isset($cancel['json'])) {
                $options['json'] = $cancel['json'];
            }
            $response = $send((string) $cancel['endpoint'], $route, $options);
            $status = (int) ($response['status'] ?? 0);
            $outcome = $status >= 200 && $status < 300 ? 'cancelled' : ($status === 0 ? 'error' : 'refused_' . $status);
        } catch (\Throwable) {
            $outcome = 'error';
        }

        return $this->recordUpstreamOutcome($task, $outcome, $upstreamStatus);
    }

    /**
     * Adapter that can cancel / poll a task of this route (vendor profiles and
     * Volcengine Ark), or null.
     *
     * @param array<string, mixed> $route
     */
    public static function cancellableAdapter(array $route, string $capability): ?object
    {
        $spec = \TokenHub\Services\Providers\Vendor\VendorProfiles::specFor($route, $capability);
        if ($spec !== null) {
            return new \TokenHub\Services\Providers\Vendor\VendorAdapter($spec);
        }
        if (ModelSupportPolicy::isVolcengineArkRoute($route)) {
            return new \TokenHub\Services\Providers\VolcengineArkAdapter();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $task
     * @return array{task_id: string, cancel: string, upstream_status: ?string}
     */
    private function recordUpstreamOutcome(array $task, string $cancel, ?string $upstreamStatus): array
    {
        if (SchemaInspector::hasColumn('multimodal_tasks', 'upstream_cancel_status')) {
            App::db()->execute('UPDATE multimodal_tasks SET upstream_cancel_status = ?, upstream_final_status = ? WHERE id = ?', [$cancel, $upstreamStatus, (int) $task['id']]);
        }
        App::logger()->info('worker', 'task upstream cancel', ['task_id' => $task['task_id'] ?? null, 'cancel' => $cancel, 'upstream_status' => $upstreamStatus]);

        return ['task_id' => (string) ($task['task_id'] ?? ''), 'cancel' => $cancel, 'upstream_status' => $upstreamStatus];
    }

    /**
     * Polls upstream for processing tasks (worker job "tasks.poll").
     *
     * BIZ-021: every polled task gets a next_poll_at (15 s after a successful
     * poll, growing to 10 min after repeated failures), so a task whose poll
     * keeps failing no longer stays at the head of the queue. The batch grows
     * with the backlog (up to TASK_POLL_MAX_BATCH, default 200) and stops
     * after TASK_POLL_TIME_BUDGET_SECONDS (default 45).
     */
    public function pollProcessing(int $limit = 20, ?callable $refresh = null): int
    {
        $scheduled = SchemaInspector::hasColumn('multimodal_tasks', 'next_poll_at');
        $maxBatch = max(1, (int) env_value('TASK_POLL_MAX_BATCH', 200));
        $budget = max(5, (int) env_value('TASK_POLL_TIME_BUDGET_SECONDS', 45));
        $dueCondition = $scheduled
            ? 'status = "processing" AND provider_task_id IS NOT NULL AND provider_task_id <> "" AND (next_poll_at IS NULL OR next_poll_at <= UTC_TIMESTAMP()) AND updated_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 SECOND)'
            : 'status = "processing" AND provider_task_id IS NOT NULL AND provider_task_id <> "" AND updated_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 SECOND)';
        $due = (int) (App::db()->first('SELECT COUNT(*) AS c FROM multimodal_tasks WHERE ' . $dueCondition)['c'] ?? 0);
        $batch = max(1, min($maxBatch, max($limit, $due)));
        $rows = App::db()->select(
            'SELECT * FROM multimodal_tasks WHERE ' . $dueCondition . ' ORDER BY ' . ($scheduled ? 'COALESCE(next_poll_at, updated_at)' : 'updated_at') . ' ASC LIMIT ' . $batch
        );
        $refresh ??= static fn (array $row): array => (new GatewayService())->refreshTaskRow($row);
        $started = microtime(true);
        $refreshed = 0;
        foreach ($rows as $row) {
            if (microtime(true) - $started > $budget) {
                break;
            }
            $ok = false;
            try {
                $ok = (($refresh($row)['refreshed'] ?? false) === true);
            } catch (\Throwable $throwable) {
                App::logger()->warning('worker', 'task poll failed', ['task_id' => $row['task_id'] ?? null, 'error' => $throwable->getMessage()]);
            }
            if ($ok) {
                $refreshed++;
            }
            $this->schedulePoll($row, $ok, $scheduled);
        }

        return $refreshed;
    }

    /**
     * Seconds until the next poll: 15 s after success; 30 s doubling per
     * consecutive failure, capped at 10 minutes.
     */
    public static function pollDelaySeconds(bool $succeeded, int $failures): int
    {
        if ($succeeded) {
            return 15;
        }

        return (int) min(600, 30 * (2 ** max(0, min(10, $failures - 1))));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function schedulePoll(array $row, bool $succeeded, bool $scheduled): void
    {
        try {
            if ($scheduled) {
                $failures = $succeeded ? 0 : (int) ($row['poll_failures'] ?? 0) + 1;
                $hasFailures = SchemaInspector::hasColumn('multimodal_tasks', 'poll_failures');
                App::db()->execute(
                    'UPDATE multimodal_tasks SET next_poll_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)' . ($hasFailures ? ', poll_failures = ?' : '') . ' WHERE id = ? AND status = "processing"',
                    $hasFailures ? [self::pollDelaySeconds($succeeded, $failures), $failures, (int) $row['id']] : [self::pollDelaySeconds($succeeded, $failures), (int) $row['id']]
                );
            } elseif (!$succeeded) {
                // Pre-migration: move the task to the back of the queue.
                App::db()->execute('UPDATE multimodal_tasks SET updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = "processing"', [(int) $row['id']]);
            }
        } catch (\Throwable) {
        }
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
