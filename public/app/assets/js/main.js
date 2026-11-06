import "./native-bridge.js";
import { getLocale, initLocaleControls, localize, t } from "/admin/assets/js/i18n.js";
// TokenHub APP shell: sign-in by link, bottom tab bar (side bar on wide
// screens), and the console's own pages rendered inside the APP.
import { adminErrorMessage, api, configureApi, setRouteSignal } from "/admin/assets/js/api.js";
import { el } from "/admin/assets/js/dom.js";
import { setCurrentUser } from "/admin/assets/js/core/session.js";
import { renderPage, preloadPage } from "/admin/assets/js/core/registry.js";
import { buildHash, parseHash, replaceHash, withoutTransientFilters } from "/admin/assets/js/core/router.js";
import { adminDomains, adminHash, domainForPage, domainsForUser, pageAllowed } from "/admin/assets/js/domains.js";
import { resources } from "/admin/assets/js/resources.js";
import { cancelPending, currentUser, exchangeLink, insecureTransport, onAuthChange, parseLink, pendingApproval, refresh, setStepUpHandler, signedIn } from "./auth.js";
import { renderCaGuide } from "./ca.js";
import { enableCardTables } from "./cards.js";
import { installLock } from "./lock.js";
import { renderMe } from "./me.js";
import { renderOverview } from "./overview.js";
import { onRealtime, startRealtime, stopRealtime } from "./realtime.js";
import { toast } from "/admin/assets/js/components/feedback.js";
import { confirmDialog } from "/admin/assets/js/components/modal.js";
import { discardUnsavedChanges, hasUnsavedChanges } from "/admin/assets/js/lib/dirty-guard.js";
import { valueLabel } from "/admin/assets/js/resources.js";
import { stopAllPollers } from "./poller.js";
import { passkeySupported } from "./passkey.js";
import { requestPin } from "./stepup.js";
import { applyTheme } from "./theme.js";

const $ = (id) => document.getElementById(id);
const shell = $("app");
const content = $("content");
const tabbar = $("tha-tabbar");
const LOG_PAGES = ["logs", "usage-records", "audit-logs", "content-archive"];
const EXTRA_LABELS = { "operations-status": localize("运行状态"), "rag-settings": localize("RAG 设置") };
let cleanup = () => {};
let routeController = null;
let installPromptEvent = null;
let alertCount = 0;
let welcomeMessage = "";

applyTheme();

function pageLabel(key) {
  return resources[key]?.label || EXTRA_LABELS[key] || key;
}

function setTitle(text) {
  $("page-title").textContent = text;
  document.title = `${text} · TokenHub`;
}

let updatedNode = null;
function setUpdated(text) {
  if (updatedNode) {
    updatedNode.textContent = text ? localize`数据更新于 ${text}` : "";
  }
}

// ------------------------------------------------------------------ banners

function banner(key, text, action = null, tone = "info") {
  const host = $("tha-banners");
  host.querySelector(`[data-banner="${key}"]`)?.remove();
  if (!text) {
    return;
  }
  host.append(el("div", { className: `tha-banner tha-banner--${tone}`, dataset: { banner: key }, role: "status" }, [el("span", { text }), action]));
}

function refreshNetworkState() {
  $("tha-net").hidden = navigator.onLine;
}
window.addEventListener("online", refreshNetworkState);
window.addEventListener("offline", refreshNetworkState);

// ------------------------------------------------------------------ tab bar

function tabs(user) {
  const items = [{ key: "overview", label: localize("概览"), href: "#/overview", icon: "M4 13h6V4H4zm0 7h6v-5H4zm10 0h6v-9h-6zm0-16v5h6V4z" }];
  const alertsHash = pageAllowed(user, "alert-events") ? adminHash(user, "alert-events") : null;
  if (alertsHash) {
    items.push({ key: "alerts", label: localize("告警"), href: alertsHash, icon: "M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0", badge: true });
  }
  items.push({ key: "manage", label: localize("管理"), href: "#/manage", icon: "M4 6h16M4 12h16M4 18h16" });
  const logPage = LOG_PAGES.find((page) => pageAllowed(user, page));
  if (logPage) {
    items.push({ key: "logs", label: localize("日志"), href: adminHash(user, logPage), icon: "M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" });
  }
  items.push({ key: "me", label: localize("我的"), href: "#/me", icon: "M20 21a8 8 0 1 0-16 0M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8" });
  return items;
}

function activeTab(route) {
  if (["overview", "manage", "me"].includes(route.special)) {
    return route.special;
  }
  if (route.tabKey === "alert-events") {
    return "alerts";
  }
  if (LOG_PAGES.includes(route.tabKey)) {
    return "logs";
  }
  return "manage";
}

function renderTabbar(user, route) {
  const current = activeTab(route);
  tabbar.replaceChildren(...tabs(user).map((item) => {
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", item.icon);
    svg.append(path);
    return el("a", {
      className: `tha-tab ${item.key === current ? "is-active" : ""}`.trim(),
      href: item.href,
      "aria-current": item.key === current ? "page" : undefined
    }, [
      svg,
      el("span", { className: "tha-tab-label", text: item.label }),
      item.badge ? el("span", { className: "tha-tab-badge", text: alertCount > 99 ? "99+" : String(alertCount), hidden: alertCount <= 0 }) : null
    ]);
  }));
  tabbar.hidden = false;
}

window.addEventListener("tokenhub-app-alert-count", (event) => {
  alertCount = Number(event.detail?.count || 0);
  tabbar.querySelectorAll(".tha-tab-badge").forEach((badge) => {
    badge.textContent = alertCount > 99 ? "99+" : String(alertCount);
    badge.hidden = alertCount <= 0;
  });
});

// ------------------------------------------------------------------ routes

function currentRoute() {
  const raw = window.location.hash.replace(/^#\/?/, "");
  const special = raw.split(/[/?]/)[0];
  if (["", "overview", "manage", "me", "ca", "welcome"].includes(special)) {
    return { special: special || "overview" };
  }
  return { special: "", ...parseHash() };
}

function navigateToPage(page, filters = {}) {
  const hash = adminHash(currentUser(), page, filters);
  if (hash) {
    window.location.hash = hash;
  }
}

async function renderRoute() {
  cleanup();
  cleanup = () => {};
  stopAllPollers();
  routeController?.abort();
  routeController = new AbortController();
  setRouteSignal(routeController.signal);
  updatedNode = null;
  const route = currentRoute();

  if (route.special === "ca") {
    setTitle(localize("证书安装指引"));
    if (signedIn()) {
      renderTabbar(currentUser(), route);
    }
    await renderCaGuide(content);
    return;
  }
  if (!signedIn()) {
    if (pendingApproval()) {
      renderPending();
      return;
    }
    renderWelcome();
    return;
  }
  const user = currentUser();
  renderTabbar(user, route);
  shell.dataset.state = "ready";

  if (route.special === "overview" || route.special === "welcome") {
    setTitle(localize("概览"));
    updatedNode = el("p", { className: "tha-updated", "aria-live": "off" });
    const host = el("div");
    content.replaceChildren(updatedNode, host);
    cleanup = renderOverview(host, { user, setUpdated }) || cleanup;
    return;
  }
  if (route.special === "manage") {
    setTitle(localize("管理"));
    renderManage(user);
    return;
  }
  if (route.special === "me") {
    setTitle(localize("我的"));
    await renderMe(content, { installPrompt: () => installPromptEvent, onSignedOut: () => window.location.replace("/app/#/welcome") });
    return;
  }

  // A console page inside the APP.
  const domain = domainForPage(user, route.tabKey);
  if (!domain || !pageAllowed(user, route.tabKey)) {
    setTitle(localize("无法打开"));
    content.replaceChildren(el("div", { className: "tha-card" }, [el("p", { text: localize("当前账号没有这个页面的权限，或页面不存在。") }), el("a", { className: "tha-link", href: "#/manage", text: localize("返回管理") })]));
    return;
  }
  setTitle(pageLabel(route.tabKey));
  const chips = el("nav", { className: "tha-chips", "aria-label": domain.label }, domain.tabs.map((tab) => el("a", {
    className: `tha-chip ${tab === route.tabKey ? "is-active" : ""}`.trim(),
    href: buildHash(domain.key, tab),
    text: pageLabel(tab),
    "aria-current": tab === route.tabKey ? "page" : undefined
  })));
  const panel = el("section", { className: "domain-page tha-page" });
  content.replaceChildren(chips, panel);
  cleanup = enableCardTables(panel);
  try {
    await renderPage(route.tabKey, panel, {
      filters: route.filters,
      navigate: async (page, filters = {}) => navigateToPage(page, filters),
      signal: routeController.signal,
      setFilters: (filters) => replaceHash(buildHash(domain.key, route.tabKey, withoutTransientFilters(filters)))
    });
  } catch (error) {
    if (error?.name !== "AbortError") {
      panel.replaceChildren(el("div", { className: "tha-card", dataset: { pageError: "true" } }, [el("p", { text: localize`页面加载失败：${adminErrorMessage(error)}` })]));
    }
  }
}

function renderManage(user) {
  const domains = domainsForUser(user);
  const labels = Object.fromEntries(adminDomains.map((domain) => [domain.key, domain.label]));
  content.replaceChildren(el("div", { className: "tha-manage" }, domains.map((domain) => el("section", { className: "tha-card" }, [
    el("header", { className: "tha-card-header" }, [el("h2", { text: labels[domain.key] || domain.label })]),
    el("ul", { className: "tha-menu" }, domain.tabs.map((tab) => el("li", {}, [el("a", {
      className: "tha-menu-item",
      href: buildHash(domain.key, tab),
      text: pageLabel(tab),
      onMouseenter: () => preloadPage(tab)
    })])))
  ]))));
}

// ------------------------------------------------------------------ welcome

let scanner = null;

function stopScanner() {
  scanner?.stop();
  scanner = null;
}

async function startScanner(target, onCode) {
  stopScanner();
  if (!window.isSecureContext || !("BarcodeDetector" in window) || !navigator.mediaDevices?.getUserMedia) {
    return false;
  }
  const detector = new window.BarcodeDetector({ formats: ["qr_code"] });
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
  const video = el("video", { className: "tha-scanner-video", autoplay: true, muted: true, playsInline: true });
  video.srcObject = stream;
  target.replaceChildren(video, el("p", { className: "tha-muted", text: localize("把认证链接的二维码放进取景框") }));
  let stopped = false;
  const tick = async () => {
    if (stopped) {
      return;
    }
    try {
      const codes = await detector.detect(video);
      const value = codes.map((code) => code.rawValue).find((raw) => parseLink(raw));
      if (value) {
        stopScanner();
        onCode(value);
        return;
      }
    } catch {
      // keep scanning
    }
    window.setTimeout(tick, 250);
  };
  scanner = {
    stop() {
      stopped = true;
      stream.getTracks().forEach((track) => track.stop());
      target.replaceChildren();
    }
  };
  void tick();
  return true;
}

function renderWelcome(message = welcomeMessage) {
  welcomeMessage = "";
  stopAllPollers();
  setCurrentUser(null);
  tabbar.hidden = true;
  shell.dataset.state = "welcome";
  setTitle(localize("登录 TokenHub"));
  const input = el("textarea", { className: "tha-link-input", rows: 3, placeholder: localize("粘贴认证链接，例如 https://…/app/link#c=thl_…"), "aria-label": localize("认证链接"), autocomplete: "off", spellcheck: false });
  const error = el("p", { className: "tha-error", role: "alert", text: message });
  const scanHost = el("div", { className: "tha-scanner" });
  const submit = el("button", { type: "submit", text: localize("登录") });
  const go = async (raw) => {
    error.textContent = "";
    const parsed = parseLink(raw);
    if (!parsed) {
      error.textContent = localize("这不是有效的认证链接。请在 TokenHub 后台左下角「APP 设备」中重新生成。");
      return;
    }
    if (parsed.origin !== window.location.origin) {
      error.textContent = localize`这个链接属于另一台服务器（${parsed.origin}）。请在该服务器上打开链接，或使用 TokenHub 安装包 APP。`;
      return;
    }
    submit.disabled = true;
    try {
      await exchangeLink(parsed.code);
      window.location.replace("/app/#/overview");
      await renderRoute();
    } catch (failure) {
      error.textContent = linkError(failure);
    } finally {
      submit.disabled = false;
    }
  };
  const form = el("form", { className: "tha-welcome-form" }, [input, error, submit]);
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    void go(input.value);
  });
  const canScan = window.isSecureContext && "BarcodeDetector" in window && Boolean(navigator.mediaDevices?.getUserMedia);
  // No in-page scanner (WebKit / iOS has no BarcodeDetector; HTTP has no camera):
  // say why, and point to the system camera or pasting the link.
  const scanFallback = !window.isSecureContext
    ? localize("当前为 HTTP 连接，网页无法使用相机扫码。请用手机系统相机扫描后台显示的二维码，或复制认证链接粘贴到上方。")
    : !("BarcodeDetector" in window)
      ? localize("当前浏览器不支持网页内扫码（iPhone / iPad 的浏览器和 APP 均如此）。请用手机系统相机扫描后台显示的二维码打开链接，或复制认证链接粘贴到上方。")
      : localize("当前环境无法使用相机。请用手机系统相机扫描后台显示的二维码，或复制认证链接粘贴到上方。");
  const cameraFailed = localize("无法使用相机（可能未授予相机权限）。请用手机系统相机扫描后台显示的二维码，或复制认证链接粘贴到上方。");
  const host = /^\d+\.\d+\.\d+\.\d+$|^\[/.test(window.location.hostname);
  content.replaceChildren(el("div", { className: "tha-welcome" }, [
    el("img", { className: "tha-welcome-logo", src: "/admin/assets/brand/tokenhub-app-icon.svg", alt: "", width: 72, height: 72 }),
    el("h2", { text: localize("用认证链接登录") }),
    el("ol", { className: "tha-steps" }, [
      el("li", { text: localize("在电脑上打开 TokenHub 后台，点左下角的「APP 设备」。") }),
      el("li", { text: localize("点「生成认证链接」，用手机扫码，或复制链接粘贴到下面。") }),
      el("li", { text: localize("登录后，APP 闪退或被系统关闭再打开，不需要重新登录。") })
    ]),
    form,
    canScan ? el("button", { type: "button", className: "secondary", text: localize("扫码登录"), onClick: async () => {
      try {
        if (!await startScanner(scanHost, (value) => void go(value))) {
          error.textContent = cameraFailed;
        }
      } catch {
        error.textContent = cameraFailed;
      }
    } }) : el("p", { className: "tha-muted tha-scan-fallback", role: "note", text: scanFallback }),
    scanHost,
    !window.isSecureContext ? el("p", { className: "tha-warning", text: localize("当前为 HTTP 连接：可以登录和使用，但无法安装 APP 或接收推送，设备 7 天不用会自动退出。建议管理员改用 HTTPS。") }) : null,
    host ? el("a", { className: "tha-link", href: "#/ca", text: localize("使用 https://IP 访问？先查看证书安装指引") }) : null
  ]));
}

/**
 * New-device approval (APP_NEW_DEVICE_APPROVAL): the device has its credential
 * but no access until the administrator confirms it in the web console.
 * Polls every 3 s while visible.
 */
function renderPending() {
  const pending = pendingApproval() || {};
  stopAllPollers();
  setCurrentUser(null);
  tabbar.hidden = true;
  shell.dataset.state = "pending";
  setTitle(localize("等待确认"));
  const status = el("p", { className: "tha-muted", role: "status", text: localize("正在等待确认…") });
  const code = el("p", { className: "tha-match-code", "aria-label": localize`匹配码 ${pending.matchCode || ""}`, text: pending.matchCode || "----" });
  content.replaceChildren(el("div", { className: "tha-welcome tha-pending" }, [
    el("img", { className: "tha-welcome-logo", src: "/admin/assets/brand/tokenhub-app-icon.svg", alt: "", width: 72, height: 72 }),
    el("h2", { text: localize("请在电脑上确认这台设备") }),
    el("ol", { className: "tha-steps" }, [
      el("li", { text: localize`在电脑上的 TokenHub 后台${pending.username ? localize`（账号 ${pending.username}）` : ""}打开左下角「APP 设备」。` }),
      el("li", { text: localize("找到「等待确认」的设备，核对匹配码与下面一致后点「确认」。") }),
      el("li", { text: localize("确认后这里会自动进入；10 分钟内未确认需要重新生成认证链接。") })
    ]),
    code,
    status,
    el("button", { type: "button", className: "secondary", text: localize("取消"), onClick: () => void cancelPending() })
  ]));
  let busy = false;
  const poll = async () => {
    if (busy || document.visibilityState !== "visible") {
      return;
    }
    busy = true;
    try {
      if (await refresh()) {
        window.clearInterval(timer);
        await renderRoute();
        return;
      }
      status.textContent = pendingApproval() ? localize("正在等待确认…") : "";
    } catch {
      status.textContent = localize("网络连接失败，正在重试…");
    } finally {
      busy = false;
    }
  };
  const timer = window.setInterval(() => void poll(), 3000);
  const onVisible = () => void poll();
  document.addEventListener("visibilitychange", onVisible);
  cleanup = () => {
    window.clearInterval(timer);
    document.removeEventListener("visibilitychange", onVisible);
  };
}

function linkError(error) {
  switch (error?.code) {
    case "APP_LINK_INVALID":
      return localize("认证链接无效或已过期（10 分钟内有效，且只能使用一次）。请在后台重新生成。");
    case "APP_DEVICE_LIMIT":
      return localize("该账号绑定的设备已达上限，请先在后台「APP 设备」中吊销不用的设备。");
    case "APP_LINK_IP_FORBIDDEN":
      return localize("这个认证链接不允许在当前网络使用。");
    case "APP_AUTH_RATE_LIMITED":
      return localize("尝试次数过多，请一分钟后再试。");
    case "NETWORK_ERROR":
      return localize("网络连接失败，请检查网络后重试。");
    default:
      return error?.message || localize("登录失败，请重试。");
  }
}

// ------------------------------------------------------------------ boot

function registerServiceWorker() {
  if (!("serviceWorker" in navigator) || !window.isSecureContext) {
    return;
  }
  // The first worker can claim this document without replacing loaded assets.
  // Only a replacement of an existing controller needs a page reload.
  let controlled = Boolean(navigator.serviceWorker.controller);
  navigator.serviceWorker.register("/app/sw.js", { scope: "/app/" }).then((registration) => {
    const offerUpdate = (worker) => banner("update", localize("TokenHub APP 有新版本。"), el("button", { type: "button", className: "secondary", text: localize("刷新"), onClick: () => worker.postMessage("skip-waiting") }));
    if (registration.waiting && navigator.serviceWorker.controller) {
      offerUpdate(registration.waiting);
    }
    registration.addEventListener("updatefound", () => {
      const worker = registration.installing;
      worker?.addEventListener("statechange", () => {
        if (worker.state === "installed" && navigator.serviceWorker.controller) {
          offerUpdate(worker);
        }
      });
    });
  }).catch(() => undefined);
  let reloading = false;
  navigator.serviceWorker.addEventListener("controllerchange", () => {
    const replacing = controlled;
    controlled = Boolean(navigator.serviceWorker.controller);
    if (replacing && controlled && !reloading) {
      reloading = true;
      window.location.reload();
    }
  });
  navigator.serviceWorker.addEventListener("message", (event) => {
    if (event.data?.type === "navigate" && typeof event.data.url === "string" && event.data.url.startsWith("/app/")) {
      window.location.assign(event.data.url);
    }
  });
}

window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  installPromptEvent = event;
});

async function boot() {
  if (getLocale() === "en-US") {
    document.querySelector('link[rel="manifest"]').href = "/app/manifest.en-US.webmanifest";
  }
  initLocaleControls({ beforeChange: async () => {
    if (shell.dataset.state === "booting" || shell.classList.contains("is-locked")) return false;
    if (hasUnsavedChanges()) {
      const leave = await confirmDialog({
        title: t("guard.leaveTitle"),
        message: t("guard.leaveMessage"),
        confirmLabel: t("guard.leaveConfirm"),
        cancelLabel: t("modal.keepEditing")
      });
      if (!leave) return false;
      discardUnsavedChanges();
    }
    return true;
  } });
  refreshNetworkState();
  registerServiceWorker();
  configureApi({
    onAuthRequired: () => {
      if (!signedIn()) {
        renderWelcome(localize("登录已失效，请重新打开认证链接。"));
      }
    },
    onError: (error) => {
      if (error?.code !== "ADMIN_STEP_UP_REQUIRED") {
        $("global-error").replaceChildren(el("p", { className: "tha-error", text: adminErrorMessage(error) }));
        window.setTimeout(() => $("global-error").replaceChildren(), 6000);
      }
    }
  });
  setStepUpHandler(async (error) => {
    let passkey = false;
    if (passkeySupported()) {
      try {
        const { data } = await api("/admin-api/app-auth/status", { silent: true });
        passkey = Boolean(data?.passkeys?.available && data.passkeys.count > 0);
      } catch {
        passkey = false;
      }
    }
    return requestPin({ operation: error?.details?.operation || "", pinSet: error?.details?.pin_set !== false || passkey, passkey });
  });
  // Live updates: alert badge, "new alert" notice and the live indicator.
  onRealtime((type, data) => {
    if (type === "status") {
      $("tha-live").hidden = !data.connected;
      return;
    }
    if (type !== "alerts") {
      return;
    }
    window.dispatchEvent(new CustomEvent("tokenhub-app-alert-count", { detail: { count: Number(data.count || 0) } }));
    const fresh = (data.latest || []).filter((row) => (data.new_ids || []).includes(row.id));
    if (fresh.length > 0) {
      const first = fresh[0];
      const user = currentUser();
      const hash = user && pageAllowed(user, "alert-events") ? adminHash(user, "alert-events") : null;
      toast(localize`新告警（${valueLabel("severity", first.severity)}）：${first.message || first.event_type}${fresh.length > 1 ? localize` 等 ${fresh.length} 条` : ""}`, { tone: first.severity === "critical" ? "error" : "info", timeout: 10_000 });
      if (hash && window.location.hash !== hash) {
        banner("new-alert", localize`有 ${fresh.length} 条新告警`, el("a", { className: "tha-link", href: hash, text: localize("查看"), onClick: () => banner("new-alert", "") }), first.severity === "critical" ? "warning" : "info");
      }
      navigator.vibrate?.(first.severity === "critical" ? [120, 60, 120] : 80);
      if (document.visibilityState === "hidden") {
        void window.TokenHubNative?.notify?.(localize("TokenHub 新告警"), `${valueLabel("severity", first.severity)}: ${first.message || first.event_type}`);
      }
    }
  });
  onAuthChange((event, detail) => {
    if (event === "recovered") {
      toast(localize("登录状态已自动恢复。如果不是你本人操作，请在“我的 → 设备”中吊销这台设备。"), { tone: "info", timeout: 10_000 });
    }
    if (event === "signed-in") {
      setCurrentUser(detail.user);
      startRealtime();
      void api("/admin-api/app-auth/push/locale", { method: "PUT", body: JSON.stringify({ locale: getLocale() }), silent: true })
        .catch(() => undefined);
      banner("insecure", insecureTransport() ? localize("当前为 HTTP 连接，建议管理员改用 HTTPS。") : "", null, "warning");
    }
    if (event === "signed-out") {
      setCurrentUser(null);
      stopRealtime();
      welcomeMessage = detail?.code === "APP_DEVICE_REJECTED"
        ? localize("这台设备的登录请求已在后台被拒绝。")
        : detail?.code === "APP_DEVICE_APPROVAL_EXPIRED"
          ? localize("等待确认超时，请在后台重新生成认证链接。")
          : detail?.wasSignedIn && detail?.code !== "LOGGED_OUT" ? localize("这台设备已退出登录，请重新打开认证链接。") : "";
      if (shell.dataset.state !== "booting") {
        void renderRoute();
      }
    }
  });
  const lock = installLock({ isSignedIn: signedIn, onFailed: () => undefined });

  // /app/link#c=thl_… — exchange the one-time code, then drop it from the URL.
  const fromLink = /\/app\/link\/?$/.test(window.location.pathname) ? new URLSearchParams(window.location.hash.replace(/^#/, "")).get("c") : null;
  if (fromLink !== null) {
    window.history.replaceState(null, "", "/app/#/overview");
    try {
      await exchangeLink(fromLink);
    } catch (error) {
      // An already linked device keeps working; otherwise show why.
      const stillSignedIn = await refresh().catch(() => false);
      if (!stillSignedIn) {
        renderWelcome(linkError(error));
        window.addEventListener("hashchange", () => void renderRoute());
        return;
      }
      banner("link", linkError(error), null, "warning");
    }
  } else {
    try {
      await refresh();
    } catch {
      // Offline at start: keep trying when the network returns.
      renderWelcome(localize("网络连接失败，联网后会自动重试。"));
      window.addEventListener("online", () => void refresh().then(() => renderRoute()).catch(() => undefined), { once: true });
    }
  }
  window.addEventListener("hashchange", () => void renderRoute());
  window.addEventListener("tokenhub-admin-navigate", (event) => {
    const detail = event instanceof CustomEvent ? event.detail || {} : {};
    if (typeof detail.page === "string") {
      navigateToPage(detail.page, detail.filters || {});
    }
  });
  if (shell.dataset.state === "booting" || signedIn()) {
    await renderRoute();
  }
  if (signedIn()) {
    lock.onColdStart();
  }
}

void boot();
