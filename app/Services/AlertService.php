<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Logger;

final class AlertService
{
    /**
     * @param null|callable(array<string, mixed>): void $writer
     */
    public function __construct(private readonly mixed $writer = null)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(string $eventType, string $severity, string $message, array $metadata = []): void
    {
        $event = [
            'event_type' => $eventType,
            'severity' => $severity,
            'message' => Logger::sanitize($message),
            'metadata' => Logger::sanitize($metadata),
            'status' => 'open',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        if (is_callable($this->writer)) {
            ($this->writer)($event);
            return;
        }

        App::db()->execute(
            'INSERT INTO alert_events (event_type, severity, message, metadata, status) VALUES (?, ?, ?, ?, ?)',
            [
                $event['event_type'],
                $event['severity'],
                $event['message'],
                json_encode($event['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $event['status'],
            ],
        );
        App::logger()->info('security', 'alert event recorded', ['event_type' => $eventType, 'severity' => $severity]);
    }

    /**
     * @param list<array{name: string, expires_at: string}> $certificates
     * @return list<array<string, mixed>>
     */
    public function certificateExpiryAlerts(array $certificates, ?int $now = null, int $thresholdDays = 14): array
    {
        $now ??= time();
        $events = [];
        foreach ($certificates as $certificate) {
            $expiresAt = strtotime((string) ($certificate['expires_at'] ?? '')) ?: 0;
            if ($expiresAt <= 0 || $expiresAt - $now > $thresholdDays * 86400) {
                continue;
            }
            $event = [
                'event_type' => 'certificate_expiring',
                'severity' => $expiresAt < $now ? 'critical' : 'warning',
                'message' => 'Certificate is expiring soon',
                'metadata' => [
                    'name' => $certificate['name'] ?? 'unknown',
                    'expires_at' => $certificate['expires_at'] ?? null,
                    'days_remaining' => (int) floor(($expiresAt - $now) / 86400),
                ],
            ];
            $events[] = $event;
            $this->record($event['event_type'], $event['severity'], $event['message'], $event['metadata']);
        }
        return $events;
    }

    public function evaluate(): void
    {
        $this->wafSpike();
        $this->providerErrors();
        $this->queueBacklog();
        $this->configuredCertificateExpiry();
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function evaluateCertificateSettings(array $settings, ?int $now = null): void
    {
        $certificates = $settings['certificates'] ?? [];
        if (!is_array($certificates)) {
            return;
        }
        $thresholdDays = (int) ($settings['threshold_days'] ?? 14);
        $this->certificateExpiryAlerts(array_values(array_filter($certificates, 'is_array')), $now, $thresholdDays);
    }

    private function wafSpike(): void
    {
        $settings = $this->alertThresholds();
        $threshold = min(100000, max(1, (int) ($settings['waf_spike_count'] ?? 10)));
        $windowMinutes = min(1440, max(1, (int) ($settings['waf_spike_window_minutes'] ?? 5)));
        $row = App::db()->first("SELECT COUNT(*) count_value FROM waf_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$windowMinutes} MINUTE)");
        if ((int) ($row['count_value'] ?? 0) >= $threshold) {
            $this->record('waf_hit_spike', 'warning', 'WAF hit volume is elevated', [
                'count' => (int) $row['count_value'],
                'threshold' => $threshold,
                'window_minutes' => $windowMinutes,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function alertThresholds(): array
    {
        try {
            return (new SystemSettingsService())->loadSection('alert_thresholds');
        } catch (\Throwable) {
            return (new SystemSettingsService())->defaults()['alert_thresholds'];
        }
    }

    private function providerErrors(): void
    {
        $rows = App::db()->select(
            'SELECT provider_id, COUNT(*) total_count, SUM(CASE WHEN status != "success" THEN 1 ELSE 0 END) error_count FROM usage_records WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) GROUP BY provider_id HAVING total_count >= 5'
        );
        foreach ($rows as $row) {
            $rate = ((int) $row['error_count']) / max(1, (int) $row['total_count']);
            if ($rate >= 0.3) {
                $this->record('provider_error_rate_high', 'warning', 'Provider error rate is high', [
                    'provider_id' => $row['provider_id'],
                    'error_rate' => $rate,
                    'window_minutes' => 5,
                ]);
            }
        }
    }

    private function queueBacklog(): void
    {
        try {
            $queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
            $count = (int) App::redis()->connection()->lLen($queue);
            if ($count >= 1000) {
                $this->record('queue_backlog_high', 'warning', 'Queue backlog is high', ['queue' => $queue, 'count' => $count]);
            }
        } catch (\Throwable) {
        }
    }

    private function configuredCertificateExpiry(): void
    {
        try {
            $row = App::db()->first('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['tls_certificates']);
            if (!$row) {
                return;
            }
            $settings = is_string($row['setting_value'] ?? null) ? json_decode((string) $row['setting_value'], true) : $row['setting_value'];
            if (is_array($settings)) {
                $this->evaluateCertificateSettings($settings);
            }
        } catch (\Throwable) {
        }
    }
}
