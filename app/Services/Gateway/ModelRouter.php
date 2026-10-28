<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\App;

final class ModelRouter
{
    private const CACHE_TTL_SECONDS = 30;

    /**
     * @return array<string, mixed>|null
     */
    public function route(GatewayRequest $request): ?array
    {
        if ($request->model === '') {
            return null;
        }

        $cacheKey = $this->cacheKey($request);
        $cached = App::redis()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $query = $this->querySpec($request);

        $route = App::db()->first(
            $query['sql'],
            $query['params']
        );
        if ($route !== null) {
            App::redis()->set($cacheKey, $route, self::CACHE_TTL_SECONDS);
        }

        return $route;
    }

    public function cacheKey(GatewayRequest $request): string
    {
        return 'model_route:' . hash('sha256', implode('|', [
            $request->protocol,
            $request->capability,
            $request->model,
        ]));
    }

    /**
     * @return array{sql: string, params: list<string>}
     */
    public function querySpec(GatewayRequest $request): array
    {
        $allowNative = in_array($request->capability, ['asr', 'tts', 'image_generation', 'video_generation'], true);
        if ($allowNative) {
            $protocolSql = 'p.protocol IN (?, "native")';
        } elseif ($request->protocol === 'anthropic' && $request->capability === 'text_generation') {
            $protocolSql = '(p.protocol = ? OR (p.protocol = "openai" AND (LOWER(p.base_url) LIKE "%api.kimi.com/coding%" OR LOWER(m.external_name) = "kimi-for-coding" OR LOWER(m.name) = "kimi-for-coding" OR LOWER(m.name) LIKE "%kimi-code%" OR LOWER(m.name) LIKE "%kimi code%")))';
        } else {
            $protocolSql = 'p.protocol = ?';
        }

        return [
            'sql' => "SELECT m.*, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m
             JOIN providers p ON p.id = m.provider_id
             WHERE m.name = ?
               AND m.capability = ?
               AND m.status = \"active\"
               AND p.status = \"active\"
               AND {$protocolSql}
             ORDER BY CASE WHEN p.protocol = ? THEN 0 ELSE 1 END, m.route_priority ASC
             LIMIT 1",
            'params' => [$request->model, $request->capability, $request->protocol, $request->protocol],
        ];
    }
}
