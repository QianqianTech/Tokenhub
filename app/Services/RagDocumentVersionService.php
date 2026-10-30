<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Document versions (RAG-025): when an upload finishes indexing, earlier
 * documents in the same dataset with the same document_key are superseded —
 * their chunks leave retrieval and their vectors are deleted. The switch
 * happens only after the new version is fully indexed, so retrieval never
 * goes empty in between.
 */
final class RagDocumentVersionService
{
    /** @return int number of superseded documents */
    public function publish(int $uploadId): int
    {
        $documents = App::db()->select(
            'SELECT id, document_id, dataset_id, document_key FROM rag_documents WHERE upload_id = ? AND deleted_at IS NULL AND document_key IS NOT NULL AND document_key <> "" AND dataset_id IS NOT NULL',
            [$uploadId]
        );
        $retention = new RagRetentionPolicyService();
        $superseded = 0;
        foreach ($documents as $document) {
            if (str_starts_with((string) $document['document_key'], 'upload:')) {
                continue; // no stable identity (template rows): nothing to replace
            }
            $older = App::db()->select(
                // Only earlier documents: an older upload that finishes indexing late never replaces a newer version.
                'SELECT id, document_id, version FROM rag_documents WHERE dataset_id = ? AND document_key = ? AND id < ? AND upload_id <> ? AND deleted_at IS NULL',
                [$document['dataset_id'], $document['document_key'], $document['id'], $uploadId]
            );
            $version = 1;
            foreach ($older as $old) {
                $retention->supersedeDocument(['id' => (int) $document['id']], $old);
                $version = max($version, (int) ($old['version'] ?? 1) + 1);
                $superseded++;
            }
            if ($older !== []) {
                App::db()->execute('UPDATE rag_documents SET version = ? WHERE id = ?', [$version, $document['id']]);
                (new AuditService())->write('rag.document.supersede', 'rag_documents', (int) $document['id'], [
                    'document_key' => $document['document_key'],
                    'superseded' => array_map(static fn (array $row): string => (string) $row['document_id'], $older),
                    'version' => $version,
                ]);
            }
        }

        return $superseded;
    }

    /**
     * Publishes indexed uploads that still have an older live version — the
     * uploads indexed by the Python worker (execution_backend python_local),
     * which cannot call publish() itself. Scheduled as rag.publish-versions.
     *
     * @return array{uploads: int, superseded: int}
     */
    public function publishPending(int $limit = 50): array
    {
        $uploads = App::db()->select(
            'SELECT DISTINCT d.upload_id FROM rag_documents d
             JOIN rag_uploads u ON u.id = d.upload_id
             WHERE u.vectorize_status = "indexed" AND d.deleted_at IS NULL AND d.dataset_id IS NOT NULL
               AND d.document_key IS NOT NULL AND d.document_key <> "" AND d.document_key NOT LIKE "upload:%"
               AND EXISTS (SELECT 1 FROM rag_documents o WHERE o.dataset_id = d.dataset_id AND o.document_key = d.document_key AND o.id < d.id AND o.upload_id <> d.upload_id AND o.deleted_at IS NULL)
             ORDER BY d.upload_id LIMIT ' . max(1, min(500, $limit))
        );
        $superseded = 0;
        foreach ($uploads as $row) {
            $superseded += $this->publish((int) $row['upload_id']);
        }

        return ['uploads' => count($uploads), 'superseded' => $superseded];
    }
}
