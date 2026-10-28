<?php

declare(strict_types=1);

namespace TokenHub\Services\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TokenHub\Support\Decimal;

/**
 * Optional pricing rules on top of a model_prices row (model_prices.pricing_rules).
 *
 *   {
 *     "time_windows": [            // first matching window wins
 *       {"name": "peak", "timezone": "UTC", "days": [1,2,3,4,5],
 *        "hours": ["01:00-04:00", "06:00-10:00"], "exclude_dates": ["2026-10-01"],
 *        "multiplier": "2"}          // or "prices": {"input_token_price": "...", ...}
 *     ],
 *     "context_tiers": [            // highest min_input_tokens reached wins; the
 *       {"name": ">200K", "min_input_tokens": 200001, "multiplier": "2"}   // whole request is priced at that tier
 *     ],
 *     "dimension_prices": [         // first entry whose "match" equals the request dimensions
 *       {"name": "1080p+audio", "match": {"resolution": "1080p", "generate_audio": true},
 *        "prices": {"video_second_price": "0.42"}}
 *     ],
 *     "modality_token_prices": {    // image/audio tokens priced apart from text tokens
 *       "image_input_token_price": "...", "audio_input_token_price": "...",
 *       "image_output_token_price": "...", "audio_output_token_price": "..."
 *     },
 *     "upstream_cost": {"enabled": true, "multiplier": "1.1"}   // bill upstream-reported cost x multiplier when present
 *   }
 *
 * Days are ISO weekdays (1 = Monday). Hours are "HH:MM-HH:MM" in the window's
 * timezone and may wrap past midnight ("22:00-02:00"). Business time is UTC.
 */
final class PricingRules
{
    public const PRICE_FIELDS = [
        'input_token_price', 'output_token_price', 'cache_read_token_price', 'cache_write_token_price',
        'reasoning_token_price', 'audio_second_price', 'tts_char_price', 'image_price', 'video_second_price', 'request_price',
    ];
    public const MODALITY_FIELDS = ['image_input_token_price', 'audio_input_token_price', 'image_output_token_price', 'audio_output_token_price'];

    /**
     * Applies time windows, context tiers and dimension prices to a price row.
     *
     * @param array<string, mixed> $price model_prices row (pricing_rules may be JSON text)
     * @param array<string, mixed> $usage canonical usage (input_tokens, pricing_dimensions, ...)
     * @return array{price: array<string, mixed>, applied: list<string>, rules: array<string, mixed>}
     */
    public function resolve(array $price, array $usage, string $atUtc): array
    {
        $rules = $this->decode($price['pricing_rules'] ?? null);
        $applied = [];
        if ($rules === []) {
            return ['price' => $price, 'applied' => [], 'rules' => []];
        }
        // Modality token prices are part of the base price, so windows,
        // tiers and dimension prices adjust them like any other price.
        foreach (self::MODALITY_FIELDS as $field) {
            $value = $rules['modality_token_prices'][$field] ?? null;
            if ($value !== null && $value !== '') {
                $price[$field] = Decimal::normalize($value);
            }
        }

        foreach ((is_array($rules['time_windows'] ?? null) ? $rules['time_windows'] : []) as $index => $window) {
            if (is_array($window) && $this->windowMatches($window, $atUtc)) {
                $price = $this->adjust($price, $window);
                $applied[] = 'time_window:' . (string) ($window['name'] ?? $index);
                break;
            }
        }

        $input = max(0, (int) ($usage['input_tokens'] ?? 0));
        $tier = null;
        foreach ((is_array($rules['context_tiers'] ?? null) ? $rules['context_tiers'] : []) as $index => $candidate) {
            if (!is_array($candidate) || $input < (int) ($candidate['min_input_tokens'] ?? PHP_INT_MAX)) {
                continue;
            }
            if ($tier === null || (int) $candidate['min_input_tokens'] > (int) $tier['min_input_tokens']) {
                $tier = $candidate + ['__index' => $index];
            }
        }
        if ($tier !== null) {
            $price = $this->adjust($price, $tier);
            $applied[] = 'context_tier:' . (string) ($tier['name'] ?? $tier['__index']);
        }

        $dimensions = is_array($usage['pricing_dimensions'] ?? null) ? $usage['pricing_dimensions'] : [];
        foreach ((is_array($rules['dimension_prices'] ?? null) ? $rules['dimension_prices'] : []) as $index => $entry) {
            if (is_array($entry) && is_array($entry['match'] ?? null) && $this->dimensionsMatch($entry['match'], $dimensions)) {
                $price = $this->adjust($price, $entry);
                $applied[] = 'dimension:' . (string) ($entry['name'] ?? $index);
                break;
            }
        }

        return ['price' => $price, 'applied' => $applied, 'rules' => $rules];
    }

    /**
     * Upstream cost multiplier when the rules bill upstream-reported cost.
     *
     * @param array<string, mixed> $rules
     */
    public function upstreamCostMultiplier(array $rules): ?string
    {
        $config = $rules['upstream_cost'] ?? null;
        if (!is_array($config) || !filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        return Decimal::normalize($config['multiplier'] ?? '1');
    }

    /**
     * @return list<string> validation errors; [] when valid
     */
    public function validate(mixed $rules): array
    {
        if ($rules === null || $rules === '' || $rules === []) {
            return [];
        }
        $rules = $this->decode($rules, true);
        if ($rules === null) {
            return ['pricing_rules must be a JSON object'];
        }
        $errors = [];
        $known = ['time_windows', 'context_tiers', 'dimension_prices', 'modality_token_prices', 'upstream_cost'];
        foreach (array_keys($rules) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = 'unknown pricing rule "' . $key . '"';
            }
        }
        foreach ((array) ($rules['time_windows'] ?? []) as $index => $window) {
            $label = 'time_windows[' . $index . ']';
            if (!is_array($window)) {
                $errors[] = $label . ' must be an object';
                continue;
            }
            try {
                new DateTimeZone((string) ($window['timezone'] ?? 'UTC'));
            } catch (Throwable) {
                $errors[] = $label . '.timezone is not a valid timezone';
            }
            foreach ((array) ($window['days'] ?? []) as $day) {
                if (!is_numeric($day) || (int) $day < 1 || (int) $day > 7) {
                    $errors[] = $label . '.days must be ISO weekdays 1-7';
                    break;
                }
            }
            if (!is_array($window['hours'] ?? null) || $window['hours'] === []) {
                $errors[] = $label . '.hours must list "HH:MM-HH:MM" ranges';
            } else {
                foreach ($window['hours'] as $range) {
                    if (!is_string($range) || $this->parseRange($range) === null) {
                        $errors[] = $label . '.hours entry "' . (is_scalar($range) ? $range : '?') . '" is not "HH:MM-HH:MM"';
                    }
                }
            }
            foreach ((array) ($window['exclude_dates'] ?? []) as $date) {
                if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    $errors[] = $label . '.exclude_dates must be YYYY-MM-DD dates';
                    break;
                }
            }
            $errors = [...$errors, ...$this->validateAdjustment($window, $label)];
        }
        foreach ((array) ($rules['context_tiers'] ?? []) as $index => $tier) {
            $label = 'context_tiers[' . $index . ']';
            if (!is_array($tier) || !isset($tier['min_input_tokens']) || !is_numeric($tier['min_input_tokens']) || (int) $tier['min_input_tokens'] < 1) {
                $errors[] = $label . '.min_input_tokens must be a positive integer';
                continue;
            }
            $errors = [...$errors, ...$this->validateAdjustment($tier, $label)];
        }
        foreach ((array) ($rules['dimension_prices'] ?? []) as $index => $entry) {
            $label = 'dimension_prices[' . $index . ']';
            if (!is_array($entry) || !is_array($entry['match'] ?? null) || $entry['match'] === []) {
                $errors[] = $label . '.match must be a non-empty object';
                continue;
            }
            $errors = [...$errors, ...$this->validateAdjustment($entry, $label)];
        }
        foreach ((array) ($rules['modality_token_prices'] ?? []) as $field => $value) {
            if (!in_array($field, self::MODALITY_FIELDS, true)) {
                $errors[] = 'modality_token_prices.' . $field . ' is not a known field';
            } elseif (!$this->isPrice($value)) {
                $errors[] = 'modality_token_prices.' . $field . ' must be a non-negative number';
            }
        }
        if (isset($rules['upstream_cost'])) {
            $config = $rules['upstream_cost'];
            if (!is_array($config) || (isset($config['multiplier']) && !$this->isPrice($config['multiplier']))) {
                $errors[] = 'upstream_cost must be {"enabled": bool, "multiplier": number}';
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(mixed $rules, bool $strict = false): ?array
    {
        if (is_string($rules)) {
            if (trim($rules) === '') {
                return [];
            }
            $rules = json_decode($rules, true);
        }
        if ($rules === null) {
            return $strict ? null : [];
        }

        return is_array($rules) ? $rules : ($strict ? null : []);
    }

    /**
     * @param array<string, mixed> $window
     */
    public function windowMatches(array $window, string $atUtc): bool
    {
        try {
            $timezone = new DateTimeZone((string) ($window['timezone'] ?? 'UTC'));
            $at = (new DateTimeImmutable($atUtc, new DateTimeZone('UTC')))->setTimezone($timezone);
        } catch (Throwable) {
            return false;
        }
        $excluded = array_map('strval', (array) ($window['exclude_dates'] ?? []));
        $days = array_map('intval', (array) ($window['days'] ?? [1, 2, 3, 4, 5, 6, 7]));
        $minute = (int) $at->format('H') * 60 + (int) $at->format('i');
        $weekday = (int) $at->format('N');
        foreach ((array) ($window['hours'] ?? []) as $range) {
            $parsed = is_string($range) ? $this->parseRange($range) : null;
            if ($parsed === null) {
                continue;
            }
            [$from, $to] = $parsed;
            $today = $at->format('Y-m-d');
            if ($from < $to) {
                if ($minute >= $from && $minute < $to && in_array($weekday, $days, true) && !in_array($today, $excluded, true)) {
                    return true;
                }
                continue;
            }
            // Wraps past midnight: the after-midnight part belongs to the
            // starting day (its weekday and its exclusion).
            if ($minute >= $from && in_array($weekday, $days, true) && !in_array($today, $excluded, true)) {
                return true;
            }
            $previous = $weekday === 1 ? 7 : $weekday - 1;
            if ($minute < $to && in_array($previous, $days, true) && !in_array($at->modify('-1 day')->format('Y-m-d'), $excluded, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $price
     * @param array<string, mixed> $adjustment
     * @return array<string, mixed>
     */
    private function adjust(array $price, array $adjustment): array
    {
        if (is_array($adjustment['prices'] ?? null)) {
            foreach ($adjustment['prices'] as $field => $value) {
                if (in_array($field, [...self::PRICE_FIELDS, ...self::MODALITY_FIELDS], true) && $this->isPrice($value)) {
                    $price[$field] = Decimal::normalize($value);
                }
            }
        }
        if (isset($adjustment['multiplier']) && $this->isPrice($adjustment['multiplier'])) {
            foreach ([...self::PRICE_FIELDS, ...self::MODALITY_FIELDS] as $field) {
                if (isset($price[$field]) && $price[$field] !== '') {
                    $price[$field] = Decimal::mulDecimal($price[$field], $adjustment['multiplier']);
                }
            }
        }

        return $price;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function dimensionsMatch(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                return false;
            }
            $have = $actual[$key];
            if (is_bool($value) || is_bool($have)) {
                if (filter_var($have, FILTER_VALIDATE_BOOL) !== filter_var($value, FILTER_VALIDATE_BOOL)) {
                    return false;
                }
                continue;
            }
            if (strtolower(trim((string) $have)) !== strtolower(trim((string) $value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: int, 1: int}|null minutes since midnight
     */
    private function parseRange(string $range): ?array
    {
        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $range, $m) !== 1) {
            return null;
        }
        $from = (int) $m[1] * 60 + (int) $m[2];
        $to = (int) $m[3] * 60 + (int) $m[4];
        if ($from > 1440 || $to > 1440 || (int) $m[2] > 59 || (int) $m[4] > 59 || $from === $to) {
            return null;
        }

        return [$from, $to];
    }

    /**
     * @param array<string, mixed> $adjustment
     * @return list<string>
     */
    private function validateAdjustment(array $adjustment, string $label): array
    {
        $errors = [];
        $hasMultiplier = isset($adjustment['multiplier']);
        $hasPrices = is_array($adjustment['prices'] ?? null) && $adjustment['prices'] !== [];
        if (!$hasMultiplier && !$hasPrices) {
            return [$label . ' needs "multiplier" or "prices"'];
        }
        if ($hasMultiplier && !$this->isPrice($adjustment['multiplier'])) {
            $errors[] = $label . '.multiplier must be a non-negative number';
        }
        foreach ($hasPrices ? $adjustment['prices'] : [] as $field => $value) {
            if (!in_array($field, [...self::PRICE_FIELDS, ...self::MODALITY_FIELDS], true)) {
                $errors[] = $label . '.prices.' . $field . ' is not a price field';
            } elseif (!$this->isPrice($value)) {
                $errors[] = $label . '.prices.' . $field . ' must be a non-negative number';
            }
        }

        return $errors;
    }

    private function isPrice(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value) && (float) $value >= 0;
    }
}
