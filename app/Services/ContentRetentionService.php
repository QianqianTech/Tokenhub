<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ContentRetentionService
{
    private const SETTING_KEY = 'content_retention.policy';
    private const MODES = ['none', 'summary', 'redacted', 'raw'];

    /**
     * @return array<string, mixed>
     */
    public function defaultPolicy(): array
    {
        return [
            'mode' => 'summary',
            'default_days' => 30,
            'max_days' => 180,
            'raw_allowed_roles' => ['super_admin'],
            'departments' => [],
            'users' => [],
            'api_keys' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function policy(): array
    {
        $row = App::db()->first('SELECT setting_value FROM system_settings WHERE setting_key = ?', [self::SETTING_KEY]);
        $value = $row['setting_value'] ?? null;
        $decoded = is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : null);

        return $this->normalizePolicy(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    public function savePolicy(array $policy): array
    {
        $policy = $this->normalizePolicy($policy);
        App::db()->execute(
            'INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP',
            [self::SETTING_KEY, json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'Content retention policy']
        );
        (new AuditService())->write('content_retention.update', 'system_settings', self::SETTING_KEY);

        return $policy;
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    public function normalizePolicy(array $policy): array
    {
        $default = $this->defaultPolicy();
        $policy = array_replace($default, $policy);
        $policy['mode'] = $this->validMode((string) ($policy['mode'] ?? $default['mode']));
        $policy['default_days'] = $this->positiveInt($policy['default_days'] ?? $default['default_days'], $default['default_days']);
        $policy['max_days'] = $this->positiveInt($policy['max_days'] ?? $default['max_days'], $default['max_days']);
        if ($policy['default_days'] > $policy['max_days']) {
            $policy['default_days'] = $policy['max_days'];
        }

        $roles = $policy['raw_allowed_roles'] ?? $default['raw_allowed_roles'];
        $policy['raw_allowed_roles'] = is_array($roles) ? array_values(array_unique(array_map('strval', $roles))) : $default['raw_allowed_roles'];

        foreach (['departments', 'users', 'api_keys'] as $scope) {
            $policy[$scope] = $this->normalizeScopePolicies($policy[$scope] ?? []);
        }

        return $policy;
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function effectivePolicy(array $policy, array $record): array
    {
        $policy = $this->normalizePolicy($policy);
        $selected = ['mode' => $policy['mode'], 'days' => $policy['default_days']];

        foreach ($this->departmentIdsFromPath((string) ($record['department_path'] ?? '')) as $departmentId) {
            if (isset($policy['departments'][(string) $departmentId])) {
                $selected = $policy['departments'][(string) $departmentId];
            }
        }

        $userId = (string) ($record['user_id'] ?? '');
        if ($userId !== '' && isset($policy['users'][$userId])) {
            $selected = $policy['users'][$userId];
        }

        $apiKeyId = (string) ($record['api_key_id'] ?? '');
        if ($apiKeyId !== '' && isset($policy['api_keys'][$apiKeyId])) {
            $selected = $policy['api_keys'][$apiKeyId];
        }

        $days = min($this->positiveInt($selected['days'] ?? $policy['default_days'], $policy['default_days']), (int) $policy['max_days']);

        return [
            'mode' => $this->validMode((string) ($selected['mode'] ?? $policy['mode'])),
            'days' => $days,
            'max_days' => (int) $policy['max_days'],
            'raw_allowed_roles' => $policy['raw_allowed_roles'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     * @return array<string, string|null>
     */
    public function prepareContent(string $mode, array $input, array $output): array
    {
        $mode = $this->validMode($mode);
        $summary = $mode === 'none' ? null : $this->summary($input, $output);

        $prepared = [
            'encrypted_input' => null,
            'encrypted_output' => null,
            'redacted_input' => null,
            'redacted_output' => null,
            'summary' => $summary,
        ];

        if ($mode === 'redacted' || $mode === 'raw') {
            $desensitizer = new DesensitizationService();
            $prepared['redacted_input'] = $this->encode($desensitizer->redact($input));
            $prepared['redacted_output'] = $this->encode($desensitizer->redact($output));
        }

        if ($mode === 'raw') {
            $prepared['encrypted_input'] = Crypto::encrypt($this->encode($input));
            $prepared['encrypted_output'] = Crypto::encrypt($this->encode($output));
        }

        return $prepared;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function writeForRecord(int $usageRecordId, array $record): void
    {
        $content = $record['content'] ?? null;
        if (!is_array($content)) {
            return;
        }

        $input = is_array($content['input'] ?? null) ? $content['input'] : [];
        $output = is_array($content['output'] ?? null) ? $content['output'] : [];
        $departmentPath = $this->departmentPath(isset($record['department_id']) ? (int) $record['department_id'] : null);
        $effective = $this->effectivePolicy($this->policy(), [
            ...$record,
            'department_path' => $departmentPath,
        ]);
        $prepared = $this->prepareContent((string) $effective['mode'], $input, $output);
        $expiresAt = $this->expiresAt((int) $effective['days']);

        App::db()->execute(
            'INSERT INTO usage_contents (usage_record_id, user_id, department_id, api_key_id, model_id, session_id, retention_mode, encrypted_input, encrypted_output, redacted_input, redacted_output, summary, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), department_id = VALUES(department_id), api_key_id = VALUES(api_key_id), model_id = VALUES(model_id), session_id = VALUES(session_id), retention_mode = VALUES(retention_mode), encrypted_input = VALUES(encrypted_input), encrypted_output = VALUES(encrypted_output), redacted_input = VALUES(redacted_input), redacted_output = VALUES(redacted_output), summary = VALUES(summary), expires_at = VALUES(expires_at), cleaned_at = NULL',
            [
                $usageRecordId,
                $record['user_id'] ?? null,
                $record['department_id'] ?? null,
                $record['api_key_id'] ?? null,
                $record['model_id'] ?? null,
                $content['session_id'] ?? $this->sessionId($input),
                $effective['mode'],
                $prepared['encrypted_input'],
                $prepared['encrypted_output'],
                $prepared['redacted_input'],
                $prepared['redacted_output'],
                $prepared['summary'],
                $expiresAt,
            ]
        );
    }

    private function validMode(string $mode): string
    {
        return in_array($mode, self::MODES, true) ? $mode : 'summary';
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $value = (int) $value;
        return $value > 0 ? $value : $default;
    }

    /**
     * @param mixed $policies
     * @return array<string, array{mode: string, days: int}>
     */
    private function normalizeScopePolicies(mixed $policies): array
    {
        if (!is_array($policies)) {
            return [];
        }

        $out = [];
        foreach ($policies as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $out[(string) $id] = [
                'mode' => $this->validMode((string) ($entry['mode'] ?? 'summary')),
                'days' => $this->positiveInt($entry['days'] ?? $entry['retention_days'] ?? 30, 30),
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function departmentIdsFromPath(string $path): array
    {
        preg_match_all('/\d+/', $path, $matches);
        return array_map('intval', $matches[0] ?? []);
    }

    private function departmentPath(?int $departmentId): string
    {
        if ($departmentId === null || $departmentId <= 0) {
            return '';
        }
        $row = App::db()->first('SELECT path FROM departments WHERE id = ?', [$departmentId]);
        return $row ? (string) $row['path'] : '';
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     */
    private function summary(array $input, array $output): string
    {
        $text = $this->encode(['input' => $input, 'output' => $output]);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 500);
        }

        return substr($text, 0, 500);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function expiresAt(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() + ($days * 86400));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function sessionId(array $input): ?string
    {
        foreach (['conversation_id', 'session_id'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key]) && (string) $input[$key] !== '') {
                return (string) $input[$key];
            }
        }

        $metadata = $input['metadata'] ?? null;
        if (is_array($metadata)) {
            foreach (['conversation_id', 'session_id'] as $key) {
                if (isset($metadata[$key]) && is_scalar($metadata[$key]) && (string) $metadata[$key] !== '') {
                    return (string) $metadata[$key];
                }
            }
        }

        return null;
    }
}
