<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Composable capability contract for one concrete model.
 *
 * Stored in models.capabilities (JSON) as three layers:
 *   {"upstream": {...}, "verified": {...}, "admin": {...}}
 * Effective value per field = admin override > verified (acceptance tests) >
 * upstream catalog. Each layer may contain:
 *   input_modalities  ["text","image","video","audio","file"]
 *   output_modalities ["text","image","audio","embedding"]
 *   features          {"tools":bool,"parallel_tool_calls":bool,"json_object":bool,
 *                      "json_schema":bool,"reasoning":bool,"streaming":bool}
 *   context_length, max_output_tokens
 *
 * Unknown is not support: a model that HAS a contract but does not declare a
 * feature is treated as not supporting it. Models without any contract (legacy
 * rows created before capability contracts) keep the previous behaviour and
 * are only matched on models.capability / capability_tags.
 */
final class ModelCapabilityContract
{
    public const FEATURES = ['tools', 'parallel_tool_calls', 'json_object', 'json_schema', 'reasoning', 'streaming'];

    /** @var array<string, mixed> */
    private array $effective;

    /**
     * @param array<string, mixed>|null $layers
     */
    public function __construct(private readonly ?array $layers)
    {
        $this->effective = $this->resolve($layers ?? []);
    }

    public static function fromRoute(array $route): self
    {
        $raw = $route['capabilities'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $raw = json_decode($raw, true);
        }

        return new self(is_array($raw) && $raw !== [] ? $raw : null);
    }

    public function isLegacy(): bool
    {
        return $this->layers === null;
    }

    /**
     * true / false when declared, null when unknown.
     */
    public function supports(string $feature): ?bool
    {
        $features = $this->effective['features'] ?? [];
        if (is_array($features) && array_key_exists($feature, $features)) {
            return (bool) $features[$feature];
        }

        return null;
    }

    public function acceptsInput(string $modality): ?bool
    {
        $modalities = $this->effective['input_modalities'] ?? null;

        return is_array($modalities) ? in_array($modality, $modalities, true) : null;
    }

    public function maxOutputTokens(): ?int
    {
        $value = $this->effective['max_output_tokens'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function contextLength(): ?int
    {
        $value = $this->effective['context_length'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Features and modalities a request needs, derived from its payload.
     *
     * @param array<string, mixed> $payload
     * @return array{features: list<string>, input_modalities: list<string>}
     */
    public static function requirements(array $payload, bool $stream, string $capability): array
    {
        $features = [];
        if (!empty($payload['tools']) || !empty($payload['functions'])) {
            $features[] = 'tools';
        }
        if (($payload['parallel_tool_calls'] ?? null) === true) {
            $features[] = 'parallel_tool_calls';
        }
        $format = $payload['response_format']['type'] ?? $payload['text']['format']['type'] ?? null;
        if ($format === 'json_schema') {
            $features[] = 'json_schema';
        } elseif ($format === 'json_object') {
            $features[] = 'json_object';
        }
        if (isset($payload['reasoning']) || isset($payload['reasoning_effort']) || isset($payload['thinking'])) {
            $features[] = 'reasoning';
        }
        if ($stream) {
            $features[] = 'streaming';
        }
        $modalities = ['text'];
        if (in_array($capability, InputModalities::TEXT_FAMILY, true)) {
            $counts = InputModalities::count($payload);
            foreach (['image', 'video', 'audio'] as $modality) {
                if ($counts[$modality] > 0) {
                    $modalities[] = $modality;
                }
            }
        }
        if ($capability === 'vision_understanding' && !in_array('image', $modalities, true)) {
            $modalities[] = 'image';
        }

        return ['features' => $features, 'input_modalities' => $modalities];
    }

    /**
     * Input modalities a legacy model (no contract) accepts, from its
     * capability and capability_tags:
     *   vision_understanding -> image; video_understanding -> image + video;
     *   audio_understanding -> audio.
     *
     * @param array<string, mixed> $route
     * @return list<string>
     */
    public static function legacyInputs(array $route): array
    {
        $tags = $route['capability_tags'] ?? null;
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }
        $capabilities = [(string) ($route['capability'] ?? ''), ...(is_array($tags) ? array_map('strval', $tags) : [])];
        $inputs = ['text'];
        foreach ($capabilities as $capability) {
            $inputs = [...$inputs, ...match ($capability) {
                'vision_understanding' => ['image'],
                'video_understanding' => ['image', 'video'],
                'audio_understanding' => ['audio'],
                default => [],
            }];
        }

        return array_values(array_unique($inputs));
    }

    /**
     * Unmet requirements of a candidate route: the contract when the model
     * has one, otherwise the modalities implied by capability / tags
     * (features stay unchecked for legacy rows).
     *
     * @param array<string, mixed> $route
     * @param array{features: list<string>, input_modalities: list<string>} $requirements
     * @return list<string>
     */
    public static function unmetForRoute(array $route, array $requirements): array
    {
        $contract = self::fromRoute($route);
        if (!$contract->isLegacy() && is_array($contract->effective()['input_modalities'] ?? null)) {
            return $contract->unmet($requirements);
        }
        $missing = $contract->isLegacy() ? [] : array_values(array_filter($contract->unmet(['features' => $requirements['features'], 'input_modalities' => ['text']])));
        $accepted = self::legacyInputs($route);
        foreach ($requirements['input_modalities'] as $modality) {
            if (!in_array($modality, $accepted, true)) {
                $missing[] = 'input:' . $modality;
            }
        }

        return $missing;
    }

    /**
     * @param array{features: list<string>, input_modalities: list<string>} $requirements
     * @return list<string> unmet requirement names; [] when the model qualifies
     */
    public function unmet(array $requirements): array
    {
        if ($this->isLegacy()) {
            return [];
        }
        $missing = [];
        foreach ($requirements['features'] as $feature) {
            if ($this->supports($feature) !== true) {
                $missing[] = $feature;
            }
        }
        foreach ($requirements['input_modalities'] as $modality) {
            if ($modality !== 'text' && $this->acceptsInput($modality) !== true) {
                $missing[] = 'input:' . $modality;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public function effective(): array
    {
        return $this->effective;
    }

    /**
     * Writes one layer, keeping the others untouched.
     *
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function withLayer(?array $existing, string $layer, array $values): array
    {
        $existing ??= [];
        $existing[$layer] = $values;

        return $existing;
    }

    /**
     * @param array<string, mixed> $layers
     * @return array<string, mixed>
     */
    private function resolve(array $layers): array
    {
        $effective = [];
        foreach (['upstream', 'verified', 'admin'] as $layer) {
            $values = $layers[$layer] ?? null;
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $key => $value) {
                if ($key === 'features' && is_array($value)) {
                    $effective['features'] = array_merge(is_array($effective['features'] ?? null) ? $effective['features'] : [], $value);
                    continue;
                }
                $effective[$key] = $value;
            }
        }

        return $effective;
    }
}
