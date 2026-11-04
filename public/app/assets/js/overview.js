// 概览: today's usage, service health, queues and open alerts at a glance,
// refreshed while the APP is in the foreground.
import { api } from "/admin/assets/js/api.js";
import { el } from "/admin/assets/js/dom.js";
import { pageAllowed, adminHash } from "/admin/assets/js/domains.js";
import { formatNumber } from "/admin/assets/js/lib/format.js";
import { valueLabel } from "/admin/assets/js/resources.js";
import { createPoller } from "./poller.js";
import { onRealtime, realtimeConnected } from "./realtime.js";

const SERVICE_LABELS = {
  php: "应用服务",
  mysql: "数据库",
  redis: "缓存 Redis",
  qdrant: "向量库 Qdrant",
  "queue-worker": "队列 Worker",
  "rag-worker": "知识库 Worker",
  schema: "数据库结构"
};

function stamp(date = new Date()) {
  return date.toLocaleTimeString("zh-CN", { hour12: false });
}

function kpi(label, value, hint = "", tone = "") {
  return el("div", { className: `tha-kpi ${tone ? `tha-kpi--${tone}` : ""}`.trim() }, [
    el("span", { className: "tha-kpi-label", text: label }),
    el("strong", { className: "tha-kpi-value", text: value }),
    hint ? el("span", { className: "tha-kpi-hint", text: hint }) : null
  ]);
}

function card(title, children, action = null) {
  return el("section", { className: "tha-card" }, [
    el("header", { className: "tha-card-header" }, [el("h2", { text: title }), action]),
    ...[].concat(children)
  ]);
}

function link(user, page, text) {
  const hash = adminHash(user, page);
  return hash ? el("a", { className: "tha-link", href: hash, text }) : null;
}

export function renderOverview(content, { user, setUpdated }) {
  const canDashboard = pageAllowed(user, "dashboard");
  const canOperations = pageAllowed(user, "operations-status");
  const canAlerts = pageAllowed(user, "alert-events");
  const kpis = el("div", { className: "tha-kpis", "aria-live": "polite" });
  const alerts = el("div", { className: "tha-alert-summary" });
  const health = el("ul", { className: "tha-health" });
  const queues = el("dl", { className: "tha-queues" });
  const sections = [];

  if (canAlerts) {
    sections.push(card("待处理告警", alerts, link(user, "alert-events", "查看全部")));
  }
  if (canDashboard) {
    sections.push(card("今日用量", kpis, link(user, "dashboard", "完整大盘")));
  }
  if (canOperations) {
    sections.push(card("服务健康", [health, queues], link(user, "operations-status", "运行状态")));
  }
  if (sections.length === 0) {
    sections.push(card("概览", el("p", { className: "tha-muted", text: "当前账号没有查看大盘、运行状态或告警的权限。可以在「管理」中打开有权限的页面。" })));
  }
  content.replaceChildren(el("div", { className: "tha-overview" }, sections));

  const showUsage = (data) => {
    const today = data?.today || {};
    const requests = Number(today.request_count || 0);
    const failed = Number(today.failed_count || 0);
    const success = requests > 0 ? (Number(today.success_count || 0) / requests) * 100 : 100;
    const cost = Number(today.total_cost || 0);
    kpis.replaceChildren(
      kpi("请求数", formatNumber(requests)),
      kpi("成功率", `${formatNumber(success, { digits: 1 })}%`, failed > 0 ? `失败 ${formatNumber(failed)}` : "", success < 95 && requests > 0 ? "danger" : ""),
      kpi("Token", formatNumber(Number(today.total_tokens || 0))),
      kpi("费用", cost > 0 ? `¥${formatNumber(cost, { digits: 2 })}` : "¥0"),
      kpi("平均延迟", `${formatNumber(Number(today.avg_latency_ms || 0))} ms`)
    );
    setUpdated(stamp());
  };
  const showHealth = (data) => {
    const services = Object.entries(data?.health?.services || {});
    services.sort(([, a], [, b]) => Number(Boolean(a?.healthy)) - Number(Boolean(b?.healthy)));
    health.replaceChildren(...services.map(([key, value]) => el("li", { className: `tha-health-item ${value?.healthy ? "is-ok" : "is-bad"}` }, [
      el("span", { className: "tha-dot", "aria-hidden": "true" }),
      el("span", { text: SERVICE_LABELS[key] || key }),
      el("span", { className: "tha-health-state", text: value?.healthy ? "正常" : "异常" })
    ])));
    const queue = data?.queues || {};
    const pipeline = data?.usage_pipeline || {};
    const entries = [
      ["待处理任务", queue.pending],
      ["处理中", queue.processing],
      ["失败任务", queue.failed],
      ["待汇总用量", pipeline.unaggregated]
    ];
    queues.replaceChildren(...entries.flatMap(([label, value]) => [
      el("dt", { text: label }),
      el("dd", { className: label === "失败任务" && Number(value) > 0 ? "is-bad" : "", text: value === null || value === undefined ? "—" : formatNumber(Number(value)) })
    ]));
    setUpdated(stamp());
  };
  const showAlerts = (total, rows) => {
    alerts.replaceChildren(
      el("strong", { className: `tha-alert-count ${total > 0 ? "is-bad" : "is-ok"}`, text: total > 0 ? `${formatNumber(total)} 条待处理` : "没有待处理告警" }),
      ...rows.slice(0, 3).map((row) => el("p", { className: "tha-alert-line", text: `${row.title || row.message || row.event_type || "告警"}${row.severity ? ` · ${valueLabel("severity", row.severity)}` : ""}` }))
    );
    setUpdated(stamp());
  };

  // Live updates replace polling while the stream is connected; polling is the fallback.
  const skipWhileLive = { skip: () => realtimeConnected() };
  const pollers = [];
  if (canDashboard) {
    pollers.push(createPoller(async () => {
      const { data } = await api("/admin-api/dashboard/realtime", { silent: true });
      if (!realtimeConnected()) {
        showUsage(data); // the live stream wins when both arrive
      }
    }, { interval: 10_000, ...skipWhileLive }));
  }
  if (canOperations) {
    pollers.push(createPoller(async () => {
      const { data } = await api("/admin-api/operations/status", { silent: true });
      if (!realtimeConnected()) {
        showHealth(data);
      }
    }, { interval: 30_000, ...skipWhileLive }));
  }
  if (canAlerts) {
    pollers.push(createPoller(async () => {
      const result = await api("/admin-api/alert-events", { query: { status: "open", limit: 3, offset: 0, with_total: 1 }, silent: true });
      const rows = Array.isArray(result.data) ? result.data : (result.data?.items || []);
      const total = Number(result.total ?? rows.length);
      if (!realtimeConnected()) {
        window.dispatchEvent(new CustomEvent("tokenhub-app-alert-count", { detail: { count: total } }));
        showAlerts(total, rows);
      }
    }, { interval: 30_000, ...skipWhileLive }));
  }
  const unsubscribe = onRealtime((type, data) => {
    if (type === "usage" && canDashboard) showUsage(data);
    if (type === "health" && canOperations) showHealth(data);
    if (type === "alerts" && canAlerts) showAlerts(Number(data?.count || 0), data?.latest || []);
  });

  return () => {
    unsubscribe();
    pollers.forEach((poller) => poller.stop());
  };
}
