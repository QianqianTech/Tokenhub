<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/BusinessSqliteFixture.php';

/*
 * 会话记录 (conversation logs): turn extraction for every protocol, session
 * grouping, per-API-key switch and retention, encryption at rest,
 * super-admin-only access, settings validation and the once-a-day cleanup at
 * the configured local time.
 */

use TokenHub\Services\ConversationLogService;
use TokenHub\Services\Gateway\ConversationExtractor;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
$_ENV['APP_KEY'] = $_ENV['APP_KEY'] ?? 'unit-test-key-for-conversation-logs-0123456789';
$logDir = sys_get_temp_dir() . '/tokenhub-conversation-logs-' . getmypid();
$_ENV['CONVERSATION_LOG_PATH'] = $logDir;
putenv('CONVERSATION_LOG_PATH=' . $logDir);
register_shutdown_function(static function () use ($logDir): void {
    if (is_dir($logDir)) {
        exec('rm -rf ' . escapeshellarg($logDir));
    }
});
putenv('APP_KEY=' . $_ENV['APP_KEY']);

// ---------------------------------------------------------------- extraction
$chat = ConversationExtractor::extract('openai', 'text_generation', [
    'model' => 'gpt',
    'messages' => [
        ['role' => 'system', 'content' => '你是助手'],
        ['role' => 'user', 'content' => '第一问'],
        ['role' => 'assistant', 'content' => '第一答'],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => '第二问'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . str_repeat('A', 4000)]]]],
    ],
], ['choices' => [['message' => ['role' => 'assistant', 'content' => '第二答', 'reasoning_content' => '想一想']]], 'id' => 'chatcmpl-1']);
assert_same('openai_chat', $chat['format'], 'Chat Completions payloads are recognised.');
assert_same('你是助手', $chat['system'], 'System messages become the system prompt.');
assert_same(1, count($chat['messages']), 'Only the new turn after the last assistant message is kept.');
assert_same('第二问', $chat['messages'][0]['parts'][0]['text'], 'The new user question is kept.');
assert_same('image', $chat['messages'][0]['parts'][1]['type'], 'Images are kept as placeholders.');
assert_true(!str_contains(json_encode($chat), 'AAAA'), 'Base64 image bytes are never stored.');
assert_same(2, $chat['prior_count'], 'Earlier messages (not the system prompt) are counted, not stored.');
assert_same('第二答', $chat['reply']['text'], 'The assistant reply is extracted.');
assert_same('想一想', $chat['reply']['reasoning'], 'Reasoning content is kept separately.');
assert_same('第二问', $chat['title'], 'The title is the newest user text.');

$first = ConversationExtractor::extract('openai', 'text_generation', ['messages' => [['role' => 'system', 'content' => '你是助手'], ['role' => 'user', 'content' => '第一问']]], ['choices' => [['message' => ['content' => '第一答']]]]);
assert_same($first['session_seed'], $chat['session_seed'], 'Turns of the same conversation share the derived session seed.');
assert_same('derived', $chat['session_source'], 'Without a client id the session is derived.');

$anthropic = ConversationExtractor::extract('anthropic', 'text_generation', [
    'system' => [['type' => 'text', 'text' => 'Claude Code system']],
    'metadata' => ['user_id' => 'user_abc_account_1234_session_0f8fad5b-d9cb-469f-a165-70867728950e'],
    'messages' => [
        ['role' => 'user', 'content' => '列出文件'],
        ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'Bash', 'input' => ['command' => 'ls']]]],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'a.txt']]],
    ],
], ['type' => 'message', 'content' => [['type' => 'thinking', 'thinking' => '...'], ['type' => 'text', 'text' => '有一个文件 a.txt']]]);
assert_same('tool', $anthropic['messages'][0]['role'], 'A user message made only of tool results is shown as tool output.');
assert_same('tool_result', $anthropic['messages'][0]['parts'][0]['type'], 'Tool results are kept.');
assert_same('claude-code:0f8fad5b-d9cb-469f-a165-70867728950e', $anthropic['session_hint'], 'Claude Code session id is read from metadata.user_id.');
assert_same('有一个文件 a.txt', $anthropic['reply']['text'], 'Anthropic text blocks form the reply.');
assert_same('列出文件', $anthropic['title'], 'Without user text in the tail the first user message is the title.');

$responses = ConversationExtractor::extract('openai', 'text_generation', [
    'instructions' => '按要求回答',
    'previous_response_id' => 'resp_1',
    'prompt_cache_key' => 'codex-conv-9',
    'input' => [
        ['type' => 'function_call', 'name' => 'shell', 'arguments' => '{"cmd":"pwd"}', 'call_id' => 'c1'],
        ['type' => 'function_call_output', 'call_id' => 'c1', 'output' => '/home'],
    ],
], ['id' => 'resp_2', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '在 /home']]], ['type' => 'function_call', 'name' => 'shell', 'arguments' => '{}']]]);
assert_same('openai_responses', $responses['format'], 'Responses payloads are recognised.');
assert_same('tool', $responses['messages'][0]['role'], 'function_call_output after the function call is the new turn.');
assert_same('resp_1', $responses['previous_response_id'], 'previous_response_id links Responses turns.');
assert_same('resp_2', $responses['response_id'], 'The response id is kept for the next turn.');
assert_same('codex-conv-9', $responses['session_hint'], 'Codex prompt_cache_key names the conversation.');
assert_same('shell', $responses['reply']['tool_calls'][0]['name'], 'Function calls in the reply are kept.');

$gemini = ConversationExtractor::extract('gemini', 'text_generation', [
    'systemInstruction' => ['parts' => [['text' => '简洁']]],
    'contents' => [['role' => 'user', 'parts' => [['text' => '你好']]]],
], ['candidates' => [['content' => ['parts' => [['text' => '你好！']]]]]], ['x-session-id' => 'web-42']);
assert_same('简洁', $gemini['system'], 'Gemini systemInstruction is the system prompt.');
assert_same('你好！', $gemini['reply']['text'], 'Gemini candidates form the reply.');
assert_same('web-42', $gemini['session_hint'], 'Session headers name the conversation.');

$stream = ConversationExtractor::extract('openai', 'text_generation', ['messages' => [['role' => 'user', 'content' => '流式']]], ['stream' => true, 'text' => '流式回复', 'tool_calls' => [['id' => 'x', 'name' => 'f', 'arguments' => '{"a":1}']]]);
assert_same('流式回复', $stream['reply']['text'], 'Stream summaries are used as the reply.');
assert_same('f', $stream['reply']['tool_calls'][0]['name'], 'Streamed tool calls are kept.');

assert_same(null, ConversationExtractor::extract('openai', 'embedding', ['input' => 'x'], []), 'Embeddings are not conversations.');
assert_same('image_generation', ConversationExtractor::extract('openai', 'image_generation', ['prompt' => 'cat'], [])['format'], 'Image generation is logged as a generation turn.');
$long = ConversationExtractor::extract('openai', 'text_generation', ['messages' => [['role' => 'user', 'content' => str_repeat('长', 30000)]]], []);
assert_true(mb_strlen($long['messages'][0]['parts'][0]['text']) < 20100, 'Long texts are cut.');

// ---------------------------------------------------------------- storage
final class ConversationLogTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // AuditService reads SHOW COLUMNS; answer it from SQLite.
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        }

        return parent::prepare(BusinessSqlitePdo::translate($query), $options);
    }
}
$pdo = new ConversationLogTestPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
foreach ([
    'CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT, updated_at TEXT)',
    'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)',
    'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, path TEXT)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, username TEXT, status TEXT)',
    'CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, department_id INT, name TEXT, key_prefix TEXT, key_suffix TEXT, key_hash TEXT, status TEXT DEFAULT "active", conversation_log_enabled INT NOT NULL DEFAULT 0, conversation_log_retention_days INT NULL)',
    "INSERT INTO departments (id, name, path) VALUES (1, '研发部', '/1/')",
    "INSERT INTO users (id, department_id, username, status) VALUES (7, 1, 'alice', 'active'), (8, 1, 'bob', 'active')",
    "INSERT INTO api_keys (id, user_id, department_id, name, key_prefix, key_suffix, key_hash) VALUES (3, 7, 1, 'alice-key', 'tkh_aaaa', 'a1b2', 'h3'), (4, 8, 1, 'bob-key', 'tkh_bbbb', 'c3d4', 'h4'), (5, 7, 1, 'alice-quiet', 'tkh_cccc', 'e5f6', 'h5')",
] as $sql) {
    $pdo->exec($sql);
}
business_use_sqlite($pdo);

$expect = static function (callable $callback, string $code, int $status, string $message): void {
    try {
        $callback();
    } catch (HttpAbortException $exception) {
        assert_same($code, $exception->errorCode, $message . ' (code)');
        assert_same($status, $exception->status, $message . ' (status)');
        return;
    }
    throw new RuntimeException($message . ' (no abort raised)');
};
// The conversation log has its own database; the application database never gets the table.
$logPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
\TokenHub\Services\ConversationLogDb::useConnection($logPdo);
$count = static fn (string $sql): int => (int) (str_contains($sql, 'conversation_logs') ? $logPdo : $pdo)->query($sql)->fetchColumn();
$mainHasLogTable = static fn (): bool => (bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'conversation_logs'")->fetchColumn();
$service = new ConversationLogService();
$record = static fn (int $usageId, array $conversation, string $created = '2026-11-04 01:00:00'): array => [
    'request_id' => 'req_' . $usageId, 'user_id' => 7, 'department_id' => 1, 'api_key_id' => 3, 'model_id' => 5,
    'model_name' => 'gpt-test', 'protocol' => 'openai', 'status' => 'success', 'input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15, 'cost_amount' => 0.002, 'cost_currency' => 'USD',
    'latency_ms' => 900, 'created_at' => $created, 'conversation' => $conversation,
];

// Off by default on every key: nothing is stored.
App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin']);
assert_same(false, ConversationLogService::enabledForKey(['id' => 3]), 'A key without the flag does not log.');
assert_same(false, $service->keySettings(3)['enabled'], 'Conversation logging is off by default for a key.');
$service->writeForRecord(['id' => 1], $record(1, $first));
assert_same(0, $count('SELECT COUNT(*) FROM conversation_logs'), 'Nothing is stored while the key has logging off.');

// Only super administrators.
App::context()->setAdminUser(['id' => 2, 'role' => 'admin', 'admin_scope' => 'global']);
$expect(static fn () => $service->saveKeySettings(3, ['enabled' => true]), 'ADMIN_PERMISSION_DENIED', 403, 'A global admin cannot switch logging on for a key.');
$expect(static fn () => $service->saveSettings(['retention_days' => 7]), 'ADMIN_PERMISSION_DENIED', 403, 'A global admin cannot change the cleanup settings.');
$expect(static fn () => $service->keys([]), 'ADMIN_PERMISSION_DENIED', 403, 'A global admin cannot list conversation logs.');
$expect(static fn () => $service->session(str_repeat('a', 64), []), 'ADMIN_PERMISSION_DENIED', 403, 'A global admin cannot read a session.');
App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin']);

// Validation of the global cleanup settings and of a key's retention.
$expect(static fn () => $service->saveSettings(['retention_days' => 0]), 'CONVERSATION_LOG_INVALID_RETENTION', 422, 'Retention must be at least one day.');
$expect(static fn () => $service->saveSettings(['cleanup_time' => '25:00']), 'CONVERSATION_LOG_INVALID_TIME', 422, 'Cleanup time must be a valid HH:MM.');
$expect(static fn () => $service->saveSettings(['timezone' => 'Mars/Base']), 'CONVERSATION_LOG_INVALID_TIMEZONE', 422, 'Timezone must be valid.');
$expect(static fn () => $service->saveKeySettings(3, ['enabled' => true, 'retention_days' => 9999]), 'CONVERSATION_LOG_INVALID_RETENTION', 422, 'A key retention above 3650 days is refused.');
$expect(static fn () => $service->saveKeySettings(99, ['enabled' => true]), 'ADMIN_NOT_FOUND', 404, 'Unknown keys are 404.');
$expect(static fn () => $service->saveSettings(['max_records_per_key' => -1]), 'CONVERSATION_LOG_INVALID_CAP', 422, 'The per-key cap cannot be negative.');

// The feature itself is off by default: keys cannot be switched on until it is enabled.
assert_same(false, $service->settings()['enabled'], 'The conversation log feature is off by default.');
$expect(static fn () => $service->saveKeySettings(3, ['enabled' => true]), 'CONVERSATION_LOG_FEATURE_DISABLED', 409, 'A key cannot be switched on while the feature is off.');
$pdo->exec('UPDATE api_keys SET conversation_log_enabled = 1 WHERE id = 3');
$service->writeForRecord(['id' => 1], $record(1, $first));
assert_same(0, $count('SELECT COUNT(*) FROM conversation_logs'), 'Even a key with the flag stores nothing while the feature is off.');
ConversationLogService::forgetEnabled();
assert_same(false, ConversationLogService::enabledForKey($pdo->query('SELECT * FROM api_keys WHERE id = 3')->fetch()), 'The gateway does not record while the feature is off.');
$pdo->exec('UPDATE api_keys SET conversation_log_enabled = 0 WHERE id = 3');

$saved = $service->saveSettings(['enabled' => true, 'retention_days' => 7, 'cleanup_time' => '3:05', 'timezone' => 'Asia/Shanghai']);
assert_same(true, $saved['enabled'], 'The feature can be enabled.');
assert_same('03:05', $saved['cleanup_time'], 'Cleanup time is normalised to HH:MM.');
assert_same(7, $saved['retention_days'], 'The default retention is saved.');
assert_same(1, $count("SELECT COUNT(*) FROM audit_logs WHERE action = 'conversation_log.settings_update'"), 'Setting changes are audited.');

// Switch logging on for alice-key (default retention) and bob-key (own retention).
$alice = $service->saveKeySettings(3, ['enabled' => true]);
assert_same(true, $alice['enabled'], 'Logging can be switched on for one key.');
assert_same(7, $alice['retention_days'], 'Without its own value the key uses the default retention.');
assert_same(false, $alice['custom_retention'], 'The key has no retention of its own.');
$bob = $service->saveKeySettings(4, ['enabled' => '1', 'retention_days' => 60]);
assert_same(60, $bob['retention_days'], 'A key can keep its conversations longer.');
assert_same(2, $count("SELECT COUNT(*) FROM audit_logs WHERE action = 'conversation_log.key_update'"), 'Key switches are audited.');
assert_true(ConversationLogService::enabledForKey($pdo->query('SELECT * FROM api_keys WHERE id = 3')->fetch()), 'The gateway sees the flag on the key row.');
assert_same(false, ConversationLogService::enabledForKey($pdo->query('SELECT * FROM api_keys WHERE id = 5')->fetch()), 'Other keys stay off.');

// Two turns of one conversation (derived session), system prompt stored once.
$service->writeForRecord(['id' => 1], $record(1, $first));
$service->writeForRecord(['id' => 2], $record(2, $chat));
$service->writeForRecord(['id' => 2], $record(2, $chat));
assert_same(2, $count('SELECT COUNT(*) FROM conversation_logs'), 'A retried usage record is stored once.');
assert_same(false, $mainHasLogTable(), 'Nothing about conversations is written to the application database.');
assert_same(1, $count('SELECT COUNT(DISTINCT session_key) FROM conversation_logs'), 'Turns with the same system prompt and first message form one session.');
assert_same(1, $count('SELECT COUNT(*) FROM conversation_logs WHERE system_stored = 1'), 'The system prompt is stored once per session.');

// Content lives in the conversation log directory, one file per key per day
// (Asia/Shanghai: 2026-11-04 01:00 UTC is 2026-11-04 09:00 local), encrypted.
assert_same(2, $count("SELECT COUNT(*) FROM conversation_logs WHERE storage = 'file' AND content_file = '2026/11/04/key-3' AND encrypted_messages IS NULL AND encrypted_reply IS NULL"), 'The table keeps only the index; content goes to the day file.');
$dayFile = $logDir . '/2026/11/04/key-3.jsonl';
assert_true(is_file($dayFile), 'The day file exists in the conversation log directory.');
$rawFile = (string) file_get_contents($dayFile);
assert_same(2, substr_count($rawFile, "\n"), 'One line per call.');
assert_true(!str_contains($rawFile, '第二问') && !str_contains($rawFile, '第二答') && !str_contains($rawFile, '你是助手'), 'File content is encrypted at rest.');

// Responses chain: previous_response_id joins the session of resp_1.
$seedTurn = ConversationExtractor::extract('openai', 'text_generation', ['input' => '开始'], ['id' => 'resp_1', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '好']]]]]);
$service->writeForRecord(['id' => 3], $record(3, $seedTurn));
$service->writeForRecord(['id' => 4], $record(4, $responses));
$keys = $logPdo->query('SELECT session_key, session_source FROM conversation_logs WHERE id IN (3, 4) ORDER BY id')->fetchAll();
assert_same($keys[0]['session_key'], $keys[1]['session_key'], 'previous_response_id continues the earlier session.');
assert_same('response_chain', $keys[1]['session_source'], 'The chained turn records how it was grouped.');

// bob-key with a client session id; alice-quiet (logging off) is ignored.
$bobRecord = $record(5, $gemini);
$bobRecord['user_id'] = 8;
$bobRecord['api_key_id'] = 4;
$service->writeForRecord(['id' => 5], $bobRecord);
$quietRecord = $record(6, $first);
$quietRecord['api_key_id'] = 5;
$service->writeForRecord(['id' => 6], $quietRecord);
assert_same(0, $count('SELECT COUNT(*) FROM conversation_logs WHERE api_key_id = 5'), 'A key with logging off stores nothing even if a job arrives.');

$list = $service->keys([]);
assert_same(2, $list['total'], 'Keys with logging on or with conversations are listed.');
assert_same('bob-key', $list['items'][0]['name'], 'Most recent conversation first.');
assert_same('tkh_bbbb****c3d4', $list['items'][0]['display'], 'The masked key is shown.');
assert_same(2, $list['items'][1]['sessions'], 'Session count per key.');
assert_same('alice', $list['items'][1]['username'], 'The key owner is shown.');
assert_same(1, $service->keys(['keyword' => 'ali'])['total'], 'Keys can be searched by name or owner.');
assert_same(1, $service->keys(['keyword' => 'tkh_bb'])['total'], 'Keys can be searched by prefix.');

$sessions = $service->sessions(3, []);
assert_same(2, $sessions['total'], 'alice-key has two sessions.');
assert_same('alice-key', $sessions['api_key']['name'], 'The session list names its key.');
$chatSession = array_values(array_filter($sessions['items'], static fn (array $row): bool => $row['first_id'] === 1))[0];
assert_same('第一问', $chatSession['title'], 'The session title is the first user message, decrypted.');

assert_same(30, $chatSession['total_tokens'], 'Each session sums the tokens of its calls.');

// One row per call with its question, answer and usage, plus the key totals.
$calls = $service->calls(3, []);
assert_same(4, $calls['total'], 'alice-key made four logged calls.');
assert_same(4, $calls['summary']['calls'], 'The summary counts the calls.');
assert_same(40, $calls['summary']['input_tokens'], 'The summary sums input tokens.');
assert_same(20, $calls['summary']['output_tokens'], 'The summary sums output tokens.');
assert_same(60, $calls['summary']['total_tokens'], 'The summary sums total tokens.');
assert_true(abs((float) $calls['summary']['cost_amount'] - 0.008) < 1e-9, 'The summary sums the cost.');
$latest = $calls['items'][0];
assert_same(4, $latest['id'], 'Newest call first.');
assert_same('tool', $latest['messages'][0]['role'], 'Each call carries its question.');
assert_same('在 /home', $latest['reply']['text'], 'Each call carries its answer.');
assert_same(15, $latest['total_tokens'], 'Each call carries its token usage.');
assert_same(10, $latest['input_tokens'], 'Input tokens per call.');
assert_true(abs((float) $latest['cost_amount'] - 0.002) < 1e-9, 'Each call carries its cost.');
assert_same(1, $service->calls(3, ['limit' => 1, 'offset' => 3])['items'][0]['id'], 'Calls are paged.');
assert_same(0, $service->calls(3, ['from' => '2026-12-01 00:00:00'])['summary']['calls'], 'The time range filters calls and totals.');

$detail = $service->session($chatSession['session_key'], []);
assert_same(30, $detail['total_tokens'], 'The session detail sums the tokens.');
assert_same(15, $detail['turns'][1]['total_tokens'], 'Each turn shows its tokens.');
assert_same(2, count($detail['turns']), 'All turns are returned in order.');
assert_same('你是助手', $detail['turns'][0]['system'], 'The system prompt is decrypted.');
assert_same('第一答', $detail['turns'][0]['reply']['text'], 'Replies are decrypted.');
assert_same('第二问', $detail['turns'][1]['messages'][0]['parts'][0]['text'], 'Messages are decrypted.');
assert_same(0, $detail['turns'][1]['prior_count'], 'Only the first turn reports unrecorded history.');
assert_same('alice-key', $detail['api_key']['name'], 'The session names its key.');
assert_same('alice', $detail['user']['username'], 'The session names the key owner.');
assert_true($count("SELECT COUNT(*) FROM audit_logs WHERE action = 'conversation_log.view'") === 1, 'Reading a session is audited.');
$expect(static fn () => $service->session('not-a-key', []), 'CONVERSATION_NOT_FOUND', 404, 'Malformed session keys are rejected.');
$expect(static fn () => $service->session(str_repeat('b', 64), []), 'CONVERSATION_NOT_FOUND', 404, 'Unknown sessions are 404.');

// ---------------------------------------------------------------- cleanup
// alice-key rows 1 and 2 are older than the default 7 days; bob-key's row is
// as old but bob-key keeps 60 days.
$logPdo->exec("UPDATE conversation_logs SET created_at = '2026-10-01 00:00:00' WHERE id IN (1, 2, 5)");
// 2026-11-04 02:59 Asia/Shanghai = 2026-11-03 18:59 UTC: before 03:05 local, not due.
$before = strtotime('2026-11-03 18:59:00 UTC');
assert_same(true, $service->cleanup(false, $before)['skipped'] ?? false, 'Cleanup waits for the configured local time.');
assert_same(5, $count('SELECT COUNT(*) FROM conversation_logs'), 'Nothing deleted before the cleanup time.');
$after = strtotime('2026-11-03 19:10:00 UTC');
$result = $service->cleanup(false, $after);
assert_same(2, $result['deleted'], 'Rows older than the retention period are deleted after the cleanup time.');
assert_same(1, $count('SELECT COUNT(*) FROM conversation_logs WHERE api_key_id = 4'), 'A key with a longer retention keeps its rows.');
assert_same('2026-11-04', $result['last_date'], 'The local date of the run is remembered.');
assert_same(true, $service->cleanup(false, $after + 600)['skipped'] ?? false, 'Cleanup runs once per day.');
assert_same('2026-11-05 03:05:00', (new DateTimeImmutable('@' . strtotime($service->nextCleanupAt($service->settings(), $after) . ' UTC')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'), 'The next run is tomorrow at the cleanup time.');
$logPdo->exec("UPDATE conversation_logs SET created_at = '2026-10-01 00:00:00' WHERE id = 3");
assert_same(1, $service->cleanupNow()['deleted'], 'Manual cleanup runs immediately.');

// ---------------------------------------------------------------- manual cleanup by condition
// Three older rows for bob-key, then delete by key and date.
$remaining = (int) $count('SELECT COUNT(*) FROM conversation_logs');
$extra = static function (int $usageId, int $apiKeyId, string $created) use ($service, $record, $first): void {
    $row = $record($usageId, $first, $created);
    $row['api_key_id'] = $apiKeyId;
    $service->writeForRecord(['id' => $usageId], $row);
};
$extra(20, 4, '2026-10-20 08:00:00');
$extra(21, 4, '2026-10-25 08:00:00');
$extra(22, 4, '2026-11-02 08:00:00');
$total = $remaining + 3;
assert_same($total, $count('SELECT COUNT(*) FROM conversation_logs'), 'Extra rows for bob-key were stored.');

$preview = $service->cleanupBy(['mode' => 'before', 'before' => '2026-10-26', 'api_key_id' => 4], true);
assert_same(3, $preview['matched'], 'Preview counts bob-key rows before 2026-10-26 (local time) without deleting.');
assert_same($total, $count('SELECT COUNT(*) FROM conversation_logs'), 'Preview deletes nothing.');
$deleted = $service->cleanupBy(['mode' => 'before', 'before' => '2026-10-26', 'api_key_id' => 4], false);
assert_same(3, $deleted['deleted'], 'Rows of one key before a date can be deleted.');
assert_same(1, $count('SELECT COUNT(*) FROM conversation_logs WHERE api_key_id = 4'), 'Newer rows of the key are kept.');
$expect(static fn () => $service->cleanupBy(['mode' => 'before'], false), 'CONVERSATION_LOG_INVALID_BEFORE', 422, 'A date is required.');
$expect(static fn () => $service->cleanupBy(['mode' => 'all'], false), 'CONVERSATION_LOG_PURGE_UNCONFIRMED', 422, 'Deleting everything needs confirmation.');
$expect(static fn () => $service->cleanupBy(['mode' => 'nope'], true), 'CONVERSATION_LOG_INVALID_MODE', 422, 'Unknown modes are refused.');

// Per-key cap: keep only the newest N rows of each key.
$extra(30, 3, '2026-11-03 01:00:00');
$extra(31, 3, '2026-11-03 02:00:00');
$extra(32, 3, '2026-11-03 03:00:00');
$service->saveSettings(['max_records_per_key' => 2]);
$capPreview = $service->cleanupBy(['mode' => 'expired', 'api_key_id' => 3], true);
assert_true($capPreview['matched'] >= 1, 'Rows beyond the per-key cap count as expired.');
$service->cleanupBy(['mode' => 'expired', 'api_key_id' => 3], false);
assert_same(2, $count('SELECT COUNT(*) FROM conversation_logs WHERE api_key_id = 3'), 'Only the newest rows within the cap remain.');
assert_same(32, (int) $logPdo->query('SELECT MAX(usage_record_id) FROM conversation_logs WHERE api_key_id = 3')->fetchColumn(), 'The newest row is kept.');
$service->saveSettings(['max_records_per_key' => 0]);

// Rows of deleted keys go at the next cleanup when purge_deleted_keys is on.
$pdo->exec("UPDATE api_keys SET status = 'deleted' WHERE id = 4");
assert_same(1, $service->cleanupBy(['mode' => 'expired', 'api_key_id' => 4], true)['matched'], 'A deleted key\'s rows are expired.');
$service->saveSettings(['purge_deleted_keys' => false]);
assert_same(0, $service->cleanupBy(['mode' => 'expired', 'api_key_id' => 4], true)['matched'], 'They are kept when purge_deleted_keys is off.');
$service->saveSettings(['purge_deleted_keys' => true]);
$pdo->exec("UPDATE api_keys SET status = 'active' WHERE id = 4");

// Automatic cleanup and daily compression can be switched off.
$service->saveSettings(['auto_cleanup' => false, 'compress_daily' => false]);
assert_same('auto_cleanup_off', $service->cleanup(false, $after + 86400)['reason'] ?? null, 'No scheduled run while auto cleanup and compression are off.');
assert_same(null, $service->settingsView()['next_cleanup_at'], 'No next run is shown while auto cleanup is off.');

// ---------------------------------------------------------------- daily compression of the directory
// Compression alone still runs once a day; it deletes nothing.
$service->saveSettings(['compress_daily' => true]);
$extra(45, 3, '2026-11-05 02:00:00');
$packedFile = $logDir . '/2026/11/05/key-3.jsonl.gz.enc';
$activeFile = $logDir . '/2026/11/05/key-3.jsonl';
assert_true(is_file($activeFile) && !is_file($packedFile), 'The day\'s file is not compressed yet.');
$before = (int) $count('SELECT COUNT(*) FROM conversation_logs');
$run = $service->cleanup(false, strtotime('2026-11-06 19:30:00 UTC'));
assert_same(0, $run['deleted'], 'Compression alone deletes nothing.');
assert_true($run['compressed_files'] >= 2, 'Files of earlier days are compressed at the cleanup time.');
assert_same($before, (int) $count('SELECT COUNT(*) FROM conversation_logs'), 'All rows are kept.');
assert_true(is_file($packedFile) && !is_file($activeFile), 'The day file was replaced by its compressed form.');
$packedRaw = (string) file_get_contents($packedFile);
assert_true(!str_contains($packedRaw, '第一答') && @gzdecode($packedRaw) === false, 'The compressed file is encrypted as a whole.');
$packedId = (int) $logPdo->query('SELECT id FROM conversation_logs WHERE usage_record_id = 45')->fetchColumn();
$fromPacked = array_values(array_filter($service->calls(3, ['limit' => 100])['items'], static fn (array $row): bool => $row['id'] === $packedId));
assert_same('第一答', $fromPacked[0]['reply']['text'] ?? null, 'Calls stored in a compressed file are read back.');
assert_same('compress', $service->settingsView()['history'][0]['trigger'], 'Compression is listed in the cleanup history.');
$service->saveSettings(['auto_cleanup' => true]);

// A late write for a compressed day goes to a new file next to it; reads merge both; compressing folds it in.
$extra(50, 3, '2026-11-05 03:00:00');
assert_true(is_file($activeFile) && is_file($packedFile), 'A late call for a compressed day starts a new active file.');
$lateId = (int) $logPdo->query('SELECT id FROM conversation_logs WHERE usage_record_id = 50')->fetchColumn();
$late = array_values(array_filter($service->calls(3, ['limit' => 100])['items'], static fn (array $row): bool => $row['id'] === $lateId));
assert_same('第一问', $late[0]['messages'][0]['parts'][0]['text'] ?? null, 'The late call is readable before compression.');
$store = new \TokenHub\Services\ConversationLogStore($logDir);
$store->compress('2026/11/05/key-3');
assert_true(is_file($packedFile) && !is_file($activeFile), 'Compressing folds the late file into the day archive.');
$late = array_values(array_filter($service->calls(3, ['limit' => 100])['items'], static fn (array $row): bool => $row['id'] === $lateId));
assert_same('第一问', $late[0]['messages'][0]['parts'][0]['text'] ?? null, 'The late call is readable after compression.');

// Deleting a row rewrites its day file without it; deleting the last row removes the file.
$service->deleteCall($lateId);
assert_same([], $store->read('2026/11/05/key-3', [$lateId]), 'A deleted call is removed from the compressed file.');
assert_true(is_file($packedFile), 'The file stays while other calls of the day remain.');

// Files no row points at are removed by the daily run.
$store->append('2026/01/01/key-99', 999999, ['messages' => [], 'reply' => ['text' => 'orphan']]);
assert_true(isset($store->files()['2026/01/01/key-99']), 'An orphan file exists.');
$swept = $service->cleanupNow();
assert_true($swept['orphan_files'] >= 1, 'Orphan files are swept.');
assert_true(!isset($store->files()['2026/01/01/key-99']) && !is_dir($logDir . '/2026/01'), 'The orphan file and its empty folders are gone.');

// Storage figures for the settings page.
$view = $service->settingsView();
assert_same('sqlite', $view['database']['driver'], 'The settings page names the log database.');
assert_same($logDir, $view['storage']['path'], 'The settings page shows the directory.');
assert_true($view['storage']['writable'], 'The directory is writable.');
assert_true($view['storage']['packed_bytes'] > 0 && $view['storage']['files'] >= 1, 'Disk usage is reported.');

// Directory not writable: the content is kept in the database instead of lost.
$blocker = $logDir . '-blocker';
file_put_contents($blocker, 'x');
putenv('CONVERSATION_LOG_PATH=' . $blocker . '/logs');
$_ENV['CONVERSATION_LOG_PATH'] = $blocker . '/logs';
$fallback = new ConversationLogService();
$fallbackRow = $record(60, $first, '2026-11-04 05:00:00');
$fallback->writeForRecord(['id' => 60], $fallbackRow);
assert_same('db', (string) $logPdo->query('SELECT storage FROM conversation_logs WHERE usage_record_id = 60')->fetchColumn(), 'Falls back to the log database (not the application database) when the directory cannot be written.');
assert_same(false, $mainHasLogTable(), 'The fallback stays out of the application database.');
$fallbackId = (int) $logPdo->query('SELECT id FROM conversation_logs WHERE usage_record_id = 60')->fetchColumn();
$found = array_values(array_filter($fallback->calls(3, ['limit' => 100])['items'], static fn (array $row): bool => $row['id'] === $fallbackId));
assert_same('第一答', $found[0]['reply']['text'] ?? null, 'Fallback rows are readable.');
putenv('CONVERSATION_LOG_PATH=' . $logDir);
$_ENV['CONVERSATION_LOG_PATH'] = $logDir;
@unlink($blocker);

// Single conversation and single call.
$callId = (int) $logPdo->query('SELECT MAX(id) FROM conversation_logs WHERE api_key_id = 3')->fetchColumn();
assert_same(1, $service->deleteCall($callId)['deleted'], 'One question/answer can be deleted.');
$expect(static fn () => $service->deleteCall($callId), 'CONVERSATION_NOT_FOUND', 404, 'A deleted call is gone.');
$sessionKey = (string) $logPdo->query('SELECT session_key FROM conversation_logs WHERE api_key_id = 4 LIMIT 1')->fetchColumn();
assert_same(1, $service->deleteSession($sessionKey)['deleted'], 'A whole conversation can be deleted.');

$history = $service->settingsView()['history'];
assert_same('session', $history[0]['trigger'], 'Cleanups are listed newest first.');
assert_same('bob-key', $history[0]['api_key_name'], 'History names the key.');
assert_true(count(array_filter($history, static fn (array $row): bool => $row['trigger'] === 'manual' && ($row['mode'] ?? '') === 'before' && $row['deleted'] === 3)) === 1, 'Manual cleanups by date are in the history.');
assert_true(count(array_filter($history, static fn (array $row): bool => $row['trigger'] === 'schedule')) === 1, 'Scheduled cleanups are in the history.');

// Turning the feature off can delete everything that was recorded.
$service->saveSettings(['enabled' => false, 'purge' => true]);
assert_same(0, $count('SELECT COUNT(*) FROM conversation_logs'), 'Disabling with purge empties the table.');
assert_same([], (new \TokenHub\Services\ConversationLogStore($logDir))->files(), 'Disabling with purge empties the directory.');
assert_same('disable', $service->settingsView()['history'][0]['trigger'], 'The purge on disable is recorded.');
$service->writeForRecord(['id' => 40], $record(40, $first));
assert_same(0, $count('SELECT COUNT(*) FROM conversation_logs'), 'Nothing is recorded after the feature is off, even for keys still flagged.');

// Without an injected connection the log database is a SQLite file in the directory.
\TokenHub\Services\ConversationLogDb::reset();
$fileDb = new \TokenHub\Services\ConversationLogDb();
assert_same('sqlite:' . $logDir . DIRECTORY_SEPARATOR . 'index.sqlite', $fileDb->dsn(), 'Default: index.sqlite in the conversation log directory.');
assert_same(0, (int) ($fileDb->first('SELECT COUNT(*) AS n FROM conversation_logs')['n'] ?? -1), 'The schema is created on first use.');
assert_true(is_file($logDir . '/index.sqlite'), 'The SQLite file exists.');
assert_same('wal', strtolower((string) $fileDb->pdo()->query('PRAGMA journal_mode')->fetchColumn()), 'WAL mode for concurrent workers.');
putenv('CONVERSATION_LOG_DB_DSN=mysql:host=db;dbname=tokenhub_conversation;password=secret');
assert_true(!str_contains((string) $fileDb->describe()['location'], 'secret'), 'A DSN password is never shown.');
putenv('CONVERSATION_LOG_DB_DSN');
\TokenHub\Services\ConversationLogDb::reset();

echo "OK\n";
