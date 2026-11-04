<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AppNativePushService;
use TokenHub\Services\AppPushService;

// TokenHub APP native push (APNs / FCM), database-free: provider tokens are
// verified with the generated keys, payload shapes, and send() runs against an
// injected transport (no network).
$dir = sys_get_temp_dir() . '/tokenhub-native-push-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
openssl_pkey_export($ec, $ecPem);
$ecPublic = openssl_pkey_get_details($ec)['key'];
file_put_contents($dir . '/AuthKey.p8', $ecPem);

$rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
openssl_pkey_export($rsa, $rsaPem);
$rsaPublic = openssl_pkey_get_details($rsa)['key'];
file_put_contents($dir . '/service-account.json', json_encode([
    'type' => 'service_account',
    'project_id' => 'tokenhub-test',
    'client_email' => 'push@tokenhub-test.iam.gserviceaccount.com',
    'private_key' => $rsaPem,
    'token_uri' => 'https://oauth2.googleapis.com/token',
]));

foreach ([
    'APNS_KEY_ID' => 'ABC123DEFG',
    'APNS_TEAM_ID' => 'TEAM123456',
    'APNS_BUNDLE_ID' => 'com.qianqian.tokenhub',
    'APNS_PRIVATE_KEY_PATH' => $dir . '/AuthKey.p8',
    'APNS_PRODUCTION' => 'false',
    'FCM_SERVICE_ACCOUNT_PATH' => $dir . '/service-account.json',
] as $key => $value) {
    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
}

$unb64 = static function (string $value): string {
    return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
};
$rawToDer = static function (string $raw): string {
    $int = static function (string $bytes): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . chr(strlen($bytes)) . $bytes;
    };
    $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
    return "\x30" . chr(strlen($body)) . $body;
};

// ------------------------------------------------------------ APNs JWT
$jwt = AppNativePushService::apnsJwt('ABC123DEFG', 'TEAM123456', $ecPem, 1_800_000_000);
[$h, $c, $s] = explode('.', $jwt);
assert_same(['alg' => 'ES256', 'kid' => 'ABC123DEFG'], json_decode($unb64($h), true), 'The APNs token header names ES256 and the key id.');
assert_same(['iss' => 'TEAM123456', 'iat' => 1_800_000_000], json_decode($unb64($c), true), 'The APNs token is issued by the team.');
assert_same(64, strlen($unb64($s)), 'The APNs signature is a raw 64-byte ES256 signature (JOSE), not DER.');
assert_same(1, openssl_verify($h . '.' . $c, $rawToDer($unb64($s)), $ecPublic, OPENSSL_ALGO_SHA256), 'The APNs token verifies with the key pair.');

// ------------------------------------------------------------ FCM assertion
$assertion = AppNativePushService::googleAssertion('push@tokenhub-test.iam.gserviceaccount.com', $rsaPem, 'https://oauth2.googleapis.com/token', 1_800_000_000);
[$h, $c, $s] = explode('.', $assertion);
assert_same('RS256', json_decode($unb64($h), true)['alg'], 'The Google assertion is RS256.');
$claims = json_decode($unb64($c), true);
assert_same('https://www.googleapis.com/auth/firebase.messaging', $claims['scope'], 'The assertion asks only for the FCM scope.');
assert_same(1_800_003_600, $claims['exp'], 'The assertion lives one hour.');
assert_same(1, openssl_verify($h . '.' . $c, $unb64($s), $rsaPublic, OPENSSL_ALGO_SHA256), 'The Google assertion verifies with the service account key.');

// ------------------------------------------------------------ payloads
$message = ['title' => 'TokenHub 告警', 'body' => '有 1 条新的严重告警', 'url' => '/app/#/alerts', 'tag' => 'tokenhub-alert-42', 'severity' => 'critical'];
$apns = AppNativePushService::apnsPayload($message);
assert_same('time-sensitive', $apns['aps']['interruption-level'], 'Critical alerts are time-sensitive on iOS.');
assert_same('/app/#/alerts', $apns['url'], 'Tapping the iOS notification opens the alert page.');
$fcm = AppNativePushService::fcmPayload('fcm-token', $message);
assert_same('HIGH', $fcm['message']['android']['priority'], 'Critical alerts are high priority on Android.');
assert_same('tokenhub_alerts', $fcm['message']['android']['notification']['channel_id'], 'Android notifications use the TokenHub alerts channel.');
assert_same(['url' => '/app/#/alerts', 'severity' => 'critical'], $fcm['message']['data'], 'FCM data carries only the page and severity.');
assert_true(!str_contains(json_encode([$apns, $fcm], JSON_UNESCAPED_UNICODE), 'api_key'), 'Payloads carry no secrets.');

// ------------------------------------------------------------ send()
$calls = [];
$responses = [];
$transport = static function (string $url, string $body, array $headers) use (&$calls, &$responses): array {
    $calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
    return array_shift($responses) ?? ['status' => 200, 'body' => ''];
};
$service = new AppNativePushService($transport);
assert_true($service->configured('ios') && $service->configured('android'), 'Both platforms are configured from the environment.');
assert_true(!$service->configured('windows'), 'Unknown platforms are never configured.');

$token = str_repeat('ab', 32);
$responses = [['status' => 200, 'body' => '']];
$result = $service->send('ios', $token, $message);
assert_same(200, $result['status'], 'An APNs push is accepted.');
assert_same('https://api.sandbox.push.apple.com/3/device/' . $token, $calls[0]['url'], 'APNS_PRODUCTION=false uses the sandbox gateway.');
assert_true(in_array('apns-topic: com.qianqian.tokenhub', $calls[0]['headers'], true), 'The APNs topic is the bundle id.');
assert_true(in_array('apns-priority: 10', $calls[0]['headers'], true), 'Critical alerts are delivered immediately.');
$responses = [['status' => 410, 'body' => '{"reason":"Unregistered"}']];
assert_true(($service->send('ios', $token, $message)['gone'] ?? false) === true, 'APNs 410 marks the subscription as gone.');
$responses = [['status' => 400, 'body' => '{"reason":"BadDeviceToken"}']];
assert_true(($service->send('ios', $token, $message)['gone'] ?? false) === true, 'BadDeviceToken marks the subscription as gone.');
assert_true(($service->send('ios', 'not-a-token', $message)['gone'] ?? false) === true, 'A malformed APNs token is dropped without a request.');
assert_same(3, count($calls), 'The provider token is cached (no extra requests).');

$calls = [];
$fcmToken = 'dGVzdC10b2tlbg:APA91b' . str_repeat('x', 40);
$responses = [['status' => 200, 'body' => '{"access_token":"ya29.test","expires_in":3599}'], ['status' => 200, 'body' => '{"name":"projects/tokenhub-test/messages/1"}']];
$result = $service->send('android', $fcmToken, $message);
assert_same(200, $result['status'], 'An FCM push is accepted.');
assert_same('https://oauth2.googleapis.com/token', $calls[0]['url'], 'FCM first exchanges the assertion for an access token.');
assert_true(str_contains($calls[0]['body'], 'grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer'), 'The JWT bearer grant is used.');
assert_same('https://fcm.googleapis.com/v1/projects/tokenhub-test/messages:send', $calls[1]['url'], 'FCM HTTP v1 send endpoint of the project.');
assert_true(in_array('authorization: Bearer ya29.test', $calls[1]['headers'], true), 'The send request carries the access token.');
assert_same($fcmToken, json_decode($calls[1]['body'], true)['message']['token'], 'The message targets the device token.');
$responses = [['status' => 404, 'body' => '{"error":{"status":"NOT_FOUND"}}']];
assert_true(($service->send('android', $fcmToken, $message)['gone'] ?? false) === true, 'FCM 404 marks the subscription as gone.');
assert_same(3, count($calls), 'The FCM access token is cached.');

$responses = [['status' => 500, 'body' => '{"error":{"status":"INTERNAL"}}']];
$failed = $service->send('android', $fcmToken, $message);
assert_true(!($failed['gone'] ?? false) && $failed['error'] === 'INTERNAL', 'A server error keeps the subscription for the next alert.');

// ------------------------------------------------------------ wiring
$root = dirname(__DIR__, 2);
$push = (string) file_get_contents($root . '/app/Services/AppPushService.php');
assert_true(str_contains($push, 'AppNativePushService') && str_contains($push, 'function subscribeNative'), 'Alert pushes reach native subscriptions.');
$bridge = (string) file_get_contents($root . '/public/app/assets/js/native-bridge.js');
assert_true(str_contains($bridge, 'PushNotifications') && str_contains($bridge, 'startsWith("/app/")'), 'Tapping a native notification only opens pages of this server.');
$capacitor = json_decode((string) file_get_contents($root . '/native/capacitor/package.json'), true);
assert_true(isset($capacitor['dependencies']['@capacitor/push-notifications']), 'The mobile shell ships the push plugin.');
$tauri = (string) file_get_contents($root . '/native/tauri/src-tauri/src/main.rs');
assert_true(str_contains($tauri, 'tauri_plugin_notification::init()'), 'The desktop shell can show system notifications.');
$migration = (string) file_get_contents($root . '/database/migrations/2026_10_31_000005_admin_app_native.sql');
assert_true(str_contains($migration, 'kind'), 'Push subscriptions record their kind (webpush / ios / android).');

array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);
echo "PASS APP 原生推送\n";
