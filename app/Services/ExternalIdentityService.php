<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ExternalIdentityService
{
    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function me(array $apiKey): array
    {
        $row = App::db()->first(
            'SELECT k.id, k.user_id, k.department_id, k.name, k.key_prefix, k.key_suffix, k.allowed_ip_cidrs, k.status, k.expires_at, k.last_used_at, k.created_at,
                    u.username, u.role, u.status AS user_status,
                    d.name AS department_name, d.status AS department_status, d.path AS department_path
             FROM api_keys k
             JOIN users u ON u.id = k.user_id
             JOIN departments d ON d.id = k.department_id
             WHERE k.id = ?',
            [$apiKey['id'] ?? 0]
        ) ?? $apiKey;

        return [
            'api_key' => [
                'id' => (int) ($row['id'] ?? $apiKey['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'display' => (string) ($row['key_prefix'] ?? '') . '...' . (string) ($row['key_suffix'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'expires_at' => $row['expires_at'] ?? null,
                'last_used_at' => $row['last_used_at'] ?? null,
                'allowed_ip_cidrs' => $this->jsonList($row['allowed_ip_cidrs'] ?? null),
                'created_at' => $row['created_at'] ?? null,
            ],
            'user' => [
                'id' => (int) ($row['user_id'] ?? $apiKey['user_id'] ?? 0),
                'username' => (string) ($row['username'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'status' => (string) ($row['user_status'] ?? ''),
            ],
            'department' => [
                'id' => (int) ($row['department_id'] ?? $apiKey['department_id'] ?? 0),
                'name' => (string) ($row['department_name'] ?? ''),
                'status' => (string) ($row['department_status'] ?? ''),
                'path' => $row['department_path'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return [
            'service' => 'tokenhub',
            'version' => (string) App::config()->get('app.version', '1.0.0'),
            'auth' => ['bearer_api_key' => true, 'user_password_login' => false],
            'enabled_capabilities' => [
                'model_proxy',
                'usage_records',
                'sync',
                'files',
                'events',
                'webhooks',
                'feedback',
            ],
            'model_endpoints' => [
                'text_generation' => '/v1/chat/completions',
                'responses' => '/v1/responses',
                'anthropic_messages' => '/v1/messages',
                'anthropic_count_tokens' => '/v1/messages/count_tokens',
                'embedding' => '/v1/embeddings',
                'asr' => '/v1/audio/transcriptions',
                'tts' => '/v1/audio/speech',
                'image_generation' => '/v1/images/generations',
                'video_generation' => '/v1/videos/generations',
                'task_status' => '/v1/tasks/{id}',
                'asset_download_url' => '/v1/assets/{id}/download-url',
            ],
            'modalities' => [
                'text_generation' => ['input' => ['text'], 'output' => ['text']],
                'responses' => ['input' => ['text', 'image'], 'output' => ['text']],
                'anthropic_messages' => ['input' => ['text', 'image'], 'output' => ['text']],
                'vision_understanding' => ['input' => ['text', 'image'], 'output' => ['text']],
                'embedding' => ['input' => ['text'], 'output' => ['embedding']],
                'asr' => ['input' => ['audio'], 'output' => ['text']],
                'tts' => ['input' => ['text'], 'output' => ['audio']],
                'image_generation' => ['input' => ['text'], 'output' => ['image']],
                'video_generation' => ['input' => ['text'], 'output' => ['video']],
            ],
            'upload_modes' => [
                'json_base64_files' => true,
                'multipart' => false,
                'file_id_references' => true,
            ],
            'planned_capabilities' => [
                'image_edit',
                'image_variation',
                'image_to_video',
                'video_understanding',
                'video_edit',
                'audio_understanding',
            ],
            'payload_limits' => [
                'sync_record_bytes' => 262144,
                'file_upload_bytes' => 10485760,
                'webhook_event_types' => 50,
            ],
            'batch_limits' => [
                'sync_records' => 200,
                'list_default' => 100,
                'list_max' => 500,
            ],
            'model_protocols' => ['openai', 'anthropic', 'native'],
        ];
    }

    /**
     * @return list<string>
     */
    private function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
