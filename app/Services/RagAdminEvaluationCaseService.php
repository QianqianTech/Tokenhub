<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagAdminEvaluationCaseService
{
    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $admin, array $input): array
    {
        $dataset = $this->managedDataset($admin, (string) ($input['dataset_id'] ?? ''));
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            abort_json('RAG_EVAL_QUERY_REQUIRED', 'query is required', 422);
        }
        $expectedChunkIds = $this->expectedChunkIds($input['expected_chunk_ids'] ?? [], (int) $dataset['kb_id']);
        $status = $this->status($input['status'] ?? 'draft');
        $caseId = 'eval_' . bin2hex(random_bytes(12));
        App::db()->execute(
            'INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, query_modality, expected_chunk_ids, status, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $caseId,
                $dataset['kb_id'],
                $dataset['id'],
                $query,
                $this->queryModality($input['query_modality'] ?? 'text'),
                json_encode($expectedChunkIds, JSON_UNESCAPED_UNICODE),
                $status,
                json_encode(array_filter([
                    'created_by' => 'admin_workspace',
                    'expected_anchors' => (new RagEvaluationAnchorService())->anchorsFor($expectedChunkIds),
                    // RAG-041: optional reference answer for the answer-level judge.
                    'expected_answer' => is_string($input['expected_answer'] ?? null) && trim($input['expected_answer']) !== '' ? mb_substr(trim($input['expected_answer']), 0, 4000) : null,
                ], static fn ($value): bool => $value !== null), JSON_UNESCAPED_UNICODE),
            ]
        );

        return ['case_id' => $caseId, 'status' => $status];
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function updateStatus(array $admin, string $caseId, mixed $status): array
    {
        $case = $this->managedCase($admin, $caseId);
        $nextStatus = $this->status($status);
        App::db()->execute('UPDATE rag_eval_cases SET status = ? WHERE id = ?', [$nextStatus, $case['id']]);

        return ['case_id' => (string) $case['case_id'], 'status' => $nextStatus];
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function import(array $admin, array $input): array
    {
        $rows = $input['rows'] ?? [];
        if (!is_array($rows) || $rows === []) {
            abort_json('RAG_EVAL_IMPORT_ROWS_REQUIRED', 'rows is required', 422);
        }

        $created = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $errors[] = ['row' => $index + 1, 'message' => 'row must be an object'];
                continue;
            }
            try {
                $created[] = $this->create($admin, $row);
            } catch (\Throwable $error) {
                $errors[] = ['row' => $index + 1, 'message' => $error->getMessage()];
            }
        }

        return [
            'created_count' => count($created),
            'failed_count' => count($errors),
            'created' => $created,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function createCorrectionFromFailure(array $admin, string $runId, string $caseId): array
    {
        $case = $this->managedCase($admin, $caseId);
        $failure = App::db()->first(
            'SELECT er.hit_rank, er.expected_chunk_ids, er.sources
             FROM rag_eval_results er
             JOIN rag_eval_runs r ON r.id = er.run_id
             WHERE r.run_id = ? AND er.case_id = ? AND er.passed = 0
             ORDER BY er.id DESC LIMIT 1',
            [$runId, $case['id']]
        );
        if ($failure === null) {
            abort_json('RAG_EVAL_FAILURE_NOT_FOUND', 'Failed evaluation result not found', 404);
        }

        $taskId = 'rct_' . bin2hex(random_bytes(12));
        App::db()->execute(
            'INSERT INTO rag_correction_tasks (correction_id, task_id, kb_id, dataset_id, kb_ids, dataset_ids, issue_type, source_type, action, status, priority, reason, details) VALUES (?, ?, ?, ?, ?, ?, "retrieval_miss", "admin", "update_content", "open", 80, ?, ?)',
            [
                $taskId,
                $taskId,
                $case['kb_id'],
                $case['dataset_id'],
                json_encode([(string) $case['external_kb_id']], JSON_UNESCAPED_UNICODE),
                json_encode([(string) $case['external_dataset_id']], JSON_UNESCAPED_UNICODE),
                'Evaluation failure: ' . (string) $case['query'],
                json_encode([
                    'run_id' => $runId,
                    'case_id' => (string) $case['case_id'],
                    'query' => (string) $case['query'],
                    'expected_chunk_ids' => json_decode((string) ($failure['expected_chunk_ids'] ?? '[]'), true) ?: [],
                    'hit_rank' => $failure['hit_rank'] === null ? null : (int) $failure['hit_rank'],
                    'sources' => json_decode((string) ($failure['sources'] ?? '[]'), true) ?: [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        return ['task_id' => $taskId, 'status' => 'open'];
    }

    /** @return array<string, mixed> */
    private function managedDataset(array $admin, string $datasetId): array
    {
        $this->requireAdmin($admin);
        $dataset = App::db()->first(
            'SELECT d.*, k.department_id, k.kb_id AS external_kb_id
             FROM rag_vector_datasets d
             JOIN rag_knowledge_bases k ON k.id = d.kb_id
             WHERE d.dataset_id = ? AND d.deleted_at IS NULL',
            [trim($datasetId)]
        );
        if ($dataset === null) {
            abort_json('RAG_DATASET_NOT_FOUND', 'Vector dataset not found', 404);
        }
        if (!(new PermissionService())->canManage($admin, (int) $dataset['department_id'])) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Dataset is outside admin scope', 403);
        }

        return $dataset;
    }

    /** @return array<string, mixed> */
    private function managedCase(array $admin, string $caseId): array
    {
        $this->requireAdmin($admin);
        $case = App::db()->first(
            'SELECT c.*, d.dataset_id AS external_dataset_id, k.kb_id AS external_kb_id, k.department_id
             FROM rag_eval_cases c
             JOIN rag_vector_datasets d ON d.id = c.dataset_id
             JOIN rag_knowledge_bases k ON k.id = c.kb_id
             WHERE c.case_id = ?',
            [trim($caseId)]
        );
        if ($case === null) {
            abort_json('RAG_EVAL_CASE_NOT_FOUND', 'Evaluation case not found', 404);
        }
        if (!(new PermissionService())->canManage($admin, (int) $case['department_id'])) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Evaluation case is outside admin scope', 403);
        }

        return $case;
    }

    /** @return list<string> */
    private function expectedChunkIds(mixed $value, int $kbId): array
    {
        $items = is_array($value) ? $value : [];
        $chunkIds = array_values(array_unique(array_filter(array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $items))));
        if ($chunkIds === []) {
            abort_json('RAG_EVAL_EXPECTED_CHUNKS_REQUIRED', 'expected_chunk_ids is required', 422);
        }
        $rows = App::db()->select(
            'SELECT chunk_id FROM rag_chunks WHERE kb_id = ? AND chunk_id IN (' . implode(',', array_fill(0, count($chunkIds), '?')) . ')',
            [$kbId, ...$chunkIds]
        );
        if (count($rows) !== count($chunkIds)) {
            abort_json('RAG_EVAL_EXPECTED_CHUNK_NOT_FOUND', 'Expected chunk must belong to the selected knowledge base', 422);
        }

        return $chunkIds;
    }

    private function queryModality(mixed $value): string
    {
        $modality = (string) $value;
        if (!in_array($modality, ['text', 'image', 'multimodal'], true)) {
            abort_json('RAG_QUERY_MODALITY_INVALID', 'query_modality must be text, image, or multimodal', 422);
        }

        return $modality;
    }

    private function status(mixed $value): string
    {
        $status = (string) $value;
        if (!in_array($status, ['draft', 'active', 'disabled'], true)) {
            abort_json('RAG_EVAL_CASE_STATUS_INVALID', 'status must be draft, active, or disabled', 422);
        }

        return $status;
    }

    private function requireAdmin(array $admin): void
    {
        $error = (new PermissionService())->requireAdmin($admin);
        if ($error !== null) {
            abort_json($error['code'], $error['message'], $error['status']);
        }
    }
}
