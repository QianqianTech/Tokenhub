<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$domainsPath = __DIR__ . '/../../public/admin/assets/js/domains.js';
$domains = is_file($domainsPath) ? (file_get_contents($domainsPath) ?: '') : '';
$app = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js') ?: '';
$css = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$adminHtml = file_get_contents(__DIR__ . '/../../public/admin/index.html') ?: '';

assert_true(!str_contains($domains, 'key: "waf"') && !str_contains($domains, '"waf-'), 'Admin navigation should not expose the removed WAF domain.');
assert_true(str_contains($domains, '"operations-status"'), 'System operations should expose an operations-status tab.');
assert_true(str_contains($domains, '"rag-build", "rag-retrieval-lab", "rag-quality", "rag-operations"'), 'RAG domain should use the current four workspace tabs.');
assert_true(str_contains($app, 'hashchange') && str_contains($domains, 'location.hash'), 'Admin navigation should support refreshable hash deep links.');
assert_true(str_contains($app, 'adminRestoreHashRoute'), 'Admin boot should restore the current URL route after authentication.');
assert_true(str_contains($app, 'renderDomainPage'), 'Admin app should render domain tabs before individual resource content.');
assert_true(str_contains($app, 'adminSectionIsOpen(domain)'), 'Sidebar domains should expose collapsible second-level navigation.');
assert_true(str_contains($app, 'adminNavigateDomain(domain.key, item.key)'), 'Sidebar domain children should navigate through the selected domain tab.');
assert_true(str_contains($app, 'aria-controls') && str_contains($app, 'aria-expanded'), 'Sidebar domain toggles should expose accessible expanded state.');
assert_true(str_contains($app, 'adminCreateDomainIcon'), 'Sidebar domains should render real local icons instead of Chinese character badges.');
assert_true(!str_contains($css, 'background: var(--panel);'), 'Domain tabs should not rely on the removed panel color variable.');
assert_true(!str_contains($css, 'background: var(--accent);'), 'Active domain tabs should not rely on the removed accent color variable.');
assert_true(
    (bool) preg_match('/\.domain-tab\.active\s*\{[^}]*background:\s*color-mix\(in srgb, var\(--primary\) 12%, var\(--surface\)\);[^}]*color:\s*var\(--primary\);/s', $css),
    'Active domain tabs should keep readable primary-colored text on a themed light background.'
);
// Derive the current cache version from the entry module instead of pinning a date.
preg_match('/app\.js\?v=(\d{8}-[A-Za-z0-9._-]+)/', $adminHtml, $entryVersionMatch);
$adminAssetVersion = $entryVersionMatch[1] ?? '';
assert_true($adminAssetVersion !== '', 'Admin HTML should load a cache-busted entry module.');
assert_true(
    str_contains($adminHtml, 'app.css?v=' . $adminAssetVersion . '"'),
    'Admin HTML should load the current cache-busted stylesheet with the same version as the entry module.'
);
assert_true(str_contains($css, '.nav-section.active > .nav-section-toggle'), 'The active business domain should have a clear sidebar selection style.');
