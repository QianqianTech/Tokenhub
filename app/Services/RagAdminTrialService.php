<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagAdminTrialService
{
    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function retrieve(array $admin, array $input): array
    {
        $permission = new PermissionService();
        $error = $permission->requireAdmin($admin);
        if ($error !== null) {
            abort_json($error['code'], $error['message'], $error['status']);
        }

        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            abort_json('RAG_QUERY_REQUIRED', 'query is required', 422);
        }

        $datasetId = trim((string) ($input['dataset_id'] ?? ''));
        if ($datasetId === '') {
            abort_json('RAG_DATASET_REQUIRED', 'dataset_id is required', 422);
        }

        $dataset = App::db()->first(
            'SELECT d.*, k.kb_id AS external_kb_id, k.department_id, k.retrieval_profile AS kb_retrieval_profile
             FROM rag_vector_datasets d
             JOIN rag_knowledge_bases k ON k.id = d.kb_id
             WHERE d.dataset_id = ? AND d.deleted_at IS NULL AND d.status = "active"',
            [$datasetId]
        );
        if ($dataset === null) {
            abort_json('RAG_DATASET_NOT_FOUND', 'Vector dataset not found', 404);
        }
        if (!$permission->canManage($admin, (int) $dataset['department_id'])) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Dataset is outside admin scope', 403);
        }

        $topK = max(1, min(20, (int) ($input['top_k'] ?? 5)));
        $queryModality = (string) ($input['query_modality'] ?? 'text');
        if (!in_array($queryModality, ['text', 'image', 'multimodal'], true)) {
            abort_json('RAG_QUERY_MODALITY_INVALID', 'query_modality must be text, image, or multimodal', 422);
        }

        $startedAt = microtime(true);
        $sources = (new RagRetrievalService())->searchSourcesForDatasets([$dataset], $query, $topK, $queryModality);

        return [
            'query' => $query,
            'query_modality' => $queryModality,
            'top_k' => $topK,
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'fallback_used' => false,
            'dataset' => [
                'dataset_id' => (string) $dataset['dataset_id'],
                'name' => (string) $dataset['name'],
                'kb_id' => (string) $dataset['external_kb_id'],
            ],
            'sources' => $sources,
        ];
    }
}
