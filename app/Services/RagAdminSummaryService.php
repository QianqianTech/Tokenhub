<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagAdminSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $windowHours = 24): array
    {
        $windowHours = max(1, min(720, $windowHours));

        return [
            'source' => 'database',
            'window_hours' => $windowHours,
            'totals' => $this->totals(),
            'status' => [
                'uploads' => $this->uploadBucket('u.status'),
                'reviews' => $this->uploadBucket('u.review_status'),
                'vectorization' => $this->uploadBucket('u.vectorize_status'),
                'jobs' => $this->jobBucket('j.status', $windowHours),
                'feedback' => $this->bucket('rag_answer_feedback', 'rating'),
                'corrections' => $this->bucket('rag_correction_tasks', 'status'),
            ],
            'pipeline' => $this->pipeline(),
            'retrieval' => $this->retrieval($windowHours),
            'evaluation' => (new RagRetrievalEvaluationService())->summary($windowHours),
            'recent' => [
                'uploads' => $this->recentUploads(),
                'datasets' => $this->recentDatasets(),
                'jobs' => $this->recentJobs(),
                'retrievals' => $this->recentRetrievals(),
                'feedback' => $this->recentFeedback(),
                'corrections' => $this->recentCorrections(),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function totals(): array
    {
        return [
            'knowledge_bases' => $this->count('rag_knowledge_bases', 'deleted_at IS NULL'),
            'active_knowledge_bases' => $this->count('rag_knowledge_bases', 'status = "active" AND deleted_at IS NULL'),
            'datasets' => $this->countDatasets(),
            'active_datasets' => $this->countDatasets('d.status = "active"'),
            'uploads' => $this->countUploads(),
            'preview_items' => $this->countPreviewItems(),
            'documents' => $this->countDocuments(),
            'chunks' => $this->countChunks('c.lifecycle_status <> "deleted"'),
            'enabled_chunks' => $this->countChunks('c.enabled = 1 AND c.lifecycle_status = "active"'),
            'images' => $this->countImageAssets(),
            'embedding_jobs' => $this->countJobs(),
            'indexed_vectors' => $this->countVectorRecords('v.status = "indexed" AND v.deleted_at IS NULL'),
            'retrievals' => $this->count('rag_retrieval_logs'),
            'feedback' => $this->count('rag_answer_feedback'),
            'open_corrections' => $this->count('rag_correction_tasks', 'status IN ("open","pending","approved","running")'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pipeline(): array
    {
        return [
            [
                'step' => 'uploads',
                'label' => 'Uploads',
                'count' => $this->countUploads(),
                'blocked' => $this->countUploads('u.status IN ("failed","partial_failed","rejected")'),
            ],
            [
                'step' => 'preview',
                'label' => 'Preview items',
                'count' => $this->countPreviewItems(),
                'blocked' => $this->countPreviewItems('p.validation_status = "error"'),
            ],
            [
                'step' => 'documents',
                'label' => 'Documents',
                'count' => $this->countDocuments(),
                'blocked' => $this->countDocuments('doc.parse_status IN ("failed","partial_failed") OR doc.index_status IN ("failed","partial_failed")'),
            ],
            [
                'step' => 'chunks',
                'label' => 'Chunks',
                'count' => $this->countChunks('c.enabled = 1 AND c.lifecycle_status = "active"'),
                'blocked' => $this->countChunks('c.enabled = 0 OR c.lifecycle_status IN ("expired","corrected","superseded","deleted")'),
            ],
            [
                'step' => 'vectors',
                'label' => 'Vectors',
                'count' => $this->countVectorRecords('v.status = "indexed" AND v.deleted_at IS NULL'),
                'blocked' => $this->countVectorRecords('v.status IN ("stale","failed") OR v.deleted_at IS NOT NULL'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function retrieval(int $windowHours): array
    {
        $row = App::db()->first(
            'SELECT COUNT(*) AS total, COALESCE(SUM(fallback_used), 0) AS fallback_count, COALESCE(SUM(hit_count), 0) AS hit_count, COALESCE(ROUND(AVG(latency_ms), 2), 0) AS avg_latency_ms
             FROM rag_retrieval_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowHours . ' HOUR)'
        ) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'fallback_count' => (int) ($row['fallback_count'] ?? 0),
            'hit_count' => (int) ($row['hit_count'] ?? 0),
            'avg_latency_ms' => (float) ($row['avg_latency_ms'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentUploads(): array
    {
        return App::db()->select(
            'SELECT u.upload_id, u.kb_id, u.dataset_id, u.api_key_id, u.content_type, u.status, u.review_status, u.vectorize_status, u.stage, u.progress, u.total_items, u.text_items, u.image_items, u.created_at
             FROM rag_uploads u
             JOIN rag_knowledge_bases k ON k.id = u.kb_id AND k.deleted_at IS NULL
             ORDER BY u.id DESC LIMIT 10'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentDatasets(): array
    {
        return App::db()->select(
            'SELECT d.dataset_id, d.name, d.status, d.chunk_count, d.vector_count, d.last_indexed_at, k.kb_id, k.name AS knowledge_base_name
             FROM rag_vector_datasets d
             JOIN rag_knowledge_bases k ON k.id = d.kb_id AND k.deleted_at IS NULL
             WHERE d.deleted_at IS NULL
             ORDER BY d.updated_at DESC, d.id DESC LIMIT 10'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentJobs(): array
    {
        return App::db()->select(
            'SELECT j.job_id, j.job_type, j.status, j.upload_id, j.dataset_id, j.processed_items, j.total_items, j.progress_percent, j.error_code, j.created_at
             FROM rag_embedding_jobs j
             JOIN rag_knowledge_bases k ON k.id = j.kb_id AND k.deleted_at IS NULL
             LEFT JOIN rag_vector_datasets d ON d.id = j.dataset_id
             WHERE j.dataset_id IS NULL OR d.id IS NOT NULL
             ORDER BY j.id DESC LIMIT 10'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRetrievals(): array
    {
        return App::db()->select(
            'SELECT retrieval_id, request_id, api_key_id, query_modality, top_k, hit_count, max_score, latency_ms, fallback_used, created_at
             FROM rag_retrieval_logs ORDER BY id DESC LIMIT 10'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentFeedback(): array
    {
        return App::db()->select(
            'SELECT feedback_id, retrieval_id, request_id, api_key_id, rating, status, created_at, resolved_at
             FROM rag_answer_feedback ORDER BY id DESC LIMIT 10'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentCorrections(): array
    {
        return App::db()->select(
            'SELECT task_id, feedback_id, retrieval_id, issue_type, priority, status, action, created_at, completed_at
             FROM rag_correction_tasks ORDER BY id DESC LIMIT 10'
        );
    }

    /**
     * @return array<string, int>
     */
    private function bucket(string $table, string $column, ?int $windowHours = null): array
    {
        $where = $windowHours === null ? '' : ' WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . max(1, min(720, $windowHours)) . ' HOUR)';
        $rows = App::db()->select("SELECT {$column} AS bucket_key, COUNT(*) AS c FROM {$table}{$where} GROUP BY {$column} ORDER BY c DESC");
        $result = [];
        foreach ($rows as $row) {
            $key = (string) ($row['bucket_key'] ?? '');
            $result[$key === '' ? 'none' : $key] = (int) ($row['c'] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function uploadBucket(string $column): array
    {
        $rows = App::db()->select(
            "SELECT {$column} AS bucket_key, COUNT(*) AS c
             FROM rag_uploads u
             JOIN rag_knowledge_bases k ON k.id = u.kb_id AND k.deleted_at IS NULL
             GROUP BY {$column}
             ORDER BY c DESC"
        );

        return $this->bucketResult($rows);
    }

    /**
     * @return array<string, int>
     */
    private function jobBucket(string $column, int $windowHours): array
    {
        $rows = App::db()->select(
            "SELECT {$column} AS bucket_key, COUNT(*) AS c
             FROM rag_embedding_jobs j
             JOIN rag_knowledge_bases k ON k.id = j.kb_id AND k.deleted_at IS NULL
             LEFT JOIN rag_vector_datasets d ON d.id = j.dataset_id
             WHERE (j.dataset_id IS NULL OR d.id IS NOT NULL)
               AND j.created_at >= DATE_SUB(NOW(), INTERVAL " . max(1, min(720, $windowHours)) . " HOUR)
             GROUP BY {$column}
             ORDER BY c DESC"
        );

        return $this->bucketResult($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function bucketResult(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $key = (string) ($row['bucket_key'] ?? '');
            $result[$key === '' ? 'none' : $key] = (int) ($row['c'] ?? 0);
        }

        return $result;
    }

    private function count(string $table, string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM ' . $table . ($where === '' ? '' : ' WHERE ' . $where);
        return (int) (App::db()->first($sql)['c'] ?? 0);
    }

    private function countDatasets(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c
            FROM rag_vector_datasets d
            JOIN rag_knowledge_bases k ON k.id = d.kb_id AND k.deleted_at IS NULL
            WHERE d.deleted_at IS NULL';
        if ($where !== '') {
            $sql .= ' AND ' . $where;
        }

        return (int) (App::db()->first($sql)['c'] ?? 0);
    }

    private function countUploads(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c
            FROM rag_uploads u
            JOIN rag_knowledge_bases k ON k.id = u.kb_id AND k.deleted_at IS NULL';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) (App::db()->first($sql)['c'] ?? 0);
    }

    private function countDocuments(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c
            FROM rag_documents doc
            JOIN rag_knowledge_bases k ON k.id = doc.kb_id AND k.deleted_at IS NULL
            WHERE doc.deleted_at IS NULL';
        if ($where !== '') {
            $sql .= ' AND ' . $where;
        }

        return (int) (App::db()->first($sql)['c'] ?? 0);
    }

    private function countChunks(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c
            FROM rag_chunks c
            JOIN rag_knowledge_bases k ON k.id = c.kb_id AND k.deleted_at IS NULL
            JOIN rag_documents doc ON doc.id = c.document_id AND doc.deleted_at IS NULL';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) (App::db()->first($sql)['c'] ?? 0);
    }

    private function countPreviewItems(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c
            FROM rag_upload_preview_items p
            JOIN rag_uploads u ON u.id = p.upload_id
            JOIN rag_knowledge_bases k ON k.id = p.kb_id AND k.deleted_at IS NULL';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) (App::db()->first($sql)['c'] ?? 0);
    }

    private function countImageAssets(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c
            FROM rag_image_assets i
            JOIN rag_uploads u ON u.id = i.upload_id
            JOIN rag_knowledge_bases k ON k.id = i.kb_id AND k.deleted_at IS NULL';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) (App::db()->first($sql)['c'] ?? 0);
    }

    private function countJobs(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c
            FROM rag_embedding_jobs j
            JOIN rag_knowledge_bases k ON k.id = j.kb_id AND k.deleted_at IS NULL
            LEFT JOIN rag_vector_datasets d ON d.id = j.dataset_id
            WHERE (j.dataset_id IS NULL OR d.id IS NOT NULL)';
        if ($where !== '') {
            $sql .= ' AND ' . $where;
        }

        return (int) (App::db()->first($sql)['c'] ?? 0);
    }

    private function countVectorRecords(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c
            FROM rag_vector_records v
            JOIN rag_knowledge_bases k ON k.id = v.kb_id AND k.deleted_at IS NULL
            JOIN rag_vector_datasets d ON d.id = v.dataset_id AND d.deleted_at IS NULL
            LEFT JOIN rag_chunks c ON c.id = v.chunk_id
            LEFT JOIN rag_image_assets i ON i.id = v.image_id
            WHERE (v.chunk_id IS NULL OR c.id IS NOT NULL)
              AND (v.image_id IS NULL OR i.id IS NOT NULL)';
        if ($where !== '') {
            $sql .= ' AND ' . $where;
        }

        return (int) (App::db()->first($sql)['c'] ?? 0);
    }
}
