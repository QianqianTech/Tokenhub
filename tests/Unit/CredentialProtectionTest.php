<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ProviderHeaderVault;
use TokenHub\Support\Crypto;

$restore = static function (array $values): void {
    foreach ($values as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
        }
    }
};
$restore(['APP_ENV' => 'testing', 'APP_KEY' => 'unit-test-key-0123456789abcdef-0123', 'APP_KEY_ID' => 'k1', 'APP_PREVIOUS_KEYS' => null, 'APP_HASH_KEY' => null]);

// --- Crypto keyring (TH-039) ---
$cipher = Crypto::encrypt('provider-secret');
assert_true(str_starts_with($cipher, 'v2:k1:'), 'New ciphertexts carry the key id.');
assert_same('provider-secret', Crypto::decrypt($cipher), 'Ciphertext decrypts with the primary key.');
assert_same(false, Crypto::needsReencryption($cipher), 'Ciphertext under the primary key needs no rotation.');

$hashBefore = Crypto::hashKey('tkh_example');
$restore(['APP_HASH_KEY' => 'unit-test-key-0123456789abcdef-0123', 'APP_KEY' => 'rotated-key-abcdefghijklmnopqrstuvwxyz', 'APP_KEY_ID' => 'k2', 'APP_PREVIOUS_KEYS' => 'k1:unit-test-key-0123456789abcdef-0123']);
assert_same('provider-secret', Crypto::decrypt($cipher), 'Old ciphertext still decrypts through APP_PREVIOUS_KEYS during rotation.');
assert_same(true, Crypto::needsReencryption($cipher), 'Old ciphertext is reported for re-encryption.');
assert_true(str_starts_with(Crypto::encrypt('x'), 'v2:k2:'), 'New writes use the new key id.');
assert_same($hashBefore, Crypto::hashKey('tkh_example'), 'Pinned APP_HASH_KEY keeps API key hashes stable across APP_KEY rotation.');

$restore(['APP_HASH_KEY' => null]);
assert_true(in_array($hashBefore, Crypto::hashKeyCandidates('tkh_example'), true), 'Previous keys provide hash candidates for lookups that were not pinned.');

$restore(['APP_PREVIOUS_KEYS' => null]);
assert_same('', Crypto::decrypt($cipher), 'Without the old key the old ciphertext is unreadable (never silently wrong).');

$legacyKey = hash('sha256', 'legacy-app-key-value-0123456789abcdef', true);
$iv = random_bytes(12);
$tag = '';
$legacyCipher = base64_encode($iv . $tag . openssl_encrypt('legacy', 'aes-256-gcm', $legacyKey, OPENSSL_RAW_DATA, $iv, $tag));
$legacyCipher = base64_encode($iv . $tag . substr(base64_decode($legacyCipher), 12));
$restore(['APP_KEY' => 'legacy-app-key-value-0123456789abcdef', 'APP_KEY_ID' => null]);
assert_same('legacy', Crypto::decrypt($legacyCipher), 'Legacy unprefixed ciphertext keeps decrypting.');

// --- production key guard (TH-005) ---
assert_same('missing', Crypto::keyProblem(''), 'Missing key is detected.');
assert_same('development default', Crypto::keyProblem('development-tokenhub-key-change-me'), 'Development default key is detected.');
assert_same('shorter than 32 characters', Crypto::keyProblem('short'), 'Short keys are detected.');
assert_same(null, Crypto::keyProblem(str_repeat('a', 40)), 'A long random key passes.');
$threw = false;
try {
    $restore(['APP_KEY' => 'development-tokenhub-key-change-me']);
    Crypto::assertProductionReady('production');
} catch (RuntimeException) {
    $threw = true;
}
assert_true($threw, 'Production start-up fails on the development key.');
Crypto::assertProductionReady('local');
$restore(['APP_KEY' => 'unit-test-key-0123456789abcdef-0123', 'APP_KEY_ID' => 'k1']);

// --- sensitive provider headers (TH-005) ---
$vault = new ProviderHeaderVault();
$stored = $vault->protectForStorage(['x-api-key' => 'sk-live-1', 'X-Org' => 'demo', 'tokenhub_adapter' => 'volcengine_ark']);
assert_true(str_starts_with((string) $stored['x-api-key'], 'enc:'), 'Credential headers are encrypted at rest.');
assert_same('demo', $stored['X-Org'], 'Plain headers stay readable.');
assert_same('volcengine_ark', $stored['tokenhub_adapter'], 'tokenhub_* control keys are never treated as credentials.');
assert_same('****', $vault->maskForDisplay($stored)['x-api-key'], 'Credential headers are masked for display.');
assert_same('sk-live-1', $vault->revealForUse($stored)['x-api-key'], 'Credential headers are decrypted only for the upstream call.');
$resaved = $vault->protectForStorage(['x-api-key' => '****', 'X-Org' => 'demo2'], $stored);
assert_same($stored['x-api-key'], $resaved['x-api-key'], 'Saving the mask keeps the stored credential.');
assert_same([], $vault->plaintextSensitiveHeaders($resaved), 'No plaintext credential remains after a save.');
assert_same(['Authorization'], $vault->plaintextSensitiveHeaders(['Authorization' => 'Bearer legacy']), 'Legacy plaintext credential headers are reported.');
$removed = $vault->protectForStorage(['X-Org' => 'demo'], $stored);
assert_true(!isset($removed['x-api-key']), 'Omitting a credential header removes it.');
