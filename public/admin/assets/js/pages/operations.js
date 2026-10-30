// 系统运维 · 运行状态 (design): one card per service with its recent checks,
// recent worker tasks, and backlogs / certificates on the side. The page
// re-checks every 30 seconds while it is open.
import { api } from "../api.js";
import { errorState, loadingState } from "../components/feedback.js";
import { clear, el, statusMessage } from "../dom.js";

const SERVICES = [
  ["php", "PHP-FPM", "网关与后台接口"],
  ["mysql", "MySQL", "业务数据与用量记录"],
  ["redis", "Redis", "限额计数、会话与任务队列"],
  ["qdrant", "Qdrant", "知识库向量检索"],
  ["queue-worker", "Queue Worker", "导出、结算等异步任务"],
  ["rag-worker", "RAG Worker", "文档解析与向量化"]
];
const REFRESH_MS = 30_000;
const HISTORY_SIZE = 48;

// Check history lives for the browser session only: the backend reports the
// current state, not past availability.
const history = new Map();

function remember(services) {
  for (const [key] of SERVICES) {
    const reported = services[key] && typeof services[key].healthy === "boolean";
    const value = !reported ? "unknown" : services[key].healthy ? "up" : "down";
    const list = history.get(key) || [];
    list.push(value);
    history.set(key, list.slice(-HISTORY_SIZE));
  }
}

function pad(value) {
  return String(value).padStart(2, "0");
}

function clock(date = new Date()) {
  return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

export async function renderOperationsStatusPage(content) {
  clear(content);
  const subtitle = el("p", { text: "正在检查服务状态…" });
  const badge = el("span", { className: "ops-overall", hidden: true });
  const body = el("div", { className: "ops-page" }, [loadingState("正在检查服务状态…", { rows: 3 })]);
  content.append(
    el("header", { className: "page-head" }, [
      el("div", { className: "page-head-text" }, [el("h2", { text: "运行状态" }), subtitle]),
      el("div", { className: "page-head-actions" }, [badge])
    ]),
    body
  );
  let timer = 0;
  let firstLoad = true;

  async function load() {
    window.clearTimeout(timer);
    let status;
    let certificates = null;
    try {
      const [result, certs] = await Promise.all([
        api("/admin-api/operations/status", { silent: true }),
        api("/admin-api/system/certificate-status", { silent: true }).catch(() => null)
      ]);
      status = result.data || {};
      certificates = certs?.data || null;
    } catch (error) {
      if (firstLoad) {
        // The page shell shows a retryable page error for a failed first load.
        throw error;
      }
      if (!body.isConnected) {
        return;
      }
      body.replaceChildren(errorState(error, { fallback: "运行状态读取失败", onRetry: load }));
      return;
    }
    firstLoad = false;
    if (!body.isConnected) {
      return;
    }
    const health = status.health || {};
    const services = health.services || {};
    remember(services);
    const down = SERVICES.filter(([key]) => services[key]?.healthy === false);
    const unknown = SERVICES.filter(([key]) => typeof services[key]?.healthy !== "boolean");
    subtitle.textContent = `${SERVICES.length} 个服务 · 每 30 秒检查一次 · 最近检查 ${clock()}`;
    badge.hidden = false;
    badge.className = `ops-overall ${down.length ? "is-down" : "is-up"}`;
    badge.textContent = down.length ? `${down.length} 个服务异常` : unknown.length ? "核心服务正常，部分未上报" : "核心服务运行正常";
    body.replaceChildren(
      el("section", { className: "ops-services", "aria-label": "服务" }, SERVICES.map(([key, label, role]) => serviceCard(key, label, role, services[key]))),
      el("div", { className: "ops-lower" }, [tasksPanel(status.recent_worker_logs), backlogPanel(status, certificates)])
    );
    timer = window.setTimeout(() => {
      if (body.isConnected) {
        void load();
      }
    }, REFRESH_MS);
  }

  await load();
}

function serviceCard(key, label, role, service) {
  const reported = service && typeof service.healthy === "boolean";
  const state = !reported ? "unknown" : service.healthy ? "up" : "down";
  const text = { up: "运行中", down: "异常", unknown: "未上报" }[state];
  const checks = history.get(key) || [];
  const upCount = checks.filter((item) => item === "up").length;
  const known = checks.filter((item) => item !== "unknown").length;
  return el("article", { className: `ops-service is-${state}` }, [
    el("header", { className: "ops-service-head" }, [
      el("span", { className: "ops-service-name" }, [el("strong", { text: label }), el("small", { text: role })]),
      el("span", { className: `ops-service-state is-${state}`, text })
    ]),
    el("div", {
      className: "ops-service-strip",
      role: "img",
      "aria-label": `本页打开后的 ${checks.length} 次检查：正常 ${upCount} 次`
    }, Array.from({ length: HISTORY_SIZE }, (_, index) => {
      const value = checks[checks.length - HISTORY_SIZE + index];
      return el("span", { className: value ? `is-${value}` : "is-empty" });
    })),
    el("footer", { className: "ops-service-foot" }, [
      el("span", { text: state === "unknown" ? "服务没有上报心跳" : service?.message ? String(service.message) : "最近检查" }),
      el("span", { className: "ops-service-rate", text: known ? `${checks.length} 次检查 · ${Math.round(upCount / known * 100)}%` : `${checks.length} 次检查` })
    ])
  ]);
}

function tasksPanel(logs) {
  const rows = Array.isArray(logs) ? logs.slice(0, 8) : [];
  const head = el("header", { className: "ops-panel-head" }, [el("h3", { text: "最近任务" }), el("span", { text: "来自任务日志" })]);
  if (!rows.length) {
    return el("section", { className: "panel ops-panel ops-tasks" }, [head, statusMessage("暂无最近任务记录")]);
  }
  return el("section", { className: "panel ops-panel ops-tasks" }, [
    head,
    el("div", { className: "table-scroll" }, [el("table", { className: "ops-task-table" }, [
      el("thead", {}, [el("tr", {}, ["任务", "时间", "耗时", "结果"].map((label) => el("th", { scope: "col", text: label })))]),
      el("tbody", {}, rows.map((row) => {
        const level = String(row.level || "info").toLowerCase();
        const failed = ["error", "critical", "alert", "emergency"].includes(level) || /fail|error/i.test(String(row.status || ""));
        return el("tr", {}, [
          el("td", { className: "ops-task-name", text: String(row.message || "未提供任务消息"), title: row.request_id ? `请求 ${row.request_id}` : null }),
          el("td", { className: "cell-mono", text: shortTime(row.timestamp) }),
          el("td", { className: "cell-mono", text: row.latency_ms ? (row.latency_ms >= 1000 ? `${(row.latency_ms / 1000).toFixed(1)} s` : `${row.latency_ms} ms`) : "–" }),
          el("td", {}, [el("span", { className: `ops-result ${failed ? "is-down" : "is-up"}`, text: failed ? "失败" : "完成" })])
        ]);
      }))
    ])])
  ]);
}

function shortTime(value) {
  const parsed = new Date(String(value || ""));
  if (Number.isNaN(parsed.getTime())) {
    return "—";
  }
  return `${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())} ${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`;
}

function metricRow(label, value, { warn = false, hint = "" } = {}) {
  const text = value === null || value === undefined ? "无法读取" : String(value);
  return el("div", { className: `ops-metric ${warn ? "is-warn" : ""}`.trim(), title: hint || null }, [el("span", { text: label }), el("strong", { text })]);
}

function backlogPanel(status, certificates) {
  const queues = status.queues || {};
  const leases = status.reservations || {};
  const notifications = status.alert_notifications || {};
  const pipeline = status.usage_pipeline || {};
  const readable = queues.readable !== false;
  const children = [
    el("header", { className: "ops-panel-head" }, [el("h3", { text: "队列与积压" })]),
    metricRow("任务队列 待处理", readable ? queues.pending : null),
    metricRow("任务队列 处理中", readable ? queues.processing : null),
    metricRow("失败任务", readable ? queues.failed : null, { warn: Number(queues.failed) > 0 }),
    metricRow("额度预占（进行中 / 已过期）", leases.active === undefined ? null : `${leases.active ?? "–"} / ${leases.expired ?? "–"}`, { warn: Number(leases.expired) > 0, hint: "过期未释放的预占会由回收任务处理" }),
    metricRow("告警通知 待发送 / 失败", notifications.pending === undefined ? null : `${notifications.pending ?? "–"} / ${notifications.dead ?? "–"}`, { warn: Number(notifications.dead) > 0 }),
    metricRow("近 24 小时未汇总用量", pipeline.unaggregated ?? null, { warn: Number(pipeline.unaggregated) > 100 })
  ];
  const certs = Array.isArray(certificates?.certificates) ? certificates.certificates : [];
  const soonest = certs.find((item) => item.status === "expired" || item.status === "expiring_soon") || null;
  if (soonest) {
    const names = [...(soonest.domains || []), ...(soonest.ip_addresses || [])].slice(0, 2).join("、") || soonest.name;
    children.push(el("div", { className: `ops-cert ${soonest.status === "expired" ? "is-expired" : ""}`.trim() }, [
      el("span", { className: "ops-cert-mark", "aria-hidden": "true" }, [el("span"), el("span")]),
      el("span", { className: "ops-cert-text" }, [
        el("strong", { text: soonest.status === "expired" ? "TLS 证书已过期" : `TLS 证书 ${soonest.days_remaining} 天后到期` }),
        el("small", { text: `${names} · ${String(soonest.expires_at || "").slice(0, 10)}${soonest.auto_renewal ? " · 自动续期" : ""}` })
      ]),
      el("a", { className: "panel-link", href: "#/system/settings-tls-certificates", text: "证书与入口" })
    ]));
  } else if (certs.length) {
    children.push(el("p", { className: "ops-cert-ok", text: `TLS 证书 ${certs.length} 张，均在有效期内` }));
  }
  return el("section", { className: "panel ops-panel ops-backlog" }, children);
}
