<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagRetrievalQualityService;
use TokenHub\Support\App;

$db = App::db();
$superAdmin = ['id' => 1, 'role' => 'super_admin', 'admin_type' => 'super_admin', 'admin_scope' => 'global'];
$qualityServiceSource = file_get_contents(__DIR__ . '/../../app/Services/RagRetrievalQualityService.php') ?: '';
assert_true(str_contains($qualityServiceSource, 'ORDER BY id DESC'), 'Quality sampling must select newest logs by monotonic ID.');
assert_true(!str_contains($qualityServiceSource, 'WHERE created_at >= DATE_SUB'), 'Quality sampling must not apply its time window in SQL before the bounded sample.');
$suffix = bin2hex(random_bytes(4));
$prefix = 'rtr_met_' . $suffix . '_';
$db->execute('DELETE FROM rag_retrieval_logs WHERE retrieval_id LIKE ?', [$prefix . '%']);
register_shutdown_function(static function () use ($db, $prefix): void {
    $db->execute('DELETE FROM rag_retrieval_logs WHERE retrieval_id LIKE ?', [$prefix . '%']);
});
$baseline = (new RagRetrievalQualityService())->summary($superAdmin, 24, 0.35);

$rows = [
    ['hit', 1, 0, 10, [['confidence_score' => 0.92]]],
    ['no-hit', 0, 0, 20, []],
    ['low-confidence', 1, 0, 30, [['confidence_score' => 0.20]]],
    ['fallback', 0, 1, 40, ['not-a-source-array']],
    ['structured-invalid', 1, 0, 50, [['confidence_score' => 'not-numeric'], null, ['rank_signals' => ['confidence_score' => 0.1]]]],
];
foreach ($rows as $index => [$query, $hitCount, $fallback, $latency, $sources]) {
    $sourcesJson = json_encode($sources, JSON_UNESCAPED_UNICODE);
    $db->execute(
        'INSERT INTO rag_retrieval_logs (retrieval_id, request_id, kb_ids, dataset_ids, query, query_modality, top_k, hit_count, max_score, sources, latency_ms, fallback_used, created_at) VALUES (?, ?, "[]", "[]", ?, "text", 5, ?, NULL, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        [$prefix . $index, 'req_met_' . $suffix . '_' . $index, $query, $hitCount, $sourcesJson, $latency, $fallback, 10 - $index]
    );
}

$summary = (new RagRetrievalQualityService())->summary($superAdmin, 24, 0.35);
$expectedTotal = $baseline['total'] + 5;
$expectedMetric = static fn (int $baselineCount, int $increment): array => [
    'count' => $baselineCount + $increment,
    'rate' => round(($baselineCount + $increment) / $expectedTotal, 4),
];
assert_same($expectedTotal, $summary['total'], 'Quality summary must count all retrievals in its time window.');
assert_same($expectedMetric($baseline['no_hit']['count'], 2), $summary['no_hit'], 'No-hit count and rate must be exact.');
assert_same($expectedMetric($baseline['fallback']['count'], 1), $summary['fallback'], 'Fallback count and rate must be exact.');
assert_same($expectedMetric($baseline['low_confidence']['count'], 1), $summary['low_confidence'], 'Low-confidence count and rate must use source confidence safely.');
$latencies = array_map(static fn (array $row): int => max(0, (int) $row['latency_ms']), $db->select('SELECT latency_ms FROM rag_retrieval_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'));
sort($latencies, SORT_NUMERIC);
assert_same(round(array_sum($latencies) / count($latencies), 2), $summary['latency_ms']['average'], 'Average latency must cover the entire window.');
assert_same($latencies[(int) ceil(count($latencies) * 0.95) - 1], $summary['latency_ms']['p95'], 'P95 latency must use nearest-rank calculation.');
assert_same(['fallback', 'low-confidence', 'no-hit'], array_slice(array_column($summary['problematic_queries'], 'query'), 0, 3), 'Problematic queries must follow the newest ID-bounded sample order and exclude healthy hits.');
assert_same($prefix . '3', $summary['problematic_queries'][0]['retrieval_id'] ?? null, 'Problematic queries must include a retrieval id for investigation.');
assert_same([], $summary['problematic_queries'][0]['dataset_ids'] ?? null, 'Problematic queries must decode dataset ids from the retrieval log.');

$bounded = (new RagRetrievalQualityService(3))->summary($superAdmin, 24, 0.35);
assert_same(3, $bounded['capacity'], 'Quality summary must expose its fixed sample capacity.');
assert_same(3, $bounded['sampled'], 'Quality summary must only calculate metrics from its bounded sample.');
assert_same(true, $bounded['truncated'], 'Quality summary must disclose when the retrieval window exceeds its sample capacity.');

$clamped = (new RagRetrievalQualityService())->summary($superAdmin, 0, 2.0);
assert_same(1, $clamped['window_hours'], 'Window must clamp to one hour.');
assert_same(1.0, $clamped['low_confidence_threshold'], 'Confidence threshold must clamp to one.');

$clampedMax = (new RagRetrievalQualityService())->summary($superAdmin, 999, -1.0);
assert_same(720, $clampedMax['window_hours'], 'Window must clamp to 720 hours.');
assert_same(0.0, $clampedMax['low_confidence_threshold'], 'Confidence threshold must clamp to zero.');

$sampleDepartmentId = 0;
$samplePrefix = 'rtr_sample_' . $suffix . '_';
try {
    $db->execute(
        'INSERT INTO departments (parent_id, name, path, level, sort_order, status) VALUES (NULL, ?, ?, 0, 0, "active")',
        ['RAG Quality Sample ' . $suffix, '/rag-quality-sample-' . $suffix . '/']
    );
    $sampleDepartmentId = (int) $db->pdo()->lastInsertId();
    $sampleActor = [
        'id' => 0,
        'role' => 'admin',
        'admin_type' => 'rag_admin',
        'admin_scope' => 'departments',
        'department_id' => $sampleDepartmentId,
        'managed_department_ids' => '[]',
    ];
    $sampleRows = [
        [$samplePrefix . 'oldest_current', date('Y-m-d H:i:s')],
        [$samplePrefix . 'stale', date('Y-m-d H:i:s', time() - 172800)],
        [$samplePrefix . 'middle_current', date('Y-m-d H:i:s')],
        [$samplePrefix . 'newest_current', date('Y-m-d H:i:s')],
    ];
    foreach ($sampleRows as [$retrievalId, $createdAt]) {
        $db->execute(
            'INSERT INTO rag_retrieval_logs (retrieval_id, request_id, department_id, kb_ids, dataset_ids, query, query_modality, top_k, hit_count, max_score, sources, latency_ms, fallback_used, created_at) VALUES (?, ?, ?, "[]", "[]", ?, "text", 5, 0, NULL, "[]", 1, 0, ?)',
            [$retrievalId, 'req_' . $retrievalId, $sampleDepartmentId, $retrievalId, $createdAt]
        );
    }

    $latestSample = (new RagRetrievalQualityService(3))->summary($sampleActor, 1, 0.35);
    assert_same(true, $latestSample['truncated'], 'Capacity truncation must be determined before the requested time window is applied.');
    assert_same(2, $latestSample['sampled'], 'A stale row inside the newest ID-bounded sample must be removed in PHP after capacity is fixed.');
    assert_same(
        [$samplePrefix . 'newest_current', $samplePrefix . 'middle_current'],
        array_column($latestSample['problematic_queries'], 'retrieval_id'),
        'Quality results must retain only current rows from the newest ID-bounded sample.'
    );
} finally {
    $db->execute('DELETE FROM rag_retrieval_logs WHERE retrieval_id LIKE ?', [$samplePrefix . '%']);
    if ($sampleDepartmentId > 0) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$sampleDepartmentId]);
    }
}
