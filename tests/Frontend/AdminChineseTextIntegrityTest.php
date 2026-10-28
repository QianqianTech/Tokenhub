<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$sources = [
    'index.html' => file_get_contents(__DIR__ . '/../../public/admin/index.html'),
    'app.js' => file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js'),
    'api.js' => file_get_contents(__DIR__ . '/../../public/admin/assets/js/api.js'),
    'dashboard.js' => file_get_contents(__DIR__ . '/../../public/admin/assets/js/dashboard.js'),
    'dom.js' => file_get_contents(__DIR__ . '/../../public/admin/assets/js/dom.js'),
    'resources.js' => file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js'),
    'table.js' => file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js'),
];

assert_true($sources !== [], 'Admin frontend sources should be readable.');

$mojibakeFragments = [
    '浼氳瘽',
    '杩愯惀',
    '妯″瀷',
    '鐘舵',
    '寮€濮',
    '璇锋眰',
    '澶辫触',
    '瀵煎嚭',
    '鍚戦噺',
    '鐪熷疄',
];

foreach ($sources as $sourceName => $source) {
    assert_true(is_string($source), "Admin source {$sourceName} should be readable.");
    foreach ($mojibakeFragments as $fragment) {
        assert_true(
            !str_contains($source, $fragment),
            "Admin source {$sourceName} contains mojibake fragment {$fragment}."
        );
    }
}

assert_true(str_contains($sources['resources.js'] ?? '', '"admin-chat-sessions": { label: "会话测试"'), 'Chat session label should render as Chinese.');
assert_true(str_contains($sources['resources.js'] ?? '', '{ key: "operations", label: "运营"'), 'Operations navigation section should render as Chinese.');
assert_true(str_contains($sources['resources.js'] ?? '', '"dashboard-share-links": { label: "大屏分享", group: "运营"'), 'Dashboard share page should render as Chinese.');
assert_true(str_contains($sources['table.js'] ?? '', 'el("h2", { text: "会话测试" })'), 'Chat session header should render as Chinese.');
assert_true(str_contains($sources['table.js'] ?? '', 'button("大屏1"'), 'Dashboard share overview action should render as Chinese.');
assert_true(str_contains($sources['table.js'] ?? '', 'button("大屏2"'), 'Dashboard share operations action should render as Chinese.');
