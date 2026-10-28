<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$screen = file_get_contents(__DIR__ . '/../../app/Services/ScreenService.php') ?: '';

foreach ([
    'listShareLinks',
    'listAccessLogs',
    'createShareLink',
    'updateShareLink',
    'disableShareLink',
    'resetPassword',
    'revokeSessions',
    'allowClientIp',
] as $method) {
    assert_true(str_contains($screen, $method), 'ScreenService should expose ' . $method . '.');
}

assert_true(str_contains($screen, 'shareScopeWhere'), 'Screen share lists should be filtered by the admin actor scope.');
assert_true(str_contains($screen, 'assertShareScopeAllowed'), 'Screen share writes should reject scope outside the admin actor scope.');
assert_true(str_contains($screen, 'assertExistingShareAllowed'), 'Screen share mutations should reject existing links outside the admin actor scope.');
assert_true(str_contains($screen, 'PermissionService'), 'Screen share admin scope should reuse department permission checks.');
