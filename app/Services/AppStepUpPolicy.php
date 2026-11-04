<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Request;

/**
 * Sensitive admin operations that a TokenHub APP session may only perform
 * after a recent PIN / passkey verification ("step-up"). Web console cookie
 * sessions are not affected.
 *
 * Kept deliberately narrow: viewing data, handling alerts, creating keys and
 * disabling keys (a damage-limiting action) never ask for verification.
 */
final class AppStepUpPolicy
{
    /** @var list<array{0: string, 1: string, 2: string}> method ('*' = any write), path regex, label */
    private const RULES = [
        ['DELETE', '#^/admin-api/api-keys/[^/]+$#', '删除 API 密钥'],
        ['POST', '#^/admin-api/api-keys/[^/]+/rotate$#', '轮换 API 密钥'],
        ['POST', '#^/admin-api/api-keys/batch$#', '批量操作 API 密钥'],
        ['POST', '#^/admin-api/quota-adjustments$#', '调整额度'],
        ['*', '#^/admin-api/admin-users(/|$)#', '管理员账号管理'],
        ['*', '#^/admin-api/system(/|-settings(/|$))#', '修改系统设置'],
        ['POST', '#^/admin-api/my/app-devices/revoke-all$#', '吊销其他 APP 设备'],
        ['DELETE', '#^/admin-api/my/app-devices/[^/]+$#', '吊销其他 APP 设备'],
        // Upstream credentials / routing: provider CRUD, model import, catalog sync.
        ['*', '#^/admin-api/providers(/|$)#', '修改供应商配置'],
        // Dashboard share links: create / edit / disable / reset password /
        // revoke sessions / allow client IP all widen or reset screen access.
        ['*', '#^/admin-api/dashboard-share-links(/|$)#', '管理大屏分享链接'],
        // Vector store endpoints and credentials (incl. the connection test,
        // which sends stored credentials to a chosen URL).
        ['*', '#^/admin-api/rag-vector-configs(/|$)#', '修改向量库配置'],
        ['POST', '#^/admin-api/users/[^/]+/reset-password$#', '重置用户密码'],
    ];

    public function enabled(): bool
    {
        return (bool) App::config()->get('app.app_client.step_up_required', true);
    }

    public function windowSeconds(): int
    {
        return max(30, (int) App::config()->get('app.app_client.step_up_window_seconds', 300));
    }

    /**
     * Label of the sensitive operation, or null when the request needs no step-up.
     */
    public function sensitiveOperation(string $method, string $path, ?int $currentDeviceId = null): ?string
    {
        $method = strtoupper($method);
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return null;
        }
        // Signing the current device out is never blocked.
        if ($method === 'DELETE' && $currentDeviceId !== null && $path === '/admin-api/my/app-devices/' . $currentDeviceId) {
            return null;
        }
        foreach (self::RULES as [$ruleMethod, $pattern, $label]) {
            if (($ruleMethod === '*' || $ruleMethod === $method) && preg_match($pattern, $path) === 1) {
                return $label;
            }
        }

        return null;
    }

    public function requestNeedsStepUp(Request $request, ?int $currentDeviceId = null): ?string
    {
        return $this->enabled() ? $this->sensitiveOperation($request->method, $request->path, $currentDeviceId) : null;
    }

    /**
     * @param string|null $stepUpAt server time (Y-m-d H:i:s) of the last verification
     */
    public function recentlyVerified(?string $stepUpAt): bool
    {
        if ($stepUpAt === null || $stepUpAt === '') {
            return false;
        }
        $row = App::db()->first('SELECT (? >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)) AS fresh', [$stepUpAt, $this->windowSeconds()]);

        return (int) ($row['fresh'] ?? 0) === 1;
    }

    /** @return list<array{method: string, pattern: string, label: string}> */
    public function rules(): array
    {
        return array_map(static fn (array $rule): array => ['method' => $rule[0], 'pattern' => $rule[1], 'label' => $rule[2]], self::RULES);
    }
}
