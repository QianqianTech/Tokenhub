import { adminErrorMessage, api } from "./api.js?v=20260710-csrf-shared-v1";
import { button, clear, el, statusMessage } from "./dom.js";
import { columnLabel, formatResourceValue } from "./resources.js";

export async function renderDashboard(content, navigate) {
  clear(content);
  const page = el("section", { className: "dashboard-screen-page" });
  const controls = el("form", { className: "dashboard-screen-filters filters", onSubmit: (event) => {
    event.preventDefault();
    load();
  } });
  const bucket = select("bucket", [["hour", "按小时"], ["day", "按天"]]);
  const department = select("department_id", [["", "全部部门"]]);
  const employeeQuery = el("input", { type: "search", name: "employee_query", placeholder: "搜索员工账号" });
  const sort = select("sort", [["request_count", "调用次数"], ["total_tokens", "Token"], ["modal_count", "模态次数"], ["error_count", "异常数"]]);
  controls.append(label("趋势粒度", bucket), label("部门", department), label("员工搜索", employeeQuery), label("排序", sort), button("刷新", () => load()));
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
  });
  controls.prepend(label("时间阶段", period), customFromField, customToField);
  department.addEventListener("change", () => load());
  employeeQuery.addEventListener("input", () => {
    clearTimeout(employeeSearchTimer);
    employeeSearchTimer = setTimeout(() => load(), 300);
  });
  const host = el("div", { className: "dashboard-grid dashboard-screen-grid" });
  page.append(controls, host);
  content.append(page);

  async function loadDepartmentOptions() {
    const result = await api("/admin-api/departments", { query: { limit: 100 } }).catch(() => null);
    const rows = Array.isArray(result?.data) ? result.data : Array.isArray(result) ? result : [];
    for (const row of rows) {
      department.append(el("option", { value: String(row.id), text: row.name || `部门 ${row.id}` }));
    }
  }

  async function load() {
    clear(host);
    const range = periodRange(period.value, customFrom.value, customTo.value);
    if (!range) {
      host.append(statusMessage("请选择有效的自定义时间区间", "error"));
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
    const results = await Promise.all([
      api("/admin-api/dashboard/realtime", { query: params }),
      api("/admin-api/dashboard/trends", { query: params }),
      api("/admin-api/dashboard/rankings", { query: { ...params, dimension: "department" } }),
      api("/admin-api/dashboard/rankings", { query: { ...params, dimension: "user" } }),
      api("/admin-api/dashboard/security", { query: params }),
      api("/admin-api/dashboard/performance", { query: params }),
      api("/admin-api/dashboard/multimodal", { query: params })
    ]).catch((error) => {
      host.append(statusMessage(adminErrorMessage(error, "仪表盘数据加载失败"), "error"));
      return null;
    });
    if (!results) {
      return;
    }
    const [realtime, trends, departmentRankings, userRankings, security, performance, multimodal] = results;
    document.getElementById("request-id").textContent = realtime.requestId || "";
    host.append(metricGrid(realtime.data.today || {}));
    host.append(operationsStatusStrip(realtime.data.today || {}, performance.data || {}, security.data || {}, range.label));
    host.append(panel("趋势", trendChart(trends.data || [], bucket.value), "chart-panel panel-wide"));
    host.append(panel("部门用量排行", rankingChart(departmentRankings.data || [], "department", navigate), "chart-panel"));
    host.append(panel("员工用量排行", rankingChart(userRankings.data || [], "user", navigate), "chart-panel"));
    host.append(panel("多模态分析", multimodalChart(multimodal.data.capabilities || []), "chart-panel"));
    host.append(panel("安全看板", securityCharts(security.data || {}, navigate), "chart-panel"));
    host.append(panel("性能看板", performanceCharts(performance.data || {}), "chart-panel"));
  }

  await loadDepartmentOptions();
  await load();
}

function sqlTimestamp(value) {
  return value.toISOString().slice(0, 19).replace("T", " ");
}

function startOfUtcDay(value) {
  const start = new Date(value);
  start.setUTCHours(0, 0, 0, 0);
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
    return { from: sqlTimestamp(from), to: sqlTimestamp(to), label: "自定义区间" };
  }
  const start = startOfUtcDay(now);
  const options = {
    today: [0, "今日"],
    "7d": [6, "近 7 天"],
    "30d": [29, "近 30 天"]
  };
  const [days, label] = options[period] || options.today;
  start.setUTCDate(start.getUTCDate() - days);
  return { from: sqlTimestamp(start), to: sqlTimestamp(now), label };
}

function operationsStatusStrip(today, performance, security, periodLabel) {
  const buckets = Array.isArray(performance.buckets) ? performance.buckets : [];
  const latest = buckets[0] || {};
  const failed = numberValue(today.failed_count);
  const securityEvents = sumRows(security.waf, "event_count") + sumRows(security.desensitization, "event_count");
  const attention = failed > 0 || securityEvents > 0;
  const items = [
    ["统计时间", periodLabel],
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

function formatBucket(value) {
  if (!value) {
    return "--";
  }
  const text = String(value);
  return text.length > 16 ? text.slice(5, 16) : text;
}

function rowName(row, fallback = "未命名") {
  return row.name || row.username || row.model_name || row.model || row.provider || row.api_key_name || row.capability || row.id || fallback;
}

function metricGrid(today) {
  const metrics = [
    ["今日请求", today.request_count || 0],
    ["成功请求", today.success_count || 0],
    ["失败请求", today.failed_count || 0],
    ["总 Token", today.total_tokens || 0],
    ["平均延迟", today.avg_latency_ms ? `${today.avg_latency_ms} ms` : "0 ms"],
    ["成本", formatIntegerCost(today.total_cost)]
  ];
  const grid = el("section", { className: "metric-grid" });
  for (const [name, value] of metrics) {
    if (name === "成本" && Number(value) === 0) {
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

function simpleTable(rows, columns) {
  if (!rows.length) {
    return statusMessage("暂无数据");
  }
  const table = el("table");
  const head = el("tr");
  for (const column of columns) {
    head.append(el("th", { text: columnLabel(column) }));
  }
  const body = el("tbody");
  for (const row of rows) {
    const tr = el("tr");
    for (const column of columns) {
      tr.append(el("td", { text: formatResourceValue(column, row[column]) }));
    }
    body.append(tr);
  }
  table.append(el("thead", {}, [head]), body);
  return table;
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

function trendChart(rows, bucket = "hour") {
  const maxPoints = bucket === "day" ? 30 : 24;
  const data = Array.isArray(rows) ? rows.slice(-maxPoints).reverse() : [];
  if (!data.length) {
    return statusMessage("暂无趋势数据");
  }
  const width = 960;
  const height = 286;
  const padding = { top: 20, right: 20, bottom: 54, left: 56 };
  const maxValue = Math.max(1, ...data.flatMap((row) => [numberValue(row.request_count), numberValue(row.failed_count)]));
  const plotWidth = width - padding.left - padding.right;
  const plotHeight = height - padding.top - padding.bottom;
  const xAt = (index) => padding.left + (data.length === 1 ? plotWidth / 2 : index / (data.length - 1) * plotWidth);
  const yAt = (value) => padding.top + (1 - numberValue(value) / maxValue) * plotHeight;
  const svg = svgNode("svg", {
    class: "trend-line-chart-svg",
    viewBox: `0 0 ${width} ${height}`,
    role: "img",
    "aria-label": "按时间展示请求量与失败请求的趋势折线图"
  });

  for (let step = 0; step <= 4; step += 1) {
    const y = padding.top + step / 4 * plotHeight;
    const value = Math.round(maxValue * (1 - step / 4));
    svg.append(svgNode("line", { class: "trend-line-grid", x1: padding.left, x2: width - padding.right, y1: y, y2: y }));
    svg.append(svgNode("text", { class: "trend-line-axis-label", x: padding.left - 10, y: y + 4, "text-anchor": "end" }, formatNumber(value)));
  }

  const series = [
    ["request", "请求量", "trend-line-series trend-line-series-request", "request_count"],
    ["failed", "失败请求", "trend-line-series trend-line-series-failed", "failed_count"]
  ];
  for (const [key, labelText, className, field] of series) {
    const points = data.map((row, index) => `${xAt(index)},${yAt(row[field])}`).join(" ");
    svg.append(svgNode("polyline", { class: className, points, fill: "none" }));
    data.forEach((row, index) => {
      const value = numberValue(row[field]);
      const point = svgNode("circle", { class: `trend-line-point trend-line-point-${key}`, cx: xAt(index), cy: yAt(value), r: 4 });
      point.append(svgNode("title", {}, `${formatBucket(row.bucket_at)} ${labelText} ${formatNumber(value)}`));
      svg.append(point);
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
    }, formatBucket(row.bucket_at)));
  });

  return el("div", { className: "chart-area trend-chart" }, [
    el("div", { className: "trend-line-chart" }, [svg]),
    el("div", { className: "chart-legend" }, [
      el("span", { text: "请求量" }),
      el("span", { text: "失败请求" })
    ])
  ]);
}

function rankingTable(rows, dimension, navigate) {
  if (!rows.length) {
    return statusMessage("暂无排行数据");
  }
  const table = el("table");
  const head = el("tr");
  for (const column of ["id", "request_count", "total_tokens", "modal_count", "error_count", "total_cost"]) {
    head.append(el("th", { text: columnLabel(column) }));
  }
  head.append(el("th", { text: "操作" }));
  const body = el("tbody");
  for (const row of rows) {
    const tr = el("tr");
    for (const column of ["id", "request_count", "total_tokens", "modal_count", "error_count", "total_cost"]) {
      tr.append(el("td", { text: formatResourceValue(column, row[column]) }));
    }
    const filterKey = dimension === "department" ? "department_id" : `${dimension}_id`;
    tr.append(el("td", {}, [button("详情", () => navigate("usage-records", { [filterKey]: row.id }), "secondary")]));
    body.append(tr);
  }
  table.append(el("thead", {}, [head]), body);
  return table;
}

function rankingChart(rows, dimension, navigate) {
  const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
  if (!data.length) {
    return statusMessage("暂无排行数据");
  }
  const maxRequests = Math.max(1, ...data.map((row) => numberValue(row.request_count)));
  const filterKey = dimension === "department" ? "department_id" : `${dimension}_id`;
  const list = el("div", { className: "chart-bars ranking-chart" });
  data.forEach((row, index) => {
    const name = rowName(row, `项目 ${index + 1}`);
    const requestCount = numberValue(row.request_count);
    list.append(el("article", { className: "ranking-bar-row" }, [
      el("span", { className: "ranking-index", text: String(index + 1).padStart(2, "0") }),
      el("div", { className: "ranking-bar-main" }, [
        el("div", { className: "ranking-bar-top" }, [
          el("strong", { className: "ranking-name", text: name }),
          el("span", { className: "ranking-value", text: formatNumber(requestCount) })
        ]),
        el("div", { className: "bar-track" }, [
          el("span", { className: "bar-fill", style: `width: ${percent(requestCount, maxRequests)}%` })
        ]),
        el("small", { className: "chart-subtle", text: `${formatNumber(row.total_tokens)} Token · ${formatNumber(row.error_count)} 异常 · 成本 ${formatCost(row.total_cost)}` })
      ]),
      button("详情", () => navigate("usage-records", { [filterKey]: row.id }), "secondary chart-action")
    ]));
  });
  return list;
}

function multimodalChart(rows) {
  const data = Array.isArray(rows) ? rows.slice(0, 6) : [];
  if (!data.length) {
    return statusMessage("暂无多模态数据");
  }
  const total = data.reduce((sum, row) => sum + numberValue(row.request_count), 0) || 1;
  const maxRequests = Math.max(1, ...data.map((row) => numberValue(row.request_count)));
  let cursor = 0;
  const colors = ["#2563eb", "#14b8a6", "#8b5cf6", "#f59e0b", "#ef4444", "#64748b"];
  const segments = data.map((row, index) => {
    const start = cursor;
    cursor += numberValue(row.request_count) / total * 100;
    return `${colors[index % colors.length]} ${start}% ${cursor}%`;
  }).join(", ");
  return el("div", { className: "distribution-chart" }, [
    el("div", {
      className: "donut-chart",
      style: `background: conic-gradient(${segments});`
    }, [
      el("strong", { text: formatNumber(total) }),
      el("span", { text: "请求" })
    ]),
    el("div", { className: "chart-bars" }, data.map((row, index) => {
      const name = formatResourceValue("capability", row.capability);
      return el("article", { className: "compact-bar-row" }, [
        el("span", { className: "series-dot", style: `background: ${colors[index % colors.length]}` }),
        el("div", { className: "compact-bar-main" }, [
          el("div", { className: "ranking-bar-top" }, [
            el("strong", { className: "ranking-name", text: name }),
            el("span", { className: "ranking-value", text: formatNumber(row.request_count) })
          ]),
          el("div", { className: "bar-track" }, [
            el("span", { className: "bar-fill", style: `width: ${percent(row.request_count, maxRequests)}%` })
          ]),
          el("small", { className: "chart-subtle", text: `${formatNumber(row.total_tokens)} Token · ${formatNumber(row.error_count)} 异常` })
        ])
      ]);
    }))
  ]);
}

function securityPanel(data, navigate) {
  return el("div", { className: "stack" }, [
    el("h3", { text: "防火墙命中" }),
    simpleTable(data.waf || [], ["action", "event_count"]),
    button("查看防火墙事件", () => navigate("waf-events"), "secondary"),
    el("h3", { text: "IP 拦截" }),
    simpleTable(data.blocked_ips || [], ["client_ip", "event_count"]),
    el("h3", { text: "脱敏事件" }),
    simpleTable(data.desensitization || [], ["action", "match_count", "event_count"]),
    button("查看脱敏事件", () => navigate("desensitization-events"), "secondary"),
    button("查看审计日志", () => navigate("audit-logs"), "secondary")
  ]);
}

function sumRows(rows, key) {
  return (Array.isArray(rows) ? rows : []).reduce((sum, row) => sum + numberValue(row[key]), 0);
}

function securityCharts(data, navigate) {
  const waf = data.waf || [];
  const blockedIps = data.blocked_ips || [];
  const desensitization = data.desensitization || [];
  const cards = [
    ["防火墙命中", sumRows(waf, "event_count"), waf[0]?.action || "规则命中"],
    ["IP 拦截", sumRows(blockedIps, "event_count"), blockedIps[0]?.client_ip || "访问控制"],
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
      button("防火墙事件", () => navigate("waf-events"), "secondary"),
      button("脱敏事件", () => navigate("desensitization-events"), "secondary"),
      button("审计日志", () => navigate("audit-logs"), "secondary")
    ])
  ]);
}

function performancePanel(data) {
  const queue = data.queue || {};
  return el("div", { className: "stack" }, [
    el("p", { text: `导出队列积压：${queue.pending_exports || 0}` }),
    simpleTable(data.buckets || [], ["bucket_at", "request_count", "failed_count", "avg_latency_ms", "p95_latency_ms", "p99_latency_ms"])
  ]);
}

function performanceCharts(data) {
  const queue = data.queue || {};
  const buckets = Array.isArray(data.buckets) ? data.buckets.slice(-10) : [];
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
  return el("div", { className: "chart-stack" }, [
    el("div", { className: "performance-summary" }, [
      el("article", {}, [el("span", { text: "导出队列" }), el("strong", { text: formatNumber(queue.pending_exports || 0) })]),
      el("article", {}, [el("span", { text: "最近平均延迟" }), el("strong", { text: `${formatNumber(latestBucket.avg_latency_ms)} ms` })]),
      el("article", {}, [el("span", { text: "最近 P99" }), el("strong", { text: `${formatNumber(latestBucket.p99_latency_ms)} ms` })])
    ]),
    el("div", { className: "sparkline" }, buckets.map((row) => (
      el("span", {
        title: `${formatBucket(row.bucket_at)} P99 ${formatNumber(row.p99_latency_ms)} ms`,
        style: `height: ${percent(row.p99_latency_ms, maxP99)}%`
      })
    ))),
    el("div", { className: "latency-buckets" }, buckets.slice(-4).map((row) => (
      el("article", { className: "latency-card" }, [
        el("span", { text: formatBucket(row.bucket_at) }),
        el("strong", { text: `${formatNumber(row.avg_latency_ms)} ms` }),
        el("small", { text: `P95 ${formatNumber(row.p95_latency_ms)} · P99 ${formatNumber(row.p99_latency_ms)} · 失败 ${formatNumber(row.failed_count)}` })
      ])
    )))
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
