<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Converts protocol specific usage objects into TokenHub's canonical usage.
 *
 * Canonical semantics (same for streaming and non-streaming):
 * - input_tokens        every prompt token sent upstream, INCLUDING cache reads
 *                       and cache writes (OpenAI "prompt_tokens" semantics).
 *                       Anthropic reports uncached input separately, so its
 *                       input_tokens + cache_read + cache_creation are summed.
 * - cache_read_tokens   subset of input_tokens served from prompt cache.
 * - cache_write_tokens  subset of input_tokens written to prompt cache.
 * - output_tokens       every generated token INCLUDING reasoning tokens.
 * - reasoning_tokens    subset of output_tokens spent on hidden reasoning.
 * - total_tokens        input_tokens + output_tokens unless upstream says otherwise.
 * - usage_source        "measured" when upstream reported usage, "estimated"
 *                       when TokenHub had to approximate it.
 * - upstream_cost       aggregator-reported cost (OpenRouter usage.cost), if any.
 *
 * Streaming values are cumulative snapshots; callers must merge with merge(),
 * which keeps the latest value per field instead of adding them up.
 */
final class UsageNormalizer
{
    public const KIND_CHAT = 'chat';
    public const KIND_RESPONSES = 'responses';
    public const KIND_ANTHROPIC = 'anthropic';
    public const KIND_EMBEDDING = 'embedding';
    public const KIND_RERANK = 'rerank';
    public const KIND_GENERIC = 'generic';
    public const KIND_GEMINI = 'gemini';

    public static function kindForEndpoint(string $endpoint, string $protocol = 'openai'): string
    {
        return match (true) {
            str_contains($endpoint, 'enerateContent') => self::KIND_GEMINI,
            str_contains($endpoint, '/messages') => self::KIND_ANTHROPIC,
            str_contains($endpoint, '/responses') => self::KIND_RESPONSES,
            str_contains($endpoint, '/embeddings') => self::KIND_EMBEDDING,
            str_contains($endpoint, '/rerank') => self::KIND_RERANK,
            str_contains($endpoint, '/chat/completions') => self::KIND_CHAT,
            $protocol === 'anthropic' => self::KIND_ANTHROPIC,
            default => self::KIND_GENERIC,
        };
    }

    /**
     * @param array<string, mixed> $usage raw upstream usage object
     * @return array<string, int|float|string|null>|null null when nothing measurable was present
     */
    public function normalize(string $kind, array $usage): ?array
    {
        if ($usage === []) {
            return null;
        }

        $result = match ($kind) {
            self::KIND_ANTHROPIC => $this->anthropic($usage),
            self::KIND_RESPONSES => $this->responses($usage),
            self::KIND_RERANK => $this->rerank($usage),
            self::KIND_GEMINI => $this->gemini($usage),
            default => $this->chat($usage),
        };
        if ($result === null) {
            return null;
        }

        if (isset($usage['cost']) && is_numeric($usage['cost'])) {
            $result['upstream_cost'] = (string) $usage['cost'];
        }
        $result['usage_source'] = 'measured';

        return $result;
    }

    /**
     * Extracts usage from a complete non-streaming response body.
     *
     * @param array<string, mixed> $response
     * @return array<string, int|float|string|null>|null
     */
    public function fromResponse(string $kind, array $response): ?array
    {
        $usage = $kind === self::KIND_GEMINI ? ($response['usageMetadata'] ?? null) : ($response['usage'] ?? null);
        if ($kind === self::KIND_RERANK && !is_array($usage) && is_array($response['meta']['billed_units'] ?? null)) {
            // Cohere-style rerank: meta.billed_units.input_tokens
            $usage = $response['meta']['billed_units'];
        }
        if ($kind === self::KIND_RESPONSES && !is_array($usage) && is_array($response['response']['usage'] ?? null)) {
            $usage = $response['response']['usage'];
        }

        return is_array($usage) ? $this->normalize($kind, $usage) : null;
    }

    /**
     * Merges a later cumulative snapshot over an earlier one.
     *
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>|null $next
     * @return array<string, mixed>|null
     */
    public function merge(?array $current, ?array $next): ?array
    {
        if ($next === null) {
            return $current;
        }
        if ($current === null) {
            return $next;
        }
        foreach ($next as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_int($value) && is_int($current[$key] ?? null)) {
                $current[$key] = max($current[$key], $value);
                continue;
            }
            $current[$key] = $value;
        }
        $current['total_tokens'] = max(
            (int) ($current['total_tokens'] ?? 0),
            (int) ($current['input_tokens'] ?? 0) + (int) ($current['output_tokens'] ?? 0)
        );

        return $current;
    }

    /**
     * @param array<string, mixed> $estimate
     * @return array<string, int|string>
     */
    public function estimated(array $estimate, int $outputChars): array
    {
        $input = (int) ($estimate['input_tokens'] ?? 0);
        $output = $outputChars > 0 ? (int) ceil($outputChars / 4) : 0;

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
            'usage_source' => 'estimated',
        ];
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, int>|null
     */
    private function chat(array $usage): ?array
    {
        $input = $this->int($usage, ['prompt_tokens', 'input_tokens']);
        $output = $this->int($usage, ['completion_tokens', 'output_tokens']);
        if ($input === null && $output === null && !isset($usage['total_tokens'])) {
            return null;
        }
        $input ??= 0;
        $output ??= 0;
        $promptDetails = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : (is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : []);
        $completionDetails = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : (is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : []);

        $cacheRead = $this->int($promptDetails, ['cached_tokens']) ?? $this->int($usage, ['prompt_cache_hit_tokens', 'cached_tokens']) ?? 0;
        $cacheWrite = $this->int($promptDetails, ['cache_write_tokens', 'cache_creation_tokens']) ?? $this->int($usage, ['cache_write_tokens']) ?? 0;
        $reasoning = $this->int($completionDetails, ['reasoning_tokens']) ?? $this->int($usage, ['reasoning_tokens']) ?? 0;
        $total = $this->int($usage, ['total_tokens']) ?? ($input + $output);

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => max($total, $input + $output),
            'cache_read_tokens' => min($cacheRead, $input),
            'cache_write_tokens' => min($cacheWrite, $input),
            'reasoning_tokens' => min($reasoning, $output),
            ...$this->modalityTokens($promptDetails, $completionDetails, $input, $output),
        ];
    }

    /**
     * Rerank providers report the tokens read as total_tokens (Jina, TEI,
     * vLLM) or prompt/input tokens; there is no generated output.
     *
     * @param array<string, mixed> $usage
     * @return array<string, int>|null
     */
    private function rerank(array $usage): ?array
    {
        $input = $this->int($usage, ['prompt_tokens', 'input_tokens', 'total_tokens']);
        if ($input === null) {
            return null; // e.g. only search_units: fall back to the estimate
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => 0,
            'total_tokens' => $input,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
        ];
    }

    /**
     * Image/audio token subsets (only present when the upstream reports them),
     * so modality prices can be applied.
     *
     * @param array<string, mixed> $inputDetails
     * @param array<string, mixed> $outputDetails
     * @return array<string, int>
     */
    private function modalityTokens(array $inputDetails, array $outputDetails, int $input, int $output): array
    {
        $tokens = [];
        foreach (['image_input_tokens' => [$inputDetails, 'image_tokens', $input], 'audio_input_tokens' => [$inputDetails, 'audio_tokens', $input], 'image_output_tokens' => [$outputDetails, 'image_tokens', $output], 'audio_output_tokens' => [$outputDetails, 'audio_tokens', $output]] as $key => [$details, $field, $cap]) {
            $value = $this->int($details, [$field]);
            if ($value !== null && $value > 0) {
                $tokens[$key] = min($value, $cap);
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, int>|null
     */
    private function responses(array $usage): ?array
    {
        $input = $this->int($usage, ['input_tokens', 'prompt_tokens']);
        $output = $this->int($usage, ['output_tokens', 'completion_tokens']);
        if ($input === null && $output === null) {
            return null;
        }
        $input ??= 0;
        $output ??= 0;
        $inputDetails = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $outputDetails = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];
        $total = $this->int($usage, ['total_tokens']) ?? ($input + $output);

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => max($total, $input + $output),
            'cache_read_tokens' => min($this->int($inputDetails, ['cached_tokens']) ?? 0, $input),
            'cache_write_tokens' => min($this->int($inputDetails, ['cache_write_tokens']) ?? 0, $input),
            'reasoning_tokens' => min($this->int($outputDetails, ['reasoning_tokens']) ?? 0, $output),
            ...$this->modalityTokens($inputDetails, $outputDetails, $input, $output),
        ];
    }

    /**
     * Gemini usageMetadata: candidates + thoughts are output, cached content
     * is a subset of the prompt; per-modality details give image/audio tokens.
     *
     * @param array<string, mixed> $usage
     * @return array<string, int>|null
     */
    private function gemini(array $usage): ?array
    {
        $prompt = $this->int($usage, ['promptTokenCount']);
        $candidates = $this->int($usage, ['candidatesTokenCount']);
        if ($prompt === null && $candidates === null && !isset($usage['totalTokenCount'])) {
            return null;
        }
        $input = ($prompt ?? 0) + ($this->int($usage, ['toolUsePromptTokenCount']) ?? 0);
        $thoughts = $this->int($usage, ['thoughtsTokenCount']) ?? 0;
        $output = ($candidates ?? 0) + $thoughts;
        $modality = static function (mixed $details, string $name): int {
            $sum = 0;
            foreach (is_array($details) ? $details : [] as $detail) {
                if (is_array($detail) && strtoupper((string) ($detail['modality'] ?? '')) === $name) {
                    $sum += (int) ($detail['tokenCount'] ?? 0);
                }
            }
            return $sum;
        };
        $result = [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => max((int) ($usage['totalTokenCount'] ?? 0), $input + $output),
            'cache_read_tokens' => min($this->int($usage, ['cachedContentTokenCount']) ?? 0, $input),
            'cache_write_tokens' => 0,
            'reasoning_tokens' => min($thoughts, $output),
        ];
        foreach (['image_input_tokens' => [$usage['promptTokensDetails'] ?? [], 'IMAGE'], 'audio_input_tokens' => [$usage['promptTokensDetails'] ?? [], 'AUDIO'], 'image_output_tokens' => [$usage['candidatesTokensDetails'] ?? [], 'IMAGE'], 'audio_output_tokens' => [$usage['candidatesTokensDetails'] ?? [], 'AUDIO']] as $key => [$details, $name]) {
            $value = $modality($details, $name);
            if ($value > 0) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, int>|null
     */
    private function anthropic(array $usage): ?array
    {
        $uncached = $this->int($usage, ['input_tokens']);
        $output = $this->int($usage, ['output_tokens']);
        $cacheRead = $this->int($usage, ['cache_read_input_tokens']);
        $cacheWrite = $this->int($usage, ['cache_creation_input_tokens']);
        if ($uncached === null && $output === null && $cacheRead === null && $cacheWrite === null) {
            return null;
        }
        $input = ($uncached ?? 0) + ($cacheRead ?? 0) + ($cacheWrite ?? 0);
        $output ??= 0;

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'cache_read_tokens' => $cacheRead ?? 0,
            'cache_write_tokens' => $cacheWrite ?? 0,
            'reasoning_tokens' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $keys
     */
    private function int(array $source, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_numeric($source[$key])) {
                return max(0, (int) $source[$key]);
            }
        }

        return null;
    }
}
