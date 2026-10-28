<?php

declare(strict_types=1);

// Fake multimodal upstream for tests/Unit/MultimodalGatewayFlowTest.php,
// started with `php -S`. Implements the OpenAI-compatible text, vision,
// embedding, image, audio and Anthropic endpoints plus the Volcengine Ark
// asynchronous video task API. Every JSON answer echoes what it received so
// the test can check what the gateway actually forwarded.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];
$base = 'http://127.0.0.1:' . ($_SERVER['SERVER_PORT'] ?? '0');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

$json = static function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

// Media parts the gateway forwarded, reduced to fingerprints.
$mediaFingerprints = static function (mixed $value) use (&$mediaFingerprints): array {
    if (!is_array($value)) {
        return [];
    }
    $found = [];
    foreach ($value as $key => $child) {
        if (is_string($child) && ($key === 'url' || $key === 'image_url' || $key === 'data' || $key === 'file_data')) {
            $found[] = hash('sha256', $child);
        }
        $found = [...$found, ...$mediaFingerprints($child)];
    }

    return $found;
};

switch (true) {
    case $path === '/mm/v1/chat/completions':
        $json([
            'id' => 'chatcmpl-mm',
            'object' => 'chat.completion',
            'model' => (string) ($body['model'] ?? ''),
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 5, 'total_tokens' => 125],
            'media' => $mediaFingerprints($body['messages'] ?? []),
            'received_model' => $body['model'] ?? null,
        ]);
        return;
    case $path === '/mm/v1/responses':
        $json([
            'id' => 'resp_mm',
            'object' => 'response',
            'status' => 'completed',
            'model' => (string) ($body['model'] ?? ''),
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
            'usage' => ['input_tokens' => 90, 'output_tokens' => 3, 'total_tokens' => 93],
            'media' => $mediaFingerprints($body['input'] ?? []),
        ]);
        return;
    case $path === '/mm/v1/embeddings':
        $json([
            'object' => 'list',
            'model' => (string) ($body['model'] ?? ''),
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
            'usage' => ['prompt_tokens' => 4, 'total_tokens' => 4],
        ]);
        return;
    case $path === '/anth/v1/messages':
        $json([
            'id' => 'msg_mm',
            'type' => 'message',
            'role' => 'assistant',
            'model' => (string) ($body['model'] ?? ''),
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 150, 'output_tokens' => 2],
            'media' => $mediaFingerprints($body['messages'] ?? []),
        ]);
        return;
    case $path === '/mm/v1/images/generations':
        $n = max(1, (int) ($body['n'] ?? 1));
        $data = [];
        for ($i = 0; $i < $n; $i++) {
            $data[] = ($body['response_format'] ?? 'url') === 'b64_json'
                ? ['b64_json' => base64_encode($png), 'revised_prompt' => 'a dot']
                : ['url' => $base . '/files/generated-' . $i . '.png?X-Tos-Security-Token=abc&Expires=1730123456789', 'revised_prompt' => 'a dot'];
        }
        $json([
            'created' => 1730000000,
            'data' => $data,
            'usage' => ['input_tokens' => 12, 'output_tokens' => 0, 'total_tokens' => 12],
            'received_model' => $body['model'] ?? null,
        ]);
        return;
    case $path === '/mm/v1/images/edits':
        $json([
            'created' => 1730000000,
            'data' => [['b64_json' => base64_encode($png)]],
            'received_fields' => $_POST,
            'received_files' => array_map(static fn (array $file): array => ['name' => $file['name'], 'type' => $file['type'], 'size' => $file['size']], $_FILES),
        ]);
        return;
    case $path === '/mm/v1/audio/speech':
        http_response_code(200);
        header('Content-Type: audio/mpeg');
        echo "ID3\x03\x00fake-mp3-bytes:" . (string) ($body['input'] ?? '');
        return;
    case $path === '/mm/v1/audio/transcriptions':
    case $path === '/mm/v1/audio/translations':
        $format = (string) ($_POST['response_format'] ?? 'json');
        $text = $path === '/mm/v1/audio/translations' ? 'hello world' : '你好世界';
        if (in_array($format, ['text', 'srt', 'vtt'], true)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $format === 'text' ? $text . "\n" : "1\n00:00:00,000 --> 00:00:04,000\n{$text}\n";
            return;
        }
        $response = [
            'text' => $text,
            'usage' => ['type' => 'duration', 'seconds' => 4],
            'received_fields' => $_POST,
            'received_files' => array_map(static fn (array $file): array => ['name' => $file['name'], 'size' => $file['size']], $_FILES),
        ];
        if ($format === 'verbose_json') {
            unset($response['usage']);
            $response['duration'] = 7.5;
            $response['language'] = 'chinese';
        }
        $json($response);
        return;
    case $path === '/ark/api/v3/contents/generations/tasks' && $method === 'POST':
        $json(['id' => 'cgt-fake-1', 'received' => $body]);
        return;
    case $path === '/ark/api/v3/contents/generations/tasks/cgt-fake-1':
        $json([
            'id' => 'cgt-fake-1',
            'model' => 'doubao-seedance-1-0-pro',
            'status' => 'succeeded',
            'content' => ['video_url' => $base . '/files/video.mp4?X-Tos-Signature=sig'],
            'usage' => ['completion_tokens' => 108900, 'total_tokens' => 108900],
        ]);
        return;
    case str_starts_with($path, '/files/'):
        header('Content-Type: ' . (str_ends_with($path, '.mp4') ? 'video/mp4' : 'image/png'));
        echo str_ends_with($path, '.mp4') ? "\x00\x00\x00\x18ftypmp42fake-video" : $png;
        return;
    default:
        $json(['error' => ['message' => 'not found: ' . $method . ' ' . $path]], 404);
}
