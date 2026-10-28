<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\SensitiveDataService;

$service = new SensitiveDataService();

$provider = $service->sanitizeRow('providers', [
    'id' => 7,
    'name' => 'OpenAI',
    'encrypted_secret' => 'encrypted-value',
    'default_headers' => '{"Authorization":"Bearer secret","X-Safe":"ok"}',
]);

assert_true(!array_key_exists('encrypted_secret', $provider), 'Provider response must not expose encrypted_secret.');
assert_same('configured', $provider['secret_status'], 'Provider response should expose only secret status.');

$apiKey = $service->sanitizeRow('api_keys', [
    'id' => 3,
    'key_hash' => 'hash',
    'key_prefix' => 'tkh_1234',
    'key_suffix' => 'abcd',
]);

assert_true(!array_key_exists('key_hash', $apiKey), 'API key response must not expose key_hash.');
assert_same('tkh_1234****abcd', $apiKey['display'], 'API key response should include a safe display value.');

$rows = $service->sanitizeRows('providers', [['encrypted_secret' => null]]);
assert_same('empty', $rows[0]['secret_status'], 'Provider rows should mark empty secrets safely.');

