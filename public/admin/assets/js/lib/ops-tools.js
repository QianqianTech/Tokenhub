// Operations tools shared by the admin pages: provider catalog sync, quota
// status and adjustment, request diagnostics, API key rotation, model
// capability contract editing, agent-management scopes and gateway-support
// helpers. Ported from the former single-file table.js.
import { adminErrorMessage, api } from "../api.js";
import { button, el, readFields, setText, showModal, statusMessage } from "../dom.js";
import { confirmDialog } from "../components/modal.js";
import { getCurrentUser, hasCapability } from "../core/session.js";
import { valueLabel } from "../resources.js";
import { resourceForm } from "./forms.js";
import { oneTimeSecretText, renderDataDetails, setErrorOutput } from "./output.js";

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

// disabled_by: "admin" (console) | "management" (agent management API) |
// null (not recorded; the management API treats it as admin).
export const API_KEY_DISABLED_BY_LABELS = { admin: "管理员", management: "智能体" };

// ---------------------------------------------------------------------------
// Operations tools: provider catalog sync, quota status/adjustment, request
// diagnostics, API key rotation and model capability contract editing.
// ---------------------------------------------------------------------------

// The signed-in administrator comes from core/session.js (set by the app shell).
// Server-side authority is route capability + data scope; these checks only
// hide actions the server would refuse anyway.
function adminViewerIsSuperAdmin() {
  return getCurrentUser()?.role === "super_admin";
}

function adminViewerCan(capability) {
  return hasCapability(capability);
}

// Mirrors PermissionService::usesGlobalScope (catalog sync, operations).
function adminViewerHasGlobalScope() {
  const viewer = getCurrentUser();
  return adminViewerIsSuperAdmin() || (viewer?.role === "admin" && viewer?.admin_scope === "global");
}

export function adminViewerCanSyncCatalog() {
  return adminViewerHasGlobalScope() && adminViewerCan("api_catalog.manage");
}

function opsText(value, fallback = "-") {
  if (value === null || value === undefined || value === "") {
    return fallback;
  }
  return String(value);
}

function opsNumber(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return opsText(value);
  }
  return Number.isInteger(number) ? number.toLocaleString("zh-CN") : number.toLocaleString("zh-CN", { maximumFractionDigits: 6 });
}

function opsUtcTime(value) {
  if (!value) {
    return "-";
  }
  const raw = String(value);
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw) ? `${raw.replace(" ", "T")}Z` : raw;
  const parsed = new Date(iso);
  return Number.isNaN(parsed.getTime()) ? raw : parsed.toLocaleString("zh-CN", { hour12: false });
}

function opsDetailGrid(pairs) {
  return el("div", { className: "ops-detail-grid" }, pairs.map(([label, value]) => el("div", { className: "ops-detail-row" }, [
    el("span", { text: label }),
    el("strong", { text: opsText(value) })
  ])));
}

function opsSection(title, children, className = "") {
  return el("section", { className: `ops-section ${className}`.trim() }, [
    el("h3", { text: title }),
    ...[].concat(children)
  ]);
}

async function copyTextToClipboard(text, feedback, sourceNode = null) {
  try {
    await navigator.clipboard.writeText(text);
    setText(feedback, "已复制到剪贴板");
  } catch {
    if (sourceNode && window.getSelection) {
      const range = document.createRange();
      range.selectNodeContents(sourceNode);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }
    setText(feedback, "浏览器未允许自动复制，已选中密钥，请按 Ctrl+C 手动复制");
  }
  feedback.hidden = false;
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

// --- 2. Quota status & adjustment ------------------------------------------

const QUOTA_METRICS = [
  ["request_count", "requests", "请求次数"],
  ["input_tokens", "input_tokens", "输入 Token"],
  ["output_tokens", "output_tokens", "输出 Token"],
  ["total_tokens", "total_tokens", "总 Token"],
  ["audio_seconds", "audio_seconds", "音频秒数"],
  ["tts_chars", "tts_chars", "TTS 字符"],
  ["image_count", "image_count", "图片数"],
  ["video_seconds", "video_seconds", "视频秒数"],
  ["cost_amount", "cost_amount", "成本"]
];

const QUOTA_TARGET_LABELS = { api_key: "API 密钥", user: "用户", department: "部门" };

function quotaTargetText(targetType, targetId) {
  return `${QUOTA_TARGET_LABELS[targetType] || targetType || "-"} #${opsText(targetId)}`;
}

function quotaMetricTable(item) {
  const limits = item.limits || {};
  const usage = item.usage || {};
  const reserved = usage.reserved_in_flight && typeof usage.reserved_in_flight === "object" ? usage.reserved_in_flight : {};
  const remaining = item.remaining || {};
  const rows = QUOTA_METRICS.filter(([name]) => name in limits || name in usage)
    .filter(([name]) => limits[name] !== null && limits[name] !== undefined || Number(usage[name]) > 0 || Number(reserved[name]) > 0);
  if (rows.length === 0) {
    return el("p", { className: "muted", text: "未设置限额，当前窗口暂无用量" });
  }
  const table = el("table", { className: "resource-table quota-status-table" });
  table.append(el("thead", {}, [el("tr", {}, ["指标", "限额", "已用（含预占）", "进行中预占", "剩余", "使用率"].map((label) => el("th", { text: label })))]));
  table.append(el("tbody", {}, rows.map(([name, , label]) => {
    const limit = limits[name];
    const used = Number(usage[name]) || 0;
    const hasLimit = limit !== null && limit !== undefined;
    const percent = hasLimit && Number(limit) > 0 ? Math.min(100, Math.round(used / Number(limit) * 100)) : null;
    const meter = el("div", { className: `quota-meter ${percent !== null && percent >= 90 ? "quota-meter--high" : ""}`.trim(), title: percent === null ? "不限" : `${percent}%` }, [
      el("span", { style: `width:${percent ?? 0}%` })
    ]);
    return el("tr", {}, [
      el("td", { text: label }),
      el("td", { text: hasLimit ? opsNumber(limit) : "不限" }),
      el("td", { text: opsNumber(used) }),
      el("td", { text: reserved[name] ? opsNumber(reserved[name]) : "0" }),
      el("td", { text: hasLimit ? opsNumber(remaining[name]) : "不限" }),
      el("td", {}, [meter, el("small", { text: percent === null ? "不限" : `${percent}%` })])
    ]);
  })));
  return el("div", { className: "quota-status-table-wrap" }, [table]);
}

function quotaPolicyCard(policy) {
  return el("article", { className: "quota-status-card" }, [
    el("header", {}, [
      el("strong", { text: policy.name || `策略 #${policy.policy_id}` }),
      el("span", { text: `${quotaTargetText(policy.target_type, policy.target_id)} · 周期 ${valueLabel("period", policy.period || "")} · 重置于 ${opsUtcTime(policy.reset_at)}` })
    ]),
    quotaMetricTable(policy)
  ]);
}

function quotaModelCard(quota) {
  return el("article", { className: "quota-status-card" }, [
    el("header", {}, [
      el("strong", { text: quota.model_name || `模型 #${quota.model_id}` }),
      el("span", { text: `模型限额 #${opsText(quota.quota_id)} · 周期 ${valueLabel("period", quota.period || "")} · 重置于 ${opsUtcTime(quota.reset_at)}` })
    ]),
    quotaMetricTable(quota)
  ]);
}

function quotaAdjustmentForm(policies, onAdjusted) {
  const fields = [
    {
      name: "policy_ref",
      label: "额度策略",
      type: "select",
      options: policies.map((policy) => [
        `${policy.policy_id}:${policy.target_type}:${policy.target_id}`,
        `${policy.name || `策略 #${policy.policy_id}`} / ${quotaTargetText(policy.target_type, policy.target_id)}`
      ])
    },
    { name: "metric", label: "指标", type: "select", options: QUOTA_METRICS.map(([, metric, label]) => [metric, label]) },
    { name: "amount", label: "调整量（正数增加已用量，负数返还额度）", type: "number" },
    { name: "reason", label: "原因（必填，写入审计日志）", type: "textarea", rows: 2 }
  ];
  const form = resourceForm(fields, { metric: "total_tokens", policy_ref: fields[0].options[0]?.[0] || "" });
  form.classList.add("quota-adjust-form");
  const amountInput = form.querySelector('[name="amount"]');
  amountInput.step = "any";
  const output = el("p", { className: "notice", hidden: true });
  const submit = button("提交调整", async () => {
    const values = readFields(form, fields);
    const [policyId, targetType, targetId] = String(values.policy_ref || "").split(":");
    const amount = Number(values.amount);
    const reason = String(values.reason || "").trim();
    if (!policyId || !targetType || !targetId) {
      setErrorOutput(output, "请选择额度策略");
      return;
    }
    if (!Number.isFinite(amount) || amount === 0) {
      setErrorOutput(output, "调整量需为非零数字");
      return;
    }
    if (reason === "") {
      setErrorOutput(output, "请填写调整原因");
      return;
    }
    submit.disabled = true;
    try {
      const result = await api("/admin-api/quota-adjustments", {
        method: "POST",
        body: JSON.stringify({
          policy_id: Number(policyId),
          target_type: targetType,
          target_id: Number(targetId),
          metric: values.metric,
          amount,
          reason
        })
      });
      const data = result.data || {};
      const metricLabel = QUOTA_METRICS.find(([, metric]) => metric === data.metric)?.[2] || data.metric;
      await onAdjusted(`已调整 ${metricLabel}：${opsNumber(data.before)} → ${opsNumber(data.after)}（窗口 ${opsText(data.window)}）`);
    } catch (error) {
      submit.disabled = false;
      setErrorOutput(output, adminErrorMessage(error, "额度调整失败"));
    }
  });
  form.append(submit, output);
  return opsSection("额度调整", [
    el("p", { className: "muted", text: "仅修正当前窗口计数（例如上游故障后返还额度），不会修改历史用量记录。" }),
    form
  ], "quota-adjust");
}

function renderQuotaStatus(body, data, reload, flash = "") {
  const policies = Array.isArray(data.policies) ? data.policies : [];
  const perModel = Array.isArray(data.per_model) ? data.per_model : [];
  const children = [];
  if (flash) {
    children.push(statusMessage(flash));
  }
  children.push(el("p", { className: "muted", text: "已用量包含进行中请求的预占额度；请求结束后预占会按实际用量结算。" }));
  if (policies.length === 0 && perModel.length === 0) {
    children.push(statusMessage("该密钥未绑定生效中的额度策略或模型限额"));
  }
  if (policies.length > 0) {
    children.push(opsSection("额度策略", policies.map(quotaPolicyCard)));
  }
  if (perModel.length > 0) {
    children.push(opsSection("模型限额", perModel.map(quotaModelCard)));
  }
  children.push(el("div", { className: "toolbar-actions" }, [button("刷新", () => reload(), "secondary")]));
  if (adminViewerCan("quotas.manage") && policies.length > 0) {
    children.push(quotaAdjustmentForm(policies, (message) => reload(message)));
  }
  body.replaceChildren(...children);
}

export async function showApiKeyQuota(row) {
  const body = el("div", { className: "quota-status-modal" }, [statusMessage("正在加载额度...")]);
  showModal(`额度 · ${row.name || row.id}`, body);
  const load = async (flash = "") => {
    try {
      const result = await api("/admin-api/quota-status", { query: { api_key_id: row.id } });
      renderQuotaStatus(body, result.data || {}, load, flash);
    } catch (error) {
      body.replaceChildren(statusMessage(adminErrorMessage(error, "额度加载失败"), "error"));
    }
  };
  await load();
}

// --- 3. Request diagnostics -------------------------------------------------

const DIAGNOSTIC_CATEGORY_LABELS = {
  permission: "权限",
  configuration: "配置",
  quota: "额度",
  policy: "策略",
  client: "客户端",
  upstream: "上游",
  rag: "RAG",
  unknown: "未知"
};

const DIAGNOSTIC_CAUSES_ZH = {
  MODEL_FORBIDDEN: ["该 API 密钥没有此模型或通道的授权。", "在 API 密钥中授权该模型，或调用已授权的模型。"],
  MODEL_NOT_FOUND: ["未配置该名称的模型。", "检查模型名称，或从厂商目录导入该模型。"],
  MODEL_UNAVAILABLE: ["该模型的所有通道均已停用或下线。", "启用一个通道，或让密钥改用其他模型。"],
  MODEL_CAPABILITY_UNSUPPORTED: ["没有启用的通道支持该接口或请求的功能（工具、JSON Schema、推理、图片）。", "改用能力契约包含该功能的模型，或在验证后更新能力契约。"],
  CAPABILITY_UNAVAILABLE: ["该能力只有 native 通道，且没有执行后端。", "为该能力配置 OpenAI/Anthropic 兼容通道（如火山方舟）。"],
  QUOTA_EXCEEDED: ["绑定到密钥、用户或部门的额度策略在当前窗口已用尽（进行中请求的预占也计入）。", "等待窗口重置、提高限额，或执行可审计的额度调整。"],
  MODEL_QUOTA_EXCEEDED: ["该密钥的模型限额已用尽。", "提高该密钥的模型限额，或等待重置。"],
  CONCURRENCY_EXCEEDED: ["绑定策略的并发请求过多。", "降低并发，或提高并发限制。"],
  PRICE_UNKNOWN: ["存在成本限额，但模型未配置价格，且未知价格策略为拒绝。", "为模型添加价格，或放宽未知价格策略。"],
  DESENSITIZATION_BLOCKED: ["出站内容（包括注入的 RAG 上下文）命中了阻断型脱敏规则。", "按该请求 ID 查看脱敏事件。"],
  ROUTING_PARAMETER_FORBIDDEN: ["请求试图修改超出通道策略的聚合路由参数（provider/plugins/models）。", "移除该参数，或在厂商上配置客户端路由策略。"],
  ROUTING_AUTO_ROUTER_FORBIDDEN: ["自动路由会选择 TokenHub 授权范围外的模型。", "使用明确的模型，或在厂商上允许自动路由。"],
  TOOL_CALL_MISMATCH: ["工具结果引用了会话中从未发出的工具调用 ID。", "先发送带 tool_calls 的助手消息，再发送对应的工具结果。"],
  TOOL_CHOICE_UNKNOWN: ["tool_choice 指定了未声明的工具。", "在 tools 中声明该工具，或修改 tool_choice。"],
  OUTBOUND_BLOCKED: ["通道地址解析到私有、回环或元数据地址。", "私有推理服务需在厂商上允许私有网络，或配置出站私网白名单。"],
  PROVIDER_ENDPOINT_UNSUPPORTED: ["通道的接口映射标记该接口不受支持。", "将请求路由到支持该接口的通道。"],
  PROVIDER_TIMEOUT: ["上游在超时时间内未响应（或流式输出中断）。", "检查上游状态；长生成任务可提高超时毫秒数。"],
  PROVIDER_RATE_LIMITED: ["所有尝试的通道均返回 429 限流。", "遵循 Retry-After、增加通道或提高上游账号限额。"],
  PROVIDER_REJECTED: ["上游拒绝了请求（4xx）：参数、鉴权或账户状态问题。", "检查厂商密钥、账户余额和请求参数。"],
  PROVIDER_ERROR: ["每次尝试上游都返回 5xx。", "检查上游状态；熔断器会绕开故障通道。"],
  PROVIDER_STREAM_ERROR: ["流式输出开始后上游发送了错误事件。", "查看留存输出中的上游错误，通常是过载或内容策略。"],
  PROVIDER_STREAM_INCOMPLETE: ["流式输出未收到结束事件就中断了。", "按截断处理输出，检查上游与网络稳定性。"],
  CLIENT_CANCELLED: ["客户端断开连接，已按断开前的用量计费。", "若非预期，请检查客户端超时设置。"],
  RAG_REQUIRED_NO_CONTEXT: ["要求使用 RAG，但没有数据集或上下文达到阈值。", "为密钥绑定数据集，或降低检索阈值。"],
  RAG_RETRIEVAL_FAILED: ["必需的检索失败（向量库或向量化服务）。", "检查 Qdrant 与向量化通道的健康状态。"],
  API_KEY_INVALID: ["密钥不存在、已停用或已删除，或其员工/部门已停用。", "签发或重新启用密钥。"],
  API_KEY_EXPIRED: ["密钥已超过过期时间（包括轮换宽限期结束后）。", "让客户端切换到轮换后的新密钥。"]
};

function renderRequestDiagnostics(data) {
  if (!data.found) {
    return [
      statusMessage("尚未找到该请求的用量记录", "error"),
      el("p", { className: "muted", text: "请求可能仍在排队写入（可在“运行状态”查看队列积压），或请求 ID 有误。" }),
      data.hint ? el("p", { className: "ops-original-text", text: data.hint }) : null
    ].filter(Boolean);
  }
  const children = [
    opsDetailGrid([
      ["状态", valueLabel("status", data.status || "") || data.status],
      ["HTTP 状态", data.http_status],
      ["错误码", data.error_code || "无"],
      ["耗时（毫秒）", data.latency_ms],
      ["创建时间", data.created_at],
      ["请求 ID", data.request_id]
    ])
  ];
  const explanation = data.explanation;
  if (data.error_code && explanation) {
    const translated = DIAGNOSTIC_CAUSES_ZH[data.error_code];
    children.push(el("section", { className: `request-diagnostics-explanation request-diagnostics-explanation--${explanation.category || "unknown"}` }, [
      el("header", {}, [
        el("strong", { text: "原因与处理建议" }),
        el("span", { className: "status-badge", text: DIAGNOSTIC_CATEGORY_LABELS[explanation.category] || explanation.category || "未知" })
      ]),
      el("p", {}, [el("b", { text: "原因：" }), document.createTextNode(translated?.[0] || explanation.cause || "-")]),
      el("p", {}, [el("b", { text: "处理建议：" }), document.createTextNode(translated?.[1] || explanation.action || "-")]),
      translated ? el("p", { className: "ops-original-text", text: `${explanation.cause || ""} ${explanation.action || ""}`.trim() }) : null
    ].filter(Boolean)));
  } else if (!data.error_code) {
    children.push(statusMessage("该请求没有记录错误码。"));
  }
  const channel = data.channel;
  if (channel) {
    children.push(opsSection("通道", opsDetailGrid([
      ["模型", `${opsText(channel.name)} #${opsText(channel.id)}`],
      ["上游模型名", channel.external_name],
      ["模型状态", valueLabel("status", channel.status || "") || channel.status],
      ["厂商", `${opsText(channel.provider_name)} #${opsText(channel.provider_id)}`],
      ["厂商状态", valueLabel("status", channel.provider_status || "") || channel.provider_status]
    ])));
  }
  if (Array.isArray(data.attempts) && data.attempts.length > 0) {
    children.push(opsSection("通道尝试", renderDataDetails(data.attempts)));
  }
  const usage = data.usage || {};
  children.push(opsSection("用量与计费", opsDetailGrid([
    ["用量来源", usage.source],
    ["输入 Token", usage.input_tokens],
    ["输出 Token", usage.output_tokens],
    ["缓存读取 Token", usage.cache_read_tokens],
    ["推理 Token", usage.reasoning_tokens],
    ["成本", usage.cost_amount === null || usage.cost_amount === undefined ? null : `${usage.cost_amount} ${usage.cost_currency || ""}`.trim()],
    ["上游成本", usage.upstream_cost],
    ["对账时间", usage.reconciled_at]
  ])));
  const stream = data.stream || {};
  const upstream = data.upstream || {};
  children.push(opsSection("流式与上游", opsDetailGrid([
    ["流式结果", stream.outcome],
    ["首字节耗时（毫秒）", stream.first_byte_ms],
    ["上游请求 ID", upstream.request_id],
    ["上游模型", upstream.model],
    ["上游厂商", upstream.provider],
    ["聚合时间", data.persistence?.aggregated_at],
    ["事件发送时间", data.persistence?.events_emitted_at]
  ])));
  return children;
}

export async function showRequestDiagnostics(requestId) {
  const id = String(requestId || "").trim();
  if (id === "") {
    showModal("请求诊断", statusMessage("请输入请求 ID", "error"));
    return;
  }
  const body = el("div", { className: "request-diagnostics" }, [statusMessage("正在诊断请求...")]);
  showModal(`请求诊断 · ${id}`, body);
  try {
    const result = await api(`/admin-api/diagnostics/requests/${encodeURIComponent(id)}`);
    body.replaceChildren(...renderRequestDiagnostics(result.data || {}));
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "请求诊断失败"), "error"));
  }
}

export function renderRequestDiagnosticsLookup() {
  const input = el("input", {
    type: "search",
    name: "diagnose_request_id",
    className: "request-diagnostics-input",
    placeholder: "按请求 ID 诊断",
    ariaLabel: "按请求 ID 诊断"
  });
  const run = () => showRequestDiagnostics(input.value);
  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      event.stopPropagation();
      void run();
    }
  });
  return el("div", { className: "request-diagnostics-lookup" }, [input, button("诊断", run, "secondary")]);
}

// --- 4. API key rotation ----------------------------------------------------

export function showApiKeyRotate(row, onRefresh) {
  const fields = [{ name: "grace_minutes", label: "旧密钥宽限期（分钟，0–10080）", type: "number" }];
  const form = resourceForm(fields, { grace_minutes: 60 });
  form.classList.add("api-key-rotate-form");
  const graceInput = form.querySelector('[name="grace_minutes"]');
  graceInput.min = "0";
  graceInput.max = "10080";
  graceInput.step = "1";
  const output = el("p", { className: "notice", hidden: true });
  form.prepend(statusMessage(`将为 API 密钥 ${row.name || row.id} 生成新的明文密钥，并复制原密钥的模型授权、模型限额与知识库绑定。旧密钥在宽限期结束后失效，0 表示立即失效。`));
  const submit = button("确认轮换", async () => {
    const raw = graceInput.value.trim();
    const graceMinutes = raw === "" ? Number.NaN : Number(raw);
    if (!Number.isInteger(graceMinutes) || graceMinutes < 0 || graceMinutes > 10080) {
      setErrorOutput(output, "宽限期需为 0 到 10080 之间的整数分钟");
      return;
    }
    submit.disabled = true;
    try {
      const result = await api(`/admin-api/api-keys/${row.id}/rotate`, {
        method: "POST",
        body: JSON.stringify({ grace_minutes: graceMinutes })
      });
      showApiKeyRotationResult(row, result.data || {}, graceMinutes);
      await onRefresh();
    } catch (error) {
      submit.disabled = false;
      setErrorOutput(output, adminErrorMessage(error, "轮换失败，请确认密钥状态和权限"));
    }
  }, "danger");
  form.append(submit, output);
  showModal(`轮换 API 密钥 #${row.id}`, form);
}

function showApiKeyRotationResult(row, data, graceMinutes) {
  const secret = el("p", { className: "notice secret-output api-key-rotate-secret", text: oneTimeSecretText(data) });
  const feedback = el("p", { className: "notice", hidden: true });
  const copyButton = button("复制新密钥", () => copyTextToClipboard(data.key || "", feedback, secret), "secondary");
  copyButton.disabled = !data.key;
  const expiry = graceMinutes === 0
    ? `旧密钥 #${opsText(data.replaces_api_key_id, row.id)} 已立即失效。`
    : `旧密钥 #${opsText(data.replaces_api_key_id, row.id)} 将于 ${opsUtcTime(data.old_key_expires_at)}（UTC ${opsText(data.old_key_expires_at)}）失效，请在此之前把客户端切换到新密钥。`;
  showModal("API 密钥已轮换", el("div", { className: "api-key-rotate-result" }, [
    statusMessage("新密钥明文只显示这一次，关闭后无法再次查看，请立即复制保存。", "error"),
    secret,
    el("div", { className: "toolbar-actions" }, [copyButton]),
    feedback,
    opsDetailGrid([
      ["新密钥 ID", data.id],
      ["新密钥显示", data.display],
      ["替换的旧密钥", data.replaces_api_key_id],
      ["旧密钥失效时间", data.old_key_expires_at ? `${opsUtcTime(data.old_key_expires_at)}` : null]
    ]),
    statusMessage(expiry)
  ]));
}

// --- 5. Model capability contract editor -----------------------------------

const MODEL_CAPABILITY_FEATURES = [
  ["tools", "工具调用"],
  ["parallel_tool_calls", "并行工具调用"],
  ["json_object", "JSON 对象输出"],
  ["json_schema", "JSON Schema 结构化输出"],
  ["reasoning", "推理"],
  ["streaming", "流式输出"]
];

const MODEL_CAPABILITY_MODALITIES = {
  input_modalities: { label: "输入模态", options: [["text", "文本"], ["image", "图片"], ["audio", "音频"], ["file", "文件"]] },
  output_modalities: { label: "输出模态", options: [["text", "文本"], ["image", "图片"], ["audio", "音频"], ["embedding", "向量"]] }
};

const MODEL_CAPABILITY_LIMITS = [
  ["context_length", "上下文长度"],
  ["max_output_tokens", "最大输出 Token"]
];

const MODEL_CAPABILITY_TAGS = ["text_generation", "vision_understanding", "embedding", "asr", "tts", "image_generation", "video_generation", "rerank", "moderation"];

function parseJsonValue(value) {
  if (typeof value === "string") {
    const trimmed = value.trim();
    if (trimmed === "") {
      return null;
    }
    try {
      return JSON.parse(trimmed);
    } catch {
      return null;
    }
  }
  return value ?? null;
}

function plainObject(value) {
  return value && typeof value === "object" && !Array.isArray(value) ? value : {};
}

function capabilityFeatureText(value) {
  if (value === true) return "支持";
  if (value === false) return "不支持";
  return "未声明";
}

function capabilityListText(value, options) {
  if (!Array.isArray(value)) {
    return "未声明";
  }
  const labels = new Map(options);
  return value.length === 0 ? "无" : value.map((item) => labels.get(item) || item).join("、");
}

function stableJson(value) {
  if (Array.isArray(value)) {
    return `[${value.map(stableJson).join(",")}]`;
  }
  if (value && typeof value === "object") {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableJson(value[key])}`).join(",")}}`;
  }
  return JSON.stringify(value ?? null);
}

export function createModelCapabilityEditor(row) {
  const hasLayers = Object.prototype.hasOwnProperty.call(row, "capabilities");
  const hasTags = Object.prototype.hasOwnProperty.call(row, "capability_tags");
  if (!hasLayers && !hasTags) {
    return null;
  }
  const baseline = { layers: plainObject(parseJsonValue(row.capabilities)), tags: parseJsonValue(row.capability_tags), updatedAt: row.updated_at || "" };
  const node = el("fieldset", { className: "model-capability-editor" }, [el("legend", { text: "能力契约" })]);
  const controls = { features: {}, modalities: {}, limits: {} };
  let tagInputs = [];
  let legacyWarning = null;

  const currentLayers = () => baseline.layers;
  const lowerValue = (key) => {
    const verified = plainObject(currentLayers().verified);
    const upstream = plainObject(currentLayers().upstream);
    return verified[key] !== undefined ? verified[key] : upstream[key];
  };
  const lowerFeature = (feature) => {
    const verified = plainObject(plainObject(currentLayers().verified).features);
    const upstream = plainObject(plainObject(currentLayers().upstream).features);
    if (feature in verified) return verified[feature];
    if (feature in upstream) return upstream[feature];
    return undefined;
  };

  if (hasLayers) {
    const layers = currentLayers();
    const upstream = plainObject(layers.upstream);
    const verified = plainObject(layers.verified);
    const admin = plainObject(layers.admin);
    const upstreamFeatures = plainObject(upstream.features);
    const verifiedFeatures = plainObject(verified.features);
    const adminFeatures = plainObject(admin.features);
    node.append(el("p", { className: "muted", text: "生效值 = 管理员覆盖 > 已验证 > 上游目录。上游与已验证层由目录同步和验收测试维护，此处只读；这里只修改管理员覆盖层。" }));
    legacyWarning = el("p", { className: "notice error", hidden: true, text: "该模型目前没有能力契约（旧版模型，仅按“能力”字段路由）。保存管理员覆盖后将启用能力契约：未声明为“支持”的功能（包括流式输出）将被视为不支持。" });
    node.append(legacyWarning);

    const featureTable = el("table", { className: "resource-table model-capability-table" });
    featureTable.append(el("thead", {}, [el("tr", {}, ["功能", "上游", "已验证", "管理员覆盖", "生效"].map((label) => el("th", { text: label })))]));
    const featureBody = el("tbody");
    for (const [feature, label] of MODEL_CAPABILITY_FEATURES) {
      const select = el("select", { name: `capability_feature_${feature}`, className: "model-capability-override", dataset: { feature } }, [
        el("option", { value: "", text: "继承" }),
        el("option", { value: "true", text: "支持" }),
        el("option", { value: "false", text: "不支持" })
      ]);
      select.value = feature in adminFeatures ? String(Boolean(adminFeatures[feature])) : "";
      const effective = el("td", { className: "model-capability-effective" });
      controls.features[feature] = { select, effective };
      featureBody.append(el("tr", {}, [
        el("td", { text: label }),
        el("td", { text: capabilityFeatureText(upstreamFeatures[feature]) }),
        el("td", { text: capabilityFeatureText(verifiedFeatures[feature]) }),
        el("td", {}, [select]),
        effective
      ]));
    }
    featureTable.append(featureBody);
    node.append(el("div", { className: "model-capability-table-wrap" }, [featureTable]));

    for (const [key, config] of Object.entries(MODEL_CAPABILITY_MODALITIES)) {
      const override = el("input", { type: "checkbox", name: `capability_override_${key}`, checked: Array.isArray(admin[key]) });
      const initial = Array.isArray(admin[key]) ? admin[key] : (Array.isArray(lowerValue(key)) ? lowerValue(key) : ["text"]);
      const checkboxes = config.options.map(([value, label]) => {
        const checkbox = el("input", { type: "checkbox", name: `capability_${key}`, value, checked: initial.includes(value) });
        return { checkbox, wrapper: el("label", { className: "multiselect-item" }, [checkbox, el("span", { text: label })]) };
      });
      const effective = el("small", { className: "model-capability-effective" });
      controls.modalities[key] = { override, checkboxes, effective };
      node.append(el("div", { className: "model-capability-modality" }, [
        el("div", { className: "model-capability-modality-head" }, [
          el("strong", { text: config.label }),
          el("span", { className: "muted", text: `上游：${capabilityListText(upstream[key], config.options)}；已验证：${capabilityListText(verified[key], config.options)}` })
        ]),
        el("label", { className: "model-capability-override-toggle" }, [override, el("span", { text: "管理员覆盖（整组替换）" })]),
        el("div", { className: "model-capability-choices" }, checkboxes.map((item) => item.wrapper)),
        effective
      ]));
    }

    const limitRow = el("div", { className: "model-capability-limits" });
    for (const [key, label] of MODEL_CAPABILITY_LIMITS) {
      const lower = lowerValue(key);
      const input = el("input", {
        type: "number",
        name: `capability_${key}`,
        min: "1",
        step: "1",
        placeholder: lower ? `继承：${lower}` : "继承（未声明）",
        value: admin[key] !== undefined && admin[key] !== null ? String(admin[key]) : ""
      });
      controls.limits[key] = input;
      limitRow.append(el("label", { className: "field" }, [el("span", { text: `${label}覆盖（留空继承）` }), input]));
    }
    node.append(limitRow);
  }

  if (hasTags) {
    const currentTags = new Set(Array.isArray(baseline.tags) ? baseline.tags.map(String) : []);
    tagInputs = MODEL_CAPABILITY_TAGS.map((tag) => el("input", { type: "checkbox", name: "capability_tags", value: tag, checked: currentTags.has(tag) }));
    node.append(el("div", { className: "model-capability-tags" }, [
      el("strong", { text: "附加能力标签（路由匹配）" }),
      el("span", { className: "muted", text: "模型也会响应这些能力的请求。注意：应用厂商目录同步时会按上游重置该标签。" }),
      el("div", { className: "model-capability-choices" }, tagInputs.map((input) => el("label", { className: "multiselect-item" }, [input, el("span", { text: valueLabel("capability", input.value) })])))
    ]));
  }

  const nextAdminLayer = () => {
    const admin = { ...plainObject(currentLayers().admin) };
    const features = { ...plainObject(admin.features) };
    for (const [feature, control] of Object.entries(controls.features)) {
      if (control.select.value === "") {
        delete features[feature];
      } else {
        features[feature] = control.select.value === "true";
      }
    }
    if (Object.keys(features).length > 0) {
      admin.features = features;
    } else {
      delete admin.features;
    }
    for (const [key, control] of Object.entries(controls.modalities)) {
      if (control.override.checked) {
        admin[key] = control.checkboxes.filter((item) => item.checkbox.checked).map((item) => item.checkbox.value);
      } else {
        delete admin[key];
      }
    }
    for (const [key, input] of Object.entries(controls.limits)) {
      const raw = input.value.trim();
      if (raw === "") {
        delete admin[key];
        continue;
      }
      const number = Number(raw);
      if (!Number.isInteger(number) || number <= 0) {
        const error = new Error("invalid capability limit");
        error.userMessage = `${MODEL_CAPABILITY_LIMITS.find(([name]) => name === key)?.[1] || key}覆盖需为正整数`;
        throw error;
      }
      admin[key] = number;
    }
    return admin;
  };

  const refreshEffective = () => {
    if (!hasLayers) {
      return;
    }
    let admin = {};
    try {
      admin = nextAdminLayer();
    } catch {
      admin = plainObject(currentLayers().admin);
    }
    const adminFeatures = plainObject(admin.features);
    for (const [feature, control] of Object.entries(controls.features)) {
      const value = feature in adminFeatures ? adminFeatures[feature] : lowerFeature(feature);
      setText(control.effective, capabilityFeatureText(value));
      control.effective.className = `model-capability-effective ${value === true ? "is-supported" : value === false ? "is-unsupported" : "is-unknown"}`;
    }
    for (const [key, control] of Object.entries(controls.modalities)) {
      for (const item of control.checkboxes) {
        item.checkbox.disabled = !control.override.checked;
      }
      const value = Array.isArray(admin[key]) ? admin[key] : lowerValue(key);
      setText(control.effective, `生效：${capabilityListText(value, MODEL_CAPABILITY_MODALITIES[key].options)}`);
    }
    const isLegacy = Object.keys(currentLayers()).filter((layer) => Object.keys(plainObject(currentLayers()[layer])).length > 0).length === 0;
    if (legacyWarning) {
      legacyWarning.hidden = !(isLegacy && Object.keys(admin).length > 0);
    }
  };
  node.addEventListener("change", refreshEffective);
  node.addEventListener("input", refreshEffective);
  refreshEffective();

  return {
    node,
    payload() {
      const payload = {};
      if (hasLayers) {
        const before = plainObject(currentLayers().admin);
        const admin = nextAdminLayer();
        if (stableJson(admin) !== stableJson(before)) {
          const layers = { ...currentLayers() };
          if (Object.keys(admin).length > 0) {
            layers.admin = admin;
          } else {
            delete layers.admin;
          }
          payload.capabilities = Object.keys(layers).length > 0 ? layers : null;
        }
      }
      if (hasTags) {
        const selected = tagInputs.filter((input) => input.checked).map((input) => input.value);
        const before = Array.isArray(baseline.tags) ? baseline.tags.map(String) : [];
        if (stableJson([...selected].sort()) !== stableJson([...before].sort())) {
          payload.capability_tags = selected;
        }
      }
      if (Object.keys(payload).length > 0 && baseline.updatedAt) {
        payload.expected_updated_at = baseline.updatedAt;
      }
      return payload;
    },
    commit(savedRow = {}) {
      if (Object.prototype.hasOwnProperty.call(savedRow, "capabilities")) {
        baseline.layers = plainObject(parseJsonValue(savedRow.capabilities));
      }
      if (Object.prototype.hasOwnProperty.call(savedRow, "capability_tags")) {
        baseline.tags = parseJsonValue(savedRow.capability_tags);
      }
      if (savedRow.updated_at) {
        baseline.updatedAt = savedRow.updated_at;
      }
      refreshEffective();
    }
  };
}

// --- Agent management scopes on API keys -----------------------------------

const API_KEY_MANAGEMENT_SCOPES = [
  ["users:read", "用户（只读）"],
  ["users:write", "用户（读写）"],
  ["api_keys:read", "API Key（只读）"],
  ["api_keys:write", "API Key（读写）"],
  ["rag:read", "知识库（只读）"],
  ["rag:write", "知识库（读写）"],
  ["usage:read", "用量（只读）"]
];

function apiKeyManagementScopeList(value) {
  const parsed = parseJsonValue(value);
  const list = Array.isArray(parsed) ? parsed : (typeof value === "string" && value.trim() !== "" ? value.split(",") : []);
  const known = new Set(API_KEY_MANAGEMENT_SCOPES.map(([scope]) => scope));
  return [...new Set(list.map((scope) => String(scope).trim()).filter((scope) => known.has(scope)))];
}

// "*:write" implies "*:read" server-side; compare sets without the implied reads.
function normalizeManagementScopes(scopes) {
  const set = new Set(scopes);
  return API_KEY_MANAGEMENT_SCOPES.map(([scope]) => scope)
    .filter((scope) => set.has(scope))
    .filter((scope) => !(scope.endsWith(":read") && set.has(scope.replace(/:read$/, ":write"))));
}

function apiKeyManagementScopeLabels(scopes) {
  const labels = new Map(API_KEY_MANAGEMENT_SCOPES);
  return scopes.map((scope) => labels.get(scope) || scope);
}

export function apiKeyManagementBadge(row) {
  const scopes = apiKeyManagementScopeList(row.management_scopes);
  if (scopes.length === 0) {
    return null;
  }
  return el("span", {
    className: "api-key-management-badge",
    text: "管理",
    title: `智能体管理权限：${apiKeyManagementScopeLabels(scopes).join("、")}`
  });
}

export function createApiKeyManagementScopeEditor(initialScopes, options = {}) {
  let baseline = normalizeManagementScopes(apiKeyManagementScopeList(initialScopes));
  const initial = new Set(apiKeyManagementScopeList(initialScopes));
  const inputs = new Map();
  const list = el("div", { className: "api-key-management-scope-list" });
  for (const [scope, label] of API_KEY_MANAGEMENT_SCOPES) {
    const input = el("input", { type: "checkbox", name: "management_scopes", value: scope, checked: initial.has(scope) });
    inputs.set(scope, input);
    list.append(el("label", { className: "multiselect-item" }, [input, el("span", { text: label })]));
  }
  const syncImpliedReads = () => {
    for (const [scope, input] of inputs) {
      if (!scope.endsWith(":write")) {
        continue;
      }
      const read = inputs.get(scope.replace(/:write$/, ":read"));
      if (!read) {
        continue;
      }
      if (input.checked) {
        if (!read.disabled) {
          read.dataset.explicit = read.checked ? "1" : "0";
        }
        read.checked = true;
        read.disabled = true;
        read.title = "已由读写权限包含";
      } else if (read.disabled) {
        read.disabled = false;
        read.checked = read.dataset.explicit === "1";
        read.title = "";
      }
    }
  };
  list.addEventListener("change", syncImpliedReads);
  syncImpliedReads();
  const selected = () => [...inputs.entries()]
    .filter(([, input]) => input.checked && !input.disabled)
    .map(([scope]) => scope);
  const summary = el("summary", { text: initial.size > 0 ? `智能体管理权限（已授予 ${initial.size} 项）` : "智能体管理权限" });
  const node = el("details", { className: "api-key-management-scopes", open: baseline.length > 0 }, [
    summary,
    el("p", { className: "muted", text: "授予后该 Key 可调用 /v1/management/* 管理本部门的用户、Key、知识库和用量；不要放在浏览器或前端代码中。" }),
    options.editing ? el("p", { className: "muted", text: "收窄权限会同时收回该 Key 下发给其他 Key 的对应权限。读写权限已包含只读权限。" }) : el("p", { className: "muted", text: "读写权限已包含只读权限。" }),
    list
  ]);
  return {
    node,
    selected,
    changed() {
      return normalizeManagementScopes(selected()).join(",") !== baseline.join(",");
    },
    commit(scopes) {
      baseline = normalizeManagementScopes(apiKeyManagementScopeList(scopes));
    }
  };
}

// --- MCP OAuth sign-ins bound to an API key ---------------------------------

const MCP_GRANT_LOGIN_LABELS = { password: "账号密码", api_key: "粘贴 API Key" };
const MCP_GRANT_REVOKE_REASONS = {
  admin: "管理员撤销",
  password_changed: "用户改了密码",
  refresh_replay: "检测到令牌重放",
  code_replay: "检测到授权码重放",
  client_revoked: "客户端注销"
};

function mcpGrantClientLabel(grant) {
  if (grant.client_name) {
    return grant.client_name;
  }
  const clientId = String(grant.client_id || "");
  if (clientId.startsWith("https://")) {
    try {
      return new URL(clientId).host;
    } catch {
      return clientId;
    }
  }
  return clientId || "-";
}

export async function showApiKeyMcpGrants(row) {
  const body = el("div", { className: "mcp-grants" }, [statusMessage("正在加载 MCP 授权...")]);
  showModal(`MCP 授权 · ${row.name || row.id}`, body);
  const load = async (flash = "") => {
    let grants;
    try {
      const result = await api("/admin-api/api-keys/mcp-oauth-grants", { query: { api_key_id: row.id, status: "all", limit: 100 } });
      grants = Array.isArray(result.data) ? result.data : [];
    } catch (error) {
      body.replaceChildren(statusMessage(adminErrorMessage(error, "MCP 授权加载失败"), "error"));
      return;
    }
    const children = [];
    if (flash) {
      children.push(statusMessage(flash));
    }
    children.push(el("p", { className: "muted", text: "MCP 客户端（Claude、ChatGPT、Codex、Cursor 等）通过 OAuth 登录后，以这个 API 密钥的权限调用 TokenHub 的 MCP 工具。撤销后客户端需要重新登录。" }));
    if (grants.length === 0) {
      children.push(statusMessage("还没有 MCP 客户端用这个密钥登录"));
      body.replaceChildren(...children);
      return;
    }
    const table = el("table", { className: "resource-table mcp-grants-table" });
    table.append(el("thead", {}, [el("tr", {}, ["客户端", "登录方式", "登录用户", "授权时间", "最近使用", "状态", "操作"].map((label) => el("th", { text: label })))]));
    table.append(el("tbody", {}, grants.map((grant) => {
      const actions = el("td", {});
      if (grant.status === "active" && adminViewerCan("api_keys.manage")) {
        actions.append(button("撤销", async () => {
          if (!await confirmDialog({
            title: "撤销 MCP 授权",
            message: "撤销后该客户端的访问令牌和刷新令牌立即失效，需要重新登录。",
            details: [["客户端", mcpGrantClientLabel(grant)], ["API 密钥", row.name || `#${row.id}`]],
            confirmLabel: "撤销"
          })) {
            return;
          }
          try {
            await api(`/admin-api/api-keys/mcp-oauth-grants/${encodeURIComponent(grant.grant_id)}/revoke`, { method: "POST" });
            await load("已撤销授权");
          } catch (error) {
            await load();
            body.prepend(statusMessage(adminErrorMessage(error, "撤销失败"), "error"));
          }
        }, "danger mcp-grant-revoke"));
      }
      const statusText = grant.status === "active" ? "有效" : `已撤销${MCP_GRANT_REVOKE_REASONS[grant.revoked_reason] ? `（${MCP_GRANT_REVOKE_REASONS[grant.revoked_reason]}）` : ""}`;
      return el("tr", {}, [
        el("td", { text: mcpGrantClientLabel(grant), title: String(grant.client_id || "") }),
        el("td", { text: MCP_GRANT_LOGIN_LABELS[grant.login_method] || opsText(grant.login_method) }),
        el("td", { text: opsText(grant.username) }),
        el("td", { text: opsUtcTime(grant.created_at) }),
        el("td", { text: grant.last_used_at ? opsUtcTime(grant.last_used_at) : "未使用" }),
        el("td", { text: statusText }),
        actions
      ]);
    })));
    children.push(el("div", { className: "quota-status-table-wrap" }, [table]));
    body.replaceChildren(...children);
  };
  await load();
}
