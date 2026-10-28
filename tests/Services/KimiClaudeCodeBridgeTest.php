<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\KimiClaudeCodeBridge;

$bridge = new KimiClaudeCodeBridge();

$kimiRoute = [
    'name' => 'kimi-codex',
    'external_name' => 'kimi-k2.7-code-highspeed',
    'base_url' => 'https://api.moonshot.cn/v1',
    'protocol' => 'openai',
];
$kimiCodeRoute = [
    'name' => 'kimi-code',
    'external_name' => 'kimi-for-coding',
    'base_url' => 'https://api.kimi.com/coding/v1',
    'protocol' => 'openai',
];
$otherRoute = [
    'name' => 'gpt-demo',
    'external_name' => 'gpt-4o-mini',
    'base_url' => 'https://api.openai.com/v1',
    'protocol' => 'openai',
];

assert_true(!$bridge->isKimiCodeRoute($kimiRoute), 'Normal Moonshot Kimi API routes should not be treated as Kimi Code routes.');
assert_true($bridge->isKimiCodeRoute($kimiCodeRoute), 'Kimi Code API routes should enable Claude Code compatibility.');
assert_true(!$bridge->isKimiCodeRoute($otherRoute), 'Non-Kimi routes should keep their normal protocol behavior.');

$anthropicRequest = new GatewayRequest(
    endpoint: '/v1/messages',
    protocol: 'anthropic',
    capability: 'text_generation',
    model: 'kimi-codex',
    stream: false,
    payload: [
        'model' => 'kimi-codex',
        'system' => 'You are concise.',
        'max_tokens' => 256,
        'temperature' => 0.2,
        'messages' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]],
        ],
    ],
    estimatedUsage: ['input_tokens' => 12],
);

$providerRequest = $bridge->toOpenAIProviderRequest($anthropicRequest);
assert_same('/v1/chat/completions', $providerRequest->endpoint, 'Claude Code messages should be sent to Kimi through chat completions.');
assert_same('openai', $providerRequest->protocol, 'Kimi upstream request should use the OpenAI-compatible protocol.');
assert_same('kimi-codex', $providerRequest->payload['model'], 'Bridge should preserve public model name before ProviderHttpClient applies external_name.');
assert_same(256, $providerRequest->payload['max_tokens'], 'Bridge should preserve max_tokens for Kimi chat completions.');
assert_same('system', $providerRequest->payload['messages'][0]['role'], 'Anthropic system prompt should become the first OpenAI system message.');
assert_same('user', $providerRequest->payload['messages'][1]['role'], 'Anthropic user message should be preserved for OpenAI.');
assert_same('hello', $providerRequest->payload['messages'][1]['content'], 'Anthropic text block should become OpenAI text content.');

$openaiRequest = new GatewayRequest(
    endpoint: '/v1/chat/completions',
    protocol: 'openai',
    capability: 'text_generation',
    model: 'kimi-codex',
    stream: false,
    payload: [
        'model' => 'kimi-codex',
        'temperature' => 0.2,
        'messages' => [['role' => 'user', 'content' => 'hello']],
    ],
    estimatedUsage: ['input_tokens' => 8],
);
$openaiProviderRequest = $bridge->toOpenAIProviderRequest($openaiRequest);
assert_same('/v1/chat/completions', $openaiProviderRequest->endpoint, 'OpenAI-compatible Kimi calls should still use chat completions upstream.');
assert_same($openaiRequest->payload['messages'], $openaiProviderRequest->payload['messages'], 'OpenAI-compatible Kimi calls should preserve chat messages upstream.');
assert_same(1.0, $openaiProviderRequest->payload['temperature'], 'Kimi Code upstream requests should normalize unsupported temperature values to 1.');

$anthropicResponse = $bridge->toAnthropicResponse($openaiRequest, $kimiRoute, [
    'id' => 'chatcmpl_123',
    'choices' => [[
        'message' => ['role' => 'assistant', 'content' => 'Kimi answer'],
        'finish_reason' => 'stop',
    ]],
    'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3, 'total_tokens' => 11],
]);

assert_same('message', $anthropicResponse['type'], 'Kimi output should be exposed as an Anthropic message.');
assert_same('assistant', $anthropicResponse['role'], 'Anthropic response role should be assistant.');
assert_same('kimi-codex', $anthropicResponse['model'], 'Anthropic response should keep the public TokenHub model name.');
assert_same('text', $anthropicResponse['content'][0]['type'], 'Text output should use Anthropic text content block.');
assert_same('Kimi answer', $anthropicResponse['content'][0]['text'], 'OpenAI message content should become Anthropic text.');
assert_same(8, $anthropicResponse['usage']['input_tokens'], 'OpenAI prompt tokens should map to Anthropic input_tokens.');
assert_same(3, $anthropicResponse['usage']['output_tokens'], 'OpenAI completion tokens should map to Anthropic output_tokens.');

$countTokens = $bridge->countTokensResponse($anthropicRequest);
assert_same(12, $countTokens['input_tokens'], 'Kimi Claude count_tokens should use the gateway request estimate.');
