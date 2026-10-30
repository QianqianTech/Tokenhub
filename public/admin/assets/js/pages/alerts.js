import { adminErrorMessage, api } from "../api.js";
import { emptyState, errorState, loadingState, toast } from "../components/feedback.js";
import { promptDialog } from "../components/modal.js";
import { pager } from "../components/pager.js";
import { button, clear, el, showModal } from "../dom.js";
import { formatDateTime } from "../lib/format.js";
import { renderDataDetails } from "../lib/output.js";
import { valueLabel } from "../resources.js";

const STATUS_LABELS = { open: "待处理", acknowledged: "已确认", resolved: "已关闭" };
const STATUS_CARDS = [
  ["open", "待处理"],
  ["acknowledged", "已确认"],
  ["resolved", "已关闭"],
  ["", "全部"]
];
const SEVERITY_ORDER = ["critical", "warning", "info"];

export async function renderAlertEventsPage(content, query = {}, title = "告警事件") {
  clear(content);
  const state = { status: "open", offset: 0, limit: 20 };
  const list = el("div", { className: "alert-list", "aria-live": "polite" });
  const cards = el("section", { className: "alert-summary", "aria-label": "按状态筛选告警" });
  const settingsLink = el("a", { className: "button-link secondary", href: "#/system/settings-alert-thresholds", text: "告警设置" });
  content.append(
    el("header", { className: "page-head" }, [
      el("div", { className: "page-head-text" }, [
        el("h2", { text: title }),
        el("p", { text: "额度、延迟、错误率与内容安全触发的告警。确认或关闭时需要填写处置说明，并记入审计日志。" })
      ]),
      el("div", { className: "page-head-actions" }, [settingsLink])
    ]),
    cards,
    el("section", { className: "panel alert-list-panel" }, [list])
  );

  async function count(status) {
    try {
      const result = await api("/admin-api/alert-events", { query: { ...query, ...(status ? { status } : {}), limit: 1, offset: 0, with_total: 1 }, silent: true });
      const total = Number(result.total);
      return Number.isFinite(total) ? total : null;
    } catch {
      return null;
    }
  }

  // Severity mix of the open alerts (from the newest 100; the API filters
  // alerts by status and type, not by severity).
  async function openSeverities() {
    try {
      const result = await api("/admin-api/alert-events", { query: { ...query, status: "open", limit: 100, offset: 0 }, silent: true });
      const rows = Array.isArray(result.data) ? result.data : [];
      const counts = {};
      for (const row of rows) {
        const key = String(row.severity || "info").toLowerCase();
        counts[key] = (counts[key] || 0) + 1;
      }
      return counts;
    } catch {
      return {};
    }
  }

  async function loadCards() {
    const [totals, severities] = await Promise.all([Promise.all(STATUS_CARDS.map(([status]) => count(status))), openSeverities()]);
    cards.replaceChildren(...STATUS_CARDS.map(([status, label], index) => {
      const pressed = state.status === status;
      const mix = status === "open"
        ? SEVERITY_ORDER.filter((key) => severities[key]).map((key) => `${valueLabel("severity", key)} ${severities[key]}`).join(" · ")
        : "";
      return el("button", {
        type: "button",
        className: `alert-summary-card is-${status || "all"} ${pressed ? "is-active" : ""}`.trim(),
        "aria-pressed": pressed ? "true" : "false",
        onClick: () => {
          state.status = status;
          state.offset = 0;
          void loadCards();
          void load();
        }
      }, [
        el("span", { className: "alert-summary-icon", "aria-hidden": "true" }, [el("span"), el("span")]),
        el("span", { className: "alert-summary-text" }, [
          el("span", { text: label }),
          el("strong", { text: totals[index] === null ? "–" : String(totals[index]) }),
          mix ? el("small", { text: mix }) : null
        ])
      ]);
    }));
  }

  async function load() {
    list.replaceChildren(loadingState());
    let result;
    try {
      result = await api("/admin-api/alert-events", { query: { ...query, ...(state.status ? { status: state.status } : {}), limit: state.limit, offset: state.offset, with_total: 1 }, silent: true });
    } catch (error) {
      list.replaceChildren(errorState(error, { fallback: `${title}加载失败`, onRetry: load }));
      return;
    }
    const rows = Array.isArray(result.data) ? result.data : [];
    if (rows.length === 0) {
      list.replaceChildren(emptyState({
        title: state.status === "open" ? "没有待处理的告警" : `暂无${STATUS_LABELS[state.status] || ""}告警`,
        description: state.status === "open" ? "额度、延迟、错误率或内容安全触发告警时会出现在这里。" : ""
      }));
      return;
    }
    const refresh = async () => {
      await Promise.all([loadCards(), load()]);
    };
    list.replaceChildren(
      ...rows.map((row) => alertCard(row, refresh)),
      pager({
        total: result.total,
        limit: state.limit,
        offset: state.offset,
        count: rows.length,
        onChange: ({ offset, limit }) => {
          state.offset = offset;
          state.limit = limit;
          return load();
        }
      })
    );
  }

  await Promise.all([loadCards(), load()]);
}

const SEVERITY_TONES = { critical: "is-critical", error: "is-critical", high: "is-critical", warning: "is-warning", medium: "is-warning" };

function alertCard(row, onRefresh) {
  const status = row.status || "open";
  const pending = status !== "acknowledged" && status !== "resolved";
  const severityTone = SEVERITY_TONES[String(row.severity || "").toLowerCase()] || "is-info";
  const actions = [];
  if (pending) {
    actions.push(button("确认", () => updateAlertStatus(row, "acknowledged", onRefresh), "secondary"));
  }
  if (status !== "resolved") {
    actions.push(button("关闭", () => updateAlertStatus(row, "resolved", onRefresh), "ghost"));
  }
  actions.push(button("详情", () => showModal(row.title || row.event_type || `告警 #${row.id}`, renderDataDetails(row)), "ghost"));
  const target = [row.target_type ? valueLabel("target_type", row.target_type) : "", row.target_id ? `#${row.target_id}` : ""].filter(Boolean).join(" · ");
  const titleText = row.title || valueLabel("event_type", row.event_type) || `告警 #${row.id}`;
  return el("article", { className: `alert-item ${severityTone} ${pending ? "is-open" : ""}`.trim() }, [
    el("div", { className: "alert-item-main" }, [
      el("div", { className: "alert-item-title" }, [
        row.severity ? el("span", { className: `alert-severity ${severityTone}`, text: valueLabel("severity", row.severity) }) : null,
        el("strong", { text: titleText }),
        row.event_type && row.event_type !== titleText ? el("code", { className: "alert-item-code", text: row.event_type }) : null
      ]),
      row.message ? el("p", { className: "alert-item-message", text: row.message }) : null,
      el("span", { className: "alert-item-meta", text: [formatDateTime(row.created_at), target].filter(Boolean).join(" · ") })
    ]),
    el("div", { className: "alert-item-side" }, [
      el("span", { className: `alert-item-status is-${status}`, text: STATUS_LABELS[status] || valueLabel("status", status) }),
      el("div", { className: "alert-item-actions" }, actions)
    ])
  ]);
}

async function updateAlertStatus(row, status, onRefresh) {
  const note = await promptDialog({
    title: status === "resolved" ? "关闭告警" : "确认告警",
    label: "处置说明",
    placeholder: "例如：已扩容队列 Worker；属误报，已调整阈值",
    confirmLabel: status === "resolved" ? "关闭告警" : "确认告警"
  });
  if (!note) {
    return;
  }
  try {
    await api(`/admin-api/alerts/${row.id}/status`, { method: "POST", body: JSON.stringify({ status, note }) });
    toast(status === "resolved" ? "告警已关闭" : "告警已确认", { tone: "success" });
    await onRefresh();
  } catch (error) {
    toast(adminErrorMessage(error, "告警处置失败"), { tone: "error" });
  }
}
