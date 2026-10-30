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
        $dimension = $effective['dimension'] ?? null;
        if (!is_numeric($dimension) || (int) $dimension < 1) {
            throw new RuntimeException('RAG_VECTOR_CONFIG_INVALID');
        }
        if (trim((string) ($effective['collection_name'] ?? '')) === '') {
            throw new RuntimeException('RAG_VECTOR_CONFIG_INVALID');
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
    public function assertBindingReferences(string $table, array $data): void
    {
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
        $vector = ($this->embeddingProvider ?? new RagEmbeddingProviderService())->embed(
            $modality,
            ['text' => 'TokenHub RAG embedding probe'],
            $config,
        );

        return [
            'provider_id' => (int) $config['provider_id'],
            'model_id' => (int) $config['model_id'],
            'model_name' => (string) $config['model_name'],
            'modality' => $modality,
            'dimension' => count($vector),
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
