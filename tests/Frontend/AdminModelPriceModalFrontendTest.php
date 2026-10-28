<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resourcesJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$tableJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';

assert_true(
    str_contains($resourcesJs, '"price_categories"'),
    'Model price table should expose a modality/category column instead of only raw price columns.'
);

assert_true(
    str_contains($resourcesJs, '"price_summary"'),
    'Model price table should expose a compact price summary column.'
);

assert_true(
    str_contains($tableJs, 'showModelPriceDetails'),
    'Model price rows should provide a details modal for categorized prices.'
);

assert_true(
    str_contains($tableJs, '/admin-api/models'),
    'Model price rendering should load models so model_id can be displayed as text, image, ASR, TTS, or video.'
);

foreach (['文字', '图片', 'ASR', 'TTS', '视频'] as $category) {
    assert_true(
        str_contains($tableJs, $category),
        "Model price details should include {$category} category labels."
    );
}
