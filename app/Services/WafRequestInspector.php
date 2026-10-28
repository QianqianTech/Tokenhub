<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\Request;

final class WafRequestInspector
{
    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed> $settings
     * @return array{matched:bool,blocked:bool,risk_score:int,categories:list<string>,hits:list<array<string,mixed>>}
     */
    public function inspect(Request $request, array $rules, array $settings): array
    {
        if (($settings['enabled'] ?? true) !== true || in_array($request->path, $settings['excluded_paths'] ?? [], true)) {
            return $this->emptyResult();
        }

        $score = 0;
        $categories = [];
        $hits = [];
        $matchedRules = [];
        foreach ($rules as $rule) {
            if (($rule['status'] ?? 'active') !== 'active') {
                continue;
            }
            $matcher = is_array($rule['matcher'] ?? null) ? $rule['matcher'] : json_decode((string) ($rule['matcher'] ?? '{}'), true);
            if (!is_array($matcher) || ($matcher['type'] ?? '') !== 'attack') {
                continue;
            }
            $ruleCode = (string) ($rule['rule_code'] ?? 'attack');
            if (isset($matchedRules[$ruleCode])) {
                continue;
            }
            foreach ($this->signals($request, $matcher['targets'] ?? ['path', 'query', 'header', 'body'], $settings) as $signal) {
                foreach ($matcher['categories'] ?? [] as $category) {
                    if ($this->matchesCategory((string) $category, $signal['normalized'])) {
                        $matchedRules[$ruleCode] = true;
                        $weight = ($matcher['critical'] ?? false) === true ? (int) ($settings['block_threshold'] ?? 100) : (int) ($matcher['weight'] ?? 0);
                        $score += max(0, $weight);
                        $categories[] = (string) $category;
                        $hits[] = [
                            'rule_code' => $ruleCode,
                            'category' => (string) $category,
                            'target' => $signal['target'],
                            'fingerprint' => hash('sha256', $signal['normalized']),
                            'weight' => max(0, $weight),
                        ];
                        break 2;
                    }
                }
            }
        }

        $categories = array_values(array_unique($categories));
        sort($categories);
        $threshold = max(1, (int) ($settings['block_threshold'] ?? 100));
        return [
            'matched' => $hits !== [],
            'blocked' => $score >= $threshold,
            'risk_score' => $score,
            'categories' => $categories,
            'hits' => $hits,
        ];
    }

    /**
     * @param list<string> $targets
     * @param array<string, mixed> $settings
     * @return list<array{target:string,normalized:string}>
     */
    private function signals(Request $request, array $targets, array $settings): array
    {
        $values = [];
        $maxFields = max(1, (int) ($settings['max_field_count'] ?? 128));
        $maxBytes = max(128, (int) ($settings['max_field_bytes'] ?? 4096));
        if (in_array('path', $targets, true)) {
            $values[] = ['target' => 'path', 'value' => $request->path];
        }
        if (in_array('query', $targets, true)) {
            $this->flatten($request->query, 'query', $values, $maxFields, $maxBytes);
        }
        if (in_array('header', $targets, true)) {
            $this->flatten($request->server, 'header', $values, $maxFields, $maxBytes);
        }
        if (in_array('body', $targets, true)) {
            $this->flatten($request->body, 'body', $values, $maxFields, $maxBytes);
        }

        return array_values(array_filter(array_map(fn (array $value): array => [
            'target' => $value['target'],
            'normalized' => $this->normalize($value['value']),
        ], $values), static fn (array $value): bool => $value['normalized'] !== ''));
    }

    /** @param list<array{target:string,value:string}> $values */
    private function flatten(mixed $value, string $target, array &$values, int $maxFields, int $maxBytes): void
    {
        if (count($values) >= $maxFields) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->flatten($item, $target, $values, $maxFields, $maxBytes);
                if (count($values) >= $maxFields) {
                    return;
                }
            }
            return;
        }
        if (is_scalar($value)) {
            $values[] = ['target' => $target, 'value' => substr((string) $value, 0, $maxBytes)];
        }
    }

    private function normalize(string $value): string
    {
        $value = rawurldecode($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(chr(92), '/', $value);
        $value = preg_replace('/[[:space:]]+/', ' ', $value) ?? $value;
        return strtolower(trim($value));
    }

    private function matchesCategory(string $category, string $value): bool
    {
        return match ($category) {
            'sqli' => preg_match('/[[:<:]]union[[:space:]]+(all[[:space:]]+)?select[[:>:]]|[[:<:]]select[[:space:]].{0,64}[[:space:]]from[[:>:]]/', $value) === 1,
            'xss' => preg_match('/<[[:space:]]*script[[:>:]]|[[:<:]]on[a-z0-9_:-]+[[:space:]]*=|[[:<:]](?:javascript|data)[[:space:]]*:/', $value) === 1,
            'command_injection' => preg_match('/(?:;|&&|[|])[[:space:]]*(?:curl|wget|bash|sh|cmd|powershell)[[:>:]]|[|][[:space:]]*(?:sh|bash)[[:>:]]/', $value) === 1,
            'ssrf' => preg_match('~[[:<:]](?:file|gopher|dict)://|[[:<:]](?:localhost|169[.]254[.]169[.]254|127[.]0[.]0[.]1)(?:[/ :]|$)|[[:<:]](?:10|192[.]168|172[.](?:1[6-9]|2[0-9]|3[0-1]))[.][0-9]{1,3}[.][0-9]{1,3}~', $value) === 1,
            'path_traversal' => preg_match('~[.][.](?:[/]|%2f|%5c)|%2e%2e(?:%2f|%5c)|[/]etc[/](?:passwd|shadow)[[:>:]]|windows[/]win[.]ini~', $value) === 1,
            default => false,
        };
    }

    /** @return array{matched:bool,blocked:bool,risk_score:int,categories:list<string>,hits:list<array<string,mixed>>} */
    private function emptyResult(): array
    {
        return ['matched' => false, 'blocked' => false, 'risk_score' => 0, 'categories' => [], 'hits' => []];
    }
}
