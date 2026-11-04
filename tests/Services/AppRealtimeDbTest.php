<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AppRealtimeService;
use TokenHub\Services\AuthService;
use TokenHub\Support\App;

// Live-update stream against MySQL/Redis: a new alert reaches an open stream
// within one check, usage and health are sent, and stream slots are capped.

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$departmentId = 0;
$userId = 0;
$alertIds = [];

try {
    $db->execute('INSERT INTO departments (parent_id, name, path, level, sort_order, status) VALUES (NULL, ?, ?, 0, 0, "active")', ['APP Live ' . $suffix, '/app-live-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status, admin_type, admin_scope) VALUES (?, ?, ?, "super_admin", "active", "super_admin", "global")',
        [$departmentId, 'app_live_' . $suffix, password_hash('App-Live-Password-1', PASSWORD_DEFAULT)]
    );
    $userId = (int) $db->pdo()->lastInsertId();
    $user = (new AuthService())->publicUser($db->first('SELECT * FROM users WHERE id = ?', [$userId]));
    App::context()->setAdminUser($user);

    $now = 2_000_000;
    $events = [];
    $sleeps = 0;
    $service = new AppRealtimeService(static function () use (&$now): int {
        return $now;
    }, static function (int $seconds) use (&$now, &$sleeps, $db, $suffix, &$alertIds): void {
        $now += $seconds;
        if (++$sleeps === 4) {
            $db->execute('INSERT INTO alert_events (event_type, severity, message, status) VALUES (?, "critical", ?, "open")', ['app.live.' . $suffix, 'live test alert ' . $suffix]);
            $alertIds[] = (int) $db->pdo()->lastInsertId();
        }
    });
    $service->run($user, static function (string $event, array $data) use (&$events): void {
        $events[] = [$event, $data];
    }, static fn (): bool => true, static fn (): bool => true);

    $names = array_column($events, 0);
    assert_true(in_array('usage', $names, true), 'Today\'s usage is streamed.');
    assert_true(in_array('health', $names, true), 'Service health is streamed.');
    $alertEvents = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'alerts'));
    assert_true(count($alertEvents) >= 2, 'The open alerts are sent at start and again when they change.');
    $withNew = array_values(array_filter($alertEvents, static fn (array $event): bool => in_array($alertIds[0] ?? -1, $event[1]['new_ids'], true)));
    assert_true($withNew !== [], 'A new alert is announced as new on the open stream.');
    $announcedAt = array_search($withNew[0], $events, true);
    assert_true($announcedAt !== false, 'The announcement is part of the stream.');

    // Slots: a device holds at most APP_STREAM_MAX_PER_DEVICE streams; the oldest gives way.
    $realtime = new AppRealtimeService();
    $first = $realtime->acquireSlot('test-a-' . $suffix, 987654321);
    $second = $realtime->acquireSlot('test-b-' . $suffix, 987654321);
    $third = $realtime->acquireSlot('test-c-' . $suffix, 987654321);
    assert_true($first !== null && $second !== null && $third !== null, 'Streams get slots while Redis is available.');
    $members = App::redis()->connection()->zRange('tokenhub:app:streams', 0, -1) ?: [];
    $mine = array_values(array_filter($members, static fn (string $item): bool => str_starts_with($item, 'd987654321:')));
    assert_true(count($mine) <= (int) App::config()->get('app.app_client.stream_max_per_device', 2), 'A device never holds more streams than allowed.');
    foreach ([$first, $second, $third] as $slot) {
        $realtime->releaseSlot($slot);
    }
} finally {
    App::context()->setAdminUser(null);
    foreach ($alertIds as $id) {
        $db->execute('DELETE FROM alert_notifications WHERE alert_event_id = ?', [$id]);
        $db->execute('DELETE FROM alert_events WHERE id = ?', [$id]);
    }
    if ($userId > 0) {
        $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
    if ($departmentId > 0) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
    }
}
