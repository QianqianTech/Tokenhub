// Request diagnostics: the per-request explanation dialog and the request id
// lookup shown on usage and log toolbars.
import { adminErrorMessage, api } from "../api.js";
import { button, el, showModal, statusMessage } from "../dom.js";
import { valueLabel } from "../resources.js";
import { renderDataDetails } from "./output.js";
import { opsDetailGrid, opsSection, opsText } from "./ops-common.js";

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
