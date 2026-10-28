<?php

declare(strict_types=1);

use TokenHub\Services\ApiKeyService;
use TokenHub\Support\App;
use TokenHub\Support\Database;

require __DIR__ . '/../vendor/autoload.php';

App::bootstrap(dirname(__DIR__));

$options = getopt('', ['call-models']) ?: [];
$baseUrl = rtrim((string) ($_SERVER['BASE_URL'] ?? getenv('BASE_URL') ?: 'http://caddy'), '/');
$keyName = (string) (getenv('TOKENHUB_DEEPSEEK_KEY_NAME') ?: 'external-deepseek-smoke');
$username = (string) (getenv('TOKENHUB_DEEPSEEK_USERNAME') ?: 'external-deepseek-smoke-user');
$departmentId = (int) (getenv('TOKENHUB_DEEPSEEK_DEPARTMENT_ID') ?: 1);
$callModels = isset($options['call-models']) || (string) getenv('TOKENHUB_DEEPSEEK_CALL_MODELS') === '1';
$db = App::db();

$userId = ensure_smoke_user($db, $username, $departmentId);
$models = [
    'flash' => find_deepseek_model($db, 'flash', (string) getenv('TOKENHUB_DEEPSEEK_FLASH_MODEL')),
    'pro' => find_deepseek_model($db, 'pro', (string) getenv('TOKENHUB_DEEPSEEK_PRO_MODEL')),
];

$db->execute('UPDATE api_keys SET status = "deleted" WHERE name = ?', [$keyName]);
$key = (new ApiKeyService())->create([
    'user_id' => $userId,
    'department_id' => $departmentId,
    'name' => $keyName,
    'model_ids' => array_map(static fn (array $model): int => (int) $model['id'], $models),
], $userId);
$token = (string) $key['key'];

$me = request_json($baseUrl, 'GET', '/v1/me', $token);
assert_response($me, 200, 'me');
if ((int) ($me['json']['data']['api_key']['id'] ?? 0) !== (int) $key['id']) {
    throw new RuntimeException('Created API Key was not returned by /v1/me.');
}

$authorized = request_json($baseUrl, 'GET', '/v1/models', $token);
assert_response($authorized, 200, 'models');
$authorizedNames = array_map(
    static fn (array $model): string => (string) ($model['name'] ?? ''),
    $authorized['json']['data']['models'] ?? []
);
foreach ($models as $variant => $model) {
    if (!in_array((string) $model['name'], $authorizedNames, true)) {
        throw new RuntimeException("Authorized model list did not include DeepSeek {$variant} model.");
    }
}

$modelCalls = [];
if ($callModels) {
    foreach ($models as $variant => $model) {
        $chat = request_json($baseUrl, 'POST', '/v1/chat/completions', $token, [
            'model' => (string) $model['name'],
            'messages' => [
                ['role' => 'user', 'content' => 'Reply with OK for TokenHub smoke verification.'],
            ],
            'max_tokens' => 8,
            'temperature' => 0,
        ]);
        assert_http_response($chat, 200, "chat {$variant}");
        if (!isset($chat['json']['choices']) && !isset($chat['json']['usage'])) {
            throw new RuntimeException("chat {$variant} did not return an OpenAI-compatible model response.");
        }
        $modelCalls[$variant] = [
            'status' => $chat['status'],
            'model' => (string) $model['name'],
            'request_id' => $chat['json']['id'] ?? null,
            'usage' => $chat['json']['usage'] ?? null,
        ];
    }
}

echo json_encode([
    'ok' => true,
    'base_url' => $baseUrl,
    'api_key_id' => (int) $key['id'],
    'api_key_display' => $key['display'],
    'user_id' => $userId,
    'models' => [
        'flash' => public_model($models['flash']),
        'pro' => public_model($models['pro']),
    ],
    'model_call_mode' => $callModels ? 'enabled' : 'disabled',
    'model_calls' => $modelCalls,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

function ensure_smoke_user(Database $db, string $username, int $departmentId): int
{
    $existing = $db->first('SELECT id FROM users WHERE username = ?', [$username]);
    if ($existing) {
        $db->execute('UPDATE users SET department_id = ?, role = "user", status = "active" WHERE id = ?', [$departmentId, (int) $existing['id']]);
        return (int) $existing['id'];
    }

    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")',
        [$departmentId, $username, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]
    );

    return (int) $db->pdo()->lastInsertId();
}

/**
 * @return array<string, mixed>
 */
function find_deepseek_model(Database $db, string $variant, string $overrideName = ''): array
{
    if ($overrideName !== '') {
        $model = $db->first(
            'SELECT m.*, p.name AS provider_name, p.base_url, p.protocol
             FROM models m JOIN providers p ON p.id = m.provider_id
             WHERE m.status = "active" AND p.status = "active" AND (m.name = ? OR m.external_name = ?)
             LIMIT 1',
            [$overrideName, $overrideName]
        );
        if ($model) {
            return $model;
        }
        throw new RuntimeException("Configured DeepSeek {$variant} model was not found: {$overrideName}");
    }

    $haystack = 'LOWER(CONCAT_WS(" ", p.name, p.base_url, m.name, m.external_name))';
    $model = $db->first(
        "SELECT m.*, p.name AS provider_name, p.base_url, p.protocol
         FROM models m JOIN providers p ON p.id = m.provider_id
         WHERE m.status = \"active\" AND p.status = \"active\"
           AND {$haystack} LIKE ?
           AND {$haystack} LIKE ?
           AND {$haystack} LIKE ?
         ORDER BY m.route_priority ASC, m.id ASC
         LIMIT 1",
        ['%deepseek%', '%v4%', '%' . strtolower($variant) . '%']
    );
    if ($model) {
        return $model;
    }

    throw new RuntimeException("Active DeepSeek v4 {$variant} model was not found.");
}

/**
 * @return array{id:int,name:string,external_name:string,provider:string,protocol:string}
 */
function public_model(array $model): array
{
    return [
        'id' => (int) $model['id'],
        'name' => (string) $model['name'],
        'external_name' => (string) $model['external_name'],
        'provider' => (string) $model['provider_name'],
        'protocol' => (string) $model['protocol'],
    ];
}

/**
 * @return array{status: int, body: string, json: array<string, mixed>|null}
 */
function request_json(string $baseUrl, string $method, string $path, string $token, ?array $payload = null): array
{
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    $body = '';
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ]);
    $responseBody = file_get_contents($baseUrl . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }
    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;

    return [
        'status' => $status,
        'body' => is_string($responseBody) ? $responseBody : '',
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

/**
 * @param array{status: int, body: string, json: array<string, mixed>|null} $response
 */
function assert_response(array $response, int $status, string $label): void
{
    assert_http_response($response, $status, $label);
    if (($response['json']['ok'] ?? null) !== true) {
        throw new RuntimeException($label . ' failed: HTTP ' . $response['status'] . ' ' . $response['body']);
    }
}

/**
 * @param array{status: int, body: string, json: array<string, mixed>|null} $response
 */
function assert_http_response(array $response, int $status, string $label): void
{
    if ($response['status'] !== $status) {
        throw new RuntimeException($label . ' failed: HTTP ' . $response['status'] . ' ' . $response['body']);
    }
}
