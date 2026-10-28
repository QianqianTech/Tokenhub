<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\NetworkInterfaceIpService;

$service = new NetworkInterfaceIpService();

$linuxOutput = implode("\n", [
    '1: lo    inet 127.0.0.1/8 scope host lo',
    '2: eth0    inet 192.168.10.25/24 brd 192.168.10.255 scope global eth0',
    '3: ens18    inet 10.0.2.15/24 brd 10.0.2.255 scope global ens18',
    '4: docker0    inet 172.18.0.1/16 brd 172.18.255.255 scope global docker0',
    '5: br-9ad7    inet 172.19.0.1/16 brd 172.19.255.255 scope global br-9ad7',
    '6: veth0    inet 172.20.0.5/16 brd 172.20.255.255 scope global veth0',
    '7: wan0    inet 8.8.8.8/24 brd 8.8.8.255 scope global wan0',
    '8: eth0    inet 172.18.0.4/16 brd 172.18.255.255 scope global eth0',
]);

assert_same(
    ['192.168.10.25', '10.0.2.15'],
    $service->parseLinuxIpOutput($linuxOutput),
    'NIC IP discovery should keep private service NICs and skip loopback, Docker, veth, bridge, and public IPs.'
);

$windowsOutput = implode("\n", [
    'Ethernet adapter Ethernet:',
    '   IPv4 Address. . . . . . . . . . . : 192.168.31.8',
    'Ethernet adapter vEthernet (DockerNAT):',
    '   IPv4 Address. . . . . . . . . . . : 172.18.0.1',
    'Wireless LAN adapter Wi-Fi:',
    '   IPv4 Address. . . . . . . . . . . : 10.10.1.44',
]);

assert_same(
    ['192.168.31.8', '10.10.1.44'],
    $service->parseWindowsIpConfigOutput($windowsOutput),
    'Windows NIC IP discovery should skip Docker virtual adapters and keep physical private adapter IPs.'
);

