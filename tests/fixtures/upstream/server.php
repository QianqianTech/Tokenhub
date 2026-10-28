<?php

declare(strict_types=1);

// Minimal fake upstream for ProviderHttpClient behaviour tests.
// Started by tests/Unit/ProviderHttpClientBehaviourTest.php with `php -S`.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$counterFile = sys_get_temp_dir() . '/tokenhub-fake-upstream-' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$counters = is_file($counterFile) ? (json_decode((string) file_get_contents($counterFile), true) ?: []) : [];
$counters[$path] = ($counters[$path] ?? 0) + 1;
file_put_contents($counterFile, json_encode($counters));

switch ($path) {
    case '/v1/chat/completions/flaky':
    case '/flaky/v1/chat/completions':
        if ($counters[$path] === 1) {
            http_response_code(503);
            echo '{"error":{"message":"overloaded"}}';
            return;
        }
        header('Content-Type: application/json');
        echo '{"id":"ok","choices":[{"message":{"content":"hi"}}],"usage":{"prompt_tokens":3,"completion_tokens":1}}';
        return;
    case '/ok/v1/chat/completions':
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        header('Content-Type: application/json');
        echo json_encode([
            'id' => 'gen-ok-1',
            'model' => (string) ($body['model'] ?? ''),
            'provider' => 'FakeVendor',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'echo:' . count($body['messages'] ?? [])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 4, 'total_tokens' => 15, 'prompt_tokens_details' => ['cached_tokens' => 3]],
            'received' => $body,
        ]);
        return;
    case '/ok/v1/completions':
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        header('Content-Type: application/json');
        echo json_encode(['id' => 'cmpl-1', 'object' => 'text_completion', 'choices' => [['index' => 0, 'text' => 'done', 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 2, 'total_tokens' => 8], 'received' => $body]);
        return;
    case '/ok/v1/rerank':
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        header('Content-Type: application/json');
        echo json_encode(['model' => (string) ($body['model'] ?? ''), 'results' => [['index' => 1, 'relevance_score' => 0.9], ['index' => 0, 'relevance_score' => 0.1]], 'usage' => ['total_tokens' => 42], 'received' => $body]);
        return;
    case '/ok/v1/moderations':
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        header('Content-Type: application/json');
        echo json_encode(['id' => 'modr-1', 'model' => (string) ($body['model'] ?? ''), 'results' => [['flagged' => false, 'categories' => ['violence' => false]]], 'received' => $body]);
        return;
    case '/stream/v1/completions':
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        header('Content-Type: text/event-stream');
        echo "data: {\"choices\":[{\"index\":0,\"text\":\"Hel\"}]}\n\n";
        echo "data: {\"choices\":[{\"index\":0,\"text\":\"lo\",\"finish_reason\":\"stop\"}]}\n\n";
        if (($body['stream_options']['include_usage'] ?? false) === true) {
            echo "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":4,\"completion_tokens\":2,\"total_tokens\":6}}\n\n";
        }
        echo "data: [DONE]\n\n";
        return;
    case '/catalog/v1/models':
        header('Content-Type: application/json');
        echo json_encode(['object' => 'list', 'data' => [
            ['id' => 'gpt-4o-mini'], ['id' => 'text-embedding-3-small'], ['id' => 'gpt-4o-realtime-preview'],
            ['id' => 'whisper-1'], ['id' => 'sora-2'], ['id' => 'suno-v4'], ['id' => 'bge-reranker-v2'],
        ]]);
        return;
    case '/limited/v1/chat/completions':
        http_response_code(429);
        header('Retry-After: 0');
        echo '{"error":{"message":"rate limited"}}';
        return;
    case '/redirect/v1/chat/completions':
        http_response_code(302);
        header('Location: http://169.254.169.254/latest/meta-data');
        return;
    case '/stream/v1/chat/completions':
        header('Content-Type: text/event-stream');
        $events = [
            "data: {\"id\":\"gen-9\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hel\"}}]}\n\n",
            "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"lo\"},\"finish_reason\":\"stop\"}]}\n\n",
            "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":2,\"total_tokens\":7}}\n\n",
            "data: [DONE]\n\n",
        ];
        foreach ($events as $event) {
            // Split every event in two writes to force cross-chunk parsing.
            $half = intdiv(strlen($event), 2);
            echo substr($event, 0, $half);
            @ob_flush();
            flush();
            usleep(20000);
            echo substr($event, $half);
            @ob_flush();
            flush();
        }
        return;
    case '/stall/v1/chat/completions':
        header('Content-Type: text/event-stream');
        echo "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"partial\"}}]}\n\n";
        @ob_flush();
        flush();
        sleep(3);
        return;
    case '/badreq/v1/chat/completions':
        // OpenAI-shaped request rejection the client must see verbatim
        // (minus the URL and the upstream model id).
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => [
            'message' => "This model's maximum context length is 8192 tokens. However, you requested 9000 tokens for model badreq-up (see https://platform.example.com/docs/limits).",
            'type' => 'invalid_request_error',
            'param' => 'messages',
            'code' => 'context_length_exceeded',
        ]]);
        return;
    case '/badreq/v1/messages':
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'prompt is too long: 210000 tokens > 200000 maximum']]);
        return;
    case '/billing/v1/chat/completions':
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['message' => 'Your credit balance is too low to access the API. Please go to Plans & Billing.', 'type' => 'invalid_request_error']]);
        return;
    case '/denied/v1/chat/completions':
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['message' => 'Incorrect API key provided: sk-live-abcdef123456.', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']]);
        return;
    case '/trickle/v1/chat/completions':
        // Never idle long enough for the idle timeout, never finishes: only
        // the maximum duration can end it.
        header('Content-Type: text/event-stream');
        for ($i = 0; $i < 40; $i++) {
            echo ": keep-alive\n\n";
            @ob_flush();
            flush();
            usleep(250000);
        }
        return;
    default:
        http_response_code(404);
        echo '{"error":"not found"}';
}
