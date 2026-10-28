<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Middleware\McpAuthMiddleware;
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
                ...(McpAuthMiddleware::enabled() ? ['mcp'] : []),
            ],
            // Model Context Protocol server (Streamable HTTP, Bearer API key). See docs/mcp.md.
            'mcp' => [
                'enabled' => McpAuthMiddleware::enabled(),
                'endpoint' => '/mcp',
                'transport' => 'streamable-http',
                'tools' => ['search_knowledge_base', 'list_knowledge_bases', 'list_models', 'get_quota', 'get_usage_summary', 'get_api_key_info'],
            ],
            'model_endpoints' => [
                'text_generation' => '/v1/chat/completions',
                'text_completion' => '/v1/completions',
                'responses' => '/v1/responses',
                'anthropic_messages' => '/v1/messages',
                'anthropic_count_tokens' => '/v1/messages/count_tokens',
                'embedding' => '/v1/embeddings',
                'rerank' => '/v1/rerank',
                'moderation' => '/v1/moderations',
                'asr' => '/v1/audio/transcriptions',
                'asr_translation' => '/v1/audio/translations',
                'tts' => '/v1/audio/speech',
                'image_generation' => '/v1/images/generations',
                'image_edit' => '/v1/images/edits',
                'video_generation' => '/v1/videos/generations',
                'music_generation' => '/v1/music/generations',
                'realtime' => '/v1/realtime (WebSocket)',
                'realtime_client_secret' => '/v1/realtime/client_secrets',
                'gemini_generate_content' => '/v1beta/models/{model}:generateContent',
                'gemini_stream_generate_content' => '/v1beta/models/{model}:streamGenerateContent',
                'openai_videos' => '/v1/videos',
                'task_status' => '/v1/tasks/{id}',
                'task_refresh' => '/v1/tasks/{id}:refresh',
                'task_cancel' => '/v1/tasks/{id}:cancel',
                'asset_download_url' => '/v1/assets/{id}/download-url',
            ],
            'modalities' => [
                'text_generation' => ['input' => ['text'], 'output' => ['text']],
                'responses' => ['input' => ['text', 'image'], 'output' => ['text']],
                'anthropic_messages' => ['input' => ['text', 'image'], 'output' => ['text']],
                'vision_understanding' => ['input' => ['text', 'image'], 'output' => ['text']],
                'video_understanding' => ['input' => ['text', 'image', 'video'], 'output' => ['text']],
                'audio_understanding' => ['input' => ['text', 'audio'], 'output' => ['text']],
                'embedding' => ['input' => ['text'], 'output' => ['embedding']],
                'rerank' => ['input' => ['text'], 'output' => ['score']],
                'moderation' => ['input' => ['text', 'image'], 'output' => ['classification']],
                'asr' => ['input' => ['audio'], 'output' => ['text']],
                'tts' => ['input' => ['text'], 'output' => ['audio']],
                'image_generation' => ['input' => ['text'], 'output' => ['image']],
                'image_edit' => ['input' => ['text', 'image'], 'output' => ['image']],
                'video_generation' => ['input' => ['text', 'image'], 'output' => ['video']],
                'music_generation' => ['input' => ['text'], 'output' => ['audio']],
                'realtime' => ['input' => ['text', 'audio'], 'output' => ['text', 'audio']],
            ],
            'upload_modes' => [
                'json_base64_files' => true,
                // multipart/form-data: audio "file" for speech, "image"/"image[]" and "mask" for edits.
                'multipart' => true,
                'multipart_endpoints' => ['/v1/audio/transcriptions', '/v1/audio/translations', '/v1/images/edits'],
                'file_id_references' => true,
            ],
            'planned_capabilities' => [
                'image_variation',
                'video_edit',
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
            'model_protocols' => ['openai', 'anthropic'],
            'audio_speech_response' => 'binary audio by default; send X-TokenHub-Response-Mode: json for the legacy base64 JSON envelope',
            'audio_transcription_response' => 'JSON for json/verbose_json; the plain text body for text/srt/vtt (X-TokenHub-Response-Mode: json wraps it)',
            'image_response' => 'OpenAI data[] items carry b64_json, or url = short-lived TokenHub download link (GATEWAY_MEDIA_URL_TTL_SECONDS); upstream URLs are never exposed; assets[] lists the stored assets',
            'video_response' => 'asynchronous: 202 with task_id; poll GET /v1/tasks/{id} or POST /v1/tasks/{id}:refresh, cancel with POST /v1/tasks/{id}:cancel, then GET /v1/assets/{id}/download-url; /v1/videos offers the same as the OpenAI Videos API',
            'task_billing' => 'asynchronous tasks are charged at submission with the requested amount; failed, cancelled and expired tasks are refunded and successful ones settled to the usage the vendor measured (task.result.billing)',
            'unsupported' => ['responses_retrieval', 'responses_delete', 'responses_cancel', 'native_provider_execution'],
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
