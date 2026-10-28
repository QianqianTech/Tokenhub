<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ExternalQuotaService
{
    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function current(array $apiKey): array
    {
        $policies = App::db()->select(
            'SELECT p.*, b.target_type, b.target_id
             FROM quota_policies p
             JOIN quota_bindings b ON b.quota_policy_id = p.id
             WHERE p.status = "active" AND (
                (b.target_type = "api_key" AND b.target_id = ?)
                OR (b.target_type = "user" AND b.target_id = ?)
                OR (b.target_type = "department" AND b.target_id = ?)
             )
             ORDER BY FIELD(b.target_type, "api_key", "user", "department"), p.id ASC',
            [$apiKey['id'] ?? 0, $apiKey['user_id'] ?? 0, $apiKey['department_id'] ?? 0]
        );
        $modelQuotas = App::db()->select(
            'SELECT q.*, m.name AS model_name
             FROM api_key_model_quotas q
             JOIN models m ON m.id = q.model_id
             WHERE q.api_key_id = ? AND q.status = "active"
             ORDER BY m.name ASC, q.period ASC',
            [$apiKey['id'] ?? 0]
        );

        return [
            'policies' => array_map(fn (array $row): array => $this->policy($row), $policies),
            'per_model' => array_map(fn (array $row): array => $this->modelQuota($row), $modelQuotas),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function policy(array $row): array
    {
        $window = $this->periodWindow((string) ($row['period'] ?? 'day'));
        $scope = (string) $row['target_type'] . ':' . (int) $row['target_id'];
        $usage = $this->usage('quota:' . (int) $row['id'] . ':' . $scope, $window, ['requests', 'input_tokens', 'output_tokens', 'total_tokens', 'audio_seconds', 'tts_chars', 'image_count', 'video_seconds', 'cost_amount']);

        return [
            'policy_id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'target_type' => (string) $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'period' => (string) $row['period'],
            'reset_at' => $window['reset_at'],
            'limits' => $this->limits($row),
            'usage' => $usage,
            'remaining' => $this->remaining($this->limits($row), $usage),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function modelQuota(array $row): array
    {
        $window = $this->periodWindow((string) ($row['period'] ?? 'day'));
        $prefix = 'model_quota:' . (int) $row['id'] . ':api_key:' . (int) $row['api_key_id'] . ':model:' . (int) $row['model_id'];
        $usage = $this->usage($prefix, $window, ['requests', 'input_tokens', 'output_tokens', 'total_tokens']);
        $limits = $this->limits($row);

        return [
            'quota_id' => (int) $row['id'],
            'model_id' => (int) $row['model_id'],
            'model_name' => (string) $row['model_name'],
            'period' => (string) $row['period'],
            'reset_at' => $window['reset_at'],
            'limits' => $limits,
            'usage' => $usage,
            'remaining' => $this->remaining($limits, $usage),
        ];
    }

    /**
     * @return array{bucket: string, reset_at: string}
     */
    private function periodWindow(string $period): array
    {
        $time = time();
        return match ($period) {
            'minute' => ['bucket' => gmdate('YmdHi', $time), 'reset_at' => gmdate('c', ((int) floor($time / 60) + 1) * 60)],
            'hour' => ['bucket' => gmdate('YmdH', $time), 'reset_at' => gmdate('c', ((int) floor($time / 3600) + 1) * 3600)],
            'month' => ['bucket' => gmdate('Ym', $time), 'reset_at' => gmdate('c', strtotime('first day of next month 00:00:00 UTC') ?: $time)],
            default => ['bucket' => gmdate('Ymd', $time), 'reset_at' => gmdate('c', strtotime('tomorrow 00:00:00 UTC') ?: $time)],
        };
    }

    /**
     * @param list<string> $metrics
     * @return array<string, int|float>
     */
    private function usage(string $prefix, array $window, array $metrics): array
    {
        $usage = [];
        foreach ($metrics as $metric) {
            $keyMetric = $metric === 'request_count' ? 'requests' : $metric;
            $value = App::redis()->get($prefix . ':' . $keyMetric . ':' . $window['bucket']);
            $usage[$metric === 'requests' ? 'request_count' : $metric] = is_numeric($value) ? (float) $value : 0;
        }
        return $usage;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int|float|null>
     */
    private function limits(array $row): array
    {
        return [
            'request_count' => $row['request_limit'] === null ? null : (int) $row['request_limit'],
            'input_tokens' => $row['input_token_limit'] === null ? null : (int) $row['input_token_limit'],
            'output_tokens' => $row['output_token_limit'] === null ? null : (int) $row['output_token_limit'],
            'total_tokens' => $row['total_token_limit'] === null ? null : (int) $row['total_token_limit'],
            'audio_seconds' => array_key_exists('audio_second_limit', $row) && $row['audio_second_limit'] !== null ? (int) $row['audio_second_limit'] : null,
            'tts_chars' => array_key_exists('tts_char_limit', $row) && $row['tts_char_limit'] !== null ? (int) $row['tts_char_limit'] : null,
            'image_count' => array_key_exists('image_limit', $row) && $row['image_limit'] !== null ? (int) $row['image_limit'] : null,
            'video_seconds' => array_key_exists('video_second_limit', $row) && $row['video_second_limit'] !== null ? (int) $row['video_second_limit'] : null,
            'cost_amount' => array_key_exists('cost_limit', $row) && $row['cost_limit'] !== null ? (float) $row['cost_limit'] : null,
        ];
    }

    /**
     * @param array<string, int|float|null> $limits
     * @param array<string, int|float> $usage
     * @return array<string, int|float|null>
     */
    private function remaining(array $limits, array $usage): array
    {
        $remaining = [];
        foreach ($limits as $metric => $limit) {
            $remaining[$metric] = $limit === null ? null : max(0, (float) $limit - (float) ($usage[$metric] ?? 0));
        }
        return $remaining;
    }
}
