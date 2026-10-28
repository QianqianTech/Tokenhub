<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css');
$appJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js');

assert_true(is_string($adminCss), 'Admin CSS should be readable.');
assert_true(is_string($appJs), 'Admin app source should be readable.');

assert_true(str_contains($appJs, 'adminSetLoginShell'), 'Admin app should switch the shell between login and authenticated states.');
assert_true(str_contains($appJs, 'adminRefreshPublicBrandIdentity'), 'Admin login page should load public brand settings before authentication.');
assert_true(str_contains($appJs, '/admin-api/system/brand-identity'), 'Admin login page should load the public admin brand identity endpoint.');
assert_true(str_contains($appJs, 'login-title'), 'Admin login page should update the login panel title from brand settings.');
assert_true(str_contains($appJs, 'is-login'), 'Admin app should expose a dedicated login shell class.');
assert_true(str_contains($appJs, 'is-authenticated'), 'Admin app should expose a dedicated authenticated shell class.');

assert_true(str_contains($adminCss, '.app-shell.is-login'), 'Admin CSS should define a standalone login shell state.');
assert_true(str_contains($adminCss, '.app-shell.is-login .sidebar'), 'Standalone login state should target the sidebar.');
assert_true(str_contains($adminCss, '.app-shell.is-login .topbar'), 'Standalone login state should target the topbar.');
assert_true(str_contains($adminCss, '.app-shell.is-login .login-panel'), 'Standalone login state should restyle the login panel.');
