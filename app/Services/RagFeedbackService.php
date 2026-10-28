<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagFeedbackService
{
    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $apiKey, array $payload): array
    {
        $retrievalId = isset($payload['retrieval_id']) && is_scalar($payload['retrieval_id']) ? (string) $payload['retrieval_id'] : '';
        $rating = isset($payload['rating']) && is_scalar($payload['rating']) ? (string) $payload['rating'] : '';
        if ($retrievalId === '' || !in_array($rating, ['good', 'bad', 'outdated', 'wrong_source', 'missing_context'], true)) {
            abort_json('RAG_FEEDBACK_INVALID', 'retrieval_id and valid rating are required', 422);
        }

        $retrieval = App::db()->first('SELECT * FROM rag_retrieval_logs WHERE retrieval_id = ? AND api_key_id = ?', [$retrievalId, $apiKey['id'] ?? 0]);
        if ($retrieval === null) {
            abort_json('RAG_RETRIEVAL_NOT_FOUND', 'RAG retrieval not found', 404);
        }

        $feedbackId = 'rfb_' . bin2hex(random_bytes(12));
        App::db()->execute(
            'INSERT INTO rag_answer_feedback (feedback_id, retrieval_id, request_id, api_key_id, user_id, department_id, rating, reason, expected_answer, selected_source_ids, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $feedbackId,
                $retrievalId,
                $retrieval['request_id'] ?? App::context()->requestId(),
                $apiKey['id'] ?? null,
                $apiKey['user_id'] ?? null,
                $apiKey['department_id'] ?? null,
                $rating,
                $this->scalar($payload['reason'] ?? null),
                $this->scalar($payload['expected_answer'] ?? null),
                json_encode($payload['selected_source_ids'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($payload['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        $task = null;
        $evalCase = null;
        if (in_array($rating, ['bad', 'outdated', 'wrong_source', 'missing_context'], true)) {
            $task = (new RagCorrectionService())->createFromFeedback($feedbackId, $retrieval, $payload);
            $evalCase = (new RagRetrievalEvaluationService())->createCandidateFromFeedback($feedbackId, $retrieval, $payload);
        }

        return [
            'feedback_id' => $feedbackId,
            'correction_task_id' => $task['task_id'] ?? null,
            'eval_case_id' => $evalCase['case_id'] ?? null,
        ];
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
