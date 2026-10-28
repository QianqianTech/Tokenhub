<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\CertificateRequestService;

$service = new CertificateRequestService();

$domain = $service->build([
    'request_type' => 'domain',
    'domain_names' => "api.example.com\nadmin.example.com",
]);
assert_same('domain', $domain['request_type'], 'Domain certificate requests should keep the domain request type.');
assert_same('domain', $domain['settings']['certificate_mode'], 'Domain requests should switch TLS settings to domain mode.');
assert_same(['api.example.com', 'admin.example.com'], $domain['settings']['domain_names'], 'Domain requests should normalize requested domains.');
assert_same([], $domain['settings']['ip_addresses'], 'Domain requests should clear requested IP addresses.');
assert_same('caddy_acme', $domain['settings']['renewal_provider'], 'Domain requests should use Caddy ACME renewal.');
assert_same(true, $domain['settings']['auto_renewal'], 'Domain requests should enable automatic renewal.');
assert_true(str_contains($domain['caddy_tls_fragment'], 'Caddy ACME'), 'Domain requests should generate a Caddy ACME fragment note.');

$ip = $service->build([
    'request_type' => 'internal_ip',
    'ip_addresses' => '10.10.0.20',
]);
assert_same('internal_ip', $ip['request_type'], 'IP certificate requests should keep the internal IP request type.');
assert_same('internal_ip', $ip['settings']['certificate_mode'], 'IP requests should switch TLS settings to internal IP mode.');
assert_same(['10.10.0.20'], $ip['settings']['ip_addresses'], 'IP requests should normalize one requested IP address.');
assert_same([], $ip['settings']['domain_names'], 'IP requests should clear requested domains.');
assert_same('caddy_internal', $ip['settings']['renewal_provider'], 'IP requests should use Caddy internal CA renewal.');
assert_true(str_contains($ip['caddy_tls_fragment'], 'tls internal'), 'IP requests should generate a Caddy internal TLS directive.');

$singleInternalIp = new ReflectionMethod($service, 'singleInternalIp');
assert_same('10.10.0.20', $singleInternalIp->invoke($service, ['10.10.0.20']), 'Internal IP requests should retain the one selected IP.');
assert_same(null, $singleInternalIp->invoke($service, ['10.10.0.20', '192.168.1.10']), 'Internal IP requests should reject multiple selected IPs.');

$requestType = new ReflectionMethod($service, 'requestType');
assert_same('public_ip', $requestType->invoke($service, 'public_ip'), 'Public IP requests should use a distinct request type.');

$publicIp = $service->build([
    'request_type' => 'public_ip',
    'ip_addresses' => '8.8.8.8',
]);
assert_same('public_ip', $publicIp['request_type'], 'Public IP requests should retain the public IP request type.');
assert_same('public_ip', $publicIp['settings']['certificate_mode'], 'Public IP requests should switch TLS settings to public IP mode.');
assert_same(['8.8.8.8'], $publicIp['settings']['ip_addresses'], 'Public IP requests should normalize requested public IP addresses.');
assert_same('letsencrypt_ip', $publicIp['settings']['renewal_provider'], 'Public IP requests should use Let\'s Encrypt IP renewal.');
assert_same(12, $publicIp['settings']['renewal_check_interval_hours'], 'Public IP requests should check renewal every 12 hours.');
assert_same('letsencrypt_shortlived', $publicIp['settings']['certificate_request']['issuer_mode'], 'Public IP requests should use the Let\'s Encrypt short-lived profile.');
assert_true(str_contains($publicIp['caddy_tls_fragment'], 'PUBLIC IP CERTIFICATE'), 'Public IP requests should generate a dedicated public IP certificate fragment.');

$publicIpReadiness = $service->publicIpReadiness('');
assert_same(false, $publicIpReadiness['ready'], 'Public IP requests should fail preflight when no Certbot command is configured.');
assert_true(str_contains($publicIpReadiness['message'], 'Certbot'), 'Public IP preflight should explain the missing Certbot requirement.');
