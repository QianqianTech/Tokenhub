<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\MultimodalTaskService;

$service = new MultimodalTaskService();

$payload = [
    'model' => 'tts-demo',
    'input' => 'hello',
    'api_key' => 'should-not-leak',
    'audio' => ['data' => str_repeat('a', 400)],
];
$sanitized = $service->sanitizeSubmitPayload($payload);
assert_same('****', $sanitized['api_key'], 'Submit payload should redact API keys.');
assert_same('[omitted]', $sanitized['audio']['data'], 'Submit payload should omit large media data fields.');

$task = $service->publicTask([
    'task_id' => 'task_123',
    'task_type' => 'tts',
    'status' => 'succeeded',
    'provider_task_id' => 'provider_123',
    'progress' => 100,
    'result' => '{"text":"ok","asset_ids":["asset_1"]}',
    'error_code' => null,
    'error_message' => null,
    'created_at' => '2026-07-08 00:00:00',
    'finished_at' => '2026-07-08 00:00:01',
], [
    ['asset_id' => 'asset_1', 'media_type' => 'audio', 'storage_path' => '/secret/audio.wav'],
]);

assert_same('task_123', $task['task_id'], 'Public task should include task_id.');
assert_same('succeeded', $task['status'], 'Public task should include status.');
assert_true(isset($task['assets'][0]['asset_id']), 'Public task should include public assets.');
assert_true(!array_key_exists('storage_path', $task['assets'][0]), 'Public task assets should not expose storage_path.');
