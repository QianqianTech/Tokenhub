<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\BusinessTime;
use TokenHub\Support\ConfigVersion;

/**
 * Quota policies (per API key, user or department) enforced on Redis
 * counters "quota:<policy>:<target_type>:<target_id>:<metric>:<bucket>".
 *
 * BIZ-012: day/week/month buckets follow the business time zone
 * (BusinessTime, app.business_timezone): a "day" quota resets at local
 * midnight, not at 00:00 UTC. Minute/hour buckets stay UTC.
 *
 * BIZ-014: the cost counter is an integer number of micro-units
 * (metric "cost_amount_u6", 1 = 0.000001 of the price currency) instead of an
 * INCRBYFLOAT sum that drifted. Reservation counters carry 'scale' =>
 * 1000000 so settlement converts usage amounts the same way. Counters of the
 * period that was current when the gateway was upgraded are carried over
 * once from the legacy float key "...:cost_amount:<bucket>"
 * (QUOTA_LEGACY_COST_KEYS=false skips that lookup once no legacy period is
 * current any more).
 */
final class QuotaService
{
    private const POLICY_CACHE_TTL_SECONDS = 30;
    public const COST_SCALE = 1000000;
    public const COST_METRIC = 'cost_amount_u6';
    private const LEGACY_COST_METRIC = 'cost_amount';

    private const SEED_COST_SCRIPT = <<<'LUA'
if redis.call('EXISTS', KEYS[2]) == 1 then return 0 end
local legacy = redis.call('GET', KEYS[1])
if not legacy then return 0 end
local micro = math.floor(tonumber(legacy) * tonumber(ARGV[1]) + 0.5)
redis.call('SET', KEYS[2], micro, 'EX', tonumber(ARGV[2]), 'NX')
return 1
LUA;

    /** @var array<string, true> legacy cost keys already checked by this process */
    private static array $seeded = [];

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
                ['metric' => self::COST_METRIC, 'usage' => 'cost_amount', 'amount' => self::toMicro($estimate['cost_amount'] ?? 0), 'limit_field' => 'cost_limit', 'scale' => self::COST_SCALE],
            ];
            $counters = [];
            foreach ($definitions as $definition) {
                $counters[] = $this->counter($policy, $scope, $window, $definition);
            }
            $this->seedLegacyCost($policy, $scope, $window);
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
                ['metric' => self::COST_METRIC, 'usage' => 'cost_amount', 'limit_field' => 'cost_limit', 'scale' => self::COST_SCALE],
            ] as $definition) {
                $amount = $definition['metric'] === self::COST_METRIC ? self::toMicro($delta['cost_amount'] ?? 0) : (int) ($delta[$definition['metric']] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $counters[] = $this->counter($policy, $scope, $window, [...$definition, 'amount' => $amount]);
            }
            if ($counters === []) {
                continue;
            }
            $this->seedLegacyCost($policy, $scope, $window);
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
                    App::redis()->decrementBy((string) $counter['key'], self::scaled($counter, $counter['amount']));
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
                    $delta = self::settlementDelta($counter, $usage);
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
            $cost = self::toMicro($usage['cost_amount'] ?? 0);
            if ($cost > 0) {
                $this->seedLegacyCost($policy, $scope, $window);
                $this->incrementCounter($policy, $scope, $window, self::COST_METRIC, $cost);
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
                $amount = $metric === 'cost_amount' ? self::toMicro($delta[$metric] ?? 0) : (float) ($delta[$metric] ?? 0);
                if ($amount == 0) {
                    continue;
                }
                $key = $this->counterKey($policy, $scope, $window, $metric === 'cost_amount' ? self::COST_METRIC : $metric);
                try {
                    if ($metric === 'cost_amount') {
                        $this->seedLegacyCost($policy, $scope, $window);
                    }
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
     * Counter bucket and Redis TTL of the period containing $time. Day, week
     * and month follow the business time zone (BIZ-012); the TTL keeps the
     * key one day past the end of its period so late settlements of that
     * period still find it.
     *
     * @return array{bucket: string, ttl: int, reset_at: int}
     */
    public function periodWindow(string $period, ?int $time = null): array
    {
        return self::window($period, $time);
    }

    /**
     * @return array{bucket: string, ttl: int, reset_at: int}
     */
    public static function window(string $period, ?int $time = null): array
    {
        $time ??= time();
        return match ($period) {
            'minute' => ['bucket' => gmdate('YmdHi', $time), 'ttl' => 60, 'reset_at' => ((int) floor($time / 60) + 1) * 60],
            'hour' => ['bucket' => gmdate('YmdH', $time), 'ttl' => 3600, 'reset_at' => ((int) floor($time / 3600) + 1) * 3600],
            'week', 'month' => self::businessWindow($period, $time),
            default => self::businessWindow('day', $time),
        };
    }

    /**
     * Key, scale and window of one usage counter, for readers outside the
     * gateway (quota views, manual adjustments). Cost is read from the
     * integer micro-unit counter: divide by 'scale'.
     *
     * @return array{key: string, legacy_key: ?string, scale: int, bucket: string, ttl: int, reset_at: int}
     */
    public function usageCounter(int $policyId, string $targetType, int $targetId, string $period, string $metric, ?int $time = null): array
    {
        $window = self::window($period, $time);
        $scope = $targetType . ':' . $targetId;
        $isCost = $metric === 'cost_amount' || $metric === self::COST_METRIC;
        $policy = ['id' => $policyId];

        return [
            'key' => $this->counterKey($policy, $scope, $window, $isCost ? self::COST_METRIC : ($metric === 'request_count' ? 'requests' : $metric)),
            'legacy_key' => $isCost ? $this->counterKey($policy, $scope, $window, self::LEGACY_COST_METRIC) : null,
            'scale' => $isCost ? self::COST_SCALE : 1,
            ...$window,
        ];
    }

    /**
     * Current value of a usage counter in its natural unit (cost in currency).
     */
    public function readUsageCounter(int $policyId, string $targetType, int $targetId, string $period, string $metric, ?int $time = null): float
    {
        $counter = $this->usageCounter($policyId, $targetType, $targetId, $period, $metric, $time);
        $value = App::redis()->get($counter['key']);
        if (is_numeric($value)) {
            return (float) $value / $counter['scale'];
        }
        if ($counter['legacy_key'] !== null) {
            $legacy = App::redis()->get($counter['legacy_key']);
            return is_numeric($legacy) ? (float) $legacy : 0.0;
        }

        return 0.0;
    }

    public static function toMicro(mixed $amount): int
    {
        if (!is_numeric($amount)) {
            return 0;
        }
        if (is_string($amount) && preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', trim($amount), $match) === 1) {
            // Exact decimal string (PriceService returns strings): no float
            // rounding on the way to micro-units.
            $fraction = str_pad(substr($match[3] ?? '', 0, 7), 7, '0');
            $micro = (int) ($match[2] === '' ? '0' : $match[2]) * self::COST_SCALE + intdiv((int) $fraction + 5, 10);
            return $match[1] === '-' ? -$micro : $micro;
        }

        return (int) round((float) $amount * self::COST_SCALE);
    }

    /**
     * actual - reserved for one reservation counter, in the counter's unit.
     *
     * @param array<string, mixed> $counter
     * @param array<string, mixed> $usage
     */
    public static function settlementDelta(array $counter, array $usage): int|float
    {
        $actual = $usage[$counter['usage'] ?? ''] ?? 0;
        if ((int) ($counter['scale'] ?? 1) === self::COST_SCALE) {
            return self::toMicro($actual) - (int) round((float) ($counter['amount'] ?? 0));
        }

        return (float) $actual - (float) ($counter['amount'] ?? 0);
    }

    /**
     * Integer amounts for micro-unit counters (DECRBY instead of a float).
     *
     * @param array<string, mixed> $counter
     */
    private static function scaled(array $counter, mixed $amount): int|float
    {
        return isset($counter['scale']) ? (int) round((float) $amount) : (is_int($amount) ? $amount : (float) $amount);
    }

    /**
     * @return array{bucket: string, ttl: int, reset_at: int}
     */
    private static function businessWindow(string $period, int $time): array
    {
        $remaining = BusinessTime::secondsUntilPeriodEnd($period, $time);
        // At least the previous fixed TTLs (1 day / 31 days), and always one
        // day past the end of the local period.
        $minimum = match ($period) {
            'month' => 2678400,
            'week' => 691200,
            default => 86400,
        };

        return ['bucket' => BusinessTime::bucket($period, $time), 'ttl' => max($minimum, $remaining + 86400), 'reset_at' => $time + $remaining];
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function counter(array $policy, string $scope, array $window, array $definition): array
    {
        $limit = $policy[$definition['limit_field']] ?? null;
        if (isset($definition['scale']) && $limit !== null && is_numeric($limit)) {
            $limit = self::toMicro($limit);
        }

        return [...$definition, 'key' => $this->counterKey($policy, $scope, $window, $definition['metric']), 'limit' => $limit, 'ttl' => $window['ttl']];
    }

    /**
     * One-time carry-over of a legacy float cost counter into the micro-unit
     * counter of the same period (BIZ-014 upgrade path).
     *
     * @param array<string, mixed> $policy
     * @param array{bucket: string, ttl: int} $window
     */
    private function seedLegacyCost(array $policy, string $scope, array $window): void
    {
        if (!filter_var(env_value('QUOTA_LEGACY_COST_KEYS', true), FILTER_VALIDATE_BOOL)) {
            return;
        }
        $legacy = $this->counterKey($policy, $scope, $window, self::LEGACY_COST_METRIC);
        if (isset(self::$seeded[$legacy])) {
            return;
        }
        self::$seeded[$legacy] = true;
        try {
            if (!App::redis()->available()) {
                return;
            }
            \TokenHub\Services\Gateway\RedisScript::run(self::SEED_COST_SCRIPT, [$legacy, $this->counterKey($policy, $scope, $window, self::COST_METRIC), (string) self::COST_SCALE, (string) max(1, (int) $window['ttl'])], 2);
        } catch (\Throwable) {
        }
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

    private function limitEnabled(mixed $limit): bool
    {
        return $limit !== null && (float) $limit > 0;
    }
}
