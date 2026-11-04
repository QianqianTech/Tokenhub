<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\ConfigVersion;
use TokenHub\Support\Request;

final class AuditService
{
    public function write(string $action, ?string $targetType = null, string|int|null $targetId = null, array $details = []): void
    {
        $context = App::context();
        $actor = $context->adminUser();
        // Agent-management and other API-key initiated writes (SEC-110).
        $apiKeyId = (int) ($context->apiKey()['id'] ?? 0) ?: null;
        $values = [
            'actor_user_id' => $actor['id'] ?? null,
            'actor_type' => $actor ? 'admin' : ($apiKeyId !== null ? 'api_key' : 'system'),
            'actor_api_key_id' => $apiKeyId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'request_id' => $context->requestId(),
            'trace_id' => $context->traceId(),
            'client_ip' => self::clientIp(),
            'details' => json_encode(App::logger()->sanitize($details), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            // Console vs TokenHub APP, and which APP device (APP-009).
            'channel' => $context->adminAuth()['channel'] ?? ($actor ? 'web' : null),
            'device_id' => ($context->adminAuth()['device_id'] ?? null) ?: null,
        ];
        $columns = array_values(array_intersect(array_keys($values), $this->auditColumns()));
        if ($columns !== []) {
            App::db()->execute(
                sprintf('INSERT INTO audit_logs (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
                array_map(static fn (string $column): mixed => $values[$column], $columns)
            );
        }
        App::logger()->info('audit', $action, ['target_type' => $targetType, 'target_id' => $targetId]);
        if (self::changesRuntimeConfig($action)) {
            // Authorization/routing/policy edits must reach every instance now,
            // not after cache TTLs expire (see ConfigVersion).
            ConfigVersion::bump('global', $action);
        }
    }

    /**
     * The real client address: X-Forwarded-For is honoured only from trusted
     * proxies (TrustedProxyService), so a client cannot forge its audit IP.
     */
    public static function clientIp(): ?string
    {
        if (!isset($_SERVER['REMOTE_ADDR']) || (string) $_SERVER['REMOTE_ADDR'] === '') {
            return null;
        }
        $request = new Request('GET', '/', $_SERVER, [], [], '', App::context());

        return (new TrustedProxyService())->clientIp($request);
    }

    /**
     * Audited mutations that can change who may call what, where requests
     * route, or which policies apply.
     */
    public static function changesRuntimeConfig(string $action): bool
    {
        foreach (['admin.create', 'admin.update', 'admin.disable', 'admin.delete', 'admin.models.', 'api_key.', 'user.', 'department.', 'provider.', 'agent_management.', 'admin_user.', 'settings_center.', 'content_retention.', 'credential.'] as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function auditColumns(): array
    {
        static $columns = null;
        if ($columns === null) {
            $columns = array_map('strval', array_column(App::db()->select('SHOW COLUMNS FROM audit_logs'), 'Field'));
        }
        return $columns;
    }
}
