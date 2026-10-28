<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';

$quotaBindingStart = strpos($resources, '"quota-bindings": {');
$quotaBindingEnd = strpos($resources, '"usage-records": {', $quotaBindingStart);
assert_true($quotaBindingStart !== false && $quotaBindingEnd !== false, 'Quota binding resource config should be inspectable.');
$quotaBindingResource = substr($resources, $quotaBindingStart, $quotaBindingEnd - $quotaBindingStart);

assert_true(
    str_contains($quotaBindingResource, 'reference: "quotaPolicies"') &&
    str_contains($quotaBindingResource, 'reference: "quotaTargets"'),
    'Quota binding form should select policies and targets by readable names instead of raw IDs.'
);

assert_true(
    str_contains($tableSource, 'resourceKey === "quota-bindings"') &&
    str_contains($tableSource, 'quotaBindingReferenceContext') &&
    str_contains($tableSource, 'quotaPolicyLabel') &&
    str_contains($tableSource, 'quotaTargetLabel'),
    'Quota binding table cells should render readable policy and target labels.'
);
