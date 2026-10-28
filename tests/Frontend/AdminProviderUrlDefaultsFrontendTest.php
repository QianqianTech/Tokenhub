<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$domJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/dom.js') ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';

assert_true(
    str_contains($tableJs, 'providerBaseUrlPresets'),
    'Provider form should define vendor base URL presets.'
);

foreach ([
    'qianqianai' => 'https://api.qianqianai.com/v1',
    'deepseek' => 'https://api.deepseek.com',
    'volcengine' => 'https://ark.cn-beijing.volces.com/api/v3',
    'tencent' => 'https://api.hunyuan.cloud.tencent.com/v1',
    'openai' => 'https://api.openai.com',
    'anthropic' => 'https://api.anthropic.com',
    'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
    'moonshot' => 'https://api.moonshot.cn/v1',
    'kimi_code' => 'https://api.kimi.com/coding/v1',
] as $vendor => $url) {
    assert_true(
        str_contains($tableJs, "\"{$vendor}\": \"{$url}\""),
        "Provider form should map {$vendor} to {$url}."
    );
}

foreach ([
    '\\u9ed4\\u524d\\u667a\\u7b97',
    '\\u706b\\u5c71\\u5f15\\u64ce',
    '\\u817e\\u8baf\\u4e91',
    '\\u517c\\u5bb9 openai',
    '\\u517c\\u5bb9openai',
] as $alias) {
    assert_true(
        str_contains($tableJs, "\"{$alias}\""),
        "Provider form should recognize Chinese vendor alias {$alias}."
    );
}

assert_true(
    str_contains($tableJs, 'bindProviderBaseUrlDefaults(form)'),
    'Provider resource form should bind vendor base URL defaults.'
);

assert_true(
    str_contains($tableJs, 'providerDisplayNamePresets'),
    'Provider form should define visible vendor display presets.'
);

assert_true(
    str_contains($tableJs, 'renderProviderPresetPicker(form)'),
    'Provider resource form should render visible vendor preset buttons.'
);

foreach ([
    '\\u9ed4\\u524d\\u667a\\u7b97',
    'DeepSeek',
    '\\u706b\\u5c71\\u5f15\\u64ce',
    '\\u817e\\u8baf\\u4e91',
    '\\u517c\\u5bb9 OpenAI',
    'Kimi API / Moonshot',
    'Kimi Code',
] as $displayName) {
    assert_true(
        str_contains($tableJs, "name: \"{$displayName}\""),
        "Provider form should display vendor preset {$displayName}."
    );
}

assert_true(
    str_contains($adminCss, '.provider-preset-picker'),
    'Provider preset picker should have visible layout styling.'
);

assert_true(
    str_contains($domJs, 'control.autocomplete = "off"'),
    'Generic admin text inputs should disable browser autocomplete.'
);
