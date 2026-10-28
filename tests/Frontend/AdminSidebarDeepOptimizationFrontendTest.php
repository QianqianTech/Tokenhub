<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$index = file_get_contents(__DIR__ . '/../../public/admin/index.html') ?: '';
$appJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js') ?: '';
$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$css = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$navListStart = strpos($appJs, 'function adminRenderNavList');
$navListEnd = $navListStart === false ? false : strpos($appJs, 'function adminDomainForTab', $navListStart);
$navListSource = $navListStart === false || $navListEnd === false ? '' : substr($appJs, $navListStart, $navListEnd - $navListStart);

foreach (['adminSidebarState', 'adminEnsureSidebarShell', 'adminRenderNavList', 'adminCaptureNavScroll', 'adminRestoreNavScroll'] as $hook) {
    assert_true(str_contains($appJs, $hook), 'Sidebar should expose stable state/render hook: ' . $hook);
}

assert_true(str_contains($appJs, 'ADMIN_SIDEBAR_MODE_COOKIE'), 'Sidebar mode should use a same-site cookie.');
assert_true(str_contains($appJs, 'ADMIN_NAV_RECENT_COOKIE'), 'Recent navigation should persist only resource keys.');
assert_true(str_contains($appJs, 'adminAllowedRecentPages'), 'Recent resources should be filtered by current role.');
assert_true(str_contains($appJs, 'domainsForUser') && str_contains($appJs, 'adminVisibleNavSections'), 'Stable sidebar rendering should preserve the capability-filtered domain navigation model.');
assert_true(!str_contains($navListSource, "return;\n  adminCaptureNavScroll();"), 'Domain navigation must not leave the stable sidebar renderer unreachable.');
assert_true(!str_contains($appJs, 'adminRenderNavSearch') && !str_contains($appJs, 'nav-search-input'), 'Sidebar should not retain a menu search renderer or input.');
assert_true(str_contains($appJs, 'adminOpenMobileNav') && str_contains($appJs, 'adminCloseMobileNav'), 'Mobile drawer should expose explicit open and close paths.');
assert_true(str_contains($appJs, 'adminUpdateSidebarModeToggle') && str_contains($appJs, '"关闭菜单"'), 'Mobile drawer should expose a correctly named close control.');
assert_true(str_contains($appJs, 'event.key === "Escape"') && !str_contains($appJs, 'event.key.toLowerCase() === "k"'), 'Sidebar should retain mobile-drawer Escape handling without a menu search shortcut.');
assert_true(str_contains($index, 'id="sidebar-toggle"') && str_contains($index, 'id="sidebar-backdrop"'), 'Admin shell should expose mobile drawer controls.');
assert_true(str_contains($index, 'aria-label="主导航"'), 'Navigation should have an accessible label.');
assert_true(str_contains($appJs, 'ADMIN_DOMAIN_ICON_PATHS') && str_contains($appJs, 'adminCreateDomainIcon'), 'Navigation domains should use local SVG icons instead of text badges.');
assert_true(str_contains($appJs, 'document.createElementNS') && str_contains($appJs, 'viewBox'), 'Sidebar icons should be rendered as inline SVG.');
assert_true(!str_contains($appJs, 'ADMIN_DOMAIN_SYMBOLS'), 'Sidebar domains should not use one-character text symbols as icons.');
assert_true(str_contains($navListSource, 'className: "nav-list"') && str_contains($navListSource, 'adminNavigateDomain(domain.key, item.key)'), 'Each domain should render navigable second-level items in the sidebar.');
assert_true((bool) preg_match('/app\.js\?v=20260710-[A-Za-z0-9._-]+/', $index), 'Admin shell should load a cache-busted JavaScript entrypoint.');

foreach (['.app-shell.sidebar-compact', '.sidebar-backdrop', '.sidebar-mobile-open', ':focus-visible', 'prefers-reduced-motion'] as $selector) {
    assert_true(str_contains($css, $selector), 'Sidebar CSS should define: ' . $selector);
}
assert_true(str_contains($css, ".app-shell.sidebar-compact .nav-list-host {\n  overflow-y: auto;"), 'Compact navigation should remain independently scrollable in short viewports.');
assert_true(str_contains($css, 'scrollbar-width: thin'), 'Sidebar navigation should use a thin scrollbar in Firefox.');
assert_true(str_contains($css, '.nav-list-host::-webkit-scrollbar'), 'Sidebar navigation should style its Chromium scrollbar.');
assert_true(str_contains($css, 'width: 6px'), 'Sidebar scrollbar should stay visually narrow.');
assert_true(str_contains($css, '.nav-list-host::-webkit-scrollbar-track') && str_contains($css, 'background: transparent'), 'Sidebar scrollbar track should be transparent.');
assert_true(str_contains($css, '.nav-list-host::-webkit-scrollbar-thumb') && str_contains($css, 'border: 0'), 'Sidebar scrollbar thumb should not have a border.');
assert_true(str_contains($css, '.nav-list-host::-webkit-scrollbar-button') && str_contains($css, 'display: none'), 'Sidebar scrollbar should hide native arrow buttons.');
assert_true((bool) preg_match('/app\.css\?v=20260710-[A-Za-z0-9._-]+/', $index), 'Admin shell should load a cache-busted stylesheet.');

$assetSources = str_replace('http://www.w3.org/2000/svg', '', $index . $appJs . $resources . $css);
assert_true(!preg_match('/https?:\/\//', $assetSources), 'Admin sidebar should not load external assets.');
