<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * MM-001: Kimi Code bridge keeps agent turns intact in both directions
 * (tool calls, tool results, thinking) for Anthropic and Responses clients.
 */

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\KimiClaudeCodeBridge;

$bridge = new KimiClaudeCodeBridge();
$openaiRoute = ['name' => 'k3.1-pro', 'external_name' => 'k3.1-pro', 'base_url' => 'https://api.kimi.ai/coding/v1', 'protocol' => 'openai'];
$anthropicRoute = ['name' => 'k3.1-pro', 'external_name' => 'k3.1-pro', 'base_url' => 'https://api.kimi.com/coding', 'protocol' => 'anthropic'];

$messagesRequest = static fn (array $payload, bool $stream = false): GatewayRequest => new GatewayRequest(
    endpoint: '/v1/messages',
    protocol: 'anthropic',
    capability: 'text_generation',
    model: 'k3.1-pro',
    stream: $stream,
    payload: $payload,
    estimatedUsage: ['input_tokens' => 50],
);

// Routing ---------------------------------------------------------------
$probe = $messagesRequest(['messages' => []]);
assert_true($bridge->isKimiCodeRoute($openaiRoute), 'International api.kimi.ai/coding host must be recognised as Kimi Code.');
assert_true($bridge->shouldBridge($probe, $openaiRoute), 'OpenAI-protocol Kimi Code channel must be bridged for Anthropic clients.');
assert_true(!$bridge->shouldBridge($probe, $anthropicRoute), 'Anthropic-protocol Kimi Code channel speaks Messages natively and must not be bridged.');

// Request translation ----------------------------------------------------
$agentTurn = $messagesRequest([
    'model' => 'k3.1-pro',
    'max_tokens' => 4096,
    'system' => [['type' => 'text', 'text' => 'You are a coding agent.']],
    'thinking' => ['type' => 'adaptive'],
    'output_config' => ['effort' => 'medium'],
    'tool_choice' => ['type' => 'auto'],
    'stop_sequences' => ['STOP'],
    'tools' => [
        ['name' => 'Read', 'description' => 'read a file', 'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]],
        ['type' => 'web_search_20250305', 'name' => 'web_search'],
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'read a.txt'],
        ['role' => 'assistant', 'content' => [
            ['type' => 'thinking', 'thinking' => 'I should read the file.', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'Reading it.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Read', 'input' => ['path' => 'a.txt']],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => [['type' => 'text', 'text' => 'hello']]],
            ['type' => 'text', 'text' => 'now summarise'],
        ]],
    ],
]);
$upstream = $bridge->toOpenAIProviderRequest($agentTurn)->payload;
$messages = $upstream['messages'];
assert_same('system', $messages[0]['role'], 'System blocks become the first system message.');
assert_same('assistant', $messages[2]['role'], 'Assistant turn is kept.');
assert_same('Reading it.', $messages[2]['content'], 'Assistant text is kept.');
assert_same('I should read the file.', $messages[2]['reasoning_content'], 'Thinking blocks are sent back as reasoning_content (Preserved Thinking).');
assert_same('toolu_1', $messages[2]['tool_calls'][0]['id'], 'tool_use becomes an OpenAI tool call with the same id.');
assert_same('{"path":"a.txt"}', $messages[2]['tool_calls'][0]['function']['arguments'], 'tool_use input becomes JSON arguments.');
assert_same('tool', $messages[3]['role'], 'tool_result becomes a tool message directly after the assistant tool calls.');
assert_same('toolu_1', $messages[3]['tool_call_id'], 'tool message keeps the tool_use id.');
assert_same('hello', $messages[3]['content'], 'tool_result text is kept.');
assert_same('user', $messages[4]['role'], 'Remaining user text follows the tool message.');
assert_same('now summarise', $messages[4]['content'], 'Remaining user text is kept.');
assert_same(1, count($upstream['tools']), 'Server tools without an input schema are dropped; function tools are kept.');
assert_same('auto', $upstream['tool_choice'], 'tool_choice auto is mapped.');
assert_same('high', $upstream['reasoning_effort'], 'Kimi has no medium effort: medium maps to high.');
assert_same(['STOP'], $upstream['stop'], 'stop_sequences map to stop.');
assert_true(!isset($upstream['thinking']), 'The thinking parameter is never sent to Kimi (K3.1 always thinks).');

$streamed = $bridge->toOpenAIProviderRequest($messagesRequest(['messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => true], true))->payload;
assert_same(true, $streamed['stream_options']['include_usage'] ?? null, 'Bridged streams ask for usage in the final chunk.');

// Non-streaming response translation ---------------------------------------
$anthropic = $bridge->toClientResponse($agentTurn, $openaiRoute, [
    'id' => 'chatcmpl-1',
    'choices' => [[
        'finish_reason' => 'tool_calls',
        'message' => [
            'role' => 'assistant',
            'content' => '',
            'reasoning_content' => 'Need another file.',
            'tool_calls' => [['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'Read', 'arguments' => '{"path":"b.txt"}']]],
        ],
    ]],
    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => 80]],
]);
assert_same('thinking', $anthropic['content'][0]['type'], 'reasoning_content becomes a thinking block.');
assert_same('tool_use', $anthropic['content'][1]['type'], 'tool_calls become tool_use blocks (no empty text block).');
assert_same('call_9', $anthropic['content'][1]['id'], 'tool_use keeps the call id.');
assert_same(['path' => 'b.txt'], $anthropic['content'][1]['input'], 'Arguments are decoded into the tool_use input.');
assert_same('tool_use', $anthropic['stop_reason'], 'finish_reason tool_calls maps to stop_reason tool_use.');
assert_same(20, $anthropic['usage']['input_tokens'], 'Anthropic input_tokens exclude cache reads.');
assert_same(80, $anthropic['usage']['cache_read_input_tokens'], 'Cached prompt tokens are reported as cache reads.');

// Streaming translation (Anthropic) -------------------------------------------
$chunks = [
    'data: ' . json_encode(['choices' => [['delta' => ['reasoning_content' => 'thinking...']]]]) . "\n",
    'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Let me check.']]]]) . "\n",
    'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'Read', 'arguments' => '{"pa']]]]]]]) . "\n",
    'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'th":"c.txt"}']]]]]]]) . "\n",
    'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n",
    'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 12]]) . "\n",
    "data: [DONE]\n",
];
$state = [];
$sse = '';
foreach ($chunks as $chunk) {
    // Split each line to exercise the cross-chunk line buffer.
    $half = intdiv(strlen($chunk), 2);
    $sse .= $bridge->transformOpenAIStreamChunk($agentTurn, $openaiRoute, substr($chunk, 0, $half), $state);
    $sse .= $bridge->transformOpenAIStreamChunk($agentTurn, $openaiRoute, substr($chunk, $half), $state);
}
$sse .= $bridge->finishStream($agentTurn, $openaiRoute, $state);
$events = [];
foreach (explode("\n", $sse) as $line) {
    if (str_starts_with($line, 'data: ')) {
        $events[] = json_decode(substr($line, 6), true);
    }
}
$types = array_column($events, 'type');
assert_same('message_start', $types[0], 'Stream opens with message_start.');
assert_same('message_stop', $types[count($types) - 1], 'Stream ends with message_stop, exactly once.');
assert_same(1, count(array_keys($types, 'message_stop', true)), 'finishStream after [DONE] must not close the stream twice.');
$blockStarts = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'content_block_start'));
assert_same(['thinking', 'text', 'tool_use'], array_map(static fn (array $event): string => $event['content_block']['type'], $blockStarts), 'Blocks: thinking, then text, then tool_use.');
assert_same('call_1', $blockStarts[2]['content_block']['id'], 'Streamed tool_use keeps the call id.');
$jsonDeltas = array_values(array_filter($events, static fn (array $event): bool => ($event['delta']['type'] ?? '') === 'input_json_delta'));
assert_same('{"path":"c.txt"}', $jsonDeltas[0]['delta']['partial_json'], 'Fragmented tool arguments are reassembled.');
$messageDelta = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'message_delta'))[0];
assert_same('tool_use', $messageDelta['delta']['stop_reason'], 'Streamed stop_reason reflects tool calls.');
assert_same(12, $messageDelta['usage']['output_tokens'], 'Streamed usage comes from the upstream usage chunk.');

// Responses (Codex) ------------------------------------------------------------
$responsesRequest = new GatewayRequest(
    endpoint: '/v1/responses',
    protocol: 'openai',
    capability: 'text_generation',
    model: 'k3.1-pro',
    stream: false,
    payload: [
        'model' => 'k3.1-pro',
        'instructions' => 'Be brief.',
        'max_output_tokens' => 512,
        'reasoning' => ['effort' => 'xhigh'],
        'tools' => [['type' => 'function', 'name' => 'shell', 'parameters' => ['type' => 'object']]],
        'input' => [
            ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'list files']]],
            ['type' => 'function_call', 'call_id' => 'call_a', 'name' => 'shell', 'arguments' => '{"cmd":"ls"}'],
            ['type' => 'function_call_output', 'call_id' => 'call_a', 'output' => 'a.txt'],
        ],
    ],
    estimatedUsage: ['input_tokens' => 20],
);
$codexUpstream = $bridge->toOpenAIProviderRequest($responsesRequest)->payload;
assert_same('system', $codexUpstream['messages'][0]['role'], 'Responses instructions become a system message.');
assert_same('list files', $codexUpstream['messages'][1]['content'], 'input_text parts become text.');
assert_same('call_a', $codexUpstream['messages'][2]['tool_calls'][0]['id'], 'function_call items become assistant tool calls.');
assert_same('tool', $codexUpstream['messages'][3]['role'], 'function_call_output items become tool messages.');
assert_same(512, $codexUpstream['max_tokens'], 'max_output_tokens maps to max_tokens.');
assert_same('max', $codexUpstream['reasoning_effort'], 'reasoning.effort xhigh maps to Kimi max.');
assert_same('shell', $codexUpstream['tools'][0]['function']['name'], 'Responses function tools become chat tools.');

$codexResponse = $bridge->toClientResponse($responsesRequest, $openaiRoute, [
    'id' => 'chatcmpl-2',
    'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Done.']]],
    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
]);
assert_same('response', $codexResponse['object'], 'Responses clients receive a Responses object, not an Anthropic message.');
assert_same('Done.', $codexResponse['output'][0]['content'][0]['text'], 'Assistant text becomes an output_text part.');

$chatRequest = new GatewayRequest(endpoint: '/v1/chat/completions', protocol: 'openai', capability: 'text_generation', model: 'k3.1-pro', stream: false, payload: ['messages' => []], estimatedUsage: []);
assert_true(!$bridge->translatesResponse($chatRequest), 'Chat Completions clients get the upstream response unchanged.');

$responsesStream = new GatewayRequest(endpoint: '/v1/responses', protocol: 'openai', capability: 'text_generation', model: 'k3.1-pro', stream: true, payload: [], estimatedUsage: []);
$state = [];
$out = $bridge->transformOpenAIStreamChunk($responsesStream, $openaiRoute, 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hi']]]]) . "\n", $state);
$out .= $bridge->transformOpenAIStreamChunk($responsesStream, $openaiRoute, "data: [DONE]\n", $state);
assert_true(str_contains($out, 'event: response.created') && str_contains($out, 'event: response.output_text.delta') && str_contains($out, 'event: response.completed'), 'Responses stream emits created, text delta and completed events.');

echo "PASS\n";
