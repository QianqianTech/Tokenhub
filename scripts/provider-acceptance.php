<?php

declare(strict_types=1);

/**
 * Model-level acceptance runner (TH-017 / TH-019 / TH-020).
 *
 * Calls a running TokenHub through its public API with a real API key and
 * records, per model and scenario, pass / fail / untested with the request id
 * so every result can be traced in usage records and diagnostics.
 *
 *   TOKENHUB_URL=https://tokenhub.example \
 *   TOKENHUB_API_KEY=tkh_... \
 *   php scripts/provider-acceptance.php --models=gpt-4o,openai/gpt-4o-mini,deepseek-flash \
 *       [--scenarios=chat,stream,tools,json_schema,vision,embedding,models] [--out=acceptance.json]
 *
 * A scenario that is not applicable or not requested is reported "untested";
 * nothing is ever reported as passed without an actual call.
 */

$base = rtrim((string) (getenv('TOKENHUB_URL') ?: ''), '/');
$key = (string) (getenv('TOKENHUB_API_KEY') ?: '');
$options = getopt('', ['models:', 'scenarios::', 'out::', 'timeout::']);
if ($base === '' || $key === '' || empty($options['models'])) {
    fwrite(STDERR, "Set TOKENHUB_URL, TOKENHUB_API_KEY and --models=...\n");
    exit(2);
}
$models = array_values(array_filter(array_map('trim', explode(',', (string) $options['models']))));
$scenarios = array_values(array_filter(array_map('trim', explode(',', (string) ($options['scenarios'] ?? 'models,chat,stream,tools,json_schema')))));
$timeout = (float) ($options['timeout'] ?? 60);

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
$http = static function (string $method, string $path, ?array $json = null) use ($base, $key, $timeout): array {
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Authorization: Bearer {$key}\r\nContent-Type: application/json\r\nAccept: application/json, text/event-stream",
        'content' => $json === null ? '' : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($base . $path, false, $context);
    $headers = [];
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m) === 1) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : ''];
};

$results = [];
$record = static function (string $model, string $scenario, string $verdict, array $detail = []) use (&$results): void {
    $results[] = ['model' => $model, 'scenario' => $scenario, 'verdict' => $verdict, ...$detail];
    fwrite(STDOUT, sprintf("%-8s %-28s %-12s %s\n", strtoupper($verdict), $model, $scenario, $detail['request_id'] ?? ($detail['reason'] ?? '')));
};

foreach ($models as $model) {
    foreach ($scenarios as $scenario) {
        $messages = [['role' => 'user', 'content' => 'Reply with the single word: pong']];
        switch ($scenario) {
            case 'models':
                $response = $http('GET', '/v1/models/' . rawurlencode($model));
                $record($model, $scenario, $response['status'] === 200 ? 'pass' : 'fail', ['http_status' => $response['status'], 'request_id' => $response['headers']['x-request-id'] ?? null]);
                break;
            case 'chat':
                $response = $http('POST', '/v1/chat/completions', ['model' => $model, 'messages' => $messages, 'max_tokens' => 16]);
                $json = json_decode($response['body'], true);
                $ok = $response['status'] === 200 && is_string($json['choices'][0]['message']['content'] ?? null) && isset($json['usage']);
                $record($model, $scenario, $ok ? 'pass' : 'fail', ['http_status' => $response['status'], 'request_id' => $response['headers']['x-request-id'] ?? null, 'usage' => $json['usage'] ?? null]);
                break;
            case 'stream':
                $response = $http('POST', '/v1/chat/completions', ['model' => $model, 'messages' => $messages, 'max_tokens' => 16, 'stream' => true]);
                $ok = $response['status'] === 200 && str_contains($response['body'], '[DONE]') && !str_contains($response['body'], 'event: error');
                $record($model, $scenario, $ok ? 'pass' : 'fail', ['http_status' => $response['status'], 'request_id' => $response['headers']['x-request-id'] ?? null, 'has_usage_frame' => str_contains($response['body'], '"usage"')]);
                break;
            case 'tools':
                $tools = [['type' => 'function', 'function' => ['name' => 'get_time', 'description' => 'Current time', 'parameters' => ['type' => 'object', 'properties' => ['tz' => ['type' => 'string']], 'required' => ['tz']]]]];
                $first = $http('POST', '/v1/chat/completions', ['model' => $model, 'messages' => [['role' => 'user', 'content' => 'What time is it in UTC? Use the tool.']], 'tools' => $tools, 'tool_choice' => 'required']);
                $json = json_decode($first['body'], true);
                $call = $json['choices'][0]['message']['tool_calls'][0] ?? null;
                if (!is_array($call)) {
                    $record($model, $scenario, 'fail', ['http_status' => $first['status'], 'request_id' => $first['headers']['x-request-id'] ?? null, 'reason' => 'no tool call']);
                    break;
                }
                $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
                $second = $http('POST', '/v1/chat/completions', ['model' => $model, 'tools' => $tools, 'messages' => [
                    ['role' => 'user', 'content' => 'What time is it in UTC? Use the tool.'],
                    ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]],
                    ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => '12:00'],
                ]]);
                $ok = is_array($arguments) && $second['status'] === 200;
                $record($model, $scenario, $ok ? 'pass' : 'fail', ['http_status' => $second['status'], 'request_id' => $second['headers']['x-request-id'] ?? null, 'arguments_valid_json' => is_array($arguments)]);
                break;
            case 'json_schema':
                $response = $http('POST', '/v1/chat/completions', ['model' => $model, 'messages' => [['role' => 'user', 'content' => 'Give a city and its country.']], 'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'city', 'strict' => true, 'schema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string'], 'country' => ['type' => 'string']], 'required' => ['city', 'country'], 'additionalProperties' => false]]]]);
                $json = json_decode($response['body'], true);
                $content = json_decode((string) ($json['choices'][0]['message']['content'] ?? ''), true);
                $ok = $response['status'] === 200 && is_array($content) && isset($content['city'], $content['country']);
                $record($model, $scenario, $ok ? 'pass' : 'fail', ['http_status' => $response['status'], 'request_id' => $response['headers']['x-request-id'] ?? null]);
                break;
            case 'embedding':
                $response = $http('POST', '/v1/embeddings', ['model' => $model, 'input' => 'pong']);
                $json = json_decode($response['body'], true);
                $record($model, $scenario, $response['status'] === 200 && is_array($json['data'][0]['embedding'] ?? null) ? 'pass' : 'fail', ['http_status' => $response['status'], 'request_id' => $response['headers']['x-request-id'] ?? null]);
                break;
            default:
                $record($model, $scenario, 'untested', ['reason' => 'unknown scenario']);
        }
    }
}

$report = [
    'tokenhub_url' => $base,
    'executed_at' => gmdate('c'),
    'models' => $models,
    'scenarios' => $scenarios,
    'results' => $results,
    'summary' => array_count_values(array_column($results, 'verdict')),
];
if (!empty($options['out'])) {
    file_put_contents((string) $options['out'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
exit(in_array('fail', array_column($results, 'verdict'), true) ? 1 : 0);
