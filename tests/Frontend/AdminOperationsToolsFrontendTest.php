<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$tableJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$appJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js') ?: '';
$apiJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/api.js') ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$adminHtml = file_get_contents(__DIR__ . '/../../public/admin/index.html') ?: '';
$contractSource = file_get_contents(__DIR__ . '/../../app/Services/Gateway/ModelCapabilityContract.php') ?: '';

assert_true($tableJs !== '' && $appJs !== '' && $apiJs !== '' && $adminCss !== '', 'Admin frontend sources should be readable.');

/**
 * Returns the source of one top-level function declared in table.js.
 */
$functionSource = static function (string $source, string $name): string {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($source, "\n}\n", $start);

    return substr($source, $start, $end === false ? null : $end - $start);
};

// All new calls must use the shared api() helper so the CSRF header is sent.
assert_true(!str_contains($tableJs, 'fetch('), 'Admin table module must not call fetch() directly; mutating calls go through api() for the CSRF header.');
assert_true(str_contains($apiJs, '"X-CSRF-Token": csrfToken'), 'Shared api() helper should send the CSRF header.');

// The current admin user is handed to the table module so super-admin-only actions can be hidden.
assert_true(str_contains($tableJs, 'export function setAdminViewer(user)'), 'Table module should expose the current admin viewer setter.');
assert_true(str_contains($appJs, 'setAdminViewer(adminState.user)'), 'Admin app should pass the signed-in user to the table module before rendering.');
assert_true(str_contains($tableJs, 'adminViewer?.role === "super_admin"'), 'Super-admin gating should use the signed-in role.');

// 1. Provider catalog sync preview / apply.
$catalogSync = $functionSource($tableJs, 'showProviderCatalogSync');
assert_true($catalogSync !== '', 'Provider catalog sync modal should exist.');
assert_true(str_contains($tableJs, 'resourceKey === "providers" && row.id && adminViewerCanSyncCatalog()') && str_contains($tableJs, 'button("目录同步", () => showProviderCatalogSync(row, onRefresh)'), 'Provider rows should expose catalog sync to global-scope admins only (matches the server rule).');
assert_true(substr_count($catalogSync, '/admin-api/providers/${row.id}/catalog-sync') === 2, 'Catalog sync should call the preview and apply endpoint.');
assert_true(str_contains($catalogSync, 'JSON.stringify({ apply: false })') && str_contains($catalogSync, 'JSON.stringify({ apply: true })'), 'Catalog sync should preview first and apply only after confirmation.');
assert_true(str_contains($catalogSync, 'button("应用"') && str_contains($catalogSync, 'window.confirm('), 'Catalog sync apply should require an explicit confirmation.');
$catalogResult = $functionSource($tableJs, 'renderCatalogSyncResult');
foreach (['data.added', 'data.changed', 'data.missing', 'data.unchanged', '"新增"', '"变更"', '"上游已下线"'] as $needle) {
    assert_true(str_contains($catalogResult, $needle), 'Catalog sync preview should render counts and lists for: ' . $needle);
}

// 2. Quota status and adjustment.
$quota = $functionSource($tableJs, 'showApiKeyQuota');
assert_true(str_contains($tableJs, '() => showApiKeyQuota(row)') && str_contains($tableJs, 'quotaButton.ariaLabel = "额度"'), 'API key rows should expose a quota action.');
assert_true(str_contains($quota, 'api("/admin-api/quota-status", { query: { api_key_id: row.id } })'), 'Quota view should load the API key quota status.');
assert_true(str_contains($tableJs, 'reserved_in_flight'), 'Quota view should show in-flight reservations.');
$adjust = $functionSource($tableJs, 'quotaAdjustmentForm');
assert_true(str_contains($adjust, 'api("/admin-api/quota-adjustments"') && str_contains($adjust, 'method: "POST"'), 'Quota adjustment should POST to the adjustment endpoint.');
foreach (['policy_id:', 'target_type', 'target_id:', 'metric:', 'amount', 'reason'] as $field) {
    assert_true(str_contains($adjust, $field), 'Quota adjustment payload should include ' . $field);
}
assert_true(str_contains($adjust, 'reason === ""') && str_contains($adjust, 'amount === 0'), 'Quota adjustment should require a reason and a non-zero amount before submitting.');
foreach (['"requests"', '"input_tokens"', '"output_tokens"', '"total_tokens"', '"audio_seconds"', '"tts_chars"', '"image_count"', '"video_seconds"', '"cost_amount"'] as $metric) {
    assert_true(str_contains($tableJs, $metric), 'Quota adjustment should offer metric ' . $metric);
}
assert_true(str_contains($tableJs, 'adminViewerCan("quotas.manage") && policies.length > 0'), 'Quota adjustment form should render for admins holding quotas.manage (server also checks the target scope).');
assert_true(str_contains($tableJs, 'reload(message)'), 'Quota view should refresh after an adjustment.');

// 3. Request diagnostics.
assert_true(str_contains($tableJs, '/admin-api/diagnostics/requests/${encodeURIComponent(id)}'), 'Diagnostics should call the request diagnostics endpoint with an encoded id.');
assert_true(str_contains($tableJs, '(resourceKey === "usage-records" || resourceKey === "logs") && row.request_id'), 'Usage and log rows with a request id should expose a diagnose action.');
assert_true(str_contains($tableJs, 'placeholder: "按请求 ID 诊断"') && str_contains($tableJs, 'toolbar.append(renderRequestDiagnosticsLookup())'), 'Usage and log toolbars should offer a request id lookup.');
$diagnostics = $functionSource($tableJs, 'renderRequestDiagnostics');
foreach (['data.found', 'explanation.cause', 'explanation.action', 'explanation.category', 'data.attempts', 'data.channel'] as $needle) {
    assert_true(str_contains($diagnostics, $needle), 'Diagnostics should render ' . $needle);
}

// 4. API key rotation.
$rotate = $functionSource($tableJs, 'showApiKeyRotate');
assert_true(str_contains($tableJs, 'row.id && row.status === "active"') && str_contains($tableJs, '() => showApiKeyRotate(row, onRefresh)') && str_contains($tableJs, 'rotateButton.ariaLabel = "轮换"'), 'Active API key rows should expose a rotate action.');
assert_true(str_contains($rotate, '/admin-api/api-keys/${row.id}/rotate') && str_contains($rotate, 'JSON.stringify({ grace_minutes: graceMinutes })'), 'Rotation should POST the grace period to the rotate endpoint.');
assert_true(str_contains($rotate, 'grace_minutes: 60') && str_contains($rotate, 'graceMinutes > 10080') && str_contains($rotate, 'graceMinutes < 0'), 'Rotation should default to 60 minutes and validate 0-10080.');
assert_true(str_contains($rotate, 'await onRefresh()'), 'Rotation should reload the API key table.');
$rotationResult = $functionSource($tableJs, 'showApiKeyRotationResult');
assert_true(str_contains($rotationResult, 'secret-output') && str_contains($rotationResult, 'oneTimeSecretText(data)'), 'Rotation should show the new key once, like key creation.');
assert_true(str_contains($rotationResult, 'copyTextToClipboard') && str_contains($tableJs, 'navigator.clipboard.writeText'), 'Rotation should offer a copy button.');
assert_true(str_contains($rotationResult, 'old_key_expires_at'), 'Rotation should show when the old key expires.');

// 5. Model capability editor: only the admin layer is written.
preg_match('/FEATURES = \[([^\]]+)\]/', $contractSource, $featureMatch);
preg_match_all("/'([a-z_]+)'/", $featureMatch[1] ?? '', $contractFeatures);
assert_true(count($contractFeatures[1]) >= 6, 'ModelCapabilityContract::FEATURES should be inspectable.');
foreach ($contractFeatures[1] as $feature) {
    assert_true(str_contains($tableJs, '["' . $feature . '", '), 'Capability editor should expose contract feature ' . $feature);
}
foreach (['input_modalities', 'output_modalities', 'context_length', 'max_output_tokens'] as $key) {
    assert_true(str_contains($tableJs, $key), 'Capability editor should cover ' . $key);
}
$editor = $functionSource($tableJs, 'createModelCapabilityEditor');
assert_true($editor !== '', 'Capability editor should exist.');
assert_true(str_contains($tableJs, 'const capabilityEditor = resourceKey === "models" ? createModelCapabilityEditor(row) : null;'), 'Model edit form should embed the capability editor.');
assert_true(str_contains($tableJs, 'Object.assign(payload, capabilityEditor.payload())'), 'Model PUT payload should include capability edits.');
assert_true(str_contains($editor, 'layers.admin = admin') && !preg_match('/layers\.(upstream|verified)\s*=/', $editor), 'Capability editor should only write the admin layer.');
assert_true(str_contains($editor, '"继承"') && str_contains($editor, '"支持"') && str_contains($editor, '"不支持"'), 'Capability features should be tri-state (inherit / supported / unsupported).');
assert_true(str_contains($editor, '"上游"') && str_contains($editor, '"已验证"'), 'Capability editor should show upstream and verified values read-only.');
assert_true(str_contains($editor, 'payload.expected_updated_at = baseline.updatedAt'), 'Capability edits should use optimistic concurrency so a concurrent catalog sync is not overwritten.');
assert_true(str_contains($editor, 'payload.capability_tags = selected'), 'Capability editor should edit capability_tags as an array.');
assert_true(str_contains($editor, 'hasOwnProperty.call(row, "capabilities")'), 'Capability editor should only send capabilities when the column exists.');

// 6. Trial chat with an API key.
$chat = $functionSource($tableJs, 'renderAdminChatSessionPage');
assert_true(str_contains($chat, 'label: "使用 API Key"') && str_contains($chat, 'lookupRows("api-keys")'), 'Chat page should offer an API key selector loaded from the API key list.');
assert_true(str_contains($chat, '...(apiKeyId > 0 ? { api_key_id: apiKeyId } : {})'), 'Chat requests should send api_key_id only when a key is selected.');
assert_true(str_contains($chat, '"不使用（直连诊断）"'), 'Chat page should keep the direct diagnostic mode as the default.');
assert_true(str_contains($chat, '计费'), 'Chat page should warn that the API key mode is billed.');
assert_true(str_contains($chat, 'grantedIds.has(Number(model.id))'), 'API key mode should offer the logical model names granted to the key.');
assert_true(str_contains($chat, 'showRequestDiagnostics(requestId)'), 'API key trials should link to request diagnostics.');

// Styling and cache busting.
foreach (['.catalog-sync-summary', '.quota-status-card', '.request-diagnostics-lookup', '.request-diagnostics-explanation', '.api-key-rotate-secret', '.model-capability-editor', '.admin-chat-api-key-field', '.admin-chat-mode-hint'] as $selector) {
    assert_true(str_contains($adminCss, $selector), 'Admin CSS should style ' . $selector);
}
preg_match('/app\.js\?v=(\d{8}-[A-Za-z0-9._-]+)/', $adminHtml, $versionMatch);
$version = $versionMatch[1] ?? '';
assert_true($version !== '' && str_contains($appJs, './table.js?v=' . $version . '"'), 'The table module should be loaded with the current cache version.');

// API key list shows who disabled a key (api_keys.disabled_by).
$resourcesJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
assert_true(str_contains($tableJs, 'apiKeyStatusText(row.status, row.disabled_by)'), 'API key status cells should include the disable source.');
assert_true(str_contains($tableJs, 'const API_KEY_DISABLED_BY_LABELS = { admin: "管理员", management: "智能体" };'), 'Disable sources should have Chinese labels.');
assert_true(str_contains($resourcesJs, '"disabled_by": "停用来源"') && str_contains($resourcesJs, '"key_suffix", "status", "disabled_by"'), 'API key details should show the disable source.');

// Only models the gateway can serve are shown (ModelSupportPolicy).
assert_true(str_contains($tableJs, 'function modelIsGatewaySupported(model)') && str_contains($tableJs, '.filter(modelIsGatewaySupported)'), 'Model pickers should only offer supported models.');
assert_true(str_contains($tableJs, 'resourceKey === "models" ? { limit: 500, support: "all" }'), 'Reference lookups should load all models so existing labels still resolve.');
assert_true(substr_count($tableJs, 'providerCatalogModels(result.data)') === 2, 'Both provider import dialogs should use the supported-only catalog.');
assert_true(str_contains($tableJs, '已隐藏 ${hiddenCount} 个 TokenHub 无法调用的模型'), 'Import dialogs should say how many models were hidden.');
assert_true(str_contains($tableJs, 'data.hidden_unsupported'), 'Catalog sync preview should mention hidden unsupported models.');
assert_true(str_contains($tableJs, 'modelIsGatewaySupported(model) && (!model.status'), 'Gateway trial chat should only list supported models.');
assert_true(str_contains($resourcesJs, '{ name: "support", label: "网关支持"') && str_contains($resourcesJs, '"route_priority", "gateway_support"]'), 'Model list should filter by gateway support and show the reason.');
