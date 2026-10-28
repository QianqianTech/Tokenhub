<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$source = file_get_contents(__DIR__ . '/../../app/Services/ApiKeyService.php') ?: '';

assert_true(
    str_contains($source, 'SELECT id FROM users WHERE id = ? AND department_id = ?') &&
    str_contains($source, 'API key user must belong to the selected department') &&
    str_contains($source, "abort_json('ADMIN_INVALID_PAYLOAD'"),
    'API key creation should reject a user that does not directly belong to the selected department.'
);
