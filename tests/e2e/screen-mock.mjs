// In-memory mock of /screen-api for the operations screen (public/screen), served the
// way Caddy does: /screen and /screen/* fall back to /screen/index.html. Kept apart
// from mock-server.mjs (admin API) so the two can evolve independently.
//
//   node screen-mock.mjs 8091 [--demo]   then open http://127.0.0.1:8091/screen/GoodCode7Q2xLm9P
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


// Dense, realistic data for judging the layout (node screen-mock.mjs 8091 --demo).
// Deterministic so screenshots are comparable between runs.
function demoFixtures() {
  const now = Date.now();
  const dayStart = shanghaiDayStart(now);
  const generated = new Date(now).toISOString();
  const hourStart = Math.floor(now / 3600000) * 3600000;
  const curve = (hourLocal) => 0.15 + Math.max(0, Math.sin((hourLocal - 6) / 16 * Math.PI)) * (hourLocal >= 12 && hourLocal < 14 ? 0.7 : 1);
  const noise = (i) => 0.85 + ((i * 7919) % 97) / 330;
  const trends = [];
  const performance = [];
  for (let i = 0; i < 48; i += 1) {
    const at = hourStart - i * 3600000;
    const hourLocal = new Date(at + 8 * 3600000).getUTCHours();
    const partial = i === 0 ? ((now - hourStart) / 3600000) : 1;
    const req = Math.round(4200 * curve(hourLocal) * noise(i) * partial);
    const failed = Math.round(req * (0.004 + ((i * 13) % 7) / 900));
    const input = req * 2900;
    const output = req * 1100;
    trends.push({ bucket_at: sqlUtc(at), request_count: req, success_count: req - failed, failed_count: failed, input_tokens: input, output_tokens: output, cache_tokens: Math.round(input * 0.18), total_cost: req * 0.012 });
    const avg = 650 + curve(hourLocal) * 500 + ((i * 31) % 120);
    performance.push({ bucket_at: sqlUtc(at), request_count: req, avg_latency_ms: avg, p95_latency_ms: avg * 3.1, p99_latency_ms: avg * 6.4 });
  }
  const today = trends.filter((row) => new Date(row.bucket_at.replace(" ", "T") + "Z").getTime() >= dayStart);
  const sum = (rows, key) => rows.reduce((total, row) => total + row[key], 0);
  const input = sum(today, "input_tokens");
  const output = sum(today, "output_tokens");
  const models = [["glm-4.6", 31], ["qwen3-max", 22], ["deepseek-v3.2", 17], ["doubao-seed-1.6", 11], ["kimi-k2", 8], ["qwen3-vl-plus", 5], ["wanx2.1-t2i", 3], ["text-embedding-v4", 3]];
  const monthTokens = 1_486_000_000;
  const modelRows = models.map(([name, pct], index) => ({ model_id: index + 1, model_name: name, request_count: Math.round(pct * 9100), input_tokens: Math.round(monthTokens * pct / 100 * 0.72), output_tokens: Math.round(monthTokens * pct / 100 * 0.28), total_tokens: Math.round(monthTokens * pct / 100), percent: pct }));
  const series = [];
  for (let i = 4; i >= 0; i -= 1) {
    const req = 260 + ((i * 37) % 60);
    series.push({ bucket_at: sqlUtc(Math.floor(now / 60000) * 60000 - i * 60000), request_count: req, failed_count: i === 1 ? 9 : 2, total_tokens: req * 4100 });
  }
  const depts = [["办公室", 9120], ["财务处", 7450], ["政策法规处", 6230], ["信息中心", 5480], ["人事处", 3910], ["审计处", 2760], ["后勤服务中心", 1840], ["机关党委", 920]];
  return {
    overview: {
      totals: { request_count: sum(today, "request_count"), success_count: sum(today, "success_count"), failed_count: sum(today, "failed_count"), input_tokens: input, output_tokens: output, cache_tokens: sum(today, "cache_tokens"), total_tokens: input + output },
      period_totals: { today: { total_tokens: input + output }, week: { total_tokens: 386_400_000 }, month: { total_tokens: monthTokens }, quarter: { total_tokens: 3_912_000_000 }, year: { total_tokens: 14_870_000_000 } },
      model_usage: modelRows,
      model_token_share: modelRows,
      live_generations: [
        { task_id: "t1", task_type: "image_generation", status: "processing", progress: 62, model_name: "wanx2.1-t2i", department_name: "宣传处", created_at: sqlUtc(now - 40000) },
        { task_id: "t2", task_type: "video_generation", status: "processing", progress: 18, model_name: "kling-v2", department_name: "信息中心", created_at: sqlUtc(now - 95000) },
        { task_id: "t3", task_type: "speech_synthesis", status: "queued", model_name: "cosyvoice-v2", user_name: "张敏", created_at: sqlUtc(now - 12000) },
        { task_id: "t4", task_type: "image_generation", status: "succeeded", total_tokens: 4200, model_name: "qwen-image", department_name: "办公室", created_at: sqlUtc(now - 150000) },
        { task_id: "t5", task_type: "video_generation", status: "failed", model_name: "kling-v2", department_name: "财务处", created_at: sqlUtc(now - 300000) },
        { task_id: "t6", task_type: "image_generation", status: "processing", progress: 81, model_name: "wanx2.1-t2i", department_name: "人事处", created_at: sqlUtc(now - 20000) }
      ],
      business_timezone: BUSINESS_TZ,
      generated_at: generated
    },
    trends,
    rankings: depts.map(([name, req], index) => ({ name, request_count: req, total_tokens: req * 4020, error_count: (index * 5) % 11 })),
    security: {
      desensitization: [{ action: "replace", match_count: 412, event_count: 238 }, { action: "redact", match_count: 96, event_count: 61 }, { action: "block", match_count: 14, event_count: 9 }, { action: "log", match_count: 33, event_count: 33 }],
      audit: [{ action: "admin.login", event_count: 41 }, { action: "api_key.create", event_count: 6 }, { action: "provider.update", event_count: 3 }, { action: "share_link.create", event_count: 2 }],
      period_start: new Date(dayStart).toISOString()
    },
    performance: { buckets: performance, queue: { pending_exports: 2 } },
    live: {
      window_minutes: 5, window_seconds: 300, qps: 4.62, tps: 18940, request_count: 1386, failed_count: 17, total_tokens: 5_682_000, error_rate: 1.23,
      avg_latency_ms: 1180, p95_latency_ms: 3620, p99_latency_ms: 8900, ttft: { available: true, avg_ms: 640 },
      series,
      providers: [
        { id: 1, name: "智谱 AI", request_count: 512, qps: 1.71, error_rate: 0.4, avg_latency_ms: 980 },
        { id: 2, name: "阿里云百炼", request_count: 431, qps: 1.44, error_rate: 1.2, avg_latency_ms: 1210 },
        { id: 3, name: "DeepSeek", request_count: 268, qps: 0.89, error_rate: 3.1, avg_latency_ms: 1640 },
        { id: 4, name: "火山方舟", request_count: 129, qps: 0.43, error_rate: 0, avg_latency_ms: 870 },
        { id: 5, name: "Moonshot", request_count: 46, qps: 0.15, error_rate: 21.7, avg_latency_ms: 4300 }
      ],
      circuits: { available: true, closed: 14, open: 1, half_open: 1, open_channels: [{ name: "moonshot-主线路", state: "open", failures: 7 }, { name: "deepseek-备用", state: "half_open", failures: 3 }] },
      open_alerts: 2, queue: { pending: 7, worker_alive: true }, health: { level: "warn", reasons: ["circuit_open"] },
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

export function startScreenMock(port = 0, { demo = false } = {}) {
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
    const data = metric ? (demo ? demoFixtures() : fixtures())[metric] : undefined;
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
  const mock = await startScreenMock(Number(process.argv[2] || 8091), { demo: process.argv.includes("--demo") });
  process.stdout.write(`screen mock on ${mock.baseUrl}/screen/${GOOD_CODE}\n`);
}
