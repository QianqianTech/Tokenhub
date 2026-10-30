async function api(path, options = {}) {
  let response;
  try {
    response = await fetch(path, {
      credentials: "same-origin",
      ...options,
      headers: {
        "Accept": "application/json",
        ...(options.body ? { "Content-Type": "application/json" } : {}),
        ...(options.headers || {})
      }
    });
  } catch {
    const error = new Error("无法连接大屏服务，请检查网络后重试");
    error.code = "SCREEN_NETWORK_ERROR";
    error.status = 0;
    throw error;
  }
  const payload = await response.json().catch(() => ({ ok: false, error: { code: "BAD_RESPONSE", message: "服务返回格式异常" } }));
  if (!response.ok || payload.ok === false) {
    const error = new Error(payload.error?.message || "大屏请求失败");
    error.code = payload.error?.code || payload.code || (response.ok ? "BAD_RESPONSE" : "SCREEN_REQUEST_FAILED");
    error.status = response.status;
    throw error;
  }
  return payload.data;
}

function screenErrorMessage(error, fallback = "大屏请求失败，请稍后重试") {
  const messages = {
    BAD_RESPONSE: "服务返回格式异常",
    SCREEN_NETWORK_ERROR: "无法连接大屏服务，请检查网络后重试",
    SCREEN_REQUEST_FAILED: "大屏服务暂时不可用，请稍后重试",
    SCREEN_LINK_DISABLED: "大屏链接已停用",
    SCREEN_LINK_EXPIRED: "大屏链接已过期",
    SCREEN_IP_FORBIDDEN: "当前 IP 不在允许范围内",
    SCREEN_UNAUTHENTICATED: "大屏链接无效或会话已失效",
    CONTENT_FORBIDDEN: "无权访问该大屏内容",
    NOT_FOUND: "大屏接口不存在，请检查部署配置",
    INTERNAL_ERROR: "大屏服务暂时不可用，请稍后重试"
  };
  return messages[error?.code] || fallback;
}

const $ = (id) => document.getElementById(id);
const setText = (node, value) => { node.textContent = value == null ? "" : String(value); };
const SCREEN_BRAND_DEFAULTS = {
  product_name: "白云区智慧政务",
  screen_title: "白云区智慧政务 运营大屏",
  logo_url: "",
  favicon_url: "/screen/assets/brand/favicon.ico"
};
const SCREEN_REFRESH_INTERVAL_MS = 10000;
const SCREEN_VIEW_ORDER = ["overview", "operations"];
const SCREEN_VIEW_KEYS = new Set(SCREEN_VIEW_ORDER);
let refreshTimer = null;
let clockTimer = null;
let currentScreenView = "overview";
const metricValueState = new Map();
const metricAnimationFrames = new Map();

function screenSafeLocalAssetUrl(value) {
  const url = String(value || "").trim();
  if (url === "") {
    return "";
  }
  if (url.startsWith("/") && !url.startsWith("//")) {
    return url;
  }
  if (url.startsWith("data:image/") && url.includes(";base64,")) {
    return url;
  }
  return "";
}

function screenApplyBrandIdentity(settings = {}) {
  const productName = String(settings.product_name || SCREEN_BRAND_DEFAULTS.product_name).trim() || SCREEN_BRAND_DEFAULTS.product_name;
  const screenTitle = String(settings.screen_title || SCREEN_BRAND_DEFAULTS.screen_title).trim() || SCREEN_BRAND_DEFAULTS.screen_title;
  const faviconUrl = screenSafeLocalAssetUrl(settings.favicon_url || SCREEN_BRAND_DEFAULTS.favicon_url);
  const logoUrl = screenSafeLocalAssetUrl(settings.logo_url || "");
  const logo = $("screen-logo");
  if (logo && logoUrl !== "") {
    logo.src = logoUrl;
  }

  document.title = screenTitle;
  const loginBrand = document.querySelector(".brand-kicker");
  if (loginBrand) {
    setText(loginBrand, productName);
  }
  const loginTitle = document.querySelector(".login-brand h1");
  if (loginTitle) {
    setText(loginTitle, screenTitle);
  }
  const screenKicker = document.querySelector(".screen-kicker");
  if (screenKicker) {
    setText(screenKicker, "实时 Token 监测");
  }
  const screenHeading = document.querySelector(".screen-title");
  if (screenHeading) {
    setText(screenHeading, screenTitle);
  }

  if (faviconUrl !== "") {
    let icon = document.querySelector('link[rel="icon"]');
    if (!icon) {
      icon = document.createElement("link");
      icon.rel = "icon";
      document.head.append(icon);
    }
    icon.href = faviconUrl;
  }
}

async function screenRefreshBrandIdentity() {
  try {
    screenApplyBrandIdentity(await api("/screen-api/system/brand-identity"));
  } catch {
    screenApplyBrandIdentity();
  }
}

function shareCodeFromPath() {
  const parts = window.location.pathname.split("/").filter(Boolean);
  if (parts[0] !== "screen" || !parts[1] || SCREEN_VIEW_KEYS.has(parts[1])) {
    return "";
  }
  return decodeURIComponent(parts[1]);
}

function screenViewFromPath() {
  const parts = window.location.pathname.split("/").filter(Boolean);
  if (parts[0] !== "screen") {
    return "overview";
  }
  const candidate = SCREEN_VIEW_KEYS.has(parts[1]) ? parts[1] : parts[2];
  return SCREEN_VIEW_KEYS.has(candidate) ? candidate : "overview";
}

function setScreenView(view) {
  const safeView = SCREEN_VIEW_KEYS.has(view) ? view : "overview";
  currentScreenView = safeView;
  document.querySelectorAll("[data-screen-view]").forEach((node) => {
    node.classList.toggle("active", node.dataset.screenView === safeView);
  });
}

function formatNumber(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number.toLocaleString("zh-CN") : "0";
}

function formatTokenMillions(value) {
  const number = numericValue(value) / 1000000;
  if (number >= 100) {
    return `${number.toFixed(0)}M`;
  }
  if (number >= 10) {
    return `${number.toFixed(1)}M`;
  }
  return `${number.toFixed(2)}M`;
}

function numericValue(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number : 0;
}

function tokenTotal(totals) {
  return numericValue(totals.total_tokens ?? (numericValue(totals.input_tokens) + numericValue(totals.output_tokens)));
}

function hasMetricData(totals) {
  return tokenTotal(totals || {}) > 0 || numericValue(totals?.request_count) > 0;
}

function overviewTotalsForDisplay(source) {
  const periods = source?.period_totals || {};
  const options = [
    ["today", "今日", source?.totals || periods.today || {}],
    ["week", "本周累计", periods.week || {}],
    ["month", "本月累计", periods.month || {}],
    ["quarter", "本季度累计", periods.quarter || {}],
    ["year", "本年度累计", periods.year || {}]
  ];
  return options.find(([, , totals]) => hasMetricData(totals)) || options[0];
}

function formatLatency(value) {
  return `${formatNumber(Math.round(Number(value || 0)))} ms`;
}

function formatPercent(value) {
  const number = Number(value || 0);
  return `${Number.isFinite(number) ? number.toFixed(number % 1 === 0 ? 0 : 1) : "0"}%`;
}

function formatTime(value) {
  if (!value) return "--";
  const text = String(value);
  return text.length > 16 ? text.slice(5, 16) : text;
}

function animateMetricValue(node, key, nextValue, formatter) {
  const end = numericValue(nextValue);
  const previous = metricValueState.has(key) ? metricValueState.get(key) : end;
  const start = numericValue(previous);
  const frame = metricAnimationFrames.get(key);
  if (frame) {
    window.cancelAnimationFrame(frame);
  }
  metricValueState.set(key, end);

  if (start === end || !Number.isFinite(start) || !Number.isFinite(end)) {
    setText(node, formatter(end));
    return;
  }

  const durationMs = 720;
  const startedAt = performance.now();
  const step = (now) => {
    const progress = Math.min(1, (now - startedAt) / durationMs);
    const eased = 1 - Math.pow(1 - progress, 3);
    const value = start + (end - start) * eased;
    setText(node, formatter(Math.round(value)));
    if (progress < 1) {
      metricAnimationFrames.set(key, window.requestAnimationFrame(step));
      return;
    }
    metricAnimationFrames.delete(key);
    setText(node, formatter(end));
  };
  metricAnimationFrames.set(key, window.requestAnimationFrame(step));
}

function metricTrendNode(key, nextValue, formatter) {
  const previous = metricValueState.get(key);
  const current = numericValue(nextValue);
  const diff = current - numericValue(previous);
  if (!metricValueState.has(key) || diff <= 0) {
    return createText("small", "metric-trend", "实时同步");
  }
  return createText("small", "metric-trend metric-trend-up", `↑ +${formatter(Math.round(diff))}`);
}

function setStatus(message, tone = "") {
  const status = $("screen-status");
  if (!status) return;
  status.className = tone ? `screen-status ${tone}` : "screen-status";
  setText(status, message);
}

function updateClock() {
  const clock = $("screen-clock");
  if (!clock) return;
  setText(clock, new Date().toLocaleTimeString("zh-CN", { hour12: false }));
}

function createText(tag, className, value) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  setText(node, value);
  return node;
}

function renderEmpty(host, message) {
  const empty = createText("div", "empty-state", message);
  host.replaceChildren(empty);
}

// Today's tokens as one large number, the breakdown beneath (design).
function renderMetricCards(today, periodLabel = "今日") {
  const totalTokens = tokenTotal(today);
  setText($("token-title"), periodLabel === "今日" ? "今日实时 Token 消耗" : `${periodLabel} Token 消耗`);
  const big = createText("strong", "metric-value token-hero", "");
  const hero = document.createElement("div");
  hero.className = "token-hero-row";
  const mark = document.createElement("span");
  mark.className = "token-hero-mark";
  mark.setAttribute("aria-hidden", "true");
  mark.append(document.createElement("span"), document.createElement("span"));
  hero.append(big, mark, createText("small", "metric-period", periodLabel));
  animateMetricValue(big, "total_tokens", totalTokens, formatNumber);
  const metrics = [
    { key: "input_tokens", label: "输入", rawValue: today.input_tokens, formatter: formatTokenMillions },
    { key: "output_tokens", label: "输出", rawValue: today.output_tokens, formatter: formatTokenMillions },
    { key: "cache_tokens", label: "缓存", rawValue: today.cache_tokens, formatter: formatTokenMillions },
    { key: "request_count", label: "调用次数", rawValue: today.request_count, formatter: formatNumber }
  ];
  const row = document.createElement("div");
  row.className = "token-breakdown";
  row.append(...metrics.map((metric) => {
    const card = document.createElement("article");
    const value = createText("strong", "", "");
    card.className = "metric-card";
    card.append(createText("span", "metric-label", metric.label), value, metricTrendNode(metric.key, metric.rawValue, metric.formatter));
    animateMetricValue(value, metric.key, metric.rawValue, metric.formatter);
    return card;
  }));
  $("token-summary").replaceChildren(hero, row);
}

function renderTokenDetails(rows) {
  const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
  const host = $("token-details");
  if (data.length === 0) {
    renderEmpty(host, "暂无模型 Token 数据");
    return;
  }
  host.replaceChildren(...data.map((model) => {
    const tokens = numericValue(model.total_tokens);
    const requests = numericValue(model.request_count);
    const item = document.createElement("div");
    item.className = "token-detail-row";
    item.append(
      createText("span", "", model.model_name || "未知模型"),
      createText("strong", "", `${formatTokenMillions(tokens)} / ${formatNumber(requests)} 次`)
    );
    return item;
  }));
}

const SHARE_COLORS = ["#34d399", "#2a7d63", "#6aa8ff", "#fbbf24", "#8e9a95", "#fb923c"];

// Donut drawn with SVG strokes; the centre shows how many models are in use.
function renderModelShare(rows) {
  const host = $("model-share-chart");
  const data = Array.isArray(rows) ? rows.filter((row) => Number(row.total_tokens || 0) > 0).slice(0, 6) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无模型占比数据");
    return;
  }
  const ns = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(ns, "svg");
  svg.setAttribute("viewBox", "0 0 180 180");
  svg.setAttribute("class", "model-chart-ring");
  svg.setAttribute("role", "img");
  svg.setAttribute("aria-label", `模型 Token 占比：${data.map((row) => `${row.model_name || "未知模型"} ${formatPercent(row.percent)}`).join("，")}`);
  const radius = 70;
  const circumference = 2 * Math.PI * radius;
  const track = document.createElementNS(ns, "circle");
  track.setAttribute("cx", "90");
  track.setAttribute("cy", "90");
  track.setAttribute("r", String(radius));
  track.setAttribute("class", "ring-track");
  svg.append(track);
  let offset = 0;
  data.forEach((row, index) => {
    const share = Math.max(0, Number(row.percent || 0)) / 100;
    const arc = document.createElementNS(ns, "circle");
    arc.setAttribute("cx", "90");
    arc.setAttribute("cy", "90");
    arc.setAttribute("r", String(radius));
    arc.setAttribute("fill", "none");
    arc.setAttribute("stroke", SHARE_COLORS[index % SHARE_COLORS.length]);
    arc.setAttribute("stroke-width", "22");
    arc.setAttribute("stroke-dasharray", `${Math.max(0, share * circumference - 2)} ${circumference}`);
    arc.setAttribute("stroke-dashoffset", String(-offset * circumference));
    arc.setAttribute("transform", "rotate(-90 90 90)");
    svg.append(arc);
    offset += share;
  });
  const count = document.createElementNS(ns, "text");
  count.setAttribute("x", "90");
  count.setAttribute("y", "88");
  count.setAttribute("class", "ring-value");
  count.textContent = String(data.length);
  const caption = document.createElementNS(ns, "text");
  caption.setAttribute("x", "90");
  caption.setAttribute("y", "110");
  caption.setAttribute("class", "ring-caption");
  caption.textContent = "在用模型";
  svg.append(count, caption);

  const legend = document.createElement("div");
  legend.className = "model-legend";
  legend.append(...data.map((row, index) => {
    const item = document.createElement("div");
    item.className = "model-legend-row";
    const swatch = document.createElement("span");
    swatch.className = "model-swatch";
    swatch.style.background = SHARE_COLORS[index % SHARE_COLORS.length];
    item.append(swatch, createText("span", "model-legend-name", row.model_name || `模型 ${index + 1}`), createText("strong", "", formatPercent(row.percent)));
    return item;
  }));
  host.replaceChildren(svg, legend);
}

function renderModelUsage(rows) {
  const host = $("model-usage");
  const data = Array.isArray(rows) ? rows.slice(0, 7) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无模型使用数据");
    return;
  }
  const max = Math.max(1, ...data.map((row) => Number(row.total_tokens || 0)));
  host.replaceChildren(...data.map((row, index) => {
    const item = document.createElement("div");
    item.className = `model-row ${index === 0 ? "is-top" : ""}`.trim();
    item.title = `${formatNumber(row.request_count)} 次 · 输入 ${formatNumber(row.input_tokens)} / 输出 ${formatNumber(row.output_tokens)}`;
    const header = document.createElement("header");
    header.append(createText("span", "model-name", row.model_name || "未知模型"), createText("strong", "", formatTokenMillions(row.total_tokens)));
    const progress = document.createElement("div");
    progress.className = "model-progress";
    const fill = document.createElement("span");
    fill.style.width = `${Math.max(2, Math.round(Number(row.total_tokens || 0) / max * 100))}%`;
    progress.append(fill);
    item.append(header, progress);
    return item;
  }));
}

function renderPeriodTotals(periods) {
  const labels = [
    ["week", "本周"],
    ["month", "本月"],
    ["year", "本年度"]
  ];
  $("period-totals").replaceChildren(...labels.map(([key, label]) => {
    const row = periods?.[key] || {};
    const card = document.createElement("span");
    card.className = "period-card";
    card.append(createText("span", "", label), createText("strong", "", formatTokenMillions(row.total_tokens)));
    return card;
  }));
}

function renderLiveGenerations(rows) {
  const host = $("live-generations");
  const data = Array.isArray(rows) ? rows.slice(0, 5) : [];
  setText($("live-count"), Array.isArray(rows) && rows.length ? `${rows.length} 路` : "");
  if (data.length === 0) {
    renderEmpty(host, "暂无正在生成内容");
    return;
  }
  const statusLabels = {
    queued: "排队中",
    processing: "生成中",
    succeeded: "已生成",
    failed: "失败",
    blocked: "已阻断",
    timeout: "超时"
  };
  host.replaceChildren(...data.map((row) => {
    const item = document.createElement("div");
    item.className = `live-row is-${row.status || "processing"}`;
    const who = row.department_name || row.user_name || (row.department_id ? `部门 ${row.department_id}` : "生成任务");
    const main = document.createElement("div");
    main.className = "live-main";
    main.append(createText("strong", "", who), createText("span", "live-meta", row.model_name || row.task_type || "--"));
    const side = document.createElement("div");
    side.className = "live-side";
    side.append(
      createText("span", "live-tokens", row.total_tokens ? formatNumber(row.total_tokens) : row.progress ? `${Math.round(Number(row.progress))}%` : formatTime(row.created_at)),
      createText("span", "live-status", statusLabels[row.status] || "生成中")
    );
    item.append(main, side);
    return item;
  }));
}

function renderOverview(overview) {
  const source = overview?.data || overview || {};
  const [, periodLabel, totals] = overviewTotalsForDisplay(source);
  renderMetricCards(totals || {}, periodLabel);
  renderTokenDetails(source.model_usage || []);
  renderModelShare(source.model_token_share || []);
  renderModelUsage(source.model_usage || []);
  renderPeriodTotals(source.period_totals || {});
  renderLiveGenerations(source.live_generations || []);
}

function bucketDate(value) {
  const text = String(value || "");
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(text) ? `${text.replace(" ", "T")}Z` : text;
  const date = new Date(iso);
  return Number.isFinite(date.getTime()) ? date : null;
}

// Hourly calls today against yesterday (local time), as two lines.
function renderOverviewTrend(rows) {
  const host = $("overview-trend");
  if (!host) return;
  const today = new Array(24).fill(null);
  const yesterday = new Array(24).fill(0);
  const now = new Date();
  const startToday = new Date(now);
  startToday.setHours(0, 0, 0, 0);
  const startYesterday = new Date(startToday.getTime() - 86400000);
  let any = false;
  for (const row of Array.isArray(rows) ? rows : []) {
    const date = bucketDate(row.bucket_at);
    if (!date) continue;
    const hour = date.getHours();
    const value = numericValue(row.request_count);
    if (date >= startToday) {
      today[hour] = (today[hour] || 0) + value;
      any = true;
    } else if (date >= startYesterday) {
      yesterday[hour] += value;
      any = true;
    }
  }
  if (!any) {
    renderEmpty(host, "暂无趋势数据");
    return;
  }
  for (let hour = 0; hour <= now.getHours(); hour += 1) {
    if (today[hour] === null) today[hour] = 0;
  }
  const max = Math.max(1, ...yesterday, ...today.filter((value) => value !== null));
  const ns = "http://www.w3.org/2000/svg";
  const width = 1000;
  const height = 300;
  const x = (hour) => hour / 23 * width;
  const y = (value) => height - value / max * (height - 12);
  const svg = document.createElementNS(ns, "svg");
  svg.setAttribute("viewBox", `0 0 ${width} ${height}`);
  svg.setAttribute("preserveAspectRatio", "none");
  svg.setAttribute("class", "trend-svg");
  svg.setAttribute("role", "img");
  const todayTotal = today.reduce((sum, value) => sum + (value || 0), 0);
  const yesterdaySame = yesterday.slice(0, now.getHours() + 1).reduce((sum, value) => sum + value, 0);
  svg.setAttribute("aria-label", `今日累计调用 ${formatNumber(todayTotal)} 次，昨日同时段 ${formatNumber(yesterdaySame)} 次`);
  for (let step = 0; step <= 4; step += 1) {
    const line = document.createElementNS(ns, "path");
    line.setAttribute("d", `M0 ${(step / 4) * height}H${width}`);
    line.setAttribute("class", "trend-grid");
    svg.append(line);
  }
  const path = (values) => values.map((value, hour) => (value === null ? null : `${x(hour)},${y(value)}`)).filter(Boolean);
  const past = document.createElementNS(ns, "polyline");
  past.setAttribute("points", path(yesterday).join(" "));
  past.setAttribute("class", "trend-line is-yesterday");
  svg.append(past);
  const points = path(today);
  if (points.length > 1) {
    const area = document.createElementNS(ns, "path");
    const lastHour = today.reduce((last, value, hour) => (value === null ? last : hour), 0);
    area.setAttribute("d", `M0,${height} L${points.join(" L")} L${x(lastHour)},${height} Z`);
    area.setAttribute("class", "trend-area");
    svg.append(area);
  }
  const line = document.createElementNS(ns, "polyline");
  line.setAttribute("points", points.join(" "));
  line.setAttribute("class", "trend-line is-today");
  svg.append(line);
  const axis = document.createElement("div");
  axis.className = "trend-axis";
  axis.append(...["00:00", "06:00", "12:00", "18:00", "24:00"].map((label) => createText("span", "", label)));
  host.replaceChildren(svg, axis);
}

function rankingName(row, index) {
  return row.name || row.department_name || row.model_name || row.model || row.provider || row.api_key_name || (row.id ? `#${row.id}` : `第 ${index + 1} 名`);
}

function renderOverviewRankings(rows) {
  const host = $("overview-rankings");
  if (!host) return;
  const data = Array.isArray(rows) ? rows.slice(0, 6) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无排行数据");
    return;
  }
  host.replaceChildren(...data.map((row, index) => {
    const item = document.createElement("div");
    item.className = "rank-row";
    item.append(
      createText("span", "rank-index", String(index + 1).padStart(2, "0")),
      createText("span", "rank-name", rankingName(row, index)),
      createText("span", "rank-value", formatNumber(row.request_count))
    );
    return item;
  }));
}

function renderOverviewPerformance(data, totals) {
  const host = $("overview-performance");
  if (!host) return;
  const source = data?.data || data || {};
  const buckets = Array.isArray(source.buckets) ? [...source.buckets].sort((a, b) => String(a.bucket_at).localeCompare(String(b.bucket_at))) : [];
  const latest = buckets[buckets.length - 1];
  const requests = numericValue(totals?.request_count);
  const success = totals?.success_count != null ? numericValue(totals.success_count) : null;
  host.replaceChildren(
    createText("strong", "", latest ? formatSeconds(latest.p95_latency_ms) : "--"),
    createText("small", "mini-good", success !== null && requests > 0 ? `成功率 ${(success / requests * 100).toFixed(2)}%` : "最近时段")
  );
}

function formatSeconds(ms) {
  const value = numericValue(ms);
  return value >= 1000 ? `${(value / 1000).toFixed(2)} s` : `${Math.round(value)} ms`;
}

function renderOverviewSecurity(data) {
  const host = $("overview-security");
  if (!host) return;
  const events = securityEvents(data);
  const total = events.reduce((sum, event) => sum + numericValue(event.event_count || event.count), 0);
  const blocked = events.filter((event) => String(event.action || "") === "block").reduce((sum, event) => sum + numericValue(event.event_count || event.count), 0);
  host.replaceChildren(
    createText("strong", "", formatNumber(total)),
    createText("small", blocked > 0 ? "mini-warn" : "", blocked > 0 ? `其中拦截 ${formatNumber(blocked)}` : "按策略处理")
  );
}

function renderTrendBars(rows) {
  const host = $("trends");
  // The API returns newest first; read oldest to newest, last 12 buckets.
  const data = Array.isArray(rows) ? [...rows].sort((a, b) => String(a.bucket_at).localeCompare(String(b.bucket_at))).slice(-12) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无趋势数据");
    return;
  }
  const max = Math.max(1, ...data.map((row) => Number(row.request_count || 0)));
  host.replaceChildren(...data.map((row) => {
    const item = document.createElement("div");
    item.className = "trend-row";
    const label = createText("span", "trend-label", formatTime(row.bucket_at));
    const track = document.createElement("span");
    track.className = "trend-track";
    const fill = document.createElement("span");
    fill.className = "trend-fill";
    fill.style.width = `${Math.max(4, Math.round(Number(row.request_count || 0) / max * 100))}%`;
    track.append(fill);
    const value = createText("strong", "trend-value", formatNumber(row.request_count));
    const meta = createText("small", "trend-meta", `成功 ${formatNumber(row.success_count)} / 失败 ${formatNumber(row.failed_count)}`);
    item.append(label, track, value, meta);
    return item;
  }));
}

function renderRankingList(rows) {
  const host = $("rankings");
  if (!host) return;
  const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无排行数据");
    return;
  }
  host.replaceChildren(...data.map((row, index) => {
    const item = document.createElement("div");
    item.className = "rank-row";
    const rank = createText("span", "rank-index", String(index + 1).padStart(2, "0"));
    const name = createText("strong", "rank-name", rankingName(row, index));
    const meta = createText("small", "rank-meta", `${formatNumber(row.total_tokens)} Token · ${formatNumber(row.error_count)} 错误`);
    const value = createText("span", "rank-value", formatNumber(row.request_count));
    item.append(rank, name, meta, value);
    return item;
  }));
}

const SECURITY_ACTION_LABELS = { block: "拦截", redact: "脱敏", replace: "替换", summary: "摘要", log: "记录", allow: "放行", remove_header: "移除请求头" };

function securityEvents(data) {
  const source = data?.data || data || {};
  const desensitization = Array.isArray(source.desensitization) ? source.desensitization.map((item) => ({ ...item, type: "脱敏" })) : [];
  return desensitization.slice(0, 8);
}

function renderSecurityPanel(data) {
  const host = $("security");
  const events = securityEvents(data);
  if (events.length === 0) {
    renderEmpty(host, "暂无安全事件");
    return;
  }
  host.replaceChildren(...events.map((event) => {
    const item = document.createElement("div");
    item.className = "security-row";
    const type = createText("span", "security-type", event.type);
    const title = createText("strong", "security-title", SECURITY_ACTION_LABELS[event.action] || event.rule_name || event.policy_name || event.action || "安全事件");
    const meta = createText("small", "security-meta", event.match_count ? `命中 ${formatNumber(event.match_count)} 处` : "按脱敏策略处理");
    const count = createText("span", "security-count", formatNumber(event.event_count || event.match_count || event.count));
    item.append(type, title, meta, count);
    return item;
  }));
}

function renderPerformancePanel(data) {
  const host = $("performance");
  const source = data?.data || data || {};
  const buckets = Array.isArray(source.buckets) ? source.buckets.slice(-6) : [];
  if (buckets.length === 0) {
    renderEmpty(host, "暂无性能数据");
    return;
  }
  host.replaceChildren(...buckets.map((row) => {
    const card = document.createElement("article");
    card.className = "performance-card";
    card.append(
      createText("span", "performance-time", formatTime(row.bucket_at)),
      createText("strong", "performance-main", formatLatency(row.avg_latency_ms)),
      createText("small", "performance-meta", `P95 ${formatLatency(row.p95_latency_ms)} · P99 ${formatLatency(row.p99_latency_ms)}`),
      createText("small", "performance-meta", `${formatNumber(row.request_count)} 请求`)
    );
    return card;
  }));
}

async function render() {
  setStatus("刷新中", "busy");
  const [overview, trends, rankings, security, performance] = await Promise.all([
    api("/screen-api/dashboard/overview"),
    api("/screen-api/dashboard/trends"),
    api("/screen-api/dashboard/rankings"),
    api("/screen-api/dashboard/security"),
    api("/screen-api/dashboard/performance")
  ]);
  renderOverview(overview);
  const trendRows = trends.data || trends || [];
  const rankingRows = rankings.data || rankings || [];
  renderTrendBars(trendRows);
  renderOverviewTrend(trendRows);
  renderRankingList(rankingRows);
  renderOverviewRankings(rankingRows);
  renderSecurityPanel(security);
  renderOverviewSecurity(security);
  renderPerformancePanel(performance);
  renderOverviewPerformance(performance, (overview?.data || overview || {}).totals);
  setStatus("已更新", "ready");
}

async function showScreen() {
  $("login").hidden = true;
  $("screen").hidden = false;
  setScreenView(screenViewFromPath());
  updateClock();
  if (clockTimer === null) {
    clockTimer = setInterval(updateClock, 1000);
  }
  await refreshScreen();
  if (refreshTimer === null) {
    refreshTimer = setInterval(refreshScreen, SCREEN_REFRESH_INTERVAL_MS);
  }
}

function updateFullscreenButton() {
  const button = $("fullscreen-toggle");
  if (!button) return;
  const active = Boolean(document.fullscreenElement);
  document.body.classList.toggle("fullscreen-active", active);
  setText(button, active ? "退出全屏" : "全屏");
}

async function toggleFullscreen() {
  try {
    if (document.fullscreenElement) {
      await document.exitFullscreen();
    } else {
      await document.documentElement.requestFullscreen();
    }
    updateFullscreenButton();
  } catch {
    setStatus("全屏切换失败", "error");
  }
}

function showLogin(message = "") {
  if (refreshTimer !== null) {
    clearInterval(refreshTimer);
    refreshTimer = null;
  }
  $("screen").hidden = true;
  $("login").hidden = false;
  $("credential-fields").hidden = shareCodeFromPath() !== "";
  setText($("entry-status"), message ? "无法进入大屏" : "正在进入大屏");
  setText($("error"), message);
}

function showCredentialLogin(message = "") {
  showLogin(message);
  $("credential-fields").hidden = false;
  setText($("entry-status"), "请输入用户名和密码");
}

async function enterByShareCode() {
  const shareCode = shareCodeFromPath();
  if (!shareCode) {
    showCredentialLogin("");
    return;
  }
  showLogin("");
  await api("/screen-api/auth/share-login", {
    method: "POST",
    body: JSON.stringify({ share_code: shareCode })
  });
  await showScreen();
}

async function enterByCredentials() {
  await api("/screen-api/auth/login", {
    method: "POST",
    body: JSON.stringify({
      username: $("username").value,
      password: $("password").value
    })
  });
  await showScreen();
}

async function refreshScreen() {
  try {
    await render();
  } catch (error) {
    if (error.status === 401 || error.status === 403 || ["SCREEN_LINK_DISABLED", "SCREEN_IP_FORBIDDEN", "SCREEN_UNAUTHENTICATED"].includes(error.code)) {
      showLogin(screenErrorMessage(error, "大屏会话已失效，请重新登录"));
      return;
    }
    setStatus("刷新失败", "error");
    throw error;
  }
}

$("login-button").addEventListener("click", async () => {
  try {
    if (shareCodeFromPath()) {
      await enterByShareCode();
    } else {
      await enterByCredentials();
    }
  } catch (error) {
    if (shareCodeFromPath()) {
      showLogin(screenErrorMessage(error));
    } else {
      showCredentialLogin(screenErrorMessage(error, "用户名或密码错误"));
    }
  }
});

$("logout").addEventListener("click", async () => {
  await api("/screen-api/auth/logout", { method: "POST" }).catch(() => null);
  showLogin("");
});

$("fullscreen-toggle").addEventListener("click", toggleFullscreen);
document.addEventListener("fullscreenchange", updateFullscreenButton);

screenApplyBrandIdentity();
screenRefreshBrandIdentity();
setScreenView(screenViewFromPath());
updateClock();
updateFullscreenButton();
api("/screen-api/auth/me").then(showScreen).catch(() => {
  if (!shareCodeFromPath()) {
    showCredentialLogin("");
    return;
  }
  enterByShareCode().catch((error) => {
    showLogin(screenErrorMessage(error));
  });
});
