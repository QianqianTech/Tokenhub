<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ExternalFeedbackService
{
    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $apiKey, array $data): array
    {
        $requestId = trim((string) ($data['request_id'] ?? ''));
        if ($requestId !== '') {
            $record = App::db()->first('SELECT id FROM usage_records WHERE request_id = ? AND api_key_id = ?', [$requestId, $apiKey['id'] ?? 0]);
            if ($record === null) {
                abort_json('REQUEST_NOT_FOUND', 'Request not found', 404);
            }
        }
        $rating = isset($data['rating']) ? max(1, min(5, (int) $data['rating'])) : null;
        $tags = is_array($data['tags'] ?? null) ? array_slice(array_values(array_map('strval', $data['tags'])), 0, 20) : [];
        $comment = isset($data['comment']) ? substr(trim((string) $data['comment']), 0, 2000) : null;

        App::db()->execute(
            'INSERT INTO external_feedback (api_key_id, user_id, department_id, request_id, rating, tags, comment)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $apiKey['id'] ?? null,
                $apiKey['user_id'] ?? null,
                $apiKey['department_id'] ?? null,
                $requestId === '' ? null : $requestId,
                $rating,
                json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $comment,
            ]
        );
        $id = (int) App::db()->pdo()->lastInsertId();

        return [
            'id' => $id,
            'request_id' => $requestId === '' ? null : $requestId,
            'rating' => $rating,
            'tags' => $tags,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}

