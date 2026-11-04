<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use TokenHub\Support\App;

final class RagVectorConfigService
{
    /** @param null|callable(): QdrantVectorStore $qdrantFactory */
    public function __construct(
        private readonly mixed $qdrantFactory = null,
        private readonly ?RagEmbeddingProviderService $embeddingProvider = null,
    )
    {
    }

    /** @var null|callable(): QdrantVectorStore process-wide override (tests, tooling) */
    private static mixed $defaultQdrantFactory = null;

    /** @param null|callable(): QdrantVectorStore $factory */
    public static function useQdrantFactory(?callable $factory): void
    {
        self::$defaultQdrantFactory = $factory;
    }

    public function store(?array $config = null): RagVectorStoreInterface
    {
        $store = (string) ($config['vector_store'] ?? App::config()->get('rag.vector_store.primary', 'qdrant'));
        if ($store === 'milvus' || $store === 'pgvector') {
            // V-06: no Milvus/pgvector driver exists; older rows keep working on the MySQL store.
            static $warned = [];
            if (!isset($warned[$store])) {
                $warned[$store] = true;
                App::logger()->warning('rag', 'rag vector store not implemented, using the MySQL fallback', ['vector_store' => $store, 'config_id' => $config['config_id'] ?? null]);
            }
        }
        if ($store === 'qdrant') {
            $factory = is_callable($this->qdrantFactory) ? $this->qdrantFactory : self::$defaultQdrantFactory;
            $qdrant = (is_callable($factory) ? $factory() : new QdrantVectorStore())->forConfig($config);
            if ($qdrant->healthy()) {
                return $qdrant;
            }
            throw new RuntimeException('RAG_VECTOR_STORE_UNAVAILABLE');
        }

        return new MysqlFallbackVectorStore();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeForWrite(array $data, bool $creating = true, ?int $id = null): array
    {
        $existing = !$creating && $id !== null
            ? App::db()->first('SELECT * FROM rag_vector_configs WHERE id = ?', [$id])
            : null;
        $effective = [...($existing ?? []), ...$data];

        $this->assertAllowed($effective, 'modality', ['text', 'image', 'multimodal']);
        $this->assertAllowed($effective, 'distance_metric', ['cosine', 'dot', 'euclidean']);
        $this->assertAllowed($effective, 'vector_store', ['qdrant', 'milvus', 'pgvector', 'mysql_fallback']);
        if (array_key_exists('vector_store', $data) && in_array((string) $data['vector_store'], ['milvus', 'pgvector'], true)
            && (string) ($existing['vector_store'] ?? '') !== (string) $data['vector_store']) {
            abort_json('RAG_VECTOR_STORE_UNSUPPORTED', 'Milvus and pgvector are not supported yet; use Qdrant (or the MySQL fallback for small datasets)', 422);
        }
        $dimension = $effective['dimension'] ?? null;
        if (!is_numeric($dimension) || (int) $dimension < 1) {
            throw new RuntimeException('RAG_VECTOR_CONFIG_INVALID');
        }
        if (trim((string) ($effective['collection_name'] ?? '')) === '') {
            throw new RuntimeException('RAG_VECTOR_CONFIG_INVALID');
        }

        $this->assertCollectionNotShared($effective, $id);
        if ($existing !== null) {
            $this->assertNotInUse($existing, $effective);
        }

        $providerId = $this->positiveId($effective['provider_id'] ?? null);
        $modelId = $this->positiveId($effective['model_id'] ?? null);
        if (($providerId === null) !== ($modelId === null)) {
            throw new RuntimeException('RAG_VECTOR_CONFIG_INVALID');
        }

        if ($providerId === null) {
            if (!$this->allowsLocalFallback($effective)) {
                throw new RuntimeException('RAG_VECTOR_CONFIG_INVALID');
            }
            $data['provider_id'] = null;
            $data['model_id'] = null;
            return $data;
        }

        $model = App::db()->first(
            'SELECT m.id, m.name, m.external_name
             FROM models m
             JOIN providers p ON p.id = m.provider_id
             WHERE m.id = ? AND p.id = ? AND m.capability = "embedding" AND m.status = "active" AND p.status = "active"
             LIMIT 1',
            [$modelId, $providerId]
        );
        if ($model === null) {
            throw new RuntimeException('RAG_MODEL_NOT_CONFIGURED');
        }

        $data['provider_id'] = $providerId;
        $data['model_id'] = $modelId;
        $data['model_name'] = trim((string) (($model['external_name'] ?? '') ?: ($model['name'] ?? '')));
        return $data;
    }

    /** @param array<string, mixed> $data */
    public function assertBindingReferences(string $table, array $data, ?int $id = null): void
    {
        if ($id !== null) {
            $this->assertRebindingAllowed($table, $data, $id);
        }
        $columns = match ($table) {
            'rag_knowledge_bases' => [
                'default_text_vector_config_id' => 'text',
                'default_image_vector_config_id' => 'image',
                'default_multimodal_vector_config_id' => 'multimodal',
            ],
            'rag_vector_datasets' => [
                'text_vector_config_id' => 'text',
                'image_vector_config_id' => 'image',
                'multimodal_vector_config_id' => 'multimodal',
            ],
            default => [],
        };
        foreach ($columns as $column => $modality) {
            if (!array_key_exists($column, $data) || $this->positiveId($data[$column]) === null) {
                continue;
            }
            if ($this->enabledConfig((int) $data[$column], $modality) === null) {
                throw new RuntimeException('RAG_VECTOR_CONFIG_INVALID');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{provider_id:int, model_id:int, model_name:string, modality:string, dimension:int}
     */
    public function probe(array $data): array
    {
        $config = $this->normalizeForWrite($data);
        if (empty($config['provider_id']) || empty($config['model_id'])) {
            throw new RuntimeException('RAG_MODEL_NOT_CONFIGURED');
        }
        $modality = (string) ($config['modality'] ?? 'text');
        $configured = (int) ($config['dimension'] ?? 0);
        // Ask the model without a dimension so a wrong value reports the real one.
        $vector = ($this->embeddingProvider ?? new RagEmbeddingProviderService())->embed(
            $modality,
            ['text' => 'TokenHub RAG embedding probe'],
            [...$config, 'dimension' => null],
        );

        return [
            'provider_id' => (int) $config['provider_id'],
            'model_id' => (int) $config['model_id'],
            'model_name' => (string) $config['model_name'],
            'modality' => $modality,
            'dimension' => count($vector),
            'configured_dimension' => $configured,
            'dimension_matches' => $configured === count($vector),
        ];
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    public function textConfig(array $dataset): array
    {
        $row = $this->modalityConfig($dataset, 'text', 'text_vector_config_id', 'default_text_vector_config_id');
        if ($row !== null) {
            return $row;
        }

        return [
            'id' => null,
            'config_id' => 'default_text',
            'modality' => 'text',
            'model_name' => 'tokenhub-fallback-text',
            'dimension' => 3,
            'distance_metric' => 'cosine',
            'vector_store' => App::config()->get('rag.vector_store.primary', 'qdrant'),
            'collection_name' => $this->collection((string) ($dataset['dataset_id'] ?? 'default'), 'text'),
            'batch_size' => 32,
        ];
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>|null
     */
    public function imageConfig(array $dataset): ?array
    {
        return $this->modalityConfig($dataset, 'image', 'image_vector_config_id', 'default_image_vector_config_id');
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>|null
     */
    public function multimodalConfig(array $dataset): ?array
    {
        return $this->modalityConfig($dataset, 'multimodal', 'multimodal_vector_config_id', 'default_multimodal_vector_config_id');
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>|null
     */
    private function modalityConfig(array $dataset, string $modality, string $datasetColumn, string $knowledgeBaseColumn): ?array
    {
        $id = $dataset[$datasetColumn] ?? null;
        if ($id !== null && (int) $id > 0) {
            $row = $this->enabledConfig((int) $id, $modality);
            if ($row !== null) {
                return $row;
            }
        }

        $knowledgeBaseId = (int) ($dataset['kb_id'] ?? 0);
        if ($knowledgeBaseId > 0) {
            $knowledgeBase = App::db()->first("SELECT {$knowledgeBaseColumn} FROM rag_knowledge_bases WHERE id = ? AND deleted_at IS NULL", [$knowledgeBaseId]);
            $knowledgeBaseConfigId = (int) ($knowledgeBase[$knowledgeBaseColumn] ?? 0);
            if ($knowledgeBaseConfigId > 0) {
                $row = $this->enabledConfig($knowledgeBaseConfigId, $modality);
                if ($row !== null) {
                    return $row;
                }
            }
        }

        $row = App::db()->first('SELECT * FROM rag_vector_configs WHERE modality = ? AND enabled = 1 ORDER BY id LIMIT 1', [$modality]);
        if ($row !== null) {
            return $row;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function enabledConfig(int $id, string $modality): ?array
    {
        return App::db()->first(
            'SELECT * FROM rag_vector_configs WHERE id = ? AND modality = ? AND enabled = 1',
            [$id, $modality]
        );
    }

    /**
     * V-02: once a config has indexed vectors, changing what produces or stores them
     * (model, dimension, distance, store, collection, document prefix) or disabling it
     * would silently mix embedding spaces or break retrieval. Switch models with a new
     * config and `php bin/rag-index.php reindex --dataset=<id> --config=<new config id>`.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $effective
     */
    private function assertNotInUse(array $existing, array $effective): void
    {
        $changed = [];
        foreach (['model_id' => 'int', 'dimension' => 'int', 'distance_metric' => 'string', 'vector_store' => 'string', 'collection_name' => 'string'] as $field => $type) {
            $before = $existing[$field] ?? null;
            $after = $effective[$field] ?? null;
            if ($type === 'int' ? (int) $before !== (int) $after : trim((string) $before) !== trim((string) $after)) {
                $changed[] = $field;
            }
        }
        if (self::prefix($existing['config_json'] ?? null, 'document_prefix') !== self::prefix($effective['config_json'] ?? null, 'document_prefix')) {
            $changed[] = 'document_prefix';
        }
        if ((int) ($existing['enabled'] ?? 1) === 1 && array_key_exists('enabled', $effective) && (int) $effective['enabled'] === 0) {
            $changed[] = 'enabled';
        }
        if ($changed === []) {
            return;
        }
        $vectors = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_vector_records WHERE vector_config_id = ? AND status = "indexed"', [(int) $existing['id']])['c'] ?? 0);
        if ($vectors > 0) {
            abort_json('RAG_VECTOR_CONFIG_IN_USE', 'This vector config already indexed ' . $vectors . ' vector(s); changing ' . implode(', ', $changed) . ' would mix or break them. Create a new vector config and switch datasets with "php bin/rag-index.php reindex --dataset=<id> --config=<new id>"', 409);
        }
    }

    /**
     * Two configs may share a collection only when they produce the same kind of vectors.
     *
     * @param array<string, mixed> $effective
     */
    private function assertCollectionNotShared(array $effective, ?int $id): void
    {
        $collection = trim((string) ($effective['collection_name'] ?? ''));
        if ($collection === '' || (string) ($effective['vector_store'] ?? 'qdrant') !== 'qdrant') {
            return;
        }
        foreach (App::db()->select('SELECT id, model_id, dimension, distance_metric FROM rag_vector_configs WHERE collection_name = ? AND vector_store = "qdrant"', [$collection]) as $other) {
            if ($id !== null && (int) $other['id'] === $id) {
                continue;
            }
            if ((int) $other['model_id'] !== (int) ($effective['model_id'] ?? 0) || (int) $other['dimension'] !== (int) ($effective['dimension'] ?? 0) || (string) $other['distance_metric'] !== (string) ($effective['distance_metric'] ?? 'cosine')) {
                abort_json('RAG_VECTOR_COLLECTION_IN_USE', 'Collection "' . $collection . '" is used by another vector config with a different model, dimension or distance; choose another collection name', 409);
            }
        }
    }

    /**
     * Changing which config a dataset (or a knowledge base default) uses leaves its existing
     * vectors behind in the old model's space; that switch goes through reindex.
     *
     * @param array<string, mixed> $data
     */
    private function assertRebindingAllowed(string $table, array $data, int $id): void
    {
        if ($table === 'rag_vector_datasets' && array_key_exists('text_vector_config_id', $data)) {
            $dataset = App::db()->first('SELECT * FROM rag_vector_datasets WHERE id = ?', [$id]);
            if ($dataset === null) {
                return;
            }
            $before = (int) ($this->textConfig($dataset)['id'] ?? 0);
            $after = $this->positiveId($data['text_vector_config_id']);
            $afterEffective = (int) ($this->textConfig([...$dataset, 'text_vector_config_id' => $after])['id'] ?? 0);
            if ($before !== $afterEffective && $this->datasetHasVectors($id, $before)) {
                abort_json('RAG_VECTOR_CONFIG_IN_USE', 'The dataset already has vectors from its current vector config; switch configs with "php bin/rag-index.php reindex --dataset=' . $id . ' --config=' . ($afterEffective ?: '<id>') . '" so they are rebuilt', 409);
            }
        }
        if ($table === 'rag_knowledge_bases' && array_key_exists('default_text_vector_config_id', $data)) {
            $knowledgeBase = App::db()->first('SELECT * FROM rag_knowledge_bases WHERE id = ?', [$id]);
            if ($knowledgeBase === null || (int) ($knowledgeBase['default_text_vector_config_id'] ?? 0) === (int) ($this->positiveId($data['default_text_vector_config_id']) ?? 0)) {
                return;
            }
            foreach (App::db()->select('SELECT * FROM rag_vector_datasets WHERE kb_id = ? AND (text_vector_config_id IS NULL OR text_vector_config_id = 0) AND deleted_at IS NULL', [$id]) as $dataset) {
                $before = (int) ($this->textConfig($dataset)['id'] ?? 0);
                if ($this->datasetHasVectors((int) $dataset['id'], $before)) {
                    abort_json('RAG_VECTOR_CONFIG_IN_USE', 'Dataset ' . $dataset['dataset_id'] . ' inherits the current default vector config and already has vectors; bind it explicitly or rebuild it with "php bin/rag-index.php reindex" before changing the default', 409);
                }
            }
        }
    }

    private function datasetHasVectors(int $datasetId, int $configId): bool
    {
        if ($configId <= 0) {
            return false;
        }

        return App::db()->first('SELECT 1 AS hit FROM rag_vector_records WHERE dataset_id = ? AND vector_config_id = ? AND status = "indexed" LIMIT 1', [$datasetId, $configId]) !== null;
    }

    public static function prefix(mixed $configJson, string $key): string
    {
        $settings = is_array($configJson) ? $configJson : (is_string($configJson) && $configJson !== '' ? (json_decode($configJson, true) ?: []) : []);

        return (string) ($settings[$key] ?? '');
    }

    /** @param array<string, mixed> $data */
    private function allowsLocalFallback(array $data): bool
    {
        $config = $data['config_json'] ?? null;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        return is_array($config) && ($config['allow_local_fallback'] ?? false) === true;
    }

    /** @param list<string> $allowed */
    private function assertAllowed(array $data, string $field, array $allowed): void
    {
        if (!in_array((string) ($data[$field] ?? ''), $allowed, true)) {
            throw new RuntimeException('RAG_VECTOR_CONFIG_INVALID');
        }
    }

    private function positiveId(mixed $value): ?int
    {
        if (!is_numeric($value) || (int) $value < 1) {
            return null;
        }
        return (int) $value;
    }

    public function collection(string $datasetId, string $modality = 'text'): string
    {
        $prefix = (string) App::config()->get('rag.qdrant.collection_prefix', 'tokenhub_rag');
        $safeDataset = preg_replace('/[^A-Za-z0-9_]+/', '_', $datasetId) ?: 'default';
        $safeModality = preg_replace('/[^A-Za-z0-9_]+/', '_', $modality) ?: 'text';
        return $prefix . '_' . $safeDataset . '_' . $safeModality;
    }
}
