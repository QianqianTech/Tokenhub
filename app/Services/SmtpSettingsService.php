<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class SmtpSettingsService
{
    public function defaults(): array
    {
        return [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'encryption' => 'starttls',
            'auth_enabled' => true,
            'username' => '',
            'from_address' => '',
            'from_name' => 'TokenHub',
            'public_url' => rtrim((string) App::config()->get('app.url', ''), '/'),
            'timeout_seconds' => 10,
            'reset_ttl_minutes' => 30,
        ];
    }

    public function settings(): array
    {
        return $this->present($this->stored());
    }

    public function save(array $data): array
    {
        $this->requireSuperAdmin();
        $current = $this->stored();
        $values = $this->normalize($data, $current);
        $encrypted = (string) ($current['encrypted_password'] ?? '');
        if (($data['clear_password'] ?? false) === true) {
            $encrypted = '';
        }
        if (array_key_exists('password', $data)) {
            if (!is_string($data['password']) || strlen($data['password']) > 1024) {
                abort_json('SMTP_INVALID_CONFIG', 'SMTP 密码格式不正确', 422);
            }
            if ($data['password'] !== '') {
                $encrypted = Crypto::encrypt($data['password']);
            }
        }
        if ($values['enabled'] && $values['auth_enabled'] && ($values['username'] === '' || $encrypted === '')) {
            abort_json('SMTP_INVALID_CONFIG', '启用 SMTP 认证需要账号和密码或授权码', 422);
        }
        $values['encrypted_password'] = $encrypted;
        App::db()->execute(
            'INSERT INTO admin_smtp_settings (id, settings_value) VALUES (1, ?) ON DUPLICATE KEY UPDATE settings_value = VALUES(settings_value)',
            [json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        $public = $this->present($values);
        (new AuditService())->write('settings_center.update.smtp', 'admin_smtp_settings', 1, $public);
        return $public;
    }

    /** Credentials are revealed only to the SMTP transport. */
    public function transportSettings(): array
    {
        $stored = $this->stored();
        $settings = $this->normalize($stored, $this->defaults());
        if (!$settings['enabled']) {
            abort_json('SMTP_NOT_CONFIGURED', '邮件服务尚未启用', 503);
        }
        $settings['password'] = Crypto::decrypt((string) ($stored['encrypted_password'] ?? ''));
        if ($settings['auth_enabled'] && ($settings['username'] === '' || $settings['password'] === '')) {
            abort_json('SMTP_NOT_CONFIGURED', '邮件服务认证信息不完整', 503);
        }
        return $settings;
    }

    /**
     * Whether the login page may offer "忘记密码": SMTP is enabled and complete
     * enough to send a reset mail. Public (unauthenticated) callers learn only
     * this boolean, never any part of the configuration.
     */
    public function recoveryEnabled(): bool
    {
        try {
            $stored = $this->stored();
            $settings = $this->normalize($stored, $this->defaults());
        } catch (\Throwable) {
            return false;
        }
        if (!$settings['enabled'] || $settings['host'] === '' || $settings['from_address'] === '') {
            return false;
        }

        return !$settings['auth_enabled'] || ($settings['username'] !== '' && (string) ($stored['encrypted_password'] ?? '') !== '');
    }

    public function requireSuperAdmin(): void
    {
        if ((App::context()->adminUser()['role'] ?? '') !== 'super_admin') {
            abort_json('ADMIN_PERMISSION_DENIED', '仅超级管理员可以配置或测试邮件服务', 403);
        }
    }

    private function stored(): array
    {
        $row = App::db()->first('SELECT settings_value FROM admin_smtp_settings WHERE id = 1');
        $decoded = json_decode((string) ($row['settings_value'] ?? '{}'), true);
        return array_replace($this->defaults(), is_array($decoded) ? $decoded : []);
    }

    private function present(array $values): array
    {
        $public = array_intersect_key($values, $this->defaults());
        $public['password_set'] = !empty($values['encrypted_password']);
        return $public;
    }

    public function normalize(array $data, ?array $current = null): array
    {
        $current ??= $this->defaults();
        $values = array_replace(array_intersect_key($current, $this->defaults()), array_intersect_key($data, $this->defaults()));
        foreach (['enabled', 'auth_enabled'] as $field) {
            if (!is_bool($values[$field])) {
                abort_json('SMTP_INVALID_CONFIG', '邮件开关格式不正确', 422);
            }
        }
        foreach (['host', 'username', 'from_address', 'from_name', 'public_url', 'encryption'] as $field) {
            if (!is_string($values[$field]) || preg_match('/[\r\n\x00]/', $values[$field]) === 1) {
                abort_json('SMTP_INVALID_CONFIG', '邮件配置包含无效字符', 422);
            }
            $values[$field] = trim($values[$field]);
        }
        $values['from_address'] = strtolower($values['from_address']);
        $values['public_url'] = rtrim($values['public_url'], '/');
        foreach (['port' => [1, 65535], 'timeout_seconds' => [1, 30], 'reset_ttl_minutes' => [5, 60]] as $field => [$min, $max]) {
            if (filter_var($values[$field], FILTER_VALIDATE_INT) === false || (int) $values[$field] < $min || (int) $values[$field] > $max) {
                abort_json('SMTP_INVALID_CONFIG', '邮件端口、超时或有效期超出允许范围', 422);
            }
            $values[$field] = (int) $values[$field];
        }
        if (!in_array($values['encryption'], ['starttls', 'ssl', 'none'], true) || strlen($values['username']) > 254 || strlen($values['from_name']) > 240) {
            abort_json('SMTP_INVALID_CONFIG', '邮件加密方式或账号格式不正确', 422);
        }
        $host = $values['host'];
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/iD', $host) !== 1) {
            abort_json('SMTP_INVALID_CONFIG', 'SMTP 服务器只需填写域名或 IP 地址', 422);
        }
        if ($values['from_address'] !== '' && (strlen($values['from_address']) > 254 || filter_var($values['from_address'], FILTER_VALIDATE_EMAIL) === false)) {
            abort_json('SMTP_INVALID_CONFIG', '发件邮箱格式不正确', 422);
        }
        $url = parse_url($values['public_url']);
        if ($values['public_url'] !== '' && (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || preg_match('/[\s\\\\]/', $values['public_url']) === 1)) {
            abort_json('SMTP_INVALID_CONFIG', '平台访问地址需要完整的 HTTP 或 HTTPS 地址', 422);
        }
        if ($values['enabled'] && ($host === '' || $values['from_address'] === '' || $values['public_url'] === '')) {
            abort_json('SMTP_INVALID_CONFIG', '请填写 SMTP 服务器、发件邮箱和平台访问地址', 422);
        }
        return $values;
    }
}
