<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ExternalIdentityService;

$capabilities = (new ExternalIdentityService())->capabilities();

assert_true(isset($capabilities['model_endpoints']), 'Capabilities should expose model endpoint discovery.');
foreach ([
    'text_generation' => '/v1/chat/completions',
    'anthropic_messages' => '/v1/messages',
    'anthropic_count_tokens' => '/v1/messages/count_tokens',
    'embedding' => '/v1/embeddings',
    'asr' => '/v1/audio/transcriptions',
    'tts' => '/v1/audio/speech',
    'image_generation' => '/v1/images/generations',
    'video_generation' => '/v1/videos/generations',
] as $capability => $endpoint) {
    assert_same($endpoint, $capabilities['model_endpoints'][$capability] ?? null, 'Capabilities should document endpoint for ' . $capability . '.');
}

assert_true(isset($capabilities['modalities']), 'Capabilities should expose modality matrix.');
assert_same(['text', 'image'], $capabilities['modalities']['vision_understanding']['input'] ?? null, 'Vision should declare text plus image input.');
assert_same(['video'], $capabilities['modalities']['video_generation']['output'] ?? null, 'Video generation should declare video output.');
assert_same('/v1/images/edits', $capabilities['model_endpoints']['image_edit'] ?? null, 'Image edits are a supported endpoint.');
assert_same('/v1/audio/translations', $capabilities['model_endpoints']['asr_translation'] ?? null, 'Audio translations are a supported endpoint.');
assert_true(!in_array('image_edit', $capabilities['planned_capabilities'] ?? [], true), 'Supported capabilities are not listed as planned.');
assert_same(['text', 'image', 'video'], $capabilities['modalities']['video_understanding']['input'] ?? null, 'Video understanding is a supported modality.');
assert_same(['text', 'audio'], $capabilities['modalities']['audio_understanding']['input'] ?? null, 'Audio understanding is a supported modality.');
assert_same('/v1/tasks/{id}:cancel', $capabilities['model_endpoints']['task_cancel'] ?? null, 'Task cancellation is discoverable.');

$requestService = file_get_contents(__DIR__ . '/../../app/Services/ExternalRequestService.php') ?: '';
assert_true(str_contains($requestService, 'usage_record_id = ?'), 'Request detail should find task records by usage_record_id.');
assert_true(!str_contains($requestService, 'task_id = ? ORDER BY id DESC LIMIT 10'), 'Request detail should not assume task_id equals request_id.');

$usageService = file_get_contents(__DIR__ . '/../../app/Services/UsageService.php') ?: '';
assert_true(str_contains($usageService, 'WebhookService'), 'Usage write path should emit external webhook events.');
assert_true(str_contains($usageService, 'request.'), 'Usage write path should emit request status events.');
assert_true(str_contains($usageService, 'task.'), 'Usage write path should emit task status events.');
