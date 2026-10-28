<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagRetrievalProfileService;

$resolved = (new RagRetrievalProfileService())->resolve([
    'min_vector_score' => 9,
    'min_hybrid_confidence' => -1,
]);

assert_same(1.0, $resolved['min_vector_score'], 'Vector threshold should clamp to one.');
assert_same(0.0, $resolved['min_hybrid_confidence'], 'Hybrid threshold should clamp to zero.');
assert_true($resolved['warnings'] !== [], 'Invalid profile values should be reported as warnings.');

$nonFinite = (new RagRetrievalProfileService())->resolve([
    'min_vector_score' => NAN,
    'min_lexical_score' => INF,
]);
assert_same(0.35, $nonFinite['min_vector_score'], 'NaN threshold must fall back to the default.');
assert_same(0.08, $nonFinite['min_lexical_score'], 'Infinite threshold must fall back to the default.');
assert_true(count($nonFinite['warnings']) >= 2, 'Every non-finite threshold must be reported.');
