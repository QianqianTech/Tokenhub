<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// Models can be found by part of their name: the filter bar sends `keyword`
// and the list query matches it against the model and upstream names.
$resources = (string) file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js');
$crud = (string) file_get_contents(__DIR__ . '/../../app/Services/CrudService.php');

assert_true(
    (bool) preg_match('/resourceKey === "models"\) \{.*?\{ name: "keyword", label: "模型名称"/s', $resources),
    'Model list filters should offer a model-name search field.'
);
assert_true(
    str_contains($resources, '{ name: "provider_id", label: "厂商 ID", type: "number", optional: true },') && str_contains($resources, '{ name: "capability", label: "能力", type: "select", options: capabilityOptions }'),
    'Model list filters should offer provider and capability filters.'
);
assert_true(
    str_contains($crud, "\$table === 'models' && isset(\$query['keyword'])") && str_contains($crud, 'models.name LIKE ? ESCAPE') && str_contains($crud, 'models.external_name LIKE ? ESCAPE'),
    'Model list queries should match keyword against model and upstream names with escaped LIKE patterns.'
);
