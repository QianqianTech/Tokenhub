<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\App;
use TokenHub\Support\ConfigVersion;
use TokenHub\Support\SchemaInspector;

/**
 * Resolves a logical model name to ordered provider channels.
 *
 * Authorization and routing use the SAME candidate set: when the API key
 * carries an explicit model-permission list, only those model rows (channel
 * level, not name level) are eligible. A model name shared by several
 * providers therefore can never route to a channel the key was not granted.
 *
 * Ordering: requested protocol first, then route_priority, then id; open
 * circuits (RouteHealth) are moved to the end. Candidates whose capability
 * contract explicitly lacks a required feature are dropped.
 */
final class ModelRouter
{
    private const CACHE_TTL_SECONDS = 30;
    private const MAX_CANDIDATES = 8;

    public function __construct(private readonly ?RouteHealth $health = null)
    {
    }

    /**
     * @param list<int>|null $allowedModelIds null = no channel restriction
     * @return array<string, mixed>|null
     */
    public function route(GatewayRequest $request, ?array $allowedModelIds = null): ?array
    {
        return $this->candidates($request, $allowedModelIds)[0] ?? null;
    }

    /**
     * @param list<int>|null $allowedModelIds
     * @return list<array<string, mixed>>
     */
    public function candidates(GatewayRequest $request, ?array $allowedModelIds = null): array
    {
        if ($request->model === '' || ($allowedModelIds !== null && $allowedModelIds === [])) {
            return [];
        }

        $cacheKey = $this->cacheKey($request, $allowedModelIds);
        $cached = App::redis()->get($cacheKey);
        if (is_array($cached) && array_is_list($cached)) {
            $rows = $cached;
        } elseif (is_array($cached) && isset($cached['id'])) {
            $rows = [$cached];
        } else {
            $query = $this->querySpec($request, $allowedModelIds, SchemaInspector::hasColumn('models', 'capability_tags'), self::MAX_CANDIDATES);
            $rows = App::db()->select($query['sql'], $query['params']);
            if ($rows !== []) {
                App::redis()->set($cacheKey, $rows, self::CACHE_TTL_SECONDS);
            }
        }

        $requirements = ModelCapabilityContract::requirements($request->payload, $request->stream, $request->capability);
        $eligible = [];
        foreach ($rows as $row) {
            if (ModelCapabilityContract::unmetForRoute($row, $requirements) === []) {
                $eligible[] = $row;
            }
        }

        return ($this->health ?? new RouteHealth())->order($eligible);
    }

    /**
     * Explains why no candidate was found so clients get an actionable error.
     *
     * @param list<int>|null $allowedModelIds
     * @return array{code: string, message: string, status: int}
     */
    public function diagnose(GatewayRequest $request, ?array $allowedModelIds = null): array
    {
        if ($request->model === '') {
            return ['code' => 'MODEL_REQUIRED', 'message' => 'model is required', 'status' => 400];
        }
        $rows = App::db()->select(
            'SELECT m.*, p.status AS provider_status, p.protocol FROM models m JOIN providers p ON p.id = m.provider_id WHERE m.name = ?',
            [$request->model]
        );
        if ($rows === []) {
            return ['code' => 'MODEL_NOT_FOUND', 'message' => 'Model is not configured', 'status' => 404];
        }
        if ($allowedModelIds !== null) {
            $allowed = array_flip($allowedModelIds);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($allowed[(int) $row['id']])));
            if ($rows === []) {
                return ['code' => 'MODEL_FORBIDDEN', 'message' => 'Model is not allowed for this key', 'status' => 403];
            }
        }
        $active = array_values(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === 'active' && ($row['provider_status'] ?? '') === 'active'));
        if ($active === []) {
            return ['code' => 'MODEL_UNAVAILABLE', 'message' => 'All channels for this model are disabled', 'status' => 503];
        }

        $requirements = ModelCapabilityContract::requirements($request->payload, $request->stream, $request->capability);
        $missing = null;
        foreach ($active as $row) {
            $unmet = ModelCapabilityContract::unmetForRoute($row, $requirements);
            $missing = $missing === null ? $unmet : array_values(array_intersect($missing, $unmet));
        }
        $inputs = array_values(array_map(static fn (string $item): string => substr($item, 6), array_filter($missing ?? [], static fn (string $item): bool => str_starts_with($item, 'input:'))));
        if ($inputs !== []) {
            return ['code' => 'MODEL_CAPABILITY_UNSUPPORTED', 'message' => 'Model does not accept ' . implode(' / ', $inputs) . ' input; choose a model configured for ' . implode(' / ', array_map(static fn (string $m): string => match ($m) {
                'image' => 'vision_understanding',
                'video' => 'video_understanding',
                'audio' => 'audio_understanding',
                default => $m,
            }, $inputs)), 'status' => 422];
        }

        return ['code' => 'MODEL_CAPABILITY_UNSUPPORTED', 'message' => 'No enabled channel supports this endpoint or request features', 'status' => 422];
    }

    /**
     * @param list<int>|null $allowedModelIds
     */
    public function cacheKey(GatewayRequest $request, ?array $allowedModelIds = null): string
    {
        $ids = $allowedModelIds;
        if ($ids !== null) {
            sort($ids);
        }

        return 'model_route:' . hash('sha256', implode('|', [
            $request->protocol,
            $request->capability,
            $request->model,
            $ids === null ? '*' : implode(',', $ids),
        ])) . ConfigVersion::cacheSuffix();
    }

    /**
     * @param list<int>|null $allowedModelIds
     * @return array{sql: string, params: list<string|int>}
     */
    public function querySpec(GatewayRequest $request, ?array $allowedModelIds = null, bool $withCapabilityTags = false, int $limit = 1): array
    {
        $allowNative = in_array($request->capability, ['asr', 'tts', 'image_generation', 'video_generation', 'music_generation'], true);
        if ($allowNative) {
            $protocolSql = 'p.protocol IN (?, "native")';
        } elseif ($request->protocol === 'anthropic' && $request->capability === 'text_generation') {
            $protocolSql = '(p.protocol = ? OR (p.protocol = "openai" AND (LOWER(p.base_url) LIKE "%api.kimi.com/coding%" OR LOWER(p.base_url) LIKE "%api.kimi.ai/coding%" OR LOWER(m.external_name) = "kimi-for-coding" OR LOWER(m.name) = "kimi-for-coding" OR LOWER(m.name) LIKE "%kimi-code%" OR LOWER(m.name) LIKE "%kimi code%")))';
        } else {
            $protocolSql = 'p.protocol = ?';
        }

        $params = [$request->model, $request->capability];
        $capabilitySql = 'm.capability = ?';
        // Chat models with extra input modalities (vision / video / audio
        // understanding) are one family: any of them may serve a chat request
        // and the per-row modality check in candidates() decides whether the
        // request's images, video or audio are accepted. Plain text therefore
        // reaches a vision model even when capability_tags was never filled.
        if (in_array($request->capability, InputModalities::TEXT_FAMILY, true)) {
            $capabilitySql = '(m.capability = ? OR m.capability IN ("text_generation", "vision_understanding", "video_understanding", "audio_understanding"))';
        }
        if ($withCapabilityTags) {
            $capabilitySql = '(' . $capabilitySql . ' OR JSON_CONTAINS(COALESCE(m.capability_tags, JSON_ARRAY()), JSON_QUOTE(?)))';
            $params[] = $request->capability;
        }
        $params[] = $request->protocol;

        $authorizationSql = '';
        if ($allowedModelIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $allowedModelIds)));
            $authorizationSql = ' AND m.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $params[] = $request->protocol;
        $limit = max(1, min(self::MAX_CANDIDATES, $limit));

        return [
            'sql' => "SELECT m.*, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m
             JOIN providers p ON p.id = m.provider_id
             WHERE m.name = ?
               AND {$capabilitySql}
               AND m.status = \"active\"
               AND p.status = \"active\"
               AND {$protocolSql}{$authorizationSql}
             ORDER BY CASE WHEN p.protocol = ? THEN 0 ELSE 1 END, m.route_priority ASC, m.id ASC
             LIMIT {$limit}",
            'params' => $params,
        ];
    }
}
