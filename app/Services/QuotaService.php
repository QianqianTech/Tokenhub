<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\ConfigVersion;

final class QuotaService
{
    private const POLICY_CACHE_TTL_SECONDS = 30;

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, int|float|string|null> $estimate
     * @return array{allowed: bool, code?: string, message?: string, status?: int, concurrency_keys?: list<string>}
     */
    public function preflight(array $apiKey, array $estimate = []): array
    {
        $policies = $this->policies($apiKey);
        $concurrencyKeys = [];
        $rollback = [];
        $reservations = [];

        foreach ($policies as $policy) {
            $window = $this->periodWindow((string) ($policy['period'] ?? 'day'));
            $scope = $this->scopeKey($policy, $apiKey);
            $definitions = [
                ['metric' => 'requests', 'usage' => 'request_count', 'amount' => 1, 'limit_field' => 'request_limit'],
                ['metric' => 'input_tokens', 'usage' => 'input_tokens', 'amount' => (int) ($estimate['input_tokens'] ?? 0), 'limit_field' => 'input_token_limit'],
                ['metric' => 'output_tokens', 'usage' => 'output_tokens', 'amount' => (int) ($estimate['output_tokens'] ?? 0), 'limit_field' => 'output_token_limit'],
                ['metric' => 'total_tokens', 'usage' => 'total_tokens', 'amount' => (int) ($estimate['total_tokens'] ?? 0), 'limit_field' => 'total_token_limit'],
                ['metric' => 'audio_seconds', 'usage' => 'audio_seconds', 'amount' => (int) ($estimate['audio_seconds'] ?? 0), 'limit_field' => 'audio_second_limit'],
                ['metric' => 'tts_chars', 'usage' => 'tts_chars', 'amount' => (int) ($estimate['tts_chars'] ?? 0), 'limit_field' => 'tts_char_limit'],
                ['metric' => 'image_count', 'usage' => 'image_count', 'amount' => (int) ($estimate['image_count'] ?? 0), 'limit_field' => 'image_limit'],
                ['metric' => 'video_seconds', 'usage' => 'video_seconds', 'amount' => (int) ($estimate['video_seconds'] ?? 0), 'limit_field' => 'video_second_limit'],
                ['metric' => 'cost_amount', 'usage' => 'cost_amount', 'amount' => (float) ($estimate['cost_amount'] ?? 0), 'limit_field' => 'cost_limit'],
            ];
            $counters = [];
            foreach ($definitions as $definition) {
                $counters[] = [...$definition, 'key' => $this->counterKey($policy, $scope, $window, $definition['metric']), 'limit' => $policy[$definition['limit_field']] ?? null, 'ttl' => $window['ttl']];
            }
            if ($this->limitEnabled($policy['concurrency_limit'] ?? null)) {
                $key = $this->counterKey($policy, $scope, ['bucket' => 'active', 'ttl' => 86400], 'concurrency');
                $counters[] = ['key' => $key, 'metric' => 'concurrency', 'usage' => 'concurrency', 'amount' => 1, 'limit' => $policy['concurrency_limit'], 'ttl' => 86400];
                $concurrencyKeys[] = $key;
            }
            $reserved = App::redis()->reserveCounters($counters);
            if (($reserved['allowed'] ?? false) !== true) {
                $this->rollbackPreflight($rollback);
                $rejected = $counters[(int) ($reserved['rejected_index'] ?? 0)] ?? [];
                return [
                    'allowed' => false,
                    'code' => ($rejected['metric'] ?? '') === 'concurrency' ? 'CONCURRENCY_EXCEEDED' : 'QUOTA_EXCEEDED',
                    'message' => (($rejected['metric'] ?? 'quota') . ' exceeded'),
                    'status' => 429,
                ];
            }
            foreach ($counters as $counter) {
                $rollback[] = ['key' => $counter['key'], 'amount' => $counter['amount']];
            }
            $reservations[] = $counters;
        }

        return [
            'allowed' => true,
            'concurrency_keys' => $concurrencyKeys,
            'reservations' => $reservations,
            'requires_cost_tracking' => $this->requiresCostTrackingForPolicies($policies),
        ];
    }

    /**
     * Reserves extra token/cost budget for an in-flight request, e.g. after RAG
     * context was injected and the final payload grew. Request and concurrency
     * counters are not touched. All-or-nothing across bound policies.
     *
     * @param array<string, mixed> $apiKey
     * @param array<string, int|float> $delta
     * @return array{allowed: bool, code?: string, message?: string, status?: int, reservations?: list<list<array<string, mixed>>>}
     */
    public function reserveDelta(array $apiKey, array $delta): array
    {
        $reservations = [];
        foreach ($this->policies($apiKey) as $policy) {
            $window = $this->periodWindow((string) ($policy['period'] ?? 'day'));
            $scope = $this->scopeKey($policy, $apiKey);
            $counters = [];
            foreach ([
                ['metric' => 'input_tokens', 'usage' => 'input_tokens', 'limit_field' => 'input_token_limit'],
                ['metric' => 'output_tokens', 'usage' => 'output_tokens', 'limit_field' => 'output_token_limit'],
                ['metric' => 'total_tokens', 'usage' => 'total_tokens', 'limit_field' => 'total_token_limit'],
                ['metric' => 'cost_amount', 'usage' => 'cost_amount', 'limit_field' => 'cost_limit'],
            ] as $definition) {
                $amount = $definition['metric'] === 'cost_amount' ? (float) ($delta['cost_amount'] ?? 0) : (int) ($delta[$definition['metric']] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $counters[] = [...$definition, 'amount' => $amount, 'key' => $this->counterKey($policy, $scope, $window, $definition['metric']), 'limit' => $policy[$definition['limit_field']] ?? null, 'ttl' => $window['ttl']];
            }
            if ($counters === []) {
                continue;
            }
            $reserved = App::redis()->reserveCounters($counters);
            if (($reserved['allowed'] ?? false) !== true) {
                $this->rollbackReservations($reservations);
                $rejected = $counters[(int) ($reserved['rejected_index'] ?? 0)] ?? [];
                return ['allowed' => false, 'code' => 'QUOTA_EXCEEDED', 'message' => (($rejected['metric'] ?? 'quota') . ' exceeded'), 'status' => 429];
            }
            $reservations[] = $counters;
        }

        return ['allowed' => true, 'reservations' => $reservations];
    }

    /**
     * Fully undoes reservations of a request that never reached the upstream
     * (including its request counter). Concurrency is released separately.
     *
     * @param list<list<array<string, mixed>>> $reservations
     */
    public function rollbackReservations(array $reservations): void
    {
        foreach ($reservations as $counters) {
            foreach ($counters as $counter) {
                if (($counter['metric'] ?? '') === 'concurrency' || !isset($counter['key'], $counter['amount'])) {
                    continue;
                }
                if ((float) $counter['amount'] > 0) {
                    App::redis()->decrementBy((string) $counter['key'], $counter['amount']);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    public function requiresCostTracking(array $apiKey): bool
    {
        return $this->requiresCostTrackingForPolicies($this->policies($apiKey));
    }

    /**
     * @param list<string> $keys
     */
    public function releaseConcurrency(array $keys): void
    {
        foreach ($keys as $key) {
            App::redis()->decrement($key);
        }
    }

    public function rollbackPreflight(array $increments): void
    {
        foreach ($this->rollbackKeys($increments) as $increment) {
            App::redis()->decrementBy((string) $increment['key'], $increment['amount']);
        }
    }

    public function rollbackKeys(array $increments): array
    {
        return array_values(array_filter($increments, fn (array $increment): bool => isset($increment['key'], $increment['amount'])));
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    public function policyCacheKey(array $apiKey): string
    {
        return 'quota_policies:' . (int) ($apiKey['id'] ?? 0)
            . ':' . (int) ($apiKey['user_id'] ?? 0)
            . ':' . (int) ($apiKey['department_id'] ?? 0)
            . ConfigVersion::cacheSuffix();
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $usage
     */
    public function recordUsage(array $apiKey, array $usage, array $reservations = []): void
    {
        if ($reservations !== []) {
            foreach ($reservations as $counters) {
                foreach ($counters as $counter) {
                    if (in_array($counter['metric'] ?? '', ['requests', 'concurrency'], true)) {
                        continue;
                    }
                    $actual = (float) ($usage[$counter['usage']] ?? 0);
                    $delta = $actual - (float) ($counter['amount'] ?? 0);
                    if ($delta > 0) {
                        App::redis()->incrementByFloat((string) $counter['key'], $delta, (int) $counter['ttl']);
                    } elseif ($delta < 0) {
                        App::redis()->decrementBy((string) $counter['key'], -$delta);
                    }
                }
            }
            return;
        }
        foreach ($this->policies($apiKey) as $policy) {
            $window = $this->periodWindow((string) ($policy['period'] ?? 'day'));
            $scope = $this->scopeKey($policy, $apiKey);
            foreach (['input_tokens', 'output_tokens', 'total_tokens', 'audio_seconds', 'tts_chars', 'image_count', 'video_seconds'] as $metric) {
                $amount = (int) ($usage[$metric] ?? 0);
                if ($amount > 0) {
                    $this->incrementCounter($policy, $scope, $window, $metric, $amount);
                }
            }
            $cost = (float) ($usage['cost_amount'] ?? 0);
            if ($cost > 0) {
                $this->incrementCounter($policy, $scope, $window, 'cost_amount', $cost);
            }
        }
    }

    /**
     * Applies a later correction (task settlement: refund of a failed task,
     * true-up to the measured usage) to the counters of the period in which
     * the request was charged. Only counters that still exist are touched:
     * an expired period needs no correction, and decrementing a missing key
     * would create a stray negative counter.
     *
     * @param array<string, mixed> $apiKey id, user_id, department_id
     * @param array<string, int|float> $delta metric => signed amount
     */
    public function adjustUsage(array $apiKey, array $delta, int $chargedAt): void
    {
        foreach ($this->policies($apiKey) as $item) {
            $policy = $item;
            $window = $this->periodWindow((string) ($policy['period'] ?? 'day'), $chargedAt);
            $scope = $this->scopeKey($policy, $apiKey);
            foreach (['input_tokens', 'output_tokens', 'total_tokens', 'audio_seconds', 'tts_chars', 'image_count', 'video_seconds', 'cost_amount'] as $metric) {
                $amount = (float) ($delta[$metric] ?? 0);
                if ($amount == 0.0) {
                    continue;
                }
                $key = $this->counterKey($policy, $scope, $window, $metric);
                try {
                    if (!App::redis()->connection()->exists($key)) {
                        continue;
                    }
                    if ($amount > 0) {
                        App::redis()->incrementByFloat($key, $amount);
                    } else {
                        App::redis()->decrementBy($key, -$amount);
                    }
                } catch (\Throwable $throwable) {
                    App::logger()->warning('quota', 'quota settlement adjustment failed', ['key' => $key, 'error' => $throwable->getMessage()]);
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
     * @param array<string, mixed> $policy
     * @param array<string, int|float|string|null> $usage
     * @return array{code: string, limit: string, message: string}|null
     */
    public function firstExceededLimit(array $policy, array $usage): ?array
    {
        $limits = [
            'request_limit' => 'request_count',
            'input_token_limit' => 'input_tokens',
            'output_token_limit' => 'output_tokens',
            'total_token_limit' => 'total_tokens',
            'audio_second_limit' => 'audio_seconds',
            'tts_char_limit' => 'tts_chars',
            'image_limit' => 'image_count',
            'video_second_limit' => 'video_seconds',
            'cost_limit' => 'cost_amount',
        ];

        foreach ($limits as $limitKey => $usageKey) {
            if ($this->limitEnabled($policy[$limitKey] ?? null) && (float) ($usage[$usageKey] ?? 0) > (float) $policy[$limitKey]) {
                return ['code' => 'QUOTA_EXCEEDED', 'limit' => $limitKey, 'message' => $limitKey . ' exceeded'];
            }
        }

        if ($this->limitEnabled($policy['concurrency_limit'] ?? null) && (int) ($usage['concurrency'] ?? 0) > (int) $policy['concurrency_limit']) {
            return ['code' => 'CONCURRENCY_EXCEEDED', 'limit' => 'concurrency_limit', 'message' => 'concurrency_limit exceeded'];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $policies
     */
    public function requiresCostTrackingForPolicies(array $policies): bool
    {
        foreach ($policies as $policy) {
            if ($this->limitEnabled($policy['cost_limit'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function policies(array $apiKey): array
    {
        $cacheKey = $this->policyCacheKey($apiKey);
        $cached = App::redis()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $policies = App::db()->select(
            'SELECT p.*, b.target_type, b.target_id FROM quota_policies p JOIN quota_bindings b ON b.quota_policy_id = p.id WHERE p.status = "active" AND ((b.target_type = "api_key" AND b.target_id = ?) OR (b.target_type = "user" AND b.target_id = ?) OR (b.target_type = "department" AND b.target_id = ?))',
            [$apiKey['id'] ?? 0, $apiKey['user_id'] ?? 0, $apiKey['department_id'] ?? 0]
        );
        App::redis()->set($cacheKey, $policies, self::POLICY_CACHE_TTL_SECONDS);

        return $policies;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function scopeKey(array $policy, array $apiKey): string
    {
        $targetType = (string) ($policy['target_type'] ?? 'api_key');
        $targetId = (int) ($policy['target_id'] ?? ($apiKey['id'] ?? 0));
        return $targetType . ':' . $targetId;
    }

    /**
     * @param array<string, mixed> $policy
     * @param array{bucket: string, ttl: int} $window
     */
    private function counterKey(array $policy, string $scope, array $window, string $metric): string
    {
        return 'quota:' . (int) $policy['id'] . ':' . $scope . ':' . $metric . ':' . $window['bucket'];
    }

    /**
     * @param array<string, mixed> $policy
     * @param array{bucket: string, ttl: int} $window
     */
    private function incrementCounter(array $policy, string $scope, array $window, string $metric, int|float $amount): int|float
    {
        $key = $this->counterKey($policy, $scope, $window, $metric);
        if ($amount === 1) {
            return (int) App::redis()->increment($key, $window['ttl']);
        }

        return App::redis()->incrementByFloat($key, $amount, $window['ttl']);
    }

    /**
     * @param array<string, mixed> $policy
     * @param array{bucket: string, ttl: int} $window
     */
    private function currentPlus(array $policy, string $scope, array $window, string $metric, int|float $amount): int|float
    {
        $key = $this->counterKey($policy, $scope, $window, $metric);
        return (float) (App::redis()->get($key) ?? 0) + $amount;
    }

    private function limitEnabled(mixed $limit): bool
    {
        return $limit !== null && (float) $limit > 0;
    }
}
