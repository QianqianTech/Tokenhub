<?php

declare(strict_types=1);

// Fake vendors for tests/Unit/VideoTaskLifecycleTest.php (php -S): Volcengine
// Ark Seedance under /ark/api/v3, MiniMax H3 (v2) and Alibaba DashScope Wan at
// the root, plus an OpenAI-compatible chat endpoint for modality routing.
// Requests are logged to a JSON file the test reads; GET polls advance a
// per-task counter (first poll "running", then the final state).
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$port = (string) ($_SERVER['SERVER_PORT'] ?? '0');
$base = 'http://127.0.0.1:' . $port;
$stateFile = sys_get_temp_dir() . '/tokenhub-video-lifecycle-' . $port . '.json';
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$state['log'][] = ['method' => $method, 'path' => $path, 'body' => $body, 'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null];
$poll = static function (string $id) use (&$state): int {
    $state['polls'][$id] = ($state['polls'][$id] ?? 0) + 1;
    return $state['polls'][$id];
};
$json = static function (array $data, int $status = 200) use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state));
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
};

if (preg_match('#^/ark/api/v3/contents/generations/tasks(?:/([^/]+))?$#', $path, $m) === 1) {
    $id = $m[1] ?? null;
    if ($method === 'POST') {
        $text = (string) ($body['content'][0]['text'] ?? '');
        $json(['id' => str_contains($text, 'fail') ? 'cgt-fail' : (str_contains($text, 'queued') ? 'cgt-queued' : 'cgt-' . substr(md5($text), 0, 8))]);
        return;
    }
    if ($method === 'DELETE') {
        $id === 'cgt-queued' ? $json([]) : $json(['error' => ['code' => 'InvalidAction.TaskRunning', 'message' => 'running tasks cannot be cancelled']], 400);
        return;
    }
    if ($id === 'cgt-fail') {
        $json(['id' => $id, 'status' => 'failed', 'error' => ['code' => 'OutputVideoSensitiveContentDetected', 'message' => 'sensitive output']]);
        return;
    }
    if ($id === 'cgt-queued' || $poll((string) $id) < 2) {
        $json(['id' => $id, 'status' => $id === 'cgt-queued' ? 'queued' : 'running']);
        return;
    }
    $json(['id' => $id, 'status' => 'succeeded', 'content' => ['video_url' => $base . '/files/ark.mp4'], 'duration' => 6, 'resolution' => '1080p', 'usage' => ['completion_tokens' => 108900, 'total_tokens' => 108900]]);
    return;
}
if ($path === '/v2/video_generation' && $method === 'POST') {
    $json(['task_id' => 'mm-h3-queued']);
    return;
}
if ($path === '/v2/video_generation/mm-h3-queued' && $method === 'DELETE') {
    $json(['task_id' => 'mm-h3-queued', 'action' => 'cancelled', 'status' => 'cancelled']);
    return;
}
if ($path === '/v2/query/video_generation/mm-h3-queued') {
    $json(['task' => ['id' => 'mm-h3-queued', 'model' => 'MiniMax-H3', 'status' => 'queued']]);
    return;
}
if ($path === '/api/v1/services/aigc/video-generation/video-synthesis' && $method === 'POST') {
    $json(['output' => ['task_id' => 'wan-fail', 'task_status' => 'PENDING'], 'request_id' => 'r1']);
    return;
}
if ($path === '/api/v1/tasks/wan-fail') {
    $json(['output' => ['task_id' => 'wan-fail', 'task_status' => 'FAILED', 'code' => 'DataInspectionFailed', 'message' => 'input image is inappropriate']]);
    return;
}
if ($path === '/chat/v1/chat/completions') {
    $types = [];
    foreach ((array) ($body['messages'][0]['content'] ?? []) as $part) {
        if (is_array($part)) {
            $types[] = (string) ($part['type'] ?? '');
        }
    }
    $json(['id' => 'c1', 'object' => 'chat.completion', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'seen'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 3, 'total_tokens' => 903], 'received_types' => $types]);
    return;
}
if (str_starts_with($path, '/files/')) {
    file_put_contents($stateFile, json_encode($state));
    header('Content-Type: video/mp4');
    echo "\x00\x00\x00\x18ftypmp42fake-" . basename($path);
    return;
}
$json(['error' => ['message' => 'not found: ' . $method . ' ' . $path]], 404);
