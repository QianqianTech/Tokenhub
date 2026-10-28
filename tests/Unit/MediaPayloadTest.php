<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\DesensitizationService;
use TokenHub\Services\Gateway\BudgetEstimator;
use TokenHub\Services\Gateway\MediaPayload;
use TokenHub\Services\Gateway\MultipartUpload;

$image = 'data:image/jpeg;base64,' . base64_encode(random_bytes(300000));
$signed = 'https://bucket.example.com/a.png?X-Amz-Security-Token=abc&Expires=1730123456789';

// Token estimates: media parts cost a fixed budget, not base64 length / 4.
$chat = ['model' => 'm', 'messages' => [['role' => 'user', 'content' => [
    ['type' => 'text', 'text' => 'describe'],
    ['type' => 'image_url', 'image_url' => ['url' => $image]],
    ['type' => 'image_url', 'image_url' => ['url' => $signed]],
]]]];
$estimate = MediaPayload::estimate($chat);
assert_same(2, $estimate['media_parts'], 'Inline and URL images are both media parts.');
assert_true($estimate['tokens'] < 5000, 'A 400 KB inline image is not estimated at 100k tokens.');
assert_same($estimate['tokens'], (new BudgetEstimator())->inputTokens($chat), 'Budget reservations use the same media-aware estimate.');
$text = ['model' => 'm', 'messages' => [['role' => 'user', 'content' => str_repeat('x', 400)]]];
assert_same((int) ceil(strlen((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) / 4), MediaPayload::estimate($text)['tokens'], 'Text-only estimates are unchanged.');
assert_same(1, MediaPayload::estimate(['input' => [['role' => 'user', 'content' => [['type' => 'input_image', 'image_url' => $image]]]]])['media_parts'], 'Responses input_image is recognised.');
assert_same(1, MediaPayload::estimate(['messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode(random_bytes(2000))]]]]]])['media_parts'], 'Anthropic base64 image source is recognised.');
assert_same(0, MediaPayload::estimate(['messages' => [['role' => 'user', 'content' => 'see https://example.com/page?token=1']]])['media_parts'], 'A URL inside plain text is not media.');

// Desensitization keeps redacting text but never rewrites media.
$redacted = (new DesensitizationService())->redact([
    'messages' => [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => '联系 13812345678 或 a@b.com, token=supersecret'],
        ['type' => 'image_url', 'image_url' => ['url' => $signed]],
        ['type' => 'image_url', 'image_url' => ['url' => $image]],
    ]]],
    'image_url' => $signed,
    'api_key' => 'sk-live',
]);
$parts = $redacted['messages'][0]['content'];
assert_true(!str_contains($parts[0]['text'], '13812345678') && !str_contains($parts[0]['text'], 'a@b.com') && !str_contains($parts[0]['text'], 'supersecret'), 'Phone, e-mail and token in text are still redacted.');
assert_same($signed, $parts[1]['image_url']['url'], 'Signed image URLs pass unchanged.');
assert_same($image, $parts[2]['image_url']['url'], 'Inline base64 images pass unchanged.');
assert_same($signed, $redacted['image_url'], 'Top-level image_url (image-to-video) passes unchanged.');
assert_same('****', $redacted['api_key'], 'Sensitive keys are still masked.');
$notMedia = (new DesensitizationService())->redact(['metadata' => ['url' => 'https://x.example.com/?token=abc']]);
assert_true(!str_contains((string) $notMedia['metadata']['url'], 'token=abc'), 'Non-media URLs are still desensitized.');

// Multipart re-encoding keeps repeated files and array fields.
$tmpA = tempnam(sys_get_temp_dir(), 'mp');
$tmpB = tempnam(sys_get_temp_dir(), 'mp');
file_put_contents($tmpA, "\x89PNG\r\n\x1a\nA");
file_put_contents($tmpB, "\x89PNG\r\n\x1a\nB");
$upload = MultipartUpload::fromGlobals(['model' => 'gpt-image-1', 'timestamp_granularities' => ['word', 'segment']], [
    'image' => ['name' => ['a.png', 'b.png'], 'type' => ['image/png', 'image/png'], 'tmp_name' => [$tmpA, $tmpB], 'error' => [0, 0], 'size' => [9, 9]],
], MultipartUpload::allowedMimePrefixes('image_generation'));
assert_true($upload['ok'], 'image[] with two images is accepted for image edits.');
$encoded = MultipartUpload::encode($upload['payload'], 'b');
assert_same(2, substr_count($encoded['body'], 'name="image[]"; filename='), 'Both images are forwarded as image[].');
assert_same(1, substr_count($encoded['body'], "name=\"timestamp_granularities[]\"\r\n\r\nword"), 'Array form fields are forwarded as name[].');
$audioOnly = MultipartUpload::fromGlobals([], ['file' => ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmpA, 'error' => 0, 'size' => 9]]);
assert_same('FILE_TYPE_UNSUPPORTED', $audioOnly['code'] ?? null, 'Speech endpoints still refuse images.');
@unlink($tmpA);
@unlink($tmpB);
