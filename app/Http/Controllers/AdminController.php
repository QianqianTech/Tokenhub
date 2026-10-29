<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\ApiKeyService;
use TokenHub\Services\AdminChatSessionService;
use TokenHub\Services\AdminDownloadService;
use TokenHub\Services\AdminOperationsService;
use TokenHub\Services\AdminUserService;
use TokenHub\Services\AuditService;
use TokenHub\Services\AuthService;
use TokenHub\Services\CertificateRequestService;
use TokenHub\Services\CertificateStatusService;
use TokenHub\Services\ContentArchiveService;
use TokenHub\Services\ContentRetentionService;
use TokenHub\Services\CrudService;
use TokenHub\Services\DashboardService;
use TokenHub\Services\DepartmentService;
use TokenHub\Services\ExportService;
use TokenHub\Services\LogService;
use TokenHub\Services\NetworkInterfaceIpService;
use TokenHub\Services\PermissionService;
use TokenHub\Services\ProviderModelSyncService;
use TokenHub\Services\ScreenService;
use TokenHub\Services\SystemSettingsService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class AdminController
{
    public function login(Request $request): Response
    {
        $data = $request->json();
        $result = (new AuthService())->login((string) ($data['username'] ?? ''), (string) ($data['password'] ?? ''), $request->clientIp(), $request->header('user-agent', '') ?? '');
        if ($result === null) {
            return Response::error('ADMIN_UNAUTHENTICATED', 'Invalid username or password', 401);
        }

        return $this->withSessionCookie(
            Response::ok(['user' => $result['user'], 'csrf_token' => $result['csrf']]),
            $result['session'],
            time() + 86400
        );
    }

    public function logout(Request $request): Response
    {
        (new AuthService())->logout($_COOKIE['tokenhub_admin_session'] ?? '');
        return $this->withSessionCookie(Response::ok(['logged_out' => true]), '', time() - 3600);
    }

    public function me(Request $request): Response
    {
        return Response::ok(['user' => App::context()->adminUser()]);
    }

    public function csrf(Request $request): Response
    {
        $csrf = Crypto::token('csrf_');
        App::db()->execute('UPDATE admin_sessions SET csrf_token_hash = ? WHERE session_hash = ?', [Crypto::hashKey($csrf), Crypto::hashKey($_COOKIE['tokenhub_admin_session'] ?? '')]);
        return Response::ok(['csrf_token' => $csrf]);
    }

    public function brandIdentity(Request $request): Response
    {
        $service = new SystemSettingsService();
        try {
            $settings = $service->loadSection('brand_identity');
        } catch (\Throwable) {
            $settings = $service->defaults()['brand_identity'];
        }

        return Response::ok([
            'product_name' => $settings['product_name'] ?? 'TokenHub',
            'admin_title' => $settings['admin_title'] ?? 'TokenHub 管理后台',
            'logo_url' => $settings['logo_url'] ?? '',
            'favicon_url' => $settings['favicon_url'] ?? '',
        ]);
    }

    public function index(Request $request, string $table): Response
    {
        return Response::ok((new CrudService())->list($table, $request->query));
    }

    public function create(Request $request, string $table): Response
    {
        return Response::ok((new CrudService())->create($table, $request->json()), 201);
    }

    public function update(Request $request, string $table): Response
    {
        return Response::ok((new CrudService())->update($table, (int) $request->params['id'], $request->json()));
    }

    public function disable(Request $request, string $table): Response
    {
        return Response::ok((new CrudService())->disable($table, (int) $request->params['id']));
    }

    public function deleteProvider(Request $request): Response
    {
        return Response::ok((new CrudService())->deleteProvider((int) $request->params['id']));
    }

    public function deleteModel(Request $request): Response
    {
        return Response::ok((new CrudService())->deleteModel((int) $request->params['id']));
    }

    public function batchModels(Request $request): Response
    {
        $data = $request->json();
        $action = (string) ($data['action'] ?? '');
        $ids = $data['ids'] ?? [];
        if (!is_array($ids)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'ids must be an array', 422);
        }

        return Response::ok((new CrudService())->batchModels($action, $ids));
    }

    public function providerModels(Request $request): Response
    {
        // Only models the gateway can serve through this provider; the
        // response says how many were hidden and why.
        return Response::ok((new ProviderModelSyncService())->fetchSupportedModels((int) $request->params['id']));
    }

    public function importProviderModels(Request $request): Response
    {
        $models = $request->json()['models'] ?? [];
        if (!is_array($models)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'models must be an array', 422);
        }

        return Response::ok((new ProviderModelSyncService())->importModels((int) $request->params['id'], $models));
    }

    public function chatSessionModels(Request $request): Response
    {
        return Response::ok((new AdminChatSessionService())->models());
    }

    public function chatSessionMessage(Request $request): Response
    {
        $payload = $request->json();
        if (!empty($payload['api_key_id'])) {
            // Production-path trial (see AdminChatSessionService::gatewayTrial).
            return (new AdminChatSessionService())->gatewayTrial($payload, false);
        }

        return Response::ok((new AdminChatSessionService())->send($payload));
    }

    public function chatSessionStream(Request $request): Response
    {
        if (!empty($request->json()['api_key_id'])) {
            return (new AdminChatSessionService())->gatewayTrial($request->json(), true);
        }

        return Response::stream(function () use ($request): void {
            $result = (new AdminChatSessionService())->stream($request->json(), static function (string $chunk): void {
                echo $chunk;
            });
            if (!$result['ok']) {
                $code = $result['error_code'] ?? 'PROVIDER_ERROR';
                echo 'event: error' . "\n";
                echo 'data: ' . json_encode(['error' => ['code' => $code, 'message' => 'Upstream provider request failed']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                echo "data: [DONE]\n\n";
            }
        }, 200, Response::sseHeaders());
    }

    public function dashboardShareLinks(Request $request): Response
    {
        return Response::ok((new ScreenService())->listShareLinks($request->query));
    }

    public function createDashboardShareLink(Request $request): Response
    {
        return Response::ok((new ScreenService())->createShareLink($request->json()), 201);
    }

    public function updateDashboardShareLink(Request $request): Response
    {
        return Response::ok((new ScreenService())->updateShareLink((int) $request->params['id'], $request->json()));
    }

    public function disableDashboardShareLink(Request $request): Response
    {
        return Response::ok((new ScreenService())->disableShareLink((int) $request->params['id']));
    }

    public function resetDashboardShareLinkPassword(Request $request): Response
    {
        $data = $request->json();
        $password = array_key_exists('password', $data) && (string) $data['password'] !== '' ? (string) $data['password'] : null;
        return Response::ok((new ScreenService())->resetPassword((int) $request->params['id'], $password));
    }

    public function revokeDashboardShareLinkSessions(Request $request): Response
    {
        return Response::ok((new ScreenService())->revokeSessions((int) $request->params['id']));
    }

    public function allowDashboardShareLinkClientIp(Request $request): Response
    {
        $data = $request->json();
        return Response::ok((new ScreenService())->allowClientIp((int) $request->params['id'], (string) ($data['client_ip'] ?? '')));
    }

    public function dashboardShareAccessLogs(Request $request): Response
    {
        return Response::ok((new ScreenService())->listAccessLogs($request->query));
    }

    public function departmentTree(Request $request): Response
    {
        $actor = App::context()->adminUser();
        $permission = new PermissionService();
        $rows = array_values(array_filter(
            App::db()->select('SELECT * FROM departments ORDER BY level ASC, sort_order ASC, id ASC'),
            static fn (array $row): bool => $actor === null || $permission->canAccessDepartmentPath($actor, (string) $row['path'])
        ));
        $visibleIds = array_flip(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $byParent = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null && !isset($visibleIds[(int) $row['parent_id']])) {
                $row['parent_id'] = null;
            }
            $byParent[(string) ($row['parent_id'] ?? 'root')][] = $row;
        }
        $build = function ($parentId) use (&$build, &$byParent): array {
            return array_map(function (array $row) use (&$build): array {
                $row['children'] = $build($row['id']);
                return $row;
            }, $byParent[(string) ($parentId ?? 'root')] ?? []);
        };

        return Response::ok($build(null));
    }

    public function moveDepartment(Request $request): Response
    {
        $data = $request->json();
        $departmentId = (int) $request->params['id'];
        $parentId = array_key_exists('parent_id', $data) && $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
        $this->requireDepartmentScope($departmentId);
        if ($parentId !== null) {
            $this->requireDepartmentScope($parentId);
        }

        return Response::ok((new DepartmentService())->move($departmentId, $parentId, (int) ($data['sort_order'] ?? 0)));
    }

    public function sortDepartments(Request $request): Response
    {
        $items = $request->json()['items'] ?? [];
        if (!is_array($items)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Department sort items must be an array', 422);
        }
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $this->requireDepartmentScope((int) $item['id']);
            }
        }

        return Response::ok((new DepartmentService())->sort($items));
    }

    public function updateDepartmentStatus(Request $request): Response
    {
        $departmentId = (int) $request->params['id'];
        $status = (string) ($request->json()['status'] ?? 'disabled');
        if (!in_array($status, ['active', 'disabled'], true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid department status', 422);
        }
        $this->requireDepartmentScope($departmentId);
        App::db()->execute('UPDATE departments SET status = ? WHERE id = ?', [$status, $departmentId]);
        (new DepartmentService())->invalidateDepartmentApiKeys($departmentId);
        (new \TokenHub\Services\AuditService())->write('department.status.update', 'departments', $departmentId, ['status' => $status]);

        return Response::ok(['id' => $departmentId, 'status' => $status]);
    }

    public function resetUserPassword(Request $request): Response
    {
        $data = $request->json();
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Password is required', 422);
        }

        $userId = (int) $request->params['id'];
        $this->requireUserScope($userId);
        $this->requireNonAdminUserTarget($userId);
        App::db()->execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
        App::db()->execute('UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ?', [$userId]);
        (new ApiKeyService())->invalidateByUser($userId);
        (new \TokenHub\Services\AuditService())->write('user.password.reset', 'users', $userId);

        return Response::ok(['id' => $userId, 'password_reset' => true]);
    }

    public function moveUserDepartment(Request $request): Response
    {
        $departmentId = (int) ($request->json()['department_id'] ?? 0);
        if ($departmentId <= 0) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'department_id is required', 422);
        }

        $userId = (int) $request->params['id'];
        $this->requireUserScope($userId);
        $this->requireNonAdminUserTarget($userId);
        $this->requireDepartmentScope($departmentId);
        App::db()->execute('UPDATE users SET department_id = ? WHERE id = ?', [$departmentId, $userId]);
        // Keys follow their user: RAG grants and quotas of the old department stop applying (RAG-005).
        App::db()->execute('UPDATE api_keys SET department_id = ? WHERE user_id = ?', [$departmentId, $userId]);
        App::db()->execute('UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ?', [$userId]);
        (new ApiKeyService())->invalidateByUser($userId);
        (new \TokenHub\Services\AuditService())->write('user.department.move', 'users', $userId, ['department_id' => $departmentId]);

        return Response::ok(['id' => $userId, 'department_id' => $departmentId]);
    }

    public function updateUserStatus(Request $request): Response
    {
        $userId = (int) $request->params['id'];
        $status = (string) ($request->json()['status'] ?? 'disabled');
        if (!in_array($status, ['active', 'disabled', 'departed'], true)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'Invalid user status', 422);
        }
        $this->requireUserScope($userId);
        $this->requireNonAdminUserTarget($userId);
        App::db()->execute('UPDATE users SET status = ? WHERE id = ?', [$status, $userId]);
        App::db()->execute('UPDATE admin_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ?', [$userId]);
        (new ApiKeyService())->invalidateByUser($userId);
        (new \TokenHub\Services\AuditService())->write('user.status.update', 'users', $userId, ['status' => $status]);

        return Response::ok(['id' => $userId, 'status' => $status]);
    }

    public function adminUsers(Request $request): Response
    {
        return Response::ok((new AdminUserService())->list($request->query));
    }

    public function createAdminUser(Request $request): Response
    {
        return Response::ok((new AdminUserService())->create($request->json()), 201);
    }

    public function updateAdminUser(Request $request): Response
    {
        return Response::ok((new AdminUserService())->update((int) $request->params['id'], $request->json()));
    }

    public function resetAdminUserPassword(Request $request): Response
    {
        return Response::ok((new AdminUserService())->resetPassword((int) $request->params['id'], (string) ($request->json()['password'] ?? '')));
    }

    public function revokeAdminUserSessions(Request $request): Response
    {
        return Response::ok((new AdminUserService())->revokeSessions((int) $request->params['id']));
    }

    public function apiKeys(Request $request): Response
    {
        return Response::ok((new ApiKeyService())->listPageWithUsage($request->query));
    }

    public function createApiKey(Request $request): Response
    {
        $actor = App::context()->adminUser();
        $this->requireDepartmentScope((int) ($request->json()['department_id'] ?? 0));
        return Response::ok((new ApiKeyService())->create($request->json(), (int) $actor['id']), 201);
    }

    public function updateApiKey(Request $request): Response
    {
        $this->requireApiKeyScope((int) $request->params['id']);
        return Response::ok((new ApiKeyService())->update((int) $request->params['id'], $request->json()));
    }

    public function updateApiKeyModels(Request $request): Response
    {
        $this->requireApiKeyScope((int) $request->params['id']);
        return Response::ok((new ApiKeyService())->replaceModels((int) $request->params['id'], $request->json()['model_ids'] ?? []));
    }

    public function apiKeyRagBindings(Request $request): Response
    {
        $this->requireApiKeyScope((int) $request->params['id']);
        return Response::ok((new ApiKeyService())->ragBindings((int) $request->params['id']));
    }

    public function updateApiKeyRagBindings(Request $request): Response
    {
        $this->requireApiKeyScope((int) $request->params['id']);
        $data = $request->json();
        return Response::ok((new ApiKeyService())->replaceRagBindings(
            (int) $request->params['id'],
            is_array($data['knowledge_base_ids'] ?? null) ? $data['knowledge_base_ids'] : [],
            is_array($data['dataset_ids'] ?? null) ? $data['dataset_ids'] : [],
        ));
    }

    public function batchApiKeys(Request $request): Response
    {
        $data = $request->json();
        $action = (string) ($data['action'] ?? '');
        $ids = $data['ids'] ?? [];
        if (!is_array($ids)) {
            abort_json('ADMIN_INVALID_PAYLOAD', 'ids must be an array', 422);
        }

        return Response::ok((new ApiKeyService())->batchStatus($action, $ids));
    }

    public function quotaStatus(Request $request): Response
    {
        $apiKeyId = (int) ($request->query['api_key_id'] ?? 0);
        $apiKey = App::db()->first('SELECT id, user_id, department_id FROM api_keys WHERE id = ?', [$apiKeyId]);
        $actor = App::context()->adminUser();
        if ($apiKey === null || $actor === null || !(new \TokenHub\Services\PermissionService())->canManage($actor, (int) $apiKey['department_id'])) {
            abort_json('ADMIN_NOT_FOUND', 'API key not found or outside admin scope', 404);
        }

        return Response::ok((new \TokenHub\Services\ExternalQuotaService())->current($apiKey));
    }

    public function adjustQuota(Request $request): Response
    {
        $this->requireSuperAdmin();
        $data = $request->json();
        $this->requireQuotaTargetScope((string) ($data['target_type'] ?? ''), (int) ($data['target_id'] ?? 0));

        return Response::ok((new \TokenHub\Services\ExternalQuotaService())->adjust(
            (int) ($data['policy_id'] ?? 0),
            (string) ($data['target_type'] ?? ''),
            (int) ($data['target_id'] ?? 0),
            (string) ($data['metric'] ?? ''),
            (float) ($data['amount'] ?? 0),
            (string) ($data['reason'] ?? ''),
        ));
    }

    public function requestDiagnostics(Request $request): Response
    {
        return Response::ok((new \TokenHub\Services\RequestDiagnosticsService())->explain((string) $request->params['request_id'], App::context()->adminUser()));
    }

    public function catalogSync(Request $request): Response
    {
        $this->requireGlobalScope();

        return Response::ok((new \TokenHub\Services\ProviderModelSyncService())->syncProvider((int) $request->params['id'], (bool) ($request->json()['apply'] ?? false)));
    }

    /**
     * Catalog price review: GET previews differences, POST {"apply": true, "model_ids": [...]} writes new price versions.
     */
    public function catalogPrices(Request $request): Response
    {
        $this->requireSuperAdmin();
        $body = $request->method === 'POST' ? $request->json() : [];
        $ids = is_array($body['model_ids'] ?? null) ? array_map('intval', $body['model_ids']) : null;

        return Response::ok((new \TokenHub\Services\ProviderModelSyncService())->catalogPrices((int) $request->params['id'], ($body['apply'] ?? false) === true, $ids));
    }

    public function rotateApiKey(Request $request): Response
    {
        $actor = App::context()->adminUser();

        return Response::ok((new ApiKeyService())->rotate(
            (int) $request->params['id'],
            (int) ($request->json()['grace_minutes'] ?? 60),
            (int) ($actor['id'] ?? 0),
        ), 201);
    }

    public function deleteApiKey(Request $request): Response
    {
        $this->requireApiKeyScope((int) $request->params['id']);
        return Response::ok((new ApiKeyService())->softDelete((int) $request->params['id']));
    }

    public function createExport(Request $request): Response
    {
        return Response::ok((new ExportService())->create($request->params['type'], $request->json()), 202);
    }

    public function logs(Request $request): Response
    {
        $filters = $request->query;
        $filters['_scope'] = (new ExportService())->actorScope(App::context()->adminUser() ?? []);
        return Response::ok((new LogService())->query($filters));
    }

    public function exportDownloadUrl(Request $request): Response
    {
        return Response::ok((new ExportService())->downloadUrl((string) $request->params['export_id']));
    }

    public function contentRetention(Request $request): Response
    {
        return Response::ok((new ContentRetentionService())->policy());
    }

    public function updateContentRetention(Request $request): Response
    {
        return Response::ok((new ContentRetentionService())->savePolicy($request->json()));
    }

    public function settingsCenter(Request $request): Response
    {
        return Response::ok((new SystemSettingsService())->settingsCenter());
    }

    public function updateSettingsCenterSection(Request $request): Response
    {
        return Response::ok((new SystemSettingsService())->saveSection((string) $request->params['section'], $request->json()));
    }

    public function checkCertificateAlerts(Request $request): Response
    {
        return Response::ok(['events' => (new SystemSettingsService())->checkCertificateAlerts()]);
    }

    public function certificateStatus(Request $request): Response
    {
        return Response::ok((new CertificateStatusService())->status());
    }

    public function networkInterfaceIps(Request $request): Response
    {
        return Response::ok((new NetworkInterfaceIpService())->candidates());
    }

    public function requestCertificate(Request $request): Response
    {
        return Response::ok((new CertificateRequestService())->request($request->json()), 202);
    }

    public function contentArchive(Request $request): Response
    {
        return Response::ok((new ContentArchiveService())->list($request->query));
    }

    public function contentArchiveDetail(Request $request): Response
    {
        $includeRaw = filter_var($request->query['include_raw'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return Response::ok((new ContentArchiveService())->detail((int) $request->params['id'], $includeRaw));
    }

    public function dashboardRealtime(Request $request): Response
    {
        return Response::ok((new DashboardService())->realtime($request->query));
    }

    public function dashboardTrends(Request $request): Response
    {
        return Response::ok((new DashboardService())->trends($request->query));
    }

    public function dashboardRankings(Request $request): Response
    {
        return Response::ok((new DashboardService())->rankings($request->query));
    }

    public function dashboardSecurity(Request $request): Response
    {
        return Response::ok((new DashboardService())->security($request->query));
    }

    public function dashboardPerformance(Request $request): Response
    {
        return Response::ok((new DashboardService())->performance($request->query));
    }

    public function dashboardMultimodal(Request $request): Response
    {
        return Response::ok((new DashboardService())->multimodal($request->query));
    }

    public function operationsStatus(Request $request): Response
    {
        $this->requireGlobalScope();
        return Response::ok((new AdminOperationsService())->status());
    }

    public function updateAlertStatus(Request $request): Response
    {
        $this->requireGlobalScope();
        $data = $request->json();
        $status = (string) ($data['status'] ?? '');
        $note = substr(trim((string) ($data['note'] ?? '')), 0, 500);
        if (!in_array($status, ['acknowledged', 'resolved', 'silenced'], true) || $note === '') {
            abort_json('ALERT_STATUS_INVALID', 'Status and handling note are required', 422);
        }
        $actor = App::context()->adminUser();

        return Response::ok((new \TokenHub\Services\AlertService())->transition(
            (int) $request->params['id'],
            $status,
            $note,
            isset($actor['id']) ? (int) $actor['id'] : null,
            isset($data['silence_until']) ? (string) $data['silence_until'] : null,
        ));
    }

    public function mediaDownloadUrl(Request $request): Response
    {
        $asset = App::db()->first('SELECT department_id FROM media_assets WHERE asset_id = ?', [(string) $request->params['asset_id']]);
        if ($asset !== null && $asset['department_id'] !== null) {
            $this->requireDepartmentScope((int) $asset['department_id']);
        } else {
            $this->requireGlobalScope();
        }
        return Response::ok((new AdminDownloadService())->createMediaDownload((string) $request->params['asset_id']));
    }

    public function archiveDownloadUrl(Request $request): Response
    {
        $this->requireGlobalScope();
        return Response::ok((new AdminDownloadService())->createArchiveDownload((int) $request->params['id']));
    }

    private function withSessionCookie(Response $response, string $value, int $expires): Response
    {
        return $response->withCookie(
            'tokenhub_admin_session',
            $value,
            $expires,
            secure: (bool) App::config()->get('app.session_secure', true)
        );
    }

    /** @return array<string, mixed> */
    /**
     * Authority for control-plane actions comes from the route capability
     * (AdminAuthMiddleware) plus a data-scope check at the call site
     * (requireGlobalScope / requireDepartmentScope); there is deliberately
     * no hard-coded super_admin role gate (see AdminPermissionScopeContractTest).
     */
    private function requireSuperAdmin(): array
    {
        return App::context()->adminUser() ?? [];
    }

    /**
     * Platform-wide resources (provider catalog, operations, alerts, log
     * archives): super admins and admins whose data scope is global.
     */
    private function requireGlobalScope(): array
    {
        $actor = App::context()->adminUser() ?? [];
        if ($actor === [] || !(new PermissionService())->usesGlobalScope($actor)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'This action needs a global administrator scope', 403);
        }
        return $actor;
    }

    /**
     * Quota top-ups are limited to targets inside the admin's data scope.
     */
    private function requireQuotaTargetScope(string $targetType, int $targetId): void
    {
        $departmentId = match ($targetType) {
            'department' => $targetId,
            'user' => (int) (App::db()->first('SELECT department_id FROM users WHERE id = ?', [$targetId])['department_id'] ?? 0),
            'api_key' => (int) (App::db()->first('SELECT department_id FROM api_keys WHERE id = ?', [$targetId])['department_id'] ?? 0),
            default => -1,
        };
        if ($departmentId === -1) {
            return; // ExternalQuotaService::adjust rejects the target type.
        }
        if ($departmentId <= 0) {
            abort_json('QUOTA_POLICY_NOT_FOUND', 'Quota target not found', 404);
        }
        $this->requireDepartmentScope($departmentId);
    }

    private function requireApiKeyScope(int $apiKeyId): void
    {
        $key = App::db()->first('SELECT department_id FROM api_keys WHERE id = ?', [$apiKeyId]);
        if ($key === null) {
            abort_json('ADMIN_NOT_FOUND', 'API key not found', 404);
        }
        $this->requireDepartmentScope((int) $key['department_id']);
    }

    private function requireDepartmentScope(int $departmentId): void
    {
        $actor = App::context()->adminUser();
        $permission = new PermissionService();
        $error = $actor ? $permission->requireAdmin($actor) : ['code' => 'ADMIN_UNAUTHENTICATED', 'message' => 'Admin login required', 'status' => 401];
        if ($error !== null) {
            abort_json($error['code'], $error['message'], $error['status']);
        }
        if (!$permission->canManage($actor, $departmentId)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Department is outside admin scope', 403);
        }
    }

    private function requireUserScope(int $userId): void
    {
        $user = App::db()->first('SELECT department_id FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            abort_json('ADMIN_NOT_FOUND', 'User not found', 404);
        }
        $this->requireDepartmentScope((int) $user['department_id']);
    }

    private function requireNonAdminUserTarget(int $userId): void
    {
        $user = App::db()->first('SELECT role FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            abort_json('ADMIN_NOT_FOUND', 'User not found', 404);
        }
        if (in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin'], true)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Use admin-users endpoints for administrator accounts', 403);
        }
    }
}
