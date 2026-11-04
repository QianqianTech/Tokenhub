<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class RagRetrievalProfileService
{
    /**
     * @return array{min_vector_score: float, min_lexical_score: float, min_hybrid_confidence: float, exact_match_discount: float, explicit: list<string>, rerank_prefilter_factor: float, warnings: list<string>, name: string}
     */
    public function resolve(array|string|null $profile): array
    {
        $defaults = [
            'min_vector_score' => 0.35,
            'min_lexical_score' => 0.08,
            'min_hybrid_confidence' => 0.20,
            'exact_match_discount' => 0.10,
        ];
        $warnings = [];
        $values = [];
        $hasOverride = false;

        if (is_string($profile)) {
            $trimmed = trim($profile);
            if ($trimmed !== '') {
                $decoded = json_decode($trimmed, true);
                if (!is_array($decoded)) {
                    $warnings[] = 'Invalid retrieval profile JSON; defaults applied.';
                } else {
                    $values = $decoded;
                    $hasOverride = $decoded !== [];
                }
            }
        } elseif (is_array($profile)) {
            $values = $profile;
            $hasOverride = $profile !== [];
        }

        $resolved = [];
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $values)) {
                $resolved[$key] = $default;
                continue;
            }
            $raw = $values[$key];
            if (!is_numeric($raw) || !is_finite((float) $raw)) {
                $warnings[] = sprintf('%s must be finite numeric; default applied.', $key);
                $resolved[$key] = $default;
                continue;
            }
            $maximum = $key === 'exact_match_discount' ? 0.25 : 1.0;
            $value = (float) $raw;
            $clamped = max(0.0, min($maximum, $value));
            if ($clamped !== $value) {
                $warnings[] = sprintf('%s was clamped to its allowed range.', $key);
            }
            $resolved[$key] = $clamped;
        }

        $factor = $values['rerank_prefilter_factor'] ?? 0.5;

        return [
            ...$resolved,
            // Which thresholds the knowledge base set itself; the rest may come from the vector config.
            'explicit' => array_values(array_intersect(array_keys($defaults), array_keys($values))),
            // With a rerank model gating results, vector/keyword thresholds only pre-filter at this share.
            'rerank_prefilter_factor' => is_numeric($factor) ? max(0.0, min(1.0, (float) $factor)) : 0.5,
            'warnings' => $warnings,
            'name' => $hasOverride ? 'kb_override' : 'default',
        ];
    }
}
