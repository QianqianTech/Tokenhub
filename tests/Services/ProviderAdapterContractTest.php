<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Providers\AnthropicAdapter;
use TokenHub\Services\Providers\OpenAIAdapter;
use TokenHub\Services\Providers\VolcengineArkAdapter;

$openaiRequest = new GatewayRequest(
    endpoint: '/v1/chat/completions',
    protocol: 'openai',
    capability: 'text_generation',
    model: 'gpt-demo',
    stream: true,
    payload: ['model' => 'gpt-demo', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hello']]],
    estimatedUsage: ['input_tokens' => 10, 'output_tokens' => 0, 'total_tokens' => 10],
);

$openaiBuilt = (new OpenAIAdapter())->buildRequest($openaiRequest, ['external_name' => 'provider-gpt']);
assert_same('provider-gpt', $openaiBuilt['json']['model'], 'OpenAI adapter should replace model with external_name.');
assert_same('text/event-stream', $openaiBuilt['headers']['Accept'], 'OpenAI stream request should accept SSE.');

$openaiUsage = (new OpenAIAdapter())->usage($openaiRequest, [
    'usage' => [
        'prompt_tokens' => 3,
        'completion_tokens' => 5,
        'total_tokens' => 8,
        'prompt_tokens_details' => ['cached_tokens' => 2],
    ],
]);
assert_same(3, $openaiUsage['input_tokens'], 'OpenAI usage should read prompt_tokens.');
assert_same(5, $openaiUsage['output_tokens'], 'OpenAI usage should read completion_tokens.');
assert_same(8, $openaiUsage['total_tokens'], 'OpenAI usage should read total_tokens.');
assert_same(2, $openaiUsage['cache_read_tokens'], 'OpenAI usage should read cached prompt tokens.');
assert_same(0, $openaiUsage['cache_write_tokens'], 'OpenAI usage should default cache write tokens to zero.');

$anthropicRequest = new GatewayRequest(
    endpoint: '/v1/messages',
    protocol: 'anthropic',
    capability: 'text_generation',
    model: 'claude-demo',
    stream: false,
    payload: ['model' => 'claude-demo', 'messages' => [['role' => 'user', 'content' => 'hello']]],
    estimatedUsage: ['input_tokens' => 9, 'output_tokens' => 0, 'total_tokens' => 9],
);

$anthropicBuilt = (new AnthropicAdapter())->buildRequest($anthropicRequest, ['external_name' => 'provider-claude']);
assert_same('provider-claude', $anthropicBuilt['json']['model'], 'Anthropic adapter should replace model with external_name.');
assert_same('2023-06-01', $anthropicBuilt['headers']['anthropic-version'], 'Anthropic adapter should set default version header.');

$anthropicUsage = (new AnthropicAdapter())->usage($anthropicRequest, [
    'usage' => ['input_tokens' => 7, 'output_tokens' => 11, 'cache_read_input_tokens' => 4, 'cache_creation_input_tokens' => 3],
]);
assert_same(7, $anthropicUsage['input_tokens'], 'Anthropic usage should read input_tokens.');
assert_same(11, $anthropicUsage['output_tokens'], 'Anthropic usage should read output_tokens.');
assert_same(18, $anthropicUsage['total_tokens'], 'Anthropic usage should sum input and output tokens.');
assert_same(4, $anthropicUsage['cache_read_tokens'], 'Anthropic usage should read cache_read_input_tokens.');
assert_same(3, $anthropicUsage['cache_write_tokens'], 'Anthropic usage should read cache_creation_input_tokens.');

$seedanceRequest = new GatewayRequest(
    endpoint: '/v1/videos/generations',
    protocol: 'openai',
    capability: 'video_generation',
    model: 'seedance-video',
    stream: false,
    payload: [
        'model' => 'seedance-video',
        'prompt' => 'A cinematic city flythrough',
        'duration' => 8,
        'resolution' => '720p',
        'ratio' => '16:9',
        'image_url' => 'https://assets.example/source.png',
    ],
    estimatedUsage: ['input_tokens' => 20, 'output_tokens' => 0, 'total_tokens' => 20, 'video_seconds' => 8],
);

$seedanceAdapter = new VolcengineArkAdapter();
$seedanceBuilt = $seedanceAdapter->buildRequest($seedanceRequest, ['external_name' => 'doubao-seedance-2-0-pro']);
assert_same('/contents/generations/tasks', $seedanceBuilt['endpoint'], 'Volcengine Ark video requests should use the content generation task endpoint.');
assert_same('doubao-seedance-2-0-pro', $seedanceBuilt['json']['model'], 'Volcengine Ark adapter should replace model with external_name.');
assert_same('A cinematic city flythrough', $seedanceBuilt['json']['content'][0]['text'], 'Volcengine Ark adapter should convert prompt to text content.');
assert_same('image_url', $seedanceBuilt['json']['content'][1]['type'], 'Volcengine Ark adapter should include reference image content.');
assert_same('https://assets.example/source.png', $seedanceBuilt['json']['content'][1]['image_url']['url'], 'Volcengine Ark adapter should preserve reference image URL.');
assert_same(8, $seedanceBuilt['json']['duration'], 'Volcengine Ark adapter should preserve video duration.');

$seedanceQueued = $seedanceAdapter->normalizeResponse($seedanceRequest, ['external_name' => 'doubao-seedance-2-0-pro'], [
    'id' => 'cg_task_123',
    'status' => 'queued',
]);
assert_same('cg_task_123', $seedanceQueued['provider_task_id'], 'Volcengine Ark queued task id should be normalized.');
assert_same('processing', $seedanceQueued['status'], 'Volcengine Ark queued status should map to processing.');

$seedanceFinished = $seedanceAdapter->normalizeResponse($seedanceRequest, ['external_name' => 'doubao-seedance-2-0-pro'], [
    'id' => 'cg_task_123',
    'status' => 'succeeded',
    'content' => ['video_url' => 'https://assets.example/result.mp4'],
]);
assert_same('succeeded', $seedanceFinished['status'], 'Volcengine Ark succeeded status should be preserved.');
assert_same('https://assets.example/result.mp4', $seedanceFinished['assets'][0]['url'], 'Volcengine Ark video result URL should become a media asset.');
assert_same(8, $seedanceAdapter->usage($seedanceRequest, $seedanceFinished)['video_seconds'], 'Volcengine Ark usage should retain estimated video seconds.');
