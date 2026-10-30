// Mock admin backend for browser E2E tests.
// Serves public/admin as the real Caddy config does and implements the
// /admin-api endpoints the admin SPA calls, using an in-memory store.
// Usage: node tests/e2e/mock-server.mjs [port]
import { createServer } from "node:http";
import { readFile, stat } from "node:fs/promises";
import { extname, join, normalize } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { randomUUID } from "node:crypto";

const root = fileURLToPath(new URL("../../public/", import.meta.url));
const { resources } = await import(pathToFileURL(join(root, "admin/assets/js/resources.js")).href);

export const ALL_CAPABILITIES = [
  "dashboard.read",
  "departments.read", "departments.manage",
  "users.read", "users.manage",
  "api_catalog.read", "api_catalog.manage",
  "api_keys.read", "api_keys.manage",
  "quotas.read", "quotas.manage",
  "usage.read", "usage.manage", "content.read", "content.manage", "content.export",
  "rag.read", "rag.manage",
  "operations.read", "operations.manage",
  "security.read", "security.manage",
  "logs.read", "logs.manage", "logs.export",
  "system.read", "system.manage",
  "admins.manage"
];

const ACCOUNTS = {
  admin: { password: "admin-pass", user: { id: 1, username: "admin", role: "super_admin", department_id: 1, status: "active", permissions: ALL_CAPABILITIES } },
  rag: { password: "rag-pass", user: { id: 2, username: "rag", role: "admin", admin_type: "rag_admin", department_id: 2, status: "active", permissions: ["dashboard.read", "rag.read", "rag.manage"] } }
};

const MIME = {
  ".html": "text/html; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".json": "application/json",
  ".svg": "image/svg+xml",
  ".png": "image/png"
};

let sessionTtlMs = 120 * 60 * 1000;

function serverStamp(ms = Date.now()) {
  return new Date(ms).toISOString().slice(0, 19).replace("T", " ");
}

function timestamp(offsetMinutes = 0) {
  const base = Date.UTC(2026, 9, 27, 10, 0, 0) - offsetMinutes * 60000;
  return new Date(base).toISOString().slice(0, 19).replace("T", " ");
}

function fakeValue(column, index, resourceKey) {
  if (column === "id") return index;
  if (column === "status") return index % 7 === 0 ? "disabled" : "active";
  if (column === "role") return index === 1 ? "super_admin" : index % 3 === 0 ? "admin" : "user";
  if (column === "department_id") return ((index - 1) % 3) + 1;
  if (column === "parent_id") return index === 1 ? null : 1;
  if (column.endsWith("_id")) return ((index - 1) % 5) + 1;
  if (column.endsWith("_at")) return timestamp(index * 7);
  if (/(count|tokens|bytes|seconds|limit|level|sort_order|price|cost|amount|days)/.test(column)) return index * 3;
  if (column === "username") return `user${String(index).padStart(3, "0")}`;
  if (column === "name") return `${resourceKey}-${index}`;
  if (column === "capabilities" || column === "model_ids" || column === "permissions") return [];
  return `${column}-${index}`;
}

const stores = new Map();
function rowsFor(resourceKey) {
  if (!stores.has(resourceKey)) {
    const config = resources[resourceKey] || {};
    const columns = config.columns || ["id", "name", "status", "created_at"];
    const count = resourceKey === "logs" || resourceKey === "audit-logs" ? 137 : resourceKey === "users" ? 140 : 6;
    const rows = [];
    for (let index = 1; index <= count; index += 1) {
      const row = {};
      for (const column of columns) row[column] = fakeValue(column, index, resourceKey);
      if (!("id" in row)) row.id = index;
      if (resourceKey === "departments") Object.assign(row, { name: ["总部", "研发部", "运营部", "市场部", "财务部", "法务部"][index - 1] || `部门${index}`, path: `/1/${index}`, level: index === 1 ? 0 : 1 });
      if (resourceKey === "users") Object.assign(row, { username: `user${String(index).padStart(3, "0")}`, display_name: `员工${index}` });
      if (resourceKey === "models") Object.assign(row, { name: `model-${index}`, provider_id: ((index - 1) % 2) + 1, capability: "text_generation", capabilities: { upstream: { features: { tools: true, streaming: true } } }, capability_tags: [], external_name: `upstream-model-${index}`, context_length: [32768, 65536, 131072, 8192, 8192, 32768][index - 1] || 32768, route_priority: index, updated_at: timestamp(index), gateway_supported: index !== 6, unsupported_reason: index === 6 ? "native_protocol" : null, unsupported_message: index === 6 ? "native 协议没有执行后端" : null });
      if (resourceKey === "alert-events") Object.assign(row, [
        { event_type: "provider.timeout", severity: "critical", title: "上游厂商连续超时", message: "厂商2 在 5 分钟内超时 26 次，已自动切换至备用渠道。", status: "open", target_type: "provider", target_id: 2 },
        { event_type: "quota.threshold", severity: "warning", title: "密钥接近日限额", message: "密钥1 今日已用 88% 的 Token 限额。", status: "open", target_type: "api_key", target_id: 1 },
        { event_type: "desensitization.hit", severity: "warning", title: "敏感信息命中", message: "请求内容中识别到 3 处身份证号，已按默认策略掩码后转发。", status: "open", target_type: "user", target_id: 3 },
        { event_type: "auth.new_ip", severity: "info", title: "新 IP 登录管理后台", message: "管理员 user003 从 10.20.6.41 首次登录。", status: "acknowledged", target_type: "user", target_id: 3 },
        { event_type: "latency.recovered", severity: "info", title: "P95 延迟回落", message: "model-1 的 P95 延迟已回到阈值以下。", status: "resolved", target_type: "model", target_id: 1 },
        { event_type: "export.done", severity: "info", title: "导出任务完成", message: "“10 月部门用量”CSV 已生成，保留 7 天。", status: "resolved", target_type: "system", target_id: null }
      ][index - 1] || {});
      if (resourceKey === "usage-records") Object.assign(row, {
        status: index === 4 ? "failed" : index === 6 ? "blocked" : "success",
        model_name: ["qwen-max", "deepseek-chat", "glm-4-plus", "glm-4-plus", "text-embedding-v3", "qwen-max"][index - 1] || "qwen-max",
        input_tokens: 1200 * index, output_tokens: index === 4 ? 0 : 380 * index, total_tokens: index === 4 ? 1200 * index : 1580 * index,
        latency_ms: index === 4 ? 30000 : 900 + index * 310, cost_amount: (0.012 * index).toFixed(4),
        error_code: index === 4 ? "upstream_timeout" : null, http_status: index === 4 ? 504 : 200,
        attempts: index === 4 ? [{ model: "glm-4-plus", outcome: "timeout" }, { model: "deepseek-chat", outcome: "success" }] : null
      });
      if (resourceKey === "providers") Object.assign(row, { name: `厂商${index}`, protocol: "openai", base_url: "https://api.example.com/v1" });
      if (resourceKey === "api-keys") Object.assign(row, { name: `密钥${index}`, key_prefix: "sk-th", key_suffix: `k${index}`, model_ids: [1, 6], management_scopes: index === 2 ? ["users:read"] : [], ...(index === 5 ? { status: "disabled", disabled_by: "management" } : { disabled_by: null }) });
      rows.push(row);
    }
    stores.set(resourceKey, rows);
  }
  return stores.get(resourceKey);
}

function nextId(rows) {
  return rows.reduce((max, row) => Math.max(max, Number(row.id) || 0), 0) + 1;
}

const sessions = new Map();
const mcpGrants = [
  { grant_id: "grt_claude", client_id: "thmcp_client_1", client_name: "Claude", api_key_id: 1, user_id: 1, username: "user001", login_method: "password", status: "active", revoked_reason: null, created_at: timestamp(30), last_used_at: timestamp(5), revoked_at: null },
  { grant_id: "grt_cc", client_id: "https://claude.ai/oauth/claude-code-client-metadata", client_name: null, api_key_id: 1, user_id: 1, username: "user001", login_method: "api_key", status: "revoked", revoked_reason: "password_changed", created_at: timestamp(90), last_used_at: null, revoked_at: timestamp(60) }
];

function json(res, status, payload, headers = {}) {
  const requestId = `req_${randomUUID().slice(0, 8)}`;
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8", "X-Request-Id": requestId, ...headers });
  res.end(JSON.stringify({ ...payload, request_id: requestId }));
}
const ok = (res, data, status = 200, headers = {}) => json(res, status, { ok: true, data }, headers);
const fail = (res, status, code, message) => json(res, status, { ok: false, error: { code, message } });

function sessionUser(req) {
  const cookie = req.headers.cookie || "";
  const match = cookie.match(/tokenhub_admin_session=([^;]+)/);
  return match ? sessions.get(match[1]) || null : null;
}

async function readBody(req) {
  if (req.__body !== undefined) return req.__body;
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const text = Buffer.concat(chunks).toString("utf8");
  if (!text) return {};
  try { return JSON.parse(text); } catch { return {}; }
}

function applyFilters(rows, query) {
  return rows.filter((row) => {
    for (const [key, value] of query.entries()) {
      if (["limit", "offset", "sort", "with_total", "keyword", "q", "ids", "from", "to", "bucket"].includes(key) || value === "") continue;
      if (key in row && String(row[key]) !== String(value)) return false;
    }
    if (query.get("ids")) {
      const ids = new Set(query.get("ids").split(",").map((id) => id.trim()));
      if (!ids.has(String(row.id))) return false;
    }
    const keyword = query.get("q") || query.get("keyword");
    if (keyword) {
      const haystack = `${row.name || ""} ${row.username || ""} ${row.display_name || ""} ${row.id}`.toLowerCase();
      if (!haystack.includes(keyword.toLowerCase())) return false;
    }
    return true;
  });
}

function sortRows(rows, sort) {
  const match = String(sort || "id_desc").match(/^(.*)_(asc|desc)$/);
  if (!match) return rows;
  const [, column, direction] = match;
  return [...rows].sort((a, b) => {
    const left = a[column];
    const right = b[column];
    const compared = typeof left === "number" && typeof right === "number" ? left - right : String(left ?? "").localeCompare(String(right ?? ""));
    return direction === "asc" ? compared : -compared;
  });
}

function pageOf(rows, query, cap = 100) {
  const limit = Math.min(cap, Math.max(1, Number(query.get("limit") || 50)));
  const offset = Math.max(0, Number(query.get("offset") || 0));
  return { items: rows.slice(offset, offset + limit), limit, offset, total: rows.length };
}


const specialGet = {
  "/admin-api/system/brand-identity": () => ({ product_name: "TokenHub", admin_title: "TokenHub 管理后台", logo_url: "", favicon_url: "" }),
  "/admin-api/system/settings-center": () => ({ sections: { brand_identity: { product_name: "TokenHub", admin_title: "TokenHub 管理后台", screen_title: "大屏" }, firewall_settings: { enabled: true, allowed_methods: ["GET", "POST"] }, tls_certificates: { certificate_mode: "domain", threshold_days: 14 } } }),
  "/admin-api/dashboard/realtime": () => ({ today: { request_count: 1200, success_count: 1197, total_tokens: 345000, input_tokens: 240000, output_tokens: 105000, cache_read_tokens: 36000, failed_count: 3, total_cost: 12.5, avg_latency_ms: 1840 } }),
  "/admin-api/dashboard/trends": () => [0, 1, 2, 3].map((index) => ({ bucket_at: timestamp(index * 1440), request_count: 100 + index * 10, total_tokens: 5000 + index * 300, error_count: index })),
  "/admin-api/dashboard/rankings": (query) => [1, 2, 3].map((index) => ({ id: index, name: query.get("dimension") === "user" ? `user00${index}` : `部门${index}`, request_count: 100 - index, total_tokens: 1000 - index })),
  "/admin-api/dashboard/security": () => ({ desensitization: [] }),
  "/admin-api/dashboard/performance": () => ({ buckets: [{ bucket_at: timestamp(), p50_latency_ms: 120, p99_latency_ms: 900 }] }),
  "/admin-api/dashboard/multimodal": () => ({ capabilities: [{ capability: "text_generation", request_count: 10 }] }),
  "/admin-api/operations/status": () => ({ health: { healthy: true, services: { php: { healthy: true }, mysql: { healthy: true }, redis: { healthy: true }, qdrant: { healthy: true }, "queue-worker": { healthy: true }, "rag-worker": { healthy: false } } }, queues: { pending: 4, processing: 1, failed: 0, readable: true }, reservations: { active: 3, expired: 0 }, alert_notifications: { pending: 0, dead: 0 }, usage_pipeline: { unaggregated: 12, events_pending: 0 }, recent_worker_logs: [{ timestamp: "2026-10-27T09:30:00Z", level: "info", message: "usage:aggregate 用量小时汇总", latency_ms: 3200, status: "ok" }, { timestamp: "2026-10-27T09:12:00Z", level: "error", message: "export:csv #318 导出失败", latency_ms: 41000 }] }),
  "/admin-api/system/content-retention": () => ({ mode: "summary", default_days: 30, max_days: 180, raw_allowed_roles: ["super_admin"] }),
  "/admin-api/system/certificate-status": () => ({ summary: {}, certificates: [] }),
  "/admin-api/system/network-interface-ips": () => ({ ips: [] }),
  "/admin-api/chat-session/models": () => [{ name: "model-1", provider_name: "厂商1" }],
  "/admin-api/departments/tree": () => {
    const rows = rowsFor("departments");
    const nest = (parentId) => rows.filter((row) => (row.parent_id ? Number(row.parent_id) : null) === parentId).map((row) => ({ ...row, children: nest(Number(row.id)) }));
    return nest(null);
  },
  "/admin-api/rag/summary": () => ({}),
  "/admin-api/rag/vectorization-metrics": () => ({}),
  "/admin-api/rag/evaluation-summary": () => ({
    source: "rag_eval_runs", total_cases: 3, active_cases: 3, draft_cases: 0,
    latest_run: { run_id: "rer_1", case_count: 3, recall_at_5: 66.67, recall_at_10: 66.67, recall_at_20: 66.67, mrr: 0.5, failed_cases: 1, status: "succeeded",
      metadata: JSON.stringify({ metrics: { ndcg_at_10: 0.5436, no_result_rate: 33.33, low_confidence_rate: 33.33, answer_evaluation: { judged_cases: 1, judge_failures: 1, answer_faithfulness: 0.9, answer_relevance: 1, citation_accuracy: 1, citation_hit_rate: 100 } } }) },
    latest_runs: [], recent_failures: []
  }),
  "/admin-api/rag/import-templates": () => [],
  "/admin-api/rag/eval-cases": () => ({ items: [], total: 0 }),
  "/admin-api/content-archive": (query) => [1, 2, 3]
    .map((index) => ({ content_id: index, request_id: `req-${index}`, session_id: `sess-${index}`, user_id: index, department_id: 1, api_key_id: 1, model_name: "model-1", status: "succeeded", retention_mode: "summary", created_at: timestamp(index * 10) }))
    .filter((row) => !query.get("user_id") || String(row.user_id) === query.get("user_id")),

};

export const requestLog = [];
// Test controls: force failures or slow responses for matching paths.
const faults = []; // { path, method, status, code, times, delayMs }

function takeFault(method, path) {
  const fault = faults.find((item) => (item.method === "*" || item.method === method) && path === item.path && item.times !== 0);
  if (fault && fault.times > 0) fault.times -= 1;
  return fault || null;
}

let ragReconcile = null;
const ragAcl = new Map();
function ragAclState(id) {
  if (!ragAcl.has(id)) {
    ragAcl.set(id, {
      knowledge_base: { id, kb_id: "kb_rd", name: "研发库", department_id: 2, department_name: "研发部", visibility: "private", can_manage: true },
      grants: [
        { id: 1, subject_type: "department", subject_id: 2, subject_name: "研发部", permission: "write", include_descendants: true, is_default: false, expires_at: null, editable: true },
        { id: 2, subject_type: "department", subject_id: 5, subject_name: "财务部", permission: "read", include_descendants: true, is_default: false, expires_at: null, editable: false }
      ],
      datasets: [
        { id: 11, dataset_id: "ds_a", name: "公开资料", acl_mode: "inherit", grants: [] },
        { id: 12, dataset_id: "ds_b", name: "核心设计", acl_mode: "custom", grants: [{ subject_type: "user", subject_id: 1, permission: "read", priority: 1, is_default: true, editable: true }] }
      ]
    });
  }
  return ragAcl.get(id);
}

async function handleApi(req, res, url) {
  const path = url.pathname;
  const method = req.method || "GET";
  const entry = { method, path, query: url.search, body: null };
  requestLog.push(entry);
  if (method !== "GET") {
    const raw = await readBody(req);
    entry.body = raw;
    req.__body = raw;
  }
  const fault = takeFault(method, path);
  if (fault?.delayMs) {
    await new Promise((resolve) => setTimeout(resolve, fault.delayMs));
  }
  if (fault?.status) {
    return fail(res, fault.status, fault.code || "INTERNAL_ERROR", "forced failure");
  }

  if (path === "/admin-api/system/brand-identity") return ok(res, specialGet[path]());
  if (path === "/admin-api/login" && method === "POST") {
    const body = await readBody(req);
    const account = ACCOUNTS[body.username];
    if (!account || account.password !== body.password) return fail(res, 401, "ADMIN_UNAUTHENTICATED", "Invalid username or password");
    const token = randomUUID();
    sessions.set(token, account.user);
    return ok(res, { user: account.user, csrf_token: "csrf-token", session_expires_at: serverStamp(Date.now() + sessionTtlMs), server_time: serverStamp() }, 200, { "Set-Cookie": `tokenhub_admin_session=${token}; Path=/; HttpOnly; SameSite=Strict` });
  }

  const user = sessionUser(req);
  if (!user) return fail(res, 401, "ADMIN_UNAUTHENTICATED", "Admin login required");
  if (method !== "GET" && req.headers["x-csrf-token"] !== "csrf-token") return fail(res, 419, "ADMIN_CSRF_INVALID", "CSRF token invalid");

  if (path === "/admin-api/me") return ok(res, { user, session_expires_at: serverStamp(Date.now() + sessionTtlMs), server_time: serverStamp() });
  if (path === "/admin-api/csrf") return ok(res, { csrf_token: "csrf-token" });
  if (path === "/admin-api/logout") {
    sessions.clear();
    return ok(res, { logged_out: true });
  }
  if (path === "/admin-api/session/renew" && method === "POST") return ok(res, { session_expires_at: serverStamp(Date.now() + sessionTtlMs), server_time: serverStamp() });

  if (method === "GET" && specialGet[path]) return ok(res, specialGet[path](url.searchParams));

  const ragUpload = path.match(/^\/admin-api\/rag\/uploads\/([^/]+)(\/preview)?$/);
  if (ragUpload && method === "GET") {
    if (!ragUpload[2]) return ok(res, { upload_id: ragUpload[1], original_filename: "images.zip", status: "parsed", stage: "previewing", review_status: "waiting_review", vectorize_status: "pending", progress: 60 });
    const page = Math.max(1, Number(url.searchParams.get("page") || 1));
    const type = url.searchParams.get("type") || "text";
    const total = 45;
    const items = Array.from({ length: Math.min(20, total - (page - 1) * 20) }, (_, index) => {
      const n = (page - 1) * 20 + index + 1;
      return type === "image"
        ? { item_type: "image", preview_item_id: `img-${n}`, title: `图片 ${n}`, purpose: "说明书", validation_status: "valid", image_asset: { image_id: `image-${n}`, metadata: { ai_annotation: { description: "一台设备", tags: ["设备", "铭牌"], confidence: 0.92 } }, warnings: n === 1 ? ["分辨率偏低"] : [] } }
        : { item_type: "text", preview_item_id: `text-${n}`, title: `条目 ${n}`, content: `内容 ${n}`, validation_status: n === 2 ? "invalid" : "valid", validation_errors: n === 2 ? ["缺少标题"] : [] };
    });
    return ok(res, { type, page, page_size: 20, total, items });
  }

  const kbUpload = path.match(/^\/admin-api\/rag\/knowledge-bases\/([^/]+)\/uploads$/);
  if (kbUpload && method === "POST") return ok(res, { upload_id: "upl_new", original_filename: req.__body?.filename, status: "waiting_confirm", stage: "waiting_confirm", review_status: "not_required", vectorize_status: "not_started", progress: 30 }, 201);
  // RAG access control (RAG-012/013) and index health (RAG-043).
  const kbAcl = path.match(/^\/admin-api\/rag\/knowledge-bases\/(\d+)\/acl$/);
  if (kbAcl && method === "GET") return ok(res, ragAclState(Number(kbAcl[1])));
  if (kbAcl && method === "PUT") {
    const body = req.__body || {};
    const state = ragAclState(Number(kbAcl[1]));
    if (body.visibility) state.knowledge_base.visibility = body.visibility;
    state.grants = [...state.grants.filter((g) => !g.editable), ...(body.grants || []).map((g) => ({ ...g, editable: true, subject_name: `#${g.subject_id}` }))];
    return ok(res, state);
  }
  const dsAcl = path.match(/^\/admin-api\/rag\/datasets\/(\d+)\/acl$/);
  if (dsAcl && method === "PUT") {
    const body = req.__body || {};
    const dataset = ragAclState(1).datasets.find((d) => String(d.id) === dsAcl[1]);
    if (!dataset) return fail(res, 404, "ADMIN_NOT_FOUND", "dataset not found");
    Object.assign(dataset, { acl_mode: body.acl_mode || dataset.acl_mode, grants: (body.grants || []).map((g) => ({ ...g, editable: true })) });
    return ok(res, dataset);
  }
  if (path === "/admin-api/rag/access-check" && method === "GET") {
    return ok(res, {
      subject: Object.fromEntries(url.searchParams.entries()),
      restricted: url.searchParams.has("api_key_id"),
      department_chain: [{ id: 1, name: "总部" }, { id: 2, name: "研发部" }],
      knowledge_bases: [{ id: 1, kb_id: "kb_rd", name: "研发库", visibility: "department", permission: "read", datasets: [{ id: 11, dataset_id: "ds_a", name: "公开资料", permission: "read" }] }]
    });
  }
  if (path === "/admin-api/rag/index-health" && method === "GET") {
    return ok(res, {
      totals: { datasets: 2, active_chunks: 120, coverage_percent: 97.5, stale_vectors: 3, failed_jobs: 1, lexical_pending: 1 },
      unbound_vector_configs: 0,
      last_reconcile: ragReconcile,
      datasets: [
        { id: 11, kb_name: "研发库", name: "公开资料", vector_store: "qdrant", embedding_model: "bge-m3", model_bound: true, active_chunks: 80, indexed_chunks: 80, coverage_percent: 100, stale_vectors: 0, failed_jobs: 0, active_jobs: 0, lexical_index_status: "ready", last_indexed_at: timestamp(10) },
        { id: 12, kb_name: "研发库", name: "核心设计", vector_store: "qdrant", embedding_model: "bge-m3", model_bound: true, active_chunks: 40, indexed_chunks: 37, coverage_percent: 92.5, stale_vectors: 3, failed_jobs: 1, active_jobs: 0, lexical_index_status: "none", last_indexed_at: timestamp(30) }
      ]
    });
  }
  if (path === "/admin-api/rag/index-reconcile" && method === "POST") {
    ragReconcile = { finished_at: timestamp(), orphans: 2, missing: 3 };
    return ok(res, { orphans: 2, missing: 3, fixed: Boolean(req.__body?.fix), collections: [{ collection: "col_text", orphans: 2, missing: 3 }] });
  }
  const backfillLexical = path.match(/^\/admin-api\/rag\/datasets\/(\d+)\/backfill-lexical$/);
  if (backfillLexical && method === "POST") return ok(res, { dataset_id: Number(backfillLexical[1]), chunks: 40, points: 40 });

  const lookup = path.match(/^\/admin-api\/lookups\/([a-z-]+)$/);
  if (lookup && method === "GET") {
    const rows = applyFilters(rowsFor(lookup[1]), url.searchParams);
    const limit = Math.min(50, Number(url.searchParams.get("limit") || 20));
    const pick = ["id", "name", "username", "role", "status", "department_id", "user_id", "key_prefix", "key_suffix", "external_name", "provider_id", "capability", "period", "kb_id", "dataset_id", "modality", "model_name", "parent_id"];
    return ok(res, rows.slice(0, url.searchParams.get("ids") ? rows.length : limit).map((row) => Object.fromEntries(pick.filter((key) => key in row).map((key) => [key, row[key]]))));
  }

  if (path === "/admin-api/api-keys" && method === "GET") {
    const page = pageOf(applyFilters(rowsFor("api-keys"), url.searchParams), url.searchParams);
    return ok(res, page);
  }

  if (path === "/admin-api/exports/usage_records" || path.startsWith("/admin-api/exports/")) {
    if (method === "POST") return ok(res, { export_id: "exp_1", status: "queued" });
  }

  // Operations tools (ported from the former table.js).
  if (path === "/admin-api/quota-status" && method === "GET") {
    return ok(res, {
      api_key_id: Number(url.searchParams.get("api_key_id")),
      policies: [{ policy_id: 3, name: "研发部月度额度", target_type: "department", target_id: 2, period: "monthly", reset_at: timestamp(-60), limits: { total_tokens: 1000000, request_count: null }, usage: { total_tokens: 920000, request_count: 12, reserved_in_flight: { total_tokens: 4000 } }, remaining: { total_tokens: 80000 } }],
      per_model: []
    });
  }
  if (path === "/admin-api/api-keys/mcp-oauth-grants" && method === "GET") {
    return ok(res, mcpGrants.filter((grant) => String(grant.api_key_id) === url.searchParams.get("api_key_id")));
  }
  const grantRevoke = path.match(/^\/admin-api\/api-keys\/mcp-oauth-grants\/([^/]+)\/revoke$/);
  if (grantRevoke && method === "POST") {
    const grant = mcpGrants.find((item) => item.grant_id === decodeURIComponent(grantRevoke[1]));
    if (!grant) return fail(res, 404, "ADMIN_NOT_FOUND", "Authorization not found");
    Object.assign(grant, { status: "revoked", revoked_reason: "admin", revoked_at: timestamp() });
    return ok(res, { grant_id: grant.grant_id, revoked: true, status: "revoked" });
  }
  if (path === "/admin-api/quota-adjustments" && method === "POST") {
    const body = req.__body || {};
    return ok(res, { metric: body.metric, before: 920000, after: 920000 + Number(body.amount || 0), window: "2026-10" });
  }
  const rotate = path.match(/^\/admin-api\/api-keys\/(\d+)\/rotate$/);
  if (rotate && method === "POST") {
    return ok(res, { id: 99, key: "sk-th-new-secret-value", display: "sk-th****new1", replaces_api_key_id: Number(rotate[1]), old_key_expires_at: timestamp(-60) }, 201);
  }
  const diagnostics = path.match(/^\/admin-api\/diagnostics\/requests\/([^/]+)$/);
  if (diagnostics && method === "GET") {
    return ok(res, {
      found: true, request_id: decodeURIComponent(diagnostics[1]), status: "failed", http_status: 429, error_code: "QUOTA_EXCEEDED", latency_ms: 12, created_at: timestamp(),
      explanation: { category: "quota", cause: "A quota policy is exhausted.", action: "Wait for the window to reset." },
      channel: { id: 1, name: "model-1", external_name: "gpt-x", status: "active", provider_id: 1, provider_name: "厂商1", provider_status: "active" },
      attempts: [], usage: { source: "estimated", input_tokens: 10 }, stream: {}, upstream: {}, persistence: {}
    });
  }
  const catalogSync = path.match(/^\/admin-api\/providers\/(\d+)\/catalog-sync$/);
  if (catalogSync && method === "POST") {
    return ok(res, {
      applied: Boolean((req.__body || {}).apply),
      added: [{ external_name: "new-model", capability: "text_generation", context_length: 128000 }],
      changed: [{ model_id: 1, external_name: "model-1", before: { context_length: 8000 }, after: { context_length: 32000 } }],
      missing: [{ model_id: 2, external_name: "model-2", status: "active" }],
      unchanged: 3,
      hidden_unsupported: 1
    });
  }
  if (path === "/admin-api/chat-session/stream" && method === "POST") {
    const requestId = `req_${randomUUID().slice(0, 8)}`;
    res.writeHead(200, { "Content-Type": "text/event-stream", "X-Request-Id": requestId });
    res.end(`data: ${JSON.stringify({ choices: [{ delta: { content: "你好" } }] })}\n\ndata: [DONE]\n\n`);
    return undefined;
  }

  const resourceMatch = path.match(/^\/admin-api\/([a-z0-9-]+)(?:\/(\d+))?(?:\/([a-z-]+))?$/);
  if (resourceMatch) {
    const [, resourceKey, id, action] = resourceMatch;
    const rows = rowsFor(resourceKey);
    if (method === "GET" && !id) {
      const filtered = sortRows(applyFilters(rows, url.searchParams), url.searchParams.get("sort"));
      const page = pageOf(filtered, url.searchParams);
      const headers = url.searchParams.get("with_total") === "1" || resourceKey === "logs" ? { "X-Total-Count": String(page.total) } : {};
      return ok(res, page.items, 200, headers);
    }
    if (method === "POST" && !id) {
      const body = await readBody(req);
      if (resourceKey === "departments" && !body.name) return fail(res, 422, "ADMIN_INVALID_PAYLOAD", "name required");
      const row = { id: nextId(rows), status: "active", created_at: timestamp(), ...body };
      rows.unshift(row);
      return ok(res, row, 201);
    }
    if ((method === "PUT" || method === "PATCH") && id) {
      const body = await readBody(req);
      const row = rows.find((item) => String(item.id) === id);
      if (!row) return fail(res, 404, "ADMIN_NOT_FOUND", "not found");
      Object.assign(row, body);
      return ok(res, row);
    }
    if (method === "DELETE" && id && resourceKey === "departments") {
      const row = rows.find((item) => String(item.id) === id);
      if (row) row.status = "disabled";
      return ok(res, row || {});
    }
    if (method === "DELETE" && id) {
      const index = rows.findIndex((item) => String(item.id) === id);
      if (index >= 0) rows.splice(index, 1);
      return ok(res, { deleted: true });
    }
    if (method === "POST" && id && action) {
      return ok(res, { status: "ok" });
    }
  }
  if (method === "GET") return ok(res, []);
  return ok(res, { status: "ok" });
}

async function serveStatic(req, res, url) {
  let pathname = decodeURIComponent(url.pathname);
  if (pathname === "/admin") {
    res.writeHead(301, { Location: "/admin/" });
    return res.end();
  }
  let file = normalize(join(root, pathname));
  if (!file.startsWith(root)) {
    res.writeHead(403);
    return res.end();
  }
  try {
    const info = await stat(file);
    if (info.isDirectory()) file = join(file, "index.html");
  } catch {
    file = join(root, "admin/index.html");
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

export function startMockServer(port = 0) {
  const server = createServer((req, res) => {
    const url = new URL(req.url || "/", "http://localhost");
    const handler = url.pathname.startsWith("/admin-api/") ? handleApi(req, res, url) : serveStatic(req, res, url);
    handler.catch((error) => {
      console.error(error);
      fail(res, 500, "INTERNAL_ERROR", String(error));
    });
  });
  return new Promise((resolve) => {
    server.listen(port, "127.0.0.1", () => {
      const address = server.address();
      resolve({
        server,
        baseUrl: `http://127.0.0.1:${address.port}`,
        requestLog,
        fault: (path, { method = "*", status = 0, code = "", times = 1, delayMs = 0 } = {}) => faults.push({ path, method, status, code, times, delayMs }),
        clearFaults: () => { faults.length = 0; },
        expireSessions: () => sessions.clear(),
        rows: (resourceKey) => rowsFor(resourceKey),
        reset: () => { stores.clear(); sessions.clear(); requestLog.length = 0; faults.length = 0; }
      });
    });
  });
}

if (import.meta.url === pathToFileURL(process.argv[1] || "").href) {
  const { baseUrl } = await startMockServer(Number(process.argv[2] || 8090));
  console.log(`Mock admin server: ${baseUrl}/admin/  (admin / admin-pass)`);
}
