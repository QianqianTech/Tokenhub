import { adminErrorMessage, api, isAbortError } from "./api.js";
import { button, el, statusMessage } from "./dom.js";
import { errorState, loadingState, toast } from "./components/feedback.js";
import { formatResourceValue } from "./resources.js";
import { departmentSelectOptions } from "./lib/references.js";

export async function renderDashboard(content, navigate) {
  content.replaceChildren();
  const page = el("section", { className: "dashboard-screen-page" });
  const controls = el("form", { className: "dashboard-screen-filters filters", onSubmit: (event) => {
    event.preventDefault();
    load();
  } });
  const bucket = select("bucket", [["hour", "按小时"], ["day", "按天"]]);
  const department = select("department_id", [["", "全部部门"]]);
  const employeeQuery = el("input", { type: "search", name: "employee_query", placeholder: "搜索员工账号" });
  const sort = select("sort", [["request_count", "调用次数"], ["total_tokens", "Token"], ["modal_count", "模态次数"], ["error_count", "异常数"]]);
  controls.append(label("趋势粒度", bucket), label("部门", department), label("员工搜索", employeeQuery), label("排序", sort), button("刷新", () => load(), "secondary"));
  const period = select("period", [["today", "今日"], ["7d", "近 7 天"], ["30d", "近 30 天"], ["custom", "自定义区间"]]);
  period.value = "30d";
  bucket.value = "day";
  const customFrom = el("input", { type: "datetime-local", name: "from", disabled: true });
  const customTo = el("input", { type: "datetime-local", name: "to", disabled: true });
  const customFromField = label("开始时间", customFrom);
  const customToField = label("结束时间", customTo);
  customFromField.hidden = true;
  customToField.hidden = true;
  let employeeSearchTimer = null;
  period.addEventListener("change", () => {
    const isCustom = period.value === "custom";
    if (!isCustom) {
      bucket.value = period.value === "today" ? "hour" : "day";
    }
    customFrom.disabled = !isCustom;
    customTo.disabled = !isCustom;
    customFromField.hidden = !isCustom;
    customToField.hidden = !isCustom;
    if (!isCustom) {
      load();
    }
  });
  controls.prepend(label("时间阶段", period), customFromField, customToField);
  department.addEventListener("change", () => load());
  bucket.addEventListener("change", () => load());
  sort.addEventListener("change", () => load());
  employeeQuery.addEventListener("input", () => {
    clearTimeout(employeeSearchTimer);
    employeeSearchTimer = setTimeout(() => load(), 300);
  });
  const notice = el("p", { className: "dashboard-notice", role: "status", "aria-live": "polite" });
  const host = el("div", { className: "dashboard-grid dashboard-screen-grid", "aria-busy": "false" });
  page.append(controls, notice, host);
  content.append(page);

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

  async function load() {
    const seq = ++loadSeq;
    const isCurrent = () => seq === loadSeq && host.isConnected;
    const range = periodRange(period.value, customFrom.value, customTo.value);
    notice.textContent = "";
    if (!range) {
      host.replaceChildren(statusMessage("请选择有效的自定义时间区间", "error"));
      return;
    }
    const params = {
      bucket: bucket.value,
      sort: sort.value,
      from: range.from,
      to: range.to,
      department_id: department.value,
      employee_query: employeeQuery.value.trim()
    };
    const requests = {
      realtime: () => api("/admin-api/dashboard/realtime", { query: params }),
      trends: () => api("/admin-api/dashboard/trends", { query: params }),
      departmentRankings: () => api("/admin-api/dashboard/rankings", { query: { ...params, dimension: "department" } }),
      userRankings: () => api("/admin-api/dashboard/rankings", { query: { ...params, dimension: "user" } }),
      security: () => api("/admin-api/dashboard/security", { query: params }),
      performance: () => api("/admin-api/dashboard/performance", { query: params }),
      multimodal: () => api("/admin-api/dashboard/multimodal", { query: params })
    };
    host.setAttribute("aria-busy", "true");
    host.replaceChildren(loadingState("正在加载仪表盘数据…", { rows: 4 }));
    const keys = Object.keys(requests);
    const outcomes = await Promise.all(keys.map((key) => settle(requests[key]())));
    if (!isCurrent()) {
      return;
    }
    host.setAttribute("aria-busy", "false");
    const results = Object.fromEntries(keys.map((key, index) => [key, outcomes[index]]));
    const failures = outcomes.filter((outcome) => outcome.status === "rejected" && !isAbortError(outcome.reason));
    if (failures.length === outcomes.length) {
      host.replaceChildren(errorState(failures[0].reason, { fallback: "仪表盘数据加载失败", onRetry: () => load() }));
      return;
    }
    if (failures.length) {
      const describe = (error) => adminErrorMessage(error, "请求失败");
      notice.textContent = `部分面板加载失败（${failures.length} 项）：${describe(failures[0].reason)}`;
    }

    const realtime = dataOf(results.realtime);
    const trends = { data: [] };
    const today = realtime?.today || null;
    const children = [];
    if (today) {
      // Status first: whether anything needs attention, then the numbers.
      children.push(operationsStatusStrip(today, dataOf(results.performance) || {}, dataOf(results.security) || {}, range.label));
      children.push(metricGrid(today, range.label));
    } else {
      children.push(resultPanel("核心指标", "chart-panel panel-wide", results.realtime, requests.realtime, (data) => metricGrid(data?.today || {}, range.label), isCurrent));
    }
    children.push(
      resultPanel("趋势", "chart-panel panel-wide", results.trends, requests.trends, (data) => {
        trends.data = data;
        return trendChart(trends.data || [], bucket.value);
      }, isCurrent),
      resultPanel("部门用量排行", "chart-panel panel-half", results.departmentRankings, requests.departmentRankings, (data) => rankingChart(data || [], "department", navigate), isCurrent),
      resultPanel("员工用量排行", "chart-panel panel-half", results.userRankings, requests.userRankings, (data) => rankingChart(data || [], "user", navigate), isCurrent),
      resultPanel("多模态分析", "chart-panel panel-third", results.multimodal, requests.multimodal, (data) => multimodalChart(data?.capabilities || []), isCurrent),
      resultPanel("安全看板", "chart-panel panel-third", results.security, requests.security, (data) => securityCharts(data || {}, navigate), isCurrent),
      resultPanel("性能看板", "chart-panel panel-third", results.performance, requests.performance, (data) => performanceCharts(data || {}), isCurrent)
    );
    host.replaceChildren(...children);
  }

  await loadDepartmentOptions();
  await load();
}

function settle(promise) {
  return promise.then((value) => ({ status: "fulfilled", value }), (reason) => ({ status: "rejected", reason }));
}

function dataOf(outcome) {
  return outcome?.status === "fulfilled" ? outcome.value?.data ?? null : null;
}

// A dashboard panel whose body shows either the rendered chart or an error
// state that retries just this panel's request.
function resultPanel(title, className, outcome, request, render, isCurrent) {
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
  return panel(title, body, className);
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

function periodRange(period, customFrom, customTo) {
  const now = new Date();
  if (period === "custom") {
    const from = new Date(customFrom);
    const to = new Date(customTo);
    if (!customFrom || !customTo || !Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime()) || from >= to) {
      return null;
    }
    return { from: sqlTimestamp(from), to: sqlTimestamp(to), label: "区间" };
  }
  const options = {
    today: [0, "今日"],
    "7d": [6, "近 7 天"],
    "30d": [29, "近 30 天"]
  };
  const [days, label] = options[period] || options.today;
  const start = startOfLocalDay(now);
  start.setDate(start.getDate() - days);
  return { from: sqlTimestamp(start), to: sqlTimestamp(now), label };
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

function operationsStatusStrip(today, performance, security, periodLabel) {
  const buckets = ascendingByBucket(performance.buckets);
  const latest = buckets[buckets.length - 1] || {};
  const failed = numberValue(today.failed_count);
  const securityEvents = sumRows(security.desensitization, "event_count");
  const attention = failed > 0 || securityEvents > 0;
  const items = [
    ["异常请求", formatNumber(failed)],
    ["最新 P99 延迟", `${formatNumber(latest.p99_latency_ms)} ms`],
    ["安全事件", formatNumber(securityEvents)]
  ];
  return el("section", { className: `operations-status-strip ${attention ? "is-attention" : "is-stable"}` }, [
    el("header", { className: "operations-status-heading" }, [
      el("span", { text: attention ? "需关注" : "运行平稳" }),
      el("small", { text: attention ? "当前时间段内存在需要核查的信号" : "当前时间段内未发现异常信号" })
    ]),
    ...items.map(([labelText, value]) => el("article", { className: "operations-status-item" }, [
      el("span", { text: labelText }),
      el("strong", { text: value })
    ]))
  ]);
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

function formatCost(value) {
  const number = numberValue(value);
  return number > 0 ? number.toFixed(4) : "未配置";
}

function percent(value, max) {
  if (max <= 0) {
    return 0;
  }
  return Math.max(4, Math.round(numberValue(value) / max * 100));
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

function rowName(row, fallback = "未命名") {
  return row.name || row.username || row.model_name || row.model || row.provider || row.api_key_name || row.capability || row.id || fallback;
}

// Older backends omit success_count; derive it instead of showing 0.
function successCount(today) {
  if (today.success_count != null) {
    return today.success_count;
  }
  return Math.max(0, numberValue(today.request_count) - numberValue(today.failed_count));
}

function metricGrid(today, periodLabel = "今日") {
  const metrics = [
    [`${periodLabel}请求`, formatNumber(today.request_count)],
    ["成功请求", formatNumber(successCount(today))],
    ["失败请求", formatNumber(today.failed_count)],
    ["总 Token", formatNumber(today.total_tokens)],
    ["平均延迟", `${formatNumber(today.avg_latency_ms)} ms`],
    ["成本", formatIntegerCost(today.total_cost)]
  ];
  const grid = el("section", { className: "metric-grid" });
  for (const [name, value] of metrics) {
    if (name === "成本" && numberValue(today.total_cost) === 0) {
      grid.append(el("article", { className: "metric muted" }, [el("span", { text: name }), el("strong", { text: "未配置" })]));
    } else {
      grid.append(el("article", { className: "metric" }, [el("span", { text: name }), el("strong", { text: value })]));
    }
  }
  return grid;
}

function panel(title, child, extraClass = "") {
  return el("section", { className: `panel dashboard-panel ${extraClass}`.trim() }, [el("h2", { text: title }), child]);
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
  { key: "request", label: "请求量", field: "request_count", color: "var(--dashboard-screen-primary, var(--primary))" },
  { key: "failed", label: "失败请求", field: "failed_count", color: "var(--danger)" }
];

function trendChart(rows, bucket = "hour") {
  const maxPoints = bucket === "day" ? 30 : 24;
  const data = ascendingByBucket(rows).slice(-maxPoints);
  if (!data.length) {
    return statusMessage("暂无趋势数据");
  }
  const width = 960;
  const height = 286;
  const padding = { top: 20, right: 20, bottom: 54, left: 56 };
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
    const maxValue = Math.max(1, ...data.flatMap((row) => active.map((series) => numberValue(row[series.field]))));
    const yAt = (value) => padding.top + (1 - numberValue(value) / maxValue) * plotHeight;
    svg.replaceChildren();
    for (let step = 0; step <= 4; step += 1) {
      const y = padding.top + step / 4 * plotHeight;
      const value = Math.round(maxValue * (1 - step / 4));
      svg.append(svgNode("line", { class: "trend-line-grid", x1: padding.left, x2: width - padding.right, y1: y, y2: y }));
      svg.append(svgNode("text", { class: "trend-line-axis-label", x: padding.left - 10, y: y + 4, "text-anchor": "end" }, formatNumber(value)));
    }
    if (activeIndex >= 0) {
      svg.append(svgNode("line", { class: "trend-line-cursor", x1: xAt(activeIndex), x2: xAt(activeIndex), y1: padding.top, y2: padding.top + plotHeight }));
    }
    for (const series of active) {
      const points = data.map((row, index) => `${xAt(index)},${yAt(row[series.field])}`).join(" ");
      svg.append(svgNode("polyline", { class: `trend-line-series trend-line-series-${series.key}`, points, fill: "none" }));
      data.forEach((row, index) => {
        const value = numberValue(row[series.field]);
        svg.append(svgNode("circle", {
          class: `trend-line-point trend-line-point-${series.key}${index === activeIndex ? " is-active" : ""}`,
          cx: xAt(index),
          cy: yAt(value),
          r: index === activeIndex ? 6 : 4
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
  const csvButton = button("导出 CSV", () => downloadCsv(`dashboard-trends-${bucket}.csv`, columns, data), "secondary chart-action");
  draw();
  return el("div", { className: "chart-area trend-chart" }, [
    frame,
    el("div", { className: "chart-toolbar" }, [legend, csvButton]),
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

function rankingChart(rows, dimension, navigate) {
  const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
  if (!data.length) {
    return statusMessage("暂无排行数据");
  }
  const maxRequests = Math.max(1, ...data.map((row) => numberValue(row.request_count)));
  const filterKey = dimension === "department" ? "department_id" : `${dimension}_id`;
  const list = el("div", { className: "chart-bars ranking-chart", role: "list" });
  data.forEach((row, index) => {
    const name = rowName(row, `项目 ${index + 1}`);
    const requestCount = numberValue(row.request_count);
    const open = () => navigate("usage-records", { [filterKey]: row.id });
    // The whole row opens the usage records; the button keeps keyboard access.
    list.append(el("article", { className: "ranking-bar-row is-clickable", role: "listitem", onClick: (event) => {
      if (!event.target.closest("button")) open();
    } }, [
      el("span", { className: "ranking-index", text: String(index + 1).padStart(2, "0") }),
      el("div", { className: "ranking-bar-main" }, [
        el("div", { className: "ranking-bar-top" }, [
          el("strong", { className: "ranking-name", text: name }),
          el("span", { className: "ranking-value", text: formatNumber(requestCount) })
        ]),
        el("div", { className: "bar-track", "aria-hidden": "true" }, [
          el("span", { className: "bar-fill", style: `width: ${percent(requestCount, maxRequests)}%` })
        ]),
        el("small", { className: "chart-subtle", text: `${formatNumber(row.total_tokens)} Token · ${formatNumber(row.error_count)} 异常 · 成本 ${formatCost(row.total_cost)}` })
      ]),
      button("详情", open, "ghost chart-action", { "aria-label": `${name} 用量详情` })
    ]));
  });
  return list;
}

function multimodalChart(rows) {
  const data = Array.isArray(rows) ? rows.slice(0, 6) : [];
  if (!data.length) {
    return statusMessage("暂无多模态数据");
  }
  const total = data.reduce((sum, row) => sum + numberValue(row.request_count), 0);
  const maxRequests = Math.max(1, ...data.map((row) => numberValue(row.request_count)));
  const colorAt = (index) => `var(--chart-${index % 6 + 1})`;
  const summary = data.map((row) => `${formatResourceValue("capability", row.capability)} ${formatNumber(row.request_count)}`).join("，");
  // Horizontal bars compare capabilities directly; a ring hid the small ones.
  return el("div", { className: "distribution-chart distribution-chart--bars", role: "img", "aria-label": `多模态请求分布：${summary}` }, [
    el("p", { className: "chart-total" }, [el("strong", { text: formatNumber(total) }), el("span", { text: " 次请求" })]),
    el("div", { className: "chart-bars" }, data.map((row, index) => {
      const name = formatResourceValue("capability", row.capability);
      return el("article", { className: "compact-bar-row" }, [
        el("span", { className: "series-dot", style: `background: ${colorAt(index)}`, "aria-hidden": "true" }),
        el("div", { className: "compact-bar-main" }, [
          el("div", { className: "ranking-bar-top" }, [
            el("strong", { className: "ranking-name", text: name }),
            el("span", { className: "ranking-value", text: `${formatNumber(row.request_count)} · ${total ? Math.round(numberValue(row.request_count) / total * 100) : 0}%` })
          ]),
          el("div", { className: "bar-track", "aria-hidden": "true" }, [
            el("span", { className: "bar-fill", style: `width: ${percent(row.request_count, maxRequests)}%; background: ${colorAt(index)}` })
          ]),
          el("small", { className: "chart-subtle", text: `${formatNumber(row.total_tokens)} Token · ${formatNumber(row.error_count)} 异常` })
        ])
      ]);
    }))
  ]);
}

function sumRows(rows, key) {
  return (Array.isArray(rows) ? rows : []).reduce((sum, row) => sum + numberValue(row[key]), 0);
}

function securityCharts(data, navigate) {
  const desensitization = data.desensitization || [];
  const cards = [
    ["脱敏事件", sumRows(desensitization, "event_count"), desensitization[0]?.action || "内容策略"]
  ];
  return el("div", { className: "chart-stack" }, [
    el("div", { className: "security-chart-grid" }, cards.map(([title, value, meta], index) => (
      el("article", { className: `security-chart-card accent-${index}` }, [
        el("span", { text: title }),
        el("strong", { text: formatNumber(value) }),
        el("small", { text: meta })
      ])
    ))),
    el("div", { className: "chart-actions" }, [
      button("脱敏事件", () => navigate("desensitization-events"), "secondary"),
      button("审计日志", () => navigate("audit-logs"), "secondary")
    ])
  ]);
}

function performanceCharts(data) {
  const queue = data.queue || {};
  const buckets = ascendingByBucket(data.buckets).slice(-12);
  if (!buckets.length) {
    return el("div", { className: "chart-stack" }, [
      el("div", { className: "performance-summary" }, [
        el("article", {}, [el("span", { text: "导出队列" }), el("strong", { text: formatNumber(queue.pending_exports || 0) })])
      ]),
      statusMessage("暂无性能数据")
    ]);
  }
  const maxP99 = Math.max(1, ...buckets.map((row) => numberValue(row.p99_latency_ms)));
  const latestBucket = buckets[buckets.length - 1] || {};
  const lastIndex = buckets.length - 1;
  return el("div", { className: "chart-stack" }, [
    el("div", { className: "performance-summary" }, [
      el("article", {}, [el("span", { text: "平均延迟" }), el("strong", { text: `${formatNumber(latestBucket.avg_latency_ms)} ms` })]),
      el("article", {}, [el("span", { text: "P95" }), el("strong", { text: `${formatNumber(latestBucket.p95_latency_ms)} ms` })]),
      el("article", {}, [el("span", { text: "P99" }), el("strong", { text: `${formatNumber(latestBucket.p99_latency_ms)} ms` })]),
      el("article", {}, [el("span", { text: "导出队列" }), el("strong", { text: formatNumber(queue.pending_exports || 0) })])
    ]),
    // P99 per bucket: earlier buckets in pine, the latest in signal green.
    el("div", {
      className: "sparkline",
      role: "img",
      "aria-label": `最近 ${buckets.length} 个时段的 P99 延迟，最新 ${formatNumber(latestBucket.p99_latency_ms)} ms，最高 ${formatNumber(maxP99)} ms`
    }, buckets.map((row, index) => (
      el("span", {
        className: index === lastIndex ? "is-latest" : "",
        title: `${formatBucket(row.bucket_at)} P99 ${formatNumber(row.p99_latency_ms)} ms`,
        style: `height: ${Math.max(4, percent(row.p99_latency_ms, maxP99))}%`
      })
    ))),
    el("p", { className: "chart-subtle", text: `统计时段 ${formatBucket(buckets[0].bucket_at)} – ${formatBucket(latestBucket.bucket_at)} · 最近失败 ${formatNumber(latestBucket.failed_count)}` })
  ]);
}

function select(name, options) {
  const control = el("select", { name });
  for (const [value, text] of options) {
    control.append(el("option", { value, text }));
  }
  return control;
}

function label(text, control) {
  return el("label", { className: "field" }, [el("span", { text }), control]);
}
