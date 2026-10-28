<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RecentGenerationService
{
    /**
     * @param list<mixed> $scopeBindings
     * @return list<array<string, mixed>>
     */
    public function fromUsageRecords(string $scopeWhere, array $scopeBindings, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return App::db()->select(
            "SELECT r.request_id task_id,
                    r.capability task_type,
                    CASE WHEN r.status = 'success' THEN 'succeeded' ELSE r.status END status,
                    CASE WHEN r.status = 'success' THEN 100 ELSE 0 END progress,
                    NULL submit_payload,
                    r.created_at,
                    r.user_id,
                    r.department_id,
                    COALESCE(u.username, CONCAT('用户 ', r.user_id)) user_name,
                    COALESCE(d.name, CONCAT('部门 ', r.department_id)) department_name,
                    COALESCE(m.name, r.model_name, CONCAT('模型 ', r.model_id)) model_name
             FROM usage_records r
             LEFT JOIN models m ON m.id = r.model_id
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN departments d ON d.id = r.department_id
             WHERE r.capability IN ('text_generation','vision_understanding','asr','tts','image_generation','video_generation'){$scopeWhere}
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT {$limit}",
            $scopeBindings
        );
    }
}
