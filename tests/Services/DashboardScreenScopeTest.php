<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\DashboardService;
use TokenHub\Support\App;

App::bootstrap(dirname(__DIR__, 2));

$db = App::db();
$service = new DashboardService();
$bucket = gmdate('Y-m-d 00:00:00');
$departments = [810101, 810102];
$childDepartment = 810103;
$providerId = 810901;
$modelIds = [810911, 810912];

$cleanup = static function () use ($db, $departments, $childDepartment, $providerId, $modelIds): void {
    $db->execute('DELETE FROM multimodal_tasks WHERE task_id LIKE "screen-overview-task-%"');
    $db->execute('DELETE FROM usage_records WHERE request_id LIKE "screen-overview-usage-%"');
    foreach (['usage_aggregates_day', 'usage_aggregates_hour'] as $table) {
        $db->execute(
            "DELETE FROM {$table} WHERE department_id IN (?, ?, ?)",
            [$departments[0], $departments[1], $childDepartment]
        );
    }
    $db->execute('DELETE FROM departments WHERE id = ?', [$childDepartment]);
    $db->execute('DELETE FROM departments WHERE id IN (?, ?)', $departments);
    $db->execute(
        'DELETE FROM models WHERE id IN (?, ?)',
        $modelIds
    );
    $db->execute('DELETE FROM providers WHERE id = ?', [$providerId]);
};

$cleanup();

try {
    $db->execute(
        'INSERT INTO departments (id, parent_id, name, path, level, sort_order, status) VALUES (?, NULL, "screen-parent", ?, 0, 1, "active")',
        [$departments[0], '/' . $departments[0] . '/']
    );
    $db->execute(
        'INSERT INTO departments (id, parent_id, name, path, level, sort_order, status) VALUES (?, NULL, "screen-other", ?, 0, 2, "active")',
        [$departments[1], '/' . $departments[1] . '/']
    );
    $db->execute(
        'INSERT INTO departments (id, parent_id, name, path, level, sort_order, status) VALUES (?, ?, "screen-child", ?, 1, 1, "active")',
        [$childDepartment, $departments[0], '/' . $departments[0] . '/' . $childDepartment . '/']
    );
    $db->execute(
        'INSERT INTO providers (id, name, protocol, base_url, status) VALUES (?, "screen-overview-provider", "openai", "https://screen-overview.example/v1", "active")',
        [$providerId]
    );
    foreach ($modelIds as $index => $modelId) {
        $db->execute(
            'INSERT INTO models (id, provider_id, name, external_name, capability, status) VALUES (?, ?, ?, ?, "text_generation", "active")',
            [$modelId, $providerId, 'screen-model-' . ($index + 1), 'screen-model-' . ($index + 1)]
        );
    }
    $db->execute(
        'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, total_cost, latency_ms_sum) VALUES (?, ?, 1, 1, 1, 910101, "text_generation", 11, 10, 1, 100, 50, 1.25, 2200)',
        [$bucket, $departments[0]]
    );
    $db->execute(
        'INSERT INTO usage_records (request_id, trace_id, user_id, department_id, api_key_id, provider_id, model_id, model_name, protocol, endpoint, capability, status, http_status, input_tokens, output_tokens, total_tokens, cost_amount, latency_ms, client_ip) VALUES ("screen-overview-usage-missing-model", "screen-overview-trace-missing-model", 1, ?, 1, 1, 910101, "historic-screen-real-model", "openai", "/v1/chat/completions", "text_generation", "success", 200, 100, 50, 150, 0.10, 200, "127.0.0.1")',
        [$departments[0]]
    );
    $db->execute(
        'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, total_cost, latency_ms_sum) VALUES (?, ?, 2, 2, 2, 910102, "text_generation", 29, 29, 0, 300, 80, 2.50, 2900)',
        [$bucket, $departments[1]]
    );
    $db->execute(
        'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, total_cost, latency_ms_sum) VALUES (?, ?, 1, 1, ?, ?, "text_generation", 5, 5, 0, 50, 25, 0.50, 500)',
        [$bucket, $childDepartment, $providerId, $modelIds[0]]
    );
    foreach ($departments as $index => $departmentId) {
        $db->execute(
            'INSERT INTO usage_aggregates_hour (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, total_cost, latency_ms_sum) VALUES (?, ?, ?, ?, ?, ?, "text_generation", ?, ?, ?, ?, ?, ?, ?)',
            [
                gmdate('Y-m-d H:00:00'),
                $departmentId,
                $index + 1,
                $index + 1,
                $index + 1,
                910101 + $index,
                $index === 0 ? 11 : 29,
                $index === 0 ? 10 : 29,
                $index === 0 ? 1 : 0,
                $index === 0 ? 100 : 300,
                $index === 0 ? 50 : 80,
                $index === 0 ? 1.25 : 2.50,
                $index === 0 ? 2200 : 2900,
            ]
        );
    }
    foreach ([
        ['now', 8, 7, 1, 800, 200, $modelIds[0]],
        ['-2 days', 6, 6, 0, 600, 150, $modelIds[0]],
        ['-20 days', 4, 4, 0, 400, 100, $modelIds[1]],
        ['-2 months', 3, 3, 0, 300, 70, $modelIds[1]],
        ['-8 months', 2, 2, 0, 200, 50, $modelIds[0]],
    ] as $row) {
        [$modifier, $requests, $success, $failed, $input, $output, $modelId] = $row;
        $db->execute(
            'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, total_cost, latency_ms_sum) VALUES (?, ?, 1, 1, ?, ?, "text_generation", ?, ?, ?, ?, ?, 1.00, 1000)',
            [gmdate('Y-m-d 00:00:00', strtotime($modifier)), $departments[0], $providerId, $modelId, $requests, $success, $failed, $input, $output]
        );
    }
    for ($index = 1; $index <= 4; $index++) {
        $status = $index <= 2 ? 'processing' : 'succeeded';
        $db->execute(
            'INSERT INTO multimodal_tasks (task_id, task_type, status, progress, user_id, department_id, api_key_id, provider_id, model_id, submit_payload, created_at) VALUES (?, "image_generation", ?, ?, 1, ?, 1, ?, ?, ?, ?)',
            [
                'screen-overview-task-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                $status,
                min(99, $index * 8),
                $departments[0],
                $providerId,
                $modelIds[$index % 2],
                json_encode(['prompt' => 'screen overview task ' . $index], JSON_UNESCAPED_SLASHES),
                gmdate('Y-m-d H:i:s', time() - $index),
            ]
        );
    }
    $db->execute(
        'INSERT INTO multimodal_tasks (task_id, task_type, status, progress, user_id, department_id, api_key_id, provider_id, model_id, submit_payload, created_at) VALUES ("screen-overview-task-missing-model", "video_generation", "processing", 88, 1, ?, 1, 1, 910101, ?, ?)',
        [
            $departments[0],
            json_encode(['prompt' => 'screen overview missing model task'], JSON_UNESCAPED_SLASHES),
            gmdate('Y-m-d H:i:s'),
        ]
    );
    for ($index = 1; $index <= 10; $index++) {
        $db->execute(
            'INSERT INTO usage_records (request_id, trace_id, user_id, department_id, api_key_id, provider_id, model_id, model_name, protocol, endpoint, capability, status, http_status, input_tokens, output_tokens, total_tokens, cost_amount, latency_ms, client_ip) VALUES (?, ?, 1, ?, 1, ?, ?, ?, "openai", "/v1/chat/completions", "text_generation", "success", 200, 100, 40, 140, 0.10, 200, "127.0.0.1")',
            [
                'screen-overview-usage-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'screen-overview-trace-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                $departments[0],
                $providerId,
                $modelIds[$index % 2],
                'screen-model-' . (($index % 2) + 1),
            ]
        );
    }

    $screen = [
        'scope_type' => 'department',
        'scope_ids' => json_encode([$departments[0]], JSON_UNESCAPED_SLASHES),
        'allowed_metrics' => json_encode(['realtime', 'rankings', 'trends'], JSON_UNESCAPED_SLASHES),
        'allow_drilldown' => 0,
    ];

    $realtime = $service->screenMetric('realtime', $screen);
    assert_true(($realtime['blocked'] ?? false) === false, 'Allowed screen metric should not be blocked.');
    assert_same(24, (int) $realtime['data']['today']['request_count'], 'Screen realtime metric should apply department scope including child departments.');

    $rankings = $service->screenMetric('rankings', $screen);
    assert_true(($rankings['blocked'] ?? false) === false, 'Allowed ranking metric should not be blocked.');
    assert_true(isset($rankings['data'][0]['request_count']), 'Ranking aggregate values should be returned.');
    assert_true(!array_key_exists('id', $rankings['data'][0]), 'Ranking detail id should be stripped when drilldown is disabled.');

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = [$severity, $message];
        return true;
    });
    try {
        $trends = $service->screenMetric('trends', $screen);
    } finally {
        restore_error_handler();
    }
    assert_same([], $warnings, 'Screen trends should not emit warnings when bucket is omitted.');
    assert_true(($trends['blocked'] ?? false) === false, 'Allowed trends metric should not be blocked.');
    assert_true(isset($trends['data'][0]['request_count']), 'Trend aggregate values should be returned.');

    $overviewScreen = [
        'scope_type' => 'department',
        'scope_ids' => json_encode([$departments[0]], JSON_UNESCAPED_SLASHES),
        'allowed_metrics' => json_encode(['overview'], JSON_UNESCAPED_SLASHES),
        'allow_drilldown' => 0,
    ];
    $overview = $service->screenMetric('overview', $overviewScreen);
    assert_true(($overview['blocked'] ?? false) === false, 'Allowed overview metric should not be blocked.');
    assert_same('overview', $overview['metric'] ?? '', 'Overview metric should identify itself.');
    assert_true((int) ($overview['data']['totals']['total_tokens'] ?? 0) > 0, 'Overview should include total token usage.');
    assert_same(
        (int) ($overview['data']['totals']['input_tokens'] ?? 0) + (int) ($overview['data']['totals']['output_tokens'] ?? 0),
        (int) ($overview['data']['totals']['total_tokens'] ?? 0),
        'Overview total tokens should equal input plus output tokens.'
    );
    assert_same(0, (int) ($overview['data']['totals']['cache_tokens'] ?? -1), 'Overview should expose cache token totals.');
    assert_true(count($overview['data']['model_usage'] ?? []) >= 2, 'Overview should include model usage ranking.');
    assert_true(count($overview['data']['model_token_share'] ?? []) >= 2, 'Overview should include model token share for pie chart.');
    $missingModelUsage = array_values(array_filter($overview['data']['model_usage'] ?? [], static fn (array $row): bool => (int) ($row['model_id'] ?? 0) === 910101));
    $missingModelShare = array_values(array_filter($overview['data']['model_token_share'] ?? [], static fn (array $row): bool => (int) ($row['model_id'] ?? 0) === 910101));
    assert_same('historic-screen-real-model', $missingModelUsage[0]['model_name'] ?? null, 'Overview should display the recorded real model name when the model config row is missing.');
    assert_same('historic-screen-real-model', $missingModelShare[0]['model_name'] ?? null, 'Model token share should display the recorded real model name when the model config row is missing.');
    assert_true(isset($overview['data']['period_totals']['today']['total_tokens']), 'Overview should include today token total.');
    assert_true(isset($overview['data']['period_totals']['week']['total_tokens']), 'Overview should include week token total.');
    assert_true(isset($overview['data']['period_totals']['month']['total_tokens']), 'Overview should include month token total.');
    assert_true(isset($overview['data']['period_totals']['quarter']['total_tokens']), 'Overview should include quarter token total.');
    assert_true(isset($overview['data']['period_totals']['year']['total_tokens']), 'Overview should include year token total.');
    assert_same(10, count($overview['data']['live_generations'] ?? []), 'Overview should return the latest 10 live generation tasks.');
    assert_true(isset($overview['data']['live_generations'][0]['model_name']), 'Live generation rows should include model names.');
    assert_true(array_key_exists('user_name', $overview['data']['live_generations'][0]), 'Live generation rows should include user names.');
    assert_true(array_key_exists('department_name', $overview['data']['live_generations'][0]), 'Live generation rows should include department names.');
    assert_same('historic-screen-real-model', $overview['data']['live_generations'][0]['model_name'] ?? null, 'Live generation rows should display the recorded real model name when the model config row is missing.');
    assert_same('processing', $overview['data']['live_generations'][0]['status'] ?? '', 'Live generation rows should prioritize active tasks.');
    assert_true(
        in_array('succeeded', array_map(static fn (array $row): string => (string) ($row['status'] ?? ''), $overview['data']['live_generations']), true),
        'Live generation rows should backfill with recent generated tasks when fewer than 10 active tasks exist.'
    );

    $security = $service->screenMetric('security', $screen);
    assert_true(($security['blocked'] ?? false) === true, 'Disallowed screen metric should be blocked.');
} finally {
    $cleanup();
}
