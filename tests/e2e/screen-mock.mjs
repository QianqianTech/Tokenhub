// In-memory mock of /screen-api for the operations screen (public/screen), served the
// way Caddy does: /screen and /screen/* fall back to /screen/index.html. Kept apart
// from mock-server.mjs (admin API) so the two can evolve independently.
//
//   node screen-mock.mjs 8091   then open http://127.0.0.1:8091/screen/GoodCode7Q2xLm9P
import { createServer } from "node:http";
import { readFile, stat } from "node:fs/promises";
import { extname, join, normalize } from "node:path";
import { fileURLToPath } from "node:url";
import { randomBytes } from "node:crypto";

const root = fileURLToPath(new URL("../../public/", import.meta.url));
const MIME = { ".html": "text/html; charset=utf-8", ".js": "text/javascript; charset=utf-8", ".css": "text/css; charset=utf-8", ".svg": "image/svg+xml", ".png": "image/png", ".ico": "image/x-icon" };

export const GOOD_CODE = "GoodCode7Q2xLm9P";
export const LIMITED_CODE = "Limited7Q2xLm9Pz";
export const BUSINESS_TZ = "Asia/Shanghai";

const SHARE = { id: 1, share_code: GOOD_CODE, name: "Lobby", username: "lobby", scope_type: "global", screen: true };

// UTC "Y-m-d H:i:s" like the aggregate tables.
function sqlUtc(ms) {
  return new Date(ms).toISOString().slice(0, 19).replace("T", " ");
}

// Start of "today" in Asia/Shanghai (UTC+8, no DST), as epoch ms.
export function shanghaiDayStart(now = Date.now()) {
  const local = new Date(now + 8 * 3600000);
  return Date.UTC(local.getUTCFullYear(), local.getUTCMonth(), local.getUTCDate()) - 8 * 3600000;
}

function fixtures() {
  const now = Date.now();
  const dayStart = shanghaiDayStart(now);
  const generated = new Date(now).toISOString();
  return {
    overview: {
      totals: { request_count: 1234, success_count: 1200, failed_count: 34, input_tokens: 5_000_000, output_tokens: 2_000_000, cache_tokens: 100_000, total_tokens: 7_000_000 },
      period_totals: { today: { total_tokens: 7_000_000 }, week: { total_tokens: 20_000_000 }, month: { total_tokens: 80_000_000 }, quarter: { total_tokens: 90_000_000 }, year: { total_tokens: 99_000_000 } },
      model_usage: [{ model_id: 1, model_name: "glm-4.6", request_count: 800, total_tokens: 5_000_000 }, { model_id: 2, model_name: "qwen-max", request_count: 434, total_tokens: 2_000_000 }],
      model_token_share: [{ model_id: 1, model_name: "glm-4.6", total_tokens: 5_000_000, percent: 71.43 }, { model_id: 2, model_name: "qwen-max", total_tokens: 2_000_000, percent: 28.57 }],
      live_generations: [{ task_id: "t1", task_type: "image_generation", status: "processing", progress: 40, model_name: "wanx", department_name: "办公室", created_at: sqlUtc(now) }],
      business_timezone: BUSINESS_TZ,
      generated_at: generated
    },
    // Today (Shanghai) 00:00 and 01:00 count as today; 23:00 the evening before as yesterday.
    trends: [
      { bucket_at: sqlUtc(dayStart + 3600000), request_count: 22, success_count: 22, failed_count: 0 },
      { bucket_at: sqlUtc(dayStart), request_count: 11, success_count: 11, failed_count: 0 },
      { bucket_at: sqlUtc(dayStart - 3600000), request_count: 5, success_count: 5, failed_count: 0 }
    ],
    rankings: [{ name: "办公室", request_count: 900, total_tokens: 4_000_000, error_count: 3 }, { name: "财务处", request_count: 334, total_tokens: 3_000_000, error_count: 1 }],
    security: { desensitization: [{ action: "replace", match_count: 12, event_count: 7 }, { action: "block", match_count: 2, event_count: 2 }], audit: [], period_start: new Date(dayStart).toISOString() },
    performance: { buckets: [{ bucket_at: sqlUtc(dayStart), request_count: 100, avg_latency_ms: 800, p95_latency_ms: 3000, p99_latency_ms: 10000 }], queue: { pending_exports: 0 } },
    live: {
      window_minutes: 5, window_seconds: 300, qps: 2.5, tps: 1200, request_count: 750, failed_count: 15, total_tokens: 360000, error_rate: 2,
      p95_latency_ms: 3000, p99_latency_ms: 10000, ttft: { available: false, avg_ms: null },
      providers: [{ id: 1, name: "智谱", request_count: 500, qps: 1.67, error_rate: 1, avg_latency_ms: 900 }, { id: 2, name: "通义", request_count: 250, qps: 0.83, error_rate: 24, avg_latency_ms: 1500 }],
      circuits: { available: true, closed: 5, open: 1, half_open: 0, open_channels: [{ name: "qwen-max", state: "open", failures: 5 }] },
      open_alerts: 3, queue: { pending: 4, worker_alive: true }, health: { level: "warn", reasons: ["circuit_open"] },
      business_timezone: BUSINESS_TZ, generated_at: generated
    }
  };
}

function send(res, status, body, headers = {}) {
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store", ...headers });
  res.end(JSON.stringify(body));
}
const ok = (res, data, headers) => send(res, 200, { ok: true, data }, headers);
const fail = (res, status, code, message = code, headers) => send(res, status, { ok: false, error: { code, message } }, headers);

async function readJson(req) {
  let body = "";
  for await (const chunk of req) body += chunk;
  try {
    return body ? JSON.parse(body) : {};
  } catch {
    return {};
  }
}

function cookieSession(req) {
  const match = /(?:^|;\s*)screen_session=([^;]+)/.exec(req.headers.cookie || "");
  return match ? decodeURIComponent(match[1]) : "";
}

export function startScreenMock(port = 0) {
  const sessions = new Set();
  const requestLog = [];
  const state = { shareLoginEnabled: true };
  const sessionCookie = (value, maxAge) => ({ "Set-Cookie": `screen_session=${encodeURIComponent(value)}; Max-Age=${maxAge}; Path=/; HttpOnly; SameSite=Strict` });

  async function handleApi(req, res, url) {
    const path = url.pathname;
    requestLog.push(`${req.method} ${path}`);
    if (path === "/screen-api/system/brand-identity") {
      return ok(res, { product_name: "TokenHub", screen_title: "TokenHub 运营大屏", logo_url: "", favicon_url: "" });
    }
    if (path === "/screen-api/auth/share-login" && req.method === "POST") {
      const { share_code: code } = await readJson(req);
      if (code === LIMITED_CODE) return fail(res, 429, "RATE_LIMITED", "Too many screen login attempts", { "Retry-After": "30" });
      if (code !== GOOD_CODE || !state.shareLoginEnabled) return fail(res, 401, "SCREEN_UNAUTHENTICATED", "Invalid screen link");
      const session = randomBytes(12).toString("hex");
      sessions.add(session);
      return ok(res, { share: SHARE }, sessionCookie(session, 86400));
    }
    if (path === "/screen-api/auth/login" && req.method === "POST") {
      const { username, password } = await readJson(req);
      if (username !== "lobby" || password !== "lobby-pass") return fail(res, 401, "SCREEN_UNAUTHENTICATED", "Invalid screen credentials");
      const session = randomBytes(12).toString("hex");
      sessions.add(session);
      return ok(res, { share: SHARE }, sessionCookie(session, 86400));
    }
    const session = cookieSession(req);
    if (!sessions.has(session)) {
      return fail(res, 401, "SCREEN_UNAUTHENTICATED", "Screen login required");
    }
    const slide = sessionCookie(session, 86400);
    if (path === "/screen-api/auth/logout") {
      sessions.delete(session);
      return ok(res, { logged_out: true }, sessionCookie("", 0));
    }
    if (path === "/screen-api/auth/me") return ok(res, { screen: SHARE }, slide);
    const metric = /^\/screen-api\/dashboard\/([a-z]+)$/.exec(path)?.[1];
    const data = metric ? fixtures()[metric] : undefined;
    if (data === undefined) return fail(res, 404, "NOT_FOUND", "Not found");
    return ok(res, { metric, scope: { type: "global", ids: [] }, business_timezone: BUSINESS_TZ, data }, slide);
  }

  async function serveStatic(req, res, url) {
    let pathname = decodeURIComponent(url.pathname);
    let file = normalize(join(root, pathname));
    if (!file.startsWith(root)) {
      res.writeHead(403);
      return res.end();
    }
    try {
      const info = await stat(file);
      if (info.isDirectory()) file = join(file, "index.html");
    } catch {
      file = join(root, "screen/index.html");
    }
    try {
      const body = await readFile(file);
      res.writeHead(200, { "Content-Type": MIME[extname(file)] || "application/octet-stream", "Cache-Control": "no-cache" });
      res.end(body);
    } catch {
      res.writeHead(404);
      res.end("Not Found");
    }
  }

  const server = createServer((req, res) => {
    const url = new URL(req.url || "/", "http://localhost");
    const handler = url.pathname.startsWith("/screen-api/") ? handleApi(req, res, url) : serveStatic(req, res, url);
    handler.catch((error) => fail(res, 500, "INTERNAL_ERROR", String(error)));
  });
  return new Promise((resolve) => {
    server.listen(port, "127.0.0.1", () => {
      resolve({
        server,
        baseUrl: `http://127.0.0.1:${server.address().port}`,
        requestLog,
        expireSessions: () => sessions.clear(),
        setShareLogin: (enabled) => { state.shareLoginEnabled = enabled; },
        reset: () => { sessions.clear(); requestLog.length = 0; state.shareLoginEnabled = true; },
        close: () => new Promise((done) => server.close(done))
      });
    });
  });
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const mock = await startScreenMock(Number(process.argv[2] || 8091));
  process.stdout.write(`screen mock on ${mock.baseUrl}/screen/${GOOD_CODE}\n`);
}
