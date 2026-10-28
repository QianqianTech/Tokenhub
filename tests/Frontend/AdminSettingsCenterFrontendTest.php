<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$settingsServiceSource = file_get_contents(__DIR__ . '/../../app/Services/SystemSettingsService.php') ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$settingsFormPanelSource = substr(
    $tableSource,
    strpos($tableSource, 'function settingsFormPanel'),
    strpos($tableSource, 'function contentArchiveQuery') - strpos($tableSource, 'function settingsFormPanel')
);
$systemSettingsRendererStart = strpos($tableSource, 'async function renderSystemSettingsPage');
$systemSettingsRendererEnd = $systemSettingsRendererStart === false ? false : strpos($tableSource, 'function systemSettingCard', $systemSettingsRendererStart);
$systemSettingsRendererSource = $systemSettingsRendererStart === false || $systemSettingsRendererEnd === false
    ? ''
    : substr($tableSource, $systemSettingsRendererStart, $systemSettingsRendererEnd - $systemSettingsRendererStart);
$certificateRendererStart = strpos($tableSource, 'function createCertificateSettingsPage');
$certificateRendererEnd = $certificateRendererStart === false ? false : strpos($tableSource, 'function renderCertificateRequestForm', $certificateRendererStart);
$certificateRendererSource = $certificateRendererStart === false || $certificateRendererEnd === false
    ? ''
    : substr($tableSource, $certificateRendererStart, $certificateRendererEnd - $certificateRendererStart);

assert_true(
    str_contains($settingsServiceSource, "'brand_identity' => '管理后台和运营大屏的标题、Logo 与浏览器图标设置'"),
    'Brand identity settings should use a Chinese user-facing description.'
);
assert_true(
    !str_contains($settingsServiceSource, 'Admin and screen title/logo settings'),
    'Brand identity settings should not retain the English user-facing description.'
);

assert_true(str_contains($adminCss, '.system-settings-panel .table-toolbar {'), 'Advanced settings should use a dedicated compact toolbar layout.');
assert_true(str_contains($adminCss, '.system-settings-panel .table-toolbar h2 {'), 'Advanced settings toolbar should remove the default heading margin.');
assert_true(str_contains($adminCss, '.system-settings-panel .table-toolbar .notice {'), 'Advanced settings toolbar notice should have a page-scoped style override.');
assert_true(preg_match('/\\.system-settings-panel \\.table-toolbar \\.notice\\s*\\{\\s*padding:\\s*0;\\s*border:\\s*0;\\s*background:\\s*transparent;\\s*line-height:\\s*1\\.7;\\s*\\}/', $adminCss) === 1, 'Advanced settings toolbar notice should be borderless, unboxed, and comfortably spaced.');
assert_true(str_contains($adminCss, '.system-settings-panel .system-setting-card {'), 'Advanced settings cards should use compact vertical spacing.');
assert_true(str_contains($tableSource, 'system-setting-accordion'), 'Advanced settings should render each configuration as an expandable accordion.');
assert_true(str_contains($tableSource, 'button(isExpanded ? "收起" : "展开"'), 'Advanced settings cards should expose a Chinese expand and collapse control.');
assert_true(str_contains($tableSource, 'createSystemSettingEditor(row, () => onRefresh(row.id))'), 'Expanded advanced settings cards should edit and save inline instead of opening a modal.');
assert_true(str_contains($tableSource, 'systemSettingFieldConfig(row, descriptor)'), 'Inline advanced settings fields should receive the setting context for Chinese labels.');
assert_true(str_contains($systemSettingsRendererSource, 'const refresh = async (expandedSettingId = null) => {'), 'Advanced settings refresh should declare the accordion state it passes to cards.');
assert_true(preg_match('/\.system-settings-list\s*\{\s*display:\s*grid;\s*gap:\s*22px;\s*\}/', $adminCss) === 1, 'Advanced settings cards should use increased vertical spacing.');
assert_true(preg_match('/\.system-setting-accordion\[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/', $adminCss) === 1, 'Collapsed advanced settings accordions should remain hidden despite their grid layout.');

assert_true(str_contains($resources, '"settings-center"'), 'Admin resources should expose settings-center.');
assert_true(str_contains($resources, '"settings-brand-identity"'), 'Admin resources should keep brand identity metadata for the settings center.');
assert_true(str_contains($resources, '"settings-tls-certificates"'), 'Admin resources should expose certificates as a settings subpage.');
assert_true(str_contains($resources, '"settings-entry-security"'), 'Admin resources should expose entry security as a settings subpage.');
assert_true(str_contains($resources, '"settings-retention-export"'), 'Admin resources should expose retention and export as a settings subpage.');
assert_true(str_contains($resources, '"settings-alert-thresholds"'), 'Admin resources should expose alert thresholds as a dedicated alert settings subpage.');
assert_true(str_contains($resources, '"settings-rag-upload-review"'), 'Admin resources should expose RAG upload review as a settings subpage.');
assert_true(str_contains($resources, '"settings-rag-package-upload"'), 'Admin resources should expose RAG image package upload settings as a subpage.');
assert_true(str_contains($resources, '"settings-rag-ai-image-processing"'), 'Admin resources should expose RAG AI image processing settings as a subpage.');
assert_true(str_contains($resources, '"settings-rag-vectorization-monitoring"'), 'Admin resources should expose RAG vectorization monitoring settings as a subpage.');
assert_true(str_contains($resources, '{ key: "system", label: "\u7cfb\u7edf\u8bbe\u7f6e"'), 'System settings should be a left sidebar section.');
$systemSectionStart = strpos($resources, '{ key: "system"');
$systemSectionEnd = $systemSectionStart === false ? false : strpos($resources, "\n", $systemSectionStart);
assert_true($systemSectionStart !== false && $systemSectionEnd !== false, 'System settings sidebar section should be inspectable.');
$systemSection = substr($resources, (int) $systemSectionStart, (int) $systemSectionEnd - (int) $systemSectionStart);
foreach (['content-retention-policy', 'settings-center', 'admin-users', 'permission-config', 'system-settings'] as $settingsItem) {
    assert_true(str_contains($systemSection, '"' . $settingsItem . '"'), 'System settings should include ' . $settingsItem . ' in the left sidebar.');
}
foreach (['settings-brand-identity', 'settings-tls-certificates', 'settings-entry-security', 'settings-alert-thresholds', 'settings-rag-vectorization-monitoring'] as $settingsItem) {
    assert_true(!str_contains($systemSection, '"' . $settingsItem . '"'), 'System settings should keep ' . $settingsItem . ' inside page tabs instead of the left sidebar.');
}
assert_true(str_contains($resources, '"settings-brand-identity": { label: "标题与标识"'), 'Brand identity subpage should be labeled clearly.');
assert_true(str_contains($resources, '"settings-alert-thresholds": { label: "告警设置"'), 'Alert thresholds should be labeled as alert settings.');
assert_true(str_contains($resources, '"system-settings": { label: "高级设置"'), 'Advanced settings should remain available as a dedicated form page.');
assert_true(!str_contains($resources, 'label: "设置值JSON"'), 'Advanced settings metadata should not expose a raw JSON input.');
assert_true(!str_contains($tableSource, '兼容证书清单 JSON'), 'Typed settings forms should not expose a JSON editor.');

assert_true(str_contains($tableSource, 'renderSettingsCenterPage'), 'Table renderer should include a settings center renderer.');
assert_true(str_contains($tableSource, 'renderSystemSettingsPage'), 'Table renderer should include a dedicated advanced settings form renderer.');
assert_true(str_contains($tableSource, 'showSystemSettingEditor'), 'Advanced settings should open a visual form editor.');
assert_true(str_contains($tableSource, 'system-setting-editor'), 'Advanced settings editor should expose a stable form hook.');
assert_true(str_contains($tableSource, 'SYSTEM_SETTING_PRESENTATIONS'), 'Advanced settings should describe built-in setting functions in Chinese.');
assert_true(str_contains($tableSource, 'days: "保存天数"'), 'Content retention user policies should show a Chinese retention-days label.');
assert_true(str_contains($tableSource, 'demo_marker: "演示标记"'), 'Content retention demo markers should show a Chinese label.');
assert_true(str_contains($tableSource, 'function systemSettingFieldLabel'), 'Advanced settings should use a shared Chinese field-label resolver.');
assert_true(str_contains($tableSource, 'const readablePath = path.filter((segment) => !/^\\d+$/.test(String(segment)));'), 'Advanced settings should omit numeric policy identifiers from readable field labels.');
assert_true(str_contains($tableSource, 'readablePath.map((segment) => labels[segment] || segment).join(" / ")'), 'Advanced settings should retain each known Chinese field-label level.');
assert_true(str_contains($tableSource, 'systemSettingFieldConfig(row, descriptor)'), 'Advanced settings editor should use the shared field-label resolver.');
assert_true(str_contains($tableSource, 'RAG 图片 AI 处理'), 'Advanced settings should retain the RAG image AI processing function.');
assert_true(str_contains($tableSource, '证书与入口'), 'Advanced settings should retain the certificate and entry function.');
assert_true(str_contains($tableSource, '标题与标识'), 'Advanced settings should retain the brand identity function.');
assert_true(str_contains($tableSource, '内容留存策略'), 'Advanced settings should retain the content retention policy function.');
assert_true(str_contains($tableSource, 'isDemoSystemSetting'), 'Advanced settings should identify demo-only marker rows.');
assert_true(str_contains($tableSource, 'filter((row) => !isDemoSystemSetting(row))'), 'Advanced settings should hide only demo-only marker rows.');
assert_true(!str_contains($tableSource, 'MANAGED_SYSTEM_SETTING_KEYS'), 'Advanced settings should not hide built-in setting functions.');
assert_true(str_contains($tableSource, 'renderSettingsSectionPage'), 'Table renderer should include individual settings section pages.');
assert_true(!str_contains($tableSource, 'await renderSettingsSectionPage(content, "settings-brand-identity");'), 'Settings center route should render categorized in-page tabs.');
assert_true(!str_contains($tableSource, 'for (const key of SETTINGS_SECTION_PAGE_ORDER) {'), 'Settings center should not stack all setting forms in the content area.');
assert_true(str_contains($tableSource, 'SETTINGS_SECTION_CATEGORIES'), 'Settings center should define second-level categories.');
assert_true(str_contains($tableSource, 'settings-category-tabs'), 'Settings center should render category tabs.');
assert_true(str_contains($tableSource, 'settings-section-tabs'), 'Settings center should render section tabs inside the active category.');
foreach (['base-entry', 'security', 'governance', 'rag'] as $categoryKey) {
    assert_true(str_contains($tableSource, 'key: "' . $categoryKey . '"'), 'Settings center should define the ' . $categoryKey . ' category.');
}
assert_true(str_contains($tableSource, '/admin-api/system/settings-center'), 'Settings center should load settings center data.');
assert_true(str_contains($tableSource, '/admin-api/system/settings-center/${section}'), 'Settings center should save sections separately.');
assert_true(str_contains($tableSource, 'rag_upload_review'), 'Settings center should save RAG upload review settings.');
assert_true(str_contains($tableSource, 'reviewer_roles'), 'RAG upload review settings should expose reviewer roles.');
assert_true(str_contains($tableSource, 'rag_package_upload'), 'Settings center should save RAG package upload settings.');
assert_true(str_contains($tableSource, 'max_package_mb'), 'RAG package upload settings should expose max package size.');
assert_true(str_contains($tableSource, 'allowed_image_extensions'), 'RAG package upload settings should expose allowed image extensions.');
assert_true(str_contains($tableSource, 'rag_ai_image_processing'), 'Settings center should save RAG AI image processing settings.');
assert_true(str_contains($tableSource, 'vision_model_id'), 'RAG AI image processing settings should expose vision model id.');
assert_true(str_contains($tableSource, 'rag_vectorization_monitoring'), 'Settings center should save RAG vectorization monitoring settings.');
assert_true(str_contains($tableSource, 'success_rate_threshold'), 'RAG vectorization monitoring should expose success threshold.');
assert_true(!str_contains($resources, '"settings-firewall"') && !str_contains($resources, '"settings-waf-detection"'), 'Settings center should not expose removed WAF settings subpages.');
assert_true(!str_contains($tableSource, 'firewall_settings') && !str_contains($tableSource, 'waf_detection_settings') && !str_contains($tableSource, 'login_failure_threshold'), 'Settings center should not save removed WAF or IP ban settings.');
assert_true(str_contains($tableSource, '/admin-api/system/settings-center/certificate-alert-check'), 'Settings center should run certificate alert checks.');
assert_true(str_contains($tableSource, '/admin-api/system/certificate-status'), 'Settings center should load real certificate status.');
assert_true(str_contains($tableSource, 'renderCertificateStatus'), 'Settings center should render parsed certificate status.');
assert_true(str_contains($tableSource, 'renderCertificateSettingsPage'), 'Certificate settings should use a dedicated certificate center renderer.');
assert_true(substr_count($certificateRendererSource, 'const statusPanel') === 1, 'Certificate settings renderer should declare its status panel only once so the admin module remains parseable.');
assert_true(str_contains($certificateRendererSource, 'certificate-request-workspace'), 'Certificate center should keep the request form and progress in one visible workflow.');
assert_true(!str_contains($certificateRendererSource, 'certificate-tab-panel'), 'Certificate center should not retain the obsolete four-tab renderer.');
assert_true(str_contains($tableSource, '/admin-api/system/certificate-request'), 'Certificate settings should submit certificate requests.');
assert_true(str_contains($tableSource, '/admin-api/system/network-interface-ips'), 'Certificate settings should load server NIC IP candidates for internal IP certificate requests.');
assert_true(str_contains($tableSource, 'renderCertificateRequestForm'), 'Certificate settings should render a first-use request form.');
assert_true(str_contains($tableSource, 'certificate_request_type'), 'Certificate request form should expose domain/IP type selection.');
assert_true(str_contains($tableSource, 'request_domain_names'), 'Certificate request form should expose requested domains.');
assert_true(str_contains($tableSource, 'request_ip_addresses'), 'Certificate request form should expose requested IP addresses.');
assert_true(str_contains($tableSource, 'autofillCertificateRequestIps'), 'Certificate request form should auto-fill requested IP addresses from server NICs.');
assert_true(str_contains($tableSource, 'certificate_mode'), 'Certificate settings should expose domain/IP certificate mode.');
assert_true(str_contains($tableSource, 'domain_names'), 'Certificate settings should expose domain certificate targets.');
assert_true(str_contains($tableSource, 'ip_addresses'), 'Certificate settings should expose IP certificate targets.');
assert_true(str_contains($tableSource, 'auto_renewal'), 'Certificate settings should expose auto-renewal control.');
assert_true(str_contains($tableSource, 'renewal_provider'), 'Certificate settings should expose renewal provider control.');
assert_true(str_contains($tableSource, 'certificate_paths'), 'Certificate settings should expose certificate scan paths.');
assert_true(str_contains($tableSource, '域名证书'), 'Certificate settings should render domain certificate copy.');
assert_true(str_contains($tableSource, 'IP 证书'), 'Certificate settings should render IP certificate copy.');
assert_true(str_contains($tableSource, 'certificate-center-page'), 'Certificate settings should render the unified certificate center page.');
assert_true(str_contains($tableSource, 'certificate-type-card'), 'Certificate center should render selectable certificate type cards.');
assert_true((bool) preg_match('/\\.settings-category-tab,\\s*\\.settings-section-tab\\s*\\{[^}]*background: var\\(--surface\\);[^}]*color: var\\(--muted\\);/s', $adminCss), 'Settings navigation tabs should follow the active theme surface.');
assert_true((bool) preg_match('/\\.settings-category-tab\\.active,\\s*\\.settings-section-tab\\.active\\s*\\{[^}]*background: color-mix\\(in srgb, var\\(--primary\\) 12%, var\\(--surface\\)\\);/s', $adminCss), 'Selected settings navigation tabs should blend with the active theme surface.');
assert_true((bool) preg_match('/\\.edit-form\\s*\\{\\s*grid-template-columns: 1fr;\\s*border: 0;\\s*padding: 0;\\s*background: transparent;\\s*\\}/s', $adminCss), 'Embedded settings forms should not restore a fixed light background inside themed panels.');
assert_true(str_contains($tableSource, 'renderInternalIpChoices'), 'Certificate center should render discovered internal IP choices.');
assert_true(str_contains($tableSource, 'internal-ip-choice'), 'Internal IP choices should use a dedicated single-selection control.');
assert_true(str_contains($tableSource, 'data-internal-ip-choice'), 'Internal IP choices should expose a stable selected IP contract.');
assert_true(str_contains($tableSource, 'public_ip'), 'Certificate center should expose a public IP certificate type.');
assert_true(str_contains($tableSource, 'letsencrypt_shortlived'), 'Certificate center should explain the Let\'s Encrypt short-lived public IP workflow.');
assert_true(str_contains($tableSource, 'certificate-request-progress'), 'Certificate center should render request progress rather than a success-only response.');
assert_true(str_contains($tableSource, 'renderCertificateInventory'), 'Certificate center should render the managed certificate inventory.');
assert_true(str_contains($adminCss, '.certificate-center-page'), 'Certificate center should have dedicated styling.');
assert_true(str_contains($adminCss, '.certificate-type-card'), 'Certificate type cards should have dedicated styling.');
assert_true((bool) preg_match('/\\.certificate-summary-card,\\s*\\.certificate-request,\\s*\\.certificate-request-progress,\\s*\\.certificate-inventory-results,\\s*\\.certificate-center-alerts\\s*\\{\\s*border: 1px solid var\\(--border-soft\\);\\s*border-radius: 10px;\\s*background: var\\(--surface\\);/s', $adminCss), 'Certificate center surfaces should follow the active theme.');
assert_true((bool) preg_match('/\\.certificate-type-card\\s*\\{[^}]*border-color: var\\(--border\\);[^}]*background: var\\(--surface\\);[^}]*color: var\\(--text\\);/s', $adminCss), 'Certificate type cards should inherit the active theme text color.');
assert_true((bool) preg_match('/\\.certificate-type-card\\.active\\s*\\{[^}]*background: color-mix\\(in srgb, var\\(--primary\\) 12%, var\\(--surface\\)\\);/s', $adminCss), 'Selected certificate types should blend with the active theme surface.');
assert_true((bool) preg_match('/\\.internal-ip-choices\\s*\\{[^}]*background: var\\(--surface-soft\\);/s', $adminCss), 'Internal IP choices should follow the active theme surface.');
assert_true((bool) preg_match('/\\.internal-ip-choice\\s*\\{[^}]*background: var\\(--surface\\);[^}]*color: var\\(--text\\);[^}]*border-color: var\\(--border\\);/s', $adminCss), 'Internal IP choice controls should inherit the active theme.');
assert_true((bool) preg_match('/\\.internal-ip-choice\\.active\\s*\\{[^}]*background: color-mix\\(in srgb, var\\(--primary\\) 12%, var\\(--surface\\)\\);/s', $adminCss), 'Selected internal IP choices should blend with the active theme surface.');
assert_true(str_contains($settingsFormPanelSource, 'const output = el("p", { className: "notice", hidden: true });'), 'Settings output panel should stay hidden until it has user-facing content.');
assert_true(!str_contains($settingsFormPanelSource, 'setText(output, JSON.stringify(saved.data, null, 2));'), 'Settings save should not render raw saved JSON after clicking save.');
assert_true(str_contains($settingsFormPanelSource, 'operationSuccessText(checked.data'), 'Certificate alert checks should render a user-facing check result.');
assert_true(!str_contains($tableSource, '未关闭告警'), 'Settings pages should not show open alert counts.');
assert_true(!str_contains($tableSource, '最近告警'), 'Settings pages should not show recent alert tables.');
assert_true(str_contains($tableSource, '证书与入口'), 'Settings center should render certificate settings.');
assert_true(str_contains($tableSource, '标题与标识'), 'Settings center should render title and logo settings.');
assert_true(str_contains($tableSource, 'brand_identity'), 'Settings center should save brand identity settings.');
assert_true(str_contains($tableSource, 'logo_url'), 'Settings center should expose a logo URL field.');
assert_true(str_contains($tableSource, '访问安全'), 'Settings center should render entry security settings.');
assert_true(str_contains($tableSource, '留存与导出'), 'Settings center should render retention and export settings.');
assert_true(str_contains($tableSource, '告警设置'), 'Settings center should render alert settings as a dedicated section.');
