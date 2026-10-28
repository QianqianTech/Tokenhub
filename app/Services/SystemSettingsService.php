<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class SystemSettingsService
{
    private const SECTION_KEYS = [
        'tls_certificates' => 'tls_certificates',
        'brand_identity' => 'brand_identity',
        'entry_security' => 'entry_security',
        'retention_export' => 'retention_export',
        'alert_thresholds' => 'alert_thresholds',
        'rag_upload_review' => 'rag_upload_review',
        'rag_package_upload' => 'rag_package_upload',
        'rag_ai_image_processing' => 'rag_ai_image_processing',
        'rag_vectorization_monitoring' => 'rag_vectorization_monitoring',
    ];

    /**
     * @param null|callable(array<string, mixed>): void $alertWriter
     */
    public function __construct(private readonly mixed $alertWriter = null)
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function defaults(): array
    {
        return [
            'tls_certificates' => [
                'threshold_days' => 14,
                'certificate_mode' => 'domain',
                'domain_names' => [],
                'ip_addresses' => [],
                'auto_renewal' => true,
                'renewal_provider' => 'caddy_acme',
                'renewal_check_interval_hours' => 24,
                'certificate_paths' => [],
                'certificate_request' => [],
                'certificates' => [],
            ],
            'brand_identity' => [
                'product_name' => 'TokenHub',
                'admin_title' => 'TokenHub 管理后台',
                'screen_title' => 'TokenHub 运营大屏',
                'logo_url' => '',
                'favicon_url' => '',
            ],
            'entry_security' => [
                'admin_allowed_cidrs' => [],
                'api_allowed_cidrs' => [],
                'api_key_cidr_enforced' => true,
                'masked_header_fields' => ['Authorization', 'Cookie', 'X-Api-Key'],
                'masked_query_fields' => ['api_key', 'token', 'secret'],
            ],
            'retention_export' => [
                'export_download_ttl_seconds' => 300,
                'export_file_retention_days' => 7,
                'backup_retention_days' => 30,
                'cleanup_batch_size' => 100,
            ],
            'alert_thresholds' => [
                'provider_error_rate' => 0.3,
                'provider_error_window_minutes' => 5,
                'provider_min_requests' => 5,
                'queue_backlog_count' => 1000,
                'certificate_threshold_days' => 14,
            ],
            'rag_upload_review' => [
                'enabled' => false,
                'reviewer_roles' => ['admin', 'super_admin'],
                'allow_reviewer_own_upload' => false,
                'reject_blocks_vectorization' => true,
                'instructions' => '',
            ],
            'rag_package_upload' => [
                'max_package_mb' => 100,
                'allowed_image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            ],
            'rag_ai_image_processing' => [
                'enabled' => false,
                'vision_model_id' => 0,
                'prompt_profile' => 'default',
                'max_retries' => 2,
                'timeout_ms' => 60000,
            ],
            'rag_vectorization_monitoring' => [
                'success_rate_threshold' => 95.0,
                'window_hours' => 24,
                'consecutive_failure_threshold' => 5,
                'qdrant_health_check_enabled' => true,
            ],
        ];
    }

    public function isKnownSection(string $section): bool
    {
        return isset(self::SECTION_KEYS[$section]);
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsCenter(): array
    {
        $sections = [];
        foreach (array_keys(self::SECTION_KEYS) as $section) {
            $sections[$section] = $this->loadSection($section);
        }

        return [
            'sections' => $sections,
            'summary' => $this->summary($sections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadSection(string $section): array
    {
        $this->assertKnownSection($section);
        $row = App::db()->first('SELECT setting_value FROM system_settings WHERE setting_key = ?', [self::SECTION_KEYS[$section]]);
        $decoded = is_string($row['setting_value'] ?? null) ? json_decode((string) $row['setting_value'], true) : null;

        return $this->normalizeSection($section, is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveSection(string $section, array $data): array
    {
        $this->assertKnownSection($section);
        $this->requireSuperAdmin();
        $normalized = $this->normalizeSection($section, $data);
        App::db()->execute(
            'INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_at = CURRENT_TIMESTAMP',
            [
                self::SECTION_KEYS[$section],
                json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $this->description($section),
            ]
        );
        (new AuditService())->write('settings_center.update.' . $section, 'system_settings', self::SECTION_KEYS[$section], $normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeSection(string $section, array $data): array
    {
        $this->assertKnownSection($section);
        $default = $this->defaults()[$section];

        return match ($section) {
            'tls_certificates' => [
                'threshold_days' => $this->intRange($data['threshold_days'] ?? null, (int) $default['threshold_days'], 1, 365),
                'certificate_mode' => $this->choice($data['certificate_mode'] ?? null, (string) $default['certificate_mode'], ['domain', 'public_ip', 'internal_ip']),
                'domain_names' => $this->stringList($data['domain_names'] ?? $default['domain_names']),
                'ip_addresses' => $this->ipList($data['ip_addresses'] ?? $default['ip_addresses']),
                'auto_renewal' => $this->boolValue($data['auto_renewal'] ?? $default['auto_renewal']),
                'renewal_provider' => $this->choice($data['renewal_provider'] ?? null, (string) $default['renewal_provider'], ['caddy_acme', 'letsencrypt_ip', 'caddy_internal']),
                'renewal_check_interval_hours' => $this->intRange($data['renewal_check_interval_hours'] ?? null, (int) $default['renewal_check_interval_hours'], 1, 168),
                'certificate_paths' => $this->stringList($data['certificate_paths'] ?? $default['certificate_paths']),
                'certificate_request' => $this->certificateRequest($data['certificate_request'] ?? []),
                'certificates' => $this->certificates($data['certificates'] ?? []),
            ],
            'brand_identity' => [
                'product_name' => $this->textValue($data['product_name'] ?? null, (string) $default['product_name'], 60),
                'admin_title' => $this->textValue($data['admin_title'] ?? null, (string) $default['admin_title'], 80),
                'screen_title' => $this->textValue($data['screen_title'] ?? null, (string) $default['screen_title'], 80),
                'logo_url' => $this->localAssetUrl($data['logo_url'] ?? null),
                'favicon_url' => $this->localAssetUrl($data['favicon_url'] ?? null),
            ],
            'entry_security' => [
                'admin_allowed_cidrs' => $this->stringList($data['admin_allowed_cidrs'] ?? $default['admin_allowed_cidrs']),
                'api_allowed_cidrs' => $this->stringList($data['api_allowed_cidrs'] ?? $default['api_allowed_cidrs']),
                'api_key_cidr_enforced' => $this->boolValue($data['api_key_cidr_enforced'] ?? $default['api_key_cidr_enforced']),
                'masked_header_fields' => $this->stringList($data['masked_header_fields'] ?? $default['masked_header_fields']),
                'masked_query_fields' => $this->stringList($data['masked_query_fields'] ?? $default['masked_query_fields']),
            ],
            'retention_export' => [
                'export_download_ttl_seconds' => $this->intRange($data['export_download_ttl_seconds'] ?? null, (int) $default['export_download_ttl_seconds'], 60, 86400),
                'export_file_retention_days' => $this->intRange($data['export_file_retention_days'] ?? null, (int) $default['export_file_retention_days'], 1, 3650),
                'backup_retention_days' => $this->intRange($data['backup_retention_days'] ?? null, (int) $default['backup_retention_days'], 1, 3650),
                'cleanup_batch_size' => $this->intRange($data['cleanup_batch_size'] ?? null, (int) $default['cleanup_batch_size'], 1, 10000),
            ],
            'alert_thresholds' => [
                'provider_error_rate' => $this->floatRange($data['provider_error_rate'] ?? null, (float) $default['provider_error_rate'], 0.01, 1.0),
                'provider_error_window_minutes' => $this->intRange($data['provider_error_window_minutes'] ?? null, (int) $default['provider_error_window_minutes'], 1, 1440),
                'provider_min_requests' => $this->intRange($data['provider_min_requests'] ?? null, (int) $default['provider_min_requests'], 1, 100000),
                'queue_backlog_count' => $this->intRange($data['queue_backlog_count'] ?? null, (int) $default['queue_backlog_count'], 1, 1000000),
                'certificate_threshold_days' => $this->intRange($data['certificate_threshold_days'] ?? null, (int) $default['certificate_threshold_days'], 1, 365),
            ],
            'rag_upload_review' => [
                'enabled' => $this->boolValue($data['enabled'] ?? $default['enabled']),
                'reviewer_roles' => $this->reviewerRoles($data['reviewer_roles'] ?? $default['reviewer_roles']),
                'allow_reviewer_own_upload' => $this->boolValue($data['allow_reviewer_own_upload'] ?? $default['allow_reviewer_own_upload']),
                'reject_blocks_vectorization' => $this->boolValue($data['reject_blocks_vectorization'] ?? $default['reject_blocks_vectorization']),
                'instructions' => $this->textValue($data['instructions'] ?? null, '', 500),
            ],
            'rag_package_upload' => [
                'max_package_mb' => $this->intRange($data['max_package_mb'] ?? null, (int) $default['max_package_mb'], 1, 1024),
                'allowed_image_extensions' => $this->imageExtensions($data['allowed_image_extensions'] ?? $default['allowed_image_extensions']),
            ],
            'rag_ai_image_processing' => [
                'enabled' => $this->boolValue($data['enabled'] ?? $default['enabled']),
                'vision_model_id' => $this->intRange($data['vision_model_id'] ?? null, (int) $default['vision_model_id'], 0, PHP_INT_MAX),
                'prompt_profile' => $this->choice($data['prompt_profile'] ?? null, (string) $default['prompt_profile'], ['default', 'ocr_first', 'catalog', 'technical']),
                'max_retries' => $this->intRange($data['max_retries'] ?? null, (int) $default['max_retries'], 1, 10),
                'timeout_ms' => $this->intRange($data['timeout_ms'] ?? null, (int) $default['timeout_ms'], 1000, 300000),
            ],
            'rag_vectorization_monitoring' => [
                'success_rate_threshold' => $this->floatRange($data['success_rate_threshold'] ?? null, (float) $default['success_rate_threshold'], 1.0, 100.0),
                'window_hours' => $this->intRange($data['window_hours'] ?? null, (int) $default['window_hours'], 1, 720),
                'consecutive_failure_threshold' => $this->intRange($data['consecutive_failure_threshold'] ?? null, (int) $default['consecutive_failure_threshold'], 1, 1000),
                'qdrant_health_check_enabled' => $this->boolValue($data['qdrant_health_check_enabled'] ?? $default['qdrant_health_check_enabled']),
            ],
        };
    }

    /**
     * @param array<string, mixed>|null $settings
     * @return list<array<string, mixed>>
     */
    public function checkCertificateAlerts(?array $settings = null): array
    {
        $settings = $settings === null ? $this->loadSection('tls_certificates') : $this->normalizeSection('tls_certificates', $settings);
        $certificates = (new CertificateStatusService())->certificates((int) ($settings['threshold_days'] ?? 14));
        if ($certificates !== []) {
            return (new AlertService($this->alertWriter))->certificateExpiryAlerts(
                $certificates,
                null,
                (int) ($settings['threshold_days'] ?? 14)
            );
        }

        return (new AlertService($this->alertWriter))->certificateExpiryAlerts(
            $settings['certificates'] ?? [],
            null,
            (int) ($settings['threshold_days'] ?? 14)
        );
    }

    private function requireSuperAdmin(): void
    {
        $actor = App::context()->adminUser();
        if (!$actor) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }
        (new PermissionService())->requireCapability($actor, 'system.manage');
    }

    private function assertKnownSection(string $section): void
    {
        if (!$this->isKnownSection($section)) {
            abort_json('ADMIN_INVALID_SETTING_SECTION', 'Unknown settings section', 404);
        }
    }

    private function description(string $section): string
    {
        return match ($section) {
            'tls_certificates' => 'TLS certificate alert settings',
            'brand_identity' => '管理后台和运营大屏的标题、Logo 与浏览器图标设置',
            'entry_security' => 'Admin and API entry security settings',
            'retention_export' => 'Export and backup retention settings',
            'alert_thresholds' => 'Operational alert threshold settings',
            'rag_upload_review' => 'RAG user upload review settings',
            'rag_package_upload' => 'RAG image package upload settings',
            'rag_ai_image_processing' => 'RAG AI image annotation settings',
            'rag_vectorization_monitoring' => 'RAG vectorization success-rate monitoring settings',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    private function summary(array $sections): array
    {
        return [
            'configured_sections' => array_keys($sections),
            'certificate_count' => count($sections['tls_certificates']['certificates'] ?? []),
            'entry_restricted' => ($sections['entry_security']['admin_allowed_cidrs'] ?? []) !== [] || ($sections['entry_security']['api_allowed_cidrs'] ?? []) !== [],
            'log_retention_policy_count' => $this->safeCount('SELECT COUNT(*) count_value FROM log_retention_policies'),
            'open_alert_count' => $this->safeCount('SELECT COUNT(*) count_value FROM alert_events WHERE status = "open"'),
            'recent_alerts' => $this->safeRows('SELECT id, event_type, severity, message, status, created_at FROM alert_events ORDER BY id DESC LIMIT 5'),
        ];
    }

    private function safeCount(string $sql): int
    {
        try {
            return (int) (App::db()->first($sql)['count_value'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeRows(string $sql): array
    {
        try {
            return App::db()->select($sql);
        } catch (\Throwable) {
            return [];
        }
    }

    private function intRange(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        $int = (int) $value;
        if ($int <= 0) {
            return $default;
        }
        if ($int < $min) {
            return $min;
        }

        return min($max, $int);
    }

    private function floatRange(mixed $value, float $default, float $min, float $max): float
    {
        if (!is_numeric($value)) {
            return $default;
        }
        $float = (float) $value;
        if ($float < $min) {
            return $default;
        }

        return min($max, $float);
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(mixed $value, string $default, array $allowed): string
    {
        $text = trim((string) ($value ?? ''));

        return in_array($text, $allowed, true) ? $text : $default;
    }

    private function textValue(mixed $value, string $default, int $maxLength): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return $default;
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
    }

    private function localAssetUrl(mixed $value): string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        if (str_starts_with($url, 'data:image/') && str_contains($url, ';base64,')) {
            return $url;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        )));
    }

    /**
     * @return list<string>
     */
    private function reviewerRoles(mixed $value): array
    {
        $roles = array_values(array_filter(
            $this->stringList($value),
            static fn (string $role): bool => in_array($role, ['admin', 'super_admin'], true)
        ));

        return $roles === [] ? ['admin', 'super_admin'] : $roles;
    }

    /**
     * @return list<string>
     */
    private function imageExtensions(mixed $value): array
    {
        $extensions = array_values(array_filter(array_map(
            static fn (string $item): string => strtolower(ltrim($item, '.')),
            $this->stringList($value)
        ), static fn (string $item): bool => in_array($item, ['jpg', 'jpeg', 'png', 'webp'], true)));

        return $extensions === [] ? ['jpg', 'jpeg', 'png', 'webp'] : $extensions;
    }

    /**
     * @return list<string>
     */
    private function ipList(mixed $value): array
    {
        return array_values(array_filter(
            $this->stringList($value),
            static fn (string $item): bool => filter_var($item, FILTER_VALIDATE_IP) !== false
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function certificateRequest(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $status = (string) ($value['status'] ?? 'requested');
        $requestType = (string) ($value['request_type'] ?? 'domain');

        $issuerMode = (string) ($value['issuer_mode'] ?? 'caddy_acme');
        $renewalInterval = $this->intRange($value['renewal_check_interval_hours'] ?? null, 24, 1, 168);

        return [
            'status' => in_array($status, ['requested', 'issued', 'verified', 'failed'], true) ? $status : 'requested',
            'request_type' => in_array($requestType, ['domain', 'public_ip', 'internal_ip'], true) ? $requestType : 'domain',
            'targets' => $this->stringList($value['targets'] ?? []),
            'requested_at' => trim((string) ($value['requested_at'] ?? '')),
            'issuer_mode' => in_array($issuerMode, ['caddy_acme', 'letsencrypt_shortlived', 'internal_ca'], true) ? $issuerMode : 'caddy_acme',
            'renewal_check_interval_hours' => $renewalInterval,
        ];
    }

    /**
     * @return list<array{name: string, domains: list<string>, issuer: string, expires_at: string, status: string}>
     */
    private function certificates(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $certificates = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $expiresAt = trim((string) ($item['expires_at'] ?? ''));
            if ($name === '' || $expiresAt === '') {
                continue;
            }
            $status = (string) ($item['status'] ?? 'active');
            $certificates[] = [
                'name' => $name,
                'domains' => $this->stringList($item['domains'] ?? []),
                'issuer' => trim((string) ($item['issuer'] ?? '')),
                'expires_at' => $expiresAt,
                'status' => in_array($status, ['active', 'disabled'], true) ? $status : 'active',
            ];
        }

        return $certificates;
    }
}
