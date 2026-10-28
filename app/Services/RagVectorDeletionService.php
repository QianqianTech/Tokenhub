<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagVectorDeletionService
{
    /**
     * @param list<int> $chunkIds
     */
    public function deleteChunks(array $chunkIds): int
    {
        $chunkIds = array_values(array_unique(array_filter(array_map('intval', $chunkIds))));
        if ($chunkIds === []) {
            return 0;
        }

        $records = App::db()->select(
            'SELECT r.*, d.dataset_id, d.name AS dataset_name
             FROM rag_vector_records r
             JOIN rag_vector_datasets d ON d.id = r.dataset_id
             WHERE r.chunk_id IN (' . implode(',', array_fill(0, count($chunkIds), '?')) . ') AND r.status <> "deleted"',
            $chunkIds
        );

        $byCollection = [];
        foreach ($records as $record) {
            $collection = (string) ($record['collection_name'] ?? '');
            if ($collection === '') {
                continue;
            }
            $byCollection[$collection][] = (string) $record['vector_id'];
        }

        $configService = new RagVectorConfigService();
        foreach ($byCollection as $collection => $vectorIds) {
            $configService->store(['vector_store' => 'qdrant'])->delete($collection, $vectorIds);
        }

        App::db()->execute(
            'UPDATE rag_vector_records SET index_status = "deleted", status = "deleted", deleted_at = NOW() WHERE chunk_id IN (' . implode(',', array_fill(0, count($chunkIds), '?')) . ')',
            $chunkIds
        );
        App::db()->execute(
            'UPDATE rag_chunks SET enabled = 0, lifecycle_status = "deleted" WHERE id IN (' . implode(',', array_fill(0, count($chunkIds), '?')) . ')',
            $chunkIds
        );

        return count($chunkIds);
    }
}
