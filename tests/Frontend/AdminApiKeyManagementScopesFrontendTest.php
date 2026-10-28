<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';

$functionSource = static function (string $source, string $name): string {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($source, "\n}\n", $start);

    return substr($source, $start, $end === false ? null : $end - $start);
};

$scopes = [
    'users:read' => '用户（只读）',
    'users:write' => '用户（读写）',
    'api_keys:read' => 'API Key（只读）',
    'api_keys:write' => 'API Key（读写）',
    'rag:read' => '知识库（只读）',
    'rag:write' => '知识库（读写）',
    'usage:read' => '用量（只读）',
];
$previous = -1;
foreach ($scopes as $scope => $label) {
    $needle = '["' . $scope . '", "' . $label . '"]';
    $position = strpos($tableJs, $needle);
    assert_true($position !== false, 'Management scope editor should offer ' . $scope . ' labelled ' . $label);
    assert_true($position > $previous, 'Management scopes should be listed in the backend order.');
    $previous = $position;
}

$editor = $functionSource($tableJs, 'createApiKeyManagementScopeEditor');
assert_true($editor !== '', 'API key forms should have a management scope editor.');
assert_true(str_contains($editor, '"details"') && str_contains($editor, '智能体管理权限'), 'Management scopes should render as a collapsible secondary section.');
assert_true(str_contains($editor, '授予后该 Key 可调用 /v1/management/* 管理本部门的用户、Key、知识库和用量；不要放在浏览器或前端代码中。'), 'Management scope editor should warn where the key may be used.');
assert_true(str_contains($editor, 'name: "management_scopes"') && str_contains($editor, 'type: "checkbox"'), 'Management scopes should be a checkbox group.');
assert_true(str_contains($editor, 'replace(/:write$/, ":read")') && str_contains($editor, 'read.checked = true') && str_contains($editor, 'read.disabled = true'), 'Checking a write scope should visually imply its read scope.');

$create = $functionSource($tableJs, 'showCreateModal');
assert_true(str_contains($create, 'resourceKey === "api-keys" ? createApiKeyManagementScopeEditor([])'), 'API key create form should include the management scope editor.');
assert_true(str_contains($create, 'payload.management_scopes = scopeEditor.selected()'), 'API key creation should send management_scopes.');

$edit = $functionSource($tableJs, 'showEditModal');
assert_true(str_contains($edit, 'createApiKeyManagementScopeEditor(row.management_scopes, { editing: true })'), 'API key edit form should start from the row management scopes.');
assert_true(str_contains($edit, 'if (scopeEditor?.changed())') && str_contains($edit, 'payload.management_scopes = scopeEditor.selected()'), 'API key edit should only send management_scopes when they changed.');
assert_true(str_contains($tableJs, 'function normalizeManagementScopes'), 'Change detection should ignore read scopes implied by write scopes.');

$badge = $functionSource($tableJs, 'apiKeyManagementBadge');
assert_true(str_contains($badge, 'text: "管理"') && str_contains($badge, 'title:'), 'API key rows with management scopes should show a "管理" badge with the scope list as a tooltip.');
assert_true(str_contains($tableJs, 'column === "name" ? apiKeyManagementBadge(row) : null'), 'The management badge should be rendered in the API key name cell.');

foreach (['.api-key-management-badge', '.api-key-management-scopes', '.api-key-management-scope-list'] as $selector) {
    assert_true(str_contains($adminCss, $selector), 'Admin CSS should style ' . $selector);
}
