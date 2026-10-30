<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagUploadService
{
    public function __construct(private readonly ?RagEmbeddingJobService $embeddingJobs = null)
    {
    }

    private const MAX_BYTES = 20971520;
    private const PACKAGE_MAX_BYTES = 104857600;

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function adminUploadForDepartment(array $admin, int $departmentId, string $kbId, array $data): array
    {
        if ($departmentId <= 0) {
            abort_json('RAG_DEPARTMENT_REQUIRED', 'department_id is required for admin RAG upload', 422);
        }

        return $this->upload([
            'user_id' => $admin['id'] ?? null,
            'department_id' => $departmentId,
            '_admin_upload' => true,
        ], $kbId, $data);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upload(array $apiKey, string $kbId, array $data): array
    {
        $processing = is_array($data['processing'] ?? null) ? $data['processing'] : [];
        $permissions = new RagPermissionService();
        $dataset = null;
        if (!empty($processing['target_dataset_id'])) {
            // Write on the target dataset is enough (a dataset grant does not imply the whole knowledge base).
            $dataset = $permissions->datasetByExternalId($apiKey, (string) $processing['target_dataset_id'], 'write');
            $kb = App::db()->first('SELECT * FROM rag_knowledge_bases WHERE kb_id = ? AND deleted_at IS NULL AND status != "disabled"', [$kbId]);
            if ($kb === null) {
                abort_json('RAG_KB_NOT_FOUND', 'Knowledge base not found', 404);
            }
            $this->assertDatasetMatchesKnowledgeBase((int) $kb['id'], $dataset);
        } else {
            $kb = $permissions->knowledgeBase($apiKey, $kbId, 'write');
        }

        $packageMode = $this->isPackageUpload($data, $processing);
        $fileName = $this->safeName((string) ($data['filename'] ?? ($packageMode ? 'rag-image-package.zip' : 'rag-upload.csv')));
        $binary = base64_decode((string) ($data['content_base64'] ?? $data['base64'] ?? ''), true);
        if ($binary === false) {
            abort_json('RAG_INVALID_PAYLOAD', 'content_base64 must be valid base64', 422);
        }
        if (strlen($binary) > $this->maxBytes($packageMode)) {
            abort_json('RAG_UPLOAD_TOO_LARGE', 'Upload exceeds RAG file size limit', 413);
        }

        $documentMode = $this->isDocumentUpload($data, $processing, $fileName, $packageMode);
        $redactionTypes = RagRedactionService::resolveTypes($processing, $kb['chunking_profile'] ?? null);
        $sha256 = hash('sha256', $binary);
        if ($documentMode && ($processing['allow_duplicate'] ?? false) !== true) {
            // Same file already in this knowledge base: tell the caller instead of indexing it twice (RAG-022).
            $duplicate = App::db()->first(
                'SELECT upload_id FROM rag_uploads WHERE kb_id = ? AND sha256 = ? AND status NOT IN ("failed","rejected","deleted") ORDER BY id DESC LIMIT 1',
                [$kb['id'], $sha256]
            );
            if ($duplicate !== null) {
                abort_json('RAG_UPLOAD_DUPLICATE', 'This file is already in the knowledge base (upload ' . $duplicate['upload_id'] . '); set processing.allow_duplicate=true to import it again', 409);
            }
        }

        $dir = storage_path('rag' . DIRECTORY_SEPARATOR . 'uploads');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $uploadId = $this->id('upl');
        $path = $dir . DIRECTORY_SEPARATOR . $uploadId . '_' . $fileName;
        file_put_contents($path, $binary);

        $template = $packageMode ? 'image' : ($documentMode ? 'document' : (string) ($processing['template'] ?? $data['content_type'] ?? 'text'));
        $contentType = $packageMode ? 'zip' : ($documentMode ? 'document' : (string) ($data['content_type'] ?? ($template === 'qa' ? 'qa' : ($template === 'image' ? 'image' : 'excel'))));
        $documentKey = $documentMode ? $this->documentKey($data, $processing, (string) ($data['filename'] ?? $fileName)) : null;
        $sourceType = !empty($apiKey['_admin_upload']) ? 'admin' : 'api';
        App::db()->execute(
            'INSERT INTO rag_uploads (upload_id, kb_id, dataset_id, owner_user_id, department_id, api_key_id, device_id, source_type, content_type, original_filename, mime_type, size_bytes, sha256, file_hash, storage_path, metadata, preview_status, vectorize_status, status, confirm_required, review_status, current_stage, stage, progress_percent, progress)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "building", "not_started", "previewing", ?, "not_required", "parse", "parse", 10, 10)',
            [
                $uploadId,
                $kb['id'],
                $dataset['id'] ?? null,
                $apiKey['user_id'] ?? null,
                $apiKey['department_id'] ?? null,
                $apiKey['id'] ?? null,
                $data['device_id'] ?? null,
                $sourceType,
                $contentType,
                $fileName,
                $data['mime_type'] ?? 'application/octet-stream',
                strlen($binary),
                $sha256,
                $sha256,
                $path,
                json_encode(array_filter([
                    'template' => $template,
                    'package_mode' => $packageMode ? 'manifest_zip' : null,
                    'client_metadata' => $data['metadata'] ?? [],
                    'original_filename' => $documentMode ? (string) ($data['filename'] ?? $fileName) : null,
                    'document_key' => $documentKey,
                    'chunking_profile' => $documentMode && is_array($processing['chunking_profile'] ?? null) ? $processing['chunking_profile'] : null,
                    'redaction_types' => $redactionTypes === [] ? null : $redactionTypes,
                ], static fn ($value): bool => $value !== null), JSON_UNESCAPED_UNICODE),
                !empty($processing['confirm_required']) || !($processing['auto_vectorize'] ?? false) ? 1 : 0,
            ]
        );
        $upload = $this->findById($uploadId, $apiKey);
        $this->buildPreview($upload, $template);
        $this->applyUploadReviewGate($uploadId);
        $upload = $this->findById($uploadId, $apiKey);

        if (($processing['auto_vectorize'] ?? false) === true && (int) $upload['confirm_required'] === 0 && (string) ($upload['review_status'] ?? 'not_required') !== 'pending' && (string) $upload['preview_status'] === 'ready') {
            return $this->confirm($apiKey, $uploadId, ['dataset_id' => $dataset['dataset_id'] ?? null]);
        }

        return $this->publicUpload($upload);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function status(array $apiKey, string $uploadId): array
    {
        return $this->publicUpload($this->findById($uploadId, $apiKey));
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function preview(array $apiKey, string $uploadId, array $query): array
    {
        $upload = $this->findById($uploadId, $apiKey);
        return $this->previewForUpload($upload, $uploadId, $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function adminPreview(array $admin, string $uploadId, array $query): array
    {
        $upload = $this->adminFindById($admin, $uploadId);
        return $this->previewForUpload($upload, $uploadId, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminStatus(array $admin, string $uploadId): array
    {
        return $this->publicUpload($this->adminFindById($admin, $uploadId));
    }

    /**
     * @return array<string, mixed>
     */
    public function adminConfirm(array $admin, string $uploadId): array
    {
        $upload = $this->adminFindById($admin, $uploadId);
        return $this->confirm([
            'id' => $upload['api_key_id'] ?? 0,
            'user_id' => $upload['owner_user_id'] ?? null,
            'department_id' => $upload['department_id'] ?? null,
        ], $uploadId);
    }

    /**
     * @param array<string, mixed> $upload
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function previewForUpload(array $upload, string $uploadId, array $query): array
    {
        $type = (string) ($query['type'] ?? 'all');
        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($query['page_size'] ?? 20)));
        $where = ['upload_id = ?'];
        $params = [$upload['id']];
        if ($type === 'text') {
            $where[] = 'item_type IN ("text","qa","table_row")';
        } elseif ($type === 'image') {
            $where[] = 'item_type = "image"';
        } elseif ($type === 'error') {
            $where[] = 'validation_status = "error"';
        } elseif ($type !== 'all') {
            $where[] = 'item_type = ?';
            $params[] = $type;
        }
        $total = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_upload_preview_items WHERE ' . implode(' AND ', $where), $params)['c'] ?? 0);
        $rows = App::db()->select(
            'SELECT * FROM rag_upload_preview_items WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize),
            $params
        );

        return [
            'upload_id' => $uploadId,
            'type' => $type,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'items' => array_map(fn (array $row): array => $this->publicPreviewItem($row), $rows),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function confirm(array $apiKey, string $uploadId, array $data = []): array
    {
        $upload = $this->findById($uploadId, $apiKey);
        if (!in_array((string) $upload['preview_status'], ['ready', 'confirmed'], true)) {
            abort_json('RAG_PREVIEW_NOT_READY', 'Upload preview is not ready', 409);
        }
        if ((string) $upload['preview_status'] === 'confirmed') {
            if (!empty($upload['dataset_id'])) {
                $confirmedDataset = $this->activeDatasetById((int) $upload['dataset_id']);
                if ($confirmedDataset !== null) {
                    $this->recoverConfirmedUpload($upload, $confirmedDataset);
                }
            }
            return $this->publicUpload($upload);
        }
        if (($upload['review_status'] ?? 'not_required') === 'pending') {
            abort_json('RAG_UPLOAD_REVIEW_REQUIRED', 'Upload requires admin review before vectorization', 409);
        }
        if (($upload['review_status'] ?? 'not_required') === 'rejected') {
            abort_json('RAG_UPLOAD_REVIEW_REJECTED', 'Upload was rejected by reviewer', 409);
        }
        $errors = (int) (App::db()->first('SELECT COUNT(*) AS c FROM rag_upload_preview_items WHERE upload_id = ? AND validation_status = "error"', [$upload['id']])['c'] ?? 0);
        if ($errors > 0) {
            abort_json('RAG_PREVIEW_HAS_ERRORS', 'Upload preview contains invalid rows', 422);
        }

        $dataset = null;
        if (!empty($data['dataset_id'])) {
            $dataset = (new RagPermissionService())->datasetByExternalId($apiKey, (string) $data['dataset_id'], 'write');
        } elseif (!empty($upload['dataset_id'])) {
            $dataset = $this->activeDatasetById((int) $upload['dataset_id']);
        }
        if ($dataset === null) {
            $datasets = (new RagPermissionService())->resolveDatasets($apiKey, [], [], [], 'write');
            $dataset = $datasets[0] ?? null;
        }
        if ($dataset === null) {
            abort_json('RAG_DATASET_NOT_FOUND', 'No writable vector dataset available', 404);
        }
        $this->assertDatasetMatchesKnowledgeBase((int) $upload['kb_id'], $dataset);

        $confirmed = App::db()->execute(
            'UPDATE rag_uploads SET dataset_id = ?, preview_status = "confirmed", vectorize_status = "queued", status = "vectorizing", confirmed_by_api_key_id = ?, confirmed_at = NOW(), current_stage = "chunking", stage = "chunking", progress_percent = 45, progress = 45 WHERE id = ? AND preview_status = "ready"',
            [$dataset['id'], $apiKey['id'] ?? null, $upload['id']]
        );
        if ($confirmed === 0) {
            $reloadedUpload = $this->findById($uploadId, $apiKey);
            if (!empty($reloadedUpload['dataset_id'])) {
                $reloadedDataset = $this->activeDatasetById((int) $reloadedUpload['dataset_id']);
                if ($reloadedDataset !== null) {
                    $this->recoverConfirmedUpload($reloadedUpload, $reloadedDataset);
                }
            }
            return $this->publicUpload($reloadedUpload);
        }
        $this->recoverConfirmedUpload($this->findById($uploadId, $apiKey), $dataset);

        return $this->publicUpload($this->findById($uploadId, $apiKey));
    }

    /**
     * @param array<string, mixed> $upload
     * @param array<string, mixed> $dataset
     */
    private function recoverConfirmedUpload(array $upload, array $dataset): void
    {
        $activeDataset = $this->activeDatasetById((int) ($dataset['id'] ?? 0));
        if ($activeDataset === null) {
            return;
        }
        $this->assertDatasetMatchesKnowledgeBase((int) $upload['kb_id'], $activeDataset);
        (new RagChunkingService())->createChunks($upload, $activeDataset);
        ($this->embeddingJobs ?? new RagEmbeddingJobService())->queueUpload((int) $upload['id'], $activeDataset);
    }

    /** @return array<string, mixed>|null */
    private function activeDatasetById(int $datasetId): ?array
    {
        return App::db()->first(
            'SELECT d.*, k.kb_id AS external_kb_id, k.chunking_profile AS kb_chunking_profile, k.retrieval_profile AS kb_retrieval_profile
             FROM rag_vector_datasets d
             JOIN rag_knowledge_bases k ON k.id = d.kb_id
             WHERE d.id = ?
               AND d.deleted_at IS NULL
               AND d.status != "disabled"
               AND k.deleted_at IS NULL
               AND k.status != "disabled"',
            [$datasetId]
        );
    }

    /** @param array<string, mixed> $dataset */
    private function assertDatasetMatchesKnowledgeBase(int $knowledgeBaseId, array $dataset): void
    {
        if ((int) ($dataset['kb_id'] ?? 0) !== $knowledgeBaseId) {
            abort_json('RAG_DATASET_KB_MISMATCH', 'Vector dataset does not belong to the upload knowledge base', 409);
        }
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function reviewUpload(array $admin, string $uploadId, array $data): array
    {
        $settings = $this->reviewSettings();
        if (($settings['enabled'] ?? false) !== true) {
            abort_json('RAG_UPLOAD_REVIEW_DISABLED', 'RAG upload review is disabled', 409);
        }
        $role = (string) ($admin['role'] ?? '');
        if (!in_array($role, $settings['reviewer_roles'] ?? [], true)) {
            abort_json('RAG_UPLOAD_REVIEW_FORBIDDEN', 'Reviewer role is not allowed', 403);
        }

        $upload = $this->adminFindById($admin, $uploadId);
        if (($settings['allow_reviewer_own_upload'] ?? false) !== true && !empty($upload['owner_user_id']) && (int) $upload['owner_user_id'] === (int) ($admin['id'] ?? 0)) {
            abort_json('RAG_UPLOAD_REVIEW_FORBIDDEN', 'Reviewer cannot approve own upload', 403);
        }

        $decision = (string) ($data['decision'] ?? '');
        $comment = substr(trim((string) ($data['comment'] ?? '')), 0, 500);
        if ($decision === 'approve') {
            App::db()->execute(
                'UPDATE rag_uploads SET review_status = "approved", reviewed_by_user_id = ?, reviewed_at = NOW(), review_comment = ?, status = "waiting_confirm", current_stage = "waiting_confirm", stage = "waiting_confirm", progress_percent = 30, progress = 30 WHERE id = ?',
                [$admin['id'] ?? null, $comment, $upload['id']]
            );
        } elseif ($decision === 'reject') {
            App::db()->execute(
                'UPDATE rag_uploads SET review_status = "rejected", reviewed_by_user_id = ?, reviewed_at = NOW(), review_comment = ?, status = "rejected", current_stage = "rejected", stage = "rejected" WHERE id = ?',
                [$admin['id'] ?? null, $comment, $upload['id']]
            );
        } else {
            abort_json('RAG_UPLOAD_REVIEW_INVALID_DECISION', 'decision must be approve or reject', 422);
        }

        (new AuditService())->write('rag.upload.review.' . $decision, 'rag_uploads', (int) $upload['id'], ['upload_id' => $uploadId, 'comment' => $comment]);

        return $this->publicUpload(App::db()->first('SELECT * FROM rag_uploads WHERE id = ?', [$upload['id']]) ?? $upload);
    }

    /**
     * @param array<string, mixed> $upload
     */
    public function buildPreview(array $upload, string $template): void
    {
        $metadata = json_decode((string) ($upload['metadata'] ?? '{}'), true) ?: [];
        if (($metadata['package_mode'] ?? null) === 'manifest_zip') {
            $package = (new RagPackageImportService())->parse((string) $upload['storage_path'], (string) $upload['upload_id']);
            if ($package['error_code'] !== null) {
                abort_json((string) $package['error_code'], (string) $package['error_message'], 422);
            }
            $items = $package['items'];
            $metadata['package_dir'] = $package['package_dir'];
            $metadata['manifest_name'] = $package['manifest_name'];
            App::db()->execute('UPDATE rag_uploads SET metadata = ?, current_stage = "package_validated", stage = "package_validated", progress_percent = 20, progress = 20 WHERE id = ?', [
                json_encode($metadata, JSON_UNESCAPED_UNICODE),
                $upload['id'],
            ]);
        } elseif ($template === 'document') {
            $items = $this->documentPreviewItems($upload, $metadata);
            if ($items === null) {
                return; // handed to the Python worker (scanned PDF / OCR); it writes the preview
            }
        } else {
            $items = (new RagExcelImportService())->parse((string) $upload['storage_path'], $template);
        }
        $imageService = new RagImageProcessingService();
        $redactionTypes = RagRedactionService::normalizeTypes($metadata['redaction_types'] ?? []);
        $redactor = new RagRedactionService();
        foreach ($items as $item) {
            $item = $redactor->redactItem($item, $redactionTypes);
            $asset = $item['item_type'] === 'image' ? $imageService->createAsset($upload, $item) : null;
            App::db()->execute(
                'INSERT INTO rag_upload_preview_items (preview_item_id, upload_id, kb_id, dataset_id, item_type, title, content, question, answer, image_asset_id, purpose, description, detailed_description, `row_number`, sheet_name, validation_status, validation_errors, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->id('pvi'),
                    $upload['id'],
                    $upload['kb_id'],
                    $upload['dataset_id'],
                    $item['item_type'],
                    $item['title'] ?? null,
                    $item['content'] ?? '',
                    $item['question'] ?? null,
                    $item['answer'] ?? null,
                    $asset['id'] ?? null,
                    $item['purpose'] ?? null,
                    $item['description'] ?? null,
                    $item['detailed_description'] ?? null,
                    $item['row_number'] ?? null,
                    $item['sheet_name'] ?? null,
                    $item['validation_status'] ?? 'valid',
                    json_encode($item['validation_errors'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($item['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                ]
            );
        }
        $counts = $this->previewCounts((int) $upload['id']);
        App::db()->execute(
            'UPDATE rag_uploads SET preview_status = "ready", status = "waiting_confirm", total_items = ?, text_items = ?, image_items = ?, current_stage = "waiting_confirm", stage = "waiting_confirm", progress_percent = 30, progress = 30 WHERE id = ?',
            [$counts['total'], $counts['text'], $counts['image'], $upload['id']]
        );
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    private function findById(string $uploadId, array $apiKey): array
    {
        if (!empty($apiKey['id'])) {
            $upload = App::db()->first('SELECT * FROM rag_uploads WHERE upload_id = ? AND api_key_id = ?', [$uploadId, $apiKey['id']]);
        } else {
            $upload = App::db()->first('SELECT * FROM rag_uploads WHERE upload_id = ? AND api_key_id IS NULL AND department_id = ?', [$uploadId, $apiKey['department_id'] ?? 0]);
        }
        if ($upload === null) {
            abort_json('RAG_UPLOAD_NOT_FOUND', 'Upload not found', 404);
        }
        return $upload;
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function adminFindById(array $admin, string $uploadId): array
    {
        $upload = App::db()->first('SELECT * FROM rag_uploads WHERE upload_id = ?', [$uploadId]);
        if ($upload === null) {
            abort_json('RAG_UPLOAD_NOT_FOUND', 'Upload not found', 404);
        }
        $this->assertAdminCanAccessUpload($admin, $upload);

        return $upload;
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $upload
     */
    private function assertAdminCanAccessUpload(array $admin, array $upload): void
    {
        $permission = new PermissionService();
        $error = $permission->requireAdmin($admin);
        if ($error !== null) {
            abort_json($error['code'], $error['message'], $error['status']);
        }
        if (!$permission->canManage($admin, isset($upload['department_id']) ? (int) $upload['department_id'] : null)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Upload is outside admin scope', 403);
        }
    }

    /**
     * @return array{total: int, text: int, image: int}
     */
    private function previewCounts(int $uploadId): array
    {
        $rows = App::db()->select('SELECT item_type, COUNT(*) AS c FROM rag_upload_preview_items WHERE upload_id = ? GROUP BY item_type', [$uploadId]);
        $total = 0;
        $text = 0;
        $image = 0;
        foreach ($rows as $row) {
            $count = (int) $row['c'];
            $total += $count;
            if ($row['item_type'] === 'image') {
                $image += $count;
            } else {
                $text += $count;
            }
        }
        return ['total' => $total, 'text' => $text, 'image' => $image];
    }

    /**
     * @param array<string, mixed> $upload
     * @return array<string, mixed>
     */
    private function publicUpload(array $upload): array
    {
        return [
            'upload_id' => $upload['upload_id'],
            'kb_id' => $upload['kb_id'],
            'dataset_id' => $upload['dataset_id'],
            'status' => $upload['status'],
            'preview_status' => $upload['preview_status'],
            'vectorize_status' => $upload['vectorize_status'],
            'review_status' => $upload['review_status'] ?? 'not_required',
            'reviewed_by_user_id' => $upload['reviewed_by_user_id'] ?? null,
            'reviewed_at' => $upload['reviewed_at'] ?? null,
            'review_comment' => $upload['review_comment'] ?? null,
            'current_stage' => $upload['current_stage'],
            'stage' => $upload['stage'] ?? $upload['current_stage'],
            'progress_percent' => (float) $upload['progress_percent'],
            'progress' => (float) ($upload['progress'] ?? $upload['progress_percent']),
            'owner_user_id' => $upload['owner_user_id'],
            'department_id' => $upload['department_id'],
            'api_key_id' => $upload['api_key_id'],
            'summary' => [
                'total_items' => (int) $upload['total_items'],
                'text_items' => (int) $upload['text_items'],
                'image_items' => (int) $upload['image_items'],
                'processed_items' => (int) $upload['processed_items'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicPreviewItem(array $row): array
    {
        $imageAsset = null;
        if (!empty($row['image_asset_id'])) {
            $asset = App::db()->first('SELECT image_id, original_filename, mime_type, width, height, thumbnail_path, purpose, short_description, detailed_description, ocr_text, labels, warnings, metadata FROM rag_image_assets WHERE id = ?', [$row['image_asset_id']]);
            if ($asset !== null) {
                $imageAsset = [
                    'image_id' => $asset['image_id'],
                    'original_filename' => $asset['original_filename'],
                    'mime_type' => $asset['mime_type'],
                    'width' => $asset['width'],
                    'height' => $asset['height'],
                    'thumbnail_path' => $asset['thumbnail_path'],
                    'purpose' => $asset['purpose'],
                    'short_description' => $asset['short_description'],
                    'detailed_description' => $asset['detailed_description'],
                    'ocr_text' => $asset['ocr_text'],
                    'labels' => json_decode((string) ($asset['labels'] ?? '[]'), true) ?: [],
                    'warnings' => json_decode((string) ($asset['warnings'] ?? '[]'), true) ?: [],
                    'metadata' => json_decode((string) ($asset['metadata'] ?? '{}'), true) ?: [],
                ];
            }
        }

        return [
            'preview_item_id' => $row['preview_item_id'],
            'item_type' => $row['item_type'],
            'title' => $row['title'],
            'content' => $row['content'],
            'question' => $row['question'],
            'answer' => $row['answer'],
            'purpose' => $row['purpose'],
            'description' => $row['description'],
            'detailed_description' => $row['detailed_description'],
            'row_number' => $row['row_number'],
            'sheet_name' => $row['sheet_name'],
            'validation_status' => $row['validation_status'],
            'validation_errors' => json_decode((string) ($row['validation_errors'] ?? '[]'), true) ?: [],
            'metadata' => json_decode((string) ($row['metadata'] ?? '[]'), true) ?: [],
            'image_asset' => $imageAsset,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $processing
     */
    private function isPackageUpload(array $data, array $processing): bool
    {
        return ($processing['package_mode'] ?? null) === 'manifest_zip'
            || ($processing['template'] ?? null) === 'image_package'
            || ($data['content_type'] ?? null) === 'zip';
    }

    /**
     * PDF, Word, PowerPoint, Markdown, HTML and plain text are imported as
     * documents (structure-preserving parse + p2 chunking). CSV/XLSX keep the
     * template import unless processing.template = "document".
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $processing
     */
    private function isDocumentUpload(array $data, array $processing, string $fileName, bool $packageMode): bool
    {
        if ($packageMode) {
            return false;
        }
        if (($processing['template'] ?? null) === 'document' || ($data['content_type'] ?? null) === 'document') {
            return true;
        }
        if (isset($processing['template']) || in_array($data['content_type'] ?? null, ['excel', 'text', 'qa', 'image'], true)) {
            return false;
        }
        $format = RagDocumentParserService::formatFor((string) ($data['filename'] ?? $fileName), (string) ($data['mime_type'] ?? ''));

        return $format !== null && !in_array($format, ['csv', 'xlsx', 'xls'], true);
    }

    /**
     * Stable identity of a document across re-uploads; a new upload with the
     * same key replaces the previous version once it is indexed (RAG-025).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $processing
     */
    private function documentKey(array $data, array $processing, string $fileName): string
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $key = trim((string) ($processing['document_key'] ?? $metadata['document_key'] ?? $metadata['source_uri'] ?? ''));

        return mb_substr($key !== '' ? $key : 'file:' . mb_strtolower(trim($fileName)), 0, 255);
    }

    /**
     * @param array<string, mixed> $upload
     * @param array<string, mixed> $metadata
     * @return list<array<string, mixed>>|null null when parsing was queued for the Python worker
     */
    private function documentPreviewItems(array $upload, array $metadata): ?array
    {
        $filename = (string) ($metadata['original_filename'] ?? $upload['original_filename'] ?? 'document');
        $parsed = (new RagDocumentParserService())->parse((string) $upload['storage_path'], $filename, (string) ($upload['mime_type'] ?? ''));
        if ($parsed['needs_worker']) {
            App::db()->execute(
                'INSERT INTO rag_embedding_jobs (job_id, kb_id, dataset_id, upload_id, job_type, execution_backend, status, total_items) VALUES (?, ?, ?, ?, "parse", "python_local", "queued", 1)',
                [$this->id('job'), $upload['kb_id'], $upload['dataset_id'], $upload['id']]
            );
            App::db()->execute(
                'UPDATE rag_uploads SET current_stage = "parsing", stage = "parsing", progress_percent = 15, progress = 15 WHERE id = ?',
                [$upload['id']]
            );

            return null;
        }
        if (trim(preg_replace('/<!--\s*page:\d+\s*-->/', '', $parsed['markdown']) ?? '') === '') {
            abort_json('RAG_DOCUMENT_EMPTY', 'No text could be extracted from the document', 422);
        }

        return [[
            'item_type' => 'text',
            'title' => $parsed['title'],
            'content' => $parsed['markdown'],
            'metadata' => [
                'document_format' => $parsed['format'],
                'parser' => $parsed['parser'],
                'pages' => $parsed['pages'],
                'parse_warnings' => $parsed['warnings'],
                'chunker' => RagStructuredChunkerService::VERSION,
                'document_key' => $metadata['document_key'] ?? null,
                'chunking_profile' => $metadata['chunking_profile'] ?? null,
            ],
            'validation_status' => $parsed['warnings'] === [] ? 'valid' : 'warning',
            'validation_errors' => $parsed['warnings'],
        ]];
    }

    private function maxBytes(bool $packageMode): int
    {
        if (!$packageMode) {
            return self::MAX_BYTES;
        }

        $row = App::db()->first('SELECT setting_value FROM system_settings WHERE setting_key = "rag_package_upload"');
        $settings = $row === null ? [] : (json_decode((string) $row['setting_value'], true) ?: []);
        $maxMb = (int) ($settings['max_package_mb'] ?? 100);

        return max(1, $maxMb) * 1024 * 1024;
    }

    private function safeName(string $name): string
    {
        return substr(preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $name))) ?: 'rag-upload.csv', 0, 160);
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private function applyUploadReviewGate(string $uploadId): void
    {
        $settings = $this->reviewSettings();
        if (($settings['enabled'] ?? false) !== true) {
            return;
        }

        App::db()->execute(
            'UPDATE rag_uploads SET review_status = "pending", status = "waiting_review", current_stage = "waiting_review", stage = "waiting_review", progress_percent = 30, progress = 30 WHERE upload_id = ?',
            [$uploadId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewSettings(): array
    {
        try {
            return (new SystemSettingsService())->loadSection('rag_upload_review');
        } catch (\Throwable) {
            return [
                'enabled' => false,
                'reviewer_roles' => ['admin', 'super_admin'],
                'allow_reviewer_own_upload' => false,
                'reject_blocks_vectorization' => true,
                'instructions' => '',
            ];
        }
    }
}
