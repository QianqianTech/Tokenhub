import { localize } from "/admin/assets/js/i18n.js";
// TokenHub APP sign-in state.
//
// - The one-time link (…/app/link#c=thl_…) is exchanged for a device
//   credential and a short-lived bearer access token.
// - The device credential lives where page scripts cannot read it: an
//   HttpOnly cookie (PWA) or the OS keychain of a native shell
//   (window.TokenHubNative, see native/README.md).
// - The access token lives only in memory. After a crash, a kill by the OS or a
//   reboot the APP simply refreshes it: no new sign-in.
import { setAuthProvider } from "/admin/assets/js/api.js";

const state = {
  token: "",
  expiresAt: 0,
  user: null,
  device: null,
  insecure: false,
  inflight: null,
  renewTimer: 0,
  pending: null
};
const listeners = new Set();
let stepUpHandler = async () => false;

export function nativeBridge() {
  const bridge = window.TokenHubNative;
  return bridge && typeof bridge.getRefreshToken === "function" && typeof bridge.setRefreshToken === "function" ? bridge : null;
}

export function platform() {
  const bridge = nativeBridge();
  if (bridge?.platform) {
    return String(bridge.platform);
  }
  const ua = navigator.userAgent || "";
  if (/iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return "ios";
  if (/Android/.test(ua)) return "android";
  if (/Mac OS X|Macintosh/.test(ua)) return "macos";
  if (/Windows/.test(ua)) return "windows";
  if (/Linux/.test(ua)) return "linux";
  return "web";
}

function deviceName() {
  const ua = navigator.userAgent || "";
  const os = { ios: /iPad/.test(ua) ? "iPad" : "iPhone", android: "Android", macos: "Mac", windows: "Windows", linux: "Linux" }[platform()] || localize("浏览器");
  const browser = /Edg\//.test(ua) ? "Edge" : /Chrome\//.test(ua) ? "Chrome" : /Firefox\//.test(ua) ? "Firefox" : /Safari\//.test(ua) ? "Safari" : "";
  const standalone = window.matchMedia?.("(display-mode: standalone)").matches || window.navigator.standalone === true;
  return [os, standalone ? "APP" : browser].filter(Boolean).join(" · ");
}

export function onAuthChange(listener) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

function emit(event, detail) {
  for (const listener of listeners) {
    try {
      listener(event, detail);
    } catch {
      // a listener must not break sign-in
    }
  }
}

export function currentUser() {
  return state.user;
}

export function currentDevice() {
  return state.device;
}

export function insecureTransport() {
  return state.insecure || !window.isSecureContext;
}

/** Details while this device waits for approval in the web console, else null. */
export function pendingApproval() {
  return state.pending || null;
}

async function markPending(details) {
  state.pending = {
    matchCode: String(details?.match_code || ""),
    expiresAt: String(details?.approval_expires_at || ""),
    username: String(details?.username || state.pending?.username || ""),
    receivedAt: state.pending?.receivedAt || Date.now()
  };
  emit("pending", state.pending);
}

export function signedIn() {
  return Boolean(state.token);
}

export function setStepUpHandler(handler) {
  stepUpHandler = typeof handler === "function" ? handler : stepUpHandler;
}

async function post(path, body = {}, headers = {}) {
  let response;
  try {
    response = await fetch(path, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json", ...headers },
      body: JSON.stringify(body)
    });
  } catch {
    const error = new Error(localize("网络连接失败"));
    error.code = "NETWORK_ERROR";
    error.status = 0;
    throw error;
  }
  const payload = await response.json().catch(() => ({ ok: false, error: { code: "BAD_RESPONSE", message: localize("服务返回格式异常") } }));
  if (!response.ok || payload.ok === false) {
    const error = new Error(payload?.error?.message || localize("请求失败"));
    error.code = payload?.error?.code || "REQUEST_FAILED";
    error.status = response.status;
    error.details = payload?.error?.details;
    throw error;
  }
  return payload.data;
}

async function accept(data) {
  state.pending = null;
  state.token = String(data.access_token || "");
  state.expiresAt = Date.now() + Number(data.expires_in || 3600) * 1000;
  state.user = data.user || state.user;
  state.device = data.device || state.device;
  state.insecure = Boolean(data.insecure_transport);
  const bridge = nativeBridge();
  if (bridge && data.refresh_token) {
    await bridge.setRefreshToken(String(data.refresh_token));
  }
  scheduleRenewal();
  emit("signed-in", { user: state.user, device: state.device });
  if (data.recovered === true) {
    // The server restored the sign-in after a lost refresh response (same
    // client, same network). Shown once: the flag is only on that response.
    emit("recovered", { device: state.device });
  }
}

async function clearLocal(reason) {
  const wasSignedIn = Boolean(state.token);
  const wasPending = Boolean(state.pending);
  state.pending = null;
  state.token = "";
  state.expiresAt = 0;
  state.user = null;
  state.device = null;
  window.clearTimeout(state.renewTimer);
  const bridge = nativeBridge();
  if (bridge && typeof bridge.clearRefreshToken === "function") {
    await bridge.clearRefreshToken().catch(() => undefined);
  }
  emit("signed-out", { code: reason?.code || "", status: reason?.status || 0, wasSignedIn, wasPending });
}

/** Extracts the one-time code from a pasted link or bare code. */
export function parseLink(input) {
  const text = String(input || "").trim();
  if (/^thl_[0-9a-f]{64}$/.test(text)) {
    return { code: text, origin: window.location.origin };
  }
  try {
    const url = new URL(text);
    const code = new URLSearchParams(url.hash.replace(/^#/, "")).get("c") || "";
    if (/^thl_[0-9a-f]{64}$/.test(code)) {
      return { code, origin: url.origin };
    }
  } catch {
    // not a URL
  }
  return null;
}

export async function exchangeLink(code) {
  const bridge = nativeBridge();
  const data = await post("/admin-api/app-auth/exchange", {
    code,
    client: bridge ? "native" : "pwa",
    device: { platform: platform(), name: deviceName() }
  });
  if (data?.pending) {
    // New-device approval: keep the credential, wait for the web console.
    const bridge = nativeBridge();
    if (bridge && data.refresh_token) {
      await bridge.setRefreshToken(String(data.refresh_token));
    }
    await markPending(data);
    return data;
  }
  await accept(data);
  return data;
}

// One refresh at a time across every tab of this browser (Web Locks), so
// parallel tabs never present the same rotating credential twice.
function withRefreshLock(task) {
  if (navigator.locks?.request) {
    return navigator.locks.request("tokenhub-app-refresh", task);
  }
  // Plain HTTP (no Web Locks): spread tabs out; the server's grace period
  // covers the rest.
  return new Promise((resolve) => window.setTimeout(resolve, Math.random() * 400)).then(task);
}

/**
 * Renews the access token with the device credential.
 * Resolves true when signed in, false when the device is signed out;
 * throws on network errors (the APP stays as it is and retries later).
 */
export function refresh() {
  if (state.inflight) {
    return state.inflight;
  }
  state.inflight = withRefreshLock(async () => {
    const body = {};
    const bridge = nativeBridge();
    if (bridge) {
      const stored = await bridge.getRefreshToken();
      if (!stored) {
        await clearLocal({ code: "APP_DEVICE_INVALID" });
        return false;
      }
      body.refresh_token = stored;
    }
    try {
      await accept(await post("/admin-api/app-auth/refresh", body));
      return true;
    } catch (error) {
      if (error.status === 401) {
        await clearLocal(error);
        return false;
      }
      if (error.code === "APP_DEVICE_PENDING") {
        await markPending(error.details || {});
        return false;
      }
      throw error;
    }
  }).finally(() => {
    state.inflight = null;
  });
  return state.inflight;
}

function scheduleRenewal() {
  window.clearTimeout(state.renewTimer);
  const delay = Math.max(10_000, state.expiresAt - Date.now() - 120_000);
  state.renewTimer = window.setTimeout(() => {
    if (document.visibilityState === "visible") {
      refresh().catch(() => undefined);
    }
  }, delay);
}

document.addEventListener("visibilitychange", () => {
  if (document.visibilityState === "visible" && state.token && state.expiresAt - Date.now() < 180_000) {
    refresh().catch(() => undefined);
  }
});

/** Stops waiting for approval on this device (the pending device expires on the server). */
export async function cancelPending() {
  await clearLocal({ code: "LOGGED_OUT" });
}

export async function logout() {
  try {
    await post("/admin-api/app-auth/logout", {}, state.token ? { Authorization: `Bearer ${state.token}` } : {});
  } finally {
    await clearLocal({ code: "LOGGED_OUT" });
  }
}

export function authHeaders() {
  return state.token ? { Authorization: `Bearer ${state.token}` } : {};
}

setAuthProvider({
  headers: authHeaders,
  refresh: () => refresh().catch(() => false),
  stepUp: (error) => stepUpHandler(error)
});
