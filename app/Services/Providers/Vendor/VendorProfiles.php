<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers\Vendor;

use TokenHub\Services\ProviderHeaderVault;

/**
 * Vendor profiles for non-OpenAI media APIs (MM-010 / MM-012 / MM-013 / MM-021).
 *
 * A provider uses a profile when its default_headers carry
 * {"tokenhub_adapter": "<profile id>"} or, failing that, when its base URL
 * host matches one of the profile's hosts. Capabilities a profile does not
 * list keep the protocol's default adapter (OpenAI-compatible chat, images,
 * ...), so one provider row can serve both.
 *
 * Per capability spec:
 *   mode         "async" (create -> poll) | "sync"
 *   mapper       VendorRequestMapper method building the upstream request
 *   task_id      dot path of the task id in the create response
 *   poll         ['method', 'path'] path template: {id}, {model}, {app}
 *   status       dot path of the task status; status_map vendor -> queued|processing|succeeded|failed
 *   results      dot paths (with *) of result URLs / base64 / hex
 *   result_kind  url | b64 | hex | text (sync) ; download_auth: result URLs need the channel credential
 *   result_fetch second request once the task succeeded (fal)
 *   error        dot paths of an error message ; error_when: path => value meaning "error"
 *   usage        canonical usage key => dot path (upstream_cost may carry a scale)
 *   callback     dot path where a TokenHub callback URL is put (optional)
 *
 * Field names follow the vendors' public API references as of 2026-10;
 * entries marked "unverified" in docs/deployment/vendor-adapters.md have not
 * been exercised against the real service.
 */
final class VendorProfiles
{
    private const VIDEO = 'video/mp4';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $taskStatus = ['queued' => 'queued', 'pending' => 'queued', 'submitted' => 'queued', 'created' => 'queued', 'queueing' => 'queued', 'in_queue' => 'queued',
            'running' => 'processing', 'processing' => 'processing', 'in_progress' => 'processing', 'starting' => 'processing', 'generating' => 'processing',
            'succeeded' => 'succeeded', 'succeed' => 'succeeded', 'success' => 'succeeded', 'completed' => 'succeeded', 'done' => 'succeeded', 'ready' => 'succeeded',
            'failed' => 'failed', 'fail' => 'failed', 'error' => 'failed', 'cancelled' => 'failed', 'canceled' => 'failed', 'expired' => 'failed', 'unknown' => 'failed'];

        return [
            'volcengine_ark' => [
                'label' => '火山方舟（Seedance / Seedream）',
                'hosts' => ['volces.com', 'byteplusapi.com'],
                // Video tasks keep the dedicated VolcengineArkAdapter.
            ],
            'kling' => [
                'label' => '可灵 Kling',
                'hosts' => ['klingai.com'],
                'auth' => ['type' => 'bearer_or_jwt'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'klingVideo', 'default_duration' => 5,
                    'task_id' => 'data.id|data.task_id',
                    'poll' => ['method' => 'GET', 'path' => '/tasks?task_ids={id}'],
                    'status' => 'data.0.status', 'status_map' => $taskStatus,
                    'results' => ['data.0.outputs.*.url'], 'mime' => self::VIDEO,
                    'error' => ['data.0.message', 'message'], 'error_when' => ['code' => 'nonzero'],
                    'callback' => 'options.callback_url',
                ],
                'image_generation' => [
                    'mode' => 'async', 'mapper' => 'klingImage',
                    'task_id' => 'data.task_id',
                    'poll' => ['method' => 'GET', 'path' => '/v1/images/generations/{id}'],
                    'status' => 'data.task_status', 'status_map' => $taskStatus,
                    'results' => ['data.task_result.images.*.url'], 'mime' => 'image/png',
                    'error' => ['data.task_status_msg', 'message'], 'error_when' => ['code' => 'nonzero'],
                    'callback' => 'callback_url',
                ],
            ],
            'minimax' => [
                'label' => 'MiniMax（海螺视频 / 生图 / 语音）',
                'hosts' => ['minimax.io', 'minimaxi.com', 'minimaxi.chat'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'minimaxVideo', 'default_duration' => 6,
                    'task_id' => 'task_id',
                    'poll' => ['method' => 'GET', 'path' => '/v2/query/video_generation/{id}'],
                    // Only queued tasks can be cancelled (not charged).
                    'cancel' => ['method' => 'DELETE', 'path' => '/v2/video_generation/{id}'],
                    'status' => 'task.status', 'status_map' => $taskStatus,
                    'results' => ['task.content.url'], 'mime' => self::VIDEO,
                    'error' => ['task.error.message', 'error.message', 'base_resp.status_msg'],
                    'usage' => ['video_seconds' => 'task.usage.output_seconds'],
                    'callback' => 'callback_url',
                ],
                'image_generation' => [
                    'mode' => 'sync', 'mapper' => 'minimaxImage',
                    'results' => ['data.image_urls.*', 'data.image_base64.*'], 'mime' => 'image/png',
                    'error' => ['base_resp.status_msg'], 'error_when' => ['base_resp.status_code' => 'nonzero'],
                ],
                'tts' => [
                    'mode' => 'sync', 'mapper' => 'minimaxTts', 'result_kind' => 'hex',
                    'results' => ['data.audio'],
                    'error' => ['base_resp.status_msg'], 'error_when' => ['base_resp.status_code' => 'nonzero'],
                    'usage' => ['tts_chars' => 'extra_info.usage_characters'],
                ],
            ],
            'dashscope' => [
                'label' => '阿里云百炼（万相 / CosyVoice / Qwen-ASR）',
                'hosts' => ['dashscope.aliyuncs.com', 'dashscope-intl.aliyuncs.com', 'maas.aliyuncs.com'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'wanVideo', 'default_duration' => 5,
                    'task_id' => 'output.task_id',
                    'poll' => ['method' => 'GET', 'path' => '/api/v1/tasks/{id}', 'root' => true],
                    'cancel' => ['method' => 'POST', 'path' => '/api/v1/tasks/{id}/cancel', 'root' => true],
                    'status' => 'output.task_status', 'status_map' => $taskStatus,
                    'results' => ['output.video_url'], 'mime' => self::VIDEO,
                    'error' => ['output.message', 'message'],
                    'usage' => ['video_seconds' => 'usage.output_video_duration|usage.duration'],
                ],
                'image_generation' => [
                    'mode' => 'async', 'mapper' => 'wanImage',
                    'task_id' => 'output.task_id',
                    'poll' => ['method' => 'GET', 'path' => '/api/v1/tasks/{id}', 'root' => true],
                    'status' => 'output.task_status', 'status_map' => $taskStatus,
                    'results' => ['output.choices.*.message.content.*.image', 'output.results.*.url'], 'mime' => 'image/png',
                    'error' => ['output.message', 'message'],
                    'usage' => ['image_count' => 'usage.image_count'],
                    'models' => '~^(wan|wanx)~i',
                ],
                'tts' => [
                    'mode' => 'sync', 'mapper' => 'cosyvoiceTts', 'result_kind' => 'url',
                    'results' => ['output.audio.url'], 'error' => ['message'],
                    'usage' => ['tts_chars' => 'usage.characters'],
                    'models' => '~^cosyvoice~i',
                ],
                'asr' => [
                    'mode' => 'sync', 'mapper' => 'qwenAsr', 'result_kind' => 'text',
                    'results' => ['choices.0.message.content'], 'error' => ['error.message', 'message'],
                    'usage' => ['audio_seconds' => 'usage.seconds'],
                    'models' => '~^qwen[0-9.]*-asr~i',
                ],
            ],
            'vidu' => [
                'label' => '生数 Vidu',
                'hosts' => ['api.vidu.com', 'api.vidu.cn'],
                'auth' => ['type' => 'header', 'name' => 'Authorization', 'prefix' => 'Token '],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'viduVideo', 'default_duration' => 5,
                    'task_id' => 'task_id',
                    'poll' => ['method' => 'GET', 'path' => '/ent/v2/tasks/{id}/creations'],
                    'status' => 'state', 'status_map' => $taskStatus,
                    'results' => ['creations.*.url'], 'mime' => self::VIDEO,
                    'error' => ['err_code', 'message'],
                    'callback' => 'callback_url',
                ],
            ],
            'zhipu' => [
                'label' => '智谱 CogVideoX',
                'hosts' => ['open.bigmodel.cn', 'api.z.ai'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'zhipuVideo', 'default_duration' => 5,
                    'task_id' => 'id',
                    'poll' => ['method' => 'GET', 'path' => '/v1/async-result/{id}'],
                    'status' => 'task_status', 'status_map' => $taskStatus,
                    'results' => ['video_result.*.url'], 'mime' => self::VIDEO,
                    'error' => ['error.message'],
                ],
            ],
            'xai' => [
                'label' => 'xAI Grok Imagine',
                'hosts' => ['api.x.ai'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'xaiVideo', 'default_duration' => 5,
                    'task_id' => 'request_id',
                    'poll' => ['method' => 'GET', 'path' => '/v1/videos/{id}'],
                    'status' => 'status', 'status_map' => $taskStatus,
                    'results' => ['video.url'], 'mime' => self::VIDEO,
                    'error' => ['error.message'],
                    'usage' => ['upstream_cost' => ['path' => 'usage.cost_in_usd_ticks', 'decimals' => 10]],
                ],
            ],
            'openrouter' => [
                'label' => 'OpenRouter（视频 / 生图）',
                'hosts' => ['openrouter.ai'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'openrouterVideo',
                    'task_id' => 'id',
                    'poll' => ['method' => 'GET', 'path' => '/v1/videos/{id}'],
                    'status' => 'status', 'status_map' => $taskStatus,
                    'results' => ['urls.*.url', 'urls.*'], 'mime' => self::VIDEO,
                    'download_fallback' => '/v1/videos/{id}/content?index={index}', 'download_count' => 'unsigned_urls',
                    'error' => ['error.message', 'error'],
                    'usage' => ['upstream_cost' => ['path' => 'usage.cost']],
                    'callback' => 'callback_url',
                ],
                'image_generation' => [
                    'mode' => 'sync', 'mapper' => 'openrouterImage',
                    'results' => ['data.*.b64_json', 'data.*.url'], 'mime' => 'image/png',
                    'usage' => ['upstream_cost' => ['path' => 'usage.cost']],
                    'error' => ['error.message'],
                ],
            ],
            'bfl' => [
                'label' => 'Black Forest Labs FLUX',
                'hosts' => ['bfl.ai'],
                'auth' => ['type' => 'header', 'name' => 'x-key'],
                'image_generation' => [
                    'mode' => 'async', 'mapper' => 'bflImage',
                    'task_id' => 'id',
                    'poll' => ['method' => 'GET', 'path' => '/v1/get_result?id={id}'],
                    'status' => 'status', 'status_map' => ['task not found' => 'failed', 'pending' => 'processing', 'reasoning' => 'processing', 'generating' => 'processing', 'ready' => 'succeeded', 'error' => 'failed', 'request moderated' => 'failed', 'content moderated' => 'failed', 'no card generated' => 'failed'],
                    'results' => ['result.sample'], 'mime' => 'image/jpeg',
                    'error' => ['detail.0.msg', 'status'],
                    // 1 credit = 0.01 USD
                    'usage' => ['upstream_cost' => ['path' => 'cost', 'scale' => '0.01']],
                ],
            ],
            'fal' => [
                'label' => 'fal.ai 队列',
                'hosts' => ['fal.run', 'fal.ai'],
                'auth' => ['type' => 'header', 'name' => 'Authorization', 'prefix' => 'Key '],
                'video_generation' => self::falSpec(self::VIDEO),
                'image_generation' => self::falSpec('image/png'),
                'music_generation' => self::falSpec('audio/mpeg'),
            ],
            'replicate' => [
                'label' => 'Replicate',
                'hosts' => ['api.replicate.com'],
                'video_generation' => self::replicateSpec(self::VIDEO),
                'image_generation' => self::replicateSpec('image/png'),
                'music_generation' => self::replicateSpec('audio/mpeg'),
            ],
            'runway' => [
                'label' => 'Runway',
                'hosts' => ['runwayml.com'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'runwayVideo', 'default_duration' => 5,
                    'task_id' => 'id',
                    'poll' => ['method' => 'GET', 'path' => '/v1/tasks/{id}', 'headers' => ['X-Runway-Version' => '2024-11-06']],
                    'status' => 'status', 'status_map' => [...$taskStatus, 'throttled' => 'queued'],
                    'results' => ['output.*'], 'mime' => self::VIDEO,
                    'error' => ['failure', 'error'],
                ],
            ],
            'luma' => [
                'label' => 'Luma Ray',
                'hosts' => ['lumalabs.ai'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'lumaVideo', 'default_duration' => 5,
                    'task_id' => 'id',
                    'poll' => ['method' => 'GET', 'path' => '/v1/generations/{id}'],
                    'status' => 'state', 'status_map' => $taskStatus,
                    'results' => ['output.*.url', 'assets.video'], 'mime' => self::VIDEO,
                    'error' => ['failure_reason'],
                ],
            ],
            'tencent_hunyuan' => [
                'label' => '腾讯混元生图（云 API 3.0）',
                'hosts' => ['hunyuan.tencentcloudapi.com'],
                'auth' => ['type' => 'tc3_hmac', 'service' => 'hunyuan', 'region' => 'ap-guangzhou', 'version' => '2023-09-01'],
                'image_generation' => [
                    'mode' => 'async', 'mapper' => 'hunyuanImage',
                    'task_id' => 'Response.JobId',
                    'poll' => ['method' => 'POST', 'path' => '/', 'headers' => ['X-TC-Action' => 'QueryHunyuanImageJob'], 'json' => ['JobId' => '{id}']],
                    'status' => 'Response.JobStatusCode', 'status_map' => ['1' => 'queued', '2' => 'processing', '4' => 'failed', '5' => 'succeeded'],
                    'results' => ['Response.ResultImage.*'], 'mime' => 'image/png',
                    'error' => ['Response.JobErrorMsg', 'Response.Error.Message'],
                ],
            ],
            'google_vertex' => [
                'label' => 'Google Vertex AI（Veo）',
                'hosts' => ['aiplatform.googleapis.com'],
                'auth' => ['type' => 'google_service_account'],
                'video_generation' => [
                    'mode' => 'async', 'mapper' => 'veoVertex', 'default_duration' => 8,
                    'task_id' => 'name',
                    'poll' => ['method' => 'POST', 'path' => '/{model}:fetchPredictOperation', 'json' => ['operationName' => '{id}']],
                    'status' => 'done', 'status_map' => ['1' => 'succeeded', 'true' => 'succeeded', '' => 'processing', '0' => 'processing', 'false' => 'processing'],
                    'results' => ['response.videos.*.bytesBase64Encoded', 'response.videos.*.gcsUri'], 'mime' => self::VIDEO,
                    'error' => ['error.message'],
                ],
            ],
            'deepgram' => [
                'label' => 'Deepgram 语音识别',
                'hosts' => ['api.deepgram.com'],
                'auth' => ['type' => 'header', 'name' => 'Authorization', 'prefix' => 'Token '],
                'asr' => ['mode' => 'sync', 'mapper' => 'deepgramListen', 'result_kind' => 'text',
                    'results' => ['results.channels.0.alternatives.0.transcript'], 'error' => ['err_msg', 'error'],
                    'usage' => ['audio_seconds' => 'metadata.duration']],
            ],
            'ideogram' => [
                'label' => 'Ideogram',
                'hosts' => ['api.ideogram.ai'],
                'auth' => ['type' => 'header', 'name' => 'Api-Key'],
                'image_generation' => ['mode' => 'sync', 'mapper' => 'ideogramImage', 'results' => ['data.*.url'], 'mime' => 'image/png', 'error' => ['detail', 'failure_reason'],
                    'usage' => ['upstream_cost' => ['path' => 'usage_cost_usd_micros', 'decimals' => 6]]],
            ],
            'stability' => [
                'label' => 'Stability AI',
                'hosts' => ['api.stability.ai'],
                'image_generation' => ['mode' => 'sync', 'mapper' => 'stabilityImage', 'results' => ['image'], 'mime' => 'image/png', 'error' => ['errors.0', 'name']],
            ],
            'elevenlabs' => [
                'label' => 'ElevenLabs（语音合成 / 识别 / 音乐）',
                'hosts' => ['api.elevenlabs.io'],
                'auth' => ['type' => 'header', 'name' => 'xi-api-key'],
                'tts' => ['mode' => 'sync', 'mapper' => 'elevenlabsTts', 'result_kind' => 'binary', 'mime' => 'audio/mpeg', 'error' => ['detail.message', 'detail']],
                'asr' => ['mode' => 'sync', 'mapper' => 'elevenlabsStt', 'result_kind' => 'text', 'results' => ['text'], 'error' => ['detail.message', 'detail']],
                'music_generation' => ['mode' => 'sync', 'mapper' => 'elevenlabsMusic', 'result_kind' => 'binary', 'mime' => 'audio/mpeg', 'error' => ['detail.message', 'detail']],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>|null profile with its id under "id"
     */
    public static function forRoute(array $route): ?array
    {
        $profiles = self::all();
        $controls = (new ProviderHeaderVault())->decode(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
        $explicit = strtolower(trim((string) ($controls['tokenhub_adapter'] ?? '')));
        if ($explicit !== '') {
            return isset($profiles[$explicit]) ? ['id' => $explicit, ...$profiles[$explicit]] : null;
        }
        $host = strtolower((string) parse_url((string) ($route['base_url'] ?? ''), PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        foreach ($profiles as $id => $profile) {
            foreach ((array) ($profile['hosts'] ?? []) as $suffix) {
                // "us-central1-aiplatform.googleapis.com" style regional hosts too.
                if ($host === $suffix || str_ends_with($host, '.' . $suffix) || str_ends_with($host, '-' . $suffix)) {
                    return ['id' => $id, ...$profile];
                }
            }
        }

        return null;
    }

    /**
     * Capability spec for a route, honoring the optional "models" filter.
     *
     * @param array<string, mixed> $route
     * @return array<string, mixed>|null
     */
    public static function specFor(array $route, string $capability): ?array
    {
        $profile = self::forRoute($route);
        $spec = $profile[$capability] ?? null;
        if (!is_array($spec)) {
            return null;
        }
        $model = (string) (($route['external_name'] ?? '') !== '' ? $route['external_name'] : ($route['name'] ?? ''));
        if (isset($spec['models']) && preg_match((string) $spec['models'], $model) !== 1) {
            return null;
        }

        return [...$spec, 'profile' => $profile['id']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function falSpec(string $mime): array
    {
        return [
            'mode' => 'async', 'mapper' => 'falQueue',
            'task_id' => 'request_id',
            'poll' => ['method' => 'GET', 'path' => '/{app}/requests/{id}/status'],
            'status' => 'status', 'status_map' => ['in_queue' => 'queued', 'in_progress' => 'processing', 'completed' => 'succeeded'],
            'result_fetch' => ['method' => 'GET', 'path' => '/{app}/requests/{id}'],
            'results' => ['*media*'], 'mime' => $mime,
            'error' => ['error', 'detail.0.msg'],
            'callback' => '?fal_webhook',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function replicateSpec(string $mime): array
    {
        return [
            'mode' => 'async', 'mapper' => 'replicate',
            'task_id' => 'id',
            'poll' => ['method' => 'GET', 'path' => '/v1/predictions/{id}'],
            'status' => 'status', 'status_map' => ['starting' => 'queued', 'processing' => 'processing', 'succeeded' => 'succeeded', 'failed' => 'failed', 'canceled' => 'failed'],
            'results' => ['output', 'output.*'], 'mime' => $mime,
            'error' => ['error'],
            'callback' => 'webhook',
        ];
    }
}
