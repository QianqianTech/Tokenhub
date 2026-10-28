<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class NetworkInterfaceIpService
{
    /**
     * @return array{ips: list<string>, primary_ip: string|null, source: string}
     */
    public function candidates(): array
    {
        $configured = $this->stringList((string) getenv('TOKENHUB_CERTIFICATE_IPS'));
        if ($configured !== []) {
            return $this->result($configured, 'TOKENHUB_CERTIFICATE_IPS');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $ips = $this->parseWindowsIpConfigOutput((string) $this->command('ipconfig'));
            return $this->result($ips, 'ipconfig');
        }

        $ips = $this->parseLinuxIpOutput((string) $this->command('ip -o -4 addr show scope global 2>/dev/null'));
        if ($ips !== []) {
            return $this->result($ips, 'ip');
        }

        return $this->result($this->parseHostnameOutput((string) $this->command('hostname -I 2>/dev/null')), 'hostname');
    }

    /**
     * @return list<string>
     */
    public function parseLinuxIpOutput(string $output): array
    {
        $ips = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (!preg_match('/^\d+:\s+([^\s:]+).*?\binet\s+([0-9.]+)\//', trim($line), $match)) {
                continue;
            }
            $interface = strtolower($match[1]);
            if ($this->isIgnoredInterface($interface)) {
                continue;
            }
            $ip = $match[2];
            if ($this->isAutoDiscoveredIpv4($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return list<string>
     */
    public function parseWindowsIpConfigOutput(string $output): array
    {
        $ips = [];
        $adapter = '';
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/adapter\s+(.+):$/i', trim($line), $match)) {
                $adapter = strtolower($match[1]);
                continue;
            }
            if ($this->isIgnoredInterface($adapter)) {
                continue;
            }
            if (!preg_match('/IPv4[^:]*:\s*([0-9.]+)/i', $line, $match)) {
                continue;
            }
            $ip = $match[1];
            if ($this->isAutoDiscoveredIpv4($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return list<string>
     */
    private function parseHostnameOutput(string $output): array
    {
        $items = preg_split('/[\s,;]+/', $output) ?: [];

        return array_values(array_unique(array_filter(
            array_map('trim', $items),
            fn (string $item): bool => $this->isAutoDiscoveredIpv4($item)
        )));
    }

    /**
     * @param list<string> $ips
     * @return array{ips: list<string>, primary_ip: string|null, source: string}
     */
    private function result(array $ips, string $source): array
    {
        $valid = array_values(array_unique(array_filter(
            $ips,
            fn (string $ip): bool => $this->isPrivateIpv4($ip)
        )));

        return [
            'ips' => $valid,
            'primary_ip' => $valid[0] ?? null,
            'source' => $source,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(string $value): array
    {
        $items = preg_split('/[\s,;]+/', $value) ?: [];

        return array_values(array_unique(array_filter(
            array_map('trim', $items),
            fn (string $item): bool => $this->isPrivateIpv4($item)
        )));
    }

    private function isIgnoredInterface(string $name): bool
    {
        foreach (['lo', 'loopback', 'docker', 'br-', 'veth', 'virbr', 'vmnet', 'wsl', 'hyper-v'] as $prefix) {
            if (str_starts_with($name, $prefix) || str_contains($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateIpv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.')) {
            return true;
        }
        if (!preg_match('/^172\.(\d+)\./', $ip, $match)) {
            return false;
        }

        $second = (int) $match[1];
        return $second >= 16 && $second <= 31;
    }

    private function isAutoDiscoveredIpv4(string $ip): bool
    {
        if (!$this->isPrivateIpv4($ip)) {
            return false;
        }

        return !preg_match('/^172\.(1[7-9]|2\d|3[0-1])\./', $ip);
    }

    private function command(string $command): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        return @shell_exec($command) ?: null;
    }
}
