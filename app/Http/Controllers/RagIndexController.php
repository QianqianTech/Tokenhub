<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\PermissionService;
use TokenHub\Services\RagIndexMaintenanceService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

/** Admin endpoints for index health and repair (RAG-030, RAG-043). */
final class RagIndexController
{
    public function health(Request $request): Response
    {
        return Response::ok((new RagIndexMaintenanceService())->health($this->scopedDatasetIds()));
    }

    public function reconcile(Request $request): Response
    {
        $body = $request->json();
        $datasetId = (int) ($body['dataset_id'] ?? 0);
        $scoped = $this->scopedDatasetIds();
        if ($datasetId <= 0 && $scoped !== null) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Reconciling every dataset requires a global administrator', 403);
        }
        if ($datasetId > 0 && $scoped !== null && !in_array($datasetId, $scoped, true)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Dataset is outside admin scope', 403);
        }
        $report = (new RagIndexMaintenanceService())->reconcile($datasetId > 0 ? $datasetId : null, (bool) ($body['fix'] ?? false));
        (new \TokenHub\Services\AuditService())->write('rag.index.reconcile', 'rag_vector_datasets', $datasetId, ['fix' => (bool) ($body['fix'] ?? false), 'orphans' => $report['orphans'], 'missing' => $report['missing']]);

        return Response::ok($report);
    }

    public function backfillLexical(Request $request): Response
    {
        $datasetId = (int) $request->params['id'];
        $scoped = $this->scopedDatasetIds();
        if ($scoped !== null && !in_array($datasetId, $scoped, true)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Dataset is outside admin scope', 403);
        }

        return Response::ok((new RagIndexMaintenanceService())->backfillLexical($datasetId));
    }

    /** @return list<int>|null null for global administrators */
    private function scopedDatasetIds(): ?array
    {
        $admin = App::context()->adminUser();
        if ($admin === null) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }
        $permission = new PermissionService();
        if ($permission->usesGlobalScope($admin)) {
            return null;
        }
        [$where, $params] = $permission->tableScopeSql($admin, 'rag_vector_datasets');

        return array_map(static fn (array $row): int => (int) $row['id'], App::db()->select('SELECT id FROM rag_vector_datasets' . $where, $params));
    }
}
