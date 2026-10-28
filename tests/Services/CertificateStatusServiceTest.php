<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\CertificateStatusService;

function test_certificate_pem(string $commonName, string $san, int $days): string
{
    $config = tempnam(sys_get_temp_dir(), 'tokenhub-openssl-');
    file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=v3_req\nprompt=no\n[dn]\nCN={$commonName}\n[v3_req]\nsubjectAltName={$san}\n");
    $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $commonName], $privateKey, ['config' => $config, 'digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $privateKey, $days, ['config' => $config, 'x509_extensions' => 'v3_req', 'digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $pem);
    unlink($config);

    return $pem;
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tokenhub-cert-status-' . bin2hex(random_bytes(4));
mkdir($root);
$caddyRoot = $root . DIRECTORY_SEPARATOR . 'caddy' . DIRECTORY_SEPARATOR . 'certificates' . DIRECTORY_SEPARATOR . 'local';
mkdir($caddyRoot, 0777, true);
file_put_contents($caddyRoot . DIRECTORY_SEPARATOR . 'domain.crt', test_certificate_pem('api.example.com', 'DNS:api.example.com,DNS:admin.example.com', 45));
file_put_contents($caddyRoot . DIRECTORY_SEPARATOR . 'ip.crt', test_certificate_pem('10.10.0.20', 'IP:10.10.0.20', 365));
file_put_contents($root . DIRECTORY_SEPARATOR . 'manual.crt', test_certificate_pem('manual.example.com', 'DNS:manual.example.com', 90));

$service = new CertificateStatusService([$root]);
$status = $service->status();

assert_same(3, count($status['certificates']), 'Certificate status should parse every PEM certificate in configured paths.');
assert_same(3, $status['summary']['total'], 'Certificate summary should count parsed certificates.');
assert_same(2, $status['summary']['domain_certificates'], 'Certificate summary should count domain certificates.');
assert_same(1, $status['summary']['ip_certificates'], 'Certificate summary should count IP certificates.');

$byName = [];
foreach ($status['certificates'] as $certificate) {
    $byName[$certificate['name']] = $certificate;
}

assert_same('domain', $byName['domain.crt']['certificate_type'], 'DNS SAN certificates should be classified as domain certificates.');
assert_same(['api.example.com', 'admin.example.com'], $byName['domain.crt']['domains'], 'DNS SANs should be exposed as domains.');
assert_same([], $byName['domain.crt']['ip_addresses'], 'Domain certificates should not expose IP addresses.');
assert_same('ip', $byName['ip.crt']['certificate_type'], 'IP SAN certificates should be classified as IP certificates.');
assert_same(['10.10.0.20'], $byName['ip.crt']['ip_addresses'], 'IP SANs should be exposed as IP addresses.');
assert_true($byName['ip.crt']['auto_renewal'] === true, 'Caddy certificates should be marked as auto-renewal managed.');
assert_true($byName['manual.crt']['auto_renewal'] === false, 'Plain certificate files should not be reported as auto-renewal managed.');
assert_true($byName['domain.crt']['days_remaining'] >= 40, 'Certificate status should expose days remaining.');

array_map('unlink', glob($caddyRoot . DIRECTORY_SEPARATOR . '*') ?: []);
rmdir($caddyRoot);
rmdir(dirname($caddyRoot));
rmdir(dirname(dirname($caddyRoot)));
array_map('unlink', glob($root . DIRECTORY_SEPARATOR . '*') ?: []);
rmdir($root);
