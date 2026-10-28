<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagRetentionPolicyService
{
    public function cleanupExpired(): array
    {
        $policies = App::db()->select('SELECT * FROM rag_retention_policies WHERE enabled = 1 AND deleted_at IS NULL');
        $summary = ['marked_expired' => 0, 'disabled' => 0, 'vectors_deleted' => 0, 'documents_deleted' => 0];
        foreach ($policies as $policy) {
            $summary = $this->applyPolicy($policy, $summary);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $document
     */
    public function deleteDocument(array $document): array
    {
        $chunks = App::db()->select('SELECT id FROM rag_chunks WHERE document_id = ? AND status <> "deleted"', [$document['id']]);
        $deletedVectors = (new RagVectorDeletionService())->deleteChunks(array_map(static fn (array $row): int => (int) $row['id'], $chunks));
        App::db()->execute('UPDATE rag_documents SET status = "deleted", deleted_at = NOW() WHERE id = ?', [$document['id']]);

        return ['deleted_vectors' => $deletedVectors, 'deleted_documents' => 1];
    }

    /**
     * @param array<string, mixed> $newDocument
     * @param array<string, mixed> $oldDocument
     */
    public function supersedeDocument(array $newDocument, array $oldDocument): array
    {
        $chunks = App::db()->select('SELECT id FROM rag_chunks WHERE document_id = ? AND status <> "deleted"', [$oldDocument['id']]);
        $deletedVectors = (new RagVectorDeletionService())->deleteChunks(array_map(static fn (array $row): int => (int) $row['id'], $chunks));
        App::db()->execute('UPDATE rag_documents SET status = "superseded", superseded_by_document_id = ?, deleted_at = NOW() WHERE id = ?', [$newDocument['id'], $oldDocument['id']]);

        return ['deleted_vectors' => $deletedVectors, 'superseded_document_id' => $oldDocument['document_id']];
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, int> $summary
     * @return array<string, int>
     */
    private function applyPolicy(array $policy, array $summary): array
    {
        $days = max(1, (int) ($policy['expire_after_days'] ?? 365));
        $action = (string) ($policy['action'] ?? 'mark_expired');
        $target = (string) ($policy['target_type'] ?? 'dataset');
        $targetId = (string) ($policy['target_id'] ?? '');

        $where = 'created_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params = [$days];
        if ($target === 'knowledge_base' && $targetId !== '') {
            $kb = App::db()->first('SELECT id FROM rag_knowledge_bases WHERE kb_id = ?', [$targetId]);
            if ($kb === null) {
                return $summary;
            }
            $where .= ' AND kb_id = ?';
            $params[] = $kb['id'];
        } elseif ($target === 'dataset' && $targetId !== '') {
            $dataset = App::db()->first('SELECT id FROM rag_vector_datasets WHERE dataset_id = ?', [$targetId]);
            if ($dataset === null) {
                return $summary;
            }
            $where .= ' AND dataset_id = ?';
            $params[] = $dataset['id'];
        }

        if ($action === 'mark_expired') {
            App::db()->execute("UPDATE rag_documents SET status = 'expired' WHERE status = 'active' AND {$where}", $params);
            $summary['marked_expired']++;
            return $summary;
        }

        if ($action === 'disable_retrieval') {
            App::db()->execute("UPDATE rag_chunks SET status = 'disabled' WHERE status = 'active' AND {$where}", $params);
            $summary['disabled']++;
            return $summary;
        }

        $documents = App::db()->select("SELECT * FROM rag_documents WHERE status <> 'deleted' AND {$where}", $params);
        foreach ($documents as $document) {
            if ($action === 'delete_vectors') {
                $chunks = App::db()->select('SELECT id FROM rag_chunks WHERE document_id = ? AND status <> "deleted"', [$document['id']]);
                $summary['vectors_deleted'] += (new RagVectorDeletionService())->deleteChunks(array_map(static fn (array $row): int => (int) $row['id'], $chunks));
            } elseif ($action === 'delete_document') {
                $result = $this->deleteDocument($document);
                $summary['vectors_deleted'] += (int) $result['deleted_vectors'];
                $summary['documents_deleted'] += (int) $result['deleted_documents'];
            }
        }

        return $summary;
    }
}
