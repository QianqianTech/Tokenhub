<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagVectorizationMetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $windowHours = 24, float $threshold = 95.0): array
    {
        $windowHours = max(1, min(720, $windowHours));
        $threshold = max(1.0, min(100.0, $threshold));
        $rows = App::db()->select(
            'SELECT job_type, status, error_code, COUNT(*) AS c FROM rag_embedding_jobs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowHours . ' HOUR) GROUP BY job_type, status, error_code'
        );

        $total = 0;
        $succeeded = 0;
        $byType = [];
        $failures = [];
        foreach ($rows as $row) {
            $count = (int) $row['c'];
            $type = (string) $row['job_type'];
            $status = (string) $row['status'];
            $total += $count;
            $byType[$type] ??= ['total' => 0, 'succeeded' => 0, 'failed' => 0, 'success_rate' => 0.0];
            $byType[$type]['total'] += $count;
            if ($status === 'succeeded') {
                $succeeded += $count;
                $byType[$type]['succeeded'] += $count;
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                $byType[$type]['failed'] += $count;
                $code = (string) ($row['error_code'] ?: 'UNKNOWN');
                $failures[$code] = ($failures[$code] ?? 0) + $count;
            }
        }

        foreach ($byType as $type => $stats) {
            $byType[$type]['success_rate'] = $stats['total'] > 0 ? round($stats['succeeded'] * 100 / $stats['total'], 2) : 100.0;
        }
        arsort($failures);
        $rate = $total > 0 ? round($succeeded * 100 / $total, 2) : 100.0;

        return [
            'window_hours' => $windowHours,
            'threshold' => $threshold,
            'total_jobs' => $total,
            'succeeded_jobs' => $succeeded,
            'failed_jobs' => max(0, $total - $succeeded),
            'overall_success_rate' => $rate,
            'below_threshold' => $rate < $threshold,
            'alert_code' => $rate < $threshold ? 'RAG_VECTOR_SUCCESS_RATE_LOW' : null,
            'by_job_type' => $byType,
            'top_failures' => $failures,
            'recent_failed_jobs' => App::db()->select(
                'SELECT job_id, job_type, status, error_code, error_message, attempts, created_at FROM rag_embedding_jobs WHERE status IN ("failed","cancelled") ORDER BY id DESC LIMIT 20'
            ),
        ];
    }
}

