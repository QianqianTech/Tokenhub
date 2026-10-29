<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// previous_response_id pinning uses a Redis mapping written as soon as the
// response completes (the usage record is written later by the worker).

use TokenHub\Services\Gateway\GatewayUsageRecorder;
use TokenHub\Support\App;

try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

$route = ['id' => 42, 'provider_id' => 7];
GatewayUsageRecorder::rememberResponseChannel('resp_abc', $route, ['id' => 5], 'success');
assert_same(42, GatewayUsageRecorder::rememberedResponseChannel('resp_abc', 5), 'The producing channel is remembered for the key.');
assert_same(null, GatewayUsageRecorder::rememberedResponseChannel('resp_abc', 6), 'Another API key cannot use the mapping.');
GatewayUsageRecorder::rememberResponseChannel('resp_failed', $route, ['id' => 5], 'failed');
assert_same(null, GatewayUsageRecorder::rememberedResponseChannel('resp_failed', 5), 'Failed responses are not remembered.');
GatewayUsageRecorder::rememberResponseChannel(null, $route, ['id' => 5], 'success');
GatewayUsageRecorder::rememberResponseChannel('resp_noroute', null, ['id' => 5], 'success');
assert_same(null, GatewayUsageRecorder::rememberedResponseChannel('resp_noroute', 5), 'Without a route nothing is stored.');
$source = (string) file_get_contents(__DIR__ . '/../../app/Services/GatewayService.php');
assert_true(str_contains($source, 'GatewayUsageRecorder::rememberedResponseChannel($previous'), 'Continuations consult the Redis mapping before the usage records.');

echo "PASS response channel pin\n";
