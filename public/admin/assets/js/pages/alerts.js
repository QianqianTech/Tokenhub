import { adminErrorMessage, api } from "../api.js";
import { emptyState, errorState, loadingState, toast } from "../components/feedback.js";
import { promptDialog } from "../components/modal.js";
import { pager } from "../components/pager.js";
import { button, clear, el, showModal } from "../dom.js";
import { formatDateTime, statusTone } from "../lib/format.js";
import { renderDataDetails } from "../lib/output.js";
import { valueLabel } from "../resources.js";

const STATUS_FILTERS = [["", "全部状态"], ["open", "待处理"], ["acknowledged", "已确认"], ["resolved", "已关闭"]];
const STATUS_LABELS = { open: "待处理", acknowledged: "已确认", resolved: "已关闭" };


export async function renderAlertEventsPage(content, query = {}, title = "告警中心") {
  clear(content);
  const state = { status: "open", offset: 0, limit: 20 };
  const statusSelect = el("select", { "aria-label": "告警状态" }, STATUS_FILTERS.map(([value, label]) => el("option", { value, text: label })));
  statusSelect.value = state.status;
  const list = el("div", { className: "stack", "aria-live": "polite" });
  statusSelect.addEventListener("change", () => {
    state.status = statusSelect.value;
    state.offset = 0;
    void load();
  });
  content.append(
    el("header", { className: "page-head" }, [
      el("div", { className: "page-head-text" }, [
        el("h2", { text: title }),
        el("p", { text: "额度、延迟、错误率与内容安全触发的告警。确认或关闭时需要填写处置说明，并记入审计日志。" })
      ]),
      el("label", { className: "field alert-status-filter" }, [el("span", { text: "状态" }), statusSelect])
    ]),
    el("section", { className: "panel alert-list-panel" }, [list])
  );

  async function load() {
    list.replaceChildren(loadingState());
    let result;
    try {
      result = await api("/admin-api/alert-events", { query: { ...query, status: state.status, limit: state.limit, offset: state.offset, with_total: 1 }, silent: true });
    } catch (error) {
      list.replaceChildren(errorState(error, { fallback: `${title}加载失败`, onRetry: load }));
      return;
    }
    const rows = Array.isArray(result.data) ? result.data : [];
    if (rows.length === 0) {
      list.replaceChildren(emptyState({ title: state.status === "open" ? "没有待处理的告警" : `暂无${title}` }));
      return;
    }
    list.replaceChildren(
      ...rows.map((row) => alertCard(row, load)),
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

  await load();
}

const SEVERITY_TONES = { critical: "is-critical", error: "is-critical", high: "is-critical", warning: "is-warning", medium: "is-warning" };

function alertCard(row, onRefresh) {
  const status = row.status || "open";
  const pending = status !== "acknowledged" && status !== "resolved";
  const severityTone = SEVERITY_TONES[String(row.severity || "").toLowerCase()] || "is-info";
  const actions = [button("详情", () => showModal(row.title || row.event_type || `告警 #${row.id}`, renderDataDetails(row)), "ghost")];
  if (pending) {
    actions.push(button("确认", () => updateAlertStatus(row, "acknowledged", onRefresh), "secondary"));
  }
  if (status !== "resolved") {
    actions.push(button("关闭", () => updateAlertStatus(row, "resolved", onRefresh), "secondary"));
  }
  const target = [row.target_type ? valueLabel("target_type", row.target_type) : "", row.target_id ? `#${row.target_id}` : ""].filter(Boolean).join(" ");
  return el("article", { className: `alert-item ${severityTone} ${pending ? "is-open" : ""}`.trim() }, [
    el("div", { className: "alert-item-main" }, [
      el("div", { className: "alert-item-title" }, [
        row.severity ? el("span", { className: `alert-severity ${severityTone}`, text: valueLabel("severity", row.severity) }) : null,
        el("strong", { text: row.title || valueLabel("event_type", row.event_type) || `告警 #${row.id}` })
      ]),
      row.message ? el("p", { className: "alert-item-message", text: row.message }) : null,
      el("span", { className: "alert-item-meta", text: [formatDateTime(row.created_at), target].filter(Boolean).join(" · ") })
    ]),
    el("div", { className: "alert-item-side" }, [
      el("span", { className: `status-pill ${statusTone(status)}`.trim(), text: STATUS_LABELS[status] || valueLabel("status", status) }),
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
