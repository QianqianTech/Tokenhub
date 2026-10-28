<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\RagFeedbackService;
use TokenHub\Services\RagAdminSummaryService;
use TokenHub\Services\RagAiImageAnnotationService;
use TokenHub\Services\RagAdminTrialService;
use TokenHub\Services\RagAdminEvaluationCaseService;
use TokenHub\Services\RagPermissionService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Services\RagSearchService;
use TokenHub\Services\RagRetrievalEvaluationService;
use TokenHub\Services\RagRetrievalQualityService;
use TokenHub\Services\RagTemplateService;
use TokenHub\Services\RagUploadService;
use TokenHub\Services\RagVectorizationMetricsService;
use TokenHub\Services\RagVectorConfigService;
use TokenHub\Services\PermissionService;
use TokenHub\Services\SystemSettingsService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class RagController
{
    public function templates(Request $request): Response
    {
        return Response::ok((new RagTemplateService())->index());
    }

    public function downloadTemplate(Request $request): Response
    {
        $file = (new RagTemplateService())->download((string) $request->params['template_id']);
        return Response::raw(
            (string) $file['body'],
            200,
            [
                'Content-Type' => (string) $file['mime_type'],
                'Content-Disposition' => 'attachment; filename="' . (string) $file['filename'] . '"',
                'X-Request-Id' => App::context()->requestId(),
            ]
        );
    }

    public function adminTemplates(Request $request): Response
    {
        return Response::ok((new RagTemplateService())->index());
    }

    public function adminDownloadTemplate(Request $request): Response
    {
        return $this->downloadTemplate($request);
    }

    public function knowledgeBases(Request $request): Response
    {
        return Response::ok((new RagPermissionService())->listKnowledgeBases($this->apiKey(), $request->query));
    }

    public function datasets(Request $request): Response
    {
        return Response::ok((new RagPermissionService())->listDatasets(
            $this->apiKey(),
            (string) $request->params['kb_id'],
            $request->query
        ));
    }

    public function adminUpload(Request $request): Response
    {
        $data = $request->json();
        $departmentId = (int) ($data['department_id'] ?? 0);
        if ($departmentId <= 0) {
            abort_json('RAG_DEPARTMENT_REQUIRED', 'department_id is required for admin RAG upload', 422);
        }
        $admin = App::context()->adminUser() ?? [];
        $permission = new PermissionService();
        $error = $permission->requireAdmin($admin);
        if ($error !== null) {
            abort_json($error['code'], $error['message'], $error['status']);
        }
        if (!$permission->canManage($admin, $departmentId)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Department is outside admin scope', 403);
        }

        return Response::ok(
            (new RagUploadService())->adminUploadForDepartment($admin, $departmentId, (string) $request->params['kb_id'], $data),
            201
        );
    }

    public function upload(Request $request): Response
    {
        return Response::ok(
            (new RagUploadService())->upload($this->apiKey(), (string) $request->params['kb_id'], $request->json(), $request->server),
            201
        );
    }

    public function uploadStatus(Request $request): Response
    {
        return Response::ok((new RagUploadService())->status($this->apiKey(), (string) $request->params['upload_id']));
    }

    public function preview(Request $request): Response
    {
        return Response::ok((new RagUploadService())->preview($this->apiKey(), (string) $request->params['upload_id'], $request->query));
    }

    public function confirmVectorization(Request $request): Response
    {
        return Response::ok((new RagUploadService())->confirm($this->apiKey(), (string) $request->params['upload_id']));
    }

    public function search(Request $request): Response
    {
        return Response::ok((new RagSearchService())->search($this->apiKey(), $request->json()));
    }

    public function retrieval(Request $request): Response
    {
        return Response::ok((new RagRetrievalService())->findRetrieval((string) $request->params['retrieval_id'], $this->apiKey()));
    }

    public function feedback(Request $request): Response
    {
        return Response::ok((new RagFeedbackService())->create($this->apiKey(), $request->json()), 201);
    }

    public function reviewUpload(Request $request): Response
    {
        return Response::ok((new RagUploadService())->reviewUpload(App::context()->adminUser() ?? [], (string) $request->params['upload_id'], $request->json()));
    }

    public function adminUploadStatus(Request $request): Response
    {
        return Response::ok((new RagUploadService())->adminStatus(App::context()->adminUser() ?? [], (string) $request->params['upload_id']));
    }

    public function adminPreview(Request $request): Response
    {
        return Response::ok((new RagUploadService())->adminPreview(App::context()->adminUser() ?? [], (string) $request->params['upload_id'], $request->query));
    }

    public function adminConfirmVectorization(Request $request): Response
    {
        return Response::ok((new RagUploadService())->adminConfirm(App::context()->adminUser() ?? [], (string) $request->params['upload_id']));
    }

    public function reprocessImage(Request $request): Response
    {
        $asset = App::db()->first('SELECT * FROM rag_image_assets WHERE image_id = ?', [(string) $request->params['image_id']]);
        if ($asset === null) {
            abort_json('RAG_IMAGE_NOT_FOUND', 'RAG image asset not found', 404);
        }
        $this->assertAdminImageScope($asset);

        return Response::ok((new RagAiImageAnnotationService())->enrichAsset($asset));
    }

    public function vectorizationMetrics(Request $request): Response
    {
        $settings = (new SystemSettingsService())->loadSection('rag_vectorization_monitoring');
        return Response::ok((new RagVectorizationMetricsService())->summary(
            (int) ($request->query['window_hours'] ?? $settings['window_hours'] ?? 24),
            (float) ($settings['success_rate_threshold'] ?? 95.0)
        ));
    }

    public function testVectorConfig(Request $request): Response
    {
        return Response::ok((new RagVectorConfigService())->probe($request->json()));
    }

    public function evaluationSummary(Request $request): Response
    {
        return Response::ok((new RagRetrievalEvaluationService())->summary((int) ($request->query['window_hours'] ?? 24)));
    }

    public function retrievalQuality(Request $request): Response
    {
        return Response::ok((new RagRetrievalQualityService())->summary(
            App::context()->adminUser() ?? [],
            (int) ($request->query['window_hours'] ?? 24),
            (float) ($request->query['low_confidence'] ?? 0.35),
        ));
    }

    public function runEvaluation(Request $request): Response
    {
        return Response::ok((new RagRetrievalEvaluationService())->run($request->json()), 201);
    }

    public function adminSummary(Request $request): Response
    {
        return Response::ok((new RagAdminSummaryService())->summary((int) ($request->query['window_hours'] ?? 24)));
    }

    public function adminTrialRetrieval(Request $request): Response
    {
        return Response::ok((new RagAdminTrialService())->retrieve(App::context()->adminUser() ?? [], $request->json()));
    }

    public function createEvaluationCase(Request $request): Response
    {
        return Response::ok((new RagAdminEvaluationCaseService())->create(App::context()->adminUser() ?? [], $request->json()), 201);
    }

    public function updateEvaluationCaseStatus(Request $request): Response
    {
        return Response::ok((new RagAdminEvaluationCaseService())->updateStatus(App::context()->adminUser() ?? [], (string) $request->params['case_id'], $request->json()['status'] ?? null));
    }

    public function importEvaluationCases(Request $request): Response
    {
        return Response::ok((new RagAdminEvaluationCaseService())->import(App::context()->adminUser() ?? [], $request->json()), 201);
    }

    public function createEvaluationFailureCorrection(Request $request): Response
    {
        return Response::ok((new RagAdminEvaluationCaseService())->createCorrectionFromFailure(
            App::context()->adminUser() ?? [],
            (string) $request->params['run_id'],
            (string) $request->params['case_id']
        ), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function apiKey(): array
    {
        return App::context()->apiKey() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertAdminUploadScope(string $uploadId): array
    {
        return (new RagUploadService())->adminFindById(App::context()->adminUser() ?? [], $uploadId);
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function assertAdminImageScope(array $asset): void
    {
        $kb = App::db()->first('SELECT department_id FROM rag_knowledge_bases WHERE id = ?', [$asset['kb_id'] ?? 0]);
        $departmentId = $kb['department_id'] ?? null;
        if ($departmentId === null && !empty($asset['upload_id'])) {
            $upload = App::db()->first('SELECT department_id FROM rag_uploads WHERE id = ?', [$asset['upload_id']]);
            $departmentId = $upload['department_id'] ?? null;
        }

        $admin = App::context()->adminUser() ?? [];
        $permission = new PermissionService();
        $error = $permission->requireAdmin($admin);
        if ($error !== null) {
            abort_json($error['code'], $error['message'], $error['status']);
        }
        if (!$permission->canManage($admin, $departmentId === null ? null : (int) $departmentId)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'RAG image is outside admin scope', 403);
        }
    }
}
