import { api } from "../api.js";
import { clear, el, statusMessage } from "../dom.js";

export async function renderOperationsStatusPage(content) {
  clear(content);
  const result = await api("/admin-api/operations/status");
  const status = result.data || {};
  const health = status.health || {};
  const services = health.services || {};
  const queues = status.queues || {};
  const logs = Array.isArray(status.recent_worker_logs) ? status.recent_worker_logs : [];
  const serviceLabels = { php: "PHP", mysql: "MySQL", redis: "Redis", qdrant: "Qdrant", "queue-worker": "队列 Worker", "rag-worker": "RAG Worker" };
  const queueMetrics = [
    ["pending", "待处理"],
    ["processing", "处理中"],
    ["failed", "失败任务"]
  ];
  const healthCards = Object.entries(serviceLabels).map(([service, label]) => {
    // A service that reported nothing is unknown, not broken.
    const reported = services[service] && typeof services[service].healthy === "boolean";
    const healthy = services[service]?.healthy === true;
    const tone = !reported ? "is-unknown" : healthy ? "is-healthy" : "is-unhealthy";
    return el("article", { className: `operations-health-card ${tone}` }, [
      el("span", { className: "operations-card-label", text: label }),
      el("strong", { text: !reported ? "未上报" : healthy ? "正常" : "异常" })
    ]);
  });
  const queueCards = queueMetrics.map(([key, label]) => el("article", { className: `operations-queue-card ${key === "failed" && Number(queues[key] || 0) > 0 ? "has-failures" : ""}` }, [
    el("span", { className: "operations-card-label", text: label }),
    el("strong", { text: String(Number(queues[key] || 0)) })
  ]));
  const workerLogList = el("div", { className: "operations-worker-log-list" }, logs.length
    ? logs.map((row) => renderOperationsWorkerLog(row))
    : [statusMessage("暂无最近任务记录")]
  );

  content.append(el("section", { className: "panel operations-status-overview" }, [
    el("div", { className: "operations-status-heading" }, [
      el("div", {}, [el("h2", { text: "系统运行状态" }), el("p", { className: "notice", text: health.healthy ? "核心服务运行正常" : "发现需要处理的服务异常" })]),
      el("span", { className: `operations-overall-status ${health.healthy ? "is-healthy" : "is-unhealthy"}`, text: health.healthy ? "运行正常" : "需要关注" })
    ]),
    el("div", { className: "operations-health-grid" }, healthCards),
    el("div", { className: "operations-queue-section" }, [el("h3", { text: "任务队列" }), el("div", { className: "operations-queue-grid" }, queueCards)]),
    el("div", { className: "operations-worker-log-section" }, [el("h3", { text: "最近任务" }), workerLogList])
  ]));
}

function renderOperationsWorkerLog(row) {
  const level = String(row.level || "info").toLowerCase();
  const metadata = [
    row.request_id ? `请求 ${row.request_id}` : "",
    row.status !== null && row.status !== undefined && row.status !== "" ? `状态 ${row.status}` : "",
    row.latency_ms !== null && row.latency_ms !== undefined && row.latency_ms !== "" ? `${row.latency_ms} ms` : ""
  ].filter(Boolean);
  return el("article", { className: "operations-worker-log" }, [
    el("div", { className: "operations-worker-log-heading" }, [
      el("span", { className: `operations-log-level is-${level}`, text: level.toUpperCase() }),
      el("time", { text: formatOperationsTimestamp(row.timestamp) })
    ]),
    el("strong", { text: String(row.message || "未提供任务消息") }),
    metadata.length ? el("div", { className: "operations-worker-log-meta" }, metadata.map((item) => el("span", { text: item }))) : null
  ].filter(Boolean));
}

function formatOperationsTimestamp(value) {
  const parsed = new Date(String(value || ""));
  return Number.isNaN(parsed.getTime()) ? "时间未知" : parsed.toLocaleString("zh-CN", { hour12: false });
}
