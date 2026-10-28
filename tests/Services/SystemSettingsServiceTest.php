<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\SystemSettingsService;

$serviceSource = file_get_contents(__DIR__ . '/../../app/Services/SystemSettingsService.php') ?: '';
assert_true(str_contains($serviceSource, "'firewall_settings'"), 'SystemSettingsService should define a typed firewall settings section.');
assert_true(str_contains($serviceSource, "auto_publish"), 'Firewall settings should expose auto_publish.');
assert_true(str_contains($serviceSource, 'WafService())->publish'), 'Firewall settings auto_publish should attempt WAF publication.');

$events = [];
$service = new SystemSettingsService(static function (array $event) use (&$events): void {
    $events[] = $event;
});

$defaults = $service->defaults();
assert_same(14, $defaults['tls_certificates']['threshold_days'], 'TLS certificate threshold should default to 14 days.');
assert_same('TokenHub', $defaults['brand_identity']['product_name'], 'Product name should default to TokenHub.');
assert_same('', $defaults['brand_identity']['logo_url'], 'Logo URL should default to empty.');
assert_same([], $defaults['entry_security']['admin_allowed_cidrs'], 'Admin CIDR defaults should be empty.');
assert_same(true, $defaults['firewall_settings']['enabled'], 'Firewall settings should default to enabled.');
assert_same(false, $defaults['firewall_settings']['auto_publish'], 'Firewall settings should not auto-publish by default.');
assert_same(true, $defaults['firewall_settings']['auto_allow_local_private_ips'], 'Firewall settings should keep local private IP access enabled by default.');
assert_same(5, $defaults['firewall_settings']['login_failure_threshold'], 'Firewall login failure threshold should default to 5.');
assert_same(300, $defaults['retention_export']['export_download_ttl_seconds'], 'Export download TTL should default to 300 seconds.');
assert_same(1000, $defaults['alert_thresholds']['queue_backlog_count'], 'Queue backlog threshold should default to 1000.');
assert_same(100, $defaults['rag_package_upload']['max_package_mb'], 'RAG image package upload limit should default to 100MB.');

assert_true($service->isKnownSection('waf_detection_settings'), 'WAF detection should be a typed settings section.');
$normalizedDetection = $service->normalizeSection('waf_detection_settings', [
    'enabled' => 'true',
    'mode' => 'block',
    'block_threshold' => 80,
    'max_inspect_bytes' => 9999999,
    'max_field_count' => 0,
    'max_field_bytes' => -1,
    'excluded_paths' => ['/health', 'bad'],
]);
assert_same('block', $normalizedDetection['mode'], 'WAF detection mode should accept explicit block mode.');
assert_same(80, $normalizedDetection['block_threshold'], 'WAF detection threshold should accept valid configured values.');
assert_same(65536, $normalizedDetection['max_inspect_bytes'], 'WAF detection bytes should clamp to the safe maximum.');
assert_same(128, $normalizedDetection['max_field_count'], 'Invalid field count should fall back to the default.');
assert_same(4096, $normalizedDetection['max_field_bytes'], 'Invalid field byte size should fall back to the default.');
assert_same(['/health'], $normalizedDetection['excluded_paths'], 'WAF detection exclusions should contain only absolute paths.');

$normalizedTls = $service->normalizeSection('tls_certificates', [
    'threshold_days' => -1,
    'certificate_mode' => 'internal_ip',
    'domain_names' => "api.example.com\nadmin.example.com",
    'ip_addresses' => "10.10.0.20\n192.168.1.10",
    'auto_renewal' => 'true',
    'renewal_provider' => 'caddy_internal',
    'certificate_paths' => "/srv/caddy-data/caddy/certificates\n/etc/tokenhub/certs/fullchain.pem",
    'certificates' => [
        ['name' => 'main', 'domains' => ['example.com'], 'issuer' => 'internal-ca', 'expires_at' => '2026-07-09 00:00:00', 'status' => 'active'],
        ['name' => '', 'expires_at' => ''],
    ],
]);
assert_same(14, $normalizedTls['threshold_days'], 'Invalid TLS threshold should fall back to default.');
assert_same('internal_ip', $normalizedTls['certificate_mode'], 'TLS settings should keep internal IP certificate mode.');
assert_same(['api.example.com', 'admin.example.com'], $normalizedTls['domain_names'], 'TLS settings should normalize domain names.');
assert_same(['10.10.0.20', '192.168.1.10'], $normalizedTls['ip_addresses'], 'TLS settings should normalize IP addresses.');
assert_same(true, $normalizedTls['auto_renewal'], 'TLS settings should normalize auto renewal.');
assert_same('caddy_internal', $normalizedTls['renewal_provider'], 'TLS settings should keep internal Caddy renewal provider.');
assert_same(['/srv/caddy-data/caddy/certificates', '/etc/tokenhub/certs/fullchain.pem'], $normalizedTls['certificate_paths'], 'TLS settings should normalize certificate paths.');
assert_same(1, count($normalizedTls['certificates']), 'Invalid certificates should be dropped.');

$normalizedPublicIpTls = $service->normalizeSection('tls_certificates', [
    'certificate_mode' => 'public_ip',
    'ip_addresses' => '8.8.8.8',
    'auto_renewal' => 'true',
    'renewal_provider' => 'letsencrypt_ip',
    'renewal_check_interval_hours' => 12,
    'certificate_request' => [
        'status' => 'requested',
        'request_type' => 'public_ip',
        'targets' => ['8.8.8.8'],
        'issuer_mode' => 'letsencrypt_shortlived',
        'renewal_check_interval_hours' => 12,
    ],
]);
assert_same('public_ip', $normalizedPublicIpTls['certificate_mode'], 'TLS settings should preserve public IP certificate mode.');
assert_same('letsencrypt_ip', $normalizedPublicIpTls['renewal_provider'], 'TLS settings should preserve Let\'s Encrypt IP renewal provider.');
assert_same(12, $normalizedPublicIpTls['renewal_check_interval_hours'], 'TLS settings should preserve the public IP renewal interval.');
assert_same('public_ip', $normalizedPublicIpTls['certificate_request']['request_type'], 'TLS settings should preserve the public IP request type.');
assert_same('letsencrypt_shortlived', $normalizedPublicIpTls['certificate_request']['issuer_mode'], 'TLS settings should preserve the public IP issuer mode.');

$normalizedBrand = $service->normalizeSection('brand_identity', [
    'product_name' => '  TokenHub Pro  ',
    'admin_title' => '',
    'screen_title' => '  运营总览  ',
    'logo_url' => 'https://cdn.example.com/logo.png',
    'favicon_url' => 'javascript:alert(1)',
]);
assert_same('TokenHub Pro', $normalizedBrand['product_name'], 'Product name should be trimmed.');
assert_same('TokenHub 管理后台', $normalizedBrand['admin_title'], 'Empty admin title should fall back to default.');
assert_same('运营总览', $normalizedBrand['screen_title'], 'Screen title should be trimmed.');
assert_same('', $normalizedBrand['logo_url'], 'Remote logo URL should be rejected.');
assert_same('', $normalizedBrand['favicon_url'], 'Unsafe favicon URL should be rejected.');

$normalizedEntry = $service->normalizeSection('entry_security', [
    'admin_allowed_cidrs' => "10.0.0.0/8\n192.168.0.0/16",
    'api_key_cidr_enforced' => 'true',
    'masked_header_fields' => ['Authorization', '', 'Cookie'],
]);
assert_same(['10.0.0.0/8', '192.168.0.0/16'], $normalizedEntry['admin_allowed_cidrs'], 'CIDR text should normalize to a list.');
assert_same(true, $normalizedEntry['api_key_cidr_enforced'], 'Boolean-like values should normalize.');
assert_same(['Authorization', 'Cookie'], $normalizedEntry['masked_header_fields'], 'Empty mask fields should be removed.');

$normalizedFirewall = $service->normalizeSection('firewall_settings', [
    'enabled' => 'true',
    'auto_publish' => 'false',
    'auto_allow_local_private_ips' => 'false',
    'admin_allowed_cidrs' => "10.0.0.0/8\n192.168.0.0/16",
    'api_allowed_cidrs' => "172.16.0.0/12",
    'manual_blocked_cidrs' => "203.0.113.9\n198.51.100.0/24",
    'allowed_methods' => "GET\nPOST\nOPTIONS\nTRACE",
    'blocked_paths' => "/.env\n/.git/*\n/admin/debug/*",
    'sensitive_query_keys' => "token\nsecret\napi_key",
    'max_body_bytes' => 0,
    'login_failure_threshold' => 3,
]);
assert_same(true, $normalizedFirewall['enabled'], 'Firewall enabled flag should normalize to boolean true.');
assert_same(false, $normalizedFirewall['auto_publish'], 'Firewall auto-publish flag should normalize to boolean false.');
assert_same(false, $normalizedFirewall['auto_allow_local_private_ips'], 'Firewall local private IP access flag should normalize to boolean false.');
assert_same(['10.0.0.0/8', '192.168.0.0/16'], $normalizedFirewall['admin_allowed_cidrs'], 'Firewall admin CIDRs should normalize to a list.');
assert_same(['172.16.0.0/12'], $normalizedFirewall['api_allowed_cidrs'], 'Firewall API CIDRs should normalize to a list.');
assert_same(['203.0.113.9', '198.51.100.0/24'], $normalizedFirewall['manual_blocked_cidrs'], 'Firewall manual blocked CIDRs should normalize to a list.');
assert_same(['GET', 'POST', 'OPTIONS', 'TRACE'], $normalizedFirewall['allowed_methods'], 'Firewall allowed methods should normalize to uppercase method names.');
assert_same(['/.env', '/.git/*', '/admin/debug/*'], $normalizedFirewall['blocked_paths'], 'Firewall blocked paths should normalize to a list.');
assert_same(['token', 'secret', 'api_key'], $normalizedFirewall['sensitive_query_keys'], 'Firewall sensitive query keys should normalize to a list.');
assert_same(10485760, $normalizedFirewall['max_body_bytes'], 'Invalid body limit should fall back to default.');
assert_same(3, $normalizedFirewall['login_failure_threshold'], 'Firewall login failure threshold should accept configured values.');

$normalizedRetention = $service->normalizeSection('retention_export', [
    'export_download_ttl_seconds' => 1,
    'export_file_retention_days' => 0,
    'backup_retention_days' => 9999,
    'cleanup_batch_size' => 0,
]);
assert_same(60, $normalizedRetention['export_download_ttl_seconds'], 'Download TTL should be clamped to minimum.');
assert_same(7, $normalizedRetention['export_file_retention_days'], 'Export retention should fall back when invalid.');
assert_same(3650, $normalizedRetention['backup_retention_days'], 'Backup retention should be clamped to maximum.');
assert_same(100, $normalizedRetention['cleanup_batch_size'], 'Cleanup batch size should fall back when invalid.');

$normalizedAlerts = $service->normalizeSection('alert_thresholds', [
    'waf_spike_count' => 0,
    'provider_error_rate' => 2,
    'provider_min_requests' => 0,
]);
assert_same(10, $normalizedAlerts['waf_spike_count'], 'WAF threshold should fall back when invalid.');
assert_same(1.0, $normalizedAlerts['provider_error_rate'], 'Provider error rate should clamp to 1.0.');
assert_same(5, $normalizedAlerts['provider_min_requests'], 'Provider minimum requests should fall back when invalid.');

$normalizedRagPackage = $service->normalizeSection('rag_package_upload', [
    'max_package_mb' => 0,
    'allowed_image_extensions' => "jpg\npng\nsvg\nwebp",
]);
assert_same(100, $normalizedRagPackage['max_package_mb'], 'Invalid RAG package size should fall back to default.');
assert_same(['jpg', 'png', 'webp'], $normalizedRagPackage['allowed_image_extensions'], 'RAG package extensions should keep only supported image formats.');

assert_true(!$service->isKnownSection('unknown'), 'Unknown settings sections should be rejected.');

$checkEvents = $service->checkCertificateAlerts([
    'threshold_days' => 30,
    'certificates' => [
        ['name' => 'soon', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)],
    ],
]);
assert_same(1, count($checkEvents), 'Certificate check should return expiring certificate events.');
