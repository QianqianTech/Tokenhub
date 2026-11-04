<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AppDeviceAuthService;
use TokenHub\Services\AppPushService;
use TokenHub\Services\WebAuthnVerifier;

// TokenHub APP second batch, database-free: passkey verification against real
// credentials recorded from a WebAuthn authenticator (ES256 and RS256), push
// rules, device risk signals, native shells and the audit trail wiring.
$root = dirname(__DIR__, 2);

// ------------------------------------------------------------ passkeys
foreach (['es256' => WebAuthnVerifier::ALG_ES256, 'rs256' => WebAuthnVerifier::ALG_RS256] as $name => $algorithm) {
    $fixture = json_decode((string) file_get_contents($root . '/tests/fixtures/webauthn-' . $name . '.json'), true);
    $origin = $fixture['origin'];
    $registered = WebAuthnVerifier::verifyRegistration($fixture['reg'], $fixture['challenge1'], $origin, 'localhost');
    assert_same($algorithm, $registered['algorithm'], "The {$name} passkey is registered with its algorithm.");
    $count = WebAuthnVerifier::verifyAssertion($fixture['asr'], $fixture['challenge2'], $origin, 'localhost', $registered['public_key_pem'], $registered['sign_count']);
    $count = WebAuthnVerifier::verifyAssertion($fixture['asr2'], $fixture['challenge3'], $origin, 'localhost', $registered['public_key_pem'], $count);
    assert_true($count > $registered['sign_count'], "The {$name} signature counter advances.");

    $rejected = static function (callable $call): bool {
        try {
            $call();
        } catch (RuntimeException) {
            return true;
        }
        return false;
    };
    assert_true($rejected(fn () => WebAuthnVerifier::verifyRegistration($fixture['reg'], $fixture['challenge2'], $origin, 'localhost')), 'Registration with another challenge is rejected.');
    assert_true($rejected(fn () => WebAuthnVerifier::verifyAssertion($fixture['asr2'], $fixture['challenge3'], 'https://phishing.example', 'localhost', $registered['public_key_pem'], 0)), 'An assertion made for another origin is rejected.');
    assert_true($rejected(fn () => WebAuthnVerifier::verifyAssertion($fixture['asr2'], $fixture['challenge3'], $origin, 'tokenhub.example.com', $registered['public_key_pem'], 0)), 'An assertion for another relying party is rejected.');
    assert_true($rejected(fn () => WebAuthnVerifier::verifyAssertion($fixture['asr'], $fixture['challenge2'], $origin, 'localhost', $registered['public_key_pem'], $count)), 'A replayed assertion (old counter) is rejected.');
    $tampered = $fixture['asr2'];
    $signature = WebAuthnVerifier::unb64($tampered['response']['signature']);
    $signature[12] = chr(ord($signature[12]) ^ 0x01);
    $tampered['response']['signature'] = WebAuthnVerifier::b64($signature);
    assert_true($rejected(fn () => WebAuthnVerifier::verifyAssertion($tampered, $fixture['challenge3'], $origin, 'localhost', $registered['public_key_pem'], 0)), 'A tampered signature is rejected.');
}
assert_same(null, WebAuthnVerifier::rpIdFor('10.0.0.8'), 'Passkeys are not offered on IP addresses.');
assert_same('tokenhub.example.com', WebAuthnVerifier::rpIdFor('TokenHub.example.com'), 'The relying party is the host name.');

// ---------------------------------------------------------- push rules
$at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable('2026-10-30 ' . $time, new DateTimeZone('Asia/Shanghai'));
assert_same(false, AppPushService::allowedByRules('info', 'warning', null, null, null), 'Info alerts are skipped when only warnings and above are wanted.');
assert_same(true, AppPushService::allowedByRules('warning', 'warning', null, null, null), 'Warnings are pushed by default.');
assert_same(false, AppPushService::allowedByRules('warning', 'critical', null, null, null), 'Only critical alerts when chosen.');
assert_same(false, AppPushService::allowedByRules('warning', 'info', '22:00', '07:00', 'Asia/Shanghai', $at('23:30:00')), 'Quiet hours across midnight hold back warnings.');
assert_same(false, AppPushService::allowedByRules('warning', 'info', '22:00', '07:00', 'Asia/Shanghai', $at('06:59:00')), 'Quiet hours end at the end time.');
assert_same(true, AppPushService::allowedByRules('warning', 'info', '22:00', '07:00', 'Asia/Shanghai', $at('07:00:00')), 'After quiet hours warnings come through again.');
assert_same(true, AppPushService::allowedByRules('critical', 'info', '22:00', '07:00', 'Asia/Shanghai', $at('23:30:00')), 'Critical alerts ignore quiet hours.');
assert_same(false, AppPushService::allowedByRules('warning', 'info', '12:00', '13:00', 'Asia/Shanghai', $at('12:30:00')), 'Daytime quiet hours work too.');

// ------------------------------------------------------- device risk
$iphoneSafari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
$windowsChrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
assert_same([], AppDeviceAuthService::riskSignals(['created_ip' => '10.1.2.3', 'last_ip' => '10.1.9.9', 'user_agent' => $iphoneSafari, 'last_user_agent' => str_replace('18_0', '18_1', $iphoneSafari)]), 'Same network and an updated browser are not flagged.');
assert_same(['network_changed'], AppDeviceAuthService::riskSignals(['created_ip' => '10.1.2.3', 'last_ip' => '203.0.113.7', 'user_agent' => $iphoneSafari, 'last_user_agent' => $iphoneSafari]), 'Another network is flagged.');
assert_same(['client_changed'], AppDeviceAuthService::riskSignals(['created_ip' => '10.1.2.3', 'last_ip' => '10.1.2.4', 'user_agent' => $iphoneSafari, 'last_user_agent' => $windowsChrome]), 'Other client software is flagged.');

// ------------------------------------------------------------- wiring
$audit = (string) file_get_contents($root . '/app/Services/AuditService.php');
assert_true(str_contains($audit, "'channel' =>") && str_contains($audit, "'device_id' =>"), 'Audit records carry the channel and APP device (APP-009).');
$migration = (string) file_get_contents($root . '/database/migrations/2026_10_31_000004_admin_app_security.sql');
foreach (["table_name = 'audit_logs' AND column_name = 'channel'", 'admin_app_passkeys', 'webauthn_challenge', 'min_severity', 'last_user_agent'] as $needle) {
    assert_true(str_contains($migration, $needle), 'The APP security migration covers ' . $needle . '.');
}
$controller = (string) file_get_contents($root . '/app/Http/Controllers/AppAuthController.php');
assert_true(str_contains($controller, "function managedAdmin") && str_contains($controller, "'super_admin'"), 'Only super administrators manage other administrators\' APP devices (APP-008).');
assert_true(str_contains($controller, "registerPasskey") && str_contains($controller, "ADMIN_STEP_UP_REQUIRED"), 'Adding a passkey to a protected device needs a fresh verification.');

// --------------------------------------------------------- native shells
$bridge = (string) file_get_contents($root . '/public/app/assets/js/native-bridge.js');
assert_true(str_contains($bridge, 'TokenHubSecureStore') && str_contains($bridge, '__TAURI__'), 'The APP page talks to both native shells.');
$main = (string) file_get_contents($root . '/public/app/assets/js/main.js');
assert_true(str_starts_with($main, 'import "./native-bridge.js";'), 'The native bridge loads before sign-in code.');
$swift = (string) file_get_contents($root . '/native/capacitor/plugins/ios/TokenHubSecureStorePlugin.swift');
$java = (string) file_get_contents($root . '/native/capacitor/plugins/android/TokenHubSecureStorePlugin.java');
$rust = (string) file_get_contents($root . '/native/tauri/src-tauri/src/main.rs');
assert_true(str_contains($swift, 'kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly') && str_contains($swift, 'callerOrigin'), 'iOS keeps the credential in the Keychain, scoped to the calling origin.');
assert_true(str_contains($java, 'EncryptedSharedPreferences') && str_contains($java, 'callerOrigin'), 'Android keeps the credential encrypted by the Keystore, scoped to the calling origin.');
assert_true(str_contains($rust, 'fn caller_key') && str_contains($rust, 'keyring'), 'The desktop shell derives the keychain entry from the calling page, not from page input.');
