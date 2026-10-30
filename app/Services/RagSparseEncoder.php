<?php

declare(strict_types=1);

namespace TokenHub\Services;

/**
 * Sparse term vectors for the lexical index (RAG-032). Terms come from
 * RagTextAnalyzerService (lower-cased words, codes and CJK bigrams), hashed
 * into a 31-bit index space. Document weights use BM25 term-frequency
 * saturation with length normalisation; Qdrant's IDF modifier supplies the
 * inverse document frequency at query time, so the score is BM25-like.
 */
final class RagSparseEncoder
{
    private const K1 = 1.2;
    private const B = 0.75;
    private const AVERAGE_TERMS = 120;

    public function __construct(private readonly ?RagTextAnalyzerService $analyzer = null)
    {
    }

    /** @return array{indices: list<int>, values: list<float>} */
    public function encodeDocument(string $text): array
    {
        $frequencies = ($this->analyzer ?? new RagTextAnalyzerService())->termFrequencies($text);
        $length = array_sum($frequencies);
        $norm = self::K1 * (1 - self::B + self::B * ($length / self::AVERAGE_TERMS));
        $weights = [];
        foreach ($frequencies as $term => $tf) {
            $weights[$this->index((string) $term)] = round(($tf * (self::K1 + 1)) / ($tf + $norm), 6);
        }

        return $this->vector($weights);
    }

    /** @return array{indices: list<int>, values: list<float>} */
    public function encodeQuery(string $text): array
    {
        $weights = [];
        foreach (array_keys(($this->analyzer ?? new RagTextAnalyzerService())->termFrequencies($text)) as $term) {
            $weights[$this->index((string) $term)] = 1.0;
        }

        return $this->vector($weights);
    }

    public function index(string $term): int
    {
        return crc32($term) & 0x7FFFFFFF;
    }

    /** @param array<int, float> $weights @return array{indices: list<int>, values: list<float>} */
    private function vector(array $weights): array
    {
        ksort($weights);

        return ['indices' => array_keys($weights), 'values' => array_values($weights)];
    }
}
