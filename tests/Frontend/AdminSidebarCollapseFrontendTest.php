<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$appJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js') ?: '';

assert_true(str_contains($appJs, 'return null;') && str_contains($appJs, 'openKeys === null'), 'Admin sidebar should distinguish absent nav cookie from an explicitly empty collapsed state.');
assert_true(str_contains($appJs, 'readNavOpenCookie() ?? new Set()'), 'Admin sidebar toggle should create an open-state set only when the nav cookie is absent.');
assert_true(str_contains($appJs, 'transientOpenKey'), 'Active navigation should use a one-event transient open section.');
assert_true(str_contains($appJs, 'adminToggleNavSection'), 'Section toggling should use one explicit state transition.');
assert_true(!str_contains($appJs, 'openKeys.has(section.key) || section.key === activeSectionKey'), 'Active sections must not be permanently forced open.');
