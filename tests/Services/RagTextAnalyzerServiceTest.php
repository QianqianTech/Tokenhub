<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagTextAnalyzerService;

$analyzer = new RagTextAnalyzerService();
$text = '设备温度过高，ＰＬＣ－０２ 报警 E77 reset';
$analysis = $analyzer->analyze($text);

assert_same(
    '设备温度过高,plc-02 报警 e77 reset',
    $analyzer->normalize($text),
    'Normalization should lowercase Latin text, convert full-width ASCII and punctuation, and collapse whitespace.'
);
assert_same('äöü αβγ', $analyzer->normalize('ÄÖÜ ΑΒΓ'), 'Normalization should lowercase Unicode text.');

foreach (['设备', '温度', '过高', 'plc-02', '报警', 'e77', 'reset'] as $term) {
    assert_true(in_array($term, $analysis['terms'], true), 'Analysis should contain term: ' . $term);
}

assert_true(in_array('e77', $analysis['codes'], true), 'Analysis should identify E77 as a code.');
assert_true(in_array('错误', $analysis['rewrite_terms'], true), 'Alarm rewrites should include 错误.');
assert_same($analysis['terms'], $analyzer->terms($text), 'terms() should expose the same deterministic terms as analyze().');
assert_same($analysis, $analyzer->analyze($text), 'Repeated analysis should produce exactly the same output.');

$repeatedPhraseAnalysis = $analyzer->analyze('设备 温度 设备');
assert_true(in_array('设备 温度', $repeatedPhraseAnalysis['phrases'], true), 'Phrases should preserve the first adjacent occurrence.');
assert_true(in_array('温度 设备', $repeatedPhraseAnalysis['phrases'], true), 'Phrases should preserve adjacency after a repeated term.');
assert_true(!in_array('设备 设备', $repeatedPhraseAnalysis['phrases'], true), 'A two-character Han term should not create a duplicate self phrase.');
assert_true(!in_array('温度 温度', $repeatedPhraseAnalysis['phrases'], true), 'Each two-character Han term should occur only once at its position.');
