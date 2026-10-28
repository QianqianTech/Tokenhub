<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Pricing\PricingRules;
use TokenHub\Support\App;
use TokenHub\Support\Decimal;

final class PriceService
{
    /**
     * @param array<string, mixed> $usage
     * @return array{cost_amount: ?string, price_snapshot: ?array<string, mixed>}
     */
    public function priceUsage(int $modelId, array $usage, ?string $at = null): array
    {
        $lookup = $this->lookupConstraint($modelId, $at ?? gmdate('Y-m-d H:i:s'));
        $price = App::db()->first(
            'SELECT * FROM model_prices WHERE ' . $lookup['where'] . ' ORDER BY ' . $lookup['order'] . ' LIMIT 1',
            $lookup['params']
        );

        return $this->applyPrice($usage, $price, $at ?? gmdate('Y-m-d H:i:s'));
    }

    /**
     * @return array{where: string, params: list<mixed>, order: string}
     */
    public function lookupConstraint(int $modelId, string $effectiveAt): array
    {
        return [
            'where' => 'model_id = ? AND effective_at <= ?',
            'params' => [$modelId, $effectiveAt],
            'order' => 'effective_at DESC, id DESC',
        ];
    }

    /**
     * @param array<string, mixed> $usage
     * @param array<string, mixed>|null $price
     * @return array{cost_amount: ?string, price_snapshot: ?array<string, mixed>}
     */
    public function applyPrice(array $usage, ?array $price, ?string $at = null): array
    {
        if ($price === null || !$this->hasAnyPrice($price)) {
            return ['cost_amount' => null, 'price_snapshot' => null];
        }
        $base = $price;
        $rules = new PricingRules();
        $resolved = $rules->resolve($price, $usage, $at ?? gmdate('Y-m-d H:i:s'));
        $price = $resolved['price'];

        // Canonical usage: input_tokens includes cache reads/writes and
        // output_tokens includes reasoning (see UsageNormalizer). Cache and
        // reasoning tokens use their own price when configured, otherwise the
        // regular input/output price, so totals stay correct either way.
        // Image/audio tokens are carved out of the uncached input and the
        // non-reasoning output only when a modality price is configured.
        $input = max(0, (int) ($usage['input_tokens'] ?? 0));
        $cacheRead = min($input, max(0, (int) ($usage['cache_read_tokens'] ?? 0)));
        $cacheWrite = min($input - $cacheRead, max(0, (int) ($usage['cache_write_tokens'] ?? 0)));
        $output = max(0, (int) ($usage['output_tokens'] ?? 0));
        $reasoning = min($output, max(0, (int) ($usage['reasoning_tokens'] ?? 0)));
        $inputPrice = $price['input_token_price'] ?? null;
        $outputPrice = $price['output_token_price'] ?? null;
        $cacheReadPrice = $this->present($price['cache_read_token_price'] ?? null) ? $price['cache_read_token_price'] : $inputPrice;
        $cacheWritePrice = $this->present($price['cache_write_token_price'] ?? null) ? $price['cache_write_token_price'] : $inputPrice;
        $reasoningPrice = $this->present($price['reasoning_token_price'] ?? null) ? $price['reasoning_token_price'] : $outputPrice;

        $uncachedInput = $input - $cacheRead - $cacheWrite;
        $imageIn = $this->present($price['image_input_token_price'] ?? null) ? min($uncachedInput, max(0, (int) ($usage['image_input_tokens'] ?? 0))) : 0;
        $audioIn = $this->present($price['audio_input_token_price'] ?? null) ? min($uncachedInput - $imageIn, max(0, (int) ($usage['audio_input_tokens'] ?? 0))) : 0;
        $visibleOutput = $output - $reasoning;
        $imageOut = $this->present($price['image_output_token_price'] ?? null) ? min($visibleOutput, max(0, (int) ($usage['image_output_tokens'] ?? 0))) : 0;
        $audioOut = $this->present($price['audio_output_token_price'] ?? null) ? min($visibleOutput - $imageOut, max(0, (int) ($usage['audio_output_tokens'] ?? 0))) : 0;

        $cost = Decimal::add(
            Decimal::mul($uncachedInput - $imageIn - $audioIn, $inputPrice),
            Decimal::mul($imageIn, $price['image_input_token_price'] ?? null),
            Decimal::mul($audioIn, $price['audio_input_token_price'] ?? null),
            Decimal::mul($cacheRead, $cacheReadPrice),
            Decimal::mul($cacheWrite, $cacheWritePrice),
            Decimal::mul($visibleOutput - $imageOut - $audioOut, $outputPrice),
            Decimal::mul($imageOut, $price['image_output_token_price'] ?? null),
            Decimal::mul($audioOut, $price['audio_output_token_price'] ?? null),
            Decimal::mul($reasoning, $reasoningPrice),
            Decimal::mul((int) ($usage['audio_seconds'] ?? 0), $price['audio_second_price'] ?? null),
            Decimal::mul((int) ($usage['tts_chars'] ?? 0), $price['tts_char_price'] ?? null),
            Decimal::mul((int) ($usage['image_count'] ?? 0), $price['image_price'] ?? null),
            Decimal::mul((int) ($usage['video_seconds'] ?? 0), $price['video_second_price'] ?? null),
            Decimal::normalize($price['request_price'] ?? null),
        );
        $source = 'catalog_estimate';

        // Optional cost-plus billing: upstream-reported cost (OpenRouter
        // usage.cost, BFL cost, xAI cost ticks, ...) x multiplier.
        $multiplier = $rules->upstreamCostMultiplier($resolved['rules']);
        $upstreamCost = $usage['upstream_cost'] ?? null;
        if ($multiplier !== null && $upstreamCost !== null && $upstreamCost !== '' && is_numeric($upstreamCost) && (float) $upstreamCost >= 0) {
            $cost = Decimal::mulDecimal((string) $upstreamCost, $multiplier);
            $source = 'upstream_cost';
        }

        $snapshot = [
            'price_id' => (int) ($base['id'] ?? 0),
            'currency' => strtoupper((string) ($base['currency'] ?? 'USD')),
            'effective_at' => $base['effective_at'] ?? null,
            'pricing_rule' => 'v3-rules',
            'input_token_price' => $price['input_token_price'] ?? null,
            'output_token_price' => $price['output_token_price'] ?? null,
            'cache_read_token_price' => $price['cache_read_token_price'] ?? null,
            'cache_write_token_price' => $price['cache_write_token_price'] ?? null,
            'reasoning_token_price' => $price['reasoning_token_price'] ?? null,
            'audio_second_price' => $price['audio_second_price'] ?? null,
            'tts_char_price' => $price['tts_char_price'] ?? null,
            'image_price' => $price['image_price'] ?? null,
            'video_second_price' => $price['video_second_price'] ?? null,
            'request_price' => $price['request_price'] ?? null,
            'source' => $source,
        ];
        foreach (PricingRules::MODALITY_FIELDS as $field) {
            if ($this->present($price[$field] ?? null)) {
                $snapshot[$field] = $price[$field];
            }
        }
        if ($resolved['applied'] !== []) {
            $snapshot['applied_rules'] = $resolved['applied'];
        }
        if ($source === 'upstream_cost') {
            $snapshot['upstream_cost'] = (string) $upstreamCost;
            $snapshot['upstream_cost_multiplier'] = $multiplier;
        }

        return ['cost_amount' => $cost, 'price_snapshot' => $snapshot];
    }

    private function present(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * @param array<string, mixed> $price
     */
    private function hasAnyPrice(array $price): bool
    {
        foreach (['input_token_price', 'output_token_price', 'cache_read_token_price', 'cache_write_token_price', 'reasoning_token_price', 'audio_second_price', 'tts_char_price', 'image_price', 'video_second_price', 'request_price'] as $key) {
            if (($price[$key] ?? null) !== null && $price[$key] !== '') {
                return true;
            }
        }

        return false;
    }
}
