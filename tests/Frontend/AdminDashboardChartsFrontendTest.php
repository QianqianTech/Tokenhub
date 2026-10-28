<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$dashboardSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/dashboard.js') ?: '';
$adminCss = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';

assert_true(str_contains($dashboardSource, 'trendChart('), 'Admin dashboard should render trend data as a chart.');
assert_true(str_contains($dashboardSource, 'rankingChart('), 'Admin dashboard should render ranking data as a chart.');
assert_true(str_contains($dashboardSource, 'multimodalChart('), 'Admin dashboard should render multimodal data as a chart.');
assert_true(str_contains($dashboardSource, 'securityCharts('), 'Admin dashboard should render security data as chart cards.');
assert_true(str_contains($dashboardSource, 'performanceCharts('), 'Admin dashboard should render performance data as chart cards.');
assert_true(str_contains($dashboardSource, 'className: "chart-area trend-chart"'), 'Trend chart should expose a stable chart-area container.');
assert_true(str_contains($dashboardSource, 'className: "trend-line-chart"'), 'Dashboard trend should render as a horizontal line chart.');
assert_true(str_contains($dashboardSource, 'http://www.w3.org/2000/svg'), 'Dashboard trend should use an SVG line chart.');
assert_true(!str_contains($dashboardSource, 'trend-column-track'), 'Dashboard trend should not render vertical column tracks.');
assert_true(str_contains($dashboardSource, 'className: "chart-bars ranking-chart"'), 'Ranking chart should expose a stable horizontal bar container.');
assert_true(str_contains($dashboardSource, 'className: "dashboard-screen-page"'), 'Admin dashboard should expose a screen-style page container.');
assert_true(str_contains($dashboardSource, 'className: "dashboard-screen-filters filters"'), 'Admin dashboard filters should have a screen-specific styling hook.');
assert_true(str_contains($dashboardSource, 'className: "dashboard-grid dashboard-screen-grid"'), 'Admin dashboard chart grid should have a screen-specific styling hook.');
assert_true(str_contains($dashboardSource, 'select("period"'), 'Dashboard should expose a reporting-period selector.');
assert_true(str_contains($dashboardSource, 'period.value = "30d";'), 'Dashboard should default to the last 30 days.');
assert_true(str_contains($dashboardSource, 'bucket.value = "day";'), 'Dashboard should default 30-day trends to daily granularity.');
assert_true(str_contains($dashboardSource, 'trendChart(trends.data || [], bucket.value)'), 'Dashboard should pass the selected trend granularity to the chart.');
assert_true(str_contains($dashboardSource, 'const maxPoints = bucket === "day" ? 30 : 24;'), 'Dashboard should automatically show 30 daily points or 24 hourly points.');
assert_true(str_contains($dashboardSource, 'datetime-local'), 'Dashboard should support custom start and end times.');
assert_true(str_contains($dashboardSource, 'periodRange('), 'Dashboard should derive shared from/to parameters from the selected period.');
assert_true(str_contains($dashboardSource, 'operations-status-strip'), 'Dashboard should render a status-first operations strip.');
assert_true(str_contains($dashboardSource, 'formatIntegerCost(today.total_cost)'), 'Dashboard cost metric should render through the integer cost formatter.');
assert_true(str_contains($dashboardSource, 'return Math.round(number).toLocaleString("zh-CN");'), 'Dashboard integer cost formatter should round to the nearest whole number.');
assert_true(str_contains($dashboardSource, 'formatResourceValue(column, row[column])'), 'Dashboard should keep localized value formatting for table fallback and drilldown surfaces.');
assert_true(str_contains($dashboardSource, '"部门用量排行"'), 'Dashboard should render a department usage ranking panel.');
assert_true(str_contains($dashboardSource, '"员工用量排行"'), 'Dashboard should render an employee usage ranking panel.');
assert_true(str_contains($resources, '"dashboard": { label: "智慧分析", group: "运营" }'), 'Dashboard resource should be labeled Smart Analysis.');

assert_true(str_contains($adminCss, '.dashboard-panel.chart-panel'), 'Admin CSS should style chart-first dashboard panels.');
assert_true(str_contains($adminCss, '.dashboard-screen-page'), 'Admin CSS should define the screen-style dashboard surface.');
assert_true(str_contains($adminCss, '.dashboard-screen-filters'), 'Admin CSS should define screen-style dashboard filters.');
assert_true(str_contains($adminCss, '.dashboard-screen-grid'), 'Admin CSS should define the screen-style dashboard grid.');
assert_true(str_contains($adminCss, '.operations-status-strip'), 'Dashboard CSS should style the operations status strip.');
assert_true(str_contains($adminCss, '--dashboard-screen-bg: var(--surface-soft);'), 'Dashboard background should inherit the active admin theme.');
assert_true(str_contains($adminCss, '--dashboard-screen-panel: var(--surface);'), 'Dashboard panels should inherit the active admin theme.');
assert_true(str_contains($adminCss, '--dashboard-screen-text: var(--text);'), 'Dashboard text should inherit the active admin theme.');
assert_true(str_contains($adminCss, '--dashboard-screen-primary: var(--primary);'), 'Dashboard accents should inherit the active admin theme.');
assert_true(str_contains($adminCss, '.chart-area'), 'Admin CSS should define the chart drawing surface.');
assert_true(str_contains($adminCss, '.trend-line-chart'), 'Admin CSS should define the horizontal trend line chart.');
assert_true(str_contains($adminCss, ".dashboard-screen-page .trend-chart {\n  grid-template-columns: minmax(0, 1fr);"), 'Dashboard line chart should override the legacy twelve-column grid.');
assert_true(str_contains($adminCss, ".trend-line-chart-svg {\n  display: block;\n  width: 100%;\n  height: 280px;"), 'Dashboard line chart should reserve a full-width plotting height.');
assert_true(str_contains($adminCss, '.chart-bars'), 'Admin CSS should define horizontal bar charts.');
assert_true(str_contains($adminCss, '.donut-chart'), 'Admin CSS should define distribution donut charts.');
assert_true(str_contains($adminCss, '.sparkline'), 'Admin CSS should define compact sparkline visuals.');
