<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagContentChunkerService;

$chunker = new RagContentChunkerService();
$sentence = '冷却循环泵持续运行，控制器记录设备温度并监测热过载状态。';
$content = "# 冷却系统\n\n第一段说明设备温度。第二句解释热过载。\n\n" . str_repeat($sentence, 18);
$requestedProfile = [
    'target_chars' => 200,
    'max_chars' => 240,
    'min_tail_chars' => 20,
    'overlap_chars' => 24,
];

$chunks = $chunker->chunk($content, $requestedProfile);

assert_true(count($chunks) > 2, 'Long content should be split into more than two chunks.');
foreach ($chunks as $index => $chunk) {
    assert_true(mb_strlen($chunk['content']) <= 240, 'Chunk ' . $index . ' must not exceed the effective maximum length.');
    assert_true($chunk['start'] >= 0, 'Chunk ' . $index . ' must have a non-negative start offset.');
    assert_true($chunk['end'] > $chunk['start'], 'Chunk ' . $index . ' must have an end offset after its start offset.');
    assert_true(
        in_array($chunk['boundary'], ['heading', 'paragraph', 'sentence', 'hard'], true),
        'Chunk ' . $index . ' must report a supported boundary type.'
    );
    assert_same(
        $chunk['content'],
        mb_substr($content, $chunk['start'], $chunk['end'] - $chunk['start']),
        'Chunk ' . $index . ' offsets must identify its exact Unicode-safe source slice.'
    );

    if ($index === 0) {
        continue;
    }

    $previous = $chunks[$index - 1];
    $overlap = $previous['end'] - $chunk['start'];
    assert_true($overlap > 0, 'Adjacent chunks should overlap.');
    assert_true($overlap <= 24, 'Adjacent chunk overlap must not exceed the requested overlap.');
    assert_same(
        mb_substr($previous['content'], -$overlap),
        mb_substr($chunk['content'], 0, $overlap),
        'Adjacent chunks must share the same source text in their overlap.'
    );
}

assert_same($chunks, $chunker->chunk($content, $requestedProfile), 'Repeated chunking must be deterministic.');
assert_same([], $chunker->chunk(''), 'Empty content should return no chunks.');

$shortContent = '设备温度正常。';
assert_same(
    [[
        'content' => $shortContent,
        'start' => 0,
        'end' => mb_strlen($shortContent),
        'boundary' => 'sentence',
    ]],
    $chunker->chunk($shortContent),
    'Short content should remain in one Unicode-safe chunk.'
);

$singleNewlineChunks = $chunker->chunk("abc\n");
assert_same('hard', $singleNewlineChunks[0]['boundary'], 'A single trailing newline is not a paragraph boundary.');

$headingPrefix = str_repeat('前文持续说明设备温度与控制状态。', 12);
$headingContent = $headingPrefix . "\n## 新标题\n" . str_repeat('标题后的内容继续说明冷却系统运行状态。', 12);
$headingChunks = $chunker->chunk($headingContent, [
    'target_chars' => 200,
    'max_chars' => 240,
    'min_tail_chars' => 0,
    'overlap_chars' => 0,
]);
$headingStart = mb_strpos($headingContent, '## 新标题');

assert_true($headingStart !== false, 'Heading fixture should contain the Markdown heading.');
assert_true(
    in_array('## 新标题', array_map(
        static fn (array $chunk): string => mb_substr($chunk['content'], 0, mb_strlen('## 新标题')),
        $headingChunks
    ), true),
    'A valid Markdown heading should begin a new chunk.'
);
assert_true(
    in_array($headingStart, array_column($headingChunks, 'start'), true),
    'A valid Markdown heading should be preserved at the exact start offset of its chunk.'
);

$notHeadingContent = $headingPrefix . "\n#not-heading\n" . str_repeat('后续内容继续说明冷却系统运行状态。', 12);
$notHeadingChunks = $chunker->chunk($notHeadingContent, [
    'target_chars' => 200,
    'max_chars' => 240,
    'min_tail_chars' => 0,
    'overlap_chars' => 0,
]);
$notHeadingStart = mb_strpos($notHeadingContent, '#not-heading');

assert_true($notHeadingStart !== false, 'Non-heading fixture should contain the hash-prefixed line.');
foreach ($notHeadingChunks as $chunk) {
    assert_true(
        $chunk['boundary'] !== 'heading',
        'A hash without a following space must not create a heading boundary.'
    );
}

$hardContent = str_repeat('冷', 650);
$hardChunks = $chunker->chunk($hardContent, [
    'target_chars' => 200,
    'max_chars' => 240,
    'min_tail_chars' => 0,
    'overlap_chars' => 100,
]);

assert_same(200, mb_strlen($hardChunks[0]['content']), 'A hard cut should use the target length before overlap is applied.');
assert_same(100, $hardChunks[1]['start'], 'The second hard-cut chunk should begin one overlap behind the first chunk end.');
foreach ($hardChunks as $index => $chunk) {
    assert_true(mb_strlen($chunk['content']) <= 240, 'Hard-cut chunk ' . $index . ' must not exceed the maximum length.');
}

$tailContent = str_repeat('热', 430);
$tailChunks = $chunker->chunk($tailContent, [
    'target_chars' => 200,
    'max_chars' => 240,
    'min_tail_chars' => 50,
    'overlap_chars' => 0,
]);

assert_same(2, count($tailChunks), 'A short tail should merge into the preceding chunk when the result remains within max.');
assert_same(230, mb_strlen($tailChunks[1]['content']), 'The merged final chunk should contain its target span and short tail.');
assert_same(430, $tailChunks[1]['end'], 'The merged final chunk should end at the end of the source text.');

$safeProfile = $chunker->profile([
    'target_chars' => -10,
    'max_chars' => 'invalid',
    'min_tail_chars' => 99999,
    'overlap_chars' => 99999,
    'version' => 'ignored',
]);

assert_same(200, $safeProfile['target_chars'], 'Target length should clamp to its safe minimum.');
assert_same(1200, $safeProfile['max_chars'], 'An invalid maximum length should use the safe default.');
assert_same(200, $safeProfile['min_tail_chars'], 'Minimum tail should clamp to the normalized target length.');
assert_same(100, $safeProfile['overlap_chars'], 'Overlap should clamp to half the normalized target length.');
assert_same('p1-v1', $safeProfile['version'], 'Profile version should be stable.');
assert_true($safeProfile['warnings'] !== [], 'Invalid profile values should produce warnings.');
