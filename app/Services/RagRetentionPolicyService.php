<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Applies rag_retention_policies (RAG-007). The previous implementation used
 * columns that do not exist in the schema (status on documents/chunks,
 * expire_after_days, target_type, deleted_at on policies) and failed on every
 * scheduler run.
 *
 * Schema: kb_id / dataset_id (both NULL = platform-wide), expires_after_days,
 * inactive_after_days, action_on_expiry, require_approval, enabled.
 */
final class RagRetentionPolicyService
{
    public function __construct(private readonly ?RagChunkLifecycleService $lifecycle = null)
    {
    }

    /** @return array<string, int> */
    public function cleanupExpired(?int $now = null): array
    {
        $now ??= time();
        $summary = ['policies' => 0, 'marked_expired' => 0, 'disabled' => 0, 'vectors_deleted' => 0, 'documents_deleted' => 0, 'approval_tasks' => 0];
        // Explicit expires_at on documents/chunks applies regardless of policies: retrieval
        // already ignores them, this only keeps the lifecycle column truthful.
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $summary['marked_expired'] += App::db()->execute('UPDATE rag_chunks SET lifecycle_status = "expired" WHERE lifecycle_status = "active" AND expires_at IS NOT NULL AND expires_at <= ?', [$stamp]);

        foreach (App::db()->select('SELECT * FROM rag_retention_policies WHERE enabled = 1 ORDER BY id') as $policy) {
            $summary['policies']++;
            $summary = $this->applyPolicy($policy, $summary, $now);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $document
     * @return array{deleted_vectors: int, deleted_documents: int}
     */
    public function deleteDocument(array $document): array
    {
        $chunkIds = $this->chunkIds((int) $document['id']);
        $deletedVectors = (new RagVectorDeletionService())->deleteChunks($chunkIds);
        App::db()->execute('UPDATE rag_documents SET deleted_at = ?, index_status = "pending" WHERE id = ?', [gmdate('Y-m-d H:i:s'), $document['id']]);

        return ['deleted_vectors' => $deletedVectors, 'deleted_documents' => 1];
    }

    /**
     * @param array<string, mixed> $newDocument
     * @param array<string, mixed> $oldDocument
     * @return array{deleted_vectors: int, superseded_document_id: string}
     */
    public function supersedeDocument(array $newDocument, array $oldDocument): array
    {
        $deletedVectors = ($this->lifecycle ?? new RagChunkLifecycleService())->disable($this->chunkIds((int) $oldDocument['id']), 'superseded');
        App::db()->execute(
            'UPDATE rag_documents SET superseded_by_document_id = ?, deleted_at = ? WHERE id = ?',
            [$newDocument['id'], gmdate('Y-m-d H:i:s'), $oldDocument['id']]
        );

        return ['deleted_vectors' => $deletedVectors, 'superseded_document_id' => (string) $oldDocument['document_id']];
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, int> $summary
     * @return array<string, int>
     */
    private function applyPolicy(array $policy, array $summary, int $now): array
    {
        $conditions = ['doc.deleted_at IS NULL'];
        $params = [];
        if (!empty($policy['dataset_id'])) {
            $conditions[] = 'doc.dataset_id = ?';
            $params[] = (int) $policy['dataset_id'];
        } elseif (!empty($policy['kb_id'])) {
            $conditions[] = 'doc.kb_id = ?';
            $params[] = (int) $policy['kb_id'];
        }
        $age = [];
        if (!empty($policy['expires_after_days'])) {
            $age[] = 'doc.created_at < ?';
            $params[] = gmdate('Y-m-d H:i:s', $now - max(1, (int) $policy['expires_after_days']) * 86400);
        }
        if (!empty($policy['inactive_after_days'])) {
            $age[] = 'doc.updated_at < ?';
            $params[] = gmdate('Y-m-d H:i:s', $now - max(1, (int) $policy['inactive_after_days']) * 86400);
        }
        $age[] = '(doc.expires_at IS NOT NULL AND doc.expires_at <= ?)';
        $params[] = gmdate('Y-m-d H:i:s', $now);
        $conditions[] = '(' . implode(' OR ', $age) . ')';

        $documents = App::db()->select(
            'SELECT doc.* FROM rag_documents doc WHERE ' . implode(' AND ', $conditions)
            . ' AND EXISTS (SELECT 1 FROM rag_chunks c WHERE c.document_id = doc.id AND c.lifecycle_status = "active" AND c.enabled = 1) ORDER BY doc.id LIMIT 500',
            $params
        );
        $action = (string) ($policy['action_on_expiry'] ?? 'mark_expired');
        foreach ($documents as $document) {
            if ((int) ($policy['require_approval'] ?? 1) === 1) {
                $summary['approval_tasks'] += $this->approvalTask($policy, $document, $action);
                continue;
            }
            $summary = $this->applyAction($action, $document, $summary);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, int> $summary
     * @return array<string, int>
     */
    public function applyAction(string $action, array $document, array $summary = []): array
    {
        $chunkIds = $this->chunkIds((int) $document['id']);
        $summary += ['marked_expired' => 0, 'disabled' => 0, 'vectors_deleted' => 0, 'documents_deleted' => 0];
        if ($chunkIds === []) {
            return $summary;
        }
        $placeholders = implode(',', array_fill(0, count($chunkIds), '?'));
        switch ($action) {
            case 'disable_retrieval':
                App::db()->execute('UPDATE rag_chunks SET enabled = 0 WHERE id IN (' . $placeholders . ')', $chunkIds);
                $summary['disabled'] += count($chunkIds);
                break;
            case 'delete_vectors':
                $summary['vectors_deleted'] += ($this->lifecycle ?? new RagChunkLifecycleService())->disable($chunkIds, 'expired');
                break;
            case 'delete_document':
                $result = $this->deleteDocument($document);
                $summary['vectors_deleted'] += $result['deleted_vectors'];
                $summary['documents_deleted'] += $result['deleted_documents'];
                break;
            default:
                App::db()->execute('UPDATE rag_chunks SET lifecycle_status = "expired" WHERE id IN (' . $placeholders . ') AND lifecycle_status = "active"', $chunkIds);
                $summary['marked_expired'] += count($chunkIds);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $document
     */
    private function approvalTask(array $policy, array $document, string $action): int
    {
        $open = App::db()->first(
            'SELECT id FROM rag_correction_tasks WHERE source_type = "expiry" AND target_document_id = ? AND status IN ("open","pending","approved","running") LIMIT 1',
            [$document['id']]
        );
        if ($open !== null) {
            return 0;
        }
        $correctionAction = match ($action) {
            'delete_document' => 'delete_document',
            default => 'disable_chunk',
        };
        App::db()->execute(
            'INSERT INTO rag_correction_tasks (correction_id, kb_id, dataset_id, issue_type, source_type, action, target_document_id, status, reason, details) VALUES (?, ?, ?, "retention", "expiry", ?, ?, "pending", ?, ?)',
            [
                'cor_' . bin2hex(random_bytes(12)),
                $document['kb_id'],
                $document['dataset_id'] ?? null,
                $correctionAction,
                $document['id'],
                'Retention policy ' . (string) ($policy['name'] ?? $policy['policy_id'] ?? '') . ' matched this document',
                json_encode(['policy_id' => $policy['policy_id'] ?? null, 'action_on_expiry' => $action], JSON_UNESCAPED_UNICODE),
            ]
        );

        return 1;
    }

    /** @return list<int> */
    private function chunkIds(int $documentId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            App::db()->select('SELECT id FROM rag_chunks WHERE document_id = ? AND lifecycle_status <> "deleted"', [$documentId])
        );
    }
}
