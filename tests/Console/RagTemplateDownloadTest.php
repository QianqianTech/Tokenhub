<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagTemplateService;

$download = (new RagTemplateService())->download('tpl_text_v1');

assert_true((string) $download['filename'] !== '', 'Template filename should not be empty.');
assert_true((string) $download['body'] !== '', 'Template body should not be empty.');
assert_true(
    str_contains((string) $download['mime_type'], 'spreadsheetml') || str_contains((string) $download['mime_type'], 'csv'),
    'Template should be xlsx when extensions are available or csv fallback otherwise.'
);

$service = new RagTemplateService();
$index = $service->index();
$templateIds = array_column($index['templates'], 'template_id');
assert_true(in_array('tpl_image_package_v1', $templateIds, true), 'Template index should expose image package manifest template.');

$packageTemplate = $service->download('tpl_image_package_v1');
assert_true((string) $packageTemplate['filename'] !== '', 'Image package template filename should not be empty.');
assert_true(str_contains((string) $packageTemplate['body'], 'objects') || str_contains((string) $packageTemplate['body'], 'xl/worksheets'), 'Image package template should include annotation fields.');
