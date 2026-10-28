<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$externalDoc = file_get_contents(__DIR__ . '/../../docs/external-api-sync.md') ?: '';
$developmentDoc = file_get_contents(__DIR__ . '/../../docs/development-tasks/13-external-api-sync-agent-calls.md') ?: '';

assert_true(str_contains($tableJs, 'Kimi API / Moonshot'), 'Admin provider presets should expose Kimi API / Moonshot.');
assert_true(str_contains($tableJs, 'providerBaseUrlPresets.moonshot'), 'Kimi API / Moonshot preset should use the Moonshot base URL preset.');
assert_true(str_contains($tableJs, 'Kimi Code'), 'Admin provider presets should expose Kimi Code separately.');
assert_true(str_contains($tableJs, 'providerBaseUrlPresets.kimi_code'), 'Kimi Code preset should use the Kimi Code base URL preset.');

foreach ([
    'Kimi API / Moonshot',
    'Kimi Code',
    'https://api.moonshot.cn/v1',
    'https://api.moonshot.ai/v1',
    'https://api.kimi.com/coding/v1',
    'https://api.kimi.com/coding/',
    'kimi-k2.7-code-highspeed',
    'kimi-for-coding',
    'Claude Code-compatible',
    'Anthropic Messages response',
    'upstream only sees the TokenHub server egress IP',
] as $needle) {
    assert_true(
        str_contains($externalDoc, $needle) || str_contains($developmentDoc, $needle),
        'Kimi Codex compatibility docs should mention ' . $needle . '.'
    );
}
