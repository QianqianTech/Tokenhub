<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class RagContentChunkerService
{
    private const VERSION = 'p1-v1';

    /**
     * @param array<string, mixed> $profile
     * @return list<array{content: string, start: int, end: int, boundary: string}>
     */
    public function chunk(string $content, array $profile = []): array
    {
        if ($content === '') {
            return [];
        }

        $settings = $this->profile($profile);
        $length = mb_strlen($content);
        if ($length <= $settings['max_chars']) {
            return [[
                'content' => $content,
                'start' => 0,
                'end' => $length,
                'boundary' => $this->boundaryAt($content, $length),
            ]];
        }

        $boundaryIndex = $this->boundaries($content);
        $chunks = [];
        $cursor = 0;

        while ($cursor < $length) {
            $start = $chunks === [] ? 0 : max(0, $cursor - $settings['overlap_chars']);
            $hardEnd = min($length, $start + $settings['max_chars']);
            $preferredEnd = min($hardEnd, $start + $settings['target_chars']);
            $minimumEnd = max($cursor + 1, $start + intdiv($preferredEnd - $start, 2));
            $end = $this->preferredBoundary($boundaryIndex['positions'], $minimumEnd, $preferredEnd);

            if ($end === null) {
                $end = $preferredEnd;
            }

            $remaining = $length - $end;
            if (
                $remaining > 0
                && $remaining < $settings['min_tail_chars']
                && $length - $start <= $settings['max_chars']
            ) {
                $end = $length;
            }

            $chunks[] = [
                'content' => mb_substr($content, $start, $end - $start),
                'start' => $start,
                'end' => $end,
                'boundary' => $boundaryIndex['types'][$end] ?? $this->boundaryAt($content, $end),
            ];
            $cursor = $end;
        }

        return $chunks;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{
     *     target_chars: int,
     *     max_chars: int,
     *     min_tail_chars: int,
     *     overlap_chars: int,
     *     version: string,
     *     warnings: list<string>
     * }
     */
    public function profile(array $profile): array
    {
        $warnings = [];
        $target = $this->integerValue($profile, 'target_chars', 800, $warnings);
        $target = $this->clamp($target, 200, 4000, 'target_chars', $warnings);

        $max = $this->integerValue($profile, 'max_chars', 1200, $warnings);
        $max = $this->clamp($max, $target, 6000, 'max_chars', $warnings);

        $minTail = $this->integerValue($profile, 'min_tail_chars', 80, $warnings);
        $minTail = $this->clamp($minTail, 0, $target, 'min_tail_chars', $warnings);

        $overlap = $this->integerValue($profile, 'overlap_chars', 120, $warnings);
        $overlap = $this->clamp($overlap, 0, min(500, intdiv($target, 2)), 'overlap_chars', $warnings);

        if (array_key_exists('version', $profile) && $profile['version'] !== self::VERSION) {
            $warnings[] = 'version is fixed at ' . self::VERSION;
        }

        return [
            'target_chars' => $target,
            'max_chars' => $max,
            'min_tail_chars' => $minTail,
            'overlap_chars' => $overlap,
            'version' => self::VERSION,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @param list<string> $warnings
     */
    private function integerValue(array $profile, string $key, int $default, array &$warnings): int
    {
        if (!array_key_exists($key, $profile)) {
            return $default;
        }

        $value = $profile[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        $warnings[] = $key . ' is invalid; using default ' . $default;

        return $default;
    }

    /**
     * @param list<string> $warnings
     */
    private function clamp(int $value, int $minimum, int $maximum, string $key, array &$warnings): int
    {
        $clamped = max($minimum, min($maximum, $value));
        if ($clamped !== $value) {
            $warnings[] = $key . ' was clamped to ' . $clamped;
        }

        return $clamped;
    }

    /**
     * @return array{
     *     positions: array{heading: list<int>, paragraph: list<int>, sentence: list<int>},
     *     types: array<int, string>
     * }
     */
    private function boundaries(string $content): array
    {
        $characters = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $positions = [
            'heading' => [],
            'paragraph' => [],
            'sentence' => [],
        ];
        $types = [];
        $lineStart = 0;
        $lineBreaks = 0;

        foreach ($characters as $index => $character) {
            if ($index === $lineStart) {
                $hashes = 0;
                while (($characters[$index + $hashes] ?? null) === '#') {
                    $hashes++;
                }
                if ($hashes >= 1 && $hashes <= 6 && ($characters[$index + $hashes] ?? null) === ' ') {
                    $positions['heading'][] = $index;
                    $types[$index] = 'heading';
                }
            }

            $end = $index + 1;
            if (in_array($character, ['。', '！', '？', '.', '!', '?'], true)) {
                $positions['sentence'][] = $end;
                $types[$end] = 'sentence';
            }

            if ($character === "\n") {
                $lineBreaks++;
                if ($lineBreaks >= 2) {
                    $positions['paragraph'][] = $end;
                    $types[$end] = 'paragraph';
                }
                $lineStart = $end;
                continue;
            }

            if ($character !== "\r") {
                $lineBreaks = 0;
            }
        }

        return ['positions' => $positions, 'types' => $types];
    }

    /**
     * @param array{heading: list<int>, paragraph: list<int>, sentence: list<int>} $positions
     */
    private function preferredBoundary(array $positions, int $minimum, int $maximum): ?int
    {
        foreach (['heading', 'paragraph', 'sentence'] as $type) {
            $candidate = $this->lastPositionInRange($positions[$type], $minimum, $maximum);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<int> $positions
     */
    private function lastPositionInRange(array $positions, int $minimum, int $maximum): ?int
    {
        $low = 0;
        $high = count($positions) - 1;
        $candidate = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $position = $positions[$middle];
            if ($position <= $maximum) {
                $candidate = $position;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $candidate !== null && $candidate >= $minimum ? $candidate : null;
    }

    private function boundaryAt(string $content, int $end): string
    {
        if ($end <= 0) {
            return 'hard';
        }

        $last = mb_substr($content, $end - 1, 1);
        if (in_array($last, ['。', '！', '？', '.', '!', '?'], true)) {
            return 'sentence';
        }
        $tailLength = min(4, $end);
        $tail = mb_substr($content, $end - $tailLength, $tailLength);
        if (str_ends_with(str_replace("\r\n", "\n", $tail), "\n\n")) {
            return 'paragraph';
        }

        return 'hard';
    }
}
