<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * BIZ-026: RagSparseEncoder (lexical sparse vectors) and
 * RagStructuredChunkerService (structure-aware chunking) had no tests.
 */

use TokenHub\Services\RagSparseEncoder;
use TokenHub\Services\RagStructuredChunkerService;

// ---------------------------------------------------------------- sparse encoder
$encoder = new RagSparseEncoder();
assert_same(crc32('invoice') & 0x7FFFFFFF, $encoder->index('invoice'), 'Term index is the 31-bit CRC32 of the term.');
assert_true($encoder->index('invoice') >= 0 && $encoder->index('invoice') <= 0x7FFFFFFF, 'Indices fit Qdrant sparse u32 / signed ints.');

$doc = $encoder->encodeDocument('Invoice invoice invoice payment');
assert_same(count($doc['indices']), count($doc['values']), 'Indices and values are parallel.');
$sorted = $doc['indices'];
sort($sorted);
assert_same($sorted, $doc['indices'], 'Indices are sorted (Qdrant requires it).');
assert_same(count(array_unique($doc['indices'])), count($doc['indices']), 'No duplicate indices.');
$weights = array_combine($doc['indices'], $doc['values']);
$invoice = $weights[$encoder->index('invoice')] ?? null;
$payment = $weights[$encoder->index('payment')] ?? null;
assert_true($invoice !== null && $payment !== null, 'Both terms are encoded.');
assert_true($invoice > $payment, 'A repeated term weighs more.');
assert_true($invoice < 2.2 + 1e-9, 'BM25 saturation bounds the weight by k1 + 1.');

$long = $encoder->encodeDocument('payment ' . str_repeat('filler words here ', 200));
$longWeights = array_combine($long['indices'], $long['values']);
assert_true(($longWeights[$encoder->index('payment')] ?? 1.0) < $payment, 'Length normalisation lowers weights in long documents.');

$query = $encoder->encodeQuery('invoice invoice payment');
assert_same([1.0], array_values(array_unique($query['values'])), 'Query terms all weigh 1 (IDF comes from Qdrant).');
assert_same(2, count($query['indices']), 'Repeated query terms collapse.');
assert_same(['indices' => [], 'values' => []], $encoder->encodeQuery(''), 'Empty text gives an empty vector.');
$cjk = $encoder->encodeQuery('发票报销');
assert_true(count($cjk['indices']) >= 2, 'Chinese text produces several terms (bigrams).');

// ---------------------------------------------------------------- structured chunker
$chunker = new RagStructuredChunkerService();
$profile = $chunker->profile(['target_tokens' => 10, 'max_tokens' => 5, 'overlap_tokens' => 999, 'min_tokens' => -1]);
assert_same(64, $profile['target_tokens'], 'Target tokens are clamped to the minimum.');
assert_true($profile['max_tokens'] >= $profile['target_tokens'], 'max_tokens never drops below target_tokens.');
assert_same(32, $profile['overlap_tokens'], 'Overlap is at most half the target.');
assert_same(0, $profile['min_tokens'], 'Negative min_tokens is clamped to 0.');
assert_same(RagStructuredChunkerService::VERSION, $profile['version'], 'The profile records the chunker version.');

assert_same(1, RagStructuredChunkerService::estimateTokens(''), 'Empty text still counts as one token.');
assert_same(4, RagStructuredChunkerService::estimateTokens('报销流程'), 'Each CJK character is one token.');
assert_same(3, RagStructuredChunkerService::estimateTokens('hello world'), 'Latin words count ~1.3 tokens.');

$markdown = "# 报销制度\n\n<!-- page:1 -->\n总则说明。\n\n## 差旅\n\n<!-- page:2 -->\n出差需要提前审批。住宿标准按城市分级。\n\n| 城市 | 标准 |\n| --- | --- |\n| 北京 | 500 |\n| 上海 | 500 |\n\n## 招待\n\n招待费需要事前申请。\n";
$chunks = $chunker->chunk($markdown);
assert_true(count($chunks) >= 3, 'Each section produces its own chunk.');
$paths = array_map(static fn (array $chunk): string => implode(' > ', $chunk['heading_path']), $chunks);
assert_true(in_array('报销制度 > 差旅', $paths, true), 'Chunks carry their heading path.');
foreach ($chunks as $chunk) {
    assert_true(!(str_contains($chunk['content'], '出差') && str_contains($chunk['content'], '招待费')), 'A chunk never crosses a heading.');
    assert_true($chunk['tokens'] === RagStructuredChunkerService::estimateTokens($chunk['content']), 'Token counts match the content.');
    assert_true(strlen($chunk['section_key']) === 16, 'Chunks carry a section key for small-to-big expansion.');
}
$travel = array_values(array_filter($chunks, static fn (array $chunk): bool => str_contains($chunk['content'], '出差')));
assert_same(2, $travel[0]['page'] ?? null, 'The source page of the section is recorded.');
$table = array_values(array_filter($chunks, static fn (array $chunk): bool => str_contains($chunk['content'], '| 北京 | 500 |')));
assert_true($table !== [] && str_contains($table[0]['content'], '| 城市 | 标准 |'), 'A small table stays whole with its header.');

$bigTable = "# 价目\n\n| 型号 | 价格 |\n| --- | --- |\n";
for ($i = 0; $i < 400; $i++) {
    $bigTable .= "| model-{$i} alpha beta gamma | {$i} |\n";
}
$tableChunks = $chunker->chunk($bigTable, ['target_tokens' => 200, 'max_tokens' => 300]);
assert_true(count($tableChunks) > 1, 'An oversized table is split by rows.');
foreach ($tableChunks as $chunk) {
    assert_true(str_contains($chunk['content'], '| 型号 | 价格 |'), 'Every table piece repeats the header row.');
    assert_true($chunk['tokens'] <= 300 + 40, 'Table pieces respect the size budget.');
}
$ordinals = array_column($tableChunks, 'section_ordinal');
assert_same(range(1, count($tableChunks)), $ordinals, 'Ordinals number the chunks of one section.');

echo "OK\n";
