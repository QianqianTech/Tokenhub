<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;

/**
 * Candidate generation for keyword retrieval (RAG-032). Instead of loading
 * every chunk of a dataset into PHP on each query, candidates come from:
 *   1. the Qdrant sparse lexical collection (BM25-like, same filters), or
 *   2. a MySQL FULLTEXT (ngram) index on rag_chunks(title, content), or
 *   3. a bounded scan of the newest chunks (small installs without either).
 * The existing PHP lexical scorer then ranks only these candidates, so the
 * confidence thresholds keep their meaning.
 */
final class RagLexicalIndexService
{
    /** @var array<string, bool> */
    private static array $fulltext = [];

    public function __construct(private readonly ?RagVectorConfigService $configs = null)
    {
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array{mode: string, chunk_ids: list<string>|null}
     */
    public function candidates(array $dataset, string $query, int $limit): array
    {
        $configService = $this->configs ?? new RagVectorConfigService();
        $config = is_array($dataset['_text_config_override'] ?? null) ? $dataset['_text_config_override'] : $configService->textConfig($dataset);
        if ((string) ($config['vector_store'] ?? '') === 'qdrant') {
            try {
                $store = $configService->store($config);
                $collection = (string) ($dataset['_collection_override'] ?? '') ?: (string) ($config['collection_name'] ?? $configService->collection((string) $dataset['dataset_id'], 'text'));
                $ready = $this->lexicalStatus($dataset) === 'ready';
                if ($ready && $store instanceof QdrantVectorStore && $store->hasLexicalIndex($collection)) {
                    $hits = $store->searchLexical($collection, $query, $limit, ['dataset_id' => (string) $dataset['dataset_id']]);
                    return ['mode' => 'qdrant_sparse', 'chunk_ids' => array_values(array_unique(array_filter(array_map(static fn (array $hit): string => (string) ($hit['payload']['chunk_id'] ?? ''), $hits))))];
                }
                if (!empty($dataset['_collection_override'])) {
                    // Evaluating a candidate index: never fall back to the database scan, or a broken
                    // candidate would look as good as the live index.
                    return ['mode' => 'qdrant_sparse', 'chunk_ids' => []];
                }
            } catch (Throwable $throwable) {
                if (!empty($dataset['_collection_override'])) {
                    return ['mode' => 'qdrant_sparse', 'chunk_ids' => []];
                }
                App::logger()->warning('rag', 'rag lexical index unavailable, using bounded scan', ['error' => $throwable->getMessage()]);
            }
        }
        if ($this->hasFulltext()) {
            try {
                $rows = App::db()->select(
                    'SELECT c.chunk_id FROM rag_chunks c
                     WHERE c.dataset_id = ? AND c.enabled = 1 AND c.lifecycle_status = "active"
                       AND MATCH(c.title, c.content) AGAINST (? IN NATURAL LANGUAGE MODE)
                     ORDER BY MATCH(c.title, c.content) AGAINST (? IN NATURAL LANGUAGE MODE) DESC
                     LIMIT ' . max(1, $limit),
                    [(int) $dataset['id'], $query, $query]
                );
                if ($rows !== [] || $this->datasetIsBackfilled((int) $dataset['id'])) {
                    return ['mode' => 'mysql_fulltext', 'chunk_ids' => array_map(static fn (array $row): string => (string) $row['chunk_id'], $rows)];
                }
            } catch (Throwable $throwable) {
                App::logger()->warning('rag', 'rag fulltext query failed, using bounded scan', ['error' => $throwable->getMessage()]);
            }
        }

        return ['mode' => 'scan', 'chunk_ids' => null];
    }

    /** @param array<string, mixed> $dataset */
    private function lexicalStatus(array $dataset): string
    {
        if (!empty($dataset['_collection_override'])) {
            return 'ready';
        }
        if (array_key_exists('lexical_index_status', $dataset)) {
            return (string) $dataset['lexical_index_status'];
        }
        try {
            return (string) (App::db()->first('SELECT lexical_index_status FROM rag_vector_datasets WHERE id = ?', [(int) ($dataset['id'] ?? 0)])['lexical_index_status'] ?? 'none');
        } catch (Throwable) {
            return 'none';
        }
    }

    public static function scanLimit(): int
    {
        return max(100, (int) env_value('RAG_LEXICAL_SCAN_LIMIT', 5000));
    }

    private function hasFulltext(): bool
    {
        $key = 'rag_chunks';
        if (!array_key_exists($key, self::$fulltext)) {
            try {
                $driver = App::db()->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
                self::$fulltext[$key] = $driver === 'mysql' && App::db()->first(
                    "SELECT 1 AS present FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'rag_chunks' AND index_type = 'FULLTEXT' LIMIT 1"
                ) !== null;
            } catch (Throwable) {
                self::$fulltext[$key] = false;
            }
        }

        return self::$fulltext[$key];
    }

    /** Rows indexed before dataset_id existed on chunks are not in the fulltext path. */
    private function datasetIsBackfilled(int $datasetId): bool
    {
        return App::db()->first('SELECT 1 AS present FROM rag_chunks WHERE dataset_id = ? LIMIT 1', [$datasetId]) !== null;
    }
}
