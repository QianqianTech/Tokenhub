<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ExternalEventService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$departmentId = $userId = $apiKeyId = 0;
try {
    $db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, ?, 0, "active")', ['Event Dept ' . $suffix, '/event-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, "hash", "user", "active")', [$departmentId, 'event_user_' . $suffix]);
    $userId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "evt", "test", ?, "active")', [$userId, $departmentId, 'Event Key ' . $suffix, hash('sha256', $suffix)]);
    $apiKeyId = (int) $db->pdo()->lastInsertId();
    for ($i = 1; $i <= 3; $i++) {
        $db->execute('INSERT INTO external_api_events (event_id, api_key_id, event_type, source, resource_id, status, summary) VALUES (?, ?, "request.success", "request", ?, "success", "{}")', ['evt_test_' . $suffix . '_' . $i, $apiKeyId, 'req_' . $i]);
    }
    $service = new ExternalEventService();
    $first = $service->events(['id' => $apiKeyId], ['limit' => 2]);
    assert_same(2, count($first['events']), 'The first outbox page should respect limit.');
    assert_same(true, $first['has_more'], 'The first outbox page should expose remaining events.');
    assert_true(preg_match('/^evt_\d+$/', (string) $first['next_cursor']) === 1, 'The outbox cursor should use the stable event id format.');
    $second = $service->events(['id' => $apiKeyId], ['limit' => 2, 'cursor' => $first['next_cursor']]);
    assert_same(1, count($second['events']), 'The next outbox page should contain the remaining event only.');
    assert_same(false, $second['has_more'], 'The final outbox page should not claim more events.');
} finally {
    if ($apiKeyId > 0) $db->execute('DELETE FROM api_keys WHERE id = ?', [$apiKeyId]);
    if ($userId > 0) $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    if ($departmentId > 0) $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
}
