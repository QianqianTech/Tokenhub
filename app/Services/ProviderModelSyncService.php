<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\SchemaInspector;

final class ProviderModelSyncService
{
    private const CAPABILITIES = [
        'text_generation',
        'vision_understanding',
        'video_understanding',
        'audio_understanding',
        'embedding',
        'asr',
        'tts',
        'image_generation',
        'video_generation',
        'rerank',
        'moderation',
        'music_generation',
        'realtime',
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
     * Upstream catalog split by ModelSupportPolicy: the admin import list
     * only offers models the gateway can serve through this provider.
     *
     * @return array{models: list<array<string, mixed>>, hidden_count: int, hidden_reasons: array<string, array{message: string, count: int, examples: list<string>}>}
     */
    public function fetchSupportedModels(int $providerId): array
    {
        $provider = $this->provider($providerId);
        [$supported, $hidden] = $this->splitBySupport($this->fetchModels($providerId), $provider);

        return ['models' => $supported, 'hidden_count' => array_sum(array_column($hidden, 'count')), 'hidden_reasons' => $hidden];
    }

    /**
     * @param list<array<string, mixed>> $models
     * @param array<string, mixed> $provider
     * @return array{0: list<array<string, mixed>>, 1: array<string, array{message: string, count: int, examples: list<string>}>}
     */
    private function splitBySupport(array $models, array $provider): array
    {
        $policy = new ModelSupportPolicy();
        $supported = [];
        $hidden = [];
        foreach ($models as $model) {
            $support = $policy->evaluate($model, $provider);
            if ($support['supported']) {
                $supported[] = $model;
                continue;
            }
            $reason = (string) $support['reason'];
            $hidden[$reason] ??= ['message' => (string) $support['message'], 'count' => 0, 'examples' => []];
            $hidden[$reason]['count']++;
            if (count($hidden[$reason]['examples']) < 5) {
                $hidden[$reason]['examples'][] = (string) ($model['external_name'] ?? $model['name'] ?? '');
            }
        }

        return [$supported, $hidden];
    }

    /**
     * @param list<array<string, mixed>|string> $models
     * @return array{provider_id: int, saved: int, created: int, updated: int, skipped: int, skipped_reasons: array<string, mixed>, models: list<array<string, mixed>>}
     */
    public function importModels(int $providerId, array $models): array
    {
        $provider = $this->provider($providerId);
        $saved = [];
        $created = 0;
        $updated = 0;
        // Unsupported models are never imported, even if a client sends them.
        [$selected, $skipped] = $this->splitBySupport($this->normalizeSelectedModels($models), $provider);

        foreach ($selected as $model) {
            $existing = App::db()->first(
                'SELECT id FROM models WHERE provider_id = ? AND name = ?',
                [$providerId, $model['name']]
            );
            if ($existing) {
                // Re-import refreshes catalog facts but never re-enables a model
                // an administrator disabled or retired.
                App::db()->execute(
                    'UPDATE models SET external_name = ?, capability = ?, context_length = ? WHERE id = ?',
                    [$model['external_name'], $model['capability'], $model['context_length'], $existing['id']]
                );
                $this->storeUpstreamLayer((int) $existing['id'], $model);
                $updated++;
                $saved[] = App::db()->first('SELECT * FROM models WHERE id = ?', [$existing['id']]) ?: ['id' => (int) $existing['id']];
                continue;
            }

            App::db()->execute(
                'INSERT INTO models (provider_id, name, external_name, capability, context_length, status, route_priority) VALUES (?, ?, ?, ?, ?, "active", 100)',
                [$providerId, $model['name'], $model['external_name'], $model['capability'], $model['context_length']]
            );
            $id = (int) App::db()->pdo()->lastInsertId();
            $this->storeUpstreamLayer($id, $model);
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
            'skipped' => array_sum(array_column($skipped, 'count')),
            'skipped_reasons' => $skipped,
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
            $metadata = is_array($item) ? $this->catalogMetadata($item) : [];
            $models[$externalName] = [
                'name' => $externalName,
                'external_name' => $externalName,
                'capability' => $this->capabilityFromMetadata($metadata) ?? $this->inferCapability($externalName),
                'context_length' => $contextLength,
            ];
            if ($metadata !== []) {
                $models[$externalName]['metadata'] = $metadata;
            }
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
                'metadata' => is_array($model) && is_array($model['metadata'] ?? null) ? $model['metadata'] : [],
            ];
        }

        return $normalized;
    }

    /**
     * Incremental catalog sync with preview.
     *
     * Compares the upstream catalog with this provider's model rows and
     * reports added / changed / missing models. With $apply the catalog facts
     * of existing rows are refreshed (status is never touched) and models
     * absent upstream are flagged with catalog_missing_since; new upstream
     * models are only reported, never auto-imported. Every detected change is
     * written to model_catalog_changes for audit.
     *
     * @return array{provider_id: int, applied: bool, added: list<array<string, mixed>>, changed: list<array<string, mixed>>, missing: list<array<string, mixed>>, unchanged: int}
     */
    public function syncProvider(int $providerId, bool $apply = false): array
    {
        if ($providerId <= 0) {
            return ['provider_id' => 0, 'applied' => false, 'added' => [], 'changed' => [], 'missing' => [], 'unchanged' => 0];
        }
        $catalog = [];
        foreach ($this->fetchModels($providerId) as $model) {
            $catalog[$model['external_name']] = $model;
        }
        $existingRows = App::db()->select('SELECT * FROM models WHERE provider_id = ?', [$providerId]);
        $existingByExternal = [];
        foreach ($existingRows as $row) {
            $existingByExternal[(string) $row['external_name']] = $row;
        }
        $hasLayers = SchemaInspector::hasColumn('models', 'capabilities');
        $hasHistory = SchemaInspector::hasColumn('model_catalog_changes', 'change_type');
        $added = [];
        $changed = [];
        $missing = [];
        $unchanged = 0;

        $policy = new ModelSupportPolicy();
        $provider = $this->provider($providerId);
        $hiddenAdded = 0;
        foreach ($catalog as $external => $model) {
            if (!isset($existingByExternal[$external])) {
                if (!$policy->evaluate($model, $provider)['supported']) {
                    // Not importable: not offered as a new model either.
                    $hiddenAdded++;
                    continue;
                }
                $added[] = $model;
                $this->recordChange($hasHistory, $providerId, null, $external, 'added', null, $model, false);
                continue;
            }
            $row = $existingByExternal[$external];
            $before = [
                'context_length' => $row['context_length'] !== null ? (int) $row['context_length'] : null,
                'upstream' => $hasLayers ? ((json_decode((string) ($row['capabilities'] ?? ''), true) ?: [])['upstream'] ?? null) : null,
            ];
            $after = [
                'context_length' => $model['context_length'],
                'upstream' => $this->upstreamLayer($model),
            ];
            if ($before == $after) {
                $unchanged++;
                if ($apply && SchemaInspector::hasColumn('models', 'catalog_synced_at')) {
                    App::db()->execute('UPDATE models SET catalog_synced_at = UTC_TIMESTAMP(), catalog_missing_since = NULL WHERE id = ?', [(int) $row['id']]);
                }
                continue;
            }
            $changed[] = ['model_id' => (int) $row['id'], 'external_name' => $external, 'before' => $before, 'after' => $after];
            $this->recordChange($hasHistory, $providerId, (int) $row['id'], $external, 'changed', $before, $after, $apply);
            if ($apply) {
                App::db()->execute('UPDATE models SET context_length = ? WHERE id = ?', [$model['context_length'], (int) $row['id']]);
                $this->storeUpstreamLayer((int) $row['id'], $model);
            }
        }

        foreach ($existingByExternal as $external => $row) {
            if (isset($catalog[$external])) {
                continue;
            }
            $missing[] = ['model_id' => (int) $row['id'], 'external_name' => $external, 'status' => $row['status']];
            $this->recordChange($hasHistory, $providerId, (int) $row['id'], (string) $external, 'missing_upstream', ['status' => $row['status']], null, $apply);
            if ($apply && SchemaInspector::hasColumn('models', 'catalog_missing_since')) {
                App::db()->execute('UPDATE models SET catalog_missing_since = COALESCE(catalog_missing_since, UTC_TIMESTAMP()) WHERE id = ?', [(int) $row['id']]);
            }
        }

        if ($apply) {
            (new AuditService())->write('provider.models.sync', 'providers', $providerId, [
                'added' => count($added),
                'changed' => count($changed),
                'missing' => count($missing),
            ]);
        }

        return ['provider_id' => $providerId, 'applied' => $apply, 'added' => $added, 'changed' => $changed, 'missing' => $missing, 'unchanged' => $unchanged, 'hidden_unsupported' => $hiddenAdded];
    }

    /**
     * Upstream catalog facts in the shape of a ModelCapabilityContract layer.
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>|null
     */
    public function upstreamLayer(array $model): ?array
    {
        $metadata = is_array($model['metadata'] ?? null) ? $model['metadata'] : [];
        if ($metadata === []) {
            return null;
        }
        $layer = [];
        foreach (['input_modalities', 'output_modalities', 'features', 'context_length', 'max_output_tokens', 'pricing'] as $key) {
            if (isset($metadata[$key])) {
                $layer[$key] = $metadata[$key];
            }
        }

        return $layer === [] ? null : $layer;
    }

    /**
     * Extracts OpenRouter-style catalog metadata (architecture, supported
     * parameters, pricing, top provider limits). Fields that are absent stay
     * absent: unknown is never turned into "supported".
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function catalogMetadata(array $item): array
    {
        $metadata = [];
        $architecture = is_array($item['architecture'] ?? null) ? $item['architecture'] : [];
        foreach (['input_modalities', 'output_modalities'] as $key) {
            if (isset($architecture[$key]) && is_array($architecture[$key])) {
                $metadata[$key] = array_values(array_map('strval', $architecture[$key]));
            }
        }
        if (isset($item['supported_parameters']) && is_array($item['supported_parameters'])) {
            $parameters = array_map('strval', $item['supported_parameters']);
            $metadata['supported_parameters'] = $parameters;
            $metadata['features'] = [
                'tools' => in_array('tools', $parameters, true),
                'parallel_tool_calls' => in_array('parallel_tool_calls', $parameters, true) || in_array('tools', $parameters, true),
                'json_object' => in_array('response_format', $parameters, true),
                'json_schema' => in_array('structured_outputs', $parameters, true),
                'reasoning' => in_array('reasoning', $parameters, true) || in_array('include_reasoning', $parameters, true),
                'streaming' => true,
            ];
        }
        if (isset($item['context_length']) && is_numeric($item['context_length'])) {
            $metadata['context_length'] = (int) $item['context_length'];
        }
        $top = is_array($item['top_provider'] ?? null) ? $item['top_provider'] : [];
        if (isset($top['max_completion_tokens']) && is_numeric($top['max_completion_tokens'])) {
            $metadata['max_output_tokens'] = (int) $top['max_completion_tokens'];
        }
        if (isset($item['pricing']) && is_array($item['pricing'])) {
            $pricing = [];
            foreach (['prompt' => 'input_token_price', 'completion' => 'output_token_price', 'input_cache_read' => 'cache_read_token_price', 'input_cache_write' => 'cache_write_token_price', 'internal_reasoning' => 'reasoning_token_price', 'request' => 'request_price', 'image' => 'image_price'] as $source => $target) {
                if (isset($item['pricing'][$source]) && is_numeric($item['pricing'][$source]) && (float) $item['pricing'][$source] >= 0) {
                    $pricing[$target] = (string) $item['pricing'][$source];
                }
            }
            if ($pricing !== []) {
                $pricing['currency'] = 'USD';
                $metadata['pricing'] = $pricing;
            }
        }
        if (isset($item['expiration_date']) && is_string($item['expiration_date'])) {
            $metadata['expiration_date'] = $item['expiration_date'];
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function capabilityFromMetadata(array $metadata): ?string
    {
        $inputs = $metadata['input_modalities'] ?? null;
        $outputs = $metadata['output_modalities'] ?? null;
        if (!is_array($outputs)) {
            return null;
        }
        if (in_array('image', $outputs, true) && !in_array('text', $outputs, true)) {
            return 'image_generation';
        }
        if (in_array('embeddings', $outputs, true) || in_array('embedding', $outputs, true)) {
            return 'embedding';
        }
        if (in_array('text', $outputs, true)) {
            $inputs = is_array($inputs) ? $inputs : [];
            return match (true) {
                in_array('video', $inputs, true) => 'video_understanding',
                in_array('audio', $inputs, true) && !in_array('image', $inputs, true) => 'audio_understanding',
                in_array('image', $inputs, true) => 'vision_understanding',
                default => 'text_generation',
            };
        }

        return null;
    }

    /**
     * @param array<string, mixed> $model
     */
    private function storeUpstreamLayer(int $modelId, array $model): void
    {
        $layer = $this->upstreamLayer($model);
        if (!SchemaInspector::hasColumn('models', 'capabilities')) {
            return;
        }
        $row = App::db()->first('SELECT capabilities FROM models WHERE id = ?', [$modelId]);
        $layers = json_decode((string) ($row['capabilities'] ?? ''), true);
        $layers = is_array($layers) ? $layers : [];
        if ($layer !== null) {
            $layers['upstream'] = $layer;
        }
        $tags = [$model['capability']];
        if (in_array($model['capability'] ?? '', ['vision_understanding', 'video_understanding', 'audio_understanding'], true)) {
            $tags[] = 'text_generation';
        }
        // Preset tags and every input modality the catalog declares are routable.
        $tags = [...$tags, ...$this->inferTags((string) ($model['external_name'] ?? $model['name'] ?? ''))];
        $inputs = is_array($layer['input_modalities'] ?? null) ? $layer['input_modalities'] : [];
        foreach (['image' => 'vision_understanding', 'video' => 'video_understanding', 'audio' => 'audio_understanding'] as $modality => $tag) {
            if (in_array($modality, $inputs, true)) {
                $tags[] = $tag;
            }
        }
        $params = [$layers === [] ? null : json_encode($layers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), json_encode(array_values(array_unique($tags))), $modelId];
        App::db()->execute(
            'UPDATE models SET capabilities = ?, capability_tags = ?' . (SchemaInspector::hasColumn('models', 'catalog_synced_at') ? ', catalog_synced_at = UTC_TIMESTAMP(), catalog_missing_since = NULL' : '') . ' WHERE id = ?',
            $params
        );
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function recordChange(bool $enabled, int $providerId, ?int $modelId, string $external, string $type, ?array $before, ?array $after, bool $applied): void
    {
        if (!$enabled) {
            return;
        }
        App::db()->execute(
            'INSERT INTO model_catalog_changes (provider_id, model_id, external_name, change_type, before_snapshot, after_snapshot, applied, actor_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $providerId,
                $modelId,
                $external,
                $type,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $applied ? 1 : 0,
                App::context()->adminUser()['id'] ?? null,
            ]
        );
    }

    /**
     * Catalog prices (from the upstream capability layer, e.g. OpenRouter's
     * per-token USD prices) compared with each model's current price (MM-032).
     * With $apply the selected models get a new price version effective now;
     * nothing is written without an explicit apply, and only token/request/
     * image prices the catalog actually states are copied.
     *
     * @param list<int>|null $modelIds models to apply (null = all with a difference)
     * @return array{provider_id: int, applied: bool, models: list<array<string, mixed>>}
     */
    public function catalogPrices(int $providerId, bool $apply = false, ?array $modelIds = null): array
    {
        $this->provider($providerId);
        $rows = App::db()->select('SELECT id, name, external_name, capabilities FROM models WHERE provider_id = ?', [$providerId]);
        $out = [];
        foreach ($rows as $row) {
            $layers = json_decode((string) ($row['capabilities'] ?? ''), true);
            $catalog = is_array($layers['upstream']['pricing'] ?? null) ? $layers['upstream']['pricing'] : [];
            unset($catalog['currency']);
            if ($catalog === []) {
                continue;
            }
            $current = App::db()->first('SELECT * FROM model_prices WHERE model_id = ? AND effective_at <= UTC_TIMESTAMP() ORDER BY effective_at DESC, id DESC LIMIT 1', [(int) $row['id']]);
            $changes = [];
            foreach ($catalog as $field => $value) {
                $have = $current[$field] ?? null;
                if ($have === null || $have === '' || \TokenHub\Support\Decimal::compare((string) $have, (string) $value) !== 0) {
                    $changes[$field] = ['current' => $have, 'catalog' => \TokenHub\Support\Decimal::normalize((string) $value)];
                }
            }
            if ($changes === []) {
                continue;
            }
            $entry = ['model_id' => (int) $row['id'], 'name' => $row['name'], 'external_name' => $row['external_name'], 'changes' => $changes, 'applied' => false];
            if ($apply && ($modelIds === null || in_array((int) $row['id'], array_map('intval', $modelIds), true))) {
                $values = ['model_id' => (int) $row['id'], 'currency' => 'USD'];
                foreach (['input_token_price', 'output_token_price', 'cache_read_token_price', 'cache_write_token_price', 'reasoning_token_price', 'audio_second_price', 'tts_char_price', 'image_price', 'video_second_price', 'request_price'] as $field) {
                    $values[$field] = isset($catalog[$field]) ? \TokenHub\Support\Decimal::normalize((string) $catalog[$field]) : ($current[$field] ?? null);
                }
                if (array_key_exists('pricing_rules', $current ?? []) && $current['pricing_rules'] !== null) {
                    $values['pricing_rules'] = $current['pricing_rules'];
                }
                $columns = array_keys($values);
                App::db()->execute('INSERT INTO model_prices (' . implode(', ', $columns) . ', effective_at) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ', UTC_TIMESTAMP())', array_values($values));
                $entry['applied'] = true;
            }
            $out[] = $entry;
        }
        if ($apply) {
            (new AuditService())->write('provider.catalog_prices.apply', 'providers', $providerId, ['models' => array_values(array_map(static fn (array $entry): int => $entry['model_id'], array_filter($out, static fn (array $entry): bool => $entry['applied'])))]);
            \TokenHub\Support\ConfigVersion::bump('global', 'catalog-prices');
        }

        return ['provider_id' => $providerId, 'applied' => $apply, 'models' => $out];
    }

    /**
     * Capability from the model family presets (config/model_catalog.php).
     */
    public function inferCapability(string $model): string
    {
        return (string) ($this->matchingRule($model)['capability'] ?? 'text_generation');
    }

    /**
     * Extra routable capabilities of a model family preset ("tags").
     *
     * @return list<string>
     */
    public function inferTags(string $model): array
    {
        $tags = $this->matchingRule($model)['tags'] ?? [];

        return array_values(array_filter(is_array($tags) ? array_map('strval', $tags) : [], fn (string $tag): bool => in_array($tag, self::CAPABILITIES, true)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchingRule(string $model): ?array
    {
        $name = strtolower($model);
        $rules = App::config()->get('model_catalog.rules', []);
        foreach (is_array($rules) ? $rules : [] as $rule) {
            $pattern = is_array($rule) ? (string) ($rule['pattern'] ?? '') : '';
            $capability = is_array($rule) ? (string) ($rule['capability'] ?? '') : '';
            if ($pattern === '' || !in_array($capability, self::CAPABILITIES, true)) {
                continue;
            }
            if (@preg_match('~' . str_replace('~', '\~', $pattern) . '~i', $name) === 1) {
                return $rule;
            }
        }

        return null;
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
