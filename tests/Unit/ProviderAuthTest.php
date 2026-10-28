<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * MM-011: pluggable upstream authentication.
 */

use TokenHub\Services\ProviderHttpClient;
use TokenHub\Services\Providers\ProviderAuth;

$auth = new ProviderAuth(null, 1793200000);

// Header / query / bearer schemes.
assert_same(['Authorization' => 'Bearer sk'], $auth->apply([], 'sk', 'POST', 'https://a.test/x', '{}', [])['headers'], 'Bearer is the default.');
assert_same('sk', $auth->apply([], 'sk', 'POST', 'https://a.test/x', '{}', [], 'anthropic')['headers']['x-api-key'], 'Anthropic protocol uses x-api-key.');
assert_same(['x-key' => 'sk'], $auth->apply(['type' => 'header', 'name' => 'x-key'], 'sk', 'POST', 'https://a.test/x', '{}', [])['headers'], 'Custom header scheme.');
assert_same('Token sk', $auth->apply(['type' => 'header', 'name' => 'Authorization', 'prefix' => 'Token '], 'sk', 'POST', 'https://a.test/x', '{}', [])['headers']['Authorization'], 'Header prefix (Vidu Token, fal Key).');
assert_same('https://a.test/x?a=1&key=sk', $auth->apply(['type' => 'query', 'name' => 'key'], 'sk', 'GET', 'https://a.test/x?a=1', '', [])['url'], 'Query scheme appends the key.');

// Kling AK/SK JWT: HS256, iss = AK, verifiable with SK.
$jwt = $auth->jwtHs256('ak-1:sk-1', 1800);
[$header, $claims, $signature] = explode('.', $jwt);
$decode = static fn (string $part): array => json_decode((string) base64_decode(strtr($part, '-_', '+/')), true);
assert_same('HS256', $decode($header)['alg'], 'JWT is HS256.');
assert_same(['iss' => 'ak-1', 'exp' => 1793201800, 'nbf' => 1793199995], $decode($claims), 'JWT claims follow the Kling spec.');
assert_same(rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $claims, 'sk-1', true)), '+/', '-_'), '='), $signature, 'JWT is signed with the secret key.');
assert_true(str_starts_with($auth->apply(['type' => 'bearer_or_jwt'], 'ak:sk', 'POST', 'https://k.test', '{}', [])['headers']['Authorization'], 'Bearer ey'), 'AK:SK secrets switch to JWT.');
assert_same('Bearer plain-key', $auth->apply(['type' => 'bearer_or_jwt'], 'plain-key', 'POST', 'https://k.test', '{}', [])['headers']['Authorization'], 'Plain API keys stay bearer.');

// Tencent TC3-HMAC-SHA256: deterministic and complete.
$tc3 = $auth->apply(['type' => 'tc3_hmac', 'service' => 'hunyuan', 'region' => 'ap-guangzhou', 'version' => '2023-09-01'], 'AKIDtest:secretKey', 'POST', 'https://hunyuan.tencentcloudapi.com/', '{"Prompt":"cat"}', ['X-TC-Action' => 'SubmitHunyuanImageJob'])['headers'];
assert_same('SubmitHunyuanImageJob', $tc3['X-TC-Action'], 'Action header kept.');
assert_same('2023-09-01', $tc3['X-TC-Version'], 'Version header set.');
assert_same('1793200000', $tc3['X-TC-Timestamp'], 'Timestamp header set.');
assert_true(preg_match('#^TC3-HMAC-SHA256 Credential=AKIDtest/2026-10-28/hunyuan/tc3_request, SignedHeaders=content-type;host;x-tc-action, Signature=[0-9a-f]{64}$#', $tc3['Authorization']) === 1, 'Authorization follows the TC3 format. Got ' . $tc3['Authorization']);
$again = $auth->apply(['type' => 'tc3_hmac', 'service' => 'hunyuan', 'version' => '2023-09-01'], 'AKIDtest:secretKey', 'POST', 'https://hunyuan.tencentcloudapi.com/', '{"Prompt":"cat"}', ['X-TC-Action' => 'SubmitHunyuanImageJob'])['headers'];
assert_same($tc3['Authorization'], $again['Authorization'], 'Signature is deterministic for the same input.');

// Google service account: RS256 assertion exchanged for an access token.
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
$public = openssl_pkey_get_details($key)['key'];
$seen = null;
$google = new ProviderAuth(static function (string $url, string $form) use (&$seen): array {
    parse_str($form, $fields);
    $seen = ['url' => $url, 'fields' => $fields];
    return ['status' => 200, 'body' => '{"access_token":"ya29.test","expires_in":3600}'];
}, 1793200000);
$account = json_encode(['client_email' => 'svc@p.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
try {
    \TokenHub\Support\App::redis()->delete('provider_auth:gsa:' . hash('sha256', 'svc@p.iam.gserviceaccount.com|https://www.googleapis.com/auth/cloud-platform'));
} catch (Throwable) {
}
$headers = $google->apply(['type' => 'google_service_account'], (string) $account, 'POST', 'https://us-central1-aiplatform.googleapis.com/v1/x', '{}', [])['headers'];
assert_same('Bearer ya29.test', $headers['Authorization'], 'Service account yields a bearer access token.');
assert_same('urn:ietf:params:oauth:grant-type:jwt-bearer', $seen['fields']['grant_type'] ?? null, 'JWT bearer grant is used.');
[$h, $c, $sig] = explode('.', (string) $seen['fields']['assertion']);
assert_same(1, openssl_verify($h . '.' . $c, (string) base64_decode(strtr($sig, '-_', '+/')), $public, OPENSSL_ALGO_SHA256), 'The assertion is signed with the service account key.');

// Absolute vendor URLs: credentials only follow the channel's own host family.
$client = new ProviderHttpClient();
$route = ['base_url' => 'https://api.bfl.ai/v1', 'default_headers' => null];
assert_same('https://api.us1.bfl.ai/v1/get_result?id=1', $client->target($route, 'https://api.us1.bfl.ai/v1/get_result?id=1', false)['url'] ?? null, 'A regional host of the same vendor is allowed.');
assert_same('OUTBOUND_BLOCKED', $client->target($route, 'https://evil.example/steal', false)['error_code'] ?? null, 'A foreign host is refused.');
assert_same('OUTBOUND_BLOCKED', $client->target(['base_url' => 'https://api.foo.com.cn'], 'https://other.com.cn/x', false)['error_code'] ?? null, 'Public suffixes such as com.cn do not make two vendors one family.');

echo "PASS\n";
