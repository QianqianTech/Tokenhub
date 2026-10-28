<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$source = file_get_contents(__DIR__ . '/../Services/ExternalApiGapServiceTest.php') ?: '';

assert_true(str_contains($source, 'try {'), 'External API gap test should wrap synthetic records in a try block.');
assert_true(str_contains($source, 'finally {'), 'External API gap test should clean synthetic records in a finally block.');
assert_true(
    str_contains($source, 'cleanup_synthetic_rag_test_data();'),
    'External API gap test should run synthetic cleanup before the test process exits.'
);
