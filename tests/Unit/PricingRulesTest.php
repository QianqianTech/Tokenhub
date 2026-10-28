<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * MM-002 / MM-015 / MM-024: pricing rules on a price version.
 */

use TokenHub\Services\PriceService;
use TokenHub\Services\Pricing\PricingDimensions;
use TokenHub\Services\Pricing\PricingRules;

$service = new PriceService();
$rules = new PricingRules();

// DeepSeek V4.1 Flash: off-peak base price, peak = x2 on weekdays
// 01:00-04:00 and 06:00-10:00 UTC, Chinese public holidays off-peak.
$deepseek = [
    'id' => 1,
    'currency' => 'USD',
    'input_token_price' => '0.00000015',
    'cache_read_token_price' => '0.000000003',
    'output_token_price' => '0.0000006',
    'pricing_rules' => json_encode([
        'time_windows' => [[
            'name' => 'peak', 'timezone' => 'UTC', 'days' => [1, 2, 3, 4, 5],
            'hours' => ['01:00-04:00', '06:00-10:00'], 'exclude_dates' => ['2026-10-01'], 'multiplier' => '2',
        ]],
    ]),
];
assert_same([], $rules->validate($deepseek['pricing_rules']), 'DeepSeek peak rule is valid.');
$usage = ['input_tokens' => 1000000, 'cache_read_tokens' => 0, 'output_tokens' => 1000000];
$offPeak = $service->applyPrice($usage, $deepseek, '2026-10-28 12:00:00'); // Wednesday 12:00 UTC
$peak = $service->applyPrice($usage, $deepseek, '2026-10-28 07:30:00');    // Wednesday 07:30 UTC
$weekend = $service->applyPrice($usage, $deepseek, '2026-10-31 07:30:00'); // Saturday
$holiday = $service->applyPrice($usage, $deepseek, '2026-10-01 07:30:00'); // excluded date
assert_same('0.75000000', $offPeak['cost_amount'], 'Off-peak: 0.15 + 0.60 per 1M.');
assert_same('1.50000000', $peak['cost_amount'], 'Peak doubles the price.');
assert_same(['time_window:peak'], $peak['price_snapshot']['applied_rules'] ?? null, 'Applied rule is kept in the price snapshot.');
assert_same('0.75000000', $weekend['cost_amount'], 'Weekends are off-peak.');
assert_same('0.75000000', $holiday['cost_amount'], 'Excluded dates are off-peak.');

// Window in another timezone wrapping midnight (22:00-02:00 Asia/Shanghai, Mondays).
$wrap = ['name' => 'night', 'timezone' => 'Asia/Shanghai', 'days' => [1], 'hours' => ['22:00-02:00'], 'multiplier' => '0.5'];
assert_true($rules->windowMatches($wrap, '2026-10-26 15:00:00'), 'Monday 23:00 Shanghai is inside the window.');
assert_true($rules->windowMatches($wrap, '2026-10-26 17:30:00'), 'Tuesday 01:30 Shanghai still belongs to the Monday window.');
assert_true(!$rules->windowMatches($wrap, '2026-10-27 15:00:00'), 'Tuesday 23:00 Shanghai is outside (only Mondays).');

// Context tier: the whole request is priced at the higher tier.
$tiered = ['id' => 2, 'input_token_price' => '0.000001', 'output_token_price' => '0.000005', 'pricing_rules' => ['context_tiers' => [['name' => '>100K', 'min_input_tokens' => 100001, 'prices' => ['input_token_price' => '0.000002', 'output_token_price' => '0.00001']]]]];
assert_same('0.15000000', $service->applyPrice(['input_tokens' => 100000, 'output_tokens' => 10000], $tiered)['cost_amount'], 'At the threshold the base tier applies.');
assert_same('0.30000200', $service->applyPrice(['input_tokens' => 100001, 'output_tokens' => 10000], $tiered)['cost_amount'], 'Above it the whole request uses the high tier.');

// Video: price per second by resolution and audio.
$video = ['id' => 3, 'video_second_price' => '0.05', 'pricing_rules' => ['dimension_prices' => [
    ['name' => '1080p+audio', 'match' => ['resolution' => '1080p', 'generate_audio' => true], 'prices' => ['video_second_price' => '0.12']],
    ['name' => '1080p', 'match' => ['resolution' => '1080P'], 'prices' => ['video_second_price' => '0.08']],
]]];
$dims = PricingDimensions::fromPayload('video_generation', ['resolution' => '1080p', 'generate_audio' => true, 'duration' => 10]);
assert_same('1.20000000', $service->applyPrice(['video_seconds' => 10, 'pricing_dimensions' => $dims], $video)['cost_amount'], '1080p with audio: 10 s x 0.12.');
assert_same('0.80000000', $service->applyPrice(['video_seconds' => 10, 'pricing_dimensions' => PricingDimensions::fromPayload('video_generation', ['resolution' => '1080p'])], $video)['cost_amount'], 'Resolution match is case-insensitive.');
assert_same('0.50000000', $service->applyPrice(['video_seconds' => 10, 'pricing_dimensions' => ['resolution' => '720p']], $video)['cost_amount'], 'No match keeps the base price.');

// gpt-image: image tokens priced apart from text tokens.
$image = ['id' => 4, 'input_token_price' => '0.000005', 'output_token_price' => '0.00001', 'pricing_rules' => ['modality_token_prices' => ['image_input_token_price' => '0.000008', 'image_output_token_price' => '0.00003']]];
$imageCost = $service->applyPrice(['input_tokens' => 1500, 'image_input_tokens' => 1000, 'output_tokens' => 4000, 'image_output_tokens' => 4000], $image);
assert_same('0.13050000', $imageCost['cost_amount'], '500 text x 5/M + 1000 image-in x 8/M + 4000 image-out x 30/M.');
// Modality prices are the base the window multiplier applies to.
$imagePeak = $image;
$imagePeak['pricing_rules']['time_windows'] = [['name' => 'peak', 'timezone' => 'UTC', 'hours' => ['00:00-23:59'], 'multiplier' => '2']];
$imagePeakCost = $service->applyPrice(['input_tokens' => 1500, 'image_input_tokens' => 1000, 'output_tokens' => 4000, 'image_output_tokens' => 4000], $imagePeak, '2026-10-28 12:00:00');
assert_same('0.26100000', $imagePeakCost['cost_amount'], 'Peak multiplier also doubles modality token prices.');

// A wrapped window's after-midnight part belongs to the start day, so an
// excluded start day also excludes the early hours of the next day.
$wrapExcluded = $wrap + ['exclude_dates' => ['2026-10-26']];
assert_true(!$rules->windowMatches($wrapExcluded, '2026-10-26 15:00:00'), 'Excluded Monday 23:00 is outside.');
assert_true(!$rules->windowMatches($wrapExcluded, '2026-10-26 17:30:00'), 'Tuesday 01:30 belongs to the excluded Monday window.');

// Cost-plus: upstream-reported cost x multiplier when present, catalog price otherwise.
$costPlus = ['id' => 5, 'input_token_price' => '0.000001', 'pricing_rules' => ['upstream_cost' => ['enabled' => true, 'multiplier' => '1.2']]];
$withUpstream = $service->applyPrice(['input_tokens' => 1000, 'upstream_cost' => '0.0105'], $costPlus);
assert_same('0.01260000', $withUpstream['cost_amount'], 'Upstream cost x 1.2.');
assert_same('upstream_cost', $withUpstream['price_snapshot']['source'], 'Snapshot says the cost came from the upstream.');
assert_same('0.00100000', $service->applyPrice(['input_tokens' => 1000], $costPlus)['cost_amount'], 'Without upstream cost the catalog price applies.');

// Validation.
assert_true($rules->validate(['time_windows' => [['hours' => ['25:00-26:00'], 'multiplier' => 2]]]) !== [], 'Invalid hour ranges are rejected.');
assert_true($rules->validate(['bogus' => 1]) !== [], 'Unknown rule keys are rejected.');
assert_true($rules->validate('not json') !== [], 'Non-JSON is rejected.');
assert_true($rules->validate(['context_tiers' => [['min_input_tokens' => 10]]]) !== [], 'A tier needs a multiplier or prices.');
assert_same([], $rules->validate(null), 'No rules is valid.');

echo "PASS\n";
