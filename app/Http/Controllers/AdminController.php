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
use TokenHub\Services\LoginIpBanService;
use TokenHub\Services\NetworkInterfaceIpService;
use TokenHub\Services\PermissionService;
use TokenHub\Services\ProviderModelSyncService;
use TokenHub\Services\ScreenService;
use TokenHub\Services\SystemSettingsService;
use TokenHub\Services\WafService;
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
        return Response::ok((new ProviderModelSyncService())->fetchModels((int) $request->params['id']));
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
        return Response::ok((new AdminChatSessionService())->send($request->json()));
    }

    public function chatSessionStream(Request $request): Response
    {
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
        return Response::ok((new ApiKeyService())->create($request->json(), (int) $actor['id']), 201);
    }

    public function updateApiKey(Request $request): Response
    {
        return Response::ok((new ApiKeyService())->update((int) $request->params['id'], $request->json()));
    }

    public function updateApiKeyModels(Request $request): Response
    {
        return Response::ok((new ApiKeyService())->replaceModels((int) $request->params['id'], $request->json()['model_ids'] ?? []));
    }

    public function apiKeyRagBindings(Request $request): Response
    {
        return Response::ok((new ApiKeyService())->ragBindings((int) $request->params['id']));
    }

    public function updateApiKeyRagBindings(Request $request): Response
    {
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

    public function deleteApiKey(Request $request): Response
    {
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
        $service = new NetworkInterfaceIpService();
        $result = $service->candidates();
        $result['local_access_ips'] = $service->localAccessIps();

        return Response::ok($result);
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

    public function publishWaf(Request $request): Response
    {
        $this->requireSuperAdmin();
        $json = $request->json();
        return Response::ok((new WafService())->publish([
            'reload' => $json['reload'] ?? true,
            'dry_run' => $json['dry_run'] ?? false,
            'confirm_high_risk' => $json['confirm_high_risk'] ?? false,
        ]), 202);
    }

    public function rollbackWaf(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->rollback((string) ($request->json()['version_code'] ?? ''), ['reload' => $request->json()['reload'] ?? true]), 202);
    }

    public function wafVersions(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->versions());
    }

    public function wafEvents(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->events($request->query));
    }

    public function wafStatistics(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->statistics($request->query));
    }

    public function wafTemplates(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->templates());
    }

    public function previewWaf(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->preview($request->json()));
    }

    public function handleWafEvent(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->handleEvent((int) $request->params['id'], $request->json()));
    }

    public function autoDispositionWaf(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->autoDisposition($request->json()), 202);
    }

    public function wafVersionDiff(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->diff((string) $request->params['version_code'], (string) ($request->query['base'] ?? 'previous')));
    }

    public function wafOverview(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new WafService())->overview());
    }

    public function wafIpBans(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new LoginIpBanService())->listBans($request->query));
    }

    public function banWafIp(Request $request): Response
    {
        $this->requireSuperAdmin();
        $data = $request->json();
        $result = (new LoginIpBanService())->ban((string) ($data['client_ip'] ?? ''), (string) ($data['reason'] ?? 'admin_manual_ban'));
        (new AuditService())->write('waf.ip_ban', 'system_ip_bans', $result['client_ip'], ['reason' => $data['reason'] ?? 'admin_manual_ban']);
        return Response::ok($result, 201);
    }

    public function unbanWafIp(Request $request): Response
    {
        $this->requireSuperAdmin();
        $ip = (string) $request->params['ip'];
        (new LoginIpBanService())->reset($ip);
        (new AuditService())->write('waf.ip_unban', 'system_ip_bans', $ip);
        return Response::ok(['client_ip' => $ip, 'banned' => false]);
    }

    public function operationsStatus(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new AdminOperationsService())->status());
    }

    public function updateAlertStatus(Request $request): Response
    {
        $this->requireSuperAdmin();
        $data = $request->json();
        $status = (string) ($data['status'] ?? '');
        $note = substr(trim((string) ($data['note'] ?? '')), 0, 500);
        if (!in_array($status, ['acknowledged', 'resolved'], true) || $note === '') {
            abort_json('ALERT_STATUS_INVALID', 'Status and handling note are required', 422);
        }
        App::db()->execute('UPDATE alert_events SET status = ? WHERE id = ?', [$status, (int) $request->params['id']]);
        (new AuditService())->write('alert.status.update', 'alert_events', (int) $request->params['id'], ['status' => $status, 'note' => $note]);
        return Response::ok(App::db()->first('SELECT * FROM alert_events WHERE id = ?', [(int) $request->params['id']]) ?: []);
    }

    public function mediaDownloadUrl(Request $request): Response
    {
        $this->requireSuperAdmin();
        return Response::ok((new AdminDownloadService())->createMediaDownload((string) $request->params['asset_id']));
    }

    public function archiveDownloadUrl(Request $request): Response
    {
        $this->requireSuperAdmin();
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
    private function requireSuperAdmin(): array
    {
        $actor = App::context()->adminUser() ?? [];
        return $actor;
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
