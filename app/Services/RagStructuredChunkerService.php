<?php

declare(strict_types=1);

namespace TokenHub\Services;

/**
 * Structure-aware chunker "p2" (RAG-023).
 *
 * Input is the Markdown produced by RagDocumentParserService (or any Markdown
 * text). Output chunks:
 *  - never cross a heading: each chunk belongs to one section and carries its
 *    heading path (document > chapter > section), used for contextual
 *    embeddings and citations;
 *  - are sized in estimated tokens (CJK characters count individually), not
 *    bytes or characters;
 *  - keep tables, code blocks and list runs intact when they fit; oversized
 *    tables are split by rows with the header repeated; oversized paragraphs
 *    are split at sentence boundaries;
 *  - overlap the previous chunk of the same section by whole sentences;
 *  - record the source page (<!-- page:N --> markers) and a section key so
 *    retrieval can expand a hit to its neighbours (small-to-big).
 */
final class RagStructuredChunkerService
{
    public const VERSION = 'p2-v1';

    /**
     * @param array<string, mixed> $profile target_tokens, max_tokens, overlap_tokens, min_tokens
     * @return list<array{content: string, heading_path: list<string>, page: int|null, section_key: string, section_ordinal: int, kind: string, tokens: int, boundary: string}>
     */
    public function chunk(string $markdown, array $profile = []): array
    {
        $settings = $this->profile($profile);
        $sections = $this->sections($this->blocks($markdown));
        $chunks = [];
        foreach ($sections as $sectionIndex => $section) {
            $sectionKey = substr(hash('sha256', implode("\x1f", $section['path']) . "\x1f" . $sectionIndex), 0, 16);
            $ordinal = 0;
            $buffer = [];
            $bufferTokens = 0;
            $bufferPage = null;
            $overlap = '';
            $flush = function (string $boundary) use (&$buffer, &$bufferTokens, &$bufferPage, &$overlap, &$chunks, &$ordinal, $section, $sectionKey, $settings): void {
                if ($buffer === []) {
                    return;
                }
                $kinds = array_unique(array_column($buffer, 'kind'));
                $body = implode("\n\n", array_column($buffer, 'text'));
                $content = $overlap !== '' && !in_array('table', $kinds, true) && !in_array('code', $kinds, true) ? $overlap . "\n" . $body : $body;
                $chunks[] = [
                    'content' => $content,
                    'heading_path' => $section['path'],
                    'page' => $bufferPage,
                    'section_key' => $sectionKey,
                    'section_ordinal' => ++$ordinal,
                    'kind' => count($kinds) === 1 ? (string) reset($kinds) : 'mixed',
                    'tokens' => self::estimateTokens($content),
                    'boundary' => $boundary,
                ];
                $overlap = $settings['overlap_tokens'] > 0 && !in_array('table', $kinds, true) && !in_array('code', $kinds, true)
                    ? $this->tailSentences($body, $settings['overlap_tokens'])
                    : '';
                $buffer = [];
                $bufferTokens = 0;
                $bufferPage = null;
            };
            foreach ($section['blocks'] as $block) {
                foreach ($this->fitBlock($block, $settings) as $piece) {
                    $tokens = self::estimateTokens($piece['text']);
                    if ($bufferTokens > 0 && $bufferTokens + $tokens > $settings['target_tokens']) {
                        $flush($piece['kind'] === 'paragraph' ? 'paragraph' : $piece['kind']);
                    }
                    $buffer[] = $piece;
                    $bufferTokens += $tokens;
                    $bufferPage ??= $piece['page'];
                    if ($bufferTokens >= $settings['max_tokens']) {
                        $flush('size');
                    }
                }
            }
            if ($buffer !== [] && $bufferTokens < $settings['min_tokens'] && $chunks !== [] && end($chunks)['section_key'] === $sectionKey) {
                // Merge a tiny tail into the previous chunk of the same section.
                $last = array_pop($chunks);
                $last['content'] .= "\n\n" . implode("\n\n", array_column($buffer, 'text'));
                $last['tokens'] = self::estimateTokens($last['content']);
                $chunks[] = $last;
                $buffer = [];
            }
            $flush('section');
        }

        return $chunks;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{target_tokens: int, max_tokens: int, overlap_tokens: int, min_tokens: int, version: string}
     */
    public function profile(array $profile): array
    {
        $int = static fn (string $key, int $default, int $min, int $max): int => max($min, min($max, is_numeric($profile[$key] ?? null) ? (int) $profile[$key] : $default));
        $target = $int('target_tokens', 400, 64, 2000);
        $max = max($target, $int('max_tokens', 600, 64, 4000));

        return [
            'target_tokens' => $target,
            'max_tokens' => $max,
            'overlap_tokens' => $int('overlap_tokens', 60, 0, intdiv($target, 2)),
            'min_tokens' => $int('min_tokens', 40, 0, intdiv($target, 2)),
            'version' => self::VERSION,
        ];
    }

    /**
     * Approximate token count that works for mixed Chinese/English text:
     * each CJK character ~1 token, each Latin word ~1.3 tokens, digits and
     * punctuation runs ~0.5.
     */
    public static function estimateTokens(string $text): int
    {
        $cjk = preg_match_all('/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u', $text);
        $words = preg_match_all('/[A-Za-z]+/', $text);
        $other = preg_match_all('/[0-9]+|[^\sA-Za-z0-9\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u', $text);

        return max(1, (int) ceil((int) $cjk + (int) $words * 1.3 + (int) $other * 0.5));
    }

    /**
     * @return list<array{kind: string, text: string, level?: int, page: int|null}>
     */
    private function blocks(string $markdown): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $blocks = [];
        $page = null;
        $paragraph = [];
        $flushParagraph = function () use (&$paragraph, &$blocks, &$page): void {
            $text = trim(implode("\n", $paragraph));
            if ($text !== '') {
                $isList = preg_match('/^\s*([-*+•]|\d+[.)])\s+/u', $text) === 1;
                $blocks[] = ['kind' => $isList ? 'list' : 'paragraph', 'text' => $text, 'page' => $page];
            }
            $paragraph = [];
        };
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^<!--\s*page:(\d+)\s*-->$/', trim($line), $m) === 1) {
                $flushParagraph();
                $page = (int) $m[1];
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $m) === 1) {
                $flushParagraph();
                $blocks[] = ['kind' => 'heading', 'level' => strlen($m[1]), 'text' => trim($m[2]), 'page' => $page];
                continue;
            }
            if (preg_match('/^\s*(```|~~~)/', $line, $m) === 1) {
                $flushParagraph();
                $fence = $m[1];
                $code = [$line];
                for ($i++; $i < $count; $i++) {
                    $code[] = $lines[$i];
                    if (str_starts_with(ltrim($lines[$i]), $fence)) {
                        break;
                    }
                }
                $blocks[] = ['kind' => 'code', 'text' => implode("\n", $code), 'page' => $page];
                continue;
            }
            if (str_starts_with(ltrim($line), '|')) {
                $flushParagraph();
                $table = [];
                for (; $i < $count && str_starts_with(ltrim($lines[$i]), '|'); $i++) {
                    $table[] = rtrim($lines[$i]);
                }
                $i--;
                $blocks[] = ['kind' => 'table', 'text' => implode("\n", $table), 'page' => $page];
                continue;
            }
            if (trim($line) === '') {
                $flushParagraph();
                continue;
            }
            $paragraph[] = rtrim($line);
        }
        $flushParagraph();

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array{path: list<string>, blocks: list<array<string, mixed>>}>
     */
    private function sections(array $blocks): array
    {
        $sections = [];
        $stack = [];
        $current = ['path' => [], 'blocks' => []];
        foreach ($blocks as $block) {
            if ($block['kind'] === 'heading') {
                if ($current['blocks'] !== []) {
                    $sections[] = $current;
                }
                $level = (int) $block['level'];
                $stack = array_filter($stack, static fn (int $existing): bool => $existing < $level, ARRAY_FILTER_USE_KEY);
                $stack[$level] = (string) $block['text'];
                ksort($stack);
                $current = ['path' => array_values($stack), 'blocks' => []];
                continue;
            }
            $current['blocks'][] = $block;
        }
        if ($current['blocks'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * Splits one block into pieces no larger than max_tokens.
     *
     * @param array<string, mixed> $block
     * @param array{target_tokens: int, max_tokens: int, overlap_tokens: int, min_tokens: int} $settings
     * @return list<array{kind: string, text: string, page: int|null}>
     */
    private function fitBlock(array $block, array $settings): array
    {
        $text = (string) $block['text'];
        $kind = (string) $block['kind'];
        $page = $block['page'] ?? null;
        if (self::estimateTokens($text) <= $settings['max_tokens']) {
            return [['kind' => $kind, 'text' => $text, 'page' => $page]];
        }
        if ($kind === 'table') {
            $rows = explode("\n", $text);
            $header = array_slice($rows, 0, min(2, count($rows)));
            $pieces = [];
            $current = $header;
            foreach (array_slice($rows, count($header)) as $row) {
                if (count($current) > count($header) && self::estimateTokens(implode("\n", [...$current, $row])) > $settings['target_tokens']) {
                    $pieces[] = ['kind' => 'table', 'text' => implode("\n", $current), 'page' => $page];
                    $current = $header;
                }
                $current[] = $row;
            }
            $pieces[] = ['kind' => 'table', 'text' => implode("\n", $current), 'page' => $page];

            return $pieces;
        }
        if ($kind === 'code' || $kind === 'list') {
            $lines = explode("\n", $text);
            $pieces = [];
            $current = [];
            foreach ($lines as $line) {
                if ($current !== [] && self::estimateTokens(implode("\n", [...$current, $line])) > $settings['target_tokens']) {
                    $pieces[] = ['kind' => $kind, 'text' => implode("\n", $current), 'page' => $page];
                    $current = [];
                }
                $current[] = $line;
            }
            if ($current !== []) {
                $pieces[] = ['kind' => $kind, 'text' => implode("\n", $current), 'page' => $page];
            }

            return $pieces;
        }
        $pieces = [];
        $current = '';
        foreach ($this->sentences($text) as $sentence) {
            if ($current !== '' && self::estimateTokens($current . $sentence) > $settings['target_tokens']) {
                $pieces[] = ['kind' => 'paragraph', 'text' => trim($current), 'page' => $page];
                $current = '';
            }
            if (self::estimateTokens($sentence) > $settings['max_tokens']) {
                // A single run-on "sentence": hard split by characters.
                foreach (mb_str_split($sentence, max(64, $settings['target_tokens'])) as $part) {
                    $pieces[] = ['kind' => 'paragraph', 'text' => trim($part), 'page' => $page];
                }
                continue;
            }
            $current .= $sentence;
        }
        if (trim($current) !== '') {
            $pieces[] = ['kind' => 'paragraph', 'text' => trim($current), 'page' => $page];
        }

        return $pieces;
    }

    /** @return list<string> */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[。！？!?；;])|(?<=[.])\s+|\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        return array_values(array_map(static fn (string $part): string => preg_match('/[\p{Han}]$/u', $part) === 1 ? $part : $part . ' ', $parts));
    }

    private function tailSentences(string $text, int $tokens): string
    {
        $sentences = $this->sentences(preg_replace('/^\|.*$/m', '', $text) ?? $text);
        $tail = [];
        $count = 0;
        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $sentenceTokens = self::estimateTokens($sentences[$i]);
            if ($count + $sentenceTokens > $tokens && $tail !== []) {
                break;
            }
            if ($sentenceTokens > $tokens) {
                break;
            }
            array_unshift($tail, $sentences[$i]);
            $count += $sentenceTokens;
        }

        return trim(implode('', $tail));
    }
}
