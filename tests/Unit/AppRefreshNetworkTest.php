<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AppDeviceAuthService;

// SEC-005: the same-UA refresh recovery path also requires the same network
// as the device's last refresh (/24 IPv4, /48 IPv6).
$cases = [
    ['203.0.113.10', '203.0.113.200', true],
    ['203.0.113.10', '203.0.114.10', false],
    ['10.0.0.1', '10.0.0.1', true],
    ['::ffff:198.51.100.7', '198.51.100.99', true],
    ['::ffff:198.51.100.7', '::ffff:198.51.101.7', false],
    ['2001:db8:abcd:1::1', '2001:db8:abcd:ffff::2', true],
    ['2001:db8:abcd:1::1', '2001:db8:abce:1::1', false],
    ['2001:db8::1', '203.0.113.10', false],
    ['', '203.0.113.10', false],
    ['not-an-ip', '203.0.113.10', false],
    ['203.0.113.10', '', false],
];
foreach ($cases as [$known, $client, $expected]) {
    assert_same($expected, AppDeviceAuthService::sameNetwork($known, $client), sprintf('sameNetwork(%s, %s)', $known, $client));
}

$service = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AppDeviceAuthService.php');
assert_true(str_contains($service, "self::sameNetwork((string) (\$device['last_ip'] ?? ''), \$clientIp)"), 'Recovery checks the network of the last refresh.');
assert_true(str_contains($service, "['recovered' => true]"), 'A recovered refresh is flagged for the APP.');
$controller = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/AppAuthController.php');
assert_true(str_contains($controller, "\$payload['recovered'] = true"), 'The refresh response carries the recovered flag.');

echo "OK\n";
