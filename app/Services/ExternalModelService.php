<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\PublicVendorMask;
use TokenHub\Services\Gateway\RouteHealth;
use TokenHub\Support\App;

final class ExternalModelService
{
    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function authorized(array $apiKey): array
    {
        $rows = App::db()->select(
            'SELECT m.id, m.name, m.capability, m.context_length, m.status, p.name AS provider_name, p.protocol
             FROM api_key_model_permissions perm
             JOIN models m ON m.id = perm.model_id
             JOIN providers p ON p.id = m.provider_id
             WHERE perm.api_key_id = ? AND m.status = "active" AND p.status = "active"
             ORDER BY m.route_priority ASC, m.id ASC',
            [$apiKey['id'] ?? 0]
        );

        // OpenAI-compatible list shape; one entry per logical model even when
        // several channels serve it.
        $data = [];
        foreach ($rows as $row) {
            $id = (string) $row['name'];
            if (isset($data[$id])) {
                continue;
            }
            $data[$id] = [
                'id' => $id,
                'object' => 'model',
                'created' => 0,
                'owned_by' => PublicVendorMask::VENDOR,
                'capability' => (string) $row['capability'],
                'context_length' => isset($row['context_length']) ? (int) $row['context_length'] : null,
            ];
        }

        return [
            'object' => 'list',
            'data' => array_values($data),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function detail(array $apiKey, string $model): array
    {
        $row = $this->authorizedRow($apiKey, $model);
        if ($row === null) {
            abort_json('MODEL_NOT_FOUND', 'Model not found or outside API key scope', 404);
        }

        return ['model' => $this->publicModel($row, true)];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function health(array $apiKey): array
    {
        $rows = $this->authorizedRows($apiKey);
        $models = array_map(fn (array $row): array => [
            'model' => (string) $row['name'],
            'capability' => (string) $row['capability'],
            'provider' => PublicVendorMask::VENDOR,
            'protocol' => (string) $row['protocol'],
            'health' => $this->healthFor($row),
        ], $rows);

        return [
            'summary' => [
                'total' => count($models),
                'active' => count(array_filter($models, static fn (array $model): bool => ($model['health']['status'] ?? '') === 'active')),
                'degraded' => count(array_filter($models, static fn (array $model): bool => ($model['health']['status'] ?? '') !== 'active')),
            ],
            'models' => $models,
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function authorizedRows(array $apiKey): array
    {
        return App::db()->select(
            'SELECT m.id, m.name, m.external_name, m.capability, m.context_length, m.status, m.route_priority, p.name AS provider_name, p.protocol, p.status AS provider_status, p.base_url, p.timeout_ms
             FROM api_key_model_permissions perm
             JOIN models m ON m.id = perm.model_id
             JOIN providers p ON p.id = m.provider_id
             WHERE perm.api_key_id = ? AND m.status = "active" AND p.status = "active"
             ORDER BY m.route_priority ASC, m.id ASC',
            [$apiKey['id'] ?? 0]
        );
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>|null
     */
    private function authorizedRow(array $apiKey, string $model): ?array
    {
        return App::db()->first(
            'SELECT m.id, m.name, m.external_name, m.capability, m.context_length, m.status, m.route_priority, p.name AS provider_name, p.protocol, p.status AS provider_status, p.base_url, p.timeout_ms
             FROM api_key_model_permissions perm
             JOIN models m ON m.id = perm.model_id
             JOIN providers p ON p.id = m.provider_id
             WHERE perm.api_key_id = ? AND (m.name = ? OR m.external_name = ?) AND m.status = "active" AND p.status = "active"
             ORDER BY m.route_priority ASC, m.id ASC
             LIMIT 1',
            [$apiKey['id'] ?? 0, $model, $model]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicModel(array $row, bool $includeHealth = false): array
    {
        $model = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'external_name' => (string) ($row['external_name'] ?? ''),
            'capability' => (string) $row['capability'],
            'context_length' => $row['context_length'] === null ? null : (int) $row['context_length'],
            'protocol' => (string) $row['protocol'],
            'provider' => PublicVendorMask::VENDOR,
            'supports_stream' => in_array((string) $row['capability'], \TokenHub\Services\Gateway\InputModalities::TEXT_FAMILY, true),
            'multimodal' => [
                'input' => $this->modalitiesIn((string) $row['capability']),
                'output' => $this->modalitiesOut((string) $row['capability']),
            ],
        ];
        if ($includeHealth) {
            $model['health'] = $this->healthFor($row);
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function healthFor(array $row): array
    {
        // Configuration readiness, live circuit state and observed success
        // rate are separate facts; "enabled" alone is not "healthy".
        $configured = (string) ($row['status'] ?? '') === 'active' && (string) ($row['provider_status'] ?? '') === 'active' ? 'active' : 'disabled';
        $circuit = (new RouteHealth())->snapshot((int) ($row['id'] ?? 0));
        $observed = $this->observedHealth((int) ($row['id'] ?? 0));
        $status = match (true) {
            $configured !== 'active' => 'disabled',
            ($circuit['state'] ?? 'closed') === 'open' => 'unhealthy',
            $observed['requests'] === null => 'unknown',
            $observed['requests'] === 0 => 'unknown',
            $observed['success_rate'] !== null && $observed['success_rate'] < 0.7 => 'degraded',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'route_status' => $configured,
            'circuit_state' => $circuit['state'] ?? 'closed',
            'last_failure_reason' => $circuit['last_failure_reason'] ?? null,
            'observed' => $observed,
            'timeout_ms' => isset($row['timeout_ms']) ? (int) $row['timeout_ms'] : null,
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Success rate over the last 15 minutes of real traffic.
     *
     * @return array{window_minutes: int, requests: int|null, success_rate: float|null}
     */
    private function observedHealth(int $modelId): array
    {
        try {
            $row = App::db()->first(
                'SELECT COALESCE(SUM(request_count), 0) requests, COALESCE(SUM(success_count), 0) successes FROM usage_aggregates_minute WHERE model_id = ? AND bucket_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)',
                [$modelId]
            );
        } catch (\Throwable) {
            return ['window_minutes' => 15, 'requests' => null, 'success_rate' => null];
        }
        $requests = (int) ($row['requests'] ?? 0);

        return ['window_minutes' => 15, 'requests' => $requests, 'success_rate' => $requests > 0 ? round(((int) $row['successes']) / $requests, 4) : null];
    }

    /**
     * @return list<string>
     */
    private function modalitiesIn(string $capability): array
    {
        return match ($capability) {
            'vision_understanding', 'moderation' => ['text', 'image'],
            'video_understanding' => ['text', 'image', 'video'],
            'audio_understanding' => ['text', 'audio'],
            'asr' => ['audio'],
            'realtime' => ['text', 'audio'],
            'image_generation', 'video_generation', 'tts', 'music_generation' => ['text'],
            default => ['text'],
        };
    }

    /**
     * @return list<string>
     */
    private function modalitiesOut(string $capability): array
    {
        return match ($capability) {
            'embedding' => ['embedding'],
            'rerank' => ['score'],
            'moderation' => ['classification'],
            'asr' => ['text'],
            'tts' => ['audio'],
            'image_generation' => ['image'],
            'video_generation' => ['video'],
            'music_generation' => ['audio'],
            'realtime' => ['text', 'audio'],
            default => ['text'],
        };
    }
}
