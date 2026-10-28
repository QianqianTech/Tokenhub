<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Gateway\Stream\SseEventParser;
use TokenHub\Services\Gateway\Stream\StreamAccumulator;
use TokenHub\Services\Gateway\UsageNormalizer;

/**
 * Feeds the same stream through every possible chunk boundary and returns the parsed events.
 *
 * @return list<array{event: string, data: string, id: ?string}>
 */
$parseSplit = static function (string $stream, int $chunkSize): array {
    $parser = new SseEventParser();
    $events = [];
    foreach (str_split($stream, max(1, $chunkSize)) as $chunk) {
        array_push($events, ...$parser->push($chunk));
    }
    $tail = $parser->finish();
    array_push($events, ...$tail['events']);

    return $events;
};

$openAiStream = "data: {\"id\":\"gen-1\",\"model\":\"m\",\"provider\":\"Fireworks\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"你好\"}}]}\r\n\r\n"
    . ": keep-alive comment\n\n"
    . "data: {\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_1\",\"function\":{\"name\":\"lookup\",\"arguments\":\"{\\\"q\\\":\"}}]}}]}\n\n"
    . "data: {\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"\\\"tokenhub\\\"}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
    . "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":12,\"completion_tokens\":7,\"total_tokens\":19,\"prompt_tokens_details\":{\"cached_tokens\":4},\"completion_tokens_details\":{\"reasoning_tokens\":2},\"cost\":0.00042}}\n\n"
    . "data: [DONE]\n\n";

$reference = $parseSplit($openAiStream, strlen($openAiStream));
assert_same(5, count($reference), 'Comment lines must not produce events and CRLF must terminate events.');
foreach ([1, 2, 3, 7, 13, 64] as $size) {
    assert_same($reference, $parseSplit($openAiStream, $size), "Byte split at {$size} must yield the same event sequence.");
}

$multiLine = "event: note\ndata: line-1\ndata: line-2\n\n";
$events = $parseSplit($multiLine, 1);
assert_same('note', $events[0]['event'], 'event field should be kept.');
assert_same("line-1\nline-2", $events[0]['data'], 'Multiple data lines are joined with a newline.');

$merged = $parseSplit("data: {\"a\":1}\n\ndata: {\"a\":2}\n\n", 1000);
assert_same(2, count($merged), 'Two events in one network chunk must both be emitted.');

$noTerminator = $parseSplit("data: {\"a\":1}", 3);
assert_same(1, count($noTerminator), 'finish() flushes a trailing event without a blank line.');

$accumulate = static function (string $kind, string $stream, int $chunkSize): StreamAccumulator {
    $acc = new StreamAccumulator($kind);
    foreach (str_split($stream, $chunkSize) as $chunk) {
        $acc->push($chunk);
    }
    $acc->finish();

    return $acc;
};

$chat = $accumulate(UsageNormalizer::KIND_CHAT, $openAiStream, 5);
assert_same('success', $chat->state(), 'Chat stream with [DONE] completes.');
assert_same('你好', $chat->text(), 'Chat text is reconstructed across splits, including multibyte characters.');
assert_same('{"q":"tokenhub"}', $chat->toolCalls()[0]['arguments'], 'Split tool call arguments rebuild to complete JSON.');
assert_same('call_1', $chat->toolCalls()[0]['id'], 'Tool call id is kept.');
assert_same(12, $chat->usage()['input_tokens'], 'Final usage chunk is read.');
assert_same(4, $chat->usage()['cache_read_tokens'], 'Cached prompt tokens are read from prompt_tokens_details.');
assert_same(2, $chat->usage()['reasoning_tokens'], 'Reasoning tokens are read from completion_tokens_details.');
assert_same('0.00042', $chat->usage()['upstream_cost'], 'OpenRouter usage.cost is preserved.');
assert_same('gen-1', $chat->upstream()['upstream_request_id'], 'Generation id is captured for reconciliation.');
assert_same('Fireworks', $chat->upstream()['upstream_provider'], 'Actual upstream provider is captured.');

$midError = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"partial\"}}]}\n\n"
    . "data: {\"error\":{\"code\":502,\"message\":\"Provider disconnected\"}}\n\n";
$errored = $accumulate(UsageNormalizer::KIND_CHAT, $midError, 4);
assert_same('upstream_error', $errored->state(), 'An in-band error after HTTP 200 must not be recorded as success.');
assert_same('502', $errored->error()['code'], 'In-band error code is kept.');

$truncated = $accumulate(UsageNormalizer::KIND_CHAT, "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"partial\"}}]}\n\n", 9);
assert_same('incomplete', $truncated->state(), 'EOF without terminal marker is incomplete.');

$finishOnly = $accumulate(UsageNormalizer::KIND_CHAT, "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"ok\"},\"finish_reason\":\"stop\"}]}\n\n", 9);
assert_same('success', $finishOnly->state(), 'finish_reason without [DONE] is accepted for compatible providers.');
assert_same('completed_without_done', $finishOnly->terminalReason(), 'The relaxed terminal reason is reported.');

$anthropicStream = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"model\":\"claude\",\"usage\":{\"input_tokens\":10,\"cache_read_input_tokens\":30,\"cache_creation_input_tokens\":5,\"output_tokens\":1}}}\n\n"
    . "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n"
    . "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":1,\"content_block\":{\"type\":\"tool_use\",\"id\":\"toolu_1\",\"name\":\"get\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":1,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\\\"a\\\":\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":1,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"1}\"}}\n\n"
    . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"tool_use\"},\"usage\":{\"output_tokens\":15}}\n\n"
    . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{},\"usage\":{\"output_tokens\":21}}\n\n"
    . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";
$anthropic = $accumulate(UsageNormalizer::KIND_ANTHROPIC, $anthropicStream, 11);
assert_same('success', $anthropic->state(), 'Anthropic stream completes on message_stop.');
assert_same(45, $anthropic->usage()['input_tokens'], 'Anthropic input includes uncached + cache read + cache creation tokens.');
assert_same(30, $anthropic->usage()['cache_read_tokens'], 'Anthropic cache read tokens are kept.');
assert_same(21, $anthropic->usage()['output_tokens'], 'Cumulative message_delta usage must not be summed.');
assert_same('{"a":1}', $anthropic->toolCalls()[0]['arguments'], 'Anthropic input_json_delta rebuilds tool input.');

$anthropicError = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":3,\"output_tokens\":1}}}\n\n"
    . "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n";
$anthropicFailed = $accumulate(UsageNormalizer::KIND_ANTHROPIC, $anthropicError, 6);
assert_same('upstream_error', $anthropicFailed->state(), 'Anthropic error event fails the stream.');
assert_same('overloaded_error', $anthropicFailed->error()['type'], 'Anthropic error type is kept.');

$responsesStream = "event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\",\"model\":\"gpt\",\"error\":null}}\n\n"
    . "event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Hello\"}\n\n"
    . "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\",\"usage\":{\"input_tokens\":20,\"input_tokens_details\":{\"cached_tokens\":8},\"output_tokens\":9,\"output_tokens_details\":{\"reasoning_tokens\":3},\"total_tokens\":29}}}\n\n";
$responses = $accumulate(UsageNormalizer::KIND_RESPONSES, $responsesStream, 7);
assert_same('success', $responses->state(), 'Responses stream completes on response.completed.');
assert_same(20, $responses->usage()['input_tokens'], 'Responses nested input_tokens are read.');
assert_same(8, $responses->usage()['cache_read_tokens'], 'Responses cached tokens are read.');
assert_same(3, $responses->usage()['reasoning_tokens'], 'Responses reasoning tokens are read.');
assert_same('resp_1', $responses->upstream()['upstream_request_id'], 'Responses id is captured.');

$responsesFailed = $accumulate(UsageNormalizer::KIND_RESPONSES, "event: response.failed\ndata: {\"type\":\"response.failed\",\"response\":{\"error\":{\"code\":\"server_error\",\"message\":\"boom\"}}}\n\n", 5);
assert_same('upstream_error', $responsesFailed->state(), 'response.failed is an upstream error.');

$normalizer = new UsageNormalizer();
$nonStreamResponses = $normalizer->fromResponse(UsageNormalizer::KIND_RESPONSES, ['usage' => ['input_tokens' => 5, 'output_tokens' => 2]]);
assert_same(5, $nonStreamResponses['input_tokens'], 'Non-stream Responses usage uses input_tokens, not prompt_tokens.');
assert_same('measured', $nonStreamResponses['usage_source'], 'Upstream usage is marked measured.');
$deepseek = $normalizer->normalize(UsageNormalizer::KIND_CHAT, ['prompt_tokens' => 100, 'completion_tokens' => 10, 'prompt_cache_hit_tokens' => 60, 'prompt_cache_miss_tokens' => 40]);
assert_same(60, $deepseek['cache_read_tokens'], 'DeepSeek prompt_cache_hit_tokens map to cache reads.');
assert_same(null, $normalizer->fromResponse(UsageNormalizer::KIND_CHAT, ['choices' => []]), 'Missing usage is null so callers can mark it estimated.');
$estimated = $normalizer->estimated(['input_tokens' => 10], 41);
assert_same('estimated', $estimated['usage_source'], 'Estimated usage is never labelled measured.');
assert_same(11, $estimated['output_tokens'], 'Estimated output uses chars/4 rounded up.');
