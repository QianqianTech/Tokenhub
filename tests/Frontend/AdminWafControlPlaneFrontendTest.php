<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$table = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js') ?: '';
$css = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$app = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js') ?: '';
$html = file_get_contents(__DIR__ . '/../../public/admin/index.html') ?: '';

assert_true(str_contains($table, '/admin-api/waf/overview'), 'WAF overview page should load its dedicated API.');
assert_true(str_contains($table, 'renderWafVersionsPage'), 'WAF versions should use a dedicated page.');
assert_true(str_contains($table, '/admin-api/waf/versions'), 'WAF versions page should use the dedicated endpoint.');
assert_true(str_contains($table, 'renderWafVersionDiff'), 'WAF version differences should be structured.');
assert_true(str_contains($table, 'confirmWafRollback'), 'WAF rollback should require a dedicated confirmation flow.');
assert_true(str_contains($table, 'showWafRuleEditor'), 'WAF rules should use a guided editor.');
assert_true(str_contains($table, 'wafMatcherFromEditor'), 'WAF rule editor should build matcher payloads.');
assert_true(!str_contains($table, 'split(/[\\n,]+/)'), 'WAF matcher lists should not split regex patterns on commas.');
assert_true(str_contains($table, '预检当前规则'), 'WAF rules should support candidate preflight.');
assert_true(str_contains($table, 'showWafDispositionDialog'), 'WAF event handling should collect a note.');
assert_true(str_contains($table, 'showWafAutoDispositionDialog'), 'WAF automatic disposition should be configurable.');
assert_true(str_contains($table, 'event.disposition_status === "open"'), 'WAF overview should count open events as pending.');
assert_true(str_contains($table, 'renderWafOverviewCockpit'), 'WAF overview should render a dedicated security cockpit.');
assert_true(str_contains($table, '边缘防护已发布'), 'WAF overview should explain published edge protection.');
assert_true(str_contains($table, '启用规则'), 'WAF overview should present enabled rule coverage.');
assert_true(str_contains($table, '防护能力'), 'WAF overview should explain its protection capabilities.');
assert_true(str_contains($table, '威胁热点'), 'WAF overview should render rule threat rankings.');
assert_true(str_contains($table, '近期攻击'), 'WAF overview should render recent attack events.');
assert_true(str_contains($table, '当前统计窗口内未发现攻击命中'), 'WAF overview should provide a safe empty state.');
assert_true(str_contains($table, '重新加载 WAF 总览'), 'WAF overview should offer a retry action on load failure.');
assert_true(str_contains($table, 'ragNavigate("waf-rules")'), 'WAF overview should link to WAF rules.');
assert_true(str_contains($table, 'ragNavigate("waf-events")'), 'WAF overview should link to WAF attack records.');
assert_true(str_contains($table, 'ragNavigate("waf-ip-bans")'), 'WAF overview should link to IP ban management.');
assert_true(str_contains($css, '.waf-overview-cockpit'), 'WAF overview should have dedicated cockpit styles.');
assert_true(str_contains($css, '.waf-cockpit-metrics'), 'WAF overview should have responsive metric layout styles.');
assert_true(str_contains($css, '.waf-threat-bar-fill'), 'WAF overview should style threat ranking bars.');
assert_true(str_contains($css, '.waf-rule-editor'), 'WAF guided rule editor should have dedicated styles.');
assert_true(str_contains($css, '.waf-version-list'), 'WAF version control plane should have dedicated styles.');
assert_true(str_contains($css, '.waf-diff-line.is-added'), 'WAF diff should distinguish added lines.');
assert_true(str_contains($css, '.waf-disposition-dialog'), 'WAF disposition dialog should have dedicated styles.');
assert_true((bool) preg_match('/table\.js\?v=20260710-[A-Za-z0-9._-]+/', $app), 'Admin app should use a cache-busted table asset.');
assert_true((bool) preg_match('/app\.css\?v=20260710-[A-Za-z0-9._-]+/', $html), 'Admin shell should use a cache-busted stylesheet.');
assert_true((bool) preg_match('/app\.js\?v=20260710-[A-Za-z0-9._-]+/', $html), 'Admin shell should use a cache-busted module graph.');
assert_true(str_contains($table, '/admin-api/waf/ip-bans'), 'WAF IP ban page should load its dedicated API.');
assert_true(str_contains($table, '/admin-api/operations/status'), 'Operations page should load read-only status data.');
assert_true(str_contains($table, '/admin-api/alerts/${row.id}/status'), 'Alert page should submit lifecycle state through its dedicated API.');
assert_true(str_contains($table, 'showWafTemplatePrefill'), 'WAF templates should provide a concrete prefill action.');
assert_true(str_contains($table, 'waf_detection_settings'), 'WAF UI should bind core detection settings.');
assert_true(str_contains($table, 'block_threshold'), 'WAF UI should expose the detection threshold.');
assert_true(str_contains($table, '确认启用 WAF 拦截模式'), 'WAF block mode should require explicit operator confirmation.');
assert_true(str_contains($table, 'showWafRuleEditor(templateRuleValues(template), onSaved)'), 'Template selection should open the guided WAF rule editor with template values.');
assert_true(str_contains($table, 'resourceKey === "alert-events"'), 'The general alert centre should render a lifecycle action page instead of a read-only table.');
assert_true(str_contains($table, '应用层封禁已生效') && str_contains($table, '边缘 WAF 等待发布'), 'IP ban feedback should distinguish app-layer enforcement from edge publication status.');
assert_true(str_contains($table, '预填规则'), 'WAF templates should be usable to prefill a new rule.');
