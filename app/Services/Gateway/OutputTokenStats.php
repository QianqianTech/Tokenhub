<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Support\App;

/**
 * Recent measured output-token counts per channel (model row), used to size
 * output reservations (MT-001): the last GATEWAY_OUTPUT_STATS_SAMPLES
 * (default 200) measured completions are kept in a capped Redis list and the
 * 95th percentile is cached for 5 minutes.
 */
final class OutputTokenStats
{
    private const PREFIX = 'gateway:output_tokens:';
    private const P95_TTL_SECONDS = 300;

    public function record(int $modelId, int $outputTokens): void
    {
        if ($modelId <= 0 || $outputTokens < 0) {
            return;
        }
        $samples = $this->samples();
        try {
            $redis = App::redis()->connection();
            $pipe = $redis->pipeline();
            $pipe->lPush(self::PREFIX . $modelId, (string) $outputTokens);
            $pipe->lTrim(self::PREFIX . $modelId, 0, $samples - 1);
            $pipe->expire(self::PREFIX . $modelId, 7 * 86400);
            $pipe->exec();
        } catch (Throwable) {
        }
    }

    /**
     * 95th percentile of recent output tokens, null with fewer than
     * GATEWAY_OUTPUT_STATS_MIN_SAMPLES (default 50) samples.
     */
    public function p95(int $modelId): ?int
    {
        if ($modelId <= 0) {
            return null;
        }
        try {
            $cached = App::redis()->get(self::PREFIX . 'p95:' . $modelId);
            if (is_numeric($cached)) {
                return (int) $cached >= 0 ? (int) $cached : null;
            }
            $values = array_map('intval', App::redis()->connection()->lRange(self::PREFIX . $modelId, 0, $this->samples() - 1) ?: []);
        } catch (Throwable) {
            return null;
        }
        $p95 = self::percentile($values, 0.95, max(1, (int) env_value('GATEWAY_OUTPUT_STATS_MIN_SAMPLES', 50)));
        try {
            // -1 caches "not enough samples" as well.
            App::redis()->set(self::PREFIX . 'p95:' . $modelId, (string) ($p95 ?? -1), self::P95_TTL_SECONDS);
        } catch (Throwable) {
        }

        return $p95;
    }

    /**
     * @param list<int> $values
     */
    public static function percentile(array $values, float $quantile, int $minSamples = 1): ?int
    {
        if (count($values) < $minSamples || $values === []) {
            return null;
        }
        sort($values);
        $index = (int) ceil($quantile * count($values)) - 1;

        return $values[max(0, min(count($values) - 1, $index))];
    }

    private function samples(): int
    {
        return max(10, (int) env_value('GATEWAY_OUTPUT_STATS_SAMPLES', 200));
    }
}
