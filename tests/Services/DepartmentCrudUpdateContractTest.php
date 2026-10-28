<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$crud = file_get_contents(__DIR__ . '/../../app/Services/CrudService.php') ?: '';

assert_true(
    (bool) preg_match("/\\\$table === 'departments' && array_key_exists\\('status', \\\$data\\).*?invalidateDepartmentApiKeys\\(\\\$id\\)/s", $crud),
    'Department status updates through generic CRUD should invalidate department API key cache.'
);
