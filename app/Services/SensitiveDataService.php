<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class SensitiveDataService
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function sanitizeRow(string $table, array $row): array
    {
        if ($table === 'providers') {
            $row['secret_status'] = empty($row['encrypted_secret']) ? 'empty' : 'configured';
            unset($row['encrypted_secret']);
            if (array_key_exists('default_headers', $row) && $row['default_headers'] !== null) {
                $vault = new ProviderHeaderVault();
                $row['sensitive_header_plaintext'] = $vault->plaintextSensitiveHeaders(is_string($row['default_headers']) || is_array($row['default_headers']) ? $row['default_headers'] : null);
                $row['default_headers'] = json_encode($vault->maskForDisplay(is_string($row['default_headers']) || is_array($row['default_headers']) ? $row['default_headers'] : null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if ($table === 'api_keys') {
            unset($row['key_hash']);
            if (isset($row['key_prefix'], $row['key_suffix'])) {
                $row['display'] = $row['key_prefix'] . '****' . $row['key_suffix'];
            }
        }

        if ($table === 'users') {
            unset($row['password_hash']);
        }

        if ($table === 'admin_sessions') {
            unset($row['session_hash'], $row['csrf_token_hash']);
        }

        if ($table === 'usage_contents') {
            unset($row['encrypted_input'], $row['encrypted_output']);
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function sanitizeRows(string $table, array $rows): array
    {
        return array_map(fn (array $row): array => $this->sanitizeRow($table, $row), $rows);
    }
}
