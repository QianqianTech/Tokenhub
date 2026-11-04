<?php

declare(strict_types=1);

namespace TokenHub\Support;

/**
 * Calendar boundaries in the deployment's business time zone (config app.business_timezone).
 *
 * All timestamps in MySQL and Redis stay UTC. Use this class whenever a value depends on
 * "which local day / week / month is it": dashboard periods, quota day/month windows.
 * Returned SQL timestamps are UTC ("Y-m-d H:i:s") so they compare directly with stored columns.
 */
final class BusinessTime
{
    private static ?\DateTimeZone $zone = null;

    public static function zone(): \DateTimeZone
    {
        if (self::$zone === null) {
            $name = trim((string) App::config()->get('app.business_timezone', 'UTC'));
            try {
                self::$zone = new \DateTimeZone($name !== '' ? $name : 'UTC');
            } catch (\Throwable) {
                self::$zone = new \DateTimeZone('UTC');
            }
        }

        return self::$zone;
    }

    /** For tests. */
    public static function reset(?string $zone = null): void
    {
        self::$zone = $zone === null ? null : new \DateTimeZone($zone);
    }

    public static function name(): string
    {
        return self::zone()->getName();
    }

    /** UTC "Y-m-d H:i:s" of the start of the local period containing $time ('day','week','month','quarter','year'). */
    public static function periodStartUtc(string $period, ?int $time = null): string
    {
        $local = (new \DateTimeImmutable('@' . ($time ?? time())))->setTimezone(self::zone());
        $start = match ($period) {
            'week' => $local->modify('monday this week')->setTime(0, 0),
            'month' => $local->modify('first day of this month')->setTime(0, 0),
            'quarter' => $local->setDate((int) $local->format('Y'), intdiv((int) $local->format('n') - 1, 3) * 3 + 1, 1)->setTime(0, 0),
            'year' => $local->setDate((int) $local->format('Y'), 1, 1)->setTime(0, 0),
            default => $local->setTime(0, 0),
        };

        return $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Bucket key for quota windows: 'day' => Ymd, 'month' => Ym (local calendar). */
    public static function bucket(string $period, ?int $time = null): string
    {
        $local = (new \DateTimeImmutable('@' . ($time ?? time())))->setTimezone(self::zone());

        return match ($period) {
            'month' => $local->format('Ym'),
            'week' => $local->format('oW'),
            'year' => $local->format('Y'),
            default => $local->format('Ymd'),
        };
    }

    /** Seconds until the end of the local period containing $time (for Redis TTLs). */
    public static function secondsUntilPeriodEnd(string $period, ?int $time = null): int
    {
        $now = $time ?? time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(self::zone());
        $end = match ($period) {
            'month' => $local->modify('first day of next month')->setTime(0, 0),
            'week' => $local->modify('monday next week')->setTime(0, 0),
            'year' => $local->setDate((int) $local->format('Y') + 1, 1, 1)->setTime(0, 0),
            default => $local->modify('+1 day')->setTime(0, 0),
        };

        return max(1, $end->getTimestamp() - $now);
    }
}
