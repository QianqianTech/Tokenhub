<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagBindingService
{
    public function grantKnowledgeBase(int $kbId, string $subjectType, int $subjectId, string $permission = 'read'): void
    {
        $this->assertBinding($subjectType, $subjectId, $permission);
        $existing = App::db()->first(
            'SELECT id FROM rag_knowledge_base_bindings WHERE kb_id = ? AND subject_type = ? AND subject_id = ?',
            [$kbId, $subjectType, $subjectId]
        );
        if ($existing === null) {
            App::db()->execute(
                'INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (?, ?, ?, ?)',
                [$kbId, $subjectType, $subjectId, $permission]
            );
        } else {
            App::db()->execute('UPDATE rag_knowledge_base_bindings SET permission = ? WHERE id = ?', [$permission, $existing['id']]);
        }
        foreach (App::db()->select('SELECT id FROM rag_vector_datasets WHERE kb_id = ? AND deleted_at IS NULL', [$kbId]) as $dataset) {
            $this->grantDatasetRow((int) $dataset['id'], $subjectType, $subjectId, $permission);
        }
    }

    public function grantDataset(int $datasetId, string $subjectType, int $subjectId, string $permission = 'read'): void
    {
        $this->assertBinding($subjectType, $subjectId, $permission);
        $dataset = App::db()->first('SELECT kb_id FROM rag_vector_datasets WHERE id = ? AND deleted_at IS NULL', [$datasetId]);
        if ($dataset === null) {
            abort_json('RAG_DATASET_NOT_FOUND', 'Vector dataset not found', 404);
        }
        $this->grantKnowledgeBase((int) $dataset['kb_id'], $subjectType, $subjectId, $permission);
        $this->grantDatasetRow($datasetId, $subjectType, $subjectId, $permission);
    }

    private function grantDatasetRow(int $datasetId, string $subjectType, int $subjectId, string $permission): void
    {
        $existing = App::db()->first(
            'SELECT id FROM rag_dataset_bindings WHERE dataset_id = ? AND subject_type = ? AND subject_id = ?',
            [$datasetId, $subjectType, $subjectId]
        );
        if ($existing === null) {
            App::db()->execute(
                'INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission) VALUES (?, ?, ?, ?)',
                [$datasetId, $subjectType, $subjectId, $permission]
            );
            return;
        }
        App::db()->execute('UPDATE rag_dataset_bindings SET permission = ? WHERE id = ?', [$permission, $existing['id']]);
    }

    private function assertBinding(string $subjectType, int $subjectId, string $permission): void
    {
        if (!in_array($subjectType, ['api_key', 'user', 'department'], true) || $subjectId <= 0 || !in_array($permission, ['read', 'write', 'admin'], true)) {
            abort_json('RAG_BINDING_INVALID', 'Invalid RAG binding', 422);
        }
    }
}
