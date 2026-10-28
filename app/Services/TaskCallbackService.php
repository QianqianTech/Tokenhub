<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Vendor task callbacks (MM-014).
 *
 * TokenHub hands vendors a per-task URL
 *   {APP_URL}/v1/task-callbacks/{task_id}/{signature}
 * signed with APP_KEY. A callback never carries trusted results: it only
 * proves "something changed" and makes TokenHub poll the vendor with its own
 * credential, so forged or replayed callbacks cannot inject media, and a
 * repeated callback is harmless (terminal tasks are not refreshed again; the
 * charge was made once at submission). The poller stays the fallback when a
 * callback never arrives.
 *
 * TASK_CALLBACKS_ENABLED (default true) only takes effect with an https APP_URL
 * that vendors can reach.
 */
final class TaskCallbackService
{
    public function urlFor(object $adapter, string $taskId, string $capability = 'video_generation'): ?string
    {
        if (!method_exists($adapter, 'supportsCallback') || $adapter->supportsCallback($capability) !== true) {
            return null;
        }
        if (!filter_var(env_value('TASK_CALLBACKS_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return null;
        }
        $base = rtrim((string) App::config()->get('app.url', ''), '/');
        if (!str_starts_with(strtolower($base), 'https://')) {
            return null;
        }

        return $base . '/v1/task-callbacks/' . rawurlencode($taskId) . '/' . $this->signature($taskId);
    }

    public function signature(string $taskId): string
    {
        $key = (string) (App::config()->get('app.key') ?: env_value('APP_KEY', ''));

        return substr(hash_hmac('sha256', 'task-callback|' . $taskId, $key), 0, 40);
    }

    public function verify(string $taskId, string $signature): bool
    {
        return $taskId !== '' && $signature !== '' && hash_equals($this->signature($taskId), $signature);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $taskId, string $signature, array $body): array
    {
        if (!$this->verify($taskId, $signature)) {
            return ['status' => 404, 'body' => ['error' => ['code' => 'NOT_FOUND', 'message' => 'Unknown callback']]];
        }
        // MiniMax verifies a callback URL by expecting its challenge back.
        if (isset($body['challenge']) && is_scalar($body['challenge'])) {
            return ['status' => 200, 'body' => ['challenge' => (string) $body['challenge']]];
        }
        $task = App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$taskId]);
        if ($task === null) {
            return ['status' => 404, 'body' => ['error' => ['code' => 'NOT_FOUND', 'message' => 'Unknown callback']]];
        }
        try {
            $fresh = App::redis()->connection()->set('task_callback:' . $taskId, '1', ['nx', 'ex' => 3]);
        } catch (\Throwable) {
            $fresh = true;
        }
        if ($fresh === false) {
            return ['status' => 200, 'body' => ['ok' => true, 'deduplicated' => true]];
        }
        $outcome = (new GatewayService())->refreshTaskRow($task);

        return ['status' => 200, 'body' => ['ok' => true, 'refreshed' => (bool) ($outcome['refreshed'] ?? false)]];
    }
}
