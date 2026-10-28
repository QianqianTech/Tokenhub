<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Gateway\AnthropicRequestParser;
use TokenHub\Services\Gateway\OpenAIRequestParser;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

$openai = new OpenAIRequestParser();

$chat = $openai->parse(new Request(
    'POST',
    '/v1/chat/completions',
    [],
    [],
    ['model' => 'gpt-demo', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hello']]],
    '{"model":"gpt-demo"}',
    new RequestContext('req_test', 'trc_test'),
));
assert_same('openai', $chat->protocol, 'Chat parser should use openai protocol.');
assert_same('text_generation', $chat->capability, 'Chat parser should map to text_generation.');
assert_same('gpt-demo', $chat->model, 'Chat parser should expose model.');
assert_true($chat->stream, 'Chat parser should preserve stream=true.');
assert_true($chat->estimatedUsage['input_tokens'] > 0, 'Chat parser should estimate input tokens.');

$embedding = $openai->parse(new Request(
    'POST',
    '/v1/embeddings',
    [],
    [],
    ['model' => 'embed-demo', 'input' => 'hello'],
    '{"model":"embed-demo"}',
    new RequestContext('req_test', 'trc_test'),
));
assert_same('embedding', $embedding->capability, 'Embeddings parser should map to embedding capability.');

$asr = $openai->parse(new Request(
    'POST',
    '/v1/audio/transcriptions',
    [],
    [],
    ['model' => 'asr-demo', 'duration' => 12],
    '{"model":"asr-demo"}',
    new RequestContext('req_test', 'trc_test'),
));
assert_same('asr', $asr->capability, 'Audio transcription parser should map to asr.');
assert_same(12, $asr->estimatedUsage['audio_seconds'], 'ASR parser should estimate audio seconds.');

$tts = $openai->parse(new Request(
    'POST',
    '/v1/audio/speech',
    [],
    [],
    ['model' => 'tts-demo', 'input' => 'hello'],
    '{"model":"tts-demo"}',
    new RequestContext('req_test', 'trc_test'),
));
assert_same('tts', $tts->capability, 'Audio speech parser should map to tts.');
assert_same(5, $tts->estimatedUsage['tts_chars'], 'TTS parser should estimate input characters.');

$image = $openai->parse(new Request(
    'POST',
    '/v1/images/generations',
    [],
    [],
    ['model' => 'image-demo', 'prompt' => 'city'],
    '{"model":"image-demo"}',
    new RequestContext('req_test', 'trc_test'),
));
assert_same('image_generation', $image->capability, 'Images parser should map to image_generation.');
assert_true($image->estimatedUsage['image_count'] >= 1, 'Images parser should estimate image count.');

$video = $openai->parse(new Request(
    'POST',
    '/v1/videos/generations',
    [],
    [],
    ['model' => 'video-demo', 'prompt' => 'city', 'duration' => 8],
    '{"model":"video-demo"}',
    new RequestContext('req_test', 'trc_test'),
));
assert_same('video_generation', $video->capability, 'Videos parser should map to video_generation.');
assert_same(8, $video->estimatedUsage['video_seconds'], 'Videos parser should estimate video seconds.');

$anthropicPayload = [
    'model' => 'claude-demo',
    'stream' => false,
    'messages' => [[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'describe this'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'abc']],
        ],
    ]],
];
$anthropic = (new AnthropicRequestParser())->parse(new Request(
    'POST',
    '/v1/messages',
    [],
    [],
    $anthropicPayload,
    json_encode($anthropicPayload, JSON_UNESCAPED_SLASHES),
    new RequestContext('req_test', 'trc_test'),
));
assert_same('anthropic', $anthropic->protocol, 'Messages parser should use anthropic protocol.');
assert_same($anthropicPayload['messages'][0]['content'], $anthropic->payload['messages'][0]['content'], 'Anthropic parser should preserve multimodal content blocks.');
assert_same('vision_understanding', $anthropic->capability, 'Messages parser should map image content to vision_understanding.');
assert_same(1, $anthropic->estimatedUsage['image_count'], 'Messages parser should estimate image count.');
