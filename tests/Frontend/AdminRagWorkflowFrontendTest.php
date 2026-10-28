<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';

function assert_ordered_contains(string $source, array $tokens, string $message): void
{
    $offset = 0;
    foreach ($tokens as $token) {
        $position = strpos($source, $token, $offset);
        assert_true($position !== false, $message . ' Missing token ' . $token . '.');
        $offset = $position + strlen($token);
    }
}

foreach ([
    '"rag-console"',
    '"rag-knowledge-management"',
    '"rag-import-review"',
    '"rag-image-vector"',
    '"rag-feedback-correction"',
    '"rag-advanced-data"',
] as $resource) {
    assert_true(str_contains($resources, $resource), 'RAG web workflow should expose category ' . $resource . ' navigation.');
}

foreach ([
    'label: "RAG知识库"',
    'label: "RAG 控制台"',
    'label: "知识库管理"',
    'label: "导入与审核"',
    'label: "图片与向量"',
    'label: "反馈与纠偏"',
    'label: "高级数据"',
] as $label) {
    assert_true(str_contains($resources, $label), 'RAG menu should use Chinese label ' . $label . '.');
}

foreach ([
    'renderRagCategoryPage',
    'RAG_CATEGORY_TABS',
    'rag-category-tabs',
    'renderRagDashboardPage',
    'renderRagEvaluationPage',
    'renderRagTemplateCenterPage',
    'renderRagUploadWorkbenchPage',
    'renderRagUploadDetailPage',
] as $renderer) {
    assert_true(str_contains($tableSource, $renderer), 'RAG web workflow should implement ' . $renderer . '.');
}

foreach ([
    '["rag-dashboard", "总览"]',
    '["rag-vectorization-metrics", "向量化监控"]',
    '["rag-knowledge-bases", "知识库"]',
    '["rag-vector-datasets", "向量数据集"]',
    '["rag-template-center", "模板下载"]',
    '["rag-upload-workbench", "上传导入"]',
    '["rag-image-preview", "图片预览"]',
    '["rag-answer-feedback", "用户反馈"]',
    '["rag-documents", "文档"]',
] as $tab) {
    assert_true(str_contains($tableSource, $tab), 'RAG category pages should include tab ' . $tab . '.');
}

$imageVectorStart = strpos($tableSource, '  "rag-image-vector": [');
$imageVectorEnd = $imageVectorStart === false ? false : strpos($tableSource, '  "rag-feedback-correction": [', $imageVectorStart);
assert_true($imageVectorStart !== false && $imageVectorEnd !== false, 'RAG image/vector tab definition should be discoverable.');
$imageVectorTabs = substr($tableSource, (int) $imageVectorStart, (int) $imageVectorEnd - (int) $imageVectorStart);
assert_true(!str_contains($imageVectorTabs, '"rag-image-preview"'), 'Image/vector category should not expose upload preview as its primary workflow.');
assert_ordered_contains($imageVectorTabs, [
    '["rag-image-assets"',
    '["rag-embedding-jobs"',
    '["rag-vector-records"',
], 'Image/vector category should focus on assets, jobs, and vector records.');

$imagePreviewStart = strpos($tableSource, 'async function renderRagImagePreviewPage');
$imagePreviewEnd = $imagePreviewStart === false ? false : strpos($tableSource, 'function renderRagImagePreviewItems', $imagePreviewStart);
assert_true($imagePreviewStart !== false && $imagePreviewEnd !== false, 'RAG image preview renderer should be discoverable.');
$imagePreviewRenderer = substr($tableSource, (int) $imagePreviewStart, (int) $imagePreviewEnd - (int) $imagePreviewStart);
assert_true(!str_contains($imagePreviewRenderer, 'confirm-vectorization'), 'Standalone image preview page should not confirm vectorization outside upload detail.');

assert_ordered_contains($resources, [
    '"rag-knowledge-bases": {',
    'columns: ["name", "status", "owner_user_id", "department_id", "created_at"]',
    'form: [',
    '{ name: "name", label: "名称" }',
    '{ name: "description", label: "说明", type: "textarea", optional: true }',
    '{ name: "department_id", label: "所属部门", type: "number", optional: true }',
], 'RAG knowledge bases should expose a create form.');
$knowledgeBaseResourceEnd = strpos($resources, '"rag-knowledge-base-bindings": {', strpos($resources, '"rag-knowledge-bases": {'));
$knowledgeBaseResource = substr($resources, strpos($resources, '"rag-knowledge-bases": {'), $knowledgeBaseResourceEnd - strpos($resources, '"rag-knowledge-bases": {'));
assert_true(!str_contains($knowledgeBaseResource, '{ name: "kb_id"'), 'RAG knowledge base create form should not ask admins to type a technical kb_id.');
assert_true(!str_contains($knowledgeBaseResource, '{ name: "visibility"'), 'RAG knowledge base create form should use the default visibility instead of asking admins.');
assert_true(!str_contains($knowledgeBaseResource, '{ name: "status"'), 'RAG knowledge base create form should use the default active status instead of asking admins.');
assert_true(!str_contains($knowledgeBaseResource, '{ name: "chunking_profile"'), 'RAG knowledge base create form should not ask admins for chunking JSON.');
assert_true(!str_contains($knowledgeBaseResource, '{ name: "retrieval_profile"'), 'RAG knowledge base create form should not ask admins for retrieval JSON.');
assert_true(!str_contains($knowledgeBaseResource, 'columns: ["id", "kb_id"'), 'RAG knowledge base list should hide database and technical IDs.');

assert_ordered_contains($resources, [
    '"rag-vector-datasets": {',
    'columns: ["name", "is_default", "status", "chunk_count", "vector_count", "last_indexed_at"]',
    'form: [',
    '{ name: "kb_id", label: "所属知识库", type: "select", coerce: "number", reference: "knowledgeBases" }',
    '{ name: "name", label: "数据集名称" }',
], 'RAG vector datasets should expose a create form.');
$datasetResourceEnd = strpos($resources, '"rag-dataset-bindings": {', strpos($resources, '"rag-vector-datasets": {'));
$datasetResource = substr($resources, strpos($resources, '"rag-vector-datasets": {'), $datasetResourceEnd - strpos($resources, '"rag-vector-datasets": {'));
assert_true(!str_contains($datasetResource, '{ name: "dataset_id"'), 'RAG vector dataset create form should not ask admins to type a technical dataset_id.');
assert_true(!str_contains($datasetResource, '{ name: "collection_prefix"'), 'RAG vector dataset create form should not ask admins to type a technical collection prefix.');
assert_true(!str_contains($datasetResource, 'columns: ["id", "dataset_id"'), 'RAG dataset list should hide database and technical IDs.');

foreach ([
    'resourceKey === "rag-knowledge-bases"',
    'resourceKey === "rag-vector-datasets"',
    'resourceKey === "rag-eval-cases"',
] as $token) {
    assert_true(str_contains($resources, $token), 'RAG resource should use a dedicated status filter: ' . $token . '.');
}

foreach ([
    '/admin-api/rag/import-templates',
    '/admin-api/rag/import-templates/${encodeURIComponent(templateId)}/download',
    '/admin-api/rag/knowledge-bases/${encodeURIComponent(kbId)}/uploads',
    '/admin-api/rag/uploads/${encodeURIComponent(uploadId)}',
    '/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/preview',
    '/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/review',
    '/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/confirm-vectorization',
    '/admin-api/rag/summary',
    '/admin-api/rag/vectorization-metrics',
    '/admin-api/rag/evaluation-summary',
    '/admin-api/rag/evaluation-runs',
] as $path) {
    assert_true(str_contains($tableSource, $path), 'RAG web workflow should call ' . $path . '.');
}

foreach ([
    'FileReader',
    'content_base64',
    'manifest_zip',
    'image_package',
    'page_size: 20',
    'waiting_review',
    'confirm-vectorization',
    'data.source',
    'renderRagPipeline',
    'renderRagStatusBuckets',
    'recall_at_20',
    'renderRagSummaryList',
    'rag-summary-chip',
    'rag-upload-progress-bar',
] as $token) {
    assert_true(str_contains($tableSource, $token), 'RAG web workflow should include ' . $token . '.');
}

foreach ([
    '.rag-dashboard-grid',
    '.rag-category-tabs',
    '.rag-category-tab-panel',
    '.rag-pipeline-steps',
    '.rag-status-grid',
    '.rag-summary-list',
    '.rag-summary-chip-row',
    '.rag-upload-workbench',
    '.rag-preview-tabs',
    '.rag-upload-progress-bar',
    '.rag-image-preview-item',
    '.rag-evaluation-cases',
    '.rag-evaluation-case-card',
    '.rag-evaluation-failure',
] as $selector) {
    assert_true(str_contains($adminCss, $selector), 'RAG workflow CSS should style ' . $selector . '.');
}

$ragEvaluationCssStart = strpos($adminCss, '.rag-evaluation-cases,');
$ragEvaluationCssEnd = strpos($adminCss, '.rag-image-preview-list,', $ragEvaluationCssStart);
assert_true($ragEvaluationCssStart !== false && $ragEvaluationCssEnd !== false, 'RAG evaluation CSS block should be inspectable.');
$ragEvaluationCss = substr($adminCss, $ragEvaluationCssStart, $ragEvaluationCssEnd - $ragEvaluationCssStart);
assert_true(!str_contains($ragEvaluationCss, 'var(--panel-subtle, #f8fafc)'), 'RAG evaluation cards must not fall back to a light-theme-only background.');
assert_true(str_contains($ragEvaluationCss, 'background: var(--surface-soft);'), 'RAG evaluation cards should use the active theme surface.');

foreach ([
    'rag-build',
    'rag-retrieval-lab',
    'rag-quality',
    'rag-operations',
    'renderRagOnboardingPage',
    'renderRagRetrievalLabPage',
    'renderRagImportTaskPage',
    '/admin-api/rag/trial-retrieval',
    '暂无可计算任务',
    '运行完成，质量未达标',
] as $token) {
    assert_true(str_contains($tableSource . $resources, $token), 'RAG workspace should expose ' . $token . '.');
}

assert_true(
    str_contains($tableSource, 'disabled: !state.uploadLoaded'),
    'RAG import task actions should be disabled before an upload is loaded.'
);

assert_true(
    str_contains($resources, 'const ragNavigationItems = ["rag-build", "rag-retrieval-lab", "rag-quality", "rag-operations"]'),
    'RAG sidebar should expose four task workspaces instead of raw data categories.'
);

assert_true(
    str_contains($tableSource, '["rag-import-task", "导入任务"]'),
    'Build workspace should expose one task-oriented import entry.'
);
assert_true(
    str_contains($tableSource, 'ragNavigate("rag-build", { tab: "rag-import-task", upload_id: uploadId })'),
    'A successful upload should open its import task directly without asking for an Upload ID.'
);
$importTaskStart = strpos($tableSource, 'async function renderRagImportTaskPage');
$importTaskEnd = $importTaskStart === false ? false : strpos($tableSource, 'async function refreshRagUploadDetail', $importTaskStart);
assert_true($importTaskStart !== false && $importTaskEnd !== false, 'Import task renderer should be discoverable.');
$importTaskRenderer = substr($tableSource, (int) $importTaskStart, (int) $importTaskEnd - (int) $importTaskStart);
assert_true(!str_contains($importTaskRenderer, 'label: "Upload ID"'), 'Primary import task must not ask the operator to type an Upload ID.');

foreach ([
    'renderRagEvaluationCasesPage',
    '/admin-api/rag/eval-cases',
    '/admin-api/rag/eval-cases/import',
    '/admin-api/rag/eval-runs/${encodeURIComponent(runId)}/failures/${encodeURIComponent(caseId)}/correction',
    '创建评估样本',
    '批量导入样本',
    '转为纠偏任务',
] as $token) {
    assert_true(str_contains($tableSource, $token), 'RAG quality workspace should expose ' . $token . '.');
}

assert_true(
    str_contains($tableSource, 'ragNavigate(nextPage, { openCreate: true })'),
    'RAG onboarding action should request the destination create dialog on its first click.'
);
assert_true(
    str_contains($tableSource, 'const { openCreate = false, ...filterValues } = initialFilters;'),
    'Generic resource pages should keep one-shot create navigation intent out of list filters.'
);
