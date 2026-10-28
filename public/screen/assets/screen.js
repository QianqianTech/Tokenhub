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

function renderMetricCards(today, periodLabel = "今日") {
  const totalTokens = tokenTotal(today);
  const metrics = [
    { key: "total_tokens", label: "总 Token", rawValue: totalTokens, formatter: formatTokenMillions, hint: "单位 M" },
    { key: "input_tokens", label: "总输入", rawValue: today.input_tokens, formatter: formatTokenMillions, hint: "Input Token" },
    { key: "output_tokens", label: "总输出", rawValue: today.output_tokens, formatter: formatTokenMillions, hint: "Output Token" },
    { key: "cache_tokens", label: "总缓存", rawValue: today.cache_tokens, formatter: formatTokenMillions, hint: "Cache Token" },
    { key: "request_count", label: "总调用次数", rawValue: today.request_count, formatter: formatNumber, hint: "次" }
  ];
  $("token-summary").replaceChildren(...metrics.map((metric, index) => {
    const card = document.createElement("article");
    const value = createText("strong", "metric-value", "");
    card.className = `metric-card accent-${index % 3}`;
    card.append(
      createText("span", "metric-label", metric.label),
      createText("small", "metric-period", periodLabel),
      value,
      metricTrendNode(metric.key, metric.rawValue, metric.formatter),
      createText("small", "metric-hint", metric.hint)
    );
    animateMetricValue(value, metric.key, metric.rawValue, metric.formatter);
    return card;
  }));
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

function renderModelShare(rows) {
  const host = $("model-share-chart");
  const data = Array.isArray(rows) ? rows.filter((row) => Number(row.total_tokens || 0) > 0).slice(0, 6) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无模型占比数据");
    return;
  }
  const colors = ["#38bdf8", "#2dd4bf", "#a78bfa", "#86efac", "#fbbf24", "#fb7185"];
  let cursor = 0;
  const segments = data.map((row, index) => {
    const start = cursor;
    cursor += Math.max(0, Number(row.percent || 0));
    return `${colors[index % colors.length]} ${start}% ${cursor}%`;
  });
  if (cursor < 100) {
    segments.push(`rgba(148, 163, 184, 0.16) ${cursor}% 100%`);
  }
  const ring = document.createElement("div");
  ring.className = "model-chart-ring";
  ring.style.background = `conic-gradient(${segments.join(", ")})`;
  ring.append(createText("strong", "", "模型 Token 占比"));

  const legend = data.map((row, index) => {
    const item = document.createElement("div");
    item.className = "model-legend-row";
    const swatch = document.createElement("span");
    swatch.className = "model-swatch";
    swatch.style.background = colors[index % colors.length];
    item.append(
      swatch,
      createText("strong", "", row.model_name || `模型 ${index + 1}`),
      createText("span", "", formatPercent(row.percent))
    );
    return item;
  });
  host.replaceChildren(ring, ...legend);
}

function renderModelUsage(rows) {
  const host = $("model-usage");
  const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无模型使用数据");
    return;
  }
  const max = Math.max(1, ...data.map((row) => Number(row.total_tokens || 0)));
  host.replaceChildren(...data.map((row) => {
    const item = document.createElement("div");
    item.className = "model-row";
    const header = document.createElement("header");
    header.append(
      createText("strong", "", row.model_name || "未知模型"),
      createText("span", "", `${formatNumber(row.request_count)} 次`)
    );
    const meta = createText("span", "", `${formatNumber(row.total_tokens)} Token · 输入 ${formatNumber(row.input_tokens)} / 输出 ${formatNumber(row.output_tokens)}`);
    const progress = document.createElement("div");
    progress.className = "model-progress";
    const fill = document.createElement("span");
    fill.style.width = `${Math.max(4, Math.round(Number(row.total_tokens || 0) / max * 100))}%`;
    progress.append(fill);
    item.append(header, meta, progress);
    return item;
  }));
}

function renderPeriodTotals(periods) {
  const labels = [
    ["today", "当日"],
    ["week", "本周"],
    ["month", "本月"],
    ["quarter", "本季度"],
    ["year", "本年度"]
  ];
  $("period-totals").replaceChildren(...labels.map(([key, label]) => {
    const row = periods?.[key] || {};
    const card = document.createElement("div");
    card.className = "period-card";
    card.append(
      createText("span", "", label),
      createText("strong", "", formatNumber(row.total_tokens))
    );
    return card;
  }));
}

function renderLiveGenerations(rows) {
  const host = $("live-generations");
  const data = Array.isArray(rows) ? rows.slice(0, 10) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无正在生成内容");
    return;
  }
  host.replaceChildren(...data.map((row) => {
    const item = document.createElement("div");
    item.className = "live-row";
    const statusLabels = {
      queued: "排队中",
      processing: "生成中",
      succeeded: "已生成",
      failed: "失败",
      blocked: "已阻断",
      timeout: "超时"
    };
    const statusLabel = statusLabels[row.status] || "生成中";
    const header = document.createElement("header");
    header.append(
      createText("strong", "", row.model_name || row.task_id || "生成任务"),
      createText("span", "live-status", statusLabel)
    );
    const userName = row.user_name || `用户 ${row.user_id || "--"}`;
    const departmentName = row.department_name || `部门 ${row.department_id || "--"}`;
    const meta = createText("span", "", `使用者 ${userName} · 部门 ${departmentName}`);
    const time = createText("small", "live-meta", `${row.task_type || "任务"} · ${formatTime(row.created_at)}`);
    const progress = document.createElement("div");
    progress.className = "live-progress";
    const fill = document.createElement("span");
    fill.style.width = `${Math.max(4, Math.min(100, Number(row.progress || 0)))}%`;
    progress.append(fill);
    item.append(header, meta, time, progress);
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

function renderTrendBars(rows) {
  const host = $("trends");
  const data = Array.isArray(rows) ? rows.slice(-10) : [];
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
  const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
  if (data.length === 0) {
    renderEmpty(host, "暂无排行数据");
    return;
  }
  host.replaceChildren(...data.map((row, index) => {
    const item = document.createElement("div");
    item.className = "rank-row";
    const rank = createText("span", "rank-index", String(index + 1).padStart(2, "0"));
    const name = createText("strong", "rank-name", row.name || row.model || row.provider || row.api_key_name || row.id || `项目 ${index + 1}`);
    const meta = createText("small", "rank-meta", `${formatNumber(row.total_tokens)} Token · ${formatNumber(row.error_count)} 错误`);
    const value = createText("span", "rank-value", formatNumber(row.request_count));
    item.append(rank, name, meta, value);
    return item;
  }));
}

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
    const title = createText("strong", "security-title", event.action || event.rule_name || event.client_ip || event.policy_name || "安全事件");
    const meta = createText("small", "security-meta", event.client_ip || event.pattern || event.target || "系统策略");
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
  renderTrendBars(trends.data || trends || []);
  renderRankingList(rankings.data || rankings || []);
  renderSecurityPanel(security);
  renderPerformancePanel(performance);
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
