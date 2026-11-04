<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// 2026-10-31 console usability (UX-101 … UX-113): static guarantees.
$root = __DIR__ . '/../../public/admin';
$html = (string) file_get_contents($root . '/index.html');
$bundle = admin_js_bundle();

// UX-107: one cache-busting version for every admin asset; base.css removed;
// breakpoints limited to 640 / 900 / 1280 (+ the documented 1100/1023 layouts).
preg_match_all('/\?v=([A-Za-z0-9._-]+)/', $html, $versions);
assert_true(count($versions[1]) >= 5 && count(array_unique($versions[1])) === 1, 'All admin assets should share one cache-busting version: ' . implode(', ', array_unique($versions[1])));
assert_true(!is_file($root . '/assets/css/base.css') && !str_contains($html, 'base.css'), 'The unused base.css should stay deleted.');
$css = '';
foreach (['app.css', 'components.css', 'redesign.css'] as $sheet) {
    $css .= (string) file_get_contents($root . '/assets/css/' . $sheet);
}
preg_match_all('/@media[^{]*?(\d+)px/', $css, $widths);
$unexpected = array_values(array_diff(array_unique($widths[1]), ['640', '900', '901', '1023', '1100', '1280']));
assert_true($unexpected === [], 'Unexpected CSS breakpoints: ' . implode(', ', $unexpected));
assert_true(str_contains($bundle, 'window.innerWidth <= 900'), 'The mobile sidebar switch should follow the 900px breakpoint.');

// UX-108: ops-tools.js split by domain.
foreach (['ops-common', 'provider-catalog', 'api-key-tools', 'diagnostics', 'capability-editor'] as $module) {
    assert_true(is_file($root . '/assets/js/lib/' . $module . '.js'), "lib/{$module}.js should exist.");
}
assert_true(!str_contains($bundle, 'ops-tools.js'), 'Nothing should import the removed ops-tools.js.');

// UX-106: labels, page descriptions and error messages come from the dictionary.
$dictionary = (string) file_get_contents($root . '/assets/js/i18n/zh-CN.js');
foreach (['export const columns', 'export const values', 'export const pageDescriptions', 'export const errors', 'export const ui'] as $export) {
    assert_true(str_contains($dictionary, $export), "zh-CN dictionary should have {$export}.");
}
$resources = (string) file_get_contents($root . '/assets/js/resources.js');
assert_true(str_contains($resources, 'translate(`column.${column}`, column)') && str_contains($resources, 'translate(`value.${column}.${value}`'), 'columnLabel/valueLabel should read the dictionary.');

// UX-101: server timestamps are UTC; filters convert local pickers to UTC.
$format = (string) file_get_contents($root . '/assets/js/lib/format.js');
assert_true(str_contains($format, 'Date.UTC(') && str_contains($format, 'export function utcStamp'), 'Server timestamps without a zone should be parsed as UTC.');
$filters = (string) file_get_contents($root . '/assets/js/lib/list-filters.js');
assert_true(str_contains($filters, 'return utcStamp(date);'), 'serverStamp should send UTC.');

// UX-102: catalog references are pickers, not numeric ids.
assert_true(str_contains($resources, '{ name: "provider_id", label: "厂商", type: "select", coerce: "number", reference: "providers" }'), 'Model form should pick the provider.');
assert_true(str_contains($resources, '{ name: "model_id", label: "模型", type: "select", coerce: "number", reference: "models" }'), 'Price form should pick the model.');

// UX-105: replies are rendered without innerHTML; no browser storage.
$markdown = (string) file_get_contents($root . '/assets/js/lib/markdown.js');
assert_true(!str_contains($markdown, 'innerHTML') && str_contains($markdown, 'createElement'), 'Markdown must build DOM nodes.');
assert_true(str_contains($markdown, '/^(https?:\/\/|mailto:)/i'), 'Markdown links must be limited to safe protocols.');

// UX-113: the login page asks whether recovery is available.
assert_true(str_contains($html, 'id="forgot-password-link" class="login-help login-text-button" type="button" hidden') && str_contains($html, 'id="forgot-password-contact"'), 'The forgot-password link should start hidden with a contact hint.');
$kernel = (string) file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php');
assert_true(str_contains($kernel, "\$router->get('/admin-api/auth/options', [\$recovery, 'options']);"), 'Public auth options route should exist.');

echo "AdminUsabilityFrontendTest OK\n";
