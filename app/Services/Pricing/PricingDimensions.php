<?php

declare(strict_types=1);

namespace TokenHub\Services\Pricing;

/**
 * Request attributes that change a media price (resolution, audio, quality,
 * mode ...). They are attached to usage as "pricing_dimensions" and matched by
 * PricingRules "dimension_prices".
 */
final class PricingDimensions
{
    private const KEYS = [
        'resolution', 'size', 'quality', 'ratio', 'aspect_ratio', 'mode', 'style',
        'image_size', 'fps', 'background', 'voice',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string|bool|int>
     */
    public static function fromPayload(string $capability, array $payload): array
    {
        if (!in_array($capability, ['image_generation', 'video_generation', 'tts', 'music_generation'], true)) {
            return [];
        }
        $dimensions = [];
        foreach (self::KEYS as $key) {
            $value = $payload[$key] ?? $payload['parameters'][$key] ?? $payload['config'][$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $dimensions[$key] = is_bool($value) ? $value : (string) $value;
            }
        }
        foreach (['generate_audio', 'with_audio', 'audio', 'sound'] as $key) {
            $value = $payload[$key] ?? $payload['parameters'][$key] ?? null;
            if (is_bool($value) || in_array($value, ['true', 'false', 0, 1, '0', '1'], true)) {
                $dimensions['generate_audio'] = filter_var($value, FILTER_VALIDATE_BOOL);
                break;
            }
        }
        if (isset($payload['duration']) && is_numeric($payload['duration'])) {
            $dimensions['duration'] = (int) $payload['duration'];
        }

        return $dimensions;
    }
}
