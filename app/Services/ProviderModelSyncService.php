<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ProviderModelSyncService
{
    private const CAPABILITIES = [
        'text_generation',
        'vision_understanding',
        'embedding',
        'asr',
        'tts',
        'image_generation',
        'video_generation',
    ];

    public function __construct(private readonly ?ProviderHttpClient $client = null)
    {
    }

    /**
     * @return list<array{name: string, external_name: string, capability: string, context_length: int|null}>
     */
    public function fetchModels(int $providerId): array
    {
        $provider = $this->provider($providerId);
        $response = ($this->client ?? new ProviderHttpClient())->send('/v1/models', $provider, [
            'method' => 'GET',
            'headers' => ['Accept' => 'application/json'],
        ]);

        if ($response['status'] === 0) {
            abort_json('PROVIDER_TIMEOUT', 'Upstream provider model list request timed out', 504);
        }
        if ($response['status'] >= 400 || !is_array($response['json'])) {
            abort_json('PROVIDER_REJECTED', 'Upstream provider model list request failed', 502);
        }

        return $this->normalizeModels($response['json']);
    }

    /**
     * @param list<array<string, mixed>|string> $models
     * @return array{provider_id: int, saved: int, created: int, updated: int, models: list<array<string, mixed>>}
     */
    public function importModels(int $providerId, array $models): array
    {
        $this->provider($providerId);
        $saved = [];
        $created = 0;
        $updated = 0;

        foreach ($this->normalizeSelectedModels($models) as $model) {
            $existing = App::db()->first(
                'SELECT id FROM models WHERE provider_id = ? AND name = ?',
                [$providerId, $model['name']]
            );
            if ($existing) {
                App::db()->execute(
                    'UPDATE models SET external_name = ?, capability = ?, context_length = ?, status = "active" WHERE id = ?',
                    [$model['external_name'], $model['capability'], $model['context_length'], $existing['id']]
                );
                $updated++;
                $saved[] = App::db()->first('SELECT * FROM models WHERE id = ?', [$existing['id']]) ?: ['id' => (int) $existing['id']];
                continue;
            }

            App::db()->execute(
                'INSERT INTO models (provider_id, name, external_name, capability, context_length, status, route_priority) VALUES (?, ?, ?, ?, ?, "active", 100)',
                [$providerId, $model['name'], $model['external_name'], $model['capability'], $model['context_length']]
            );
            $id = (int) App::db()->pdo()->lastInsertId();
            $created++;
            $saved[] = App::db()->first('SELECT * FROM models WHERE id = ?', [$id]) ?: ['id' => $id];
        }

        (new AuditService())->write('provider.models.import', 'providers', $providerId, [
            'saved' => count($saved),
            'created' => $created,
            'updated' => $updated,
        ]);

        return [
            'provider_id' => $providerId,
            'saved' => count($saved),
            'created' => $created,
            'updated' => $updated,
            'models' => (new SensitiveDataService())->sanitizeRows('models', $saved),
        ];
    }

    /**
     * @return list<array{name: string, external_name: string, capability: string, context_length: int|null}>
     */
    public function normalizeModels(array $payload): array
    {
        $items = $payload['data'] ?? $payload['models'] ?? $payload;
        if (!is_array($items)) {
            return [];
        }

        $models = [];
        foreach ($items as $item) {
            $externalName = is_array($item)
                ? (string) ($item['id'] ?? $item['name'] ?? $item['model'] ?? '')
                : (string) $item;
            $externalName = trim($externalName);
            if ($externalName === '' || isset($models[$externalName])) {
                continue;
            }
            $contextLength = is_array($item) && isset($item['context_length']) && is_numeric($item['context_length'])
                ? (int) $item['context_length']
                : null;
            $models[$externalName] = [
                'name' => $externalName,
                'external_name' => $externalName,
                'capability' => $this->inferCapability($externalName),
                'context_length' => $contextLength,
            ];
        }

        return array_values($models);
    }

    /**
     * @param list<array<string, mixed>|string> $models
     * @return list<array{name: string, external_name: string, capability: string, context_length: int|null}>
     */
    private function normalizeSelectedModels(array $models): array
    {
        $normalized = [];
        foreach ($models as $model) {
            $externalName = is_array($model)
                ? (string) ($model['external_name'] ?? $model['id'] ?? $model['name'] ?? '')
                : (string) $model;
            $externalName = trim($externalName);
            if ($externalName === '') {
                continue;
            }
            $name = is_array($model) ? trim((string) ($model['name'] ?? $externalName)) : $externalName;
            $capability = is_array($model) ? (string) ($model['capability'] ?? '') : '';
            if (!in_array($capability, self::CAPABILITIES, true)) {
                $capability = $this->inferCapability($externalName);
            }
            $contextLength = is_array($model) && isset($model['context_length']) && is_numeric($model['context_length'])
                ? (int) $model['context_length']
                : null;
            $normalized[] = [
                'name' => $name !== '' ? $name : $externalName,
                'external_name' => $externalName,
                'capability' => $capability,
                'context_length' => $contextLength,
            ];
        }

        return $normalized;
    }

    private function inferCapability(string $model): string
    {
        $name = strtolower($model);
        return match (true) {
            str_contains($name, 'embed') => 'embedding',
            str_contains($name, 'whisper') || str_contains($name, 'asr') || str_contains($name, 'transcri') => 'asr',
            str_contains($name, 'tts') || str_contains($name, 'speech') => 'tts',
            str_contains($name, 'seedance') || str_contains($name, 'video') => 'video_generation',
            str_contains($name, 'seedream') || str_contains($name, 'dall-e') || str_contains($name, 'image') => 'image_generation',
            str_contains($name, 'kimi-k2.6') => 'vision_understanding',
            str_contains($name, 'vision') || str_contains($name, 'vl') => 'vision_understanding',
            default => 'text_generation',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function provider(int $providerId): array
    {
        $provider = App::db()->first('SELECT * FROM providers WHERE id = ?', [$providerId]);
        if (!$provider) {
            abort_json('ADMIN_NOT_FOUND', 'Provider not found', 404);
        }

        return $provider;
    }
}
