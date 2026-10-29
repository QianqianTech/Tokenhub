<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;

/**
 * Removes vectors for chunks from the vector store and marks their records
 * deleted. The database stays authoritative: when the store is unreachable
 * the records are still marked deleted, retrieval already filters inactive
 * chunks (RagRetrievalService rehydration), and the reconcile job removes the
 * leftover points later (RAG-030).
 */
final class RagVectorDeletionService
{
    public function __construct(private readonly ?RagVectorConfigService $configs = null)
    {
    }

    /**
     * Delete vectors and retire the chunks (lifecycle "deleted").
     *
     * @param list<int> $chunkIds
     */
    public function deleteChunks(array $chunkIds): int
    {
        $chunkIds = $this->ids($chunkIds);
        if ($chunkIds === []) {
            return 0;
        }
        $this->deleteVectors($chunkIds);
        App::db()->execute(
            'UPDATE rag_chunks SET enabled = 0, lifecycle_status = "deleted" WHERE id IN (' . $this->placeholders($chunkIds) . ')',
            $chunkIds
        );

        return count($chunkIds);
    }

    /**
     * Delete vectors only; the caller decides the chunk lifecycle.
     *
     * @param list<int> $chunkIds
     * @return int number of vector records marked deleted
     */
    public function deleteVectors(array $chunkIds): int
    {
        $chunkIds = $this->ids($chunkIds);
        if ($chunkIds === []) {
            return 0;
        }
        $records = App::db()->select(
            'SELECT r.vector_id, r.collection_name, r.vector_config_id, c.vector_store
             FROM rag_vector_records r
             LEFT JOIN rag_vector_configs c ON c.id = r.vector_config_id
             WHERE r.chunk_id IN (' . $this->placeholders($chunkIds) . ') AND r.status <> "deleted"',
            $chunkIds
        );

        $byStore = [];
        foreach ($records as $record) {
            $collection = (string) ($record['collection_name'] ?? '');
            if ($collection === '') {
                continue;
            }
            $store = (string) ($record['vector_store'] ?? '') ?: (string) App::config()->get('rag.vector_store.primary', 'qdrant');
            $byStore[$store][$collection][] = (string) $record['vector_id'];
        }

        $configService = $this->configs ?? new RagVectorConfigService();
        foreach ($byStore as $storeName => $collections) {
            try {
                $store = $configService->store(['vector_store' => $storeName]);
                foreach ($collections as $collection => $vectorIds) {
                    $store->delete($collection, $vectorIds);
                }
            } catch (Throwable $throwable) {
                App::logger()->warning('rag', 'rag vector delete deferred to reconcile', ['store' => $storeName, 'error' => $throwable->getMessage()]);
            }
        }

        return App::db()->execute(
            'UPDATE rag_vector_records SET index_status = "deleted", status = "deleted", deleted_at = NOW() WHERE chunk_id IN (' . $this->placeholders($chunkIds) . ') AND status <> "deleted"',
            $chunkIds
        );
    }

    /** @param list<mixed> $ids @return list<int> */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    /** @param list<int> $ids */
    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
