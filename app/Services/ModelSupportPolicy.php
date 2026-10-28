<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Providers\Vendor\VendorProfiles;
use TokenHub\Support\App;

/**
 * Whether the TokenHub gateway can actually serve a model through its
 * provider channel. The admin console only lists supported models (model
 * list, provider catalog import, catalog sync, model pickers); this class is
 * the single definition.
 *
 * Unsupported when:
 *  - the model has no provider row;
 *  - its capability has no gateway entry point;
 *  - the provider uses the "native" protocol (never routed: no executor);
 *  - the provider protocol cannot be reached for that capability: requests
 *    for embeddings / media / rerank / moderation arrive as OpenAI requests
 *    and only route to "openai" channels; Anthropic channels serve
 *    conversation models through /v1/messages; Gemini channels serve
 *    conversation models (generateContent) and realtime sessions;
 *  - video or music generation without a vendor adapter for the channel
 *    (VendorProfiles / Volcengine Ark): the task could not be tracked;
 *  - a realtime model configured as a chat/audio model: realtime models only
 *    work through /v1/realtime with capability "realtime".
 *
 * Rules mirror ModelRouter::querySpec, GatewayService::adapterFor /
 * usableCandidates and RealtimeService; update them together.
 */
final class ModelSupportPolicy
{
    /** Capabilities with a gateway entry point. */
    public const GATEWAY_CAPABILITIES = [
        'text_generation',
        'vision_understanding',
        'video_understanding',
        'audio_understanding',
        'embedding',
        'rerank',
        'moderation',
        'asr',
        'tts',
        'image_generation',
        'video_generation',
        'music_generation',
        'realtime',
    ];

    private const CONVERSATION_CAPABILITIES = ['text_generation', 'vision_understanding'];

    /** Capabilities each provider protocol can be reached for. */
    private const PROTOCOL_CAPABILITIES = [
        'openai' => self::GATEWAY_CAPABILITIES,
        'anthropic' => self::CONVERSATION_CAPABILITIES,
        // Gemini also takes video and audio parts (inline_data / file_data).
        'gemini' => [...self::CONVERSATION_CAPABILITIES, 'video_understanding', 'audio_understanding', 'realtime'],
    ];

    /** Task-based capabilities that need a vendor adapter to poll results. */
    private const TASK_CAPABILITIES = ['video_generation', 'music_generation'];

    private const ARK_MARKERS = ['ark.cn-', 'volces.com/api/v3', 'seedance', 'seedream'];

    private const MESSAGES = [
        'PROVIDER_MISSING' => '所属厂商不存在',
        'CAPABILITY_UNSUPPORTED' => '网关没有该能力的调用接口',
        'NATIVE_PROTOCOL' => 'native 协议渠道没有执行器，不会被路由',
        'PROTOCOL_CAPABILITY_MISMATCH' => '该厂商协议无法提供这种能力（Anthropic 渠道只能提供对话模型；Gemini 渠道只能提供对话和实时语音；其他能力需要 OpenAI 兼容渠道）',
        'TASK_ADAPTER_MISSING' => '该渠道没有视频/音乐任务适配器，无法查询生成结果',
        'REALTIME_MISCONFIGURED' => '实时语音模型只能通过 /v1/realtime 使用，请把能力设为 realtime',
        'PROTOCOL_UNKNOWN' => '未知的厂商协议',
    ];

    /** @var array<string, list<int>>|null */
    private static ?array $idCache = null;

    /**
     * @param array<string, mixed> $model name, external_name, capability
     * @param array<string, mixed>|null $provider protocol, base_url, default_headers
     * @return array{supported: bool, reason: ?string, message: ?string}
     */
    public function evaluate(array $model, ?array $provider): array
    {
        $capability = (string) ($model['capability'] ?? '');
        $protocol = (string) ($provider['protocol'] ?? '');
        $reason = match (true) {
            $provider === null => 'PROVIDER_MISSING',
            !in_array($capability, self::GATEWAY_CAPABILITIES, true) => 'CAPABILITY_UNSUPPORTED',
            $protocol === 'native' => 'NATIVE_PROTOCOL',
            !isset(self::PROTOCOL_CAPABILITIES[$protocol]) => 'PROTOCOL_UNKNOWN',
            !in_array($capability, self::PROTOCOL_CAPABILITIES[$protocol], true) => 'PROTOCOL_CAPABILITY_MISMATCH',
            in_array($capability, self::TASK_CAPABILITIES, true) && !$this->hasTaskAdapter($model, $provider, $capability) => 'TASK_ADAPTER_MISSING',
            $capability !== 'realtime' && self::looksRealtime($model) => 'REALTIME_MISCONFIGURED',
            default => null,
        };

        return ['supported' => $reason === null, 'reason' => $reason, 'message' => $reason === null ? null : self::MESSAGES[$reason]];
    }

    /**
     * Ids of supported (or unsupported) models, evaluated in PHP so the
     * definition stays in one place; used to filter paginated admin lists.
     *
     * @return list<int>
     */
    public function modelIds(bool $supported): array
    {
        if (self::$idCache === null) {
            $rows = App::db()->select(
                'SELECT m.id, m.name, m.external_name, m.capability, p.id AS provider_row_id, p.protocol, p.base_url, p.default_headers
                 FROM models m LEFT JOIN providers p ON p.id = m.provider_id'
            );
            $cache = ['supported' => [], 'unsupported' => []];
            foreach ($rows as $row) {
                $provider = $row['provider_row_id'] === null ? null : ['protocol' => $row['protocol'], 'base_url' => $row['base_url'], 'default_headers' => $row['default_headers']];
                $cache[$this->evaluate($row, $provider)['supported'] ? 'supported' : 'unsupported'][] = (int) $row['id'];
            }
            self::$idCache = $cache;
        }

        return self::$idCache[$supported ? 'supported' : 'unsupported'];
    }

    /** Drops the per-process id cache (tests, long-running workers). */
    public static function forget(): void
    {
        self::$idCache = null;
    }

    /**
     * Adds gateway_supported / unsupported_reason / unsupported_message to
     * model rows (admin lists). Provider rows are loaded in one query.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function annotate(array $rows): array
    {
        $providerIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['provider_id'] ?? 0), $rows))));
        $providers = [];
        if ($providerIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($providerIds), '?'));
            foreach (App::db()->select("SELECT id, protocol, base_url, default_headers FROM providers WHERE id IN ({$placeholders})", $providerIds) as $provider) {
                $providers[(int) $provider['id']] = $provider;
            }
        }

        return array_map(function (array $row) use ($providers): array {
            $support = $this->evaluate($row, $providers[(int) ($row['provider_id'] ?? 0)] ?? null);
            $row['gateway_supported'] = $support['supported'];
            $row['unsupported_reason'] = $support['reason'];
            $row['unsupported_message'] = $support['message'];

            return $row;
        }, $rows);
    }

    /**
     * Channel served by the dedicated Volcengine Ark video adapter (shared
     * with GatewayService::usesVolcengineArkAdapter).
     *
     * @param array<string, mixed> $route name, external_name, base_url, default_headers
     */
    public static function isVolcengineArkRoute(array $route): bool
    {
        $headers = $route['default_headers'] ?? null;
        if (is_string($headers) && $headers !== '') {
            $decoded = json_decode($headers, true);
            if (is_array($decoded) && (string) ($decoded['tokenhub_adapter'] ?? '') === 'volcengine_ark') {
                return true;
            }
        } elseif (is_array($headers) && (string) ($headers['tokenhub_adapter'] ?? '') === 'volcengine_ark') {
            return true;
        }

        foreach (['name', 'external_name', 'base_url'] as $field) {
            $value = strtolower(is_scalar($route[$field] ?? null) ? (string) $route[$field] : '');
            foreach (self::ARK_MARKERS as $marker) {
                if (str_contains($value, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $model
     * @param array<string, mixed> $provider
     */
    private function hasTaskAdapter(array $model, array $provider, string $capability): bool
    {
        $route = [
            'name' => $model['name'] ?? '',
            'external_name' => $model['external_name'] ?? '',
            'protocol' => $provider['protocol'] ?? 'openai',
            'base_url' => $provider['base_url'] ?? '',
            'default_headers' => $provider['default_headers'] ?? null,
        ];
        if (VendorProfiles::specFor($route, $capability) !== null) {
            return true;
        }

        return $capability === 'video_generation' && self::isVolcengineArkRoute($route);
    }

    /**
     * @param array<string, mixed> $model
     */
    private static function looksRealtime(array $model): bool
    {
        foreach (['name', 'external_name'] as $field) {
            if (str_contains(strtolower((string) ($model[$field] ?? '')), 'realtime')) {
                return true;
            }
        }

        return false;
    }
}
