<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ProviderModelSyncService;

$service = new ProviderModelSyncService();

$models = $service->normalizeModels([
    'data' => [
        ['id' => 'deepseek-chat', 'object' => 'model'],
        ['id' => 'text-embedding-3-large', 'owned_by' => 'openai'],
        ['id' => 'dall-e-3'],
        ['id' => 'whisper-1'],
        ['id' => 'tts-1'],
    ],
]);

assert_same('deepseek-chat', $models[0]['external_name'], 'Model id should become external_name.');
assert_same('deepseek-chat', $models[0]['name'], 'Model id should become the default internal name.');
assert_same('text_generation', $models[0]['capability'], 'Chat model should default to text generation.');
assert_same('embedding', $models[1]['capability'], 'Embedding model should infer embedding capability.');
assert_same('image_generation', $models[2]['capability'], 'Image model should infer image capability.');
assert_same('asr', $models[3]['capability'], 'Whisper model should infer ASR capability.');
assert_same('tts', $models[4]['capability'], 'TTS model should infer TTS capability.');

$plainList = $service->normalizeModels(['models' => ['hunyuan-lite', 'hunyuan-turbo']]);
assert_same('hunyuan-lite', $plainList[0]['external_name'], 'Plain model lists should be normalized.');
assert_same(2, count($plainList), 'Plain model lists should preserve model count.');

$kimiModels = $service->normalizeModels([
    'data' => [
        ['id' => 'kimi-k2.7-code-highspeed', 'context_length' => 256000],
        ['id' => 'kimi-k2.7-code'],
        ['id' => 'kimi-k2.6'],
        ['id' => 'moonshot-v1-8k'],
    ],
]);

assert_same('text_generation', $kimiModels[0]['capability'], 'Kimi code model should route as text generation for Codex-compatible calls.');
assert_same(256000, $kimiModels[0]['context_length'], 'Kimi code model should preserve provider context length.');
assert_same('text_generation', $kimiModels[1]['capability'], 'Kimi code model should infer text generation.');
assert_same('vision_understanding', $kimiModels[2]['capability'], 'Kimi multimodal model should route through the existing vision understanding capability.');
assert_same('text_generation', $kimiModels[3]['capability'], 'Legacy Moonshot model names should infer text generation.');

$volcengineModels = $service->normalizeModels([
    'data' => [
        ['id' => 'doubao-seedance-2-0-pro'],
        ['id' => 'doubao-seedream-4-0'],
        ['id' => 'doubao-1-5-thinking-pro'],
    ],
]);

assert_same('video_generation', $volcengineModels[0]['capability'], 'Seedance models should infer video generation capability.');
assert_same('image_generation', $volcengineModels[1]['capability'], 'Seedream models should infer image generation capability.');
assert_same('text_generation', $volcengineModels[2]['capability'], 'Doubao text models should infer text generation capability.');
