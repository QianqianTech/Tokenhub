<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * SEC-108 SafeRemoteFetcher connects to the address the policy checked
 *         (CURLOPT_RESOLVE) and re-checks every redirect hop.
 * SEC-109 /oauth/register and /oauth/token are throttled per client IP.
 * SEC-110 audit rows use the trusted-proxy client IP, record the API key
 *         behind API-key initiated writes, and failed console sign-ins are
 *         audited without the password.
 * SEC-112 Crypto encrypts with an HKDF-derived key while old ciphertexts and
 *         stored HMAC hashes keep working; production never tries the
 *         development key; task callbacks are signed with a derived key and
 *         the old signature is accepted during the transition.
 */

use TokenHub\Services\AuditService;
use TokenHub\Services\AuthService;
use TokenHub\Services\Mcp\OAuth\OAuthException;
use TokenHub\Services\Mcp\OAuth\OAuthServer;
use TokenHub\Services\OutboundUrlPolicy;
use TokenHub\Services\SafeRemoteFetcher;
use TokenHub\Services\TaskCallbackService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Database;
use TokenHub\Support\RequestContext;

$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_KEY'] = 'security-extras-test-key-0123456789abcdef';
$_ENV['APP_KEY_ID'] = 'k1';
unset($_ENV['APP_PREVIOUS_KEYS'], $_ENV['APP_HASH_KEY']);
$_ENV['MCP_OAUTH_REGISTER_PER_MINUTE'] = '2';
$_ENV['MCP_OAUTH_TOKEN_PER_MINUTE'] = '3';
$_ENV['TRUSTED_PROXY_CIDRS'] = '10.0.0.0/8';
(new ReflectionProperty(App::class, 'config'))->setValue(null, \TokenHub\Support\Config::default());
(new ReflectionProperty(App::class, 'context'))->setValue(null, new RequestContext('req_sec_extras', 'trc_sec_extras'));

// ---------------------------------------------------------------- SEC-112 crypto
$legacyV2 = (static function (string $plain, string $rawKey, string $kid): string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $rawKey, true), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v2:' . $kid . ':' . base64_encode($iv . $tag . $cipher);
})('old-provider-secret', $_ENV['APP_KEY'], 'k1');
assert_same('old-provider-secret', Crypto::decrypt($legacyV2), 'A v2 ciphertext written before the upgrade still decrypts.');
assert_same(true, Crypto::needsReencryption($legacyV2), 'v2 ciphertexts are reported for re-encryption.');
$v3 = Crypto::encrypt('new-secret');
assert_true(str_starts_with($v3, 'v3:k1:'), 'New ciphertexts use the HKDF format.');
assert_same('new-secret', Crypto::decrypt($v3), 'v3 ciphertexts decrypt.');
assert_same(false, Crypto::needsReencryption($v3), 'v3 under the primary key needs no rotation.');
$raw = base64_decode(substr($v3, strlen('v3:k1:')), true);
assert_same(false, openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', $_ENV['APP_KEY'], true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)), 'The encryption key is no longer sha256(APP_KEY), the key behind hashKey().');
assert_same(hash_hmac('sha256', 'tkh_live_key', hash('sha256', $_ENV['APP_KEY'], true)), Crypto::hashKey('tkh_live_key'), 'hashKey() is unchanged, so stored API key and session hashes keep matching.');
assert_true(Crypto::hmac('task-callback', 'x') !== Crypto::hmac('other', 'x'), 'Derived MAC keys are separated by purpose.');
assert_true(Crypto::derivedKey('encryption') !== hash('sha256', $_ENV['APP_KEY'], true), 'Derived keys differ from the legacy hash key.');

$devKey = 'development-tokenhub-key-change-me';
$devCipher = (static function (string $plain, string $rawKey): string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $rawKey, true), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
})('dev-written', $devKey);
assert_same('dev-written', Crypto::decrypt($devCipher), 'Outside production, legacy values written with the development key still decrypt.');
$_ENV['APP_ENV'] = 'production';
assert_same('', Crypto::decrypt($devCipher), 'In production the development key is never tried.');
$_ENV['APP_ENV'] = 'testing';

// Task callbacks: derived signature, legacy accepted during the transition.
$callbacks = new TaskCallbackService();
$taskId = 'task_' . bin2hex(random_bytes(4));
$legacySignature = substr(hash_hmac('sha256', 'task-callback|' . $taskId, $_ENV['APP_KEY']), 0, 40);
assert_true($callbacks->signature($taskId) !== $legacySignature, 'New callback URLs are signed with the derived key.');
assert_same(40, strlen($callbacks->signature($taskId)), 'The signature keeps its length.');
assert_same(true, $callbacks->verify($taskId, $callbacks->signature($taskId)), 'New signatures verify.');
assert_same(true, $callbacks->verify($taskId, $legacySignature), 'Callback URLs handed out before the upgrade keep working.');
assert_same(false, $callbacks->verify($taskId, str_repeat('0', 40)), 'Forged signatures fail.');
$_ENV['TASK_CALLBACK_ACCEPT_LEGACY_SIGNATURES'] = 'false';
assert_same(false, $callbacks->verify($taskId, $legacySignature), 'Legacy signatures stop working once the transition is turned off.');
assert_same(true, $callbacks->verify($taskId, $callbacks->signature($taskId)), 'New signatures still verify after the transition.');
unset($_ENV['TASK_CALLBACK_ACCEPT_LEGACY_SIGNATURES']);

// ---------------------------------------------------------------- SEC-108 pinned fetch
$policy = new OutboundUrlPolicy(static fn (string $host): array => match ($host) {
    'assets.example-public.test' => ['93.184.216.34'],
    'rebind.example-public.test' => ['93.184.216.35'],
    'pinned.invalid' => ['127.0.0.1'],
    default => filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [],
}, ['127.0.0.1']);
$seen = [];
$hops = [
    'https://assets.example-public.test/a' => ['status' => 302, 'headers' => ['HTTP/1.1 302 Found', 'Location: https://rebind.example-public.test/b'], 'body' => '', 'truncated' => false],
    'https://rebind.example-public.test/b' => ['status' => 302, 'headers' => ['HTTP/1.1 302 Found', 'Location: http://169.254.169.254/latest/meta-data'], 'body' => '', 'truncated' => false],
];
$fetcher = new SafeRemoteFetcher($policy, static function (string $url, array $options) use (&$seen, $hops): array {
    $seen[] = [$url, $options['address'] ?? null];
    return $hops[$url] ?? ['status' => 404, 'headers' => [], 'body' => '', 'truncated' => false];
}, 1024, 5);
$result = $fetcher->get('https://assets.example-public.test/a');
assert_same('OUTBOUND_BLOCKED:forbidden_address', $result['error'] ?? null, 'A redirect hop into the metadata address is re-checked and blocked.');
assert_same([['https://assets.example-public.test/a', '93.184.216.34'], ['https://rebind.example-public.test/b', '93.184.216.35']], $seen, 'Every hop is connected to the address its own check resolved.');

if (function_exists('curl_init') && function_exists('proc_open')) {
    $docroot = sys_get_temp_dir() . '/th-pin-' . bin2hex(random_bytes(4));
    mkdir($docroot);
    file_put_contents($docroot . '/index.php', "<?php header('Content-Type: image/png'); echo 'PINNED:' . (\$_SERVER['HTTP_HOST'] ?? '');");
    $port = random_int(20000, 40000);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    try {
        $live = null;
        for ($i = 0; $i < 40; $i++) {
            usleep(100000);
            $live = (new SafeRemoteFetcher($policy, null, 1024, 5))->get('http://pinned.invalid:' . $port . '/index.php');
            if (($live['ok'] ?? false) === true) {
                break;
            }
        }
        // pinned.invalid does not resolve in DNS: success proves the connection used the checked address.
        assert_same(true, $live['ok'] ?? null, 'The cURL transport connects to the checked address. Got ' . json_encode($live));
        assert_same('PINNED:pinned.invalid:' . $port, $live['body'] ?? null, 'The Host header keeps the real host name.');
        assert_same('image/png', $live['content_type'] ?? null, 'Headers are captured.');
        $small = (new SafeRemoteFetcher($policy, null, 4, 5))->get('http://pinned.invalid:' . $port . '/index.php');
        assert_same('OUTBOUND_TOO_LARGE', $small['error'] ?? null, 'The byte limit aborts the transfer.');
    } finally {
        proc_terminate($server);
        proc_close($server);
        @unlink($docroot . '/index.php');
        @rmdir($docroot);
    }
}

// ---------------------------------------------------------------- SEC-109 OAuth throttles
$oauth = new OAuthServer('https://hub.example.com');
$ip = '198.51.100.' . random_int(1, 254) . '-' . bin2hex(random_bytes(3));
$codes = [];
for ($i = 0; $i < 3; $i++) {
    try {
        $oauth->register(['redirect_uris' => []], $ip);
    } catch (OAuthException $exception) {
        $codes[] = $exception->status;
    }
}
assert_same([400, 400, 429], $codes, 'Dynamic registration is throttled per client IP.');
$codes = [];
for ($i = 0; $i < 4; $i++) {
    try {
        $oauth->token(['grant_type' => 'authorization_code'], null, null, $ip);
    } catch (OAuthException $exception) {
        $codes[] = $exception->status === 429 ? 429 : 'other';
    }
}
assert_same(['other', 'other', 'other', 429], $codes, 'The token endpoint is throttled per client IP.');

// ---------------------------------------------------------------- SEC-110 audit
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]) extends PDO {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $query = preg_replace('/\bFOR UPDATE\b/i', '', $query) ?? $query;
            if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
                $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
            }
            return parent::prepare($query, $options);
        }
    };
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, username TEXT, password_hash TEXT, role TEXT, status TEXT DEFAULT "active")');
    $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, actor_api_key_id INT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT, channel TEXT, device_id INT)');
    $pdo->exec("INSERT INTO users (id, department_id, username, password_hash, role) VALUES (7, 1, 'ops', '" . password_hash('right-password', PASSWORD_DEFAULT) . "', 'admin')");
    $database = new Database(App::config());
    (new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
    (new ReflectionProperty(App::class, 'database'))->setValue(null, $database);

    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.9';
    App::context()->setApiKey(['id' => 4321, 'user_id' => 7]);
    (new AuditService())->write('agent_management.user.create', 'users', 99);
    $row = $pdo->query("SELECT * FROM audit_logs WHERE action = 'agent_management.user.create'")->fetch();
    assert_same('203.0.113.9', $row['client_ip'], 'The audit IP is the first untrusted hop from the right, not a client-chosen X-Forwarded-For entry.');
    assert_same(4321, (int) $row['actor_api_key_id'], 'API-key initiated writes record the key id.');
    assert_same('api_key', $row['actor_type'], 'The actor type names the API key.');
    App::context()->setApiKey(null);

    $_SERVER['REMOTE_ADDR'] = '192.0.2.44';
    assert_same(null, (new AuthService())->login('ops', 'wrong-password-123', '192.0.2.44', 'test'), 'A wrong password is refused.');
    assert_same(null, (new AuthService())->login('nobody', 'whatever-pass', '192.0.2.44', 'test'), 'An unknown account is refused.');
    $failed = $pdo->query("SELECT * FROM audit_logs WHERE action = 'admin.login.failed' ORDER BY id")->fetchAll();
    assert_same(2, count($failed), 'Failed console sign-ins are audited.');
    assert_same('7', (string) $failed[0]['target_id'], 'A wrong password names the account.');
    assert_same('192.0.2.44', $failed[0]['client_ip'], 'The failed sign-in records the client IP.');
    foreach ($failed as $entry) {
        assert_true(!str_contains((string) $entry['details'], 'wrong-password-123') && !str_contains((string) $entry['details'], 'whatever-pass'), 'The password is never written to the audit log.');
    }
    assert_true(str_contains((string) $failed[1]['details'], 'nobody'), 'The attempted username is recorded.');
    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
}

echo "PASS\n";
