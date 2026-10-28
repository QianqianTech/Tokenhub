<?php

declare(strict_types=1);

namespace TokenHub\Support;

/**
 * Exact non-negative decimal arithmetic on strings (no bcmath/gmp needed).
 *
 * Money and per-token prices are DECIMAL(18,8) in the database; multiplying
 * them as floats drifts in the last digits. Values are handled as integer
 * strings scaled by 10^SCALE and rounded half-up only when formatting.
 */
final class Decimal
{
    public const SCALE = 8;

    /**
     * quantity (integer count) * unit price (decimal string) -> decimal string
     */
    public static function mul(int|string $quantity, string|int|float|null $price): string
    {
        $q = self::toScaled((string) $quantity, 0);
        $p = self::toScaled(self::normalize($price), self::SCALE);
        if ($q === '0' || $p === '0') {
            return self::format('0');
        }

        return self::format(self::mulIntegers($q, $p));
    }

    /**
     * decimal * decimal (both non-negative) -> decimal string, rounded half up.
     * Used for price multipliers (peak x2, upstream cost x1.2, ...).
     */
    public static function mulDecimal(string|int|float|null $a, string|int|float|null $b): string
    {
        $x = self::toScaled(self::normalize($a), self::SCALE);
        $y = self::toScaled(self::normalize($b), self::SCALE);
        if ($x === '0' || $y === '0') {
            return self::format('0');
        }
        $product = str_pad(self::mulIntegers($x, $y), self::SCALE + 1, '0', STR_PAD_LEFT);
        $kept = substr($product, 0, -self::SCALE);
        if ((int) $product[strlen($product) - self::SCALE] >= 5) {
            $kept = self::addIntegers($kept, '1');
        }

        return self::format(ltrim($kept, '0') ?: '0');
    }

    public static function add(string ...$values): string
    {
        $sum = '0';
        foreach ($values as $value) {
            $sum = self::addIntegers($sum, self::toScaled(self::normalize($value), self::SCALE));
        }

        return self::format($sum);
    }

    /**
     * a - b of two non-negative decimals -> signed decimal string
     * ("-1.50000000" when b > a). Used for settlement deltas.
     */
    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        $x = self::toScaled(self::normalize($a), self::SCALE);
        $y = self::toScaled(self::normalize($b), self::SCALE);
        if (self::compare(self::format($x), self::format($y)) >= 0) {
            return self::format(self::subtractIntegers($x, $y));
        }

        return '-' . self::format(self::subtractIntegers($y, $x));
    }

    public static function compare(string $a, string $b): int
    {
        $x = ltrim(self::toScaled(self::normalize($a), self::SCALE), '0') ?: '0';
        $y = ltrim(self::toScaled(self::normalize($b), self::SCALE), '0') ?: '0';
        if (strlen($x) !== strlen($y)) {
            return strlen($x) <=> strlen($y);
        }

        return strcmp($x, $y) <=> 0;
    }

    public static function normalize(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_float($value)) {
            $value = number_format($value, self::SCALE, '.', '');
        }
        $value = trim((string) $value);
        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            if (is_numeric($value) && (float) $value >= 0) {
                return number_format((float) $value, self::SCALE, '.', '');
            }
            return '0';
        }

        return $value;
    }

    /**
     * Scaled integer string -> "123.45678900"
     */
    private static function format(string $scaled): string
    {
        $scaled = ltrim($scaled, '0');
        $scaled = str_pad($scaled === '' ? '0' : $scaled, self::SCALE + 1, '0', STR_PAD_LEFT);

        return substr($scaled, 0, -self::SCALE) . '.' . substr($scaled, -self::SCALE);
    }

    private static function toScaled(string $decimal, int $scale): string
    {
        [$int, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $int = ltrim($int, '0');
        if (strlen($frac) > $scale) {
            // round half up at the requested scale
            $roundDigit = (int) $frac[$scale];
            $frac = substr($frac, 0, $scale);
            $digits = ($int === '' ? '0' : $int) . $frac;
            if ($roundDigit >= 5) {
                $digits = self::addIntegers($digits, '1');
            }
            return ltrim($digits, '0') ?: '0';
        }
        $digits = ($int === '' ? '' : $int) . str_pad($frac, $scale, '0');

        return ltrim($digits, '0') ?: '0';
    }

    private static function addIntegers(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry + ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0);
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
            $i--;
            $j--;
        }

        return ltrim($result, '0') ?: '0';
    }

    /**
     * a - b for integer strings with a >= b.
     */
    private static function subtractIntegers(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $digit = (int) $a[$i] - $borrow - ($j >= 0 ? (int) $b[$j] : 0);
            $borrow = $digit < 0 ? 1 : 0;
            $result = ($digit + 10 * $borrow) . $result;
            $i--;
            $j--;
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function mulIntegers(string $a, string $b): string
    {
        $lengthA = strlen($a);
        $lengthB = strlen($b);
        $product = array_fill(0, $lengthA + $lengthB, 0);
        for ($i = $lengthA - 1; $i >= 0; $i--) {
            for ($j = $lengthB - 1; $j >= 0; $j--) {
                $sum = $product[$i + $j + 1] + ((int) $a[$i]) * ((int) $b[$j]);
                $product[$i + $j + 1] = $sum % 10;
                $product[$i + $j] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $product), '0') ?: '0';
    }
}
