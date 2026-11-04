<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AppPushService;
use TokenHub\Services\AppStepUpPolicy;

// TokenHub APP (/app/): shell files, caching rules, Caddy routes, step-up
// policy and Web Push crypto. Static and database-free.
$root = dirname(__DIR__, 2);

// ------------------------------------------------------------------ shell
$manifest = json_decode((string) file_get_contents($root . '/public/app/manifest.webmanifest'), true);
assert_true(is_array($manifest), 'The APP manifest must be valid JSON.');
assert_same('/app/', $manifest['scope'] ?? null, 'The APP is scoped to /app/.');
assert_same('standalone', $manifest['display'] ?? null, 'The installed APP opens in its own window.');
$sizes = array_column($manifest['icons'] ?? [], 'sizes');
assert_true(in_array('192x192', $sizes, true) && in_array('512x512', $sizes, true), 'The manifest needs 192 and 512 px icons.');
assert_true(in_array('maskable', array_column($manifest['icons'], 'purpose'), true), 'The manifest needs a maskable icon.');
foreach ($manifest['icons'] as $icon) {
    assert_true(is_file($root . '/public' . $icon['src']), 'Manifest icon should exist: ' . $icon['src']);
}

$index = (string) file_get_contents($root . '/public/app/index.html');
foreach (['rel="manifest"', 'viewport-fit=cover', 'id="modal-root"', 'id="content"', '/app/assets/js/main.js'] as $needle) {
    assert_true(str_contains($index, $needle), 'APP index.html should contain ' . $needle);
}

$sw = (string) file_get_contents($root . '/public/app/sw.js');
assert_true(str_contains($sw, 'url.pathname.startsWith("/admin-api/")') && str_contains($sw, 'never cached'), 'The service worker must never cache API responses.');
assert_true(str_contains($sw, '"push"') && str_contains($sw, 'notificationclick'), 'The service worker shows alert notifications.');

$js = '';
foreach (glob($root . '/public/app/assets/js/*.js') ?: [] as $file) {
    $js .= (string) file_get_contents($file);
}
assert_true(!str_contains($js, 'innerHTML'), 'The APP builds its DOM without innerHTML.');
assert_true(!preg_match('/localStorage\.setItem\([^)]*(token|refresh)/i', $js), 'Credentials are never written to localStorage.');
assert_true(str_contains($js, 'history.replaceState(null, "", "/app/#/overview")'), 'The one-time code is removed from the address bar before it is exchanged.');
assert_true(str_contains($js, 'navigator.locks'), 'Parallel tabs refresh one at a time.');
$api = (string) file_get_contents($root . '/public/admin/assets/js/api.js');
assert_true(str_contains($api, 'export function setAuthProvider') && str_contains($api, 'ADMIN_STEP_UP_REQUIRED'), 'The shared API client supports the APP bearer provider and step-up replay.');

// ------------------------------------------------------------------ Caddy
foreach (['docker/caddy/Caddyfile', 'docker/release/caddy/Caddyfile'] as $file) {
    $caddy = (string) file_get_contents($root . '/' . $file);
    assert_true(str_contains($caddy, '@app path /app /app/*') && str_contains($caddy, 'try_files {path} /app/index.html'), $file . ' should serve the APP with an index fallback for /app/link.');
    assert_true(str_contains($caddy, 'Service-Worker-Allowed'), $file . ' should allow the /app/ service worker scope.');
}
$example = (string) file_get_contents($root . '/Caddyfile.example');
assert_true(str_contains($example, 'handle_path /app/*') && str_contains($example, 'public/app'), 'Caddyfile.example should serve the APP for source installs.');
$entrypoint = (string) file_get_contents($root . '/docker/release/caddy/entrypoint.sh');
assert_true(str_contains($entrypoint, 'pki/authorities/local') && str_contains($entrypoint, "-name '*.crt'"), 'The Caddy image shares only the public internal-CA certificates.');

// --------------------------------------------------------------- step-up
$policy = new AppStepUpPolicy();
$cases = [
    ['DELETE', '/admin-api/api-keys/12', true],
    ['POST', '/admin-api/api-keys/12/rotate', true],
    ['POST', '/admin-api/api-keys/batch', true],
    ['POST', '/admin-api/quota-adjustments', true],
    ['POST', '/admin-api/admin-users', true],
    ['PUT', '/admin-api/admin-users/3', true],
    ['PUT', '/admin-api/system/settings-center/brand_identity', true],
    ['PUT', '/admin-api/system-settings/4', true],
    ['POST', '/admin-api/my/app-devices/revoke-all', true],
    ['DELETE', '/admin-api/my/app-devices/8', true],
    ['DELETE', '/admin-api/my/app-devices/7', false], // the current device signs itself out freely
    ['GET', '/admin-api/admin-users', false],
    ['POST', '/admin-api/api-keys', false],
    ['PUT', '/admin-api/api-keys/12', false], // includes disabling a key: damage limitation stays fast
    ['POST', '/admin-api/alerts/5/status', false],
    ['GET', '/admin-api/system/brand-identity', false],
    // SEC-004: providers, dashboard share links and vector store writes.
    ['POST', '/admin-api/providers', true],
    ['PUT', '/admin-api/providers/3', true],
    ['DELETE', '/admin-api/providers/3', true],
    ['POST', '/admin-api/providers/3/models', true],
    ['POST', '/admin-api/providers/3/catalog-sync', true],
    ['GET', '/admin-api/providers', false],
    ['GET', '/admin-api/providers/3/models', false],
    ['POST', '/admin-api/dashboard-share-links', true],
    ['PUT', '/admin-api/dashboard-share-links/9', true],
    ['DELETE', '/admin-api/dashboard-share-links/9', true],
    ['POST', '/admin-api/dashboard-share-links/9/reset-password', true],
    ['POST', '/admin-api/dashboard-share-links/9/revoke-sessions', true],
    ['POST', '/admin-api/dashboard-share-links/9/allow-client-ip', true],
    ['GET', '/admin-api/dashboard-share-links', false],
    ['POST', '/admin-api/rag-vector-configs', true],
    ['PUT', '/admin-api/rag-vector-configs/2', true],
    ['DELETE', '/admin-api/rag-vector-configs/2', true],
    ['POST', '/admin-api/rag-vector-configs/test', true],
    ['GET', '/admin-api/rag-vector-configs', false],
    ['POST', '/admin-api/users/5/reset-password', true],
    ['POST', '/admin-api/provider-health', false],
];
foreach ($cases as [$method, $path, $sensitive]) {
    assert_same($sensitive, $policy->sensitiveOperation($method, $path, 7) !== null, sprintf('Step-up for %s %s', $method, $path));
}
$middleware = (string) file_get_contents($root . '/app/Middleware/AdminAuthMiddleware.php');
assert_true(str_contains($middleware, "'ADMIN_STEP_UP_REQUIRED'") && str_contains($middleware, "=== 'app'"), 'Step-up is enforced by the server for APP sessions only.');

// ------------------------------------------------------------ Web Push
// Encrypt for a subscriber key pair, then decrypt as the browser would (RFC 8291).
$subscriber = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$details = openssl_pkey_get_details($subscriber);
$uaPublic = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$auth = random_bytes(16);
$message = '{"title":"TokenHub 新告警","body":"有一条告警待处理"}';
$body = AppPushService::encrypt($message, AppPushService::b64($uaPublic), AppPushService::b64($auth));
$salt = substr($body, 0, 16);
assert_same(4096, unpack('N', substr($body, 16, 4))[1], 'Record size header.');
$idLength = ord($body[20]);
$asPublic = substr($body, 21, $idLength);
$cipher = substr($body, 21 + $idLength);
$asPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $asPublic), 64, "\n") . "-----END PUBLIC KEY-----\n";
$shared = openssl_pkey_derive(openssl_pkey_get_public($asPem), $subscriber, 32);
$ikm = hash_hmac('sha256', "WebPush: info\x00" . $uaPublic . $asPublic . "\x01", hash_hmac('sha256', $shared, $auth, true), true);
$prk = hash_hmac('sha256', $ikm, $salt, true);
$cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
$nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);
$plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
assert_same($message . "\x02", $plain, 'A subscriber can decrypt the push message.');

// VAPID token: ES256 over header.claims, verifiable with the public key.
$pair = AppPushService::generateKeyPair();
$authorization = AppPushService::vapidAuthorization('https://fcm.googleapis.com/fcm/send/abc', $pair['private_pem'], $pair['public'], 'mailto:ops@example.com', 2000000000);
assert_true(preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $authorization, $m) === 1, 'VAPID header format.');
$claims = json_decode(AppPushService::unb64($m[2]), true);
assert_same('https://fcm.googleapis.com', $claims['aud'] ?? null, 'VAPID audience is the push service origin.');
$raw = AppPushService::unb64($m[3]);
assert_same(64, strlen($raw), 'ES256 signatures are 64 raw bytes.');
$der = static function (string $int): string {
    $int = ltrim($int, "\x00");
    if ($int === '' || (ord($int[0]) & 0x80) !== 0) {
        $int = "\x00" . $int;
    }
    return "\x02" . chr(strlen($int)) . $int;
};
$sequence = $der(substr($raw, 0, 32)) . $der(substr($raw, 32));
$publicPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . AppPushService::unb64($m[4])), 64, "\n") . "-----END PUBLIC KEY-----\n";
assert_same(1, openssl_verify($m[1] . '.' . $m[2], "\x30" . chr(strlen($sequence)) . $sequence, $publicPem, OPENSSL_ALGO_SHA256), 'The VAPID signature verifies with the published key.');
