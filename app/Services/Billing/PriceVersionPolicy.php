<?php

declare(strict_types=1);

namespace TokenHub\Services\Billing;

/**
 * BIZ-011: a model_prices row that has taken effect is history. Usage
 * records, settlements and reconciliation refer to it (price_snapshot.price_id),
 * so its prices may not change any more: a new price is a new row with a later
 * effective_at. Rows that are still scheduled (effective_at in the future)
 * can be edited freely.
 */
final class PriceVersionPolicy
{
    /** Columns that define what a request costs. */
    public const PRICE_COLUMNS = [
        'input_token_price', 'output_token_price', 'cache_read_token_price', 'cache_write_token_price',
        'reasoning_token_price', 'audio_second_price', 'tts_char_price', 'image_price', 'video_second_price',
        'request_price', 'image_input_token_price', 'audio_input_token_price', 'image_output_token_price',
        'audio_output_token_price', 'pricing_rules', 'currency', 'effective_at', 'model_id',
    ];

    /**
     * @param array<string, mixed> $current the stored row
     * @param array<string, mixed> $changes the requested update
     * @return list<string> price columns the update would change on an effective row ([] = allowed)
     */
    public static function blockedChanges(array $current, array $changes, ?int $now = null): array
    {
        $effectiveAt = strtotime((string) ($current['effective_at'] ?? '') . ' UTC');
        if ($effectiveAt === false || $effectiveAt > ($now ?? time())) {
            return [];
        }
        $blocked = [];
        foreach (self::PRICE_COLUMNS as $column) {
            if (!array_key_exists($column, $changes)) {
                continue;
            }
            if (self::normalize($changes[$column]) !== self::normalize($current[$column] ?? null)) {
                $blocked[] = $column;
            }
        }

        return $blocked;
    }

    /**
     * Throws (422 PRICE_VERSION_IMMUTABLE) when the update would rewrite an
     * effective price.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $changes
     */
    public static function assertEditable(array $current, array $changes, ?int $now = null): void
    {
        $blocked = self::blockedChanges($current, $changes, $now);
        if ($blocked !== []) {
            abort_json('PRICE_VERSION_IMMUTABLE', 'This price is already in effect; add a new price row with a later effective_at instead of editing: ' . implode(', ', $blocked), 422);
        }
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
        }
        $string = trim((string) $value);
        $decoded = json_decode($string, true);
        if (is_array($decoded)) {
            return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $string;
    }
}
