<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$adminSources = [];
foreach (glob(__DIR__ . '/../../public/admin/assets/js/*.js') ?: [] as $sourceFile) {
    $adminSources[realpath($sourceFile) ?: $sourceFile] = file_get_contents($sourceFile);
}
$adminHtml = file_get_contents(__DIR__ . '/../../public/admin/index.html');
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css');
$apiJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/api.js');
$appJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js');
$dashboardSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/dashboard.js');
$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js');
$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js');
$caddyfile = file_get_contents(__DIR__ . '/../../docker/caddy/Caddyfile');

assert_true($adminSources !== [], 'Admin JS sources should be readable.');
assert_true(is_string($adminHtml), 'Admin HTML should be readable.');
assert_true(is_string($adminCss), 'Admin CSS should be readable.');
assert_true(is_string($apiJs), 'Admin API source should be readable.');
assert_true(is_string($appJs), 'Admin app source should be readable.');
assert_true(is_string($dashboardSource), 'Dashboard source should be readable.');
assert_true(is_string($resources), 'Resource source should be readable.');
assert_true(is_string($tableSource), 'Admin table source should be readable.');
assert_true(is_string($caddyfile), 'Caddyfile should be readable.');
preg_match('/app\.js\?v=(\d{8}-[A-Za-z0-9._-]+)/', $adminHtml, $entryVersionMatch);
assert_true(
    isset($entryVersionMatch[1]) && str_contains($adminHtml, 'app.css?v=' . $entryVersionMatch[1] . '"'),
    'Admin entry module should use the current cache version, shared with the stylesheet.'
);
preg_match('/["\'](\.\/api\.js\?v=[A-Za-z0-9._-]+)["\']/', $appJs, $sharedApiModuleMatch);
$sharedApiModule = $sharedApiModuleMatch[1] ?? '';
assert_true(
    $sharedApiModule !== '',
    'Admin API module should use a shared, cache-busted CSRF module version.'
);
foreach ($adminSources as $adminSourceFile => $source) {
    preg_match_all('/from\s+["\'](\.\/api\.js[^"\']*)["\']/', (string) $source, $apiImports);
    foreach ($apiImports[1] as $apiImport) {
        assert_same($sharedApiModule, $apiImport, "Admin module {$adminSourceFile} must import the shared API module instance.");
    }
}
foreach ([$appJs, $tableSource, $dashboardSource] as $adminModule) {
    assert_true(
        str_contains($adminModule, $sharedApiModule),
        'Every admin module that performs API requests must import the same versioned API module instance so it shares the CSRF token.'
    );
}

foreach ($adminSources as $adminSourceFile => $source) {
    assert_true(is_string($source), "Admin source {$adminSourceFile} should be readable.");
    assert_true(!str_contains($source, 'localStorage'), 'Admin frontend must not use localStorage.');
    assert_true(!str_contains($source, 'sessionStorage'), 'Admin frontend must not use sessionStorage.');
    assert_true(!str_contains($source, 'console.'), 'Admin frontend must not print sensitive responses to console.');
    assert_true(!preg_match('/\.innerHTML\s*=/i', $source), 'Admin frontend must not assign dynamic content through innerHTML.');
}

assert_true(str_contains($adminHtml, 'id="theme-switcher"'), 'Admin shell should expose a theme switcher.');
assert_true(str_contains($adminHtml, 'id="request-id" hidden'), 'Admin shell must keep the request-ID placeholder hidden on every page.');
assert_true(str_contains($adminHtml, '<title>TokenHub 管理后台</title>'), 'Admin shell title should be localized Chinese.');
assert_true(str_contains($adminHtml, '<h1 id="page-title">智慧分析</h1>'), 'Admin initial page title should use the Smart Analysis label.');
assert_true(str_contains($adminHtml, 'data-theme-choice="dark"'), 'Admin theme switcher should expose the dark theme.');
assert_true(str_contains($adminHtml, 'data-theme-choice="light"'), 'Admin theme switcher should expose the light theme.');
assert_true(str_contains($adminHtml, 'data-theme-choice="auto"'), 'Admin theme switcher should expose the automatic theme.');
foreach (['dark', 'light', 'auto'] as $themeChoice) {
    assert_true(
        preg_match('/data-theme-choice="' . $themeChoice . '"[^>]*>\\s*<svg\\b[\\s\\S]*?<\\/svg>\\s*<\\/button>/', $adminHtml) === 1,
        "Admin {$themeChoice} theme choice should render only an inline SVG icon."
    );
}
assert_true(str_contains($adminHtml, 'aria-label='), 'Theme icons should retain accessible names.');
assert_true(str_contains($adminHtml, 'title='), 'Theme icons should retain hover labels.');
assert_true(str_contains($adminHtml, '深色'), 'Admin theme switcher should display the dark theme label in Chinese.');
assert_true(str_contains($adminHtml, '浅色'), 'Admin theme switcher should display the light theme label in Chinese.');
assert_true(str_contains($adminHtml, '自适应'), 'Admin theme switcher should display the automatic theme label in Chinese.');
assert_true(str_contains($adminHtml, '黔前智算 TokenHub 版权所有'), 'Admin shell should display the TokenHub copyright owner.');
$modalRootPosition = strpos($adminHtml, '<div id="modal-root"></div>');
$copyrightPosition = strpos($adminHtml, '黔前智算 TokenHub 版权所有');
$workspaceClosePosition = $modalRootPosition === false ? false : strpos($adminHtml, "      </section>\n    </main>", $modalRootPosition);
assert_true(
    $modalRootPosition !== false
    && $copyrightPosition !== false
    && $workspaceClosePosition !== false
    && $copyrightPosition > $modalRootPosition
    && $copyrightPosition < $workspaceClosePosition,
    'Admin copyright should be placed at the bottom of the right workspace.'
);
assert_true(str_contains($appJs, 'tokenhub_admin_theme'), 'Admin theme choice should be stored in a non-sensitive cookie.');
assert_true(str_contains($appJs, 'applyAdminTheme'), 'Admin app should apply theme classes through a dedicated helper.');
assert_true(str_contains($appJs, 'adminInitThemeSwitcher'), 'Admin app should initialize the theme switcher during boot.');
assert_true(str_contains($appJs, 'adminApplyBrandIdentity'), 'Admin app should apply configured title and logo settings.');
assert_true(str_contains($appJs, '/admin-api/system/settings-center'), 'Admin app should load brand settings from the settings center endpoint.');
assert_true(str_contains($appJs, 'valueLabel("role", adminState.user.role)'), 'Admin topbar should localize the current user role.');
assert_true(str_contains($appJs, 'prefers-color-scheme: dark'), 'Admin app should detect the system dark-mode preference.');
assert_true(str_contains($appJs, 'ADMIN_THEME_MEDIA_QUERY.addEventListener("change"'), 'Admin app should update the automatic theme when the system preference changes.');
assert_true(str_contains($appJs, 'theme-dark'), 'Admin app should apply the dark theme class.');
assert_true(str_contains($appJs, 'theme-light'), 'Admin app should apply the light theme class.');
assert_true(str_contains($appJs, 'value === "a" || value === "b"'), 'Admin app should migrate legacy A and B theme cookies to the light theme.');
assert_true(str_contains($appJs, 'value === "c"'), 'Admin app should migrate the legacy C theme cookie to the dark theme.');
assert_true(
    preg_match('/function readThemeCookie\(\)\s*\{[\s\S]*?try\s*\{\s*return normalizeAdminTheme\(decodeURIComponent\(item\.slice\(prefix\.length\)\)\);\s*\}\s*catch\s*\{\s*return "auto";\s*\}/', $appJs) === 1,
    'Admin theme cookie reader should safely fall back to auto when a stored value is malformed.'
);
assert_true(str_contains($adminCss, '.theme-light'), 'Admin CSS should define the light theme.');
assert_true(str_contains($adminCss, '.theme-dark'), 'Admin CSS should define the dark theme.');
assert_true(!str_contains($adminCss, '.app-shell.theme-a'), 'Admin CSS should not retain the legacy theme A root selector.');
assert_true(!str_contains($adminCss, '.app-shell.theme-b'), 'Admin CSS should not retain the legacy theme B root selector.');
assert_true(!str_contains($adminCss, '.app-shell.theme-c'), 'Admin CSS should not retain the legacy theme C root selector.');
assert_true(str_contains($adminCss, '.theme-switcher'), 'Admin CSS should style the theme switcher.');
preg_match('/\.theme-choice\s*\{([^}]*)\}/', $adminCss, $themeChoiceCssMatch);
assert_true(isset($themeChoiceCssMatch[1]), 'Admin theme choice CSS block should be inspectable.');
assert_true(str_contains($themeChoiceCssMatch[1], 'width: 28px'), 'Theme choices should use compact icon width.');
assert_true(str_contains($themeChoiceCssMatch[1], 'padding: 0'), 'Theme choices should not reserve text-label padding.');
assert_true(str_contains($adminCss, '.app-copyright'), 'Admin CSS should position the copyright text.');
preg_match('/\.app-copyright\s*\{([^}]*)\}/', $adminCss, $copyrightCssMatch);
assert_true(isset($copyrightCssMatch[1]), 'Admin copyright CSS block should be inspectable.');
assert_true(!str_contains($copyrightCssMatch[1], 'position: fixed'), 'Admin copyright should stay in the right workspace flow, not fixed to the viewport.');
assert_true(str_contains($copyrightCssMatch[1], 'margin-top: auto'), 'Admin copyright should sit at the bottom of the right workspace.');
assert_true(str_contains($copyrightCssMatch[1], 'text-align: center'), 'Admin copyright should be centered at the bottom of the right workspace.');
$adminAssetSources = str_replace('http://www.w3.org/2000/svg', '', $adminHtml . $adminCss . $appJs);
assert_true(!preg_match('/https?:\/\//i', $adminAssetSources), 'Admin frontend must not reference remote assets.');
assert_true(!preg_match('/@import/i', $adminCss), 'Admin CSS must not import external styles.');
assert_true(str_contains($resources, 'navigationSections'), 'Admin resources should define sidebar navigation sections.');
assert_true(str_contains($resources, 'navSectionsForRole'), 'Admin resources should expose role-filtered navigation sections.');
assert_true(!str_contains($resources, 'key: "quick"'), 'Admin navigation should not expose a common entries section.');
assert_true(!str_contains($resources, '常用入口'), 'Admin navigation should not expose a common entries label.');
assert_true(str_contains($resources, '智慧分析'), 'Admin navigation labels should use the Smart Analysis label.');
assert_true(str_contains($resources, '用量明细'), 'Usage navigation label should be normalized Chinese.');
assert_true(str_contains($resources, '日志中心'), 'Log navigation label should be normalized Chinese.');
assert_true(str_contains($resources, 'API 密钥'), 'Admin navigation should use Chinese API key labels.');
assert_true(str_contains($resources, 'export const columnLabels'), 'Admin resources should centralize Chinese table column labels.');
assert_true(str_contains($resources, '"username": "用户名"'), 'Admin table labels should localize username.');
assert_true(str_contains($resources, '"department_id": "部门 ID"'), 'Admin table labels should localize department_id.');
assert_true(str_contains($resources, '"last_login_at": "最后登录时间"'), 'Admin table labels should localize last_login_at.');
assert_true(str_contains($resources, 'export const valueLabels'), 'Admin resources should centralize Chinese enum value labels.');
assert_true(str_contains($resources, 'formatResourceValue'), 'Admin resources should expose formatted resource values.');
assert_true(str_contains($resources, '"active": "启用"'), 'Admin value labels should localize active status.');
assert_true(str_contains($resources, '"super_admin": "超级管理员"'), 'Admin value labels should localize super admin role.');
assert_true(str_contains($resources, '"api_key": "API 密钥"'), 'Admin value labels should use Chinese API key enum values.');
assert_true(str_contains($resources, 'label: "允许模型"'), 'Admin API key forms should expose Chinese model labels.');
assert_true(!preg_match('/label: "[^"]*Header/', $resources), 'Admin resource form labels should not expose English Header labels.');
assert_true(!str_contains($resources, 'IP/CIDR'), 'Admin resource form labels should not expose English CIDR notation.');
assert_true(!str_contains($resources, 'JSON 数组'), 'Admin resource form labels should not expose mixed-language JSON array wording.');
assert_true(str_contains($tableSource, 'columnLabel(column)'), 'Generic resource tables should translate column headers.');
$detailTableStart = strpos($tableSource, 'function renderDataDetails');
$detailTableEnd = $detailTableStart === false ? false : strpos($tableSource, "\n}\n", $detailTableStart);
$detailTableSource = $detailTableStart === false || $detailTableEnd === false ? '' : substr($tableSource, $detailTableStart, $detailTableEnd - $detailTableStart);
assert_true(
    str_contains($detailTableSource, 'columns.map(columnLabel)') || str_contains($detailTableSource, 'el("th", { text: columnLabel(column) })'),
    'Simple statistic tables should translate column headers.'
);
assert_true(str_contains($tableSource, 'formatResourceValue(column, row[column])'), 'Generic resource tables should translate enum cell values.');
assert_true(str_contains($dashboardSource, 'formatResourceValue(column, row[column])'), 'Dashboard tables should translate enum cell values.');
assert_true(!preg_match('/label: "[^"]*CIDR/', $tableSource), 'Settings center labels should not expose English CIDR labels.');
assert_true(!preg_match('/label: "[^"]*Header/', $tableSource), 'Settings center labels should not expose English Header labels.');
assert_true(!preg_match('/label: "[^"]*Query/', $tableSource), 'Settings center labels should not expose English Query labels.');
assert_true(str_contains($appJs, 'tokenhub_admin_nav_open'), 'Admin sidebar open state should be stored in a non-sensitive cookie.');
assert_true(!str_contains($appJs, 'adminRenderNavSearch'), 'Admin sidebar must not render a navigation search control.');
assert_true(str_contains($appJs, 'nav-section-toggle'), 'Admin sidebar should render collapsible section toggles.');
assert_true(!str_contains($appJs, 'nav-search-input'), 'Admin sidebar must not expose a navigation search input.');
assert_true(!str_contains($appJs, 'compactSearchOpen'), 'Admin sidebar must not keep compact search state.');
assert_true(!str_contains($appJs, 'aria-keyshortcuts'), 'Admin sidebar must not register a search keyboard shortcut.');
assert_true(str_contains($appJs, 'adminNavItemIsActive'), 'Admin sidebar should use a section-aware active item helper.');
assert_true(!str_contains($appJs, 'adminState.page === item.key ? "active"'), 'Admin sidebar must not mark every duplicate resource entry active.');
assert_true(!str_contains($adminCss, '.nav-search'), 'Admin CSS must not style a removed sidebar search control.');
assert_true(str_contains($adminCss, '.nav-section'), 'Admin CSS should style sidebar sections.');
assert_true(str_contains($adminCss, '.nav-section-toggle'), 'Admin CSS should style section toggles.');
assert_true(str_contains($adminCss, '.nav-count'), 'Admin CSS should style section counts.');
assert_true(str_contains($adminCss, '.brand-logo'), 'Admin CSS should style configured brand logos.');

assert_true(str_contains($apiJs, 'ADMIN_UNAUTHENTICATED'), 'API wrapper should normalize unauthenticated handling.');
assert_true(str_contains($apiJs, 'ADMIN_CSRF_INVALID'), 'API wrapper should normalize CSRF handling.');
assert_true(str_contains($apiJs, 'adminErrorMessage'), 'API wrapper should expose localized admin error messages.');
assert_true(str_contains($apiJs, '登录状态已失效'), 'Admin auth errors should be localized Chinese.');
assert_true(str_contains($appJs, 'adminErrorMessage(error'), 'Admin shell should render localized error messages.');
assert_true(str_contains($tableSource, 'adminErrorMessage(error'), 'Admin resource pages should render localized error messages.');
assert_true(str_contains($dashboardSource, 'adminErrorMessage(error'), 'Dashboard should render localized error messages.');
assert_true(str_contains($tableSource, 'operationSuccessText'), 'Admin operations should use human-readable success summaries.');
assert_true(str_contains($tableSource, 'renderDataDetails'), 'Admin detail views should render API data as readable tables instead of JSON code blocks.');
assert_true(!preg_match('/showModal\([^;]+el\("pre",\s*\{\s*text:\s*JSON\.stringify\((?:result|saved|checked)\.data/s', $tableSource), 'Admin operation modals must not display raw API response JSON.');
assert_true(!preg_match('/setText\(output,\s*JSON\.stringify\((?:result|saved|checked)\.data/s', $tableSource), 'Admin operation outputs must not display raw API response JSON.');
assert_true(str_contains($dashboardSource, 'audit-logs'), 'Dashboard should drill down to audit records.');
assert_true(str_contains($caddyfile, 'Content-Security-Policy'), 'Caddy should emit a CSP header.');
assert_true(str_contains($caddyfile, 'script-src \'self\''), 'CSP should only allow same-origin scripts.');
assert_true(str_contains($caddyfile, '@php path /health /ready /admin-api/* /screen-api/* /v1/* /download/*'), 'Caddy should forward API and readiness routes to PHP.');
assert_true(str_contains($caddyfile, 'request>headers>Authorization replace "****"'), 'Caddy logs should mask Authorization rather than delete it.');
assert_true(str_contains($caddyfile, 'request>headers>Cookie replace "****"'), 'Caddy logs should mask Cookie rather than delete it.');

assert_true(!str_contains($resources, '"waf-') && !str_contains($resources, '"settings-firewall"') && !str_contains($resources, '防火墙'), 'Admin resources should not expose removed WAF pages or labels.');
assert_true(!str_contains($tableSource, '/admin-api/waf') && !str_contains($tableSource, 'firewall_settings') && !str_contains($tableSource, 'login_failure_threshold'), 'Admin table renderer should not reference removed WAF or IP ban features.');
assert_true(!str_contains($caddyfile, 'waf-current.caddy') && !str_contains($caddyfile, 'caddy-security'), 'Caddy should not import a WAF fragment or write the WAF security log.');
assert_true(str_contains($resources, '"logs"'), 'Admin resources should expose the log center.');
assert_true(str_contains($resources, '"alert-events"'), 'Admin resources should expose alert events.');
assert_true(str_contains($resources, '"log-archive-files"'), 'Admin resources should expose log archives.');
assert_true(str_contains($resources, '"content-archive"'), 'Admin resources should expose session archive query.');
assert_true(str_contains($resources, '"content-retention-policy"'), 'Admin resources should expose content retention policy settings.');
assert_true(str_contains($resources, '"settings-center"'), 'Admin resources should expose the typed settings center.');
assert_true(str_contains($resources, '"settings-brand-identity"'), 'Admin resources should expose brand identity settings.');
assert_true(str_contains($resources, '"settings-tls-certificates"'), 'Admin resources should expose certificate settings.');
assert_true(str_contains($resources, '"settings-entry-security"'), 'Admin resources should expose entry security settings.');
assert_true(str_contains($resources, '"settings-retention-export"'), 'Admin resources should expose retention and export settings.');
assert_true(str_contains($resources, '"settings-alert-thresholds"'), 'Admin resources should expose alert settings outside the mixed system page.');
assert_true(str_contains($resources, '"settings-rag-upload-review"'), 'Admin resources should expose RAG upload review settings.');
assert_true(str_contains($resources, '"settings-rag-package-upload"'), 'Admin resources should expose RAG image package upload settings.');
assert_true(str_contains($resources, '"settings-rag-ai-image-processing"'), 'Admin resources should expose RAG AI image processing settings.');
assert_true(str_contains($resources, '"settings-rag-vectorization-monitoring"'), 'Admin resources should expose RAG vectorization monitoring settings.');
assert_true(str_contains($resources, '{ key: "system", label: "\u7cfb\u7edf\u8bbe\u7f6e"'), 'System settings should be a left sidebar section.');
assert_true(str_contains($resources, 'items: ["content-retention-policy", "settings-center", "admin-users", "permission-config", "system-settings"]'), 'System settings should include the dedicated permission configuration page alongside administrator management.');
assert_true(!str_contains($resources, 'items: ["content-retention-policy", "settings-brand-identity"'), 'Typed settings sections should move from left sidebar pages into the settings center page.');
assert_true(str_contains($resources, '"system-settings": { label: "高级设置"'), 'Advanced settings should remain a dedicated form page.');
assert_true(!str_contains($resources, 'label: "设置值JSON"'), 'Advanced settings should not expose a raw JSON input.');
assert_true(str_contains($resources, 'readOnly: true'), 'Read-only resources should be marked in resource metadata.');
assert_true(str_contains($tableSource, 'renderContentArchivePage'), 'Admin table renderer should handle the session archive page.');
assert_true(str_contains($tableSource, 'renderContentRetentionPolicyPage'), 'Admin table renderer should handle the retention policy page.');
assert_true(str_contains($tableSource, 'renderSettingsCenterPage'), 'Admin table renderer should handle the settings center page.');
assert_true(str_contains($tableSource, 'renderSystemSettingsPage'), 'Admin table renderer should handle advanced settings as form cards.');
assert_true(str_contains($tableSource, 'renderSettingsSectionPage'), 'Admin table renderer should handle individual settings section pages.');
assert_true(!str_contains($tableSource, 'await renderSettingsSectionPage(content, "settings-brand-identity");'), 'Settings center route should render categorized in-page tabs.');
assert_true(str_contains($tableSource, 'SETTINGS_SECTION_CATEGORIES'), 'Settings center should define second-level categories.');
assert_true(str_contains($tableSource, 'settings-category-tabs'), 'Settings center should render category tabs.');
assert_true(str_contains($tableSource, 'settings-section-tabs'), 'Settings center should render section tabs.');
assert_true(str_contains($tableSource, '/admin-api/system/settings-center'), 'Settings center should load the typed settings endpoint.');
assert_true(str_contains($tableSource, '/admin-api/system/settings-center/certificate-alert-check'), 'Settings center should expose certificate alert checks.');
assert_true(str_contains($tableSource, '/admin-api/system/settings-center/${section}'), 'Settings center should save individual sections.');
assert_true(str_contains($tableSource, '标题与标识'), 'Settings center should render title and logo settings.');
assert_true(str_contains($tableSource, 'brand_identity'), 'Settings center should save brand identity settings.');
assert_true(str_contains($tableSource, 'showUserPasswordReset'), 'User rows should expose password reset through a dedicated action.');
assert_true(str_contains($tableSource, 'showUserDepartmentMove'), 'User rows should expose department move through a dedicated action.');
assert_true(str_contains($tableSource, 'showUserStatusUpdate'), 'User rows should expose status update through a dedicated action.');
assert_true(str_contains($tableSource, 'departmentAwareFields'), 'Admin create and edit forms should convert department_id fields into selectable department options.');
assert_true(
    str_contains($tableSource, 'const formFields = await lookupAwareFields(config.form)') &&
    str_contains($tableSource, 'const departmentFields = await departmentAwareFields(fields)'),
    'Admin create and edit modals should resolve department and reference select options before rendering forms.'
);
assert_true(str_contains($tableSource, 'coerce: "number"'), 'Department select fields should still submit department_id as a number.');
assert_true(str_contains(implode("\n", $adminSources), 'config.coerce === "number"'), 'Admin form reader should support numeric coercion for select-backed fields.');
$moveStart = strpos($tableSource, 'function showUserDepartmentMove');
$moveEnd = strpos($tableSource, 'function showUserStatusUpdate');
assert_true($moveStart !== false && $moveEnd !== false && $moveEnd > $moveStart, 'User department move source should be inspectable.');
$moveSource = substr($tableSource, $moveStart, $moveEnd - $moveStart);
assert_true(str_contains($moveSource, 'departmentSelectOptions'), 'User department move should build selectable department options.');
assert_true(str_contains($moveSource, 'type: "select"'), 'User department move should use a department select instead of a free numeric input.');
assert_true(!str_contains($moveSource, 'type: "number"'), 'User department move should not expose department_id as a free numeric input.');
assert_true(str_contains($moveSource, 'Number(values.department_id)'), 'User department move should still submit department_id as a number.');
assert_true(str_contains($tableSource, 'config.readOnly !== true'), 'Read-only resources should not render destructive generic actions.');
