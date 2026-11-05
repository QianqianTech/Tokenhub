import { createPoller } from "./poller.js";

const SCREEN_REQUEST_TIMEOUT_MS = 8000;
const inflightControllers = new Set();

// Every request has a deadline (AbortController) so a slow backend cannot pile up
// requests from a screen that keeps polling.
async function api(path, options = {}) {
  const { timeoutMs = SCREEN_REQUEST_TIMEOUT_MS, ...fetchOptions } = options;
  const controller = new AbortController();
  inflightControllers.add(controller);
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, timeoutMs);
  try {
    let response;
    try {
      response = await fetch(path, {
        credentials: "same-origin",
        ...fetchOptions,
        signal: controller.signal,
        headers: {
          "Accept": "application/json",
          ...(fetchOptions.body ? { "Content-Type": "application/json" } : {}),
          ...(fetchOptions.headers || {})
        }
      });
    } catch {
      const error = new Error(timedOut ? "大屏服务响应超时，请稍后重试" : "无法连接大屏服务，请检查网络后重试");
      error.code = timedOut ? "SCREEN_TIMEOUT" : (controller.signal.aborted ? "SCREEN_ABORTED" : "SCREEN_NETWORK_ERROR");
      error.status = 0;
      throw error;
    }
    const payload = await response.json().catch(() => ({ ok: false, error: { code: timedOut ? "SCREEN_TIMEOUT" : "BAD_RESPONSE", message: "服务返回格式异常" } }));
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error?.message || "大屏请求失败");
      error.code = payload.error?.code || payload.code || (response.ok ? "BAD_RESPONSE" : "SCREEN_REQUEST_FAILED");
      error.status = response.status;
      error.retryAfter = Number(response.headers.get("Retry-After") || 0);
      throw error;
    }
    return payload.data;
  } finally {
    clearTimeout(timer);
    inflightControllers.delete(controller);
  }
}

function abortInflightRequests() {
  for (const controller of [...inflightControllers]) {
    controller.abort();
  }
  inflightControllers.clear();
}

function screenErrorMessage(error, fallback = "大屏请求失败，请稍后重试") {
  const messages = {
    BAD_RESPONSE: "服务返回格式异常",
    SCREEN_NETWORK_ERROR: "无法连接大屏服务，请检查网络后重试",
    SCREEN_TIMEOUT: "大屏服务响应超时，请稍后重试",
    RATE_LIMITED: "尝试过于频繁，请稍后再试",
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
const setText = (node, value) => { if (node) node.textContent = value == null ? "" : String(value); };
const SVG_NS = "http://www.w3.org/2000/svg";
const SCREEN_BRAND_DEFAULTS = {
  product_name: "TokenHub",
  screen_title: "TokenHub 运营大屏",
  logo_url: "",
  favicon_url: "/screen/assets/brand/favicon.ico"
};
const SCREEN_REFRESH_INTERVAL_MS = 10000;
// Data older than this (three missed refreshes plus slack) is flagged on screen.
const SCREEN_STALE_AFTER_MS = 35000;
const SCREEN_IDLE_AFTER_MS = 4000;
// Design canvas: every size in screen.css is drawn for 1920×1080.
const SCREEN_DESIGN_WIDTH = 1920;
const SCREEN_DESIGN_HEIGHT = 1080;
const SCREEN_VIEW_ORDER = ["overview", "operations"];
const SCREEN_VIEW_KEYS = new Set(SCREEN_VIEW_ORDER);
let poller = null;
let clockTimer = null;
let refreshing = null;
let reloginTried = false;
let lastSuccessAt = 0;
let lastRendered = null;
let idleTimer = 0;
// Business time zone reported by the backend (BUSINESS_TIMEZONE): "today", the trend
// day boundaries, the clock and "updated at" all use it, not the browser's zone.
let businessTimeZone = "";
const zonedFormatters = new Map();
let currentScreenView = "overview";
const metricValueState = new Map();
const metricAnimationFrames = new Map();

/* ------------------------------------------------------------------ brand */

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
  if (logoUrl !== "") {
    for (const logo of [$("screen-logo"), document.querySelector(".login-logo")]) {
      if (logo) logo.src = logoUrl;
    }
  }

  document.title = screenTitle;
  setText(document.querySelector(".brand-kicker"), productName);
  setText(document.querySelector(".login-brand h1"), screenTitle);
  const screenHeading = document.querySelector(".screen-title");
  if (screenHeading) {
    setText(screenHeading, screenTitle);
    screenHeading.title = screenTitle;
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

const SCOPE_LABELS = { global: "全局", department: "部门范围", user: "用户范围", api_key: "密钥范围" };

// Scoped links see part of the traffic; say so next to the title.
function renderScope(session) {
  const node = $("screen-scope");
  if (!node) return;
  const share = session?.share || session?.screen || session || {};
  const label = SCOPE_LABELS[String(share.scope_type || "")];
  node.hidden = !label;
  setText(node, label || "");
}

/* ----------------------------------------------------------- routing */

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

/* ------------------------------------------------- scaling and kiosk */

// One layout for every landscape display: the 1920×1080 design is zoomed to the
// window (4K → 2×, 2560×1440 → 1.33×, 1366×768 → 0.71×). Portrait or small windows
// keep a normal scrolling layout.
function applyScreenScale() {
  const width = window.innerWidth;
  const height = window.innerHeight;
  const fit = width >= 1200 && height >= 640 && width > height;
  const zoomable = typeof CSS !== "undefined" && typeof CSS.supports === "function" && CSS.supports("zoom", "2");
  const scale = fit && zoomable ? Math.min(4, Math.max(0.62, Math.min(width / SCREEN_DESIGN_WIDTH, height / SCREEN_DESIGN_HEIGHT))) : 1;
  const root = document.documentElement;
  root.classList.toggle("screen-fit", fit);
  root.style.setProperty("--screen-scale", scale.toFixed(4));
}

function markActive() {
  document.body.classList.remove("screen-idle");
  window.clearTimeout(idleTimer);
  idleTimer = window.setTimeout(() => {
    if (!$("screen").hidden && document.documentElement.classList.contains("screen-fit")) {
      document.body.classList.add("screen-idle");
    }
  }, SCREEN_IDLE_AFTER_MS);
}

let resizeTimer = 0;
function onResize() {
  applyScreenScale();
  window.clearTimeout(resizeTimer);
  // Lists are trimmed to the rows that fit, so a new size needs a fresh layout.
  resizeTimer = window.setTimeout(() => {
    if (lastRendered && !$("screen").hidden) renderAll(lastRendered);
  }, 150);
}

/* -------------------------------------------------------- formatting */

function numericValue(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number : 0;
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

// Large token totals stay short: 1.49B, 386M, 12.7M.
function formatTokenCompact(value) {
  const number = numericValue(value);
  if (number >= 1e9) {
    return `${(number / 1e9).toFixed(number >= 1e11 ? 0 : 2)}B`;
  }
  return formatTokenMillions(number);
}

// Axis ticks: 12k, 3.5k, 800.
function formatAxis(value) {
  const number = numericValue(value);
  if (number >= 1e9) return `${+(number / 1e9).toFixed(1)}B`;
  if (number >= 1e6) return `${+(number / 1e6).toFixed(1)}M`;
  if (number >= 1e4) return `${+(number / 1e3).toFixed(0)}k`;
  if (number >= 1e3) return `${+(number / 1e3).toFixed(1)}k`;
  return String(Math.round(number));
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

function formatSeconds(ms) {
  const value = numericValue(ms);
  return value >= 1000 ? `${(value / 1000).toFixed(2)} s` : `${Math.round(value)} ms`;
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

function percentChange(current, previous) {
  if (!(previous > 0)) return null;
  return (current - previous) / previous * 100;
}

function deltaNode(change, label) {
  if (change === null || !Number.isFinite(change)) {
    return null;
  }
  const up = change >= 0.05;
  const down = change <= -0.05;
  const arrow = up ? "↑" : down ? "↓" : "→";
  return createText("span", `delta${up ? " is-up" : down ? " is-down" : ""}`, `${label} ${arrow} ${Math.abs(change).toFixed(1)}%`);
}

/* --------------------------------------------------------- time zone */

function setBusinessTimeZone(zone) {
  const name = String(zone || "").trim();
  if (!name || name === businessTimeZone) return;
  try {
    new Intl.DateTimeFormat("en-US", { timeZone: name });
    businessTimeZone = name;
  } catch {
    businessTimeZone = "";
  }
}

function zonedFormatter(kind = "parts") {
  const key = `${kind}:${businessTimeZone || "local"}`;
  if (!zonedFormatters.has(key)) {
    const zone = businessTimeZone ? { timeZone: businessTimeZone } : {};
    zonedFormatters.set(key, kind === "weekday"
      ? new Intl.DateTimeFormat("zh-CN", { ...zone, weekday: "long" })
      : new Intl.DateTimeFormat("en-CA", { ...zone, year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23" }));
  }
  return zonedFormatters.get(key);
}

// Calendar parts of a moment in the business time zone.
function zonedParts(date) {
  const parts = {};
  for (const part of zonedFormatter().formatToParts(date)) {
    parts[part.type] = part.value;
  }
  const hour = Number(parts.hour) % 24;
  return {
    day: `${parts.year}-${parts.month}-${parts.day}`,
    month: Number(parts.month),
    date: Number(parts.day),
    year: parts.year,
    hour,
    minute: Number(parts.minute),
    label: `${parts.month}-${parts.day} ${String(hour).padStart(2, "0")}:${parts.minute}`,
    hourLabel: `${String(hour).padStart(2, "0")}:00`,
    time: `${String(hour).padStart(2, "0")}:${parts.minute}:${parts.second}`
  };
}

function bucketDate(value) {
  const text = String(value || "");
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(text) ? `${text.replace(" ", "T")}Z` : text;
  const date = new Date(iso);
  return Number.isFinite(date.getTime()) ? date : null;
}

// Aggregate buckets are UTC ("Y-m-d H:i:s"); show them in the business time zone.
function formatBucketTime(value) {
  const date = bucketDate(value);
  return date ? zonedParts(date).label : formatTime(value);
}

function formatRelative(value) {
  const date = bucketDate(value);
  if (!date) return "--";
  const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
  if (seconds < 60) return `${seconds} 秒前`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)} 分钟前`;
  return formatBucketTime(value);
}

/* ------------------------------------------------------------ status */

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

// "↑ +1.2M" since the previous refresh; nothing when the value did not grow.
function metricTrendNode(key, nextValue, formatter) {
  const previous = metricValueState.get(key);
  const current = numericValue(nextValue);
  const diff = current - numericValue(previous);
  if (!metricValueState.has(key) || diff <= 0) {
    return null;
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
  const now = new Date();
  const parts = zonedParts(now);
  setText($("screen-clock"), parts.time);
  setText($("screen-date"), `${parts.year}年${parts.month}月${parts.date}日 ${zonedFormatter("weekday").format(now)}`);
  renderStale();
}

function renderStale() {
  const node = $("screen-stale");
  if (!node) return;
  const age = lastSuccessAt ? Date.now() - lastSuccessAt : 0;
  const stale = !$("screen").hidden && lastSuccessAt > 0 && age > SCREEN_STALE_AFTER_MS;
  node.hidden = !stale;
  if (stale) {
    const minutes = Math.floor(age / 60000);
    setText(node, `数据已 ${minutes >= 1 ? `${minutes} 分钟` : `${Math.floor(age / 1000)} 秒`}未更新，画面为最后一次成功获取的数据，正在自动重试`);
  }
}

function renderUpdatedAt(value) {
  const node = $("screen-updated");
  if (!node) return;
  const date = value ? new Date(value) : null;
  setText(node, date && Number.isFinite(date.getTime()) ? `数据更新于 ${zonedParts(date).time}` : "");
}

const HEALTH_REASON_LABELS = {
  worker_heartbeat_missing: "队列 worker 无心跳",
  queue_backlog: "队列积压",
  circuit_open: "有渠道熔断",
  error_rate_high: "错误率偏高"
};

function renderHealth(live) {
  const node = $("screen-health");
  if (!node) return;
  const level = live?.health?.level || "unknown";
  const reasons = Array.isArray(live?.health?.reasons) ? live.health.reasons : [];
  node.className = `screen-health is-${level}`;
  const labels = { ok: "运行正常", warn: "需要关注", critical: "服务异常", unknown: "状态未知" };
  const detail = reasons.map((reason) => HEALTH_REASON_LABELS[reason] || reason).join("、");
  const pending = live?.queue?.pending;
  node.title = [labels[level] || labels.unknown, detail, pending != null ? `队列待处理 ${formatNumber(pending)}` : ""].filter(Boolean).join(" · ");
  // The reason is visible on the wall, not only in a tooltip nobody can hover.
  setText(node, detail && level !== "ok" ? `${labels[level] || labels.unknown} · ${detail}` : (labels[level] || labels.unknown));
}

/* ------------------------------------------------------------ DOM kit */

function createText(tag, className, value) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  setText(node, value);
  return node;
}

function el(tag, className, children = []) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  node.append(...children.filter(Boolean));
  return node;
}

function svgEl(tag, attrs = {}) {
  const node = document.createElementNS(SVG_NS, tag);
  for (const [key, value] of Object.entries(attrs)) {
    node.setAttribute(key, String(value));
  }
  return node;
}

function bar(className, ratio, minPercent = 2) {
  const track = el("div", className);
  const fill = document.createElement("span");
  fill.style.width = `${Math.max(minPercent, Math.min(100, Math.round(ratio * 1000) / 10))}%`;
  track.append(fill);
  return track;
}

function renderEmpty(host, message) {
  const empty = createText("div", "empty-state", message);
  host.replaceChildren(empty);
}

// On the wall nothing scrolls: drop the list rows that do not fit their panel.
function fitRows(host, keep = 1) {
  if (!host || !document.documentElement.classList.contains("screen-fit")) return;
  const limit = host.getBoundingClientRect().bottom + 1;
  while (host.children.length > keep && host.lastElementChild.getBoundingClientRect().bottom > limit) {
    host.lastElementChild.remove();
  }
}

// Axis maximum = 4 × a round step (1, 1.5, 2, 2.5, 3, 4, 5, 6, 8 × 10ⁿ) so the four
// gridlines read cleanly; counts never get fractional steps.
function niceMax(value, { integer = true } = {}) {
  const rawStep = Math.max(1e-9, value) / 4;
  const magnitude = 10 ** Math.floor(Math.log10(rawStep));
  let step = 10 * magnitude;
  for (const factor of [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]) {
    if (factor * magnitude >= rawStep) {
      step = factor * magnitude;
      break;
    }
  }
  return (integer ? Math.max(1, step) : step) * 4;
}

// Monotone cubic path through points (no overshoot below zero or above peaks).
function smoothPath(points) {
  if (points.length < 2) return points.length ? `M${points[0][0]},${points[0][1]}` : "";
  const n = points.length;
  const dx = [];
  const slope = [];
  for (let i = 0; i < n - 1; i += 1) {
    dx[i] = points[i + 1][0] - points[i][0];
    slope[i] = dx[i] === 0 ? 0 : (points[i + 1][1] - points[i][1]) / dx[i];
  }
  const tangent = [slope[0]];
  for (let i = 1; i < n - 1; i += 1) {
    tangent[i] = slope[i - 1] * slope[i] <= 0 ? 0 : (slope[i - 1] + slope[i]) / 2;
  }
  tangent[n - 1] = slope[n - 2];
  for (let i = 0; i < n - 1; i += 1) {
    if (slope[i] === 0) {
      tangent[i] = 0;
      tangent[i + 1] = 0;
      continue;
    }
    const a = tangent[i] / slope[i];
    const b = tangent[i + 1] / slope[i];
    const h = a * a + b * b;
    if (h > 9) {
      const t = 3 / Math.sqrt(h);
      tangent[i] = t * a * slope[i];
      tangent[i + 1] = t * b * slope[i];
    }
  }
  let d = `M${points[0][0].toFixed(1)},${points[0][1].toFixed(1)}`;
  for (let i = 0; i < n - 1; i += 1) {
    const [x0, y0] = points[i];
    const [x1, y1] = points[i + 1];
    const h = dx[i] / 3;
    d += ` C${(x0 + h).toFixed(1)},${(y0 + tangent[i] * h).toFixed(1)} ${(x1 - h).toFixed(1)},${(y1 - tangent[i + 1] * h).toFixed(1)} ${x1.toFixed(1)},${y1.toFixed(1)}`;
  }
  return d;
}

/**
 * Chart frame shared by every chart: y-axis ticks on the left, a stretchable SVG plot
 * (viewBox 1000×300, preserveAspectRatio none, non-scaling strokes) and an HTML layer
 * for markers and labels so text and dots are never distorted.
 */
function chartFrame(max, tickFormatter, label) {
  const width = 1000;
  const height = 300;
  const ticks = [4, 3, 2, 1, 0].map((step) => createText("span", "", tickFormatter(max * step / 4)));
  const svg = svgEl("svg", { viewBox: `0 0 ${width} ${height}`, preserveAspectRatio: "none", class: "chart-svg trend-svg", role: "img", "aria-label": label });
  for (let step = 0; step <= 4; step += 1) {
    svg.append(svgEl("path", { d: `M0 ${(step / 4) * height}H${width}`, class: "trend-grid" }));
  }
  const plot = el("div", "chart-plot", [svg]);
  const xaxis = el("div", "chart-xaxis");
  const body = el("div", "chart-body", [el("div", "chart-y", ticks), plot, el("span", "chart-corner"), xaxis]);
  return { width, height, svg, plot, xaxis, body, y: (value) => height - Math.min(1, numericValue(value) / max) * height };
}

// X-axis labels placed at their data position; the outer two hug the edges.
function axisLabels(host, labels) {
  host.replaceChildren(...labels.map(([ratio, text]) => {
    const node = createText("span", ratio <= 0.02 ? "is-start" : ratio >= 0.98 ? "is-end" : "", text);
    node.style.left = `${(ratio * 100).toFixed(2)}%`;
    return node;
  }));
}

function chartMarker(plot, xRatio, yRatio, text) {
  const left = `${(xRatio * 100).toFixed(2)}%`;
  const top = `${(yRatio * 100).toFixed(2)}%`;
  const dot = el("span", "chart-marker");
  dot.style.left = left;
  dot.style.top = top;
  plot.append(dot);
  if (text) {
    const callout = createText("span", `chart-callout${xRatio > 0.85 ? " is-right" : xRatio < 0.12 ? " is-left" : ""}`, text);
    callout.style.left = left;
    callout.style.top = top;
    plot.append(callout);
  }
}

/* ======================================================== 大屏1 · 总览 */

const SHARE_COLORS = ["#34d399", "#6aa8ff", "#fbbf24", "#a78bfa", "#fb923c"];
const SHARE_OTHER_COLOR = "#4b5551";

// Today's tokens as one large number, the breakdown and comparisons beneath.
function renderMetricCards(today, periodLabel = "今日", compare = null) {
  const totalTokens = tokenTotal(today);
  setText($("token-title"), periodLabel === "今日" ? "今日实时 Token 消耗" : `${periodLabel} Token 消耗`);
  const big = createText("strong", "metric-value token-hero", "");
  const digits = formatNumber(totalTokens).length;
  if (digits >= 14) big.classList.add("is-xlong");
  else if (digits >= 12) big.classList.add("is-long");
  const mark = document.createElement("span");
  mark.className = "token-hero-mark";
  mark.setAttribute("aria-hidden", "true");
  mark.append(document.createElement("span"), document.createElement("span"));
  const side = el("div", "token-hero-side", [
    periodLabel === "今日" && compare ? deltaNode(compare.tokenChange, "较昨日同时段") : null,
    createText("small", "metric-period", periodLabel)
  ]);
  const hero = el("div", "token-hero-row", [big, mark, side]);
  animateMetricValue(big, "total_tokens", totalTokens, formatNumber);

  const input = numericValue(today.input_tokens);
  const output = numericValue(today.output_tokens);
  const io = input + output;
  const requests = numericValue(today.request_count);
  const success = today.success_count != null ? numericValue(today.success_count) : (today.failed_count != null ? requests - numericValue(today.failed_count) : null);
  const metrics = [
    { key: "input_tokens", tone: "is-input", label: "输入", rawValue: today.input_tokens, formatter: formatTokenMillions, hint: io > 0 ? `占 ${(input / io * 100).toFixed(1)}%` : "" },
    { key: "output_tokens", tone: "is-output", label: "输出", rawValue: today.output_tokens, formatter: formatTokenMillions, hint: io > 0 ? `占 ${(output / io * 100).toFixed(1)}%` : "" },
    { key: "cache_tokens", tone: "is-cache", label: "缓存", rawValue: today.cache_tokens, formatter: formatTokenMillions, hint: input > 0 ? `相当于输入的 ${(numericValue(today.cache_tokens) / input * 100).toFixed(1)}%` : "" },
    { key: "request_count", tone: "is-requests", label: "调用次数", rawValue: today.request_count, formatter: formatNumber, hint: success !== null && requests > 0 ? `成功率 ${(success / requests * 100).toFixed(2)}%` : "" }
  ];
  const row = document.createElement("div");
  row.className = "token-breakdown";
  row.append(...metrics.map((metric) => {
    const value = createText("strong", "", "");
    const foot = el("div", "metric-foot", [
      metric.hint ? createText("small", "metric-hint", metric.hint) : null,
      metricTrendNode(metric.key, metric.rawValue, metric.formatter)
    ]);
    const card = el("article", `metric-card ${metric.tone}`, [createText("span", "metric-label", metric.label), value, foot]);
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

// Donut of this month's tokens by model: the top five and the rest as "其他".
function renderModelShare(rows) {
  const host = $("model-share-chart");
  const all = Array.isArray(rows) ? rows.filter((row) => Number(row.total_tokens || 0) > 0) : [];
  if (all.length === 0) {
    renderEmpty(host, "暂无模型占比数据");
    return;
  }
  const totalTokens = all.reduce((sum, row) => sum + numericValue(row.total_tokens), 0);
  const share = (row) => (row.percent != null ? numericValue(row.percent) : (totalTokens > 0 ? numericValue(row.total_tokens) / totalTokens * 100 : 0));
  const top = all.slice(0, all.length > 6 ? 5 : 6).map((row, index) => ({ name: row.model_name || `模型 ${index + 1}`, tokens: numericValue(row.total_tokens), percent: share(row), color: SHARE_COLORS[index] || SHARE_OTHER_COLOR }));
  const rest = all.slice(top.length);
  if (rest.length > 0) {
    top.push({ name: `其他 ${rest.length} 个`, tokens: rest.reduce((sum, row) => sum + numericValue(row.total_tokens), 0), percent: rest.reduce((sum, row) => sum + share(row), 0), color: SHARE_OTHER_COLOR });
  }

  const svg = svgEl("svg", { viewBox: "0 0 180 180", class: "model-chart-ring", role: "img", "aria-label": `模型 Token 占比：${top.map((row) => `${row.name} ${formatPercent(row.percent)}`).join("，")}` });
  const radius = 70;
  const circumference = 2 * Math.PI * radius;
  svg.append(svgEl("circle", { cx: 90, cy: 90, r: radius, class: "ring-track" }));
  let offset = 0;
  for (const row of top) {
    const portion = Math.max(0, row.percent) / 100;
    svg.append(svgEl("circle", {
      cx: 90, cy: 90, r: radius, fill: "none", class: "ring-arc",
      stroke: row.color, "stroke-width": 20,
      "stroke-dasharray": `${Math.max(0, portion * circumference - 2.5)} ${circumference}`,
      "stroke-dashoffset": -offset * circumference,
      transform: "rotate(-90 90 90)"
    }));
    offset += portion;
  }
  const count = svgEl("text", { x: 90, y: 90, class: "ring-value" });
  count.textContent = String(all.length);
  const caption = svgEl("text", { x: 90, y: 112, class: "ring-caption" });
  caption.textContent = "在用模型";
  svg.append(count, caption);

  const legend = el("div", "model-legend", top.map((row) => {
    const swatch = document.createElement("span");
    swatch.className = "model-swatch";
    swatch.style.background = row.color;
    return el("div", "model-legend-row", [
      swatch,
      createText("span", "model-legend-name", row.name),
      createText("span", "model-legend-tokens", formatTokenCompact(row.tokens)),
      createText("strong", "", formatPercent(row.percent))
    ]);
  }));
  legend.title = `本月合计 ${formatNumber(totalTokens)} Token`;
  host.replaceChildren(svg, legend);
}

function renderModelUsage(rows) {
  const host = $("model-usage");
  const data = Array.isArray(rows) ? rows.slice(0, 10) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无模型使用数据");
    return;
  }
  const max = Math.max(1, ...data.map((row) => Number(row.total_tokens || 0)));
  host.replaceChildren(...data.map((row, index) => {
    const item = el("div", `model-row ${index === 0 ? "is-top" : ""}`.trim(), [
      el("header", "", [
        createText("span", "model-rank", String(index + 1).padStart(2, "0")),
        createText("span", "model-name", row.model_name || "未知模型"),
        createText("span", "model-requests", `${formatNumber(row.request_count)} 次`),
        createText("strong", "", formatTokenCompact(row.total_tokens))
      ]),
      bar("model-progress", numericValue(row.total_tokens) / max)
    ]);
    item.title = `${formatNumber(row.request_count)} 次 · 输入 ${formatNumber(row.input_tokens)} / 输出 ${formatNumber(row.output_tokens)}`;
    return item;
  }));
}

function renderPeriodTotals(periods) {
  const labels = [
    ["week", "本周"],
    ["month", "本月"],
    ["quarter", "本季"],
    ["year", "本年"]
  ];
  $("period-totals").replaceChildren(...labels.map(([key, label]) => {
    const row = periods?.[key] || {};
    const card = document.createElement("span");
    card.className = "period-card";
    card.title = `${label} ${formatNumber(tokenTotal(row))} Token`;
    card.append(createText("span", "", label), createText("strong", "", formatTokenCompact(tokenTotal(row))));
    return card;
  }));
}

const TASK_TYPES = {
  image: ["图像", "is-image", "图像生成"],
  video: ["视频", "is-video", "视频生成"],
  speech: ["语音", "is-audio", "语音合成"],
  audio: ["语音", "is-audio", "音频生成"],
  tts: ["语音", "is-audio", "语音合成"],
  asr: ["转写", "is-audio", "语音识别"],
  music: ["音乐", "is-audio", "音乐生成"]
};

function taskType(type) {
  const key = Object.keys(TASK_TYPES).find((name) => String(type || "").toLowerCase().includes(name));
  return key ? TASK_TYPES[key] : ["任务", "is-other", type || "生成任务"];
}

const LIVE_STATUS_LABELS = {
  queued: "排队中",
  processing: "生成中",
  succeeded: "已生成",
  failed: "失败",
  blocked: "已阻断",
  timeout: "超时"
};

function renderLiveGenerations(rows) {
  const host = $("live-generations");
  const all = Array.isArray(rows) ? rows : [];
  const activeCount = all.filter((row) => ["processing", "queued"].includes(row.status || "processing")).length;
  setText($("live-count"), all.length ? `${activeCount} 路进行中` : "");
  // Running work first, then the most recent finished tasks.
  const rank = (row) => ({ processing: 0, queued: 1 }[row.status || "processing"] ?? 2);
  const data = all.map((row, index) => ({ row, index })).sort((a, b) => rank(a.row) - rank(b.row) || a.index - b.index).slice(0, 5).map(({ row }) => row);
  if (data.length === 0) {
    renderEmpty(host, "暂无正在生成内容");
    return;
  }
  host.replaceChildren(...data.map((row) => {
    const status = row.status || "processing";
    const [short, tone, typeLabel] = taskType(row.task_type);
    const who = row.department_name || row.user_name || (row.department_id ? `部门 ${row.department_id}` : "生成任务");
    const progress = numericValue(row.progress);
    const main = el("div", "live-main", [
      createText("strong", "", who),
      createText("span", "live-meta", [row.model_name, typeLabel].filter(Boolean).join(" · ") || "--"),
      status === "processing" && progress > 0 ? bar("live-progress", progress / 100, 0) : null,
      status === "queued" ? el("div", "live-progress", [document.createElement("span")]) : null
    ]);
    const side = el("div", "live-side", [
      createText("span", "live-tokens", status === "processing" && progress > 0 ? `${Math.round(progress)}%` : row.total_tokens ? formatNumber(row.total_tokens) : formatRelative(row.created_at)),
      createText("span", "live-status", LIVE_STATUS_LABELS[status] || "生成中")
    ]);
    return el("div", `live-row is-${status}`, [createText("span", `live-type ${tone}`, short), main, side]);
  }));
}

function renderOverview(overview, compare = null) {
  const source = overview?.data || overview || {};
  const [, periodLabel, totals] = overviewTotalsForDisplay(source);
  renderMetricCards(totals || {}, periodLabel, compare);
  renderTokenDetails(source.model_usage || []);
  renderModelShare(source.model_token_share || []);
  renderModelUsage(source.model_usage || []);
  renderPeriodTotals(source.period_totals || {});
  renderLiveGenerations(source.live_generations || []);
}

/**
 * Hourly series for today and yesterday in the business time zone, plus the
 * like-for-like comparison: yesterday up to the same minute (the current hour of
 * yesterday is prorated).
 */
function dayComparison(rows) {
  const calls = { today: new Array(24).fill(null), yesterday: new Array(24).fill(0) };
  const tokens = { today: 0, yesterday: new Array(24).fill(0) };
  const now = new Date();
  const nowParts = zonedParts(now);
  const yesterdayKey = zonedParts(new Date(now.getTime() - 86400000)).day;
  let any = false;
  for (const row of Array.isArray(rows) ? rows : []) {
    const date = bucketDate(row.bucket_at);
    if (!date) continue;
    const parts = zonedParts(date);
    const value = numericValue(row.request_count);
    const rowTokens = numericValue(row.input_tokens) + numericValue(row.output_tokens);
    if (parts.day === nowParts.day) {
      calls.today[parts.hour] = (calls.today[parts.hour] || 0) + value;
      tokens.today += rowTokens;
      any = true;
    } else if (parts.day === yesterdayKey) {
      calls.yesterday[parts.hour] += value;
      tokens.yesterday[parts.hour] += rowTokens;
      any = true;
    }
  }
  for (let hour = 0; hour <= nowParts.hour; hour += 1) {
    if (calls.today[hour] === null) calls.today[hour] = 0;
  }
  const fraction = nowParts.minute / 60;
  const sameTime = (values) => values.slice(0, nowParts.hour).reduce((sum, value) => sum + value, 0) + values[nowParts.hour] * fraction;
  const todayCalls = calls.today.reduce((sum, value) => sum + (value || 0), 0);
  const yesterdayCalls = sameTime(calls.yesterday);
  const yesterdayTokens = sameTime(tokens.yesterday);
  return {
    any,
    nowHour: nowParts.hour,
    nowFraction: fraction,
    calls,
    todayCalls,
    yesterdayCalls,
    // Wall-clock "same time": the plain sum of yesterday's hours up to now, for the label.
    yesterdaySame: calls.yesterday.slice(0, nowParts.hour + 1).reduce((sum, value) => sum + value, 0),
    callChange: percentChange(todayCalls, yesterdayCalls),
    tokenChange: tokens.today > 0 || yesterdayTokens > 0 ? percentChange(tokens.today, yesterdayTokens) : null
  };
}

// Hourly calls today against yesterday. Day boundaries follow the business time
// zone reported by the backend, not the browser's zone.
function renderOverviewTrend(rows, stats = dayComparison(rows)) {
  const host = $("overview-trend");
  if (!host) return;
  const summary = $("overview-trend-summary");
  if (!stats.any) {
    summary?.replaceChildren();
    renderEmpty(host, "暂无趋势数据");
    return;
  }
  const { today, yesterday } = stats.calls;
  const runRate = stats.nowFraction >= 0.1 ? (today[stats.nowHour] || 0) / stats.nowFraction : 0;
  const max = niceMax(Math.max(1, runRate, ...yesterday, ...today.filter((value) => value !== null)) * 1.08);
  const label = `今日累计调用 ${formatNumber(stats.todayCalls)} 次，昨日同时段 ${formatNumber(stats.yesterdaySame)} 次`;
  const frame = chartFrame(max, formatAxis, label);
  const x = (hour) => (hour + 0.5) / 24 * frame.width;
  const defs = svgEl("defs");
  const gradient = svgEl("linearGradient", { id: "screen-trend-fill", x1: 0, y1: 0, x2: 0, y2: 1 });
  gradient.append(svgEl("stop", { offset: "0%", "stop-color": "#34d399", "stop-opacity": 0.32 }), svgEl("stop", { offset: "100%", "stop-color": "#34d399", "stop-opacity": 0 }));
  defs.append(gradient);
  frame.svg.prepend(defs);

  const pastPoints = yesterday.map((value, hour) => [x(hour), frame.y(value)]);
  frame.svg.append(svgEl("path", { d: smoothPath(pastPoints), class: "trend-line is-yesterday" }));
  // Completed hours are a solid line; the running hour would always look like a drop,
  // so it is drawn dashed at its projected hourly rate.
  const completed = today.slice(0, stats.nowHour).map((value, hour) => [x(hour), frame.y(value || 0)]);
  const nowValue = today[stats.nowHour] || 0;
  const projected = stats.nowFraction >= 0.1 ? nowValue / stats.nowFraction : null;
  if (completed.length > 1) {
    const line = smoothPath(completed);
    const last = completed[completed.length - 1];
    frame.svg.append(svgEl("path", { d: `${line} L${last[0].toFixed(1)},${frame.height} L${completed[0][0].toFixed(1)},${frame.height} Z`, class: "trend-area" }));
    frame.svg.append(svgEl("path", { d: line, class: "trend-line is-today" }));
  }
  const nowX = x(stats.nowHour);
  const nowY = frame.y(projected ?? nowValue);
  frame.svg.append(svgEl("path", { d: `M${nowX} 0V${frame.height}`, class: "trend-now-line" }));
  if (completed.length > 0) {
    const last = completed[completed.length - 1];
    frame.svg.append(svgEl("path", { d: `M${last[0].toFixed(1)},${last[1].toFixed(1)} L${nowX.toFixed(1)},${nowY.toFixed(1)}`, class: "trend-line is-today is-running" }));
  }
  const hourLabel = `${String(stats.nowHour).padStart(2, "0")}:00`;
  chartMarker(frame.plot, nowX / frame.width, nowY / frame.height, projected !== null
    ? `${hourLabel} 已 ${formatNumber(nowValue)} 次 · 预计 ${formatNumber(Math.round(projected))}`
    : `${hourLabel} · ${formatNumber(nowValue)} 次`);

  axisLabels(frame.xaxis, ["00:00", "06:00", "12:00", "18:00", "24:00"].map((text, index) => [index / 4, text]));
  host.replaceChildren(frame.body);

  summary?.replaceChildren(...[
    createText("span", "", "今日"),
    createText("strong", "", formatNumber(stats.todayCalls)),
    createText("span", "", "次"),
    deltaNode(stats.callChange, "较昨日同时段")
  ].filter(Boolean));
}

function rankingName(row, index) {
  return row.name || row.department_name || row.model_name || row.model || row.provider || row.api_key_name || (row.id ? `#${row.id}` : `第 ${index + 1} 名`);
}

function renderOverviewRankings(rows) {
  const host = $("overview-rankings");
  if (!host) return;
  const data = Array.isArray(rows) ? rows.slice(0, 10) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无排行数据");
    return;
  }
  const max = Math.max(1, ...data.map((row) => numericValue(row.request_count)));
  const total = (Array.isArray(rows) ? rows : []).reduce((sum, row) => sum + numericValue(row.request_count), 0);
  host.replaceChildren(...data.map((row, index) => {
    const value = el("span", "rank-value", [
      document.createTextNode(formatNumber(row.request_count)),
      total > 0 ? createText("small", "rank-share", formatPercent(numericValue(row.request_count) / total * 100)) : null
    ]);
    return el("div", "rank-row", [
      createText("span", "rank-index", String(index + 1).padStart(2, "0")),
      createText("span", "rank-name", rankingName(row, index)),
      value,
      bar("rank-bar", numericValue(row.request_count) / max)
    ]);
  }));
}

function sortedBuckets(data) {
  const source = data?.data || data || {};
  return Array.isArray(source.buckets) ? [...source.buckets].sort((a, b) => String(a.bucket_at).localeCompare(String(b.bucket_at))) : [];
}

function renderOverviewPerformance(data, totals) {
  const host = $("overview-performance");
  if (!host) return;
  const buckets = sortedBuckets(data);
  const latest = buckets[buckets.length - 1];
  const requests = numericValue(totals?.request_count);
  const success = totals?.success_count != null ? numericValue(totals.success_count) : null;
  host.replaceChildren(
    createText("strong", "", latest ? formatSeconds(latest.p95_latency_ms) : "--"),
    createText("small", "mini-good", success !== null && requests > 0 ? `成功率 ${(success / requests * 100).toFixed(2)}%` : "最近时段")
  );
}

const SECURITY_ACTION_LABELS = { block: "拦截", redact: "脱敏", replace: "替换", summary: "摘要", log: "记录", allow: "放行", remove_header: "移除请求头" };

function securityEvents(data) {
  const source = data?.data || data || {};
  const desensitization = Array.isArray(source.desensitization) ? source.desensitization.map((item) => ({ ...item, type: "脱敏" })) : [];
  return desensitization.slice(0, 8);
}

function renderOverviewSecurity(data) {
  const host = $("overview-security");
  if (!host) return;
  const events = securityEvents(data);
  const total = events.reduce((sum, event) => sum + numericValue(event.event_count || event.count), 0);
  const blocked = events.filter((event) => String(event.action || "") === "block").reduce((sum, event) => sum + numericValue(event.event_count || event.count), 0);
  host.replaceChildren(
    createText("strong", "", formatNumber(total)),
    createText("small", blocked > 0 ? "mini-warn" : "", blocked > 0 ? `其中拦截 ${formatNumber(blocked)}` : "今日 · 按策略处理")
  );
}

/* ====================================================== 大屏2 · 运维 */

function sparkline(values) {
  const data = values.map(numericValue);
  if (data.length < 2) return null;
  const max = Math.max(...data, 1e-9);
  const width = 100;
  const height = 26;
  const points = data.map((value, index) => [index / (data.length - 1) * width, height - 2 - value / max * (height - 4)]);
  const line = smoothPath(points);
  const svg = svgEl("svg", { viewBox: `0 0 ${width} ${height}`, preserveAspectRatio: "none", class: "sparkline", "aria-hidden": "true" });
  svg.append(svgEl("path", { d: `${line} L${width},${height} L0,${height} Z`, class: "spark-area" }), svgEl("path", { d: line }));
  return svg;
}

function renderLivePanel(live) {
  const host = $("live-metrics");
  if (!host) return;
  if (!live || live.blocked) {
    renderEmpty(host, "暂无实时数据");
    renderProviders(null);
    return;
  }
  const circuits = live.circuits || {};
  const ttft = live.ttft || {};
  const queue = live.queue || {};
  const series = Array.isArray(live.series) ? live.series : [];
  const perMinute = (key) => series.map((row) => numericValue(row[key]));
  const errorSeries = series.map((row) => (numericValue(row.request_count) > 0 ? numericValue(row.failed_count) / numericValue(row.request_count) : 0));
  const errorRate = numericValue(live.error_rate);
  const openCircuits = numericValue(circuits.open);
  const halfOpen = numericValue(circuits.half_open);
  const pending = numericValue(queue.pending);
  setText($("live-window"), `近 ${live.window_minutes || 5} 分钟 · 每分钟`);
  const cards = [
    { label: "QPS", value: numericValue(live.qps).toFixed(2), meta: `近 ${live.window_minutes || 5} 分钟 ${formatNumber(live.request_count)} 次`, spark: perMinute("request_count") },
    { label: "TPS", value: formatNumber(Math.round(numericValue(live.tps))), meta: "Token / 秒", spark: perMinute("total_tokens") },
    { label: "错误率", value: formatPercent(errorRate), meta: `${formatNumber(live.failed_count)} 次失败`, spark: errorSeries, tone: errorRate >= 20 ? "is-danger" : errorRate >= 5 ? "is-warn" : "" },
    { label: "延迟 P95", value: formatSeconds(live.p95_latency_ms), meta: `P99 ${formatSeconds(live.p99_latency_ms)}`, tone: numericValue(live.p95_latency_ms) >= 30000 ? "is-warn" : "" },
    { label: "首 Token", value: ttft.available ? formatSeconds(ttft.avg_ms) : "--", meta: ttft.available ? "平均首 Token 延迟" : "待网关记录" },
    { label: "未处理告警", value: live.open_alerts == null ? "--" : formatNumber(live.open_alerts), meta: live.open_alerts == null ? "仅全局大屏显示" : "状态为待处理", tone: numericValue(live.open_alerts) > 0 ? "is-warn" : "" },
    { label: "熔断", value: formatNumber(openCircuits), meta: `半开 ${formatNumber(halfOpen)} · 正常 ${formatNumber(circuits.closed || 0)}`, tone: openCircuits > 0 ? "is-danger" : halfOpen > 0 ? "is-warn" : "" },
    { label: "队列待处理", value: queue.pending == null ? "--" : formatNumber(pending), meta: queue.worker_alive === false ? "worker 无心跳" : (queue.worker_alive ? "worker 在线" : "队列状态"), tone: queue.worker_alive === false ? "is-danger" : pending >= 100 ? "is-warn" : "" }
  ];
  host.replaceChildren(...cards.map((card) => el("article", `live-metric-card ${card.tone || ""}`.trim(), [
    createText("span", "metric-label", card.label),
    createText("strong", "", card.value),
    createText("small", "performance-meta", card.meta),
    card.spark ? sparkline(card.spark) : null
  ])));
  renderProviders(live);
}

function renderProviders(live) {
  const providers = $("provider-health");
  const circuitsHost = $("circuit-channels");
  if (!providers) return;
  const rows = Array.isArray(live?.providers) ? live.providers : [];
  if (rows.length === 0) {
    setText($("provider-meta"), "");
    renderEmpty(providers, "近 5 分钟暂无调用");
  } else {
    const total = rows.reduce((sum, row) => sum + numericValue(row.request_count), 0);
    const max = Math.max(1, ...rows.map((row) => numericValue(row.request_count)));
    setText($("provider-meta"), `${rows.length} 家 · ${formatNumber(total)} 次`);
    providers.replaceChildren(...rows.slice(0, 8).map((row) => {
      const errorRate = numericValue(row.error_rate);
      const tone = errorRate >= 20 ? " is-warn is-danger" : errorRate >= 5 ? " is-warn" : "";
      const item = el("div", `provider-row${tone}`, [
        createText("strong", "rank-name", row.name || (row.id ? `厂商 #${row.id}` : "未知厂商")),
        createText("small", "rank-meta", `错误 ${formatPercent(errorRate)} · ${formatSeconds(row.avg_latency_ms)}`),
        createText("span", "rank-value", formatNumber(row.request_count)),
        bar("rank-bar", numericValue(row.request_count) / max)
      ]);
      item.title = `QPS ${numericValue(row.qps).toFixed(2)} · 错误率 ${formatPercent(errorRate)} · 平均延迟 ${formatSeconds(row.avg_latency_ms)}`;
      return item;
    }));
  }
  if (!circuitsHost) return;
  const circuits = live?.circuits || {};
  if (!live || circuits.available === false) {
    circuitsHost.replaceChildren();
    circuitsHost.hidden = true;
    return;
  }
  circuitsHost.hidden = false;
  const channels = Array.isArray(circuits.open_channels) ? circuits.open_channels : [];
  circuitsHost.replaceChildren(
    createText("span", "circuit-title", "渠道熔断"),
    ...(channels.length
      ? channels.slice(0, 6).map((channel) => createText("span", `circuit-chip is-${channel.state || "open"}`, `${channel.name || "未命名渠道"} · ${channel.state === "half_open" ? "半开" : "熔断"}`))
      : [createText("span", "circuit-ok", `全部 ${formatNumber(circuits.closed || 0)} 个渠道正常`)])
  );
}

// Last 48 hours as stacked columns (success + failed); today's bars are bright.
function renderTrendBars(rows) {
  const host = $("trends");
  const summary = $("trends-summary");
  const list = Array.isArray(rows) ? rows : [];
  if (list.length === 0) {
    summary?.replaceChildren();
    renderEmpty(host, "暂无趋势数据");
    return;
  }
  const byHour = new Map();
  for (const row of list) {
    const date = bucketDate(row.bucket_at);
    if (date) byHour.set(Math.floor(date.getTime() / 3600000), row);
  }
  const lastHour = Math.max(Math.floor(Date.now() / 3600000), ...byHour.keys());
  const slots = Array.from({ length: 48 }, (_, index) => {
    const hour = lastHour - 47 + index;
    const row = byHour.get(hour) || {};
    const requests = numericValue(row.request_count);
    const failed = numericValue(row.failed_count);
    const parts = zonedParts(new Date(hour * 3600000));
    return { requests, failed, success: row.success_count != null ? numericValue(row.success_count) : Math.max(0, requests - failed), parts };
  });
  const todayKey = zonedParts(new Date()).day;
  const totalRequests = slots.reduce((sum, slot) => sum + slot.requests, 0);
  const totalFailed = slots.reduce((sum, slot) => sum + slot.failed, 0);
  const max = niceMax(Math.max(1, ...slots.map((slot) => slot.requests)) * 1.08);
  const frame = chartFrame(max, formatAxis, `近 48 小时调用 ${formatNumber(totalRequests)} 次，失败 ${formatNumber(totalFailed)} 次`);
  const slotWidth = frame.width / slots.length;
  const barWidth = slotWidth * 0.68;
  let dayStartIndex = -1;
  slots.forEach((slot, index) => {
    const isToday = slot.parts.day === todayKey;
    if (isToday && dayStartIndex < 0) dayStartIndex = index;
    const x = index * slotWidth + (slotWidth - barWidth) / 2;
    const successTop = frame.y(slot.success);
    const failedTop = frame.y(slot.success + slot.failed);
    if (slot.success > 0) {
      const rect = svgEl("rect", { x: x.toFixed(2), y: successTop.toFixed(2), width: barWidth.toFixed(2), height: (frame.height - successTop).toFixed(2), class: `bar-success${isToday ? " is-today" : ""}` });
      const title = svgEl("title");
      title.textContent = `${slot.parts.label} · ${formatNumber(slot.requests)} 次（失败 ${formatNumber(slot.failed)}）`;
      rect.append(title);
      frame.svg.append(rect);
    }
    if (slot.failed > 0) {
      frame.svg.append(svgEl("rect", { x: x.toFixed(2), y: failedTop.toFixed(2), width: barWidth.toFixed(2), height: Math.max(1.5, successTop - failedTop).toFixed(2), class: "bar-failed" }));
    }
  });
  if (dayStartIndex > 0) {
    const lineX = dayStartIndex * slotWidth;
    frame.svg.append(svgEl("path", { d: `M${lineX} 0V${frame.height}`, class: "bar-day-line" }));
    const label = createText("span", "chart-day-label", "今日");
    label.style.left = `calc(${(lineX / frame.width * 100).toFixed(2)}% + 6px)`;
    frame.plot.append(label);
  }
  const latest = slots[slots.length - 1];
  chartMarker(frame.plot, (slots.length - 0.5) / slots.length, frame.y(latest.requests) / frame.height, `${latest.parts.hourLabel} · ${formatNumber(latest.requests)} 次`);
  axisLabels(frame.xaxis, slots.map((slot, index) => [(index + 0.5) / slots.length, slot]).filter(([, slot]) => slot.parts.hour % 6 === 0).map(([ratio, slot]) => [ratio, slot.parts.hour === 0 ? `${slot.parts.month}/${slot.parts.date}` : slot.parts.hourLabel]));
  host.replaceChildren(frame.body);
  summary?.replaceChildren(
    createText("strong", "", formatNumber(totalRequests)),
    createText("span", "", "次 · 失败率"),
    createText("strong", "", totalRequests > 0 ? `${(totalFailed / totalRequests * 100).toFixed(2)}%` : "--")
  );
}

function renderRankingList(rows) {
  const host = $("rankings");
  if (!host) return;
  const data = Array.isArray(rows) ? rows.slice(0, 10) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无排行数据");
    return;
  }
  const max = Math.max(1, ...data.map((row) => numericValue(row.request_count)));
  host.replaceChildren(...data.map((row, index) => el("div", "rank-row", [
    createText("span", "rank-index", String(index + 1).padStart(2, "0")),
    createText("strong", "rank-name", rankingName(row, index)),
    createText("small", "rank-meta", `${formatTokenCompact(row.total_tokens)} · ${formatNumber(row.error_count)} 错误`),
    createText("span", "rank-value", formatNumber(row.request_count)),
    bar("rank-bar", numericValue(row.request_count) / max)
  ])));
}

const AUDIT_RESOURCES = {
  admin: "管理员", user: "用户", users: "用户", api_key: "API 密钥", api_keys: "API 密钥", provider: "厂商", providers: "厂商",
  model: "模型", models: "模型", department: "部门", departments: "部门", dashboard_share_link: "大屏链接", rag: "知识库",
  asset: "媒体资源", settings_center: "系统设置", system_settings: "系统设置", smtp: "邮件", mcp_oauth: "MCP 授权", export: "导出", migration: "数据库迁移", install: "安装"
};
const AUDIT_VERBS = {
  create: "新增", update: "修改", delete: "删除", disable: "停用", enable: "启用", login: "登录", logout: "退出", reset: "重置",
  password_reset: "重置密码", revoke: "撤销", revoke_all: "全部撤销", sessions_revoke: "撤销会话", download_url: "下载", test: "测试",
  replace: "替换", batch: "批量操作", move: "移动", applied: "执行", allow_client_ip: "放行 IP"
};

function auditLabel(action) {
  const parts = String(action || "").split(".");
  const resource = AUDIT_RESOURCES[parts[0]];
  const verb = AUDIT_VERBS[parts[parts.length - 1]];
  if (resource && verb) return `${resource} · ${verb}`;
  return resource || String(action || "审计事件");
}

function renderSecurityPanel(data) {
  const host = $("security");
  const events = securityEvents(data);
  const source = data?.data || data || {};
  const audit = Array.isArray(source.audit) ? source.audit.slice(0, 6) : [];
  if (events.length === 0 && audit.length === 0) {
    renderEmpty(host, "今日暂无安全事件");
    return;
  }
  const count = (event) => numericValue(event.event_count || event.match_count || event.count);
  const total = events.reduce((sum, event) => sum + count(event), 0);
  const max = Math.max(1, ...events.map(count));
  const blocked = events.filter((event) => event.action === "block").reduce((sum, event) => sum + count(event), 0);
  const nodes = [
    el("div", "security-total", [createText("strong", "", formatNumber(total)), createText("span", "", blocked > 0 ? `次脱敏事件 · 拦截 ${formatNumber(blocked)}` : "次脱敏事件")]),
    ...events.map((event) => el("div", `security-row${event.action === "block" ? " is-block" : ""}`, [
      createText("strong", "security-title", SECURITY_ACTION_LABELS[event.action] || event.rule_name || event.policy_name || event.action || "安全事件"),
      createText("span", "security-count", formatNumber(count(event))),
      bar("security-bar", count(event) / max),
      event.match_count ? createText("small", "security-meta", `命中 ${formatNumber(event.match_count)} 处`) : null
    ]))
  ];
  if (audit.length > 0) {
    nodes.push(createText("div", "security-section", "审计操作"));
    nodes.push(...audit.map((item) => el("div", "security-row is-audit", [
      createText("span", "security-title", auditLabel(item.action)),
      createText("span", "security-count", formatNumber(item.event_count || item.count))
    ])));
  }
  host.replaceChildren(...nodes);
}

// Latency over the last 24 hours (average, P95, P99) and the latest hour as numbers.
function renderPerformancePanel(data) {
  const host = $("performance");
  const buckets = sortedBuckets(data).slice(-24);
  if (buckets.length === 0) {
    renderEmpty(host, "暂无性能数据");
    return;
  }
  const latest = buckets[buckets.length - 1];
  const stats = el("div", "performance-stats", [
    ["平均", latest.avg_latency_ms, `${formatNumber(latest.request_count)} 请求`],
    ["P95", latest.p95_latency_ms, formatBucketTime(latest.bucket_at)],
    ["P99", latest.p99_latency_ms, "最近一小时"]
  ].map(([label, value, meta]) => el("article", "performance-card", [
    createText("span", "performance-time", label),
    createText("strong", "performance-main", formatSeconds(value)),
    createText("small", "performance-meta", meta)
  ])));
  if (buckets.length < 2) {
    host.replaceChildren(stats, createText("div", "empty-state", "至少两个小时的数据后显示延迟曲线"));
    return;
  }
  const peak = Math.max(1, ...buckets.map((row) => numericValue(row.p99_latency_ms)), ...buckets.map((row) => numericValue(row.p95_latency_ms)));
  const max = niceMax(peak * 1.05);
  const frame = chartFrame(max, (value) => (value >= 1000 ? `${+(value / 1000).toFixed(1)} s` : `${Math.round(value)} ms`), `最近 ${buckets.length} 小时延迟：最新平均 ${formatSeconds(latest.avg_latency_ms)}，P95 ${formatSeconds(latest.p95_latency_ms)}`);
  const x = (index) => index / (buckets.length - 1) * frame.width;
  for (const [key, className] of [["p99_latency_ms", "is-p99"], ["p95_latency_ms", "is-p95"], ["avg_latency_ms", "is-avg"]]) {
    frame.svg.append(svgEl("path", { d: smoothPath(buckets.map((row, index) => [x(index), frame.y(row[key])])), class: `trend-line ${className}` }));
  }
  const pick = [0, Math.floor((buckets.length - 1) / 2), buckets.length - 1];
  axisLabels(frame.xaxis, pick.map((index) => [index / (buckets.length - 1), zonedParts(bucketDate(buckets[index].bucket_at) || new Date()).label]));
  host.replaceChildren(stats, el("div", "trend-chart", [frame.body]));
}

/* ========================================================== refresh */

function isAuthError(error) {
  return error?.status === 401 || error?.status === 403 || ["SCREEN_LINK_DISABLED", "SCREEN_LINK_EXPIRED", "SCREEN_IP_FORBIDDEN", "SCREEN_UNAUTHENTICATED"].includes(error?.code);
}

const FIT_LISTS = ["model-usage", "overview-rankings", "provider-health", "rankings", "security"];
const SCREEN_METRICS = ["overview", "trends", "rankings", "security", "performance", "live"];

// Draws every panel from the last fetched payloads (also used after a resize).
function renderAll(payloads) {
  const { overview, trends, rankings, security, performance, live } = payloads;
  const trendRows = trends ? (trends.data || trends || []) : null;
  const stats = trendRows ? dayComparison(trendRows) : null;
  if (overview) {
    renderOverview(overview, stats);
  }
  if (trendRows) {
    renderTrendBars(trendRows);
    renderOverviewTrend(trendRows, stats);
  }
  if (rankings) {
    const rankingRows = rankings.data || rankings || [];
    renderRankingList(rankingRows);
    renderOverviewRankings(rankingRows);
  }
  if (security) {
    renderSecurityPanel(security);
    renderOverviewSecurity(security);
  }
  if (performance) {
    renderPerformancePanel(performance);
    renderOverviewPerformance(performance, (overview?.data || overview || {}).totals);
  }
  if (live) {
    const liveData = live.blocked ? null : (live.data || live);
    renderLivePanel(liveData);
    renderHealth(liveData);
  }
  // Trim only once every panel has its final size.
  for (const id of FIT_LISTS) {
    fitRows($(id));
  }
}

async function render() {
  setStatus("刷新中", "busy");
  const results = await Promise.allSettled(SCREEN_METRICS.map((metric) => api(`/screen-api/dashboard/${metric}`)));
  const failure = results.find((result) => result.status === "rejected");
  const authFailure = results.find((result) => result.status === "rejected" && isAuthError(result.reason));
  if (authFailure) {
    throw authFailure.reason;
  }
  const value = (index) => (results[index].status === "fulfilled" ? results[index].value : null);
  const [overview, trends, rankings, security, performance, live] = SCREEN_METRICS.map((_, index) => value(index));
  setBusinessTimeZone(overview?.business_timezone || overview?.data?.business_timezone || trends?.business_timezone || live?.business_timezone);
  // A failed metric keeps its last good payload instead of blanking the panel.
  const previous = lastRendered || {};
  lastRendered = {
    overview: overview || previous.overview || null,
    trends: trends || previous.trends || null,
    rankings: rankings || previous.rankings || null,
    security: security || previous.security || null,
    performance: performance || previous.performance || null,
    live: live || previous.live || null
  };
  renderAll(lastRendered);
  if (overview) {
    renderUpdatedAt((overview.data || overview).generated_at);
  }
  updateClock();
  if (failure) {
    setStatus(failure.reason?.code === "SCREEN_TIMEOUT" ? "部分数据超时" : "部分数据刷新失败", "error");
    throw failure.reason;
  }
  lastSuccessAt = Date.now();
  renderStale();
  setStatus("已更新", "ready");
}

async function showScreen(session) {
  $("login").hidden = true;
  $("screen").hidden = false;
  if (session) renderScope(session);
  setScreenView(screenViewFromPath());
  applyScreenScale();
  markActive();
  updateClock();
  if (clockTimer === null) {
    clockTimer = setInterval(updateClock, 1000);
  }
  // Visibility-aware polling (pauses in background tabs, refreshes when the screen or
  // the network comes back, backs off after failures); never two refreshes at once.
  if (poller === null) {
    poller = createPoller(refreshScreen, { interval: SCREEN_REFRESH_INTERVAL_MS, maxInterval: SCREEN_REFRESH_INTERVAL_MS * 8 });
  } else {
    await poller.refresh();
  }
}

function stopPolling() {
  if (poller !== null) {
    poller.stop();
    poller = null;
  }
  abortInflightRequests();
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
  stopPolling();
  lastRendered = null;
  lastSuccessAt = 0;
  document.body.classList.remove("screen-idle");
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
  const session = await api("/screen-api/auth/share-login", {
    method: "POST",
    body: JSON.stringify({ share_code: shareCode })
  });
  await showScreen(session);
}

async function enterByCredentials() {
  const session = await api("/screen-api/auth/login", {
    method: "POST",
    body: JSON.stringify({
      username: $("username").value,
      password: $("password").value
    })
  });
  await showScreen(session);
}

// In-flight guard: a refresh never starts while the previous one is still running.
function refreshScreen() {
  if (refreshing) {
    return refreshing;
  }
  refreshing = refreshOnce().finally(() => {
    refreshing = null;
  });
  return refreshing;
}

async function refreshOnce() {
  try {
    await render();
    reloginTried = false;
  } catch (error) {
    if (error?.code === "SCREEN_ABORTED") {
      return;
    }
    // An expired session on a share-code screen signs in again once, silently.
    if (error?.status === 401 && shareCodeFromPath() && !reloginTried) {
      reloginTried = true;
      try {
        await api("/screen-api/auth/share-login", { method: "POST", body: JSON.stringify({ share_code: shareCodeFromPath() }) });
        await render();
        reloginTried = false;
        return;
      } catch (retryError) {
        error = retryError;
      }
    }
    if (isAuthError(error)) {
      showLogin(screenErrorMessage(error, "大屏会话已失效，请重新登录"));
      return;
    }
    setStatus(error?.code === "SCREEN_TIMEOUT" ? "刷新超时" : "刷新失败", "error");
    throw error;
  }
}

async function submitLogin() {
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
}

$("login-button").addEventListener("click", submitLogin);
$("password").addEventListener("keydown", (event) => {
  if (event.key === "Enter") submitLogin();
});

$("logout").addEventListener("click", async () => {
  await api("/screen-api/auth/logout", { method: "POST" }).catch(() => null);
  showLogin("");
});

$("fullscreen-toggle").addEventListener("click", toggleFullscreen);
document.addEventListener("fullscreenchange", updateFullscreenButton);
document.addEventListener("keydown", (event) => {
  markActive();
  if ((event.key === "f" || event.key === "F") && !event.ctrlKey && !event.metaKey && !event.altKey && !$("screen").hidden && !(event.target instanceof HTMLInputElement)) {
    toggleFullscreen();
  }
});
for (const name of ["mousemove", "pointerdown", "touchstart"]) {
  window.addEventListener(name, markActive, { passive: true });
}
window.addEventListener("resize", onResize);

applyScreenScale();
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
