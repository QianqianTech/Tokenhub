<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class LogRetentionPolicyService
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(string $logType, array $data): array
    {
        $type = (new LogService())->normalizeType($logType);
        $min = max($this->defaultMinimum($type), (int) ($data['min_retention_days'] ?? 0));
        $data['min_retention_days'] = $min;

        if (array_key_exists('online_retention_days', $data)) {
            $data['online_retention_days'] = max($min, (int) $data['online_retention_days']);
        }

        if (array_key_exists('archive_retention_days', $data)) {
            $data['archive_retention_days'] = max($min, (int) $data['archive_retention_days']);
        }

        if (array_key_exists('compress_enabled', $data)) {
            $data['compress_enabled'] = (int) filter_var($data['compress_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('encrypt_enabled', $data)) {
            $data['encrypt_enabled'] = (int) filter_var($data['encrypt_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }

    private function defaultMinimum(string $logType): int
    {
        return match ($logType) {
            'audit', 'security' => 180,
            'export' => 90,
            default => 30,
        };
    }
}

