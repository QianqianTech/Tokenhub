import { adminErrorMessage, api, streamApi } from "../api.js";
import { button, clear, el, field, setText, statusMessage } from "../dom.js";
import { lookupRows, normalizeIdList } from "../lib/references.js";
import { modelIsGatewaySupported, showRequestDiagnostics } from "../lib/ops-tools.js";

function apiKeyDisplay(row = {}) {
  if (row.display) {
    return String(row.display).replace("****", "...");
  }
  if (row.key_prefix && row.key_suffix) {
    return `${row.key_prefix}...${row.key_suffix}`;
  }
  return row.key_suffix ? `sk-...${row.key_suffix}` : "";
}

export async function renderAdminChatSessionPage(content) {
  clear(content);
  const state = { messages: [], stickToBottom: true };
  const [modelResult, chatApiKeys] = await Promise.all([
    api("/admin-api/chat-session/models").catch((error) => ({ error, data: [] })),
    lookupRows("api-keys")
  ]);
  const models = Array.isArray(modelResult.data) ? modelResult.data : [];
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
  const output = el("p", { className: "notice", hidden: true });
  const modelField = field(fields[0], "");
  modelField.classList.add("admin-chat-model-field");
  const promptField = field(fields[1], "");
  const trialMeta = el("div", { className: "admin-chat-trial-meta", hidden: true });
  const apiKeyField = field(fields[2], "");
  apiKeyField.classList.add("admin-chat-api-key-field");
  const apiKeySelect = apiKeyField.querySelector('select[name="api_key_id"]');
  const modelSelect = modelField.querySelector('select[name="model"]');
  const modeHint = el("p", { className: "admin-chat-mode-hint" });
  const setModelOptions = (options) => {
    const previous = modelSelect.value;
    modelSelect.replaceChildren(...options.map(([value, label]) => el("option", { value, text: label })));
    if (options.some(([value]) => value === previous)) {
      modelSelect.value = previous;
    }
  };
  const updateChatMode = async () => {
    const apiKeyId = apiKeySelect.value;
    trialMeta.hidden = true;
    if (!apiKeyId) {
      modeHint.className = "admin-chat-mode-hint notice";
      setText(modeHint, "直连诊断模式：直接调用上游，绕过额度、脱敏、RAG 与计费，不产生用量记录，仅用于连通性检查。");
      setModelOptions(modelOptions);
      send.disabled = modelOptions.length === 0;
      return;
    }
    modeHint.className = "admin-chat-mode-hint notice secret-output";
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
    send.disabled = modelSelect.options.length === 0;
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

  const transcript = el("section", { className: "admin-chat-transcript" });
  const scrollToBottom = () => {
    if (state.stickToBottom) {
      transcript.scrollTop = transcript.scrollHeight;
    }
  };
  const renderTranscript = () => {
    clear(transcript);
    if (state.messages.length === 0) {
      transcript.append(el("div", { className: "admin-chat-empty" }, [
        el("strong", { text: "还没有消息" }),
        el("span", { text: "选择模型并输入一句话，回复会经过网关计量，并记录在用量明细里。" })
      ]));
      return;
    }
    for (const message of state.messages) {
      transcript.append(el("article", { className: `admin-chat-message ${message.role}` }, [
        el("div", { className: "admin-chat-role", text: message.role === "user" ? "用户" : "助手" }),
        el("div", { className: "admin-chat-bubble", text: message.content })
      ]));
    }
    scrollToBottom();
  };
  transcript.addEventListener("scroll", () => {
    const distance = transcript.scrollHeight - transcript.scrollTop - transcript.clientHeight;
    state.stickToBottom = distance < 32;
  });

  const send = button("发送", async () => {
    const model = form.querySelector('[name="model"]')?.value || "";
    const prompt = form.querySelector('[name="prompt"]')?.value.trim() || "";
    const apiKeyId = Number(apiKeySelect.value) || 0;
    if (!model || !prompt) {
      output.hidden = false;
      output.className = "notice error";
      setText(output, "请选择模型并填写消息。");
      return;
    }

    const messages = [...state.messages, { role: "user", content: prompt }];

    send.disabled = true;
    state.stickToBottom = true;
    output.hidden = false;
    output.className = "notice";
    setText(output, "发送中...");
    state.messages.push({ role: "user", content: prompt });
    const assistant = { role: "assistant", content: "" };
    state.messages.push(assistant);
    form.querySelector('[name="prompt"]').value = "";
    renderTranscript();
    let reasoningSeen = false;
    trialMeta.hidden = true;
    try {
      const streamed = await streamApi("/admin-api/chat-session/stream", {
        method: "POST",
        body: JSON.stringify({
          model,
          messages,
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
        const choice = chunk.choices?.[0] || {};
        const messageDelta = choice.delta || choice.message || {};
        if (messageDelta.reasoning_content) {
          reasoningSeen = true;
        }
        const delta = messageDelta.content || "";
        if (delta) {
          assistant.content += delta;
          renderTranscript();
        }
      });
      if (!assistant.content) {
        assistant.content = reasoningSeen
          ? "（模型只返回了推理过程，未返回最终答案。请重试或切换模型。）"
          : "（模型未返回文本内容）";
      }
      state.messages = state.messages.slice(-20);
      output.className = "notice";
      setText(output, apiKeyId > 0 ? `完成：${model}（API Key 试用，已按所选密钥计费）` : `完成：${model}`);
      showTrialMeta(streamed?.requestId, apiKeyId);
      renderTranscript();
    } catch (error) {
      if (!assistant.content) {
        assistant.content = adminErrorMessage(error, "会话请求失败");
      }
      output.className = "notice error";
      setText(output, adminErrorMessage(error, "会话请求失败"));
      showTrialMeta(error.requestId, apiKeyId);
      renderTranscript();
    } finally {
      send.disabled = false;
    }
  });

  const reset = button("清空", () => {
    state.messages = [];
    output.hidden = true;
    renderTranscript();
  }, "secondary");
  form.append(
    el("section", { className: "admin-chat-input-shell" }, [
      promptField,
      el("div", { className: "admin-chat-input-footer" }, [
        modelField,
        apiKeyField,
        el("div", { className: "toolbar-actions" }, [send, reset])
      ])
    ]),
    modeHint,
    output,
    trialMeta
  );
  apiKeySelect.addEventListener("change", () => {
    void updateChatMode();
  });
  await updateChatMode();
  if (modelOptions.length === 0) {
    send.disabled = true;
    form.prepend(statusMessage(modelResult.error ? adminErrorMessage(modelResult.error, "模型加载失败") : "暂无可用文本模型", modelResult.error ? "error" : ""));
  }

  content.append(el("section", { className: "panel admin-chat-shell" }, [
    el("header", { className: "admin-chat-header" }, [el("h2", { text: "会话测试" })]),
    transcript,
    form
  ]));
  renderTranscript();
}
