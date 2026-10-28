<?php

declare(strict_types=1);

namespace TokenHub\Services;

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

        return [
            'data' => array_map(fn (array $row): array => [
                'id' => (string) $row['name'],
            ], $rows),
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
            'provider' => (string) $row['provider_name'],
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
            'provider' => (string) $row['provider_name'],
            'supports_stream' => in_array((string) $row['capability'], ['text_generation', 'vision_understanding'], true),
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
        $status = (string) ($row['status'] ?? '') === 'active' && (string) ($row['provider_status'] ?? '') === 'active' ? 'active' : 'disabled';
        return [
            'status' => $status,
            'route_status' => $status,
            'timeout_ms' => isset($row['timeout_ms']) ? (int) $row['timeout_ms'] : null,
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<string>
     */
    private function modalitiesIn(string $capability): array
    {
        return match ($capability) {
            'vision_understanding' => ['text', 'image'],
            'asr' => ['audio'],
            'image_generation', 'video_generation', 'tts' => ['text'],
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
            'asr' => ['text'],
            'tts' => ['audio'],
            'image_generation' => ['image'],
            'video_generation' => ['video'],
            default => ['text'],
        };
    }
}
