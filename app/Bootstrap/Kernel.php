<?php

declare(strict_types=1);

namespace TokenHub\Bootstrap;

use TokenHub\Http\Controllers\AdminController;
use TokenHub\Http\Controllers\AgentManagementController;
use TokenHub\Http\Controllers\DownloadController;
use TokenHub\Http\Controllers\ExternalController;
use TokenHub\Http\Controllers\FileController;
use TokenHub\Http\Controllers\GatewayController;
use TokenHub\Http\Controllers\HealthController;
use TokenHub\Http\Controllers\McpController;
use TokenHub\Http\Controllers\OAuthController;
use TokenHub\Http\Controllers\RagController;
use TokenHub\Http\Controllers\ScreenController;
use TokenHub\Http\Controllers\SyncController;
use TokenHub\Http\Controllers\UsageController;
use TokenHub\Http\Controllers\WebhookController;
use TokenHub\Middleware\AdminAuthMiddleware;
use TokenHub\Middleware\AgentManagementAuthMiddleware;
use TokenHub\Middleware\ApiKeyAuthMiddleware;
use TokenHub\Middleware\CsrfMiddleware;
use TokenHub\Middleware\GatewayAuthMiddleware;
use TokenHub\Middleware\McpAuthMiddleware;
use TokenHub\Middleware\ScreenAuthMiddleware;
use TokenHub\Support\Router;

final class Kernel
{
    public static function router(): Router
    {
        $router = new Router();
        $health = new HealthController();
        $mcp = new McpController();
        $oauth = new OAuthController();
        $admin = new AdminController();
        $agentManagement = new AgentManagementController();
        $download = new DownloadController();
        $external = new ExternalController();
        $files = new FileController();
        $gateway = new GatewayController();
        $rag = new RagController();
        $screen = new ScreenController();
        $sync = new SyncController();
        $usage = new UsageController();
        $webhooks = new WebhookController();

        $router->get('/health', [$health, 'public']);
        $router->get('/ready', [$health, 'ready']);
        $router->get('/admin-api/health', [$health, 'admin']);
        $router->get('/download/{token}', [$download, 'show']);

        $router->get('/admin-api/system/brand-identity', [$admin, 'brandIdentity']);
        $router->post('/admin-api/login', [$admin, 'login']);
        $router->post('/admin-api/logout', [$admin, 'logout'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/me', [$admin, 'me'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/csrf', [$admin, 'csrf'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/session/renew', [$admin, 'renewSession'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/lookups/{type}', [$admin, 'lookup'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/dashboard-share-links', [$admin, 'dashboardShareLinks'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/dashboard-share-links', [$admin, 'createDashboardShareLink'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->put('/admin-api/dashboard-share-links/{id}', [$admin, 'updateDashboardShareLink'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->delete('/admin-api/dashboard-share-links/{id}', [$admin, 'disableDashboardShareLink'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/dashboard-share-links/{id}/reset-password', [$admin, 'resetDashboardShareLinkPassword'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/dashboard-share-links/{id}/revoke-sessions', [$admin, 'revokeDashboardShareLinkSessions'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/dashboard-share-links/{id}/allow-client-ip', [$admin, 'allowDashboardShareLinkClientIp'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/dashboard-share-access-logs', [$admin, 'dashboardShareAccessLogs'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/chat-session/models', [$admin, 'chatSessionModels'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/chat-session/messages', [$admin, 'chatSessionMessage'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/chat-session/stream', [$admin, 'chatSessionStream'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);

        $router->delete('/admin-api/providers/{id}', [$admin, 'deleteProvider'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/models/batch', [$admin, 'batchModels'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->delete('/admin-api/models/{id}', [$admin, 'deleteModel'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);

        foreach (self::adminResources() as $resource => $table) {
            $router->get("/admin-api/{$resource}", fn ($request) => $admin->index($request, $table), [new AdminAuthMiddleware()]);
            $router->post("/admin-api/{$resource}", fn ($request) => $admin->create($request, $table), [new AdminAuthMiddleware(), new CsrfMiddleware()]);
            $router->put("/admin-api/{$resource}/{id}", fn ($request) => $admin->update($request, $table), [new AdminAuthMiddleware(), new CsrfMiddleware()]);
            if (!in_array($resource, ['providers', 'models'], true)) {
                $router->delete("/admin-api/{$resource}/{id}", fn ($request) => $admin->disable($request, $table), [new AdminAuthMiddleware(), new CsrfMiddleware()]);
            }
        }

        $router->get('/admin-api/providers/{id}/models', [$admin, 'providerModels'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/providers/{id}/models', [$admin, 'importProviderModels'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/departments/tree', [$admin, 'departmentTree'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/departments/{id}/move', [$admin, 'moveDepartment'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/departments/sort', [$admin, 'sortDepartments'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->patch('/admin-api/departments/{id}/status', [$admin, 'updateDepartmentStatus'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/users/{id}/reset-password', [$admin, 'resetUserPassword'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/users/{id}/department', [$admin, 'moveUserDepartment'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->patch('/admin-api/users/{id}/status', [$admin, 'updateUserStatus'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/admin-users', [$admin, 'adminUsers'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/admin-users', [$admin, 'createAdminUser'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->put('/admin-api/admin-users/{id}', [$admin, 'updateAdminUser'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/admin-users/{id}/reset-password', [$admin, 'resetAdminUserPassword'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/admin-users/{id}/revoke-sessions', [$admin, 'revokeAdminUserSessions'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/api-keys', [$admin, 'apiKeys'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/api-keys/mcp-oauth-grants', [$admin, 'mcpOAuthGrants'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/api-keys/mcp-oauth-grants/{grant_id}/revoke', [$admin, 'revokeMcpOAuthGrant'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/api-keys', [$admin, 'createApiKey'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/api-keys/batch', [$admin, 'batchApiKeys'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->put('/admin-api/api-keys/{id}', [$admin, 'updateApiKey'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->put('/admin-api/api-keys/{id}/models', [$admin, 'updateApiKeyModels'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/api-keys/{id}/rag-bindings', [$admin, 'apiKeyRagBindings'], [new AdminAuthMiddleware()]);
        $router->put('/admin-api/api-keys/{id}/rag-bindings', [$admin, 'updateApiKeyRagBindings'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/api-keys/{id}/rotate', [$admin, 'rotateApiKey'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/quota-status', [$admin, 'quotaStatus'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/quota-adjustments', [$admin, 'adjustQuota'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/diagnostics/requests/{request_id}', [$admin, 'requestDiagnostics'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/providers/{id}/catalog-sync', [$admin, 'catalogSync'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/providers/{id}/catalog-prices', [$admin, 'catalogPrices'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/providers/{id}/catalog-prices', [$admin, 'catalogPrices'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->delete('/admin-api/api-keys/{id}', [$admin, 'deleteApiKey'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/logs', [$admin, 'logs'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/exports/{type}', [$admin, 'createExport'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/exports/{export_id}/download-url', [$admin, 'exportDownloadUrl'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/system/content-retention', [$admin, 'contentRetention'], [new AdminAuthMiddleware()]);
        $router->put('/admin-api/system/content-retention', [$admin, 'updateContentRetention'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/system/settings-center', [$admin, 'settingsCenter'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/system/certificate-status', [$admin, 'certificateStatus'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/system/network-interface-ips', [$admin, 'networkInterfaceIps'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/system/certificate-request', [$admin, 'requestCertificate'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/system/settings-center/certificate-alert-check', [$admin, 'checkCertificateAlerts'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->put('/admin-api/system/settings-center/{section}', [$admin, 'updateSettingsCenterSection'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/rag/import-templates', [$rag, 'adminTemplates'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/rag/import-templates/{template_id}/download', [$rag, 'adminDownloadTemplate'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/rag/knowledge-bases/{kb_id}/uploads', [$rag, 'adminUpload'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/rag/uploads/{upload_id}', [$rag, 'adminUploadStatus'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/rag/uploads/{upload_id}/review', [$rag, 'reviewUpload'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/rag/uploads/{upload_id}/preview', [$rag, 'adminPreview'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/rag/image-assets/{image_id}/reprocess-ai', [$rag, 'reprocessImage'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/rag/uploads/{upload_id}/confirm-vectorization', [$rag, 'adminConfirmVectorization'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/rag-vector-configs/test', [$rag, 'testVectorConfig'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/rag/summary', [$rag, 'adminSummary'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/rag/vectorization-metrics', [$rag, 'vectorizationMetrics'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/rag/evaluation-summary', [$rag, 'evaluationSummary'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/rag/retrieval-quality', [$rag, 'retrievalQuality'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/rag/evaluation-runs', [$rag, 'runEvaluation'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/rag/trial-retrieval', [$rag, 'adminTrialRetrieval'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/rag/eval-cases', [$rag, 'createEvaluationCase'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->patch('/admin-api/rag/eval-cases/{case_id}/status', [$rag, 'updateEvaluationCaseStatus'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/rag/eval-cases/import', [$rag, 'importEvaluationCases'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/rag/eval-runs/{run_id}/failures/{case_id}/correction', [$rag, 'createEvaluationFailureCorrection'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/admin-api/content-archive', [$admin, 'contentArchive'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/content-archive/{id}', [$admin, 'contentArchiveDetail'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/dashboard/realtime', [$admin, 'dashboardRealtime'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/dashboard/trends', [$admin, 'dashboardTrends'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/dashboard/rankings', [$admin, 'dashboardRankings'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/dashboard/security', [$admin, 'dashboardSecurity'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/dashboard/performance', [$admin, 'dashboardPerformance'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/dashboard/multimodal', [$admin, 'dashboardMultimodal'], [new AdminAuthMiddleware()]);
        $router->get('/admin-api/operations/status', [$admin, 'operationsStatus'], [new AdminAuthMiddleware()]);
        $router->post('/admin-api/alerts/{id}/status', [$admin, 'updateAlertStatus'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/media-assets/{asset_id}/download-url', [$admin, 'mediaDownloadUrl'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
        $router->post('/admin-api/log-archive-files/{id}/download-url', [$admin, 'archiveDownloadUrl'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);

        foreach (GatewayController::ENDPOINTS as $endpoint) {
            $router->post($endpoint, [$gateway, 'proxy'], [new GatewayAuthMiddleware()]);
        }
        // Stateful Responses API features are not proxied: answer explicitly
        // instead of a generic 404 so SDK users see the contract boundary.
        $router->get('/v1/responses/{id}', [$gateway, 'responsesStateUnsupported'], [new ApiKeyAuthMiddleware()]);
        $router->delete('/v1/responses/{id}', [$gateway, 'responsesStateUnsupported'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/responses/{id}/cancel', [$gateway, 'responsesStateUnsupported'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/responses/{id}/input_items', [$gateway, 'responsesStateUnsupported'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/tasks/{id}', [$gateway, 'task'], [new GatewayAuthMiddleware()]);
        $router->post('/v1/task-callbacks/{task_id}/{signature}', [$gateway, 'taskCallback']);
        // Realtime relay (Go realtime-gateway): internal session/usage API and browser client secrets.
        $realtime = new \TokenHub\Http\Controllers\RealtimeController();
        $router->post('/internal/realtime/sessions', [$realtime, 'sessions']);
        $router->post('/internal/realtime/usage', [$realtime, 'usage']);
        $router->post('/v1/realtime/client_secrets', [$realtime, 'clientSecret'], [new GatewayAuthMiddleware()]);
        // Gemini-native API (Google GenAI SDKs): model in the path.
        foreach (['/v1beta/models/{model}:generateContent', '/v1beta/models/{model}:streamGenerateContent', '/v1/models/{model}:generateContent', '/v1/models/{model}:streamGenerateContent'] as $geminiPath) {
            $router->post($geminiPath, [$gateway, 'proxy'], [new GatewayAuthMiddleware()]);
        }
        $router->post('/v1/tasks/{id}:refresh', [$gateway, 'refreshTask'], [new GatewayAuthMiddleware()]);
        $router->post('/v1/tasks/{id}:cancel', [$gateway, 'cancelTask'], [new GatewayAuthMiddleware()]);
        // OpenAI Videos API surface (id = TokenHub task id).
        $router->get('/v1/videos/{id}', [$gateway, 'video'], [new GatewayAuthMiddleware()]);
        $router->get('/v1/videos/{id}/content', [$gateway, 'videoContent'], [new GatewayAuthMiddleware()]);
        $router->delete('/v1/videos/{id}', [$gateway, 'deleteVideo'], [new GatewayAuthMiddleware()]);
        $router->get('/v1/assets/{id}/download-url', [$gateway, 'assetDownloadUrl'], [new GatewayAuthMiddleware()]);
        $router->get('/v1/me', [$external, 'me'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/models', [$external, 'models'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/quota', [$external, 'quota'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/usage/summary', [$usage, 'summary'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/usage/trends', [$usage, 'trends'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/usage/records', [$external, 'usageRecords'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/requests/{request_id}', [$external, 'requestDetail'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/sync/devices', [$sync, 'registerDevice'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/sync/devices', [$sync, 'devices'], [new ApiKeyAuthMiddleware()]);
        $router->delete('/v1/sync/devices/{device_id}', [$sync, 'deleteDevice'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/sync/manifest', [$sync, 'manifest'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/sync/namespaces', [$sync, 'namespaces'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/sync/records', [$sync, 'records'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/sync/records:batch', [$sync, 'batchRecords'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/sync/records/{namespace}/{client_record_id}', [$sync, 'record'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/sync/records/{namespace}/{client_record_id}:resolve-conflict', [$sync, 'resolveConflict'], [new ApiKeyAuthMiddleware()]);
        $router->put('/v1/sync/records/{namespace}/{client_record_id}', [$sync, 'upsertRecord'], [new ApiKeyAuthMiddleware()]);
        $router->delete('/v1/sync/records/{namespace}/{client_record_id}', [$sync, 'deleteRecord'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/files', [$files, 'upload'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/assets', [$files, 'assets'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/files/{file_id}/download-url', [$files, 'downloadUrl'], [new ApiKeyAuthMiddleware()]);
        $router->delete('/v1/files/{file_id}', [$files, 'delete'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/rag/import-templates', [$rag, 'templates'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/rag/import-templates/{template_id}/download', [$rag, 'downloadTemplate'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/rag/knowledge-bases', [$rag, 'knowledgeBases'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/rag/knowledge-bases/{kb_id}/datasets', [$rag, 'datasets'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/rag/knowledge-bases/{kb_id}/uploads', [$rag, 'upload'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/rag/uploads/{upload_id}', [$rag, 'uploadStatus'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/rag/uploads/{upload_id}/preview', [$rag, 'preview'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/rag/uploads/{upload_id}/confirm-vectorization', [$rag, 'confirmVectorization'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/rag/search', [$rag, 'search'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/rag/retrievals/{retrieval_id}', [$rag, 'retrieval'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/rag/feedback', [$rag, 'feedback'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/capabilities', [$external, 'capabilities'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/models/{model+}', [$external, 'modelDetail'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/model-health', [$external, 'modelHealth'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/openapi.json', [$external, 'openapi'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/events', [$external, 'events'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/webhooks', [$webhooks, 'create'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/webhooks', [$webhooks, 'index'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/webhooks/{id}/deliveries', [$webhooks, 'deliveries'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/webhooks/{id}:test', [$webhooks, 'test'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/webhooks/{id}:rotate-secret', [$webhooks, 'rotateSecret'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/webhook-deliveries/{delivery_id}:retry', [$webhooks, 'retryDelivery'], [new ApiKeyAuthMiddleware()]);
        $router->delete('/v1/webhooks/{id}', [$webhooks, 'delete'], [new ApiKeyAuthMiddleware()]);
        $router->post('/v1/feedback', [$external, 'feedback'], [new ApiKeyAuthMiddleware()]);
        $router->get('/v1/management/department', [$agentManagement, 'department'], [new AgentManagementAuthMiddleware(AgentManagementAuthMiddleware::ANY_SCOPE)]);
        $router->get('/v1/management/usage/summary', [$agentManagement, 'usageSummary'], [new AgentManagementAuthMiddleware('usage:read')]);
        $router->get('/v1/management/usage/trends', [$agentManagement, 'usageTrends'], [new AgentManagementAuthMiddleware('usage:read')]);
        $router->get('/v1/management/users', [$agentManagement, 'users'], [new AgentManagementAuthMiddleware('users:read')]);
        $router->get('/v1/management/users/{id}', [$agentManagement, 'user'], [new AgentManagementAuthMiddleware('users:read')]);
        $router->post('/v1/management/users', [$agentManagement, 'createUser'], [new AgentManagementAuthMiddleware('users:write')]);
        $router->patch('/v1/management/users/{id}', [$agentManagement, 'updateUser'], [new AgentManagementAuthMiddleware('users:write')]);
        $router->delete('/v1/management/users/{id}', [$agentManagement, 'deleteUser'], [new AgentManagementAuthMiddleware('users:write')]);
        $router->get('/v1/management/api-keys', [$agentManagement, 'apiKeys'], [new AgentManagementAuthMiddleware('api_keys:read')]);
        $router->post('/v1/management/api-keys', [$agentManagement, 'createApiKey'], [new AgentManagementAuthMiddleware('api_keys:write')]);
        $router->get('/v1/management/api-keys/{id}', [$agentManagement, 'showApiKey'], [new AgentManagementAuthMiddleware('api_keys:read')]);
        $router->patch('/v1/management/api-keys/{id}', [$agentManagement, 'updateApiKey'], [new AgentManagementAuthMiddleware('api_keys:write')]);
        $router->post('/v1/management/api-keys/{id}:rotate', [$agentManagement, 'rotateApiKey'], [new AgentManagementAuthMiddleware('api_keys:write')]);
        $router->get('/v1/management/api-keys/{id}/quota', [$agentManagement, 'apiKeyQuota'], [new AgentManagementAuthMiddleware('api_keys:read')]);
        $router->put('/v1/management/api-keys/{id}/models', [$agentManagement, 'replaceApiKeyModels'], [new AgentManagementAuthMiddleware('api_keys:write')]);
        $router->delete('/v1/management/api-keys/{id}', [$agentManagement, 'deleteApiKey'], [new AgentManagementAuthMiddleware('api_keys:write')]);
        $router->get('/v1/management/rag/knowledge-bases', [$agentManagement, 'knowledgeBases'], [new AgentManagementAuthMiddleware('rag:read')]);
        $router->post('/v1/management/rag/knowledge-bases', [$agentManagement, 'createKnowledgeBase'], [new AgentManagementAuthMiddleware('rag:write')]);
        $router->patch('/v1/management/rag/knowledge-bases/{id}', [$agentManagement, 'updateKnowledgeBase'], [new AgentManagementAuthMiddleware('rag:write')]);
        $router->delete('/v1/management/rag/knowledge-bases/{id}', [$agentManagement, 'deleteKnowledgeBase'], [new AgentManagementAuthMiddleware('rag:write')]);
        $router->get('/v1/management/rag/datasets', [$agentManagement, 'datasets'], [new AgentManagementAuthMiddleware('rag:read')]);
        $router->post('/v1/management/rag/datasets', [$agentManagement, 'createDataset'], [new AgentManagementAuthMiddleware('rag:write')]);
        $router->patch('/v1/management/rag/datasets/{id}', [$agentManagement, 'updateDataset'], [new AgentManagementAuthMiddleware('rag:write')]);
        $router->delete('/v1/management/rag/datasets/{id}', [$agentManagement, 'deleteDataset'], [new AgentManagementAuthMiddleware('rag:write')]);

        // Model Context Protocol (Streamable HTTP, stateless). See docs/mcp.md.
        $router->post('/mcp', [$mcp, 'handle'], [new McpAuthMiddleware()]);
        $router->get('/mcp', [$mcp, 'methodNotAllowed']);
        // Built-in OAuth 2.1 server so MCP clients can sign in (docs/mcp.md).
        $router->get('/.well-known/oauth-protected-resource', [$oauth, 'protectedResource']);
        $router->get('/.well-known/oauth-protected-resource/mcp', [$oauth, 'protectedResource']);
        $router->get('/.well-known/oauth-authorization-server', [$oauth, 'serverMetadata']);
        $router->get('/.well-known/openid-configuration', [$oauth, 'serverMetadata']);
        $router->post('/oauth/register', [$oauth, 'register']);
        $router->get('/oauth/authorize', [$oauth, 'authorize']);
        $router->post('/oauth/authorize', [$oauth, 'approve']);
        $router->post('/oauth/token', [$oauth, 'token']);
        $router->post('/oauth/revoke', [$oauth, 'revoke']);
        $router->delete('/mcp', [$mcp, 'methodNotAllowed']);

        $router->post('/screen-api/auth/login', [$screen, 'login']);
        $router->post('/screen-api/auth/share-login', [$screen, 'shareLogin']);
        $router->post('/screen-api/auth/logout', [$screen, 'logout'], [new ScreenAuthMiddleware()]);
        $router->get('/screen-api/auth/me', [$screen, 'me'], [new ScreenAuthMiddleware()]);
        $router->get('/screen-api/system/brand-identity', [$screen, 'brandIdentity']);
        $router->get('/screen-api/assets/{id}/download-url', [$screen, 'assetDownloadUrl'], [new ScreenAuthMiddleware()]);
        foreach (['overview', 'realtime', 'trends', 'rankings', 'security', 'performance', 'multimodal'] as $metric) {
            $router->get("/screen-api/dashboard/{$metric}", fn ($request) => $screen->dashboard($request, $metric), [new ScreenAuthMiddleware()]);
        }

        return $router;
    }

    private static function adminResources(): array
    {
        return [
            'departments' => 'departments',
            'users' => 'users',
            'providers' => 'providers',
            'models' => 'models',
            'model-prices' => 'model_prices',
            'api-key-model-quotas' => 'api_key_model_quotas',
            'quota-policies' => 'quota_policies',
            'quota-bindings' => 'quota_bindings',
            'usage-records' => 'usage_records',
            'multimodal-tasks' => 'multimodal_tasks',
            'media-assets' => 'media_assets',
            'usage-contents' => 'usage_contents',
            'export-tasks' => 'export_tasks',
            'desensitization-policies' => 'desensitization_policies',
            'desensitization-events' => 'desensitization_events',
            'log-retention-policies' => 'log_retention_policies',
            'log-archive-files' => 'log_archive_files',
            'alert-events' => 'alert_events',
            'audit-logs' => 'audit_logs',
            'system-settings' => 'system_settings',
            'rag-knowledge-bases' => 'rag_knowledge_bases',
            'rag-knowledge-base-bindings' => 'rag_knowledge_base_bindings',
            'rag-vector-datasets' => 'rag_vector_datasets',
            'rag-dataset-bindings' => 'rag_dataset_bindings',
            'rag-vector-configs' => 'rag_vector_configs',
            'rag-import-templates' => 'rag_import_templates',
            'rag-uploads' => 'rag_uploads',
            'rag-upload-preview-items' => 'rag_upload_preview_items',
            'rag-documents' => 'rag_documents',
            'rag-chunks' => 'rag_chunks',
            'rag-image-assets' => 'rag_image_assets',
            'rag-embedding-jobs' => 'rag_embedding_jobs',
            'rag-vector-records' => 'rag_vector_records',
            'rag-retrieval-logs' => 'rag_retrieval_logs',
            'rag-answer-feedback' => 'rag_answer_feedback',
            'rag-correction-tasks' => 'rag_correction_tasks',
            'rag-retention-policies' => 'rag_retention_policies',
            'rag-eval-cases' => 'rag_eval_cases',
            'rag-eval-runs' => 'rag_eval_runs',
            'rag-eval-results' => 'rag_eval_results',
        ];
    }
}
