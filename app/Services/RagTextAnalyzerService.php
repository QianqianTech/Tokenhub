<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class RagTextAnalyzerService
{
    /**
     * @return array{
     *     original: string,
     *     normalized: string,
     *     terms: list<string>,
     *     phrases: list<string>,
     *     codes: list<string>,
     *     rewrite_terms: list<string>
     * }
     */
    public function analyze(string $text): array
    {
        $normalized = $this->normalize($text);
        $terms = $this->terms($normalized);
        $termOccurrences = $this->termOccurrences($normalized);

        $phraseCandidates = [];
        for ($index = 0, $count = count($termOccurrences) - 1; $index < $count; $index++) {
            $phraseCandidates[] = $termOccurrences[$index] . ' ' . $termOccurrences[$index + 1];
        }
        $phrases = $this->stableUnique($phraseCandidates);

        $codeCandidates = [];
        foreach ($terms as $term) {
            if (
                preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $term) === 1
                && preg_match('/[a-z]/', $term) === 1
                && preg_match('/[0-9]/', $term) === 1
            ) {
                $codeCandidates[] = $term;
            }
        }
        $codes = $this->stableUnique($codeCandidates);

        $rewriteCandidates = [];
        foreach ($this->rewriteRules() as $source => $rewrites) {
            if (!str_contains($normalized, $source)) {
                continue;
            }

            foreach ($rewrites as $rewrite) {
                $rewriteCandidates[] = $rewrite;
            }
        }
        $rewriteTerms = $this->stableUnique($rewriteCandidates);

        return [
            'original' => $text,
            'normalized' => $normalized,
            'terms' => $terms,
            'phrases' => $phrases,
            'codes' => $codes,
            'rewrite_terms' => $rewriteTerms,
        ];
    }

    public function normalize(string $text): string
    {
        $normalized = preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}]/u',
            static function (array $matches): string {
                $bytes = unpack('C*', $matches[0]);
                if ($bytes === false) {
                    return $matches[0];
                }

                $codePoint = (($bytes[1] & 0x0F) << 12)
                    | (($bytes[2] & 0x3F) << 6)
                    | ($bytes[3] & 0x3F);

                return chr($codePoint - 0xFEE0);
            },
            $text
        );

        $normalized = strtr($normalized ?? $text, [
            '　' => ' ',
            '。' => '.',
            '、' => ',',
            '“' => '"',
            '”' => '"',
            '‘' => "'",
            '’' => "'",
            '—' => '-',
            '–' => '-',
        ]);
        $normalized = mb_strtolower($normalized, 'UTF-8');

        return preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);
    }

    /** @return list<string> */
    public function terms(string $text): array
    {
        $normalized = $this->normalize($text);

        return $this->stableUnique($this->termOccurrences($normalized));
    }

    /** @return list<string> */
    private function termOccurrences(string $normalized): array
    {
        preg_match_all('/[a-z0-9]+(?:-[a-z0-9]+)*|\p{Han}+/u', $normalized, $matches);

        $occurrences = [];
        foreach ($matches[0] as $segment) {
            $occurrences[] = $segment;
            if (preg_match('/^\p{Han}+$/u', $segment) !== 1) {
                continue;
            }

            $characters = preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($characters) === 2) {
                continue;
            }

            for ($index = 0, $count = count($characters) - 1; $index < $count; $index++) {
                $occurrences[] = $characters[$index] . $characters[$index + 1];
            }
        }

        return $occurrences;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function stableUnique(array $values): array
    {
        $unique = [];
        $seen = [];
        foreach ($values as $value) {
            if (isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $unique[] = $value;
        }

        return $unique;
    }

    /** @return array<string, list<string>> */
    private function rewriteRules(): array
    {
        return [
            '故障' => ['错误', '报警'],
            '错误' => ['故障', '报警'],
            '报警' => ['错误', '故障'],
            '过热' => ['温度过高', '热过载'],
            '温度过高' => ['过热', '热过载'],
        ];
    }
}
