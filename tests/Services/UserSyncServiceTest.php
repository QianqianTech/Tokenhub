<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\UserSyncService;

$service = new UserSyncService();

$hashA = $service->payloadHash(['b' => 2, 'a' => 1]);
$hashB = $service->payloadHash(['a' => 1, 'b' => 2]);
assert_same($hashA, $hashB, 'Payload hash should be stable for object key order.');

assert_same(0, $service->versionFromCursor(null), 'Empty cursor should start from version zero.');
assert_same(128, $service->versionFromCursor('ver_128'), 'Version cursor should parse numeric suffix.');
assert_same(0, $service->versionFromCursor('bad_128'), 'Invalid cursor prefix should be ignored.');

$current = [
    'sync_version' => 12,
    'payload_hash' => $service->payloadHash(['theme' => 'light']),
];
assert_true(
    $service->hasConflict($current, 11, $service->payloadHash(['theme' => 'dark'])),
    'Older base version with changed payload should conflict.'
);
assert_true(
    !$service->hasConflict($current, 12, $service->payloadHash(['theme' => 'dark'])),
    'Matching base version should not conflict.'
);
assert_true(
    !$service->hasConflict($current, 11, $service->payloadHash(['theme' => 'light'])),
    'Older base version with identical payload should be idempotent.'
);

assert_same('settings', $service->normalizeNamespace(' settings '), 'Namespace should be trimmed.');
assert_true(!$service->isValidNamespace('../secret'), 'Namespace should reject path traversal characters.');
assert_true($service->isValidNamespace('agent-state'), 'Namespace should allow agent-state names.');

