<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

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

        return $this->applyPrice($usage, $price);
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
    public function applyPrice(array $usage, ?array $price): array
    {
        if ($price === null || !$this->hasAnyPrice($price)) {
            return ['cost_amount' => null, 'price_snapshot' => null];
        }

        $cost = 0.0;
        $cost += ((int) ($usage['input_tokens'] ?? 0)) * $this->decimal($price['input_token_price'] ?? null);
        $cost += ((int) ($usage['output_tokens'] ?? 0)) * $this->decimal($price['output_token_price'] ?? null);
        $cost += ((int) ($usage['audio_seconds'] ?? 0)) * $this->decimal($price['audio_second_price'] ?? null);
        $cost += ((int) ($usage['tts_chars'] ?? 0)) * $this->decimal($price['tts_char_price'] ?? null);
        $cost += ((int) ($usage['image_count'] ?? 0)) * $this->decimal($price['image_price'] ?? null);
        $cost += ((int) ($usage['video_seconds'] ?? 0)) * $this->decimal($price['video_second_price'] ?? null);
        $cost += $this->decimal($price['request_price'] ?? null);

        return [
            'cost_amount' => number_format($cost, 8, '.', ''),
            'price_snapshot' => [
                'price_id' => (int) $price['id'],
                'currency' => (string) ($price['currency'] ?? 'USD'),
                'effective_at' => $price['effective_at'] ?? null,
                'input_token_price' => $price['input_token_price'] ?? null,
                'output_token_price' => $price['output_token_price'] ?? null,
                'audio_second_price' => $price['audio_second_price'] ?? null,
                'tts_char_price' => $price['tts_char_price'] ?? null,
                'image_price' => $price['image_price'] ?? null,
                'video_second_price' => $price['video_second_price'] ?? null,
                'request_price' => $price['request_price'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $price
     */
    private function hasAnyPrice(array $price): bool
    {
        foreach (['input_token_price', 'output_token_price', 'audio_second_price', 'tts_char_price', 'image_price', 'video_second_price', 'request_price'] as $key) {
            if (($price[$key] ?? null) !== null && $price[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    private function decimal(mixed $value): float
    {
        return $value === null || $value === '' ? 0.0 : (float) $value;
    }
}
