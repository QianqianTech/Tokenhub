<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagCorrectionService
{
    /**
     * @param array<string, mixed> $retrieval
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createFromFeedback(string $feedbackId, array $retrieval, array $payload): array
    {
        $taskId = 'rct_' . bin2hex(random_bytes(12));
        $rating = isset($payload['rating']) && is_scalar($payload['rating']) ? (string) $payload['rating'] : 'bad';
        $priority = match ($rating) {
            'outdated', 'wrong_source' => 80,
            'missing_context' => 70,
            default => 60,
        };

        App::db()->execute(
            'INSERT INTO rag_correction_tasks (correction_id, task_id, feedback_id, retrieval_id, kb_ids, dataset_ids, issue_type, priority, status, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "open", ?)',
            [
                $taskId,
                $taskId,
                $feedbackId,
                $retrieval['retrieval_id'] ?? null,
                $retrieval['kb_ids'] ?? '[]',
                $retrieval['dataset_ids'] ?? '[]',
                $rating,
                $priority,
                json_encode([
                    'reason' => $payload['reason'] ?? null,
                    'expected_answer' => $payload['expected_answer'] ?? null,
                    'selected_source_ids' => $payload['selected_source_ids'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        return ['task_id' => $taskId, 'status' => 'open'];
    }
}
