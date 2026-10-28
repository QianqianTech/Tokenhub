<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\PriceService;

$service = new PriceService();

$usage = [
    'input_tokens' => 1000,
    'output_tokens' => 500,
    'audio_seconds' => 2,
    'tts_chars' => 100,
    'image_count' => 3,
    'video_seconds' => 4,
];

$price = [
    'id' => 42,
    'input_token_price' => '0.00000100',
    'output_token_price' => '0.00000200',
    'audio_second_price' => '0.01000000',
    'tts_char_price' => '0.00010000',
    'image_price' => '0.03000000',
    'video_second_price' => '0.25000000',
    'request_price' => '0.00500000',
    'currency' => 'USD',
    'effective_at' => '2026-07-08 00:00:00',
];

$priced = $service->applyPrice($usage, $price);
assert_same('1.12700000', $priced['cost_amount'], 'Cost should be calculated from the captured price row.');
assert_same(42, $priced['price_snapshot']['price_id'], 'Snapshot should retain source price id.');
assert_same('USD', $priced['price_snapshot']['currency'], 'Snapshot should retain currency.');

$unpriced = $service->applyPrice($usage, null);
assert_same(null, $unpriced['cost_amount'], 'Missing price should keep cost empty.');
assert_same(null, $unpriced['price_snapshot'], 'Missing price should keep snapshot empty.');

$lookup = $service->lookupConstraint(9, '2026-07-08 12:00:00');
assert_same('model_id = ? AND effective_at <= ?', $lookup['where'], 'Effective price lookup should find rows active at the requested time.');
assert_same([9, '2026-07-08 12:00:00'], $lookup['params'], 'Effective price lookup should bind model and effective time.');
assert_same('effective_at DESC, id DESC', $lookup['order'], 'Effective price lookup should return newest active price first.');
