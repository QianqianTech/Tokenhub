<?php

declare(strict_types=1);

namespace TokenHub\Services\Video;

use TokenHub\Services\GatewayService;
use TokenHub\Services\MultimodalTaskService;
use TokenHub\Support\App;

/**
 * OpenAI Videos API surface on top of TokenHub tasks, for clients built on
 * the OpenAI SDK (client.videos.create / retrieve / download_content /
 * delete), whatever vendor actually renders the video:
 *
 *   POST   /v1/videos                 same body as /v1/videos/generations (+ seconds, size)
 *   GET    /v1/videos/{id}            video object; refreshes unfinished tasks
 *   GET    /v1/videos/{id}/content    the MP4 (variant=thumbnail -> last frame image)
 *   DELETE /v1/videos/{id}            cancels an unfinished task, deletes a finished one's assets
 *
 * The video id is the TokenHub task id.
 */
final class OpenAIVideoApi
{
    public const CREATE_ENDPOINT = '/v1/videos';

    /**
     * OpenAI create body -> TokenHub video fields every vendor mapper reads:
     * seconds -> duration, size "1280x720" -> ratio "16:9" + resolution
     * "720p", input_reference (URL / data URI / {image_url}) -> image_url.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function canonicalPayload(array $payload): array
    {
        if (!isset($payload['duration']) && isset($payload['seconds']) && is_numeric($payload['seconds'])) {
            $payload['duration'] = (int) $payload['seconds'];
        }
        $size = $payload['size'] ?? null;
        if (is_string($size) && preg_match('/^(\d{2,5})\s*[x*×]\s*(\d{2,5})$/u', trim($size), $m) === 1) {
            [$width, $height] = [(int) $m[1], (int) $m[2]];
            $gcd = static function (int $a, int $b) use (&$gcd): int {
                return $b === 0 ? $a : $gcd($b, $a % $b);
            };
            $divisor = max(1, $gcd($width, $height));
            $payload['ratio'] ??= ($width / $divisor) . ':' . ($height / $divisor);
            $payload['resolution'] ??= min($width, $height) . 'p';
        }
        if (!isset($payload['image_url']) && isset($payload['input_reference'])) {
            $reference = CanonicalVideoRequest::media($payload['input_reference']);
            if ($reference !== null) {
                $payload['image_url'] = $reference;
            }
        }

        return $payload;
    }

    /**
     * Gateway submit answer (task view) -> OpenAI video object.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $payload original request body
     * @return array<string, mixed>
     */
    public function fromSubmission(array $submitted, array $payload): array
    {
        $video = CanonicalVideoRequest::fromPayload($payload);

        return $this->object([
            'task_id' => $submitted['task_id'] ?? null,
            'status' => $submitted['status'] ?? 'queued',
            'progress' => $submitted['progress'] ?? null,
        ], (string) ($payload['model'] ?? ''), $video->duration, $video->size);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        $gateway = new GatewayService();
        $task = $gateway->task($id);
        if (($task['task_type'] ?? '') !== 'video_generation') {
            abort_json('CONTENT_FORBIDDEN', 'Video not found', 404);
        }
        if (in_array((string) ($task['status'] ?? ''), ['queued', 'processing'], true)) {
            $row = App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$id]);
            if ($row !== null) {
                $gateway->refreshTaskRow($row);
                $task = $gateway->task($id);
            }
        }

        $submitted = App::db()->first('SELECT submit_payload FROM multimodal_tasks WHERE task_id = ?', [$id]);
        $payload = json_decode((string) ($submitted['submit_payload'] ?? ''), true);
        $video = CanonicalVideoRequest::fromPayload(is_array($payload) ? $payload : []);

        return $this->object($task, $this->model($id), $video->duration, $video->size);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        $gateway = new GatewayService();
        $task = $gateway->task($id);
        if (in_array((string) ($task['status'] ?? ''), ['queued', 'processing'], true)) {
            $gateway->cancelTask($id);
        } else {
            $row = App::db()->first('SELECT id FROM multimodal_tasks WHERE task_id = ?', [$id]);
            App::db()->execute('UPDATE media_assets SET deleted_at = ? WHERE task_id = ? AND deleted_at IS NULL', [gmdate('Y-m-d H:i:s'), (int) ($row['id'] ?? 0)]);
        }

        return ['id' => $id, 'object' => 'video.deleted', 'deleted' => true];
    }

    /**
     * Asset to serve for GET /v1/videos/{id}/content.
     *
     * @return array<string, mixed>
     */
    public function contentAsset(string $id, string $variant): array
    {
        $task = (new GatewayService())->task($id);
        if (($task['status'] ?? '') !== 'succeeded') {
            abort_json('VIDEO_NOT_READY', 'Video is not completed yet', 409);
        }
        $row = App::db()->first('SELECT id FROM multimodal_tasks WHERE task_id = ?', [$id]);
        $wanted = $variant === 'thumbnail' ? 'image' : 'video';
        $asset = App::db()->first(
            'SELECT * FROM media_assets WHERE task_id = ? AND media_type = ? AND deleted_at IS NULL ORDER BY id ASC LIMIT 1',
            [(int) ($row['id'] ?? 0), $wanted]
        );
        if ($asset === null) {
            abort_json('CONTENT_FORBIDDEN', 'Requested video variant is not available', 404);
        }

        return $asset;
    }

    /**
     * @param array<string, mixed> $task public task view
     * @return array<string, mixed>
     */
    private function object(array $task, string $model, ?int $seconds, ?string $size): array
    {
        $status = match ((string) ($task['status'] ?? '')) {
            'queued' => 'queued',
            'succeeded' => 'completed',
            'failed', 'expired' => 'failed',
            default => 'in_progress',
        };
        $usage = $task['result']['billing']['final'] ?? $task['result']['settled_usage'] ?? $task['result']['provider_response']['usage'] ?? [];
        $metadata = $task['result']['provider_response']['metadata'] ?? [];
        // Measured (settled) length first, the requested one otherwise.
        $seconds = isset($usage['video_seconds']) && (int) $usage['video_seconds'] > 0 ? (int) $usage['video_seconds'] : $seconds;

        return array_filter([
            'id' => (string) ($task['task_id'] ?? ''),
            'object' => 'video',
            'model' => $model !== '' ? $model : null,
            'status' => $status,
            'progress' => $status === 'completed' ? 100 : (int) ($task['progress'] ?? 0),
            'created_at' => isset($task['created_at']) ? (strtotime((string) $task['created_at']) ?: time()) : time(),
            'completed_at' => $status === 'completed' && isset($task['finished_at']) ? (strtotime((string) $task['finished_at']) ?: null) : null,
            'seconds' => $seconds !== null ? (string) $seconds : null,
            'size' => $size ?? ($metadata['size'] ?? null),
            'error' => $status === 'failed' ? ['code' => (string) ($task['error_code'] ?? 'PROVIDER_TASK_FAILED'), 'message' => (string) ($task['error_message'] ?? 'Video generation failed')] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function model(string $taskId): string
    {
        $row = App::db()->first('SELECT m.name FROM multimodal_tasks t JOIN models m ON m.id = t.model_id WHERE t.task_id = ?', [$taskId]);

        return (string) ($row['name'] ?? '');
    }
}
