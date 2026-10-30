import { adminErrorMessage, api, isAbortError } from "./api.js";
import { button, el, statusMessage } from "./dom.js";
import { errorState, loadingState, toast } from "./components/feedback.js";
import { formatResourceValue } from "./resources.js";
import { departmentSelectOptions } from "./lib/references.js";
import { canLookup, resolveLookup } from "./lib/lookups.js";

const PERIODS = [["today", "今日"], ["7d", "近 7 天"], ["30d", "近 30 天"], ["custom", "自定义"]];
const AUTO_REFRESH_MS = 60_000;

export async function renderDashboard(content, navigate) {
  content.replaceChildren();
  const page = el("section", { className: "dash-page" });
  // The period is a segmented control; the select stays as the single source
  // of truth (it is what periodRange() and the custom range read).
  const period = select("period", PERIODS);
  period.value = "30d";
  const bucket = select("bucket", [["hour", "按小时"], ["day", "按天"]]);
  bucket.value = "day";
  const department = select("department_id", [["", "全部部门"]]);
  const customFrom = el("input", { type: "datetime-local", name: "from", disabled: true, "aria-label": "开始时间" });
  const customTo = el("input", { type: "datetime-local", name: "to", disabled: true, "aria-label": "结束时间" });
  const customRange = el("div", { className: "dashboard-custom-range", hidden: true }, [
    customFrom,
    el("span", { className: "dashboard-range-sep", text: "至", "aria-hidden": "true" }),
    customTo,
    button("应用", () => load(), "secondary")
  ]);
  const periodButtons = PERIODS.map(([value, text]) => el("button", {
    type: "button",
    className: "segmented-option",
    dataset: { period: value },
    "aria-pressed": value === period.value ? "true" : "false",
    text,
    onClick: () => setPeriod(value)
  }));
  const segmented = el("div", { className: "segmented dashboard-period", role: "group", "aria-label": "时间阶段" }, periodButtons);
  const employeeQuery = el("input", { type: "search", name: "employee_query", className: "dash-search", placeholder: "搜索员工账号", "aria-label": "按员工账号筛选" });
  let employeeSearchTimer = 0;
  employeeQuery.addEventListener("input", () => {
    window.clearTimeout(employeeSearchTimer);
    employeeSearchTimer = window.setTimeout(() => load(), 300);
  });
  const exportButton = button("导出报表", () => exportTrend(), "secondary");
  const controls = el("form", { className: "dash-controls", onSubmit: (event) => {
    event.preventDefault();
    load();
  } }, [
    segmented,
    customRange,
    el("label", { className: "inline-select" }, [el("span", { text: "部门" }), department]),
    employeeQuery,
    exportButton
  ]);
  const subtitle = el("p", { className: "dashboard-subtitle" });
  const head = el("header", { className: "page-head dash-head" }, [
    el("div", { className: "page-head-text" }, [el("h2", { text: "智慧分析" }), subtitle]),
    controls
  ]);
  const notice = el("p", { className: "dashboard-notice", role: "status", "aria-live": "polite" });
  const host = el("div", { className: "dash-grid", "aria-busy": "false" });
  page.append(head, notice, host);
  content.append(page);

  let lastTrend = [];
  let refreshTimer = 0;

  function setPeriod(value) {
    period.value = value;
    for (const option of periodButtons) {
      option.setAttribute("aria-pressed", option.dataset.period === value ? "true" : "false");
    }
    const isCustom = value === "custom";
    if (!isCustom) {
      bucket.value = value === "today" ? "hour" : "day";
    }
    customFrom.disabled = !isCustom;
    customTo.disabled = !isCustom;
    customRange.hidden = !isCustom;
    if (isCustom) {
      customFrom.focus();
      return;
    }
    load();
  }

  department.addEventListener("change", () => load());

  function exportTrend() {
    const columns = ["bucket_at", "request_count", "success_count", "failed_count", "total_cost"];
    if (!lastTrend.length) {
      toast("当前时间段没有趋势数据可导出", { timeout: 2400 });
      return;
    }
    downloadCsv(`dashboard-trends-${bucket.value}.csv`, columns, lastTrend);
  }

  // Every load gets a sequence number; responses of an older load are
  // dropped so quick filter changes never append duplicate panels.
  let loadSeq = 0;

  async function loadDepartmentOptions() {
    // The tree endpoint is scoped to the departments this administrator may
    // see and yields indented labels that show the hierarchy.
    for (const [value, text] of await departmentSelectOptions()) {
      department.append(el("option", { value, text }));
    }
  }

  function scheduleRefresh() {
    window.clearTimeout(refreshTimer);
    if (period.value === "custom") {
      return;
    }
    refreshTimer = window.setTimeout(() => {
      if (!host.isConnected) {
        return;
      }
      if (document.hidden) {
        scheduleRefresh();
        return;
      }
      void load({ quiet: true });
    }, AUTO_REFRESH_MS);
  }

  async function load({ quiet = false } = {}) {
    const seq = ++loadSeq;
    const isCurrent = () => seq === loadSeq && host.isConnected;
    const range = periodRange(period.value, customFrom.value, customTo.value);
    notice.textContent = "";
    if (!range) {
      host.replaceChildren(statusMessage("请选择有效的自定义时间区间", "error"));
      return;
    }
    if (period.value === "custom") {
      // Short custom ranges read better by hour.
      bucket.value = range.hours <= 48 ? "hour" : "day";
    }
    const departmentLabel = department.selectedOptions[0]?.textContent?.trim() || "全部部门";
    subtitle.textContent = [
      departmentLabel,
      employeeQuery.value.trim() ? `员工包含“${employeeQuery.value.trim()}”` : "",
      `${range.label} ${range.display}`,
      period.value === "custom" ? "" : "每 60 秒自动刷新"
    ].filter(Boolean).join(" · ");
    const params = {
      bucket: bucket.value,
      from: range.from,
      to: range.to,
      department_id: department.value,
      employee_query: employeeQuery.value.trim()
    };
    const requests = {
      realtime: () => api("/admin-api/dashboard/realtime", { query: params }),
      trends: () => api("/admin-api/dashboard/trends", { query: params }),
      departmentRankings: () => api("/admin-api/dashboard/rankings", { query: { ...params, dimension: "department", sort: "total_tokens" } }),
      userRankings: () => api("/admin-api/dashboard/rankings", { query: { ...params, dimension: "user", sort: "request_count" } }),
      security: () => api("/admin-api/dashboard/security", { query: params }),
      performance: () => api("/admin-api/dashboard/performance", { query: params }),
      multimodal: () => api("/admin-api/dashboard/multimodal", { query: params })
    };
    if (!quiet) {
      host.setAttribute("aria-busy", "true");
      host.replaceChildren(loadingState("正在加载仪表盘数据…", { rows: 4 }));
    }
    const keys = Object.keys(requests);
    const [outcomes, previousToday, openAlerts] = await Promise.all([
      Promise.all(keys.map((key) => settle(requests[key]()))),
      // Comparison with the previous period; optional, never blocks the page.
      previousTotals(params, range),
      openAlertCount()
    ]);
    if (!isCurrent()) {
      return;
    }
    host.setAttribute("aria-busy", "false");
    const results = Object.fromEntries(keys.map((key, index) => [key, outcomes[index]]));
    const failures = outcomes.filter((outcome) => outcome.status === "rejected" && !isAbortError(outcome.reason));
    if (failures.length === outcomes.length) {
      host.replaceChildren(errorState(failures[0].reason, { fallback: "仪表盘数据加载失败", onRetry: () => load() }));
      scheduleRefresh();
      return;
    }
    if (failures.length) {
      const describe = (error) => adminErrorMessage(error, "请求失败");
      notice.textContent = `部分面板加载失败（${failures.length} 项）：${describe(failures[0].reason)}`;
    }

    const realtime = dataOf(results.realtime);
    const trends = { data: [] };
    const today = realtime?.today || null;
    const security = dataOf(results.security) || {};
    const children = [];
    if (today) {
      // Status first: whether anything needs attention, then the numbers.
      children.push(operationsStatusStrip(today, dataOf(results.performance) || {}, security, openAlerts, navigate));
      children.push(metricGrid(today, range.label, previousToday, range));
    } else {
      children.push(resultPanel("核心指标", "chart-panel panel-wide", results.realtime, requests.realtime, (data) => metricGrid(data?.today || {}, range.label, null, range), isCurrent));
    }
    const departmentPanel = resultPanel("部门用量排行", "chart-panel panel-half", results.departmentRankings, requests.departmentRankings, (data) => departmentRanking(data || [], navigate), isCurrent, {
      aside: el("span", { className: "panel-aside", text: "按 Token" })
    });
    const userPanel = resultPanel("员工用量排行", "chart-panel panel-half", results.userRankings, requests.userRankings, (data) => userRanking(data || [], navigate), isCurrent, {
      aside: el("a", { className: "panel-link", href: "#", text: "查看用量明细", onClick: (event) => {
        event.preventDefault();
        navigate("usage-records");
      } })
    });
    children.push(
      resultPanel("请求趋势", "chart-panel panel-wide", results.trends, requests.trends, (data) => {
        trends.data = data;
        lastTrend = ascendingByBucket(data || []);
        return trendChart(trends.data || [], bucket.value);
      }, isCurrent, { titleId: "dashboard-trend-title" }),
      departmentPanel,
      userPanel,
      resultPanel("多模态分析", "chart-panel panel-third", results.multimodal, requests.multimodal, (data) => multimodalChart(data?.capabilities || []), isCurrent),
      resultPanel("安全看板", "chart-panel panel-third", results.security, requests.security, (data) => securityCharts(data || {}, navigate, openAlerts), isCurrent, {
        aside: openAlerts > 0 ? el("span", { className: "status-pill is-warning", text: `${formatNumber(openAlerts)} 条待处理` }) : null
      }),
      resultPanel("性能看板", "chart-panel panel-third", results.performance, requests.performance, (data) => performanceCharts(data || {}), isCurrent)
    );
    host.replaceChildren(...children);
    scheduleRefresh();
  }

  await loadDepartmentOptions();
  await load();
}

async function openAlertCount() {
  try {
    const result = await api("/admin-api/alert-events", { query: { status: "open", limit: 1, offset: 0, with_total: 1 }, silent: true });
    const total = Number(result.total ?? (Array.isArray(result.data) ? result.data.length : 0));
    return Number.isFinite(total) ? total : 0;
  } catch {
    return null;
  }
}

function settle(promise) {
  return promise.then((value) => ({ status: "fulfilled", value }), (reason) => ({ status: "rejected", reason }));
}

function dataOf(outcome) {
  return outcome?.status === "fulfilled" ? outcome.value?.data ?? null : null;
}

// A dashboard panel whose body shows either the rendered chart or an error
// state that retries just this panel's request.
function resultPanel(title, className, outcome, request, render, isCurrent, options = {}) {
  const body = el("div", { className: "dashboard-panel-body" });
  const show = (result) => {
    if (result.status === "fulfilled") {
      body.replaceChildren(render(result.value?.data ?? null));
      return;
    }
    if (isAbortError(result.reason)) {
      return;
    }
    body.replaceChildren(errorState(result.reason, {
      fallback: `${title}加载失败`,
      onRetry: async () => {
        body.replaceChildren(loadingState("正在重新加载…", { rows: 2 }));
        const next = await settle(request());
        if (isCurrent()) {
          show(next);
        }
      }
    }));
  };
  show(outcome);
  return panel(title, body, className, options);
}

function sqlTimestamp(value) {
  return value.toISOString().slice(0, 19).replace("T", " ");
}

// Periods start at the administrator's local midnight; the server stores and
// compares UTC timestamps, so the boundary is converted with toISOString().
function startOfLocalDay(value) {
  const start = new Date(value);
  start.setHours(0, 0, 0, 0);
  return start;
}

function shortStamp(date, withDate) {
  const time = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
  return withDate ? `${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${time}` : time;
}

function periodRange(period, customFrom, customTo) {
  const now = new Date();
  if (period === "custom") {
    const from = new Date(customFrom);
    const to = new Date(customTo);
    if (!customFrom || !customTo || !Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from >= to) {
      return null;
    }
    return {
      from: sqlTimestamp(from),
      to: sqlTimestamp(to),
      start: from,
      end: to,
      hours: (to - from) / 3_600_000,
      label: "区间",
      display: `${shortStamp(from, true)} – ${shortStamp(to, true)}`
    };
  }
  const options = {
    today: [0, "今日"],
    "7d": [6, "近 7 天"],
    "30d": [29, "近 30 天"]
  };
  const [days, label] = options[period] || options.today;
  const start = startOfLocalDay(now);
  start.setDate(start.getDate() - days);
  return {
    from: sqlTimestamp(start),
    to: sqlTimestamp(now),
    start,
    end: now,
    hours: (now - start) / 3_600_000,
    label,
    display: days === 0 ? `${shortStamp(start, false)} – ${shortStamp(now, false)}` : `${shortStamp(start, true)} – ${shortStamp(now, true)}`
  };
}

// Totals of the comparison window: for today, yesterday up to the same time of
// day (from hourly buckets, so a partial day is compared with a partial day);
// otherwise the window of the same length right before the selected one.
async function previousTotals(params, range) {
  const length = range.end - range.start;
  const sameTimeYesterday = range.label === "今日";
  const start = new Date(range.start.getTime() - (sameTimeYesterday ? 86_400_000 : length));
  const end = new Date((sameTimeYesterday ? range.end.getTime() - 86_400_000 : range.start.getTime()) - 1000);
  const query = { ...params, from: sqlTimestamp(start), to: sqlTimestamp(end) };
  try {
    if (range.hours <= 48) {
      const result = await api("/admin-api/dashboard/trends", { query: { ...query, bucket: "hour" }, silent: true });
      const rows = Array.isArray(result?.data) ? result.data : [];
      if (!rows.length) {
        return null;
      }
      const total = (key) => rows.reduce((sum, row) => sum + numberValue(row[key]), 0);
      const requests = total("request_count");
      return {
        request_count: requests,
        success_count: total("success_count"),
        failed_count: total("failed_count"),
        total_tokens: total("input_tokens") + total("output_tokens"),
        total_cost: total("total_cost"),
        avg_latency_ms: requests > 0 ? total("latency_ms_sum") / requests : 0
      };
    }
    const result = await api("/admin-api/dashboard/realtime", { query, silent: true });
    return result?.data?.today || null;
  } catch {
    return null;
  }
}

function bucketTime(row) {
  const time = parseUtc(row?.bucket_at);
  return time ? time.getTime() : 0;
}

// The API returns the newest buckets first (ORDER BY bucket_at DESC LIMIT n);
// charts read left-to-right from oldest to newest.
function ascendingByBucket(rows) {
  return (Array.isArray(rows) ? [...rows] : []).sort((left, right) => bucketTime(left) - bucketTime(right));
}

function parseUtc(value) {
  if (!value) {
    return null;
  }
  const text = String(value).trim();
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(text) ? `${text.replace(" ", "T")}Z` : text;
  const date = new Date(iso);
  return Number.isFinite(date.getTime()) ? date : null;
}

function operationsStatusStrip(today, performance, security, openAlerts, navigate) {
  const buckets = ascendingByBucket(performance.buckets);
  const latest = buckets[buckets.length - 1] || {};
  const failed = numberValue(today.failed_count);
  const securityEvents = sumRows(security.desensitization, "event_count");
  const alerts = numberValue(openAlerts);
  const attention = failed > 0 || alerts > 0;
  const items = [
    ["异常请求", formatNumber(failed), failed > 0 ? () => navigate("usage-records", { status: "failed" }) : null],
    ["最新 P99", formatLatency(latest.p99_latency_ms), null],
    ["安全事件", formatNumber(securityEvents), securityEvents > 0 ? () => navigate("desensitization-events") : null]
  ];
  if (openAlerts !== null && openAlerts !== undefined) {
    items.push(["待处理告警", formatNumber(alerts), alerts > 0 ? () => navigate("alert-events") : null]);
  }
  return el("section", { className: `dash-status ${attention ? "is-attention" : "is-stable"}`, "aria-label": "运行状态" }, [
    el("div", { className: "dash-status-heading" }, [
      el("span", { className: "dash-status-dot", "aria-hidden": "true" }),
      el("strong", { text: attention ? "需关注" : "运行平稳" }),
      el("span", { className: "dash-status-desc", text: attention ? "当前时间段内存在需要核查的信号" : "当前时间段内未发现异常信号" })
    ]),
    el("div", { className: "dash-status-items" }, items.map(([labelText, value, open]) => (
      open
        ? el("button", { type: "button", className: "dash-status-item is-link", onClick: open }, [el("span", { text: labelText }), el("b", { text: value })])
        : el("span", { className: "dash-status-item" }, [el("span", { text: labelText }), el("b", { text: value })])
    )))
  ]);
}

function formatLatency(value) {
  const ms = numberValue(value);
  if (ms >= 1000) {
    return `${(ms / 1000).toFixed(2)} s`;
  }
  return `${formatNumber(Math.round(ms))} ms`;
}

function compactNumber(value) {
  const number = numberValue(value);
  const abs = Math.abs(number);
  if (abs >= 1e8) {
    return `${(number / 1e8).toFixed(2)} 亿`;
  }
  if (abs >= 1e6) {
    return `${(number / 1e6).toFixed(2)}M`;
  }
  if (abs >= 1e4) {
    return `${(number / 1e3).toFixed(1)}K`;
  }
  return formatNumber(number);
}

function numberValue(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number : 0;
}

function formatNumber(value) {
  return numberValue(value).toLocaleString("zh-CN");
}

function formatIntegerCost(value) {
  const number = numberValue(value);
  return Math.round(number).toLocaleString("zh-CN");
}


function percent(value, max) {
  if (max <= 0 || numberValue(value) <= 0) {
    return 0;
  }
  return Math.max(2, Math.round(numberValue(value) / max * 100));
}

function pad(value) {
  return String(value).padStart(2, "0");
}

// Hourly buckets are shown in the viewer's local time; daily buckets are
// whole UTC days, so only their date part is shown.
function formatBucket(value, bucket = "hour") {
  if (!value) {
    return "--";
  }
  const text = String(value);
  if (bucket === "day") {
    return text.slice(5, 10);
  }
  const date = parseUtc(text);
  if (!date) {
    return text.length > 16 ? text.slice(5, 16) : text;
  }
  return `${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}


// Older backends omit success_count; derive it instead of showing 0.
function successCount(today) {
  if (today.success_count != null) {
    return today.success_count;
  }
  return Math.max(0, numberValue(today.request_count) - numberValue(today.failed_count));
}

// Change against the comparison window. `higherIsBetter` decides the tone:
// more requests is good news, more failures or latency is not.
function delta(current, previous, { higherIsBetter = true, compareLabel }) {
  const now = numberValue(current);
  const before = numberValue(previous);
  if (previous === null || previous === undefined || before === 0) {
    return null;
  }
  const change = (now - before) / before * 100;
  if (Math.abs(change) < 0.05) {
    return { text: `与${compareLabel}持平`, tone: "flat" };
  }
  const up = change > 0;
  const good = up === higherIsBetter;
  return { text: `${up ? "↑" : "↓"} ${Math.abs(change).toFixed(1)}% 较${compareLabel}`, tone: good ? "good" : "bad" };
}

function metricGrid(today, periodLabel = "今日", previous = null, range = null) {
  const compareLabel = periodLabel === "今日" ? "昨日同时段" : "上一周期";
  const prev = (key) => (previous ? previous[key] : null);
  const requests = numberValue(today.request_count);
  const success = successCount(today);
  const failed = numberValue(today.failed_count);
  const days = range ? Math.max(1, Math.round(range.hours / 24)) : 1;
  const cost = numberValue(today.total_cost);
  const metrics = [
    {
      name: `${periodLabel}请求`,
      value: formatNumber(today.request_count),
      note: delta(requests, prev("request_count"), { compareLabel }) || (days > 1 ? { text: `日均 ${formatNumber(Math.round(requests / days))}`, tone: "flat" } : null)
    },
    {
      name: "成功请求",
      value: formatNumber(success),
      note: requests > 0 ? { text: `成功率 ${(success / requests * 100).toFixed(2)}%`, tone: "flat" } : null
    },
    {
      name: "失败请求",
      value: formatNumber(today.failed_count),
      note: delta(failed, prev("failed_count"), { higherIsBetter: false, compareLabel }) || (requests > 0 ? { text: `失败率 ${(failed / requests * 100).toFixed(2)}%`, tone: "flat" } : null)
    },
    {
      name: "总 Token",
      value: compactNumber(today.total_tokens),
      title: formatNumber(today.total_tokens),
      note: delta(today.total_tokens, prev("total_tokens"), { compareLabel })
        || { text: `输入 ${compactNumber(today.input_tokens)} · 输出 ${compactNumber(today.output_tokens)}`, tone: "flat" }
    },
    {
      name: "平均延迟",
      value: formatLatency(today.avg_latency_ms),
      note: delta(today.avg_latency_ms, prev("avg_latency_ms"), { higherIsBetter: false, compareLabel })
    },
    {
      name: "成本",
      // Kept whole: the dashboard shows magnitude, the usage records show cents.
      value: cost > 0 ? `¥${formatIntegerCost(today.total_cost)}` : "未配置",
      note: cost > 0
        ? delta(cost, prev("total_cost"), { higherIsBetter: false, compareLabel })
        : { text: "在价格配置中设置模型单价后计算", tone: "flat" },
      muted: cost <= 0
    }
  ];
  const grid = el("section", { className: "dash-kpis", "aria-label": `${periodLabel}指标` });
  for (const metric of metrics) {
    grid.append(el("article", { className: `dash-kpi ${metric.muted ? "is-muted" : ""}`.trim() }, [
      el("span", { className: "dash-kpi-label", text: metric.name }),
      el("strong", { className: "dash-kpi-value", text: metric.value, title: metric.title || null }),
      metric.note ? el("small", { className: `dash-kpi-note is-${metric.note.tone}`, text: metric.note.text }) : null
    ]));
  }
  return grid;
}

function panel(title, child, extraClass = "", { aside = null, titleId = "" } = {}) {
  return el("section", { className: `panel dashboard-panel ${extraClass}`.trim() }, [
    el("header", { className: "dashboard-panel-head" }, [el("h2", { id: titleId || null, text: title }), aside]),
    child
  ]);
}

// Axis maximum that splits into four whole, round steps (0, 25, 50, 75, 100).
function niceMax(value) {
  const target = Math.max(4, numberValue(value)) / 4;
  const magnitude = 10 ** Math.floor(Math.log10(target));
  const step = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].map((factor) => factor * magnitude).find((candidate) => candidate >= target && Number.isInteger(candidate)) || Math.ceil(target);
  return step * 4;
}

function svgNode(tag, attributes = {}, text = "") {
  const node = document.createElementNS("http://www.w3.org/2000/svg", tag);
  for (const [name, value] of Object.entries(attributes)) {
    node.setAttribute(name, String(value));
  }
  if (text) {
    node.textContent = text;
  }
  return node;
}

const TREND_SERIES = [
  { key: "request", label: "请求量", field: "request_count", color: "var(--primary)" },
  { key: "failed", label: "失败请求（右轴）", field: "failed_count", color: "var(--danger)" }
];

function trendChart(rows, bucket = "hour") {
  const maxPoints = bucket === "day" ? 30 : 24;
  const data = ascendingByBucket(rows).slice(-maxPoints);
  if (!data.length) {
    return statusMessage("暂无趋势数据");
  }
  const width = 960;
  const height = 260;
  const padding = { top: 16, right: 52, bottom: 40, left: 56 };
  const plotWidth = width - padding.left - padding.right;
  const plotHeight = height - padding.top - padding.bottom;
  const xAt = (index) => padding.left + (data.length === 1 ? plotWidth / 2 : index / (data.length - 1) * plotWidth);
  const visible = new Set(TREND_SERIES.map((series) => series.key));
  let activeIndex = -1;

  const svg = svgNode("svg", {
    class: "trend-line-chart-svg",
    viewBox: `0 0 ${width} ${height}`,
    role: "img",
    tabindex: "0",
    "aria-label": "按时间展示请求量与失败请求的趋势折线图，可用左右方向键逐点查看"
  });
  const tooltip = el("div", { className: "chart-tooltip", hidden: true });
  const live = el("span", { className: "visually-hidden", "aria-live": "polite" });
  const frame = el("div", { className: "trend-line-chart" }, [svg, tooltip, live]);

  function pointText(index) {
    const row = data[index];
    return [formatBucket(row.bucket_at, bucket), ...TREND_SERIES.filter((series) => visible.has(series.key)).map((series) => `${series.label} ${formatNumber(row[series.field])}`)].join(" · ");
  }

  function draw() {
    const active = TREND_SERIES.filter((series) => visible.has(series.key));
    // Failures are a small fraction of requests: with both series shown they
    // get their own scale on the right axis so their shape stays readable.
    const dualAxis = active.length > 1;
    const maxOf = (series) => niceMax(Math.max(...data.map((row) => numberValue(row[series.field]))));
    const shared = Math.max(4, ...active.map(maxOf));
    const scales = Object.fromEntries(active.map((series) => [series.key, dualAxis ? maxOf(series) : shared]));
    const yAt = (series, value) => padding.top + (1 - numberValue(value) / scales[series.key]) * plotHeight;
    const leftSeries = active[0];
    const rightSeries = dualAxis ? active[1] : null;
    svg.replaceChildren();
    for (let step = 0; step <= 4; step += 1) {
      const y = padding.top + step / 4 * plotHeight;
      svg.append(svgNode("line", { class: "trend-line-grid", x1: padding.left, x2: width - padding.right, y1: y, y2: y }));
      if (leftSeries) {
        svg.append(svgNode("text", { class: "trend-line-axis-label", x: padding.left - 10, y: y + 4, "text-anchor": "end" }, formatNumber(Math.round(scales[leftSeries.key] * (1 - step / 4)))));
      }
      if (rightSeries) {
        svg.append(svgNode("text", { class: `trend-line-axis-label trend-line-axis-right trend-line-axis-${rightSeries.key}`, x: width - padding.right + 10, y: y + 4, "text-anchor": "start" }, formatNumber(Math.round(scales[rightSeries.key] * (1 - step / 4)))));
      }
    }
    if (activeIndex >= 0) {
      svg.append(svgNode("line", { class: "trend-line-cursor", x1: xAt(activeIndex), x2: xAt(activeIndex), y1: padding.top, y2: padding.top + plotHeight }));
    }
    for (const series of active) {
      const coords = data.map((row, index) => [xAt(index), yAt(series, row[series.field])]);
      const points = coords.map(([x, y]) => `${x},${y}`).join(" ");
      if (series.key === "request" && coords.length > 1) {
        const base = padding.top + plotHeight;
        svg.append(svgNode("path", {
          class: "trend-line-area",
          d: `M${coords[0][0]},${base} ${coords.map(([x, y]) => `L${x},${y}`).join(" ")} L${coords[coords.length - 1][0]},${base} Z`
        }));
      }
      svg.append(svgNode("polyline", { class: `trend-line-series trend-line-series-${series.key}`, points, fill: "none" }));
      data.forEach((row, index) => {
        if (index !== activeIndex && data.length > 12) {
          return;
        }
        svg.append(svgNode("circle", {
          class: `trend-line-point trend-line-point-${series.key}${index === activeIndex ? " is-active" : ""}`,
          cx: xAt(index),
          cy: yAt(series, row[series.field]),
          r: index === activeIndex ? 5 : 3
        }));
      });
    }
    const labelStep = Math.max(1, Math.ceil(data.length / 8));
    data.forEach((row, index) => {
      if (index % labelStep !== 0 && index !== data.length - 1) {
        return;
      }
      svg.append(svgNode("text", {
        class: "trend-line-axis-label trend-line-time-label",
        x: xAt(index),
        y: height - 18,
        "text-anchor": index === 0 ? "start" : index === data.length - 1 ? "end" : "middle"
      }, formatBucket(row.bucket_at, bucket)));
    });
    // Transparent hit area so the pointer can be anywhere in the plot.
    svg.append(svgNode("rect", { class: "trend-line-hit", x: padding.left, y: padding.top, width: plotWidth, height: plotHeight, fill: "transparent" }));
    updateTooltip();
  }

  function updateTooltip() {
    if (activeIndex < 0 || !visible.size) {
      tooltip.hidden = true;
      return;
    }
    tooltip.textContent = pointText(activeIndex);
    tooltip.hidden = false;
    const ratio = xAt(activeIndex) / width;
    tooltip.style.left = `${Math.round(ratio * 100)}%`;
    tooltip.style.top = "8px";
    tooltip.style.transform = ratio > 0.7 ? "translateX(calc(-100% - 8px))" : "translateX(8px)";
  }

  function setActive(index, { announce = false } = {}) {
    const next = Math.max(-1, Math.min(data.length - 1, index));
    if (next === activeIndex) {
      return;
    }
    activeIndex = next;
    draw();
    if (announce && activeIndex >= 0) {
      live.textContent = pointText(activeIndex);
    }
  }

  svg.addEventListener("pointermove", (event) => {
    const rect = svg.getBoundingClientRect();
    if (!rect.width) {
      return;
    }
    const x = (event.clientX - rect.left) / rect.width * width;
    const ratio = data.length === 1 ? 0 : (x - padding.left) / plotWidth;
    setActive(Math.round(Math.max(0, Math.min(1, ratio)) * (data.length - 1)));
  });
  svg.addEventListener("pointerleave", () => setActive(-1));
  svg.addEventListener("blur", () => setActive(-1));
  svg.addEventListener("keydown", (event) => {
    const moves = { ArrowLeft: activeIndex < 0 ? data.length - 1 : activeIndex - 1, ArrowRight: activeIndex < 0 ? 0 : activeIndex + 1, Home: 0, End: data.length - 1 };
    if (event.key in moves) {
      event.preventDefault();
      setActive(Math.max(0, moves[event.key]), { announce: true });
    } else if (event.key === "Escape") {
      setActive(-1);
    }
  });

  const legend = el("div", { className: "chart-legend", role: "group", "aria-label": "显示的数据序列" }, TREND_SERIES.map((series) => {
    const toggle = el("button", { type: "button", "aria-pressed": "true", onClick: () => {
      if (visible.has(series.key) && visible.size === 1) {
        toast("至少保留一条数据序列", { timeout: 2000 });
        return;
      }
      visible.has(series.key) ? visible.delete(series.key) : visible.add(series.key);
      toggle.setAttribute("aria-pressed", String(visible.has(series.key)));
      draw();
    } }, [
      el("span", { className: "chart-legend-swatch", style: `background: ${series.color}`, "aria-hidden": "true" }),
      el("span", { text: series.label })
    ]);
    return toggle;
  }));

  const columns = ["bucket_at", "request_count", "success_count", "failed_count", "total_cost"];
  draw();
  return el("div", { className: "chart-area trend-chart" }, [
    el("div", { className: "chart-toolbar" }, [legend]),
    frame,
    dataTable("查看趋势数据表", columns, data)
  ]);
}

// Accessible fallback for charts: the same numbers as a plain table.
function dataTable(summary, columns, rows) {
  const head = el("tr", {}, columns.map((column) => el("th", { scope: "col", text: TABLE_LABELS[column] || column })));
  const body = el("tbody", {}, rows.map((row) => el("tr", {}, columns.map((column) => el("td", {
    text: column === "bucket_at" ? String(row[column] ?? "") : formatResourceValue(column, row[column])
  })))));
  return el("details", { className: "chart-data-table" }, [
    el("summary", { text: summary }),
    el("div", { className: "table-scroll" }, [el("table", {}, [el("thead", {}, [head]), body])])
  ]);
}

const TABLE_LABELS = {
  bucket_at: "时间 (UTC)",
  request_count: "请求量",
  success_count: "成功请求",
  failed_count: "失败请求",
  total_cost: "成本"
};

function csvCell(value) {
  const text = value === null || value === undefined ? "" : String(value);
  // Neutralise spreadsheet formulas and quote every cell.
  const safe = /^[=+\-@\t\r]/.test(text) && !/^-?\d+(\.\d+)?$/.test(text) ? `'${text}` : text;
  return `"${safe.replaceAll("\"", "\"\"")}"`;
}

function downloadCsv(filename, columns, rows) {
  const lines = [columns.map((column) => csvCell(TABLE_LABELS[column] || column)).join(",")];
  for (const row of rows) {
    lines.push(columns.map((column) => csvCell(row[column])).join(","));
  }
  const blob = new Blob([`﻿${lines.join("\r\n")}\r\n`], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = el("a", { href: url, download: filename });
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// Rankings carry only ids; names come from the lookup service (cached).
function fillNames(rows, type, apply) {
  const ids = rows.map((row) => row.id).filter((id) => id !== null && id !== undefined && id !== "");
  if (!ids.length || !canLookup(type)) {
    return;
  }
  resolveLookup(type, ids).then(apply, () => {});
}

function rankingLabel(row, fallback) {
  if (row.id === null || row.id === undefined || row.id === "" || Number(row.id) === 0) {
    return fallback;
  }
  return row.name || row.username || `#${row.id}`;
}

function departmentRanking(rows, navigate) {
  const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
  if (!data.length) {
    return statusMessage("暂无排行数据");
  }
  const max = Math.max(1, ...data.map((row) => numberValue(row.total_tokens)));
  const names = new Map();
  const list = el("ol", { className: "chart-bars ranking-chart department-ranking" }, data.map((row, index) => {
    const name = rankingLabel(row, "未归属部门");
    const label = el("span", { className: "ranking-name", text: name });
    names.set(Number(row.id), label);
    const hasId = Number(row.id) > 0;
    const inner = [
      label,
      el("span", { className: "bar-track", "aria-hidden": "true" }, [
        el("span", { className: `bar-fill ${index === 0 ? "is-top" : ""}`.trim(), style: `width: ${Math.max(2, Math.round(numberValue(row.total_tokens) / max * 100))}%` })
      ]),
      el("span", { className: "ranking-value", text: compactNumber(row.total_tokens), title: `${formatNumber(row.total_tokens)} Token · ${formatNumber(row.request_count)} 次调用` })
    ];
    return el("li", {}, [hasId
      ? el("button", { type: "button", className: "ranking-row", "aria-label": `${name}：${formatNumber(row.total_tokens)} Token，查看用量明细`, onClick: () => navigate("usage-records", { department_id: row.id }) }, inner)
      : el("div", { className: "ranking-row" }, inner)]);
  }));
  fillNames(data, "departments", (byId) => {
    for (const [id, node] of names) {
      const found = byId.get(id);
      if (found?.name) {
        node.textContent = found.name;
        node.closest("button")?.setAttribute("aria-label", `${found.name}：查看用量明细`);
      }
    }
  });
  return list;
}

function userRanking(rows, navigate) {
  const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
  if (!data.length) {
    return statusMessage("暂无排行数据");
  }
  const cells = new Map();
  const body = el("tbody", {}, data.map((row, index) => {
    const name = rankingLabel(row, "未归属员工");
    const hasId = Number(row.id) > 0;
    const nameNode = hasId
      ? el("button", { type: "button", className: "link-button", text: name, "aria-label": `${name} 用量明细`, onClick: () => navigate("usage-records", { user_id: row.id }) })
      : el("span", { text: name });
    const deptCell = el("td", { className: "ranking-dept", text: "" });
    cells.set(Number(row.id), { nameNode, deptCell });
    return el("tr", {}, [
      el("td", { className: "rank-no", text: String(index + 1) }),
      el("td", {}, [nameNode]),
      deptCell,
      el("td", { className: "num", text: formatNumber(row.request_count) }),
      el("td", { className: "num", text: compactNumber(row.total_tokens), title: formatNumber(row.total_tokens) })
    ]);
  }));
  fillNames(data, "users", async (byId) => {
    const departmentIds = [];
    for (const [id, { nameNode }] of cells) {
      const user = byId.get(id);
      if (user?.username) {
        nameNode.textContent = user.username;
        nameNode.setAttribute?.("aria-label", `${user.username} 用量明细`);
      }
      if (user?.department_id) {
        departmentIds.push(user.department_id);
      }
    }
    if (!departmentIds.length || !canLookup("departments")) {
      return;
    }
    const departments = await resolveLookup("departments", departmentIds).catch(() => new Map());
    for (const [id, { deptCell }] of cells) {
      const department = departments.get(Number(byId.get(id)?.department_id));
      deptCell.textContent = department?.name || "";
    }
  });
  return el("div", { className: "table-scroll ranking-table-wrap" }, [
    el("table", { className: "ranking-table" }, [
      el("thead", {}, [el("tr", {}, [
        el("th", { scope: "col", text: "#" }),
        el("th", { scope: "col", text: "员工" }),
        el("th", { scope: "col", text: "部门" }),
        el("th", { scope: "col", className: "num", text: "调用" }),
        el("th", { scope: "col", className: "num", text: "Token" })
      ])]),
      body
    ])
  ]);
}

function multimodalChart(rows) {
  const data = Array.isArray(rows) ? rows.slice(0, 6) : [];
  if (!data.length) {
    return statusMessage("暂无多模态数据");
  }
  const total = data.reduce((sum, row) => sum + numberValue(row.request_count), 0);
  const maxRequests = Math.max(1, ...data.map((row) => numberValue(row.request_count)));
  const summary = data.map((row) => `${formatResourceValue("capability", row.capability)} ${formatNumber(row.request_count)}`).join("，");
  // Horizontal bars compare capabilities directly; a ring hid the small ones.
  return el("ul", { className: "dash-bars", "aria-label": `多模态请求分布：${summary}` }, data.map((row) => {
    const name = formatResourceValue("capability", row.capability);
    const share = total ? Math.round(numberValue(row.request_count) / total * 100) : 0;
    return el("li", { title: `${formatNumber(row.total_tokens)} Token · ${formatNumber(row.error_count)} 异常` }, [
      el("span", { className: "dash-bar-name", text: name }),
      el("span", { className: "dash-bar-value", text: `${formatNumber(row.request_count)} · ${share}%` }),
      el("span", { className: "bar-track", "aria-hidden": "true" }, [
        el("span", { className: "bar-fill", style: `width: ${percent(row.request_count, maxRequests)}%` })
      ])
    ]);
  }));
}

function sumRows(rows, key) {
  return (Array.isArray(rows) ? rows : []).reduce((sum, row) => sum + numberValue(row[key]), 0);
}

const BLOCK_ACTIONS = new Set(["block", "reject", "deny", "blocked", "rejected"]);

function securityCharts(data, navigate, openAlerts = null) {
  const desensitization = Array.isArray(data.desensitization) ? data.desensitization : [];
  const blocked = desensitization.filter((row) => BLOCK_ACTIONS.has(String(row.action || "").toLowerCase()));
  const tiles = [
    ["脱敏事件", formatNumber(sumRows(desensitization, "event_count")), ""],
    ["待处理告警", openAlerts === null ? "—" : formatNumber(openAlerts), numberValue(openAlerts) > 0 ? "is-warning" : ""],
    ["拦截请求", formatNumber(sumRows(blocked, "event_count")), ""]
  ];
  return el("div", { className: "chart-stack" }, [
    el("div", { className: "security-tiles" }, tiles.map(([title, value, tone]) => (
      el("article", { className: `security-tile ${tone}`.trim() }, [
        el("span", { text: title }),
        el("strong", { text: value })
      ])
    ))),
    el("div", { className: "chart-actions" }, [
      button("查看告警事件", () => navigate("alert-events"), "secondary"),
      button("脱敏事件", () => navigate("desensitization-events"), "secondary"),
      button("审计日志", () => navigate("audit-logs"), "secondary")
    ])
  ]);
}

function performanceCharts(data) {
  const queue = data.queue || {};
  const buckets = ascendingByBucket(data.buckets).slice(-12);
  const latestBucket = buckets[buckets.length - 1] || {};
  const facts = el("dl", { className: "performance-facts" }, [
    el("div", {}, [el("dt", { text: "导出队列" }), el("dd", { text: `${formatNumber(queue.pending_exports || 0)} 待处理` })]),
    buckets.length ? el("div", {}, [el("dt", { text: "最近时段失败" }), el("dd", { text: formatNumber(latestBucket.failed_count) })]) : null,
    buckets.length ? el("div", {}, [el("dt", { text: "统计时段" }), el("dd", { text: `${formatBucket(buckets[0].bucket_at)} – ${formatBucket(latestBucket.bucket_at)}` })]) : null
  ]);
  if (!buckets.length) {
    return el("div", { className: "chart-stack" }, [statusMessage("暂无性能数据"), facts]);
  }
  const maxP99 = Math.max(1, ...buckets.map((row) => numberValue(row.p99_latency_ms)));
  const lastIndex = buckets.length - 1;
  return el("div", { className: "chart-stack" }, [
    el("div", { className: "performance-summary" }, [
      el("article", {}, [el("span", { text: "平均" }), el("strong", { text: formatLatency(latestBucket.avg_latency_ms) })]),
      el("article", {}, [el("span", { text: "P95" }), el("strong", { text: formatLatency(latestBucket.p95_latency_ms) })]),
      el("article", {}, [el("span", { text: "P99" }), el("strong", { text: formatLatency(latestBucket.p99_latency_ms) })])
    ]),
    // P99 per bucket: earlier buckets in pine, the latest in signal green.
    el("div", {
      className: "sparkline",
      role: "img",
      "aria-label": `最近 ${buckets.length} 个时段的 P99 延迟，最新 ${formatLatency(latestBucket.p99_latency_ms)}，最高 ${formatLatency(maxP99)}`
    }, buckets.map((row, index) => (
      el("span", {
        className: index === lastIndex ? "is-latest" : "",
        title: `${formatBucket(row.bucket_at)} P99 ${formatLatency(row.p99_latency_ms)}`,
        style: `height: ${Math.max(4, percent(row.p99_latency_ms, maxP99))}%`
      })
    ))),
    facts
  ]);
}

function select(name, options) {
  const control = el("select", { name });
  for (const [value, text] of options) {
    control.append(el("option", { value, text }));
  }
  return control;
}

