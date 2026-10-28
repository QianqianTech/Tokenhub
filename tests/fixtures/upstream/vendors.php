<?php

declare(strict_types=1);

// Fake vendor upstream for tests/Unit/ModelSupportFlowTest.php (php -S).
// Emulates the request/response shapes of the vendor media APIs TokenHub
// adapts (Kling, MiniMax, DashScope, Vidu, BFL, fal, OpenRouter, xAI,
// ElevenLabs) plus a Kimi Code chat endpoint. Every create call is stored so
// the test can assert what TokenHub actually sent.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $query);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];
$base = 'http://127.0.0.1:' . ($_SERVER['SERVER_PORT'] ?? '0');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders() ?: [], CASE_LOWER) : [];
$log = sys_get_temp_dir() . '/tokenhub-fake-vendors-' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$mp4 = "\x00\x00\x00\x18ftypmp42fake-video";
$mp3 = "ID3\x03\x00fake-mp3";

$record = static function (string $key) use ($log, $body, $headers, $raw): void {
    $all = is_file($log) ? (json_decode((string) file_get_contents($log), true) ?: []) : [];
    $all[$key] = ['body' => $body, 'headers' => $headers, 'raw_size' => strlen($raw)];
    file_put_contents($log, json_encode($all));
};
$json = static function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

switch (true) {
    // ---------------------------------------------------------- Kling 4.0 omni video
    case $path === '/omni-video/kling-4.0' && $method === 'POST':
        $record('kling');
        $json(['code' => 0, 'message' => 'SUCCEED', 'data' => ['id' => 'kl-task-1', 'status' => 'submitted']]);
        return;
    case $path === '/tasks' && ($query['task_ids'] ?? '') === 'kl-task-1':
        $record('kling_poll');
        $json(['code' => 0, 'data' => [['id' => 'kl-task-1', 'status' => 'succeeded', 'outputs' => [['type' => 'video', 'url' => $base . '/files/kling.mp4', 'duration' => 5]]]]]);
        return;

    // ---------------------------------------------------------- MiniMax
    case $path === '/v2/video_generation':
        $record('minimax_video');
        $json(['task_id' => 'mm-task-1', 'base_resp' => ['status_code' => 0]]);
        return;
    case $path === '/v2/query/video_generation/mm-task-1':
        $json(['task' => ['status' => 'failed', 'error' => ['code' => 1026, 'message' => 'content moderation']]]);
        return;
    case $path === '/v1/image_generation':
        $record('minimax_image');
        $json(['id' => 'img-1', 'data' => ['image_urls' => [$base . '/files/mm.png']], 'base_resp' => ['status_code' => 0, 'status_msg' => 'success']]);
        return;
    case $path === '/v1/t2a_v2':
        $record('minimax_tts');
        $json(['data' => ['audio' => bin2hex($mp3), 'status' => 2], 'extra_info' => ['usage_characters' => 7], 'base_resp' => ['status_code' => 0]]);
        return;

    // ---------------------------------------------------------- DashScope (Wan video, CosyVoice, Qwen ASR)
    case $path === '/api/v1/services/aigc/video-generation/video-synthesis':
        $record('wan');
        if (($headers['x-dashscope-async'] ?? '') !== 'enable') {
            $json(['code' => 'InvalidParameter', 'message' => 'async header required'], 400);
            return;
        }
        $json(['output' => ['task_id' => 'wan-task-1', 'task_status' => 'PENDING'], 'request_id' => 'r1']);
        return;
    case $path === '/api/v1/tasks/wan-task-1':
        $json(['output' => ['task_id' => 'wan-task-1', 'task_status' => 'SUCCEEDED', 'video_url' => $base . '/files/wan.mp4'], 'usage' => ['output_video_duration' => 5]]);
        return;
    case $path === '/api/v1/services/audio/tts/SpeechSynthesizer':
        $record('cosyvoice');
        $json(['output' => ['audio' => ['url' => $base . '/files/cosy.mp3']], 'usage' => ['characters' => 5]]);
        return;
    case $path === '/compatible-mode/v1/chat/completions':
        $record('qwen_asr');
        $json(['choices' => [['message' => ['role' => 'assistant', 'content' => '你好世界']]], 'usage' => ['seconds' => 3, 'prompt_tokens' => 20, 'completion_tokens' => 4]]);
        return;

    // ---------------------------------------------------------- Vidu
    case $path === '/ent/v2/img2video':
        $record('vidu');
        if (!str_starts_with((string) ($headers['authorization'] ?? ''), 'Token ')) {
            $json(['err_code' => 'Unauthorized'], 401);
            return;
        }
        $json(['task_id' => 'vidu-1', 'state' => 'created']);
        return;
    case $path === '/ent/v2/tasks/vidu-1/creations':
        // Reports success but returns no creation: must not settle as success.
        $json(['id' => 'vidu-1', 'state' => 'success', 'creations' => []]);
        return;

    // ---------------------------------------------------------- BFL
    case $path === '/v1/flux-3-image':
        $record('bfl');
        if (($headers['x-key'] ?? '') === '') {
            $json(['detail' => 'missing key'], 401);
            return;
        }
        $json(['id' => 'bfl-1', 'polling_url' => $base . '/v1/get_result?id=bfl-1', 'cost' => 4]);
        return;
    case $path === '/v1/get_result':
        $json(['id' => 'bfl-1', 'status' => 'Ready', 'result' => ['sample' => $base . '/files/bfl.jpg'], 'cost' => 4]);
        return;

    // ---------------------------------------------------------- fal queue
    case $path === '/fal-ai/kling-video/v2/master/text-to-video':
        $record('fal');
        $json(['request_id' => 'fal-1', 'status_url' => $base . '/fal-ai/kling-video/requests/fal-1/status']);
        return;
    case $path === '/fal-ai/kling-video/requests/fal-1/status':
        $json(['status' => 'COMPLETED']);
        return;
    case $path === '/fal-ai/kling-video/requests/fal-1':
        $json(['video' => ['url' => $base . '/files/fal.mp4', 'content_type' => 'video/mp4']]);
        return;

    // ---------------------------------------------------------- OpenRouter video (authenticated download)
    case $path === '/api/v1/videos' && $method === 'POST':
        $record('openrouter_video');
        $json(['id' => 'or-vid-1', 'polling_url' => $base . '/api/v1/videos/or-vid-1', 'status' => 'pending'], 202);
        return;
    case $path === '/api/v1/videos/or-vid-1':
        $json(['id' => 'or-vid-1', 'status' => 'completed', 'unsigned_urls' => [$base . '/api/v1/videos/or-vid-1/content?index=0'], 'usage' => ['cost' => 0.42]]);
        return;
    case $path === '/api/v1/videos/or-vid-1/content':
        if (($headers['authorization'] ?? '') !== 'Bearer sk-or-test') {
            http_response_code(401);
            echo 'unauthorized';
            return;
        }
        header('Content-Type: video/mp4');
        echo $mp4;
        return;

    // ---------------------------------------------------------- xAI video
    case $path === '/v1/videos/generations' && $method === 'POST':
        $record('xai');
        $json(['request_id' => 'xai-1']);
        return;
    case $path === '/v1/videos/xai-1':
        $json(['status' => 'done', 'video' => ['url' => $base . '/files/xai.mp4', 'duration' => 6], 'usage' => ['cost_in_usd_ticks' => 1200000000]]);
        return;

    // ---------------------------------------------------------- ElevenLabs TTS (binary)
    case str_starts_with($path, '/v1/text-to-speech/'):
        $record('elevenlabs');
        if (($headers['xi-api-key'] ?? '') === '') {
            $json(['detail' => ['message' => 'missing key']], 401);
            return;
        }
        header('Content-Type: audio/mpeg');
        echo $mp3;
        return;

    // ---------------------------------------------------------- Kimi Code chat (tool call)
    case $path === '/coding/v1/chat/completions':
        $record('kimi');
        $json([
            'id' => 'chatcmpl-kimi',
            'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
                'role' => 'assistant', 'content' => '', 'reasoning_content' => 'need to read',
                'tool_calls' => [['id' => 'call_k1', 'type' => 'function', 'function' => ['name' => 'Read', 'arguments' => '{"path":"a.txt"}']]],
            ]]],
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 9, 'prompt_tokens_details' => ['cached_tokens' => 30]],
        ]);
        return;

    // ---------------------------------------------------------- Gemini native
    case str_starts_with($path, '/v1beta/models/gemini-3.8-flash:generateContent'):
        $record('gemini');
        if (($headers['x-goog-api-key'] ?? '') !== 'g-key') {
            $json(['error' => ['code' => 403, 'message' => 'bad key']], 403);
            return;
        }
        $json([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'thinking', 'thought' => true], ['text' => 'A cat.']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1300, 'candidatesTokenCount' => 4, 'thoughtsTokenCount' => 20, 'totalTokenCount' => 1324, 'cachedContentTokenCount' => 100,
                'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 42], ['modality' => 'IMAGE', 'tokenCount' => 1258]]],
            'modelVersion' => 'gemini-3.8-flash', 'responseId' => 'resp-g1',
        ]);
        return;
    case str_starts_with($path, '/v1beta/models/gemini-3.8-flash:streamGenerateContent'):
        $record('gemini_stream');
        header('Content-Type: text/event-stream');
        echo 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 1]]) . "\r\n\r\n";
        @ob_flush();
        flush();
        echo 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'lo']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 2, 'totalTokenCount' => 12]]) . "\r\n\r\n";
        return;

    // ---------------------------------------------------------- media files (short-lived in real life)
    case str_starts_with($path, '/files/'):
        $expired = sys_get_temp_dir() . '/tokenhub-fake-vendors-expired-' . ($_SERVER['SERVER_PORT'] ?? '0');
        if (is_file($expired)) {
            http_response_code(403);
            echo 'expired';
            return;
        }
        $type = match (true) {
            str_ends_with($path, '.mp4') => 'video/mp4',
            str_ends_with($path, '.mp3') => 'audio/mpeg',
            str_ends_with($path, '.jpg') => 'image/jpeg',
            default => 'image/png',
        };
        header('Content-Type: ' . $type);
        echo match ($type) {
            'video/mp4' => $mp4,
            'audio/mpeg' => $mp3,
            default => $png,
        };
        return;
    default:
        $json(['error' => ['message' => 'not found: ' . $method . ' ' . $path]], 404);
}
