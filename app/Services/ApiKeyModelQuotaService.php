<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ApiKeyModelQuotaService
{
    private const QUOTA_CACHE_TTL_SECONDS = 30;

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $route
     * @param array<string, int|float|string|null> $estimate
     * @return array{allowed: bool, code?: string, message?: string, status?: int}
     */
    public function preflight(array $apiKey, array $route, array $estimate = []): array
    {
        $scope = $this->scope($apiKey, $route);
        if ($scope === null) {
            return ['allowed' => true];
        }

        $rollback = [];
        $reservations = [];
        foreach ($this->quotas($scope['api_key_id'], $scope['model_id']) as $quota) {
            $window = $this->periodWindow((string) ($quota['period'] ?? 'day'));
            $definitions = [
                ['metric' => 'requests', 'usage' => 'request_count', 'amount' => 1, 'limit_field' => 'request_limit'],
                ['metric' => 'input_tokens', 'usage' => 'input_tokens', 'amount' => (int) ($estimate['input_tokens'] ?? 0), 'limit_field' => 'input_token_limit'],
                ['metric' => 'output_tokens', 'usage' => 'output_tokens', 'amount' => (int) ($estimate['output_tokens'] ?? 0), 'limit_field' => 'output_token_limit'],
                ['metric' => 'total_tokens', 'usage' => 'total_tokens', 'amount' => (int) ($estimate['total_tokens'] ?? 0), 'limit_field' => 'total_token_limit'],
            ];
            $counters = array_map(fn (array $definition): array => [...$definition, 'key' => $this->counterKey($quota, $scope, $window, $definition['metric']), 'limit' => $quota[$definition['limit_field']] ?? null, 'ttl' => $window['ttl']], $definitions);
            $reserved = App::redis()->reserveCounters($counters);
            if (($reserved['allowed'] ?? false) !== true) {
                $this->rollbackPreflight($rollback);
                return [
                    'allowed' => false,
                    'code' => 'MODEL_QUOTA_EXCEEDED',
                    'message' => (($counters[(int) ($reserved['rejected_index'] ?? 0)]['metric'] ?? 'quota') . ' exceeded'),
                    'status' => 429,
                ];
            }
            foreach ($counters as $counter) {
                $rollback[] = ['key' => $counter['key'], 'amount' => $counter['amount']];
            }
            $reservations[] = $counters;
        }

        return ['allowed' => true, 'reservations' => $reservations];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $route
     * @param array<string, mixed> $usage
     */
    public function recordUsage(array $apiKey, array $route, array $usage, array $reservations = []): void
    {
        $scope = $this->scope($apiKey, $route);
        if ($scope === null) {
            return;
        }
        if ($reservations !== []) {
            foreach ($reservations as $counters) {
                foreach ($counters as $counter) {
                    if (($counter['metric'] ?? '') === 'requests') {
                        continue;
                    }
                    $delta = (float) ($usage[$counter['usage']] ?? 0) - (float) ($counter['amount'] ?? 0);
                    if ($delta > 0) {
                        App::redis()->incrementByFloat((string) $counter['key'], $delta, (int) $counter['ttl']);
                    } elseif ($delta < 0) {
                        App::redis()->decrementBy((string) $counter['key'], -$delta);
                    }
                }
            }
            return;
        }

        foreach ($this->quotas($scope['api_key_id'], $scope['model_id']) as $quota) {
            $window = $this->periodWindow((string) ($quota['period'] ?? 'day'));
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $metric) {
                $amount = (int) ($usage[$metric] ?? 0);
                if ($amount > 0) {
                    $this->incrementCounter($quota, $scope, $window, $metric, $amount);
                }
            }
        }
    }

    /**
     * @return array{bucket: string, ttl: int}
     */
    public function periodWindow(string $period, ?int $time = null): array
    {
        $time ??= time();
        return match ($period) {
            'minute' => ['bucket' => gmdate('YmdHi', $time), 'ttl' => 60],
            'hour' => ['bucket' => gmdate('YmdH', $time), 'ttl' => 3600],
            'month' => ['bucket' => gmdate('Ym', $time), 'ttl' => 2678400],
            default => ['bucket' => gmdate('Ymd', $time), 'ttl' => 86400],
        };
    }

    /**
     * @param array<string, mixed> $quota
     * @param array<string, int|float|string|null> $usage
     * @return array{code: string, limit: string, message: string}|null
     */
    public function firstExceededLimit(array $quota, array $usage): ?array
    {
        $limits = [
            'request_limit' => 'request_count',
            'input_token_limit' => 'input_tokens',
            'output_token_limit' => 'output_tokens',
            'total_token_limit' => 'total_tokens',
        ];

        foreach ($limits as $limitKey => $usageKey) {
            if ($this->limitEnabled($quota[$limitKey] ?? null) && (float) ($usage[$usageKey] ?? 0) > (float) $quota[$limitKey]) {
                return ['code' => 'MODEL_QUOTA_EXCEEDED', 'limit' => $limitKey, 'message' => $limitKey . ' exceeded'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $quota
     * @param array{api_key_id: int, model_id: int} $scope
     * @param array{bucket: string, ttl: int} $window
     */
    public function counterKey(array $quota, array $scope, array $window, string $metric): string
    {
        return 'model_quota:' . (int) $quota['id']
            . ':api_key:' . $scope['api_key_id']
            . ':model:' . $scope['model_id']
            . ':' . $metric
            . ':' . $window['bucket'];
    }

    public function rollbackPreflight(array $increments): void
    {
        foreach ($increments as $increment) {
            if (isset($increment['key'], $increment['amount'])) {
                App::redis()->decrementBy((string) $increment['key'], $increment['amount']);
            }
        }
    }

    public function quotaCacheKey(int $apiKeyId, int $modelId): string
    {
        return 'api_key_model_quotas:' . $apiKeyId . ':' . $modelId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quotas(int $apiKeyId, int $modelId): array
    {
        $cacheKey = $this->quotaCacheKey($apiKeyId, $modelId);
        $cached = App::redis()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $quotas = App::db()->select(
            'SELECT * FROM api_key_model_quotas WHERE api_key_id = ? AND model_id = ? AND status = "active"',
            [$apiKeyId, $modelId]
        );
        App::redis()->set($cacheKey, $quotas, self::QUOTA_CACHE_TTL_SECONDS);

        return $quotas;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $route
     * @return array{api_key_id: int, model_id: int}|null
     */
    private function scope(array $apiKey, array $route): ?array
    {
        $apiKeyId = (int) ($apiKey['id'] ?? 0);
        $modelId = (int) ($route['id'] ?? 0);
        if ($apiKeyId <= 0 || $modelId <= 0) {
            return null;
        }

        return ['api_key_id' => $apiKeyId, 'model_id' => $modelId];
    }

    private function limitEnabled(mixed $limit): bool
    {
        return $limit !== null && (float) $limit > 0;
    }

    /**
     * @param array<string, mixed> $quota
     * @param array{api_key_id: int, model_id: int} $scope
     * @param array{bucket: string, ttl: int} $window
     */
    private function incrementCounter(array $quota, array $scope, array $window, string $metric, int|float $amount): int|float
    {
        $key = $this->counterKey($quota, $scope, $window, $metric);
        if ($amount === 1) {
            return (int) App::redis()->increment($key, $window['ttl']);
        }

        return App::redis()->incrementByFloat($key, $amount, $window['ttl']);
    }

    /**
     * @param array<string, mixed> $quota
     * @param array{api_key_id: int, model_id: int} $scope
     * @param array{bucket: string, ttl: int} $window
     */
    private function currentPlus(array $quota, array $scope, array $window, string $metric, int|float $amount): int|float
    {
        $key = $this->counterKey($quota, $scope, $window, $metric);
        return (float) (App::redis()->get($key) ?? 0) + $amount;
    }
}
