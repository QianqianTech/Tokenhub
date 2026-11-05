<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/BusinessSqliteFixture.php';

/*
 * 会话记录 · 腾讯云 COS 存储: request signature (documented vectors), COS
 * settings (secret never returned), connection test, choosing local or cloud
 * storage, uploading today's file every run, archive upload and local removal,
 * reading back from COS, deletions reaching COS, orphan objects, index
 * backups, moving back to local, purge.
 */

use TokenHub\Services\ConversationLogDb;
use TokenHub\Services\ConversationLogService;
use TokenHub\Services\Gateway\ConversationExtractor;
use TokenHub\Services\TencentCosClient;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
$_ENV['APP_KEY'] = $_ENV['APP_KEY'] ?? 'unit-test-key-for-conversation-log-cos-0123456789';
putenv('APP_KEY=' . $_ENV['APP_KEY']);
$logDir = sys_get_temp_dir() . '/tokenhub-conversation-cos-' . getmypid();
$_ENV['CONVERSATION_LOG_PATH'] = $logDir;
putenv('CONVERSATION_LOG_PATH=' . $logDir);
register_shutdown_function(static function () use ($logDir): void {
    if (is_dir($logDir)) {
        exec('rm -rf ' . escapeshellarg($logDir));
    }
});

// ---------------------------------------------------------------- signature (vectors from the COS documentation)
[$put] = TencentCosClient::httpString('PUT', '/exampleobject(腾讯云)', [], [
    'Date' => 'Thu, 16 May 2019 06:45:51 GMT', 'Host' => 'examplebucket-1250000000.cos.ap-beijing.myqcloud.com', 'Content-Type' => 'text/plain',
    'Content-Length' => '13', 'Content-MD5' => 'mQ/fVh815F3k6TAUm8m0eg==', 'x-cos-acl' => 'private', 'x-cos-grant-read' => 'uin="100000000011"',
]);
assert_same('8b2751e77f43a0995d6e9eb9477f4b685cca4172', sha1($put), 'HttpString of the documented upload example.');
[$get, $paramList, $headerList] = TencentCosClient::httpString('GET', '/exampleobject(腾讯云)', ['response-content-type' => 'application/octet-stream', 'response-cache-control' => 'max-age=600'], [
    'Date' => 'Thu, 16 May 2019 06:55:53 GMT', 'Host' => 'examplebucket-1250000000.cos.ap-beijing.myqcloud.com',
]);
assert_same('54ecfe22f59d3514fdc764b87a32d8133ea611e6', sha1($get), 'HttpString of the documented download example.');
assert_same('response-cache-control;response-content-type', $paramList, 'Parameters are sorted.');
assert_same('date;host', $headerList, 'Headers are lower-cased and sorted.');
$auth = (new TencentCosClient('AKIDtest', 'secret', 'b-1250000000', 'ap-guangzhou'))->authorization('GET', '/a', [], ['Host' => 'h'], 1557989753, 1557996953);
$keyTime = '1557989753;1557996953';
$expected = hash_hmac('sha1', "sha1\n{$keyTime}\n" . sha1("get\n/a\n\nhost=h\n") . "\n", hash_hmac('sha1', $keyTime, 'secret'));
assert_same("q-sign-algorithm=sha1&q-ak=AKIDtest&q-sign-time={$keyTime}&q-key-time={$keyTime}&q-header-list=host&q-url-param-list=&q-signature={$expected}", $auth, 'Authorization follows the documented format.');

// ---------------------------------------------------------------- fake COS bucket
final class FakeCos
{
    /** @var array<string, string> */
    public static array $objects = [];
    public static int $puts = 0;
    public static bool $down = false;
    /** @var list<string> */
    public static array $authorizations = [];

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: string}
     */
    public static function handle(string $method, string $url, array $headers, string $body): array
    {
        if (self::$down) {
            throw new RuntimeException('connection refused');
        }
        self::$authorizations[] = $headers['Authorization'] ?? '';
        if (!str_contains($headers['Authorization'] ?? '', 'q-ak=AKIDexample12345678') || !str_contains($headers['Authorization'] ?? '', 'q-header-list=')) {
            return [403, '<Error><Code>AccessDenied</Code><Message>bad signature</Message></Error>'];
        }
        $parts = parse_url($url);
        $key = ltrim(rawurldecode($parts['path'] ?? '/'), '/');
        parse_str($parts['query'] ?? '', $query);
        if ($method === 'GET' && $key === '') {
            $prefix = (string) ($query['prefix'] ?? '');
            $marker = (string) ($query['marker'] ?? '');
            $max = (int) ($query['max-keys'] ?? 1000);
            $keys = array_values(array_filter(array_keys(self::$objects), static fn (string $k): bool => str_starts_with($k, $prefix) && ($marker === '' || strcmp($k, $marker) > 0)));
            sort($keys, SORT_STRING);
            $page = array_slice($keys, 0, $max);
            $xml = '<ListBucketResult><IsTruncated>' . (count($keys) > $max ? 'true' : 'false') . '</IsTruncated>';
            foreach ($page as $k) {
                $xml .= '<Contents><Key>' . htmlspecialchars($k) . '</Key><Size>' . strlen(self::$objects[$k]) . '</Size></Contents>';
            }
            if (count($keys) > $max) {
                $xml .= '<NextMarker>' . htmlspecialchars((string) end($page)) . '</NextMarker>';
            }

            return [200, $xml . '</ListBucketResult>'];
        }

        return match ($method) {
            'PUT' => (function () use ($key, $body, $headers): array {
                if (base64_encode(md5($body, true)) !== ($headers['Content-MD5'] ?? '')) {
                    return [400, '<Error><Code>InvalidDigest</Code></Error>'];
                }
                self::$objects[$key] = $body;
                self::$puts++;
                return [200, ''];
            })(),
            'GET' => isset(self::$objects[$key]) ? [200, self::$objects[$key]] : [404, '<Error><Code>NoSuchKey</Code></Error>'],
            'DELETE' => (function () use ($key): array {
                unset(self::$objects[$key]);
                return [204, ''];
            })(),
            default => [405, ''],
        };
    }

    /** @return list<string> */
    public static function keys(string $prefix = ''): array
    {
        $keys = array_values(array_filter(array_keys(self::$objects), static fn (string $k): bool => str_starts_with($k, $prefix)));
        sort($keys);

        return $keys;
    }
}
TencentCosClient::useTransport([FakeCos::class, 'handle']);

// Paging through a long listing.
$client = new TencentCosClient('AKIDexample12345678', 'k', 'b-1250000000', 'ap-guangzhou');
for ($i = 0; $i < 2005; $i++) {
    FakeCos::$objects[sprintf('page/%05d', $i)] = 'x';
}
assert_same(2005, count($client->list('page/')), 'Listings follow pagination.');
FakeCos::$objects = [];

// ---------------------------------------------------------------- application + log databases
final class CosTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        }

        return parent::prepare(BusinessSqlitePdo::translate($query), $options);
    }
}
$pdo = new CosTestPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
foreach ([
    'CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT, updated_at TEXT)',
    'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)',
    'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, path TEXT)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, username TEXT, status TEXT)',
    'CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, department_id INT, name TEXT, key_prefix TEXT, key_suffix TEXT, key_hash TEXT, status TEXT DEFAULT "active", conversation_log_enabled INT NOT NULL DEFAULT 0, conversation_log_retention_days INT NULL)',
    "INSERT INTO departments (id, name, path) VALUES (1, '研发部', '/1/')",
    "INSERT INTO users (id, department_id, username, status) VALUES (7, 1, 'alice', 'active')",
    "INSERT INTO api_keys (id, user_id, department_id, name, key_prefix, key_suffix, key_hash) VALUES (3, 7, 1, 'alice-key', 'tkh_aaaa', 'a1b2', 'h3')",
] as $sql) {
    $pdo->exec($sql);
}
business_use_sqlite($pdo);
$logPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
ConversationLogDb::useConnection($logPdo);

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
App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin', 'username' => 'root']);
$service = new ConversationLogService();

// ---------------------------------------------------------------- COS settings
$expect(static fn () => $service->saveSettings(['storage_driver' => 'cos']), 'CONVERSATION_LOG_COS_NOT_CONFIGURED', 422, 'Cloud storage needs a COS configuration first.');
$expect(static fn () => $service->saveSettings(['storage_driver' => 'ftp']), 'CONVERSATION_LOG_INVALID_STORAGE', 422, 'Only local or COS storage.');
$expect(static fn () => $service->testCos(), 'CONVERSATION_LOG_COS_NOT_CONFIGURED', 422, 'Nothing to test without a configuration.');
$cosInput = ['secret_id' => 'AKIDexample12345678', 'secret_key' => 'super-secret-key', 'bucket' => 'tokenhub-logs-1250000000', 'region' => 'ap-guangzhou', 'prefix' => 'tokenhub/conversation-logs', 'storage_class' => 'STANDARD_IA', 'keep_local_days' => 0, 'backup_index' => true];
$expect(static fn () => $service->saveCos([...$cosInput, 'bucket' => 'no-appid']), 'CONVERSATION_LOG_COS_INVALID', 422, 'The bucket needs the APPID suffix.');
$expect(static fn () => $service->saveCos([...$cosInput, 'region' => 'Guangzhou']), 'CONVERSATION_LOG_COS_INVALID', 422, 'The region is checked.');
$expect(static fn () => $service->saveCos([...$cosInput, 'prefix' => '../etc']), 'CONVERSATION_LOG_COS_INVALID', 422, 'The prefix is checked.');
$expect(static fn () => $service->saveCos([...$cosInput, 'endpoint' => 'http://10.0.0.1']), 'CONVERSATION_LOG_COS_INVALID', 422, 'A custom endpoint must be https.');
$expect(static fn () => $service->saveCos([...$cosInput, 'storage_class' => 'ARCHIVE']), 'CONVERSATION_LOG_COS_INVALID', 422, 'Archive storage cannot be read back directly.');
$view = $service->saveCos($cosInput);
assert_true($view['configured'], 'COS is configured.');
assert_true($view['secret_key_set'] && !isset($view['secret_key']), 'The secret key is never returned.');
assert_same('AKIDex*********5678', $view['secret_id'], 'The SecretId is masked.');
$stored = (string) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'conversation_log.cos'")->fetchColumn();
assert_true(!str_contains($stored, 'super-secret-key'), 'The secret key is stored encrypted.');
$kept = $service->saveCos([...$cosInput, 'secret_key' => '', 'secret_id' => $view['secret_id']]);
assert_true($kept['configured'], 'Saving with an empty secret key and the masked SecretId keeps both.');
$audit = (string) $pdo->query("SELECT details FROM audit_logs WHERE action = 'conversation_log.cos_update' ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_true(!str_contains($audit, 'super-secret'), 'The audit log does not contain the secret.');

$test = $service->testCos();
assert_true($test['ok'] && $test['listed'], 'The connection test writes, reads, lists and deletes a probe.');
assert_same('tokenhub-logs-1250000000.cos.ap-guangzhou.myqcloud.com', $test['host'], 'Default endpoint from bucket and region.');
assert_same([], FakeCos::keys(), 'The probe object is removed.');
FakeCos::$down = true;
$expect(static fn () => $service->testCos(), 'CONVERSATION_LOG_COS_TEST_FAILED', 502, 'A failing connection is reported.');
FakeCos::$down = false;

// ---------------------------------------------------------------- choose cloud storage
$service->saveSettings(['enabled' => true, 'storage_driver' => 'cos', 'cleanup_time' => '03:00', 'timezone' => 'Asia/Shanghai', 'retention_days' => 30]);
assert_same('cos', $service->settings()['storage_driver'], 'Cloud storage can be chosen once COS works.');
$service->saveKeySettings(3, ['enabled' => true]);
$service = new ConversationLogService();
$first = ConversationExtractor::extract('openai', 'text_generation', ['messages' => [['role' => 'user', 'content' => '你好']]], ['choices' => [['message' => ['content' => '你好！']]]]);
$record = static fn (int $usageId, string $created): array => [
    'request_id' => 'req_' . $usageId, 'user_id' => 7, 'department_id' => 1, 'api_key_id' => 3, 'model_name' => 'gpt', 'status' => 'success',
    'input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15, 'created_at' => $created, 'conversation' => $first,
];
// 2026-11-03 02:00 UTC = 11-03 local; 2026-11-04 02:00 UTC = 11-04 local.
$service->writeForRecord(['id' => 1], $record(1, '2026-11-03 02:00:00'));
$service->writeForRecord(['id' => 2], $record(2, '2026-11-03 03:00:00'));
$service->writeForRecord(['id' => 3], $record(3, '2026-11-04 02:00:00'));
$prefix = 'tokenhub/conversation-logs/';

// A scheduler run before the daily time uploads the files that changed.
$tick = strtotime('2026-11-04 04:00:00 UTC'); // 12:00 local, before nothing is due? cleanup_time 03:00 → due
$service->saveSettings(['cleanup_time' => '23:00']);
$run = $service->cleanup(false, $tick);
assert_same('not_due', $run['reason'] ?? null, 'The daily part waits for its time.');
assert_same([$prefix . '2026/11/03/key-3.jsonl', $prefix . '2026/11/04/key-3.jsonl'], FakeCos::keys($prefix), 'Every scheduler run uploads changed files to COS.');
$putsAfterFirst = FakeCos::$puts;
$service->cleanup(false, $tick + 300);
assert_same($putsAfterFirst, FakeCos::$puts, 'Unchanged files are not uploaded again.');
$service->writeForRecord(['id' => 4], $record(4, '2026-11-04 02:30:00'));
$service->cleanup(false, $tick + 600);
assert_same($putsAfterFirst + 1, FakeCos::$puts, 'A file that grew is uploaded again.');
assert_true(!str_contains(FakeCos::$objects[$prefix . '2026/11/04/key-3.jsonl'], '你好'), 'Objects in COS are encrypted.');

// The daily run compresses earlier days, uploads them and removes them locally (keep 0 days).
$service->saveSettings(['cleanup_time' => '03:00']);
$daily = $service->cleanup(false, strtotime('2026-11-04 20:00:00 UTC')); // 11-05 04:00 local
assert_true($daily['cos_uploaded'] >= 1 && $daily['cos_errors'] === 0, 'The daily run uploads the archives.');
assert_true(isset(FakeCos::$objects[$prefix . '2026/11/03/key-3.jsonl.gz.enc']), 'The 11-03 archive is in COS.');
assert_true(!isset(FakeCos::$objects[$prefix . '2026/11/03/key-3.jsonl']), 'The day snapshot is replaced by the archive.');
assert_true(!is_file($logDir . '/2026/11/03/key-3.jsonl.gz.enc') && !is_file($logDir . '/2026/11/03/key-3.jsonl'), 'Uploaded archives are removed from disk.');
assert_true(count(FakeCos::keys($prefix . '_index/')) === 1, 'The index database is backed up to COS.');

// Reading back from COS.
$service = new ConversationLogService();
$calls = $service->calls(3, ['limit' => 10]);
assert_same(4, $calls['total'], 'All calls are listed.');
foreach ($calls['items'] as $item) {
    assert_same('你好！', $item['reply']['text'] ?? null, 'Content is read back from COS when it is not on disk.');
}

// Deleting a call rewrites the archive in COS; deleting the last one removes it.
$archived = array_values(array_filter($calls['items'], static fn (array $row): bool => str_starts_with($row['created_at'], '2026-11-03')));
$before = FakeCos::$objects[$prefix . '2026/11/03/key-3.jsonl.gz.enc'];
$service->deleteCall($archived[0]['id']);
assert_true(isset(FakeCos::$objects[$prefix . '2026/11/03/key-3.jsonl.gz.enc']) && FakeCos::$objects[$prefix . '2026/11/03/key-3.jsonl.gz.enc'] !== $before, 'The COS archive is rewritten without the deleted call.');
$left = (new ConversationLogService())->calls(3, ['limit' => 10]);
assert_same(3, $left['total'], 'Three calls remain.');
$service->deleteCall($archived[1]['id']);
assert_true(!isset(FakeCos::$objects[$prefix . '2026/11/03/key-3.jsonl.gz.enc']), 'An archive with no calls left is removed from COS.');

// Orphan objects in COS are swept by the daily run.
FakeCos::$objects[$prefix . '2026/01/01/key-99.jsonl.gz.enc'] = 'stray';
$swept = $service->cleanupNow();
assert_true($swept['orphan_files'] >= 1 && !isset(FakeCos::$objects[$prefix . '2026/01/01/key-99.jsonl.gz.enc']), 'Orphan objects are removed from COS.');

// COS down: the scheduler run records the error, nothing is lost.
FakeCos::$down = true;
$service->writeForRecord(['id' => 5], $record(5, '2026-11-04 05:00:00'));
$service->cleanup(false, strtotime('2026-11-05 01:00:00 UTC'));
$state = $service->cosView()['state'];
assert_true(str_contains((string) ($state['last_error'] ?? ''), 'connection refused'), 'Sync errors are shown on the settings page.');
FakeCos::$down = false;
assert_same(3, (new ConversationLogService())->calls(3, ['limit' => 10])['total'], 'Records written while COS was down are kept locally.');

// ---------------------------------------------------------------- media in COS
// Uploaded with the scheduler run, removed from disk with the archives,
// fetched back when an administrator opens them, deleted with their call.
$mediaService = new ConversationLogService();
$capture = $mediaService->mediaCapture(3, '2026-11-04 06:00:00');
$withImage = ConversationExtractor::extract('openai', 'text_generation', ['messages' => [['role' => 'user', 'content' => [
    ['type' => 'text', 'text' => '看图'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('png-bytes-for-cos')]],
]]]], ['choices' => [['message' => ['content' => '好']]]], [], $capture);
$withImage['media'] = $capture->items();
$mediaService->writeForRecord(['id' => 6], [...$record(6, '2026-11-04 06:00:00'), 'conversation' => $withImage]);
$mediaRel = (string) $withImage['media'][0]['file'];
$mediaService->cleanup(false, strtotime('2026-11-05 01:30:00 UTC'));
assert_true(isset(FakeCos::$objects[$prefix . $mediaRel]), 'Media files are uploaded to COS.');
assert_true(!str_contains(FakeCos::$objects[$prefix . $mediaRel], 'png-bytes-for-cos'), 'Media objects in COS are encrypted.');
assert_true(!is_file($logDir . '/' . $mediaRel), 'Uploaded media of earlier days are removed from disk (keep 0 days).');
$putsMedia = FakeCos::$puts;
$mediaService->cleanup(false, strtotime('2026-11-05 01:35:00 UTC'));
assert_same($putsMedia, FakeCos::$puts, 'Media are uploaded once.');
$mediaResponse = (new ConversationLogService())->mediaResponse((string) $withImage['media'][0]['key'], null);
$sent = '';
ob_start(static function (string $buffer) use (&$sent): string {
    $sent .= $buffer;
    return '';
});
$mediaResponse->send();
ob_end_clean();
assert_same('png-bytes-for-cos', $sent, 'Media are fetched back from COS when opened.');
$mediaLogId = (int) $logPdo->query('SELECT id FROM conversation_logs WHERE usage_record_id = 6')->fetchColumn();
(new ConversationLogService())->deleteCall($mediaLogId);
assert_true(!isset(FakeCos::$objects[$prefix . $mediaRel]), 'Deleting the call deletes its media in COS.');

// ---------------------------------------------------------------- back to local storage
$expect(static fn () => $service->pullFromCos(), 'CONVERSATION_LOG_COS_IN_USE', 409, 'Switch to local storage before moving files back.');
$service->saveSettings(['storage_driver' => 'local']);
$pulled = (new ConversationLogService())->pullFromCos();
assert_true($pulled['files'] >= 1 && $pulled['errors'] === [], 'Files are moved back from COS.');
assert_same([], array_values(array_filter(FakeCos::keys($prefix), static fn (string $k): bool => !str_contains($k, '/_index/'))), 'No day files remain in COS.');
TencentCosClient::useTransport(static fn () => throw new RuntimeException('COS must not be needed now'));
$local = (new ConversationLogService())->calls(3, ['limit' => 10]);
foreach ($local['items'] as $item) {
    assert_same('你好！', $item['reply']['text'] ?? null, 'Everything is readable from disk after moving back.');
}
TencentCosClient::useTransport([FakeCos::class, 'handle']);

// Purge removes the COS objects too.
(new ConversationLogService())->purgeAll();
assert_same([], FakeCos::keys($prefix), 'Purge empties the COS prefix.');

TencentCosClient::useTransport(null);
ConversationLogDb::reset();
echo "OK\n";
