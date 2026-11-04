// Provider catalog: gateway-support helpers and the provider catalog sync
// preview/apply dialog.
import { adminErrorMessage, api } from "../api.js";
import { button, el, setText, showModal, statusMessage } from "../dom.js";
import { confirmDialog } from "../components/modal.js";
import { valueLabel } from "../resources.js";
import { setErrorOutput } from "./output.js";
import { opsNumber, opsText } from "./ops-common.js";

// Rows from /admin-api/models carry gateway_supported (ModelSupportPolicy).
// Older payloads without the flag count as supported.
export function modelIsGatewaySupported(model) {
  return model?.gateway_supported !== false;
}

// GET /admin-api/providers/{id}/models -> { models, hidden_count, hidden_reasons }
// (an older server returned a bare array).
export function providerCatalogModels(data) {
  if (Array.isArray(data)) {
    return { models: data, hiddenNotice: null };
  }
  const models = Array.isArray(data?.models) ? data.models : [];
  const hiddenCount = Number(data?.hidden_count || 0);
  if (hiddenCount <= 0) {
    return { models, hiddenNotice: null };
  }
  const reasons = Object.values(data?.hidden_reasons || {})
    .map((reason) => `${reason.message}（${reason.count} 个${Array.isArray(reason.examples) && reason.examples.length ? `，如 ${reason.examples.slice(0, 3).join("、")}` : ""}）`);
  const notice = el("p", { className: "notice provider-model-import-hidden", text: `已隐藏 ${hiddenCount} 个 TokenHub 无法调用的模型。` });
  notice.title = reasons.join("\n");
  return { models, hiddenNotice: notice };
}

// --- 1. Provider catalog sync ----------------------------------------------

const CATALOG_SYNC_UPSTREAM_LABELS = {
  input_modalities: "输入模态",
  output_modalities: "输出模态",
  features: "功能",
  context_length: "上下文长度",
  max_output_tokens: "最大输出",
  pricing: "上游价格"
};

function catalogSyncChangedFields(change) {
  const parts = [];
  const before = change.before || {};
  const after = change.after || {};
  if (JSON.stringify(before.context_length ?? null) !== JSON.stringify(after.context_length ?? null)) {
    parts.push(`上下文长度 ${opsText(before.context_length, "未知")} → ${opsText(after.context_length, "未知")}`);
  }
  const beforeUpstream = before.upstream && typeof before.upstream === "object" ? before.upstream : {};
  const afterUpstream = after.upstream && typeof after.upstream === "object" ? after.upstream : {};
  const keys = [...new Set([...Object.keys(beforeUpstream), ...Object.keys(afterUpstream)])]
    .filter((key) => JSON.stringify(beforeUpstream[key] ?? null) !== JSON.stringify(afterUpstream[key] ?? null));
  if (keys.length > 0) {
    parts.push(`上游能力变化：${keys.map((key) => CATALOG_SYNC_UPSTREAM_LABELS[key] || key).join("、")}`);
  }
  return parts.length > 0 ? parts.join("；") : "上游能力层有变化";
}

function catalogSyncList(title, items, describe, emptyText, tone = "") {
  const shown = items.slice(0, 200);
  return el("section", { className: `catalog-sync-group ${tone}`.trim() }, [
    el("header", {}, [el("strong", { text: title }), el("span", { text: `${items.length} 个` })]),
    items.length === 0
      ? el("p", { className: "muted", text: emptyText })
      : el("ul", { className: "catalog-sync-list" }, [
        ...shown.map((item) => {
          const [name, detail] = describe(item);
          return el("li", {}, [el("strong", { text: name }), el("small", { text: detail })]);
        }),
        items.length > shown.length ? el("li", { className: "muted", text: `另有 ${items.length - shown.length} 个未显示` }) : null
      ].filter(Boolean))
  ]);
}

function renderCatalogSyncResult(data, applied) {
  const added = Array.isArray(data.added) ? data.added : [];
  const changed = Array.isArray(data.changed) ? data.changed : [];
  const missing = Array.isArray(data.missing) ? data.missing : [];
  const unchanged = Number(data.unchanged) || 0;
  return [
    el("div", { className: "catalog-sync-summary" }, [
      ["新增", added.length],
      ["变更", changed.length],
      ["上游已下线", missing.length],
      ["无变化", unchanged]
    ].map(([label, value]) => el("article", { className: "catalog-sync-count" }, [el("span", { text: label }), el("strong", { text: String(value) })]))),
    statusMessage(applied
      ? "已应用：变更模型的上下文长度与上游能力层已更新，上游缺失的模型已标记缺失时间（不会自动停用）。"
      : "预览不会修改模型。应用后将更新“变更”模型的上下文长度与上游能力层，并为“上游已下线”模型标记缺失时间；“新增”模型不会自动导入，请使用“拉取模型”导入。"),
    ...(Number(data.hidden_unsupported) > 0 ? [statusMessage(`另有 ${Number(data.hidden_unsupported)} 个上游新模型 TokenHub 无法调用，未列出。`)] : []),
    catalogSyncList("新增（上游有，本地未配置）", added, (model) => [
      opsText(model.external_name || model.name),
      [valueLabel("capability", model.capability || "text_generation"), model.context_length ? `上下文 ${opsNumber(model.context_length)}` : ""].filter(Boolean).join(" / ")
    ], "无新增模型", "catalog-sync-group--added"),
    catalogSyncList("变更", changed, (change) => [
      `${opsText(change.external_name)}${change.model_id ? ` #${change.model_id}` : ""}`,
      catalogSyncChangedFields(change)
    ], "无变更模型", "catalog-sync-group--changed"),
    catalogSyncList("上游已下线（本地有，上游目录缺失）", missing, (model) => [
      `${opsText(model.external_name)}${model.model_id ? ` #${model.model_id}` : ""}`,
      `本地状态：${valueLabel("status", model.status || "") || "-"}`
    ], "无下线模型", "catalog-sync-group--missing")
  ];
}

export async function showProviderCatalogSync(row, onSaved) {
  const body = el("div", { className: "catalog-sync" }, [statusMessage("正在对比上游模型目录...")]);
  showModal(`目录同步预览 - ${row.name || row.id}`, body);
  let preview;
  try {
    const result = await api(`/admin-api/providers/${row.id}/catalog-sync`, {
      method: "POST",
      body: JSON.stringify({ apply: false })
    });
    preview = result.data || {};
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "目录同步预览失败，请检查厂商基础地址和密钥"), "error"));
    return;
  }
  const output = el("p", { className: "notice", hidden: true });
  const changedCount = (preview.changed || []).length;
  const missingCount = (preview.missing || []).length;
  const applyButton = button("应用", async () => {
    if (!await confirmDialog({
      title: "应用目录同步",
      message: `确认应用目录同步？将更新 ${changedCount} 个模型，标记 ${missingCount} 个上游缺失模型。`,
      confirmLabel: "应用",
      tone: "primary"
    })) {
      return;
    }
    applyButton.disabled = true;
    try {
      const result = await api(`/admin-api/providers/${row.id}/catalog-sync`, {
        method: "POST",
        body: JSON.stringify({ apply: true })
      });
      const heading = body.closest(".modal-dialog")?.querySelector(".modal-header h2");
      if (heading) {
        setText(heading, `目录同步已应用 - ${row.name || row.id}`);
      }
      body.replaceChildren(...renderCatalogSyncResult(result.data || {}, true));
      await onSaved();
    } catch (error) {
      applyButton.disabled = false;
      setErrorOutput(output, adminErrorMessage(error, "应用目录同步失败"));
    }
  }, "catalog-sync-apply");
  body.replaceChildren(
    ...renderCatalogSyncResult(preview, false),
    el("div", { className: "toolbar-actions" }, [applyButton]),
    output
  );
}
