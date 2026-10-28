<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ModelDisplayNameService
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function backfillMissingNames(array $rows): array
    {
        $modelIds = [];
        foreach ($rows as $row) {
            $modelId = isset($row['model_id']) ? (int) $row['model_id'] : 0;
            if ($modelId > 0 && (string) ($row['configured_model_name'] ?? '') === '') {
                $modelIds[] = $modelId;
            }
        }

        $usageNames = $this->latestUsageNames($modelIds);
        return array_map(static function (array $row) use ($usageNames): array {
            $modelId = isset($row['model_id']) ? (int) $row['model_id'] : 0;
            if ($modelId > 0 && (string) ($row['configured_model_name'] ?? '') === '' && isset($usageNames[$modelId])) {
                $row['model_name'] = $usageNames[$modelId];
            }
            unset($row['configured_model_name']);
            return $row;
        }, $rows);
    }

    /**
     * @param list<int> $modelIds
     * @return array<int, string>
     */
    private function latestUsageNames(array $modelIds): array
    {
        $modelIds = array_values(array_unique(array_filter($modelIds, static fn (int $id): bool => $id > 0)));
        if ($modelIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($modelIds), '?'));
        $rows = App::db()->select(
            "SELECT r.model_id, r.model_name
             FROM usage_records r
             JOIN (
                 SELECT model_id, MAX(id) latest_id
                 FROM usage_records
                 WHERE model_id IN ({$placeholders})
                   AND model_name IS NOT NULL
                   AND model_name <> ''
                 GROUP BY model_id
             ) latest ON latest.latest_id = r.id",
            $modelIds
        );

        $names = [];
        foreach ($rows as $row) {
            $modelId = (int) ($row['model_id'] ?? 0);
            $modelName = trim((string) ($row['model_name'] ?? ''));
            if ($modelId > 0 && $modelName !== '') {
                $names[$modelId] = $modelName;
            }
        }
        return $names;
    }
}
