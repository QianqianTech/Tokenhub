// In-memory TokenHub APP sign-in for the mock server: one-time link codes,
// rotating device credentials (cookie), bearer access tokens, PIN step-up.
import { randomBytes } from "node:crypto";

const hex = () => randomBytes(32).toString("hex");
const codes = new Map(); // code -> user
const devices = new Map(); // id -> { id, user, refresh, prev, status, pin, name, platform }
const access = new Map(); // token -> { user, deviceId, stepUpAt }
let nextDevice = 1;

// recoverNext: the next refresh with the previous credential is a lost-response recovery (SEC-005).
export const appState = { codes, devices, access, approval: false, recoverNext: false };

/** New-device approval (APP_NEW_DEVICE_APPROVAL) on or off. */
export function setApproval(on) {
  appState.approval = Boolean(on);
}

export function issueLinkCode(user) {
  const code = `thl_${hex()}`;
  codes.set(code, user);
  return code;
}

// Open live-update streams (GET /admin-api/app-auth/stream).
const streams = new Set();
let alertSeq = 100;
const liveAlerts = [];

export function pushLiveAlert({ severity = "critical", message = "上游厂商连续超时" } = {}) {
  const alert = { id: ++alertSeq, event_type: "provider_error_rate_high", severity, message, created_at: "2026-10-30 12:00:00" };
  liveAlerts.unshift(alert);
  for (const res of streams) {
    res.write(`event: alerts\ndata: ${JSON.stringify({ count: liveAlerts.length, latest: liveAlerts.slice(0, 3), new_ids: [alert.id] })}\n\n`);
  }
  return alert;
}

export function liveStreamCount() {
  return streams.size;
}

export function expireAccessTokens() {
  access.clear();
}

export function resetApp() {
  for (const res of streams) res.end();
  streams.clear();
  liveAlerts.length = 0;
  codes.clear();
  devices.clear();
  access.clear();
  nextDevice = 1;
  appState.approval = false;
  appState.recoverNext = false;
}

function cookieValue(req, name) {
  const match = (req.headers.cookie || "").match(new RegExp(`${name}=([^;]+)`));
  return match ? decodeURIComponent(match[1]) : "";
}

function bearer(req) {
  const match = String(req.headers.authorization || "").match(/^Bearer\s+(.+)$/i);
  return match ? match[1].trim() : "";
}

export function appBearerSession(req) {
  const token = bearer(req);
  if (!token) return null;
  const session = access.get(token);
  if (!session || devices.get(session.deviceId)?.status !== "active") return { invalid: true };
  return session;
}

function issue(device) {
  const token = `tha_${hex()}`;
  access.set(token, { user: device.user, deviceId: device.id, stepUpAt: 0 });
  return { access_token: token, token_type: "Bearer", expires_in: 3600, device: { id: device.id, name: device.name, platform: device.platform, client_type: "pwa", bound_over_http: true, pin_set: Boolean(device.pin) }, user: device.user, insecure_transport: false };
}

const cookie = (value, maxAge = 86400 * 30) => `tokenhub_app_device=${value}; Path=/admin-api/app-auth; HttpOnly; SameSite=Strict; Max-Age=${maxAge}`;

const SENSITIVE = [["DELETE", /^\/admin-api\/api-keys\/[^/]+$/, "删除 API 密钥"], ["POST", /^\/admin-api\/quota-adjustments$/, "调整额度"]];

/**
 * Handles /admin-api/app-auth/* and /admin-api/my/app-devices*; returns true
 * when the request was answered. `session` is the bearer session (if any).
 */
export async function handleAppAuth({ req, res, path, method, readBody, ok, fail, json, consoleUser }) {
  if (path === "/admin-api/app-auth/exchange" && method === "POST") {
    const body = await readBody(req);
    const user = codes.get(body.code);
    if (!user) return fail(res, 401, "APP_LINK_INVALID", "The sign-in link is invalid or has expired"), true;
    codes.delete(body.code);
    if (appState.approval) {
      const device = { id: nextDevice++, user, refresh: `thr_${hex()}`, prev: "", status: "pending", approvalCode: String(1000 + Math.floor(Math.random() * 9000)), pin: "", name: body.device?.name || "APP", platform: body.device?.platform || "web" };
      devices.set(device.id, device);
      ok(res, { pending: true, match_code: device.approvalCode, approval_expires_at: "2026-10-30 10:10:00", username: user.username, device: { id: device.id, name: device.name, platform: device.platform, status: "pending" }, insecure_transport: false }, 202, { "Set-Cookie": cookie(device.refresh) });
      return true;
    }
    const previous = [...devices.values()].find((item) => item.refresh === cookieValue(req, "tokenhub_app_device"));
    if (previous) previous.status = "revoked";
    const device = { id: nextDevice++, user, refresh: `thr_${hex()}`, prev: "", status: "active", pin: "", name: body.device?.name || "APP", platform: body.device?.platform || "web" };
    devices.set(device.id, device);
    ok(res, issue(device), 200, { "Set-Cookie": cookie(device.refresh) });
    return true;
  }
  if (path === "/admin-api/app-auth/refresh" && method === "POST") {
    const presented = cookieValue(req, "tokenhub_app_device");
    const waiting = [...devices.values()].find((item) => item.refresh === presented && (item.status === "pending" || item.status === "rejected"));
    if (waiting?.status === "pending") {
      json(res, 409, { ok: false, error: { code: "APP_DEVICE_PENDING", message: "waiting for approval", details: { pending: true, match_code: waiting.approvalCode, approval_expires_at: "2026-10-30 10:10:00" } } });
      return true;
    }
    if (waiting?.status === "rejected") {
      json(res, 401, { ok: false, error: { code: "APP_DEVICE_REJECTED", message: "rejected" } }, { "Set-Cookie": cookie("", 0) });
      return true;
    }
    const device = [...devices.values()].find((item) => item.status === "active" && (item.refresh === presented || item.prev === presented));
    if (!device) {
      json(res, 401, { ok: false, error: { code: "APP_DEVICE_INVALID", message: "signed out" } }, { "Set-Cookie": cookie("", 0) });
      return true;
    }
    if (device.prev === presented && appState.recoverNext) {
      appState.recoverNext = false;
      device.prev = "";
      device.refresh = `thr_${hex()}`;
      ok(res, { ...issue(device), recovered: true }, 200, { "Set-Cookie": cookie(device.refresh) });
    } else if (device.refresh === presented) {
      device.prev = device.refresh;
      device.refresh = `thr_${hex()}`;
      ok(res, issue(device), 200, { "Set-Cookie": cookie(device.refresh) });
    } else {
      ok(res, issue(device));
    }
    return true;
  }
  if (!path.startsWith("/admin-api/app-auth/") && !path.startsWith("/admin-api/my/app-devices")) {
    return false;
  }
  const session = appBearerSession(req);
  if (path === "/admin-api/my/app-devices" && method === "GET") {
    const user = session?.user || consoleUser;
    if (!user) return fail(res, 401, "ADMIN_UNAUTHENTICATED", "Admin login required"), true;
    const list = [...devices.values()].filter((item) => (item.status === "active" || item.status === "pending") && item.user.id === user.id)
      .map((item) => ({ id: item.id, name: item.name, platform: item.platform, client_type: "pwa", bound_over_http: false, last_seen_at: "2026-10-30 10:00:00", created_at: "2026-10-30 09:00:00", current: item.id === session?.deviceId, pending: item.status === "pending", ...(item.status === "pending" && !session ? { approval_code: item.approvalCode } : {}) }));
    ok(res, { devices: list, approval_required: appState.approval, max_devices: 5, current_device_id: session?.deviceId ?? null, link_ttl_minutes: 10, secure_transport: true });
    return true;
  }
  const decision = path.match(/^\/admin-api\/my\/app-devices\/(\d+)\/(approve|reject)$/);
  if (decision && method === "POST") {
    if (!consoleUser || session) return fail(res, 403, "APP_APPROVAL_CONSOLE_ONLY", "console only"), true;
    const target = devices.get(Number(decision[1]));
    if (!target || target.user.id !== consoleUser.id) return fail(res, 404, "ADMIN_NOT_FOUND", "APP device not found"), true;
    if (target.status !== "pending") return fail(res, 409, "APP_DEVICE_NOT_PENDING", "not pending"), true;
    target.status = decision[2] === "approve" ? "active" : "rejected";
    ok(res, decision[2] === "approve" ? { id: target.id, approved: true } : { id: target.id, rejected: true });
    return true;
  }
  if (path === "/admin-api/my/app-devices/links" && method === "POST") {
    if (!consoleUser) return fail(res, 403, "APP_LINK_CONSOLE_ONLY", "console only"), true;
    const code = issueLinkCode(consoleUser);
    ok(res, { id: 1, code, path: `/app/link#c=${code}`, link: `/app/link#c=${code}`, expires_at: "2026-10-30 10:10:00", ttl_seconds: 600 }, 201);
    return true;
  }
  if (!session || session.invalid) return fail(res, 401, "ADMIN_UNAUTHENTICATED", "Admin login required"), true;
  const device = devices.get(session.deviceId);
  if (path === "/admin-api/app-auth/stream") {
    res.writeHead(200, { "Content-Type": "text/event-stream", "Cache-Control": "no-cache" });
    res.write("retry: 3000\n\n");
    res.write(`event: ready\ndata: ${JSON.stringify({ alerts: true, usage: true, health: true, seconds: 55 })}\n\n`);
    res.write(`event: alerts\ndata: ${JSON.stringify({ count: liveAlerts.length, latest: liveAlerts.slice(0, 3), new_ids: [] })}\n\n`);
    res.write(`event: usage\ndata: ${JSON.stringify({ today: { request_count: 4321, success_count: 4300, failed_count: 21, total_tokens: 987654, total_cost: "88.5", avg_latency_ms: 900 } })}\n\n`);
    res.write(`event: health\ndata: ${JSON.stringify({ health: { healthy: false, services: { php: { healthy: true }, mysql: { healthy: true }, redis: { healthy: true }, qdrant: { healthy: true }, "queue-worker": { healthy: true }, "rag-worker": { healthy: false } } }, queues: { pending: 4, processing: 1, failed: 0, readable: true }, usage_pipeline: { unaggregated: 12, events_pending: 0 } })}\n\n`);
    streams.add(res);
    req.on("close", () => streams.delete(res));
    return true;
  }
  if (path === "/admin-api/app-auth/status") {
    ok(res, { device_id: device.id, pin_set: Boolean(device.pin), step_up_required: true, step_up_fresh: Date.now() - session.stepUpAt < 300000, step_up_window_seconds: 300, sensitive_operations: ["删除 API 密钥"], push: { available: true, subscribed: false, native: { ios: false, android: false }, preferences: { min_severity: "warning", quiet_start: null, quiet_end: null, time_zone: null } }, passkeys: { available: false, count: 0 }, secure_transport: true, session_expires_at: "2026-10-30 11:00:00" });
    return true;
  }
  if (path === "/admin-api/app-auth/pin" && method === "POST") {
    const body = await readBody(req);
    if (device.pin && body.current_pin !== device.pin) return json(res, 403, { ok: false, error: { code: "APP_PIN_INVALID", message: "Wrong PIN", details: { remaining: 4 } } }), true;
    device.pin = String(body.pin || "");
    session.stepUpAt = Date.now();
    ok(res, { ok: true, pin_set: true });
    return true;
  }
  if (path === "/admin-api/app-auth/step-up" && method === "POST") {
    const body = await readBody(req);
    if (!device.pin) return json(res, 409, { ok: false, error: { code: "APP_PIN_NOT_SET", message: "Set a PIN", details: { pin_set: false } } }), true;
    if (String(body.pin) !== device.pin) return json(res, 403, { ok: false, error: { code: "APP_PIN_INVALID", message: "Wrong PIN", details: { remaining: 4 } } }), true;
    session.stepUpAt = Date.now();
    ok(res, { ok: true, step_up_window_seconds: 300 });
    return true;
  }
  if (path === "/admin-api/app-auth/logout" && method === "POST") {
    device.status = "revoked";
    ok(res, { logged_out: true }, 200, { "Set-Cookie": cookie("", 0) });
    return true;
  }
  if (path === "/admin-api/app-auth/push/preferences" && method === "PUT") {
    const body = await readBody(req);
    ok(res, { min_severity: body.min_severity || "warning", quiet_start: body.quiet_start || null, quiet_end: body.quiet_end || null, time_zone: body.time_zone || null });
    return true;
  }
  if (path === "/admin-api/app-auth/push/locale" && method === "PUT") {
    const { locale } = await readBody(req);
    device.locale = locale === "en-US" ? "en-US" : "zh-CN";
    ok(res, { locale: device.locale });
    return true;
  }
  if (path === "/admin-api/app-auth/push/native" && method === "POST") {
    const body = await readBody(req);
    ok(res, { subscribed: ["ios", "android"].includes(body.platform) && Boolean(body.token), platform: body.platform });
    return true;
  }
  if (path === "/admin-api/app-auth/push/key") {
    ok(res, { public_key: "BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U" });
    return true;
  }
  return false;
}

/** 403 ADMIN_STEP_UP_REQUIRED for sensitive writes without a fresh PIN check. */
export function stepUpChallenge(session, method, path) {
  if (!session || session.invalid) return null;
  const rule = SENSITIVE.find(([ruleMethod, pattern]) => ruleMethod === method && pattern.test(path));
  if (!rule || Date.now() - session.stepUpAt < 300000) return null;
  const device = devices.get(session.deviceId);
  return { code: "ADMIN_STEP_UP_REQUIRED", message: "Verify with your APP PIN to continue", details: { operation: rule[2], pin_set: Boolean(device?.pin), window_seconds: 300 } };
}
