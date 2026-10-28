<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';

assert_true(
    preg_match('/"api-keys"\s*:\s*\{[^}]*columns:\s*\["id", "name", "created_at", "key", "status"\]/s', $resources) === 1,
    'API key list columns should match the dedicated API Keys page fields.'
);

assert_true(
    preg_match('/"api-keys"\s*:\s*\{[^}]*detailColumns:\s*\[[^\]]*"key_prefix"[^\]]*"cache_write_tokens"[^\]]*"created_at"/s', $resources) === 1,
    'API key detail columns should keep the full key, cache, usage, and timestamp fields.'
);

assert_true(
    str_contains($tableSource, 'resourceKey === "api-keys"') &&
    str_contains($tableSource, 'renderApiKeyPage') &&
    str_contains($tableSource, 'API 密钥只会在新建后显示一次'),
    'API key navigation should render a dedicated page with the screenshot safety notice.'
);

assert_true(
    !str_contains($tableSource, 'API_KEY_LIMIT') &&
    !str_contains($tableSource, '你最多可以创建 5 个 API 密钥'),
    'API key creation must not be blocked by a client-side quantity limit.'
);

assert_true(
    str_contains($tableSource, 'API ID') &&
    str_contains($tableSource, '状态码') &&
    str_contains($tableSource, '新建 API 密钥') &&
    str_contains($tableSource, 'apiKeyDisplay'),
    'API key table should expose the screenshot headers, create action, and masked key display.'
);

assert_true(
    str_contains($tableSource, 'renderApiKeyAnalytics') &&
    str_contains($tableSource, 'api-key-analysis-grid') &&
    str_contains($tableSource, 'request_count') &&
    str_contains($tableSource, 'cache_tokens') &&
    str_contains($tableSource, 'total_cost'),
    'API key page should render aggregate analysis metrics above the table.'
);

assert_true(
    str_contains($tableSource, 'keyword') &&
    str_contains($tableSource, 'department_id') &&
    str_contains($tableSource, '搜索') &&
    str_contains($tableSource, '部门') &&
    str_contains($tableSource, 'department_name') &&
    str_contains($tableSource, 'user_name'),
    'API key page should support keyword and department filters with visible ownership.'
);

assert_true(
    str_contains($tableSource, 'config.detailColumns || config.columns'),
    'API key details should use configured detail columns when present.'
);

assert_true(
    str_contains($tableSource, 'pageSize: 20') &&
    str_contains($tableSource, 'offset: state.offset') &&
    str_contains($tableSource, '共 ${total} 条') &&
    str_contains($tableSource, '上一页') &&
    str_contains($tableSource, '下一页'),
    'API key page should provide server-backed pagination controls.'
);

assert_true(
    str_contains($tableSource, 'Array.isArray(result.data?.items) ? result.data.items : []'),
    'API key reference lookups should accept the paginated API key response.'
);

assert_true(
    str_contains($tableSource, 'const lastOffset = Math.max(0, (Math.ceil(total / pageSize) - 1) * pageSize)') &&
    str_contains($tableSource, 'state.offset !== lastOffset'),
    'API key pagination should return to the last available page after a deletion.'
);
