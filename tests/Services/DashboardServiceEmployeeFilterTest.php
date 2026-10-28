<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\DashboardService;
use TokenHub\Support\App;

App::bootstrap(dirname(__DIR__, 2));

$db = App::db();
$service = new DashboardService();
$departments = [890301, 890302];
$users = [890401, 890402, 890403];
$bucket = gmdate('Y-m-d 00:00:00');

$cleanup = static function () use ($db, $departments, $users): void {
    foreach (['usage_aggregates_day', 'usage_aggregates_hour'] as $table) {
        $db->execute("DELETE FROM {$table} WHERE user_id IN (?, ?, ?)", $users);
    }
    $db->execute('DELETE FROM users WHERE id IN (?, ?, ?)', $users);
    $db->execute('DELETE FROM departments WHERE id IN (?, ?)', $departments);
};

$cleanup();

try {
    foreach ($departments as $index => $departmentId) {
        $db->execute(
            'INSERT INTO departments (id, parent_id, name, path, level, sort_order, status) VALUES (?, NULL, ?, ?, 0, ?, "active")',
            [$departmentId, 'smart-analysis-department-' . ($index + 1), '/' . $departmentId . '/', $index + 1]
        );
    }
    foreach ([
        [$users[0], $departments[0], 'smart_analysis_alice'],
        [$users[1], $departments[0], 'smart_analysis_bob'],
        [$users[2], $departments[1], 'smart_analysis_alice_remote'],
    ] as [$userId, $departmentId, $username]) {
        $db->execute(
            'INSERT INTO users (id, department_id, username, password_hash, role, status) VALUES (?, ?, ?, "hash", "user", "active")',
            [$userId, $departmentId, $username]
        );
    }
    foreach ([
        [$users[0], $departments[0], 30, 300, 100],
        [$users[1], $departments[0], 20, 200, 80],
        [$users[2], $departments[1], 40, 400, 120],
    ] as [$userId, $departmentId, $requests, $inputTokens, $outputTokens]) {
        $db->execute(
            'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, total_cost, latency_ms_sum) VALUES (?, ?, ?, ?, 1, 1, "text_generation", ?, ?, 0, ?, ?, 0, ?)',
            [$bucket, $departmentId, $userId, $userId, $requests, $requests, $inputTokens, $outputTokens, $requests * 100]
        );
    }

    App::context()->setAdminUser([
        'id' => 890501,
        'role' => 'admin',
        'department_id' => $departments[0],
        'managed_department_ids' => json_encode([$departments[0]], JSON_UNESCAPED_SLASHES),
    ]);
    $accessibleRows = $service->rankings([
        'dimension' => 'user',
        'sort' => 'total_tokens',
        'from' => $bucket,
        'employee_query' => 'alice',
    ]);
    assert_same([$users[0]], array_map(static fn (array $row): int => (int) $row['id'], $accessibleRows), 'Employee keyword should only return matching employees inside the admin department scope.');

    App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin', 'department_id' => 1, 'managed_department_ids' => '[]']);
    $departmentRows = $service->rankings([
        'dimension' => 'user',
        'sort' => 'total_tokens',
        'from' => $bucket,
        'department_id' => $departments[0],
        'employee_query' => 'alice',
    ]);
    assert_same([$users[0]], array_map(static fn (array $row): int => (int) $row['id'], $departmentRows), 'Department and employee keyword filters should be applied as an intersection.');

    $emptyRows = $service->rankings([
        'dimension' => 'user',
        'sort' => 'total_tokens',
        'from' => $bucket,
        'employee_query' => 'not-a-user',
    ]);
    assert_same([], $emptyRows, 'Unknown employee keywords should return no usage rows.');
} finally {
    App::context()->setAdminUser(null);
    $cleanup();
}
