import { adminErrorMessage, api, isAbortError, streamApi } from "../api.js";
import { button, clear, el, field, setText, statusMessage } from "../dom.js";
import { copyText, toast } from "../components/feedback.js";
import { getCurrentUser } from "../core/session.js";
import { lookupRows, normalizeIdList } from "../lib/references.js";
import { renderMarkdown } from "../lib/markdown.js";
import { modelIsGatewaySupported } from "../lib/provider-catalog.js";
import { showRequestDiagnostics } from "../lib/diagnostics.js";

const HISTORY_LIMIT = 20;

function apiKeyDisplay(row = {}) {
  if (row.display) {
    return String(row.display).replace("****", "...");
  }
  if (row.key_prefix && row.key_suffix) {
    return `${row.key_prefix}...${row.key_suffix}`;
  }
  return row.key_suffix ? `sk-...${row.key_suffix}` : "";
}

// --- Session history ----------------------------------------------------------
// Kept in memory for the signed-in administrator: it survives switching pages
// inside the console but not a reload or sign-out. The admin frontend never
// writes to browser storage (AdminFrontendSecurityTest), because
// test prompts and replies can contain sensitive data.
const sessionHistory = new Map();

function historyKey() {
  const user = getCurrentUser();
  return String(user?.id ?? user?.username ?? "anonymous");
}

function readHistory() {
  try {
    const saved = sessionHistory.get(historyKey());
    if (!saved || !Array.isArray(saved.messages)) {
      return null;
    }
    return { ...saved, messages: saved.messages.map((message) => ({ ...message })) };
  } catch {
    return null;
  }
}

function writeHistory(state, model, apiKeyId) {
  try {
    const kept = state.messages.filter((message) => !message.pending).slice(-HISTORY_LIMIT);
    const messages = kept.map(({ role, content, model: name, metrics, error, stopped }) => ({ role, content, model: name, metrics, error, stopped }));
    // Signing in as someone else drops the previous administrator's history.
    for (const key of sessionHistory.keys()) {
      if (key !== historyKey()) sessionHistory.delete(key);
    }
    if (messages.length === 0) {
      sessionHistory.delete(historyKey());
      return;
    }
    sessionHistory.set(historyKey(), { model, apiKeyId, messages });
  } catch {
    // History is a convenience only.
  }
}

// --- Metrics -----------------------------------------------------------------

function durationText(ms) {
  if (!Number.isFinite(ms) || ms < 0) return "–";
  return ms >= 1000 ? `${(ms / 1000).toFixed(ms >= 10000 ? 0 : 1)} 秒` : `${Math.round(ms)} 毫秒`;
}

function shellQuote(text) {
  return `'${String(text).replace(/'/g, "'\\''")}'`;
}

/** The same request as a public API call (the key itself is never embedded). */
export function chatCurl(body) {
  const origin = typeof window !== "undefined" ? window.location.origin : "";
  return [
    `curl -N ${shellQuote(`${origin}/v1/chat/completions`)}`,
    "  -H \"Authorization: Bearer $TOKENHUB_API_KEY\"",
    "  -H \"Content-Type: application/json\"",
    `  -d ${shellQuote(JSON.stringify(body))}`
  ].join(" \\\n");
}

function metricItem(label, value, title = "") {
  return el("span", { className: "admin-chat-metric", title: title || null }, [
    el("span", { className: "admin-chat-metric-label", text: label }),
    el("span", { className: "admin-chat-metric-value", text: value })
  ]);
}

function usageTokens(usage = {}) {
  const input = Number(usage.prompt_tokens ?? usage.input_tokens);
  const output = Number(usage.completion_tokens ?? usage.output_tokens);
  const total = Number(usage.total_tokens ?? (Number.isFinite(input) && Number.isFinite(output) ? input + output : NaN));
  return { input, output, total };
}

function metricsRow(message, { onRetry, isLast }) {
  const metrics = message.metrics || {};
  const items = [];
  if (Number.isFinite(metrics.elapsedMs)) items.push(metricItem("耗时", durationText(metrics.elapsedMs)));
  if (Number.isFinite(metrics.ttftMs)) items.push(metricItem("首字", durationText(metrics.ttftMs), "从发送到收到第一个字的时间"));
  const tokens = usageTokens(metrics.usage || {});
  if (Number.isFinite(tokens.total)) {
    items.push(metricItem("Token", `${Number.isFinite(tokens.input) ? tokens.input : "–"} / ${Number.isFinite(tokens.output) ? tokens.output : "–"}`, `输入 / 输出，合计 ${tokens.total}`));
  }
  if (metrics.mode === "direct") {
    items.push(metricItem("费用", "直连不计费"));
  } else if (metrics.cost !== undefined && metrics.cost !== null && metrics.cost !== "") {
    items.push(metricItem("费用", `${metrics.cost}${metrics.currency ? ` ${metrics.currency}` : ""}`));
  } else if (metrics.mode === "gateway") {
    items.push(metricItem("费用", "待计费", "用量记录写入后可在请求诊断中查看"));
  }
  if (metrics.channel) items.push(metricItem("渠道", metrics.channel));
  if (metrics.requestId) items.push(metricItem("请求 ID", metrics.requestId));
  const actions = [];
  if (metrics.requestBody) {
    actions.push(button("复制 curl", async () => {
      const ok = await copyText(chatCurl(metrics.requestBody));
      toast(ok ? "已复制 curl 命令（请把 $TOKENHUB_API_KEY 换成你的密钥）" : "复制失败，请手动选择文本", { tone: ok ? "success" : "error", timeout: 3000 });
    }, "link-button admin-chat-copy-curl"));
  }
  if (metrics.requestId && metrics.mode === "gateway") {
    actions.push(button("诊断", () => showRequestDiagnostics(metrics.requestId), "link-button"));
  }
  if (isLast && onRetry) {
    actions.push(button("重试", onRetry, "link-button admin-chat-retry"));
  }
  if (items.length === 0 && actions.length === 0) {
    return null;
  }
  return el("div", { className: "admin-chat-metrics", "aria-label": "本条回复的指标" }, [...items, ...actions]);
}

// --- Page --------------------------------------------------------------------

export async function renderAdminChatSessionPage(content, initialFilters = {}) {
  clear(content);
  const saved = readHistory();
  const state = { messages: saved?.messages || [], stickToBottom: true, controller: null, sending: false };
  const [modelResult, chatApiKeys] = await Promise.all([
    api("/admin-api/chat-session/models").catch((error) => ({ error, data: [] })),
    lookupRows("api-keys")
  ]);
  const models = Array.isArray(modelResult.data) ? modelResult.data : [];
  const providerByModel = new Map(models.map((row) => [row.name, row.provider_name || ""]));
  const modelOptions = models.map((row) => {
    const suffix = row.provider_name ? ` / ${row.provider_name}` : "";
    return [row.name, `${row.name}${suffix}`];
  });
  const activeApiKeys = chatApiKeys.filter((key) => key.id && key.status === "active");
  const apiKeyOptions = [
    ["", "不使用（直连诊断）"],
    ...activeApiKeys.map((key) => [String(key.id), `${key.name || `API 密钥 #${key.id}`} / ${apiKeyDisplay(key) || `#${key.id}`}`])
  ];
  let gatewayModels = null;

  const fields = [
    { name: "model", label: "模型", type: "select", options: modelOptions },
    { name: "prompt", label: "消息", type: "textarea", rows: 3 },
    { name: "api_key_id", label: "使用 API Key", type: "select", options: apiKeyOptions }
  ];
  const form = el("form", { className: "admin-chat-composer", onSubmit: (event) => event.preventDefault() });
  const output = el("p", { className: "admin-chat-status", role: "status", hidden: true });
  const modelField = field(fields[0], "");
  modelField.classList.add("admin-chat-model-field");
  const promptField = field(fields[1], "");
  const trialMeta = el("div", { className: "admin-chat-trial-meta", hidden: true });
  const apiKeyField = field(fields[2], "");
  apiKeyField.classList.add("admin-chat-api-key-field");
  const apiKeySelect = apiKeyField.querySelector('select[name="api_key_id"]');
  const modelSelect = modelField.querySelector('select[name="model"]');
  const modeHint = el("p", { className: "admin-chat-mode-hint" });
  // A link from an API key row (#…/admin-chat-sessions?api_key_id=7) preselects
  // that key; otherwise the last used key and model are restored.
  const requestedKey = initialFilters.api_key_id !== undefined ? String(initialFilters.api_key_id) : (saved?.apiKeyId || "");
  if (requestedKey && apiKeyOptions.some(([value]) => value === requestedKey)) {
    apiKeySelect.value = requestedKey;
  } else if (initialFilters.api_key_id !== undefined) {
    output.hidden = false;
    output.className = "admin-chat-status error";
    setText(output, "链接中的 API 密钥不存在、已停用或不在你的管理范围内，已切换为直连诊断。");
  }
  const preferredModel = typeof initialFilters.model === "string" ? initialFilters.model : (saved?.model || "");
  const setModelOptions = (options) => {
    const previous = modelSelect.value || preferredModel;
    modelSelect.replaceChildren(...(options.length
      ? options.map(([value, label]) => el("option", { value, text: label }))
      : [el("option", { value: "", text: "暂无可用模型" })]));
    modelSelect.disabled = options.length === 0;
    if (options.some(([value]) => value === previous)) {
      modelSelect.value = previous;
    }
  };
  const updateChatMode = async () => {
    const apiKeyId = apiKeySelect.value;
    trialMeta.hidden = true;
    if (!apiKeyId) {
      modeHint.className = "admin-chat-mode-hint";
      setText(modeHint, "直连诊断模式：直接调用上游，绕过额度、脱敏、RAG 与计费，不产生用量记录，仅用于连通性检查。");
      setModelOptions(modelOptions);
      send.disabled = modelOptions.length === 0;
      return;
    }
    modeHint.className = "admin-chat-mode-hint is-gateway";
    setText(modeHint, "API Key 试用模式：请求经过真实网关（鉴权、额度、脱敏、RAG、路由与故障转移），按所选密钥计费并产生用量记录。模型使用该密钥已授权的逻辑模型名。");
    gatewayModels ??= await lookupRows("models", { query: { support: "all" } });
    const apiKey = activeApiKeys.find((key) => String(key.id) === apiKeyId) || {};
    const grantedIds = new Set(normalizeIdList(apiKey.model_ids));
    const grantedNames = [...new Set(gatewayModels
      .filter((model) => grantedIds.has(Number(model.id)) && modelIsGatewaySupported(model) && (!model.status || model.status === "active") && ["text_generation", "vision_understanding"].includes(model.capability))
      .map((model) => model.name)
      .filter(Boolean))];
    if (gatewayModels.length === 0) {
      setModelOptions(modelOptions);
      setText(modeHint, `${modeHint.textContent}（无法读取模型目录，下列模型可能未授权给该密钥。）`);
    } else {
      setModelOptions(grantedNames.map((name) => [name, name]));
      if (grantedNames.length === 0) {
        setText(modeHint, "所选 API 密钥没有已授权的文本模型，请先在 API 密钥中授权模型。");
      }
    }
    send.disabled = modelSelect.disabled;
  };
  const showTrialMeta = (requestId, apiKeyId) => {
    if (!apiKeyId || !requestId) {
      trialMeta.hidden = true;
      return;
    }
    trialMeta.replaceChildren(
      el("span", { text: `请求 ID：${requestId}` }),
      button("诊断此请求", () => showRequestDiagnostics(requestId), "secondary")
    );
    trialMeta.hidden = false;
  };

  const transcript = el("section", { className: "admin-chat-transcript", "aria-live": "polite" });
  const scrollToBottom = () => {
    if (state.stickToBottom) {
      transcript.scrollTop = transcript.scrollHeight;
    }
  };
  const suggestions = ["你好，请用一句话介绍你自己。", "用三点说明什么是 RAG。", "把“今天天气很好”翻译成英文。"];
  const bubbleFor = (message) => {
    const isUser = message.role === "user";
    const bubble = el("div", { className: `admin-chat-bubble${!isUser && !message.content ? " is-pending" : ""}${message.error ? " is-error" : ""}` });
    if (!message.content) {
      bubble.textContent = isUser ? "" : "正在生成…";
    } else if (isUser || message.error) {
      bubble.textContent = message.content;
    } else {
      bubble.classList.add("is-markdown");
      bubble.append(renderMarkdown(message.content));
    }
    if (message.stopped) {
      bubble.append(el("span", { className: "admin-chat-stopped", text: "（已停止）" }));
    }
    return bubble;
  };
  const lastAssistantIndex = () => {
    for (let index = state.messages.length - 1; index >= 0; index -= 1) {
      if (state.messages[index].role === "assistant") return index;
    }
    return -1;
  };
  const renderTranscript = () => {
    clear(transcript);
    if (state.messages.length === 0) {
      transcript.append(el("div", { className: "admin-chat-empty" }, [
        el("span", { className: "admin-chat-empty-icon", "aria-hidden": "true", text: "AI" }),
        el("strong", { text: "还没有消息" }),
        el("span", { text: modelOptions.length === 0
          ? "当前没有可用的文本模型。请先在「模型服务 › 模型管理」中启用一个文本生成模型。"
          : "选择模型并输入一句话，测试上游连通性或按密钥走完整网关链路。" }),
        modelOptions.length === 0
          ? el("a", { className: "button-link secondary", href: "#/models/models", text: "前往模型管理" })
          : el("div", { className: "admin-chat-suggestions" }, suggestions.map((text) => el("button", {
            type: "button",
            className: "admin-chat-suggestion",
            text,
            onClick: () => {
              const prompt = form.querySelector('[name="prompt"]');
              prompt.value = text;
              prompt.focus();
            }
          })))
      ]));
      return;
    }
    const lastIndex = lastAssistantIndex();
    state.messages.forEach((message, index) => {
      const isUser = message.role === "user";
      const node = el("article", { className: `admin-chat-message ${message.role}` }, [
        el("div", { className: "admin-chat-avatar", "aria-hidden": "true", text: isUser ? "我" : "AI" }),
        el("div", { className: "admin-chat-message-body" }, [
          el("div", { className: "admin-chat-role", text: isUser ? "我" : (message.model || "助手") }),
          bubbleFor(message),
          !isUser && !message.pending ? metricsRow(message, { onRetry: state.sending ? null : retry, isLast: index === lastIndex }) : null
        ])
      ]);
      message.node = node;
      transcript.append(node);
    });
    scrollToBottom();
  };
  // Streaming updates only replace the growing bubble, not the transcript.
  const updateBubble = (message) => {
    const bubble = message.node?.querySelector(".admin-chat-bubble");
    if (!bubble) {
      renderTranscript();
      return;
    }
    bubble.replaceWith(bubbleFor(message));
    scrollToBottom();
  };
  transcript.addEventListener("scroll", () => {
    const distance = transcript.scrollHeight - transcript.scrollTop - transcript.clientHeight;
    state.stickToBottom = distance < 32;
  });

  const persist = () => writeHistory(state, modelSelect.value, apiKeySelect.value);

  // Cost and channel come from the usage record the gateway writes after the
  // reply; it can lag a moment behind the stream, so it is read twice at most.
  const loadGatewayMetrics = async (assistant) => {
    const requestId = assistant.metrics?.requestId;
    if (!requestId) return;
    for (const delay of [400, 1500]) {
      await new Promise((resolve) => window.setTimeout(resolve, delay));
      try {
        const result = await api(`/admin-api/diagnostics/requests/${encodeURIComponent(requestId)}`, { silent: true });
        const data = result.data || {};
        if (!data.found) continue;
        const usage = data.usage || {};
        const channel = data.channel || {};
        assistant.metrics = {
          ...assistant.metrics,
          cost: usage.cost_amount ?? assistant.metrics.cost,
          currency: usage.cost_currency || "",
          channel: [channel.provider_name || data.upstream?.provider, channel.external_name || data.upstream?.model].filter(Boolean).join(" · ") || assistant.metrics.channel,
          usage: Object.keys(assistant.metrics.usage || {}).length ? assistant.metrics.usage : { input_tokens: usage.input_tokens, output_tokens: usage.output_tokens }
        };
        persist();
        renderTranscript();
        return;
      } catch {
        return;
      }
    }
  };

  const runTurn = async (model, apiKeyId) => {
    const history = state.messages
      .filter((message) => !message.error && !message.pending && message.content)
      .map(({ role, content }) => ({ role, content }));
    const requestBody = { model, messages: history, stream: true };
    const assistant = { role: "assistant", content: "", model, pending: true };
    state.messages.push(assistant);
    state.sending = true;
    state.stickToBottom = true;
    state.controller = new AbortController();
    send.hidden = true;
    stop.hidden = false;
    output.hidden = false;
    output.className = "admin-chat-status";
    setText(output, "发送中...");
    trialMeta.hidden = true;
    renderTranscript();
    let reasoningSeen = false;
    let usage = {};
    const started = performance.now();
    let firstTokenAt = 0;
    const mode = apiKeyId > 0 ? "gateway" : "direct";
    assistant.metrics = { mode, requestBody, channel: mode === "direct" ? providerByModel.get(model) || "" : "" };
    try {
      const streamed = await streamApi("/admin-api/chat-session/stream", {
        method: "POST",
        signal: state.controller.signal,
        body: JSON.stringify({
          model,
          messages: history,
          ...(apiKeyId > 0 ? { api_key_id: apiKeyId } : {})
        })
      }, (data) => {
        if (data === "[DONE]") {
          return;
        }
        const chunk = JSON.parse(data);
        if (chunk.error) {
          const error = new Error(chunk.error.message || "stream error");
          error.code = chunk.error.code || "REQUEST_FAILED";
          throw error;
        }
        if (chunk.usage && typeof chunk.usage === "object") {
          usage = chunk.usage;
        }
        const choice = chunk.choices?.[0] || {};
        const messageDelta = choice.delta || choice.message || {};
        if (messageDelta.reasoning_content) {
          reasoningSeen = true;
          firstTokenAt ||= performance.now();
        }
        const delta = messageDelta.content || "";
        if (delta) {
          firstTokenAt ||= performance.now();
          assistant.content += delta;
          updateBubble(assistant);
        }
      });
      if (!assistant.content) {
        assistant.content = reasoningSeen
          ? "（模型只返回了推理过程，未返回最终答案。请重试或切换模型。）"
          : "（模型未返回文本内容）";
      }
      assistant.metrics = {
        ...assistant.metrics,
        elapsedMs: performance.now() - started,
        ttftMs: firstTokenAt ? firstTokenAt - started : undefined,
        usage,
        requestId: streamed?.requestId || ""
      };
      output.className = "admin-chat-status";
      setText(output, apiKeyId > 0 ? `完成：${model}（API Key 试用，已按所选密钥计费）` : `完成：${model}`);
      showTrialMeta(streamed?.requestId, apiKeyId);
      if (mode === "gateway") {
        void loadGatewayMetrics(assistant);
      }
    } catch (error) {
      assistant.metrics = {
        ...assistant.metrics,
        elapsedMs: performance.now() - started,
        ttftMs: firstTokenAt ? firstTokenAt - started : undefined,
        usage,
        requestId: error.requestId || ""
      };
      if (isAbortError(error)) {
        assistant.stopped = true;
        output.className = "admin-chat-status";
        setText(output, "已停止生成");
      } else {
        if (!assistant.content) {
          assistant.content = adminErrorMessage(error, "会话请求失败");
          assistant.error = true;
        }
        output.className = "admin-chat-status error";
        setText(output, adminErrorMessage(error, "会话请求失败"));
        showTrialMeta(error.requestId, apiKeyId);
      }
    } finally {
      assistant.pending = false;
      state.sending = false;
      state.controller = null;
      state.messages = state.messages.slice(-HISTORY_LIMIT);
      send.hidden = false;
      stop.hidden = true;
      send.disabled = modelSelect.disabled;
      persist();
      renderTranscript();
    }
  };

  const send = button("发送", async () => {
    if (state.sending) return;
    const model = form.querySelector('[name="model"]')?.value || "";
    const prompt = form.querySelector('[name="prompt"]')?.value.trim() || "";
    const apiKeyId = Number(apiKeySelect.value) || 0;
    if (!model || !prompt) {
      output.hidden = false;
      output.className = "admin-chat-status error";
      setText(output, "请选择模型并填写消息。");
      return;
    }
    send.disabled = true;
    state.messages.push({ role: "user", content: prompt });
    form.querySelector('[name="prompt"]').value = "";
    await runTurn(model, apiKeyId);
  });

  // 重试: drop the last reply and ask again with the same conversation.
  async function retry() {
    if (state.sending) return;
    const index = lastAssistantIndex();
    if (index < 0) return;
    const model = state.messages[index].model || modelSelect.value;
    state.messages = state.messages.slice(0, index);
    if (!state.messages.some((message) => message.role === "user")) {
      renderTranscript();
      return;
    }
    await runTurn(modelSelect.value || model, Number(apiKeySelect.value) || 0);
  }

  const stop = button("停止", () => state.controller?.abort(), "secondary admin-chat-stop", { hidden: true });

  const reset = button("清空对话", () => {
    state.controller?.abort();
    state.messages = [];
    output.hidden = true;
    trialMeta.hidden = true;
    persist();
    renderTranscript();
  }, "secondary");
  send.classList.add("admin-chat-send");
  const promptInput = promptField.querySelector('textarea[name="prompt"]');
  promptInput.rows = 2;
  promptInput.placeholder = "输入消息，Enter 或 Ctrl/⌘ + Enter 发送，Shift + Enter 换行";
  promptInput.setAttribute("aria-keyshortcuts", "Enter Control+Enter Meta+Enter");
  promptField.querySelector(".field-label")?.classList.add("visually-hidden");
  promptInput.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" || event.isComposing || event.keyCode === 229) {
      return;
    }
    if (event.metaKey || event.ctrlKey || !event.shiftKey) {
      event.preventDefault();
      if (!send.disabled && !state.sending) {
        send.click();
      }
    }
  });
  form.append(
    el("section", { className: "admin-chat-input-shell" }, [
      promptField,
      el("div", { className: "admin-chat-input-footer" }, [
        modelField,
        apiKeyField,
        el("div", { className: "admin-chat-actions" }, [stop, send])
      ])
    ]),
    el("div", { className: "admin-chat-meta-row" }, [modeHint]),
    output,
    trialMeta
  );
  apiKeySelect.addEventListener("change", () => {
    void updateChatMode().then(persist);
  });
  modelSelect.addEventListener("change", persist);
  await updateChatMode();
  if (modelResult.error) {
    send.disabled = true;
    form.prepend(statusMessage(adminErrorMessage(modelResult.error, "模型加载失败"), "error"));
  }

  content.append(
    el("header", { className: "page-head" }, [
      el("div", { className: "page-head-text" }, [
        el("h2", { text: "会话测试" }),
        el("p", { text: "向模型发送测试消息：直连诊断只检查上游连通性；选择 API Key 则走完整网关链路并计费。对话记录只保留在当前登录会话中，刷新或退出后清空。" })
      ]),
      el("div", { className: "page-head-actions" }, [reset])
    ]),
    el("section", { className: "panel admin-chat-shell" }, [transcript, form])
  );
  renderTranscript();
}
