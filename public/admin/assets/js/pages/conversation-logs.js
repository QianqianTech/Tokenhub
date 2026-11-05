// 会话记录 (conversation logs): what each API key (Token) asked the AI, what it
// answered and how many tokens every call consumed. Super administrators only.
// API keys -> 逐条问答 (one card per call) or 按会话 -> transcript; the selection
// is kept in the URL.
import { pageHead } from "../lib/resource-table.js";
import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, field } from "../dom.js";
import { emptyState, errorState, loadingState, toast } from "../components/feedback.js";
import { confirmDialog } from "../components/modal.js";
import { pager } from "../components/pager.js";
import { renderMarkdown } from "../lib/markdown.js";
import { formatDateTime, formatNumber } from "../lib/format.js";
import { guardForm, markClean } from "../lib/dirty-guard.js";
import { navigateTo } from "../lib/navigation.js";

const KEY_LIMIT = 20;
const CALL_LIMIT = 20;
const SESSION_LIMIT = 20;
const TURN_LIMIT = 50;
const PREVIEW_CHARS = 160;

const FORMAT_LABELS = {
  openai_chat: "Chat Completions",
  openai_responses: "Responses",
  openai_completions: "Completions",
  anthropic: "Anthropic Messages",
  gemini: "Gemini",
  image_generation: "图片生成",
  video_generation: "视频生成",
  tts: "语音合成",
  music_generation: "音乐生成",
  asr: "语音识别"
};

const PART_LABELS = { image: "图片", audio: "音频", video: "视频", file: "文件" };
const CURRENCY_SYMBOLS = { USD: "$", CNY: "¥", RMB: "¥", EUR: "€", GBP: "£", JPY: "¥" };

function formatCost(amount, currency) {
  if (amount === null || amount === undefined || amount === "") return "—";
  const value = Number(amount);
  if (!Number.isFinite(value)) return "—";
  const digits = value !== 0 && Math.abs(value) < 0.01 ? 6 : 4;
  const symbol = CURRENCY_SYMBOLS[String(currency || "").toUpperCase()];
  return symbol ? `${symbol}${value.toFixed(digits)}` : `${value.toFixed(digits)} ${currency || ""}`.trim();
}

function tokenChips(row, currency) {
  return el("div", { className: "conversation-usage-chips" }, [
    el("span", { className: "conversation-chip", title: "输入 Token（包含客户端重新发送的历史消息）" }, [el("small", { text: "输入" }), el("strong", { text: formatNumber(row.input_tokens) })]),
    el("span", { className: "conversation-chip", title: "输出 Token" }, [el("small", { text: "输出" }), el("strong", { text: formatNumber(row.output_tokens) })]),
    el("span", { className: "conversation-chip is-total", title: "本次调用合计 Token" }, [el("small", { text: "合计" }), el("strong", { text: formatNumber(row.total_tokens) })]),
    row.cache_read_tokens ? el("span", { className: "conversation-chip", title: "命中缓存的输入 Token" }, [el("small", { text: "缓存" }), el("strong", { text: formatNumber(row.cache_read_tokens) })]) : null,
    el("span", { className: "conversation-chip", title: "按价格配置计算的费用" }, [el("small", { text: "费用" }), el("strong", { text: formatCost(row.cost_amount, row.cost_currency || currency) })])
  ]);
}

function plainText(parts = []) {
  return parts.map((part) => {
    if (part.type === "text") return part.text;
    if (part.type === "tool_result") return `[工具结果] ${part.text || ""}`;
    if (part.type === "tool_call") return `[调用工具 ${part.name || ""}]`;
    return `[${PART_LABELS[part.type] || part.type}]`;
  }).join(" ").replace(/\s+/g, " ").trim();
}

function preview(text) {
  const value = String(text || "").trim();
  return value.length > PREVIEW_CHARS ? `${value.slice(0, PREVIEW_CHARS)}…` : value;
}

function questionText(messages = []) {
  return preview(messages.map((message) => plainText(message.parts)).filter(Boolean).join(" ")) || "（无文本内容）";
}

function answerText(reply = {}, media = []) {
  if (reply.text) return preview(reply.text);
  if ((reply.tool_calls || []).length) return `调用工具：${reply.tool_calls.map((call) => call.name).join("、")}`;
  if (reply.error) return `错误：${preview(reply.error)}`;
  const outputs = media.filter((item) => item.direction === "output" && item.source !== "task");
  if (outputs.length) {
    const counts = {};
    for (const item of outputs) counts[item.kind] = (counts[item.kind] || 0) + 1;
    return `生成了 ${Object.entries(counts).map(([kind, count]) => `${count} 个${PART_LABELS[kind] || kind}`).join("、")}`;
  }
  const task = media.find((item) => item.source === "task");
  if (task?.status === "waiting") return "生成中，完成后自动保存结果";
  if (task?.status === "failed") return task.error || "生成失败";
  return "（无文本回复）";
}

const MEDIA_ERRORS = {
  too_large: "超过单个文件上限，未保存",
  asset_missing: "素材已被删除，无法保存",
  asset_unreadable: "素材文件无法读取",
  unsupported_source: "来源不支持保存",
  task_missing: "找不到生成任务",
  no_assets: "任务完成，但没有返回文件",
  empty_download: "下载到的文件为空"
};

function mediaError(item) {
  return MEDIA_ERRORS[item.error] || item.error || "";
}

function mediaMap(turn) {
  return new Map((turn.media || []).map((item) => [item.key, item]));
}

// A stored image / video / audio, or its status while it is being saved.
function mediaItem(item, part = null) {
  const label = PART_LABELS[item.kind] || item.kind || "文件";
  const caption = [label, item.name || null, item.size_bytes ? formatBytes(item.size_bytes) : null].filter(Boolean).join(" · ");
  if (item.status === "stored" && item.url) {
    let media;
    if (item.kind === "image") {
      media = el("a", { href: item.url, target: "_blank", rel: "noopener", className: "conversation-media-link", title: "在新标签页打开原图" }, [
        el("img", { src: item.url, alt: part?.ref ? `${label}：${part.ref}` : label, loading: "lazy", className: "conversation-media-image" })
      ]);
    } else if (item.kind === "video") {
      media = el("video", { src: item.url, controls: true, preload: "metadata", className: "conversation-media-video" });
    } else if (item.kind === "audio") {
      media = el("audio", { src: item.url, controls: true, preload: "none", className: "conversation-media-audio" });
    }
    return el("figure", { className: `conversation-media-item is-${item.kind}` }, [
      media,
      el("figcaption", {}, [
        el("span", { text: caption }),
        el("a", { href: item.url, download: "", className: "conversation-media-download", text: "下载" })
      ])
    ].filter(Boolean));
  }
  const status = item.status === "skipped"
    ? mediaError(item) || "未保存"
    : item.status === "failed"
      ? `保存失败${mediaError(item) ? `：${mediaError(item)}` : ""}`
      : "正在保存…";
  return el("div", { className: `conversation-media-item is-pending is-${item.status}` }, [
    el("span", { className: "conversation-media", text: `[${label}] ${part?.ref || ""}`.trim() }),
    el("small", { className: item.status === "failed" ? "conversation-error" : "conversation-muted", text: status })
  ]);
}

// Result of a generation task: waiting / failed / nothing returned.
function taskStatusNode(item) {
  if (item.status === "waiting") {
    return el("p", { className: "conversation-muted conversation-task-status", text: `${PART_LABELS[item.kind] || ""}生成中，完成后自动保存到会话记录。` });
  }
  if (item.status === "failed") {
    return el("p", { className: "conversation-error conversation-task-status", text: item.error || "生成失败" });
  }
  if (item.error === "no_assets") {
    return el("p", { className: "conversation-muted conversation-task-status", text: MEDIA_ERRORS.no_assets });
  }
  return null;
}

function paramsNode(params) {
  const entries = Object.entries(params || {});
  if (!entries.length) return null;
  return el("div", { className: "conversation-params", "aria-label": "生成参数" }, entries.map(([name, value]) =>
    el("span", { className: "conversation-chip" }, [el("small", { text: name }), el("strong", { text: String(value) })])));
}

function mediaThumbs(row) {
  const items = (row.media || []).filter((item) => item.direction === "output" && item.source !== "task" && item.status === "stored" && item.url);
  if (!items.length) return null;
  return el("div", { className: "conversation-thumbs" }, items.slice(0, 6).map((item) => item.kind === "image"
    ? el("a", { href: item.url, target: "_blank", rel: "noopener", className: "conversation-thumb", title: "打开原图" }, [el("img", { src: item.url, alt: "生成的图片", loading: "lazy" })])
    : el("span", { className: "conversation-chip conversation-thumb-chip" }, [el("small", { text: PART_LABELS[item.kind] || item.kind }), el("strong", { text: item.size_bytes ? formatBytes(item.size_bytes) : "已保存" })])));
}

export async function renderConversationLogsPage(content, initialFilters = {}, context = {}) {
  clear(content);
  const state = {
    keyword: initialFilters.keyword || "",
    keyOffset: Math.max(0, Number(initialFilters.key_offset) || 0),
    apiKeyId: Number(initialFilters.api_key_id) || null,
    view: initialFilters.view === "sessions" ? "sessions" : "calls",
    offset: Math.max(0, Number(initialFilters.offset) || 0),
    sessionKey: /^[0-9a-f]{64}$/.test(initialFilters.session || "") ? initialFilters.session : null,
    turnOffset: Math.max(0, Number(initialFilters.turn_offset) || 0)
  };
  const syncUrl = () => {
    const filters = {};
    if (state.keyword) filters.keyword = state.keyword;
    if (state.keyOffset) filters.key_offset = String(state.keyOffset);
    if (state.apiKeyId) filters.api_key_id = String(state.apiKeyId);
    if (state.apiKeyId && state.view !== "calls") filters.view = state.view;
    if (state.offset) filters.offset = String(state.offset);
    if (state.sessionKey) filters.session = state.sessionKey;
    if (state.turnOffset) filters.turn_offset = String(state.turnOffset);
    context.setFilters?.(filters);
  };

  const keysPane = el("section", { className: "panel conversation-pane conversation-keys", "aria-label": "API 密钥" });
  const mainPane = el("section", { className: "panel conversation-pane conversation-main", "aria-label": "问答记录" });
  const layout = el("div", { className: "conversation-log-layout is-two-pane" }, [keysPane, mainPane]);
  const banner = el("div", { className: "notice warning conversation-feature-gate conversation-log-banner", hidden: true }, [
    el("span", { text: "会话记录功能未启用：不会记录任何新的问答。启用后再为需要的 API 密钥开启。已有记录仍可查看和清理。" }),
    button("去启用", () => context.navigate?.("conversation-log-settings"), "secondary")
  ]);
  api("/admin-api/conversation-logs/settings", { silent: true }).then((result) => {
    banner.hidden = Boolean(result.data?.enabled);
  }).catch(() => {});
  content.append(
    pageHead("会话记录", "按 API 密钥（Token）查看向 AI 的每次提问、回答，以及每次调用消耗的 Token 和费用。在密钥上单独开启记录；内容加密保存，仅超级管理员可查看，查看会写入审计日志。", el("div", {}, [
      button("API 密钥", () => context.navigate?.("api-keys"), "secondary"),
      button("清理设置", () => context.navigate?.("conversation-log-settings"), "secondary")
    ])),
    banner,
    layout
  );

  // ---------------------------------------------------------------- API keys
  const searchInput = el("input", { type: "search", name: "keyword", placeholder: "密钥名称、前缀或负责人", value: state.keyword, "aria-label": "搜索 API 密钥" });
  const searchForm = el("form", { className: "conversation-search", onSubmit: (event) => {
    event.preventDefault();
    state.keyword = searchInput.value.trim();
    state.keyOffset = 0;
    loadKeys();
  } }, [searchInput, el("button", { type: "submit", className: "secondary", text: "搜索" })]);
  const keyList = el("div", { className: "conversation-list" });
  const keyPager = el("div", { className: "conversation-pager" });
  keysPane.append(el("h3", { className: "conversation-pane-title", text: "API 密钥" }), searchForm, keyList, keyPager);

  let keySeq = 0;
  async function loadKeys() {
    syncUrl();
    const seq = ++keySeq;
    keyList.replaceChildren(loadingState("正在加载密钥…", { rows: 4 }));
    clear(keyPager);
    try {
      const result = await api(`/admin-api/conversation-logs/api-keys?${new URLSearchParams({ keyword: state.keyword, limit: KEY_LIMIT, offset: state.keyOffset })}`, { silent: true });
      if (seq !== keySeq) return;
      const rows = result.data || [];
      clear(keyList);
      if (rows.length === 0) {
        keyList.append(emptyState({
          title: state.keyword ? "没有匹配的密钥" : "还没有密钥开启会话记录",
          description: state.keyword ? "" : "在「API 接入 › API 密钥」中点开密钥的「会话记录」开启后，用这个密钥向 AI 的问答会出现在这里。",
          action: state.keyword ? null : button("去 API 密钥开启", () => context.navigate?.("api-keys"), "secondary")
        }));
      }
      for (const row of rows) {
        const active = Number(row.api_key_id) === state.apiKeyId;
        const item = el("button", {
          type: "button",
          className: `conversation-item${active ? " is-active" : ""}`,
          "aria-pressed": active ? "true" : "false",
          onClick: () => {
            state.apiKeyId = Number(row.api_key_id);
            state.view = "calls";
            state.offset = 0;
            state.sessionKey = null;
            state.turnOffset = 0;
            keyList.querySelectorAll(".conversation-item").forEach((node) => {
              node.classList.toggle("is-active", node === item);
              node.setAttribute("aria-pressed", node === item ? "true" : "false");
            });
            loadMain();
          }
        }, [
          el("span", { className: "conversation-item-head" }, [
            el("span", { className: "conversation-item-title", text: row.name || `#${row.api_key_id}` }),
            el("span", { className: `status-pill ${row.enabled ? "is-positive" : "is-muted"}`, text: row.enabled ? "记录中" : "已关闭" })
          ]),
          el("span", { className: "conversation-item-meta cell-mono", text: row.display }),
          el("span", { className: "conversation-item-meta", text: [row.username, row.department_name].filter(Boolean).join(" · ") || "—" }),
          el("span", { className: "conversation-item-meta", text: row.turns ? `${formatNumber(row.turns)} 次问答 · ${formatNumber(row.total_tokens)} Token` : "暂无问答" })
        ]);
        keyList.append(item);
      }
      keyPager.append(pager({
        total: result.total,
        limit: KEY_LIMIT,
        offset: state.keyOffset,
        count: rows.length,
        pageSizes: [KEY_LIMIT],
        onChange: ({ offset }) => {
          state.keyOffset = offset;
          loadKeys();
        }
      }));
    } catch (error) {
      if (seq === keySeq) keyList.replaceChildren(errorState(error, { fallback: "密钥列表加载失败", onRetry: loadKeys }));
    }
  }

  // ---------------------------------------------------------------- main pane
  let mainSeq = 0;
  async function loadMain() {
    syncUrl();
    const seq = ++mainSeq;
    if (!state.apiKeyId) {
      mainPane.replaceChildren(emptyState({ title: "选择一个 API 密钥", description: "查看这个密钥向 AI 的提问、回答和每次消耗的 Token。" }));
      return;
    }
    if (state.sessionKey) {
      return loadTranscript(seq);
    }
    mainPane.replaceChildren(loadingState("正在加载问答记录…", { rows: 5 }));
    const endpoint = state.view === "sessions" ? "sessions" : "calls";
    const limit = state.view === "sessions" ? SESSION_LIMIT : CALL_LIMIT;
    try {
      const result = await api(`/admin-api/conversation-logs/api-keys/${state.apiKeyId}/${endpoint}?${new URLSearchParams({ limit, offset: state.offset })}`, { silent: true });
      if (seq !== mainSeq) return;
      renderKeyView(result.data || {});
    } catch (error) {
      if (seq === mainSeq) mainPane.replaceChildren(errorState(error, { fallback: adminErrorMessage(error, "问答记录加载失败"), onRetry: loadMain }));
    }
  }

  function renderKeyView(data) {
    const key = data.api_key || {};
    const summary = data.summary || {};
    const head = el("header", { className: "conversation-key-head" }, [
      el("div", { className: "conversation-key-title" }, [
        el("h3", { className: "conversation-pane-title", text: key.name || `#${state.apiKeyId}` }),
        el("p", { className: "conversation-item-meta", text: [key.display, key.username ? `负责人 ${key.username}` : null, key.department_name].filter(Boolean).join(" · ") }),
        summary.calls ? button("清空这个密钥的记录", () => purgeKey(key), "danger conversation-purge-key") : null
      ]),
      conversationKeySettings(key, { compact: true, onSaved: () => loadKeys() })
    ]);
    const stats = el("div", { className: "conversation-summary" }, [
      stat("问答次数", formatNumber(summary.calls)),
      stat("输入 Token", formatNumber(summary.input_tokens)),
      stat("输出 Token", formatNumber(summary.output_tokens)),
      stat("合计 Token", formatNumber(summary.total_tokens), true),
      stat("费用", formatCost(summary.cost_amount, summary.currency)),
      stat("会话数", formatNumber(summary.sessions))
    ]);
    const tabs = el("div", { className: "conversation-view-tabs", role: "tablist", "aria-label": "查看方式" }, [
      viewTab("calls", "逐条问答"),
      viewTab("sessions", "按会话")
    ]);
    const list = el("div", { className: state.view === "calls" ? "conversation-calls" : "conversation-list" });
    const items = data.items || [];
    if (items.length === 0) {
      list.append(emptyState({ title: "暂无问答记录", description: key.enabled ? "用这个密钥调用文本模型后，问答和 Token 消耗会出现在这里。" : "勾选上面的「记录这个密钥的对话」后开始记录。" }));
    }
    for (const row of items) {
      list.append(state.view === "calls" ? callCard(row, summary.currency) : sessionItem(row, summary.currency));
    }
    const limit = state.view === "sessions" ? SESSION_LIMIT : CALL_LIMIT;
    const pagerNode = pager({
      total: Number(data.total),
      limit,
      offset: state.offset,
      count: items.length,
      pageSizes: [limit],
      onChange: ({ offset }) => {
        state.offset = offset;
        loadMain();
      }
    });
    mainPane.replaceChildren(head, stats, tabs, list, el("div", { className: "conversation-pager" }, [pagerNode]));
    mainPane.scrollTop = 0;
  }

  async function purgeKey(key) {
    const ok = await confirmDialog({
      title: `清空「${key.name || `#${key.id}`}」的会话记录`,
      message: "将永久删除这个密钥的全部问答记录，无法恢复。密钥的记录开关不变。",
      confirmLabel: "永久删除",
      requireText: "清空"
    });
    if (!ok) return;
    try {
      const result = await api("/admin-api/conversation-logs/cleanup", { method: "POST", body: JSON.stringify({ mode: "all", api_key_id: key.id, confirm: "PURGE" }) });
      toast(`已删除 ${formatNumber(result.data?.deleted || 0)} 条记录`, { tone: "success" });
      state.offset = 0;
      loadMain();
      loadKeys();
    } catch (error) {
      toast(adminErrorMessage(error, "清空失败"), { tone: "error" });
    }
  }

  async function deleteCall(row) {
    const ok = await confirmDialog({ title: "删除这条问答", message: "将永久删除这一次的提问和回答记录，无法恢复。", confirmLabel: "删除" });
    if (!ok) return;
    try {
      await api(`/admin-api/conversation-logs/calls/${row.id}`, { method: "DELETE" });
      toast("已删除", { tone: "success" });
      loadMain();
      loadKeys();
    } catch (error) {
      toast(adminErrorMessage(error, "删除失败"), { tone: "error" });
    }
  }

  async function deleteSession(sessionKey, turns) {
    const ok = await confirmDialog({ title: "删除整段会话", message: `将永久删除这段会话的 ${formatNumber(turns)} 次问答记录，无法恢复。`, confirmLabel: "删除" });
    if (!ok) return;
    try {
      const result = await api(`/admin-api/conversation-logs/sessions/${sessionKey}`, { method: "DELETE" });
      toast(`已删除 ${formatNumber(result.data?.deleted || 0)} 条记录`, { tone: "success" });
      state.sessionKey = null;
      state.turnOffset = 0;
      loadMain();
      loadKeys();
    } catch (error) {
      toast(adminErrorMessage(error, "删除失败"), { tone: "error" });
    }
  }

  function stat(label, value, strong = false) {
    return el("div", { className: `conversation-stat${strong ? " is-strong" : ""}` }, [el("small", { text: label }), el("strong", { text: value })]);
  }

  function viewTab(view, label) {
    const active = state.view === view;
    return el("button", {
      type: "button",
      role: "tab",
      className: `conversation-view-tab${active ? " is-active" : ""}`,
      "aria-selected": active ? "true" : "false",
      text: label,
      onClick: () => {
        if (state.view === view) return;
        state.view = view;
        state.offset = 0;
        loadMain();
      }
    });
  }

  function callCard(row, currency) {
    const detail = el("div", { className: "conversation-thread conversation-call-detail", hidden: true });
    const toggle = button("展开", () => {
      const open = detail.hidden;
      detail.hidden = !open;
      toggle.textContent = open ? "收起" : "展开";
      if (open && detail.childElementCount === 0) {
        const media = mediaMap(row);
        for (const message of row.messages || []) {
          detail.append(messageNode(message, media, row.params));
        }
        detail.append(replyNode(row));
      }
    }, "secondary conversation-call-toggle");
    return el("article", { className: "conversation-call" }, [
      el("header", { className: "conversation-call-head" }, [
        el("span", { className: "conversation-item-meta", text: [formatDateTime(row.created_at), row.model_name, row.status && row.status !== "success" ? `状态 ${row.status}` : null].filter(Boolean).join(" · ") }),
        tokenChips(row, currency)
      ]),
      el("p", { className: "conversation-qa" }, [el("span", { className: "conversation-qa-label", text: "问" }), el("span", { text: questionText(row.messages) })]),
      el("p", { className: "conversation-qa is-answer" }, [el("span", { className: "conversation-qa-label", text: "答" }), el("span", { text: answerText(row.reply, row.media || []) })]),
      mediaThumbs(row),
      el("div", { className: "conversation-call-actions" }, [
        toggle,
        button("查看整段会话", () => {
          state.sessionKey = row.session_key;
          state.turnOffset = 0;
          loadMain();
        }, "secondary"),
        button("删除", () => deleteCall(row), "secondary conversation-delete-call")
      ]),
      detail
    ]);
  }

  function sessionItem(row, currency) {
    return el("button", {
      type: "button",
      className: "conversation-item",
      onClick: () => {
        state.sessionKey = row.session_key;
        state.turnOffset = 0;
        loadMain();
      }
    }, [
      el("span", { className: "conversation-item-title", text: row.title }),
      el("span", { className: "conversation-item-meta", text: [`${formatNumber(row.turns)} 次问答`, `${formatNumber(row.total_tokens)} Token`, row.cost_amount !== null && row.cost_amount !== undefined ? formatCost(row.cost_amount, currency) : null, (row.models || []).join("、")].filter(Boolean).join(" · ") }),
      el("span", { className: "conversation-item-meta", text: `${formatDateTime(row.started_at)} — ${formatDateTime(row.last_at)}` })
    ]);
  }

  async function loadTranscript(seq) {
    mainPane.replaceChildren(loadingState("正在加载会话…", { rows: 6 }));
    try {
      const result = await api(`/admin-api/conversation-logs/sessions/${state.sessionKey}?${new URLSearchParams({ limit: TURN_LIMIT, offset: state.turnOffset })}`, { silent: true });
      if (seq !== mainSeq) return;
      renderTranscript(result.data || {});
    } catch (error) {
      if (seq === mainSeq) mainPane.replaceChildren(errorState(error, { fallback: adminErrorMessage(error, "会话加载失败"), onRetry: loadMain }));
    }
  }

  function renderTranscript(data) {
    const back = button("← 返回问答列表", () => {
      state.sessionKey = null;
      state.turnOffset = 0;
      loadMain();
    }, "secondary");
    const head = el("header", { className: "conversation-transcript-head" }, [
      el("div", { className: "conversation-transcript-title" }, [
        back,
        el("h3", { className: "conversation-pane-title", text: data.api_key?.name ? `${data.api_key.name} · 整段会话` : "整段会话" }),
        button("删除整段会话", () => deleteSession(data.session_key, data.turns_total), "danger conversation-delete-session")
      ]),
      el("p", { className: "conversation-item-meta", text: [
        data.api_key?.display,
        data.user?.username ? `负责人 ${data.user.username}` : null,
        `${formatDateTime(data.started_at)} — ${formatDateTime(data.last_at)}`
      ].filter(Boolean).join(" · ") }),
      tokenChips({ input_tokens: data.input_tokens, output_tokens: data.output_tokens, total_tokens: data.total_tokens, cost_amount: data.cost_amount }, data.currency),
      el("p", { className: "conversation-item-meta", text: `共 ${formatNumber(data.turns_total)} 次问答` })
    ]);
    const thread = el("div", { className: "conversation-thread" });
    const turns = data.turns || [];
    let lastSystem = null;
    for (const turn of turns) {
      if (turn.prior_count > 0) {
        thread.append(el("p", { className: "conversation-divider", text: `此前已有 ${turn.prior_count} 条消息（开启会话记录前产生，未记录）` }));
      }
      if (turn.system && turn.system !== lastSystem) {
        lastSystem = turn.system;
        thread.append(collapsible("系统提示词", turn.system, "conversation-system"));
      }
      const media = mediaMap(turn);
      for (const message of turn.messages || []) {
        thread.append(messageNode(message, media, turn.params));
      }
      thread.append(replyNode(turn, data.currency));
    }
    if (turns.length === 0) {
      thread.append(emptyState({ title: "这一页没有内容" }));
    }
    const pagerNode = data.turns_total > TURN_LIMIT ? pager({
      total: Number(data.turns_total),
      limit: TURN_LIMIT,
      offset: state.turnOffset,
      count: turns.length,
      pageSizes: [TURN_LIMIT],
      label: "问答",
      onChange: ({ offset }) => {
        state.turnOffset = offset;
        loadMain();
      }
    }) : null;
    mainPane.replaceChildren(head, thread, pagerNode ? el("div", { className: "conversation-pager" }, [pagerNode]) : "");
    mainPane.scrollTop = 0;
  }

  loadMain();
  await loadKeys();
}

function messageNode(message, media = new Map(), params = null) {
  const role = message.role === "tool" ? "tool" : (message.role === "assistant" ? "assistant" : "user");
  const label = role === "tool" ? "工具结果" : (role === "assistant" ? "助手（历史）" : "提问");
  const bubble = el("div", { className: "admin-chat-bubble" });
  appendParts(bubble, message.parts || [], media);
  if (role === "user" && params) {
    const chips = paramsNode(params);
    if (chips) bubble.append(chips);
  }
  return chatMessage(role === "user" ? "user" : "assistant", `conversation-message is-${role}`, role === "user" ? "问" : (role === "tool" ? "工" : "AI"), label, bubble);
}

function replyNode(turn, currency = "") {
  const reply = turn.reply || {};
  const bubble = el("div", { className: `admin-chat-bubble${reply.error ? " is-error" : ""}` });
  if (reply.reasoning) {
    bubble.append(collapsible("思考过程", reply.reasoning, "conversation-reasoning"));
  }
  if (reply.text) {
    const markdown = el("div", { className: "admin-chat-bubble is-markdown conversation-markdown" });
    markdown.append(renderMarkdown(reply.text));
    bubble.append(markdown);
  }
  for (const call of reply.tool_calls || []) {
    bubble.append(toolCallNode(call));
  }
  const media = mediaMap(turn);
  const shown = new Set();
  const gallery = el("div", { className: "conversation-media-gallery" });
  for (const part of reply.media || []) {
    const item = part.media ? media.get(part.media) : null;
    if (item) shown.add(item.key);
    gallery.append(item ? mediaItem(item, part) : mediaNode(part));
  }
  // Generated files copied from the task, and the task's own state.
  let taskNodes = 0;
  for (const item of turn.media || []) {
    if (item.direction !== "output" || shown.has(item.key)) continue;
    if (item.source === "task") {
      const node = taskStatusNode(item);
      if (node) {
        bubble.append(node);
        taskNodes += 1;
      }
      continue;
    }
    gallery.append(mediaItem(item));
  }
  if (gallery.childElementCount) bubble.append(gallery);
  if (reply.note) {
    bubble.append(el("p", { className: "conversation-muted conversation-note", text: reply.note }));
  }
  if (reply.error) {
    bubble.append(el("p", { className: "conversation-error", text: `错误：${reply.error}` }));
  }
  if (!reply.text && !(reply.tool_calls || []).length && !gallery.childElementCount && !taskNodes && !reply.note && !reply.error) {
    bubble.append(el("span", { className: "conversation-muted", text: "（无文本回复）" }));
  }
  const meta = [
    turn.model_name,
    FORMAT_LABELS[turn.format] || turn.format,
    turn.status && turn.status !== "success" ? `状态 ${turn.status}` : null,
    turn.latency_ms ? `${formatNumber(turn.latency_ms)} ms` : null,
    formatDateTime(turn.created_at)
  ].filter(Boolean).join(" · ");
  return chatMessage("assistant", "conversation-message is-reply", "AI", "回答", bubble,
    el("div", { className: "conversation-turn-footer" }, [
      tokenChips(turn, turn.cost_currency || currency),
      el("span", { className: "conversation-turn-meta", text: meta, title: turn.request_id ? `请求 ID：${turn.request_id}` : "" })
    ]));
}

// Same structure as the 会话测试 transcript (avatar + role + bubble).
function chatMessage(side, className, avatar, label, bubble, meta = null) {
  return el("div", { className: `admin-chat-message ${side} ${className}` }, [
    el("div", { className: "admin-chat-avatar", "aria-hidden": "true", text: avatar }),
    el("div", { className: "admin-chat-message-body" }, [
      el("span", { className: "admin-chat-role", text: label }),
      bubble,
      meta
    ])
  ]);
}

function appendParts(bubble, parts, media = new Map()) {
  if (parts.length === 0) {
    bubble.append(el("span", { className: "conversation-muted", text: "（空消息）" }));
  }
  for (const part of parts) {
    const item = part.media ? media.get(part.media) : null;
    if (item) {
      bubble.append(mediaItem(item, part));
      continue;
    }
    if (part.type === "text") {
      bubble.append(el("div", { className: "conversation-text", text: part.text }));
    } else if (part.type === "tool_call") {
      bubble.append(toolCallNode(part));
    } else if (part.type === "tool_result") {
      bubble.append(collapsible(`工具结果${part.name ? `（${part.name}）` : ""}`, part.text, "conversation-tool"));
    } else {
      bubble.append(mediaNode(part));
    }
  }
}

function toolCallNode(call) {
  return collapsible(`调用工具 ${call.name || ""}`.trim(), prettyJson(call.arguments), "conversation-tool");
}

function mediaNode(part) {
  return el("span", { className: "conversation-media", text: `[${PART_LABELS[part.type] || part.type}] ${part.ref || ""}`.trim() });
}

function collapsible(summary, body, className) {
  return el("details", { className: `conversation-details ${className}` }, [
    el("summary", { text: summary }),
    el("pre", { className: "conversation-pre", text: body || "" })
  ]);
}

function prettyJson(value) {
  if (typeof value !== "string") {
    return JSON.stringify(value, null, 2);
  }
  try {
    return JSON.stringify(JSON.parse(value), null, 2);
  } catch {
    return value;
  }
}

/**
 * Per-key switch: 记录这个密钥的对话 + 保存天数. Used on the 会话记录 page and
 * in the API 密钥 page's 「会话记录」 dialog. Super administrators only. While
 * the feature itself is off the switch is locked with a link to turn it on.
 */
export function conversationKeySettings(key, { compact = false, onSaved = null, onView = null, onLeave = null } = {}) {
  const featureOn = key.feature_enabled !== false;
  const id = `conversation-key-${key.id}-${Math.random().toString(36).slice(2, 8)}`;
  const enabled = el("input", { type: "checkbox", id, name: "enabled", checked: Boolean(key.enabled), disabled: !featureOn && !key.enabled });
  const days = el("input", {
    type: "number", name: "retention_days", min: "1", max: "3650", step: "1",
    value: key.custom_retention ? String(key.retention_days) : "",
    placeholder: `默认 ${key.default_retention_days ?? key.retention_days ?? 30}`,
    "aria-label": "保存天数"
  });
  const output = el("p", { className: "notice", hidden: true, role: "status" });
  const save = button("保存", async () => {
    if (!days.reportValidity()) return;
    if (enabled.checked && !key.enabled) {
      const ok = await confirmDialog({
        title: `开启「${key.name || `#${key.id}`}」的会话记录`,
        message: "开启后，用这个密钥调用文本模型的完整对话内容会被加密保存，仅超级管理员可查看。请确认符合本单位的数据管理制度，并已告知密钥使用方。",
        confirmLabel: "确认开启",
        tone: "primary"
      });
      if (!ok) {
        enabled.checked = false;
        return;
      }
    }
    try {
      const result = await api(`/admin-api/conversation-logs/api-keys/${key.id}`, {
        method: "PUT",
        body: JSON.stringify({ enabled: enabled.checked, retention_days: days.value === "" ? null : Number(days.value) })
      });
      Object.assign(key, result.data || {});
      days.placeholder = `默认 ${key.default_retention_days ?? 30}`;
      output.hidden = false;
      output.className = "notice success";
      output.textContent = key.enabled ? `已开启，保存 ${key.retention_days} 天。` : "已关闭，不再记录这个密钥的新对话。";
      onSaved?.(key);
    } catch (error) {
      output.hidden = false;
      output.className = "notice error";
      output.textContent = adminErrorMessage(error, "保存失败");
    }
  }, compact ? "secondary" : "");
  const gate = featureOn ? null : el("div", { className: "notice warning conversation-feature-gate" }, [
    el("span", { text: key.enabled
      ? "会话记录功能已在系统设置中关闭，这个密钥暂不记录。"
      : "需要先在「系统设置 › 会话记录设置」中启用会话记录功能，才能为密钥开启。" }),
    button("去启用", () => {
      onLeave?.();
      navigateTo("conversation-log-settings");
    }, "secondary")
  ]);
  return el("div", { className: `conversation-key-settings${compact ? " is-compact" : ""}` }, [
    gate,
    el("div", { className: "conversation-switch" }, [
      enabled,
      el("div", {}, [
        el("label", { htmlFor: id, className: "conversation-switch-label", text: "记录这个密钥的对话" }),
        compact ? null : el("small", { text: "开启后，用这个密钥调用 Chat、Responses、Messages、Gemini 等文本接口的对话会被加密保存。关闭后不再记录新对话，已有记录按保存天数清理。" })
      ])
    ]),
    el("div", { className: "conversation-key-days" }, [
      el("span", { className: "field-label", text: "保存天数" }),
      days,
      save,
      onView ? button("查看对话", onView, "secondary") : null
    ]),
    output
  ]);
}

// ---------------------------------------------------------------- settings page

const TIMEZONES = [
  ["Asia/Shanghai", "北京时间（Asia/Shanghai）"],
  ["Asia/Hong_Kong", "香港（Asia/Hong_Kong）"],
  ["Asia/Taipei", "台北（Asia/Taipei）"],
  ["Asia/Singapore", "新加坡（Asia/Singapore）"],
  ["Asia/Tokyo", "东京（Asia/Tokyo）"],
  ["Europe/London", "伦敦（Europe/London）"],
  ["Europe/Berlin", "柏林（Europe/Berlin）"],
  ["America/New_York", "纽约（America/New_York）"],
  ["America/Los_Angeles", "洛杉矶（America/Los_Angeles）"],
  ["UTC", "UTC"]
];

const HISTORY_TRIGGERS = { schedule: "定时清理", manual: "手动清理", purge: "清空全部", disable: "关闭功能时清空", session: "删除会话", call: "删除单条问答", compress: "压缩文件", cos_sync: "同步到 COS", cos_pull: "从 COS 迁回本地" };

const COS_REGIONS = [
  ["ap-beijing", "北京"], ["ap-shanghai", "上海"], ["ap-guangzhou", "广州"], ["ap-nanjing", "南京"], ["ap-chengdu", "成都"], ["ap-chongqing", "重庆"],
  ["ap-hongkong", "中国香港"], ["ap-singapore", "新加坡"], ["ap-tokyo", "东京"], ["ap-seoul", "首尔"], ["ap-bangkok", "曼谷"], ["ap-jakarta", "雅加达"],
  ["na-siliconvalley", "硅谷"], ["na-ashburn", "弗吉尼亚"], ["eu-frankfurt", "法兰克福"], ["sa-saopaulo", "圣保罗"]
];

function formatBytes(bytes) {
  const value = Number(bytes) || 0;
  if (value < 1024) return `${value} B`;
  const units = ["KB", "MB", "GB", "TB"];
  let size = value / 1024;
  let unit = 0;
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024;
    unit += 1;
  }
  return `${size.toFixed(size >= 100 ? 0 : 1)} ${units[unit]}`;
}
const HISTORY_MODES = { expired: "按保存规则", before: "按日期", all: "全部", session: "整段会话", call: "单条问答" };

function checkboxField(name, label, hint, checked) {
  const id = `conversation-setting-${name}`;
  return el("div", { className: "conversation-switch" }, [
    el("input", { type: "checkbox", id, name, checked: Boolean(checked) }),
    el("div", {}, [
      el("label", { htmlFor: id, className: "conversation-switch-label", text: label }),
      hint ? el("small", { text: hint }) : null
    ])
  ]);
}

export async function renderConversationLogSettingsPage(content, context = {}) {
  clear(content);
  content.append(loadingState("正在加载设置…"));
  let data;
  try {
    data = (await api("/admin-api/conversation-logs/settings", { silent: true })).data || {};
  } catch (error) {
    content.replaceChildren(errorState(error, { fallback: "会话记录设置加载失败", onRetry: () => renderConversationLogSettingsPage(content, context) }));
    return;
  }
  clear(content);

  // ---------------------------------------------------------------- feature + rules
  const featureSwitch = checkboxField("enabled", "启用会话记录功能",
    "默认关闭。启用后，才能在「API 接入 › API 密钥」里为单个密钥开启会话记录；关闭后所有密钥都停止记录。", data.enabled);
  const days = el("input", { type: "number", name: "retention_days", min: "1", max: "3650", step: "1", required: true, value: String(data.retention_days ?? 30) });
  const cap = el("input", { type: "number", name: "max_records_per_key", min: "0", max: "10000000", step: "1", required: true, value: String(data.max_records_per_key ?? 0) });
  const purgeDeleted = checkboxField("purge_deleted_keys", "密钥删除后清除它的记录", "在下一次清理时删除已删除密钥的全部会话记录。", data.purge_deleted_keys !== false);
  const autoCleanup = checkboxField("auto_cleanup", "每天自动清理", "按保存天数、条数上限和上面的规则自动删除过期记录。", data.auto_cleanup !== false);
  const compressDaily = checkboxField("compress_daily", "每天压缩记录文件", "会话内容按「日期/密钥」存放在独立目录中；每天把前一天及更早的文件压缩并加密成一个文件，节省磁盘空间。", data.compress_daily !== false);
  const recordMedia = checkboxField("record_media", "记录图片、视频、音频",
    "保存提问中的图片、音频、视频，以及图片 / 视频 / 语音 / 音乐生成的结果和语音识别上传的音频。文件加密后放在会话记录目录（选用 COS 时一并上传），随记录一起清理。", data.record_media !== false);
  const mediaMax = el("input", { type: "number", name: "media_max_mb", min: "1", max: "2048", step: "1", required: true, value: String(data.media_max_mb ?? 100) });
  const mediaMaxField = el("label", { className: "field is-required" }, [el("span", { className: "field-label", text: "单个媒体文件上限（MB）" }), mediaMax, el("small", { className: "field-hint", text: "超过的文件不保存，只记一条说明；可填 1～2048。" })]);
  const time = el("input", { type: "time", name: "cleanup_time", required: true, value: data.cleanup_time || "03:00" });
  const zones = [...TIMEZONES];
  if (data.timezone && !zones.some(([value]) => value === data.timezone)) {
    zones.unshift([data.timezone, data.timezone]);
  }
  const timezone = el("select", { name: "timezone" }, zones.map(([value, label]) => el("option", { value, text: label, selected: value === data.timezone })));
  const output = el("p", { className: "notice", hidden: true, role: "status" });
  const checked = (node) => node.querySelector("input").checked;
  const driverOption = (value, label, hint) => el("label", { className: "conversation-storage-option" }, [
    el("input", { type: "radio", name: "storage_driver", value, checked: (data.storage_driver || "local") === value }),
    el("span", {}, [el("strong", { text: label }), el("small", { text: hint })])
  ]);
  const storageChoice = el("div", { className: "conversation-storage-choice", role: "radiogroup", "aria-label": "存储方式" }, [
    driverOption("local", "本地存储", "保存在服务器的会话记录目录中（默认）。"),
    driverOption("cos", "腾讯云 COS", "保存到腾讯云对象存储：当天的文件每 5 分钟上传一次，归档文件上传后按设置从本地删除。需先在下方配置 COS 并测试连接。")
  ]);
  const selectedDriver = () => storageChoice.querySelector('input[name="storage_driver"]:checked')?.value || "local";

  const form = guardForm(el("form", { className: "edit-form conversation-settings-form", onSubmit: (event) => event.preventDefault() }, [
    featureSwitch,
    el("h4", { className: "conversation-form-heading", text: "存储方式" }),
    storageChoice,
    el("h4", { className: "conversation-form-heading", text: "保存规则" }),
    el("label", { className: "field is-required" }, [el("span", { className: "field-label", text: "默认保存天数" }), days, el("small", { className: "field-hint", text: "密钥没有单独设置保存天数时使用，可填 1～3650。" })]),
    el("label", { className: "field is-required" }, [el("span", { className: "field-label", text: "每个密钥最多保留条数" }), cap, el("small", { className: "field-hint", text: "超过时删除最早的记录；0 表示不限制。" })]),
    purgeDeleted,
    el("h4", { className: "conversation-form-heading", text: "多模态内容" }),
    recordMedia,
    mediaMaxField,
    el("h4", { className: "conversation-form-heading", text: "每日任务" }),
    compressDaily,
    autoCleanup,
    el("label", { className: "field is-required" }, [el("span", { className: "field-label", text: "每日执行时间" }), time, el("small", { className: "field-hint", text: "每天在这个时间压缩文件并清理过期记录。" })]),
    el("label", { className: "field" }, [el("span", { className: "field-label", text: "时区（也决定按哪天分目录）" }), timezone]),
    el("div", { className: "toolbar-actions" }, [button("保存设置", save)]),
    output
  ]));
  const syncAutoFields = () => {
    const daily = checked(autoCleanup) || checked(compressDaily);
    time.disabled = !daily;
    timezone.disabled = !daily;
  };
  const syncMediaFields = () => {
    mediaMax.disabled = !checked(recordMedia);
  };
  recordMedia.querySelector("input").addEventListener("change", syncMediaFields);
  syncMediaFields();
  autoCleanup.querySelector("input").addEventListener("change", syncAutoFields);
  compressDaily.querySelector("input").addEventListener("change", syncAutoFields);
  syncAutoFields();

  async function save() {
    if (!form.reportValidity()) {
      return;
    }
    const payload = {
      enabled: checked(featureSwitch),
      compress_daily: checked(compressDaily),
      retention_days: Number(days.value),
      max_records_per_key: Number(cap.value),
      purge_deleted_keys: checked(purgeDeleted),
      auto_cleanup: checked(autoCleanup),
      cleanup_time: time.value,
      timezone: timezone.value,
      storage_driver: selectedDriver(),
      record_media: checked(recordMedia),
      media_max_mb: Number(mediaMax.value)
    };
    if (payload.storage_driver === "cos" && data.storage_driver !== "cos") {
      const ok = await confirmDialog({
        title: "改用腾讯云 COS 存储",
        message: "保存后，本地已有的会话记录文件会在下次同步时上传到 COS，归档文件上传成功后按“本地保留天数”从服务器删除。",
        confirmLabel: "确认改用 COS",
        tone: "primary"
      });
      if (!ok) return;
    }
    if (payload.storage_driver === "local" && data.storage_driver === "cos") {
      const ok = await confirmDialog({
        title: "改回本地存储",
        message: "改回后不再上传新文件；已在 COS 中的记录仍可查看。如需把它们下载回服务器，保存后点「从 COS 迁回本地」。",
        confirmLabel: "确认改回",
        tone: "primary"
      });
      if (!ok) return;
    }
    if (payload.enabled && !data.enabled) {
      const ok = await confirmDialog({
        title: "启用会话记录功能",
        message: "启用后，超级管理员可以为单个 API 密钥开启会话记录，记录用这个密钥向 AI 的完整问答和 Token 消耗。请确认符合本单位的数据管理制度，并已告知相关使用方。",
        confirmLabel: "确认启用",
        tone: "primary"
      });
      if (!ok) {
        featureSwitch.querySelector("input").checked = false;
        return;
      }
    }
    if (!payload.enabled && data.enabled) {
      const purge = el("input", { type: "checkbox", name: "purge_on_disable" });
      const ok = await confirmDialog({
        title: "关闭会话记录功能",
        message: "关闭后所有密钥都会停止记录（各密钥的开关保留，重新启用后恢复）。已有记录仍按保存规则清理。",
        confirmLabel: "确认关闭",
        body: el("label", { className: "conversation-switch conversation-confirm-option" }, [purge, el("span", { text: "同时删除全部已记录的对话（不可恢复）" })])
      });
      if (!ok) {
        featureSwitch.querySelector("input").checked = true;
        return;
      }
      payload.purge = purge.checked;
    }
    try {
      const saved = await api("/admin-api/conversation-logs/settings", { method: "PUT", body: JSON.stringify(payload) });
      data = saved.data || data;
      markClean(form);
      output.hidden = false;
      output.className = "notice success";
      output.textContent = data.enabled ? "已保存，会话记录功能已启用。" : "已保存，会话记录功能未启用。";
      renderStatus();
      renderHistory();
      renderCos();
    } catch (error) {
      output.hidden = false;
      output.className = "notice error";
      output.textContent = adminErrorMessage(error, "保存失败，请检查输入");
    }
  }

  // ---------------------------------------------------------------- status
  const statusPanel = el("section", { className: "panel settings-form-panel conversation-status" });
  function renderStatus() {
    const stats = data.stats || {};
    const storage = data.storage || {};
    const database = data.database || {};
    const media = data.media || {};
    const last = data.last_cleanup;
    statusPanel.replaceChildren(...[
      el("h3", { text: "存储状态" }),
      stats.columns_missing
        ? el("p", { className: "notice error", text: "API 密钥表缺少会话记录字段，请先执行数据库迁移（php bin/migrate.php）。" })
        : null,
      el("dl", { className: "conversation-stats" }, [
        el("dt", { text: "功能状态" }), el("dd", {}, [el("span", { className: `status-pill ${data.enabled ? "is-positive" : "is-muted"}`, text: data.enabled ? "已启用" : "未启用" })]),
        el("dt", { text: "开启记录的密钥" }), el("dd", { text: `${formatNumber(stats.enabled_keys || 0)} 个` }),
        el("dt", { text: "有记录的密钥" }), el("dd", { text: `${formatNumber(stats.keys || 0)} 个` }),
        el("dt", { text: "记录条数" }), el("dd", { text: formatNumber(stats.records || 0) }),
        el("dt", { text: "最早记录" }), el("dd", { text: stats.oldest_at ? formatDateTime(stats.oldest_at) : "—" }),
        el("dt", { text: "上次自动/立即清理" }), el("dd", { text: last?.last_run_at ? `${formatDateTime(last.last_run_at)}（删除 ${formatNumber(last.deleted || 0)} 条）` : "尚未清理" }),
        el("dt", { text: "下次每日任务" }), el("dd", { text: data.auto_cleanup === false && data.compress_daily === false ? "已关闭" : (data.next_cleanup_at ? formatDateTime(data.next_cleanup_at) : "—") })
      ]),
      el("h3", { text: "存储目录" }),
      storage.writable === false
        ? el("p", { className: "notice error", text: "目录不可写：新的会话内容会暂存在会话记录的独立数据库中。请检查目录权限或 CONVERSATION_LOG_PATH 配置。" })
        : null,
      el("dl", { className: "conversation-stats" }, [
        el("dt", { text: "目录" }), el("dd", { className: "cell-mono", text: storage.path || "—" }),
        el("dt", { text: "文件" }), el("dd", { text: `${formatNumber(storage.files || 0)} 个（${formatNumber(storage.days || 0)} 天）` }),
        el("dt", { text: "未压缩（当天）" }), el("dd", { text: formatBytes(storage.active_bytes) }),
        el("dt", { text: "已压缩" }), el("dd", { text: formatBytes(storage.packed_bytes) }),
        el("dt", { text: "合计占用" }), el("dd", { text: formatBytes(storage.total_bytes) }),
        el("dt", { text: "最早一天" }), el("dd", { text: storage.oldest_day || "—" })
      ]),
      el("p", { className: "conversation-muted", text: "目录结构：年/月/日/key-密钥ID.jsonl（当天，逐条加密）→ 次日压缩为 .jsonl.gz.enc（整体加密）；图片、视频、音频在 media/年/月/日/key-密钥ID/ 下，每个文件单独加密。Docker 部署时位于 storage 数据卷中，会随 backup.sh 一起备份。" }),
      el("h3", { text: "多模态内容" }),
      media.error ? el("p", { className: "notice error", text: `媒体文件统计失败：${media.error}` }) : null,
      el("dl", { className: "conversation-stats" }, [
        el("dt", { text: "记录多模态内容" }), el("dd", {}, [el("span", { className: `status-pill ${data.record_media !== false ? "is-positive" : "is-muted"}`, text: data.record_media !== false ? `已开启（单个文件上限 ${formatNumber(data.media_max_mb ?? 100)} MB）` : "已关闭（只记占位说明）" })]),
        el("dt", { text: "已保存" }), el("dd", { text: `${formatNumber(media.stored || 0)} 个（${formatBytes(media.stored_bytes)}）` }),
        el("dt", { text: "服务器上的媒体文件" }), el("dd", { text: `${formatNumber(media.local_files || 0)} 个（${formatBytes(media.local_bytes)}）` }),
        el("dt", { text: "等待保存" }), el("dd", { text: `${formatNumber(media.pending || 0)} 个（生成中的任务、待下载的链接）` }),
        el("dt", { text: "超过上限未保存" }), el("dd", { text: `${formatNumber(media.skipped || 0)} 个` }),
        el("dt", { text: "保存失败" }), el("dd", { text: `${formatNumber(media.failed || 0)} 个` })
      ]),
      el("h3", { text: "独立数据库" }),
      database.error
        ? el("p", { className: "notice error", text: `会话记录数据库无法连接：${database.error}` })
        : null,
      el("dl", { className: "conversation-stats" }, [
        el("dt", { text: "类型" }), el("dd", { text: database.driver === "mysql" ? "MySQL（独立库）" : database.driver === "sqlite" ? "SQLite 文件" : "—" }),
        el("dt", { text: "位置" }), el("dd", { className: "cell-mono", text: database.location || "—" }),
        database.bytes !== null && database.bytes !== undefined ? el("dt", { text: "大小" }) : null,
        database.bytes !== null && database.bytes !== undefined ? el("dd", { text: formatBytes(database.bytes) }) : null,
        el("dt", { text: "暂存在数据库的问答" }), el("dd", { text: `${formatNumber(stats.db_records || 0)} 条（目录不可写时才会出现）` })
      ].filter(Boolean)),
      el("p", { className: "conversation-muted", text: "索引（密钥、会话、时间、Token、费用）和目录不可写时的暂存内容都放在这个独立数据库中，不占用 TokenHub 主数据库。默认是会话记录目录下的 index.sqlite；访问量大时可在 .env 中设置 CONVERSATION_LOG_DB_DSN 改用单独的 MySQL 库。" }),
      el("div", { className: "toolbar-actions" }, [
        button("立即压缩", async () => {
          try {
            const result = (await api("/admin-api/conversation-logs/compress", { method: "POST", body: "{}" })).data || {};
            toast(result.files ? `已压缩 ${formatNumber(result.files)} 个文件（${formatBytes(result.bytes_before)} → ${formatBytes(result.bytes_after)}）` : "没有需要压缩的文件（当天的文件次日压缩）", { tone: "success" });
            await refresh();
          } catch (error) {
            toast(adminErrorMessage(error, "压缩失败"), { tone: "error" });
          }
        }, "secondary"),
        button("立即保存待处理的媒体", async () => {
          try {
            const result = (await api("/admin-api/conversation-logs/media/process", { method: "POST", body: "{}" })).data || {};
            toast(`已保存 ${formatNumber(result.stored || 0)} 个，处理完成的任务 ${formatNumber(result.tasks || 0)} 个${result.failed ? `，失败 ${formatNumber(result.failed)} 个` : ""}`, { tone: result.failed ? "error" : "success" });
            await refresh();
          } catch (error) {
            toast(adminErrorMessage(error, "处理失败"), { tone: "error" });
          }
        }, "secondary"),
        button("立即清理过期记录", async () => {
          try {
            const result = await api("/admin-api/conversation-logs/cleanup", { method: "POST", body: "{}" });
            toast(`已清理 ${formatNumber(result.data?.deleted || 0)} 条过期记录`, { tone: "success" });
            await refresh();
          } catch (error) {
            toast(adminErrorMessage(error, "清理失败"), { tone: "error" });
          }
        }, "secondary"),
        button("查看会话记录", () => context.navigate?.("conversation-logs"), "secondary")
      ])
    ].filter(Boolean));
  }

  // ---------------------------------------------------------------- manual cleanup
  const keySelect = field({ name: "api_key_id", label: "范围（留空为全部密钥）", type: "remote-select", lookup: "api-keys", optional: true, emptyOption: "全部密钥" }, "");
  const mode = el("select", { name: "mode" }, [
    el("option", { value: "expired", text: "按保存规则删除过期记录" }),
    el("option", { value: "before", text: "删除某日期之前的记录" }),
    el("option", { value: "all", text: "删除全部记录" })
  ]);
  const before = el("input", { type: "date", name: "before" });
  const beforeField = el("label", { className: "field", hidden: true }, [el("span", { className: "field-label", text: "删除此日期（不含）之前的记录" }), before]);
  const previewOutput = el("p", { className: "notice", hidden: true, role: "status" });
  mode.addEventListener("change", () => {
    beforeField.hidden = mode.value !== "before";
    previewOutput.hidden = true;
  });
  const criteria = () => {
    const apiKeyId = Number(keySelect.querySelector('[name="api_key_id"]')?.value || 0);
    const body = { mode: mode.value };
    if (apiKeyId > 0) body.api_key_id = apiKeyId;
    if (mode.value === "before") body.before = before.value;
    return body;
  };
  const describe = (body) => `${body.api_key_id ? "所选密钥" : "全部密钥"}${body.mode === "before" ? ` ${body.before} 之前` : body.mode === "all" ? "的全部" : "已过期"}的记录`;
  const manualPanel = el("section", { className: "panel settings-form-panel conversation-manual" }, [
    el("h3", { text: "手动清理" }),
    el("div", { className: "conversation-manual-form" }, [
      keySelect,
      el("label", { className: "field" }, [el("span", { className: "field-label", text: "清理方式" }), mode]),
      beforeField,
      el("div", { className: "toolbar-actions" }, [
        button("预览", async () => {
          const body = criteria();
          if (body.mode === "before" && !body.before) {
            before.reportValidity?.();
            previewOutput.hidden = false;
            previewOutput.className = "notice error";
            previewOutput.textContent = "请选择日期。";
            return;
          }
          try {
            const result = (await api("/admin-api/conversation-logs/cleanup/preview", { method: "POST", body: JSON.stringify(body) })).data || {};
            previewOutput.hidden = false;
            previewOutput.className = "notice";
            previewOutput.textContent = result.matched
              ? `${describe(body)}共 ${formatNumber(result.matched)} 条（${formatDateTime(result.oldest_at)} — ${formatDateTime(result.newest_at)}）。`
              : `${describe(body)}：没有符合条件的记录。`;
          } catch (error) {
            previewOutput.hidden = false;
            previewOutput.className = "notice error";
            previewOutput.textContent = adminErrorMessage(error, "预览失败");
          }
        }, "secondary"),
        button("执行清理", async () => {
          const body = criteria();
          if (body.mode === "before" && !body.before) {
            previewOutput.hidden = false;
            previewOutput.className = "notice error";
            previewOutput.textContent = "请选择日期。";
            return;
          }
          let matched = null;
          try {
            matched = (await api("/admin-api/conversation-logs/cleanup/preview", { method: "POST", body: JSON.stringify(body) })).data?.matched ?? null;
          } catch {
            // the confirmation still works without the count
          }
          if (matched === 0) {
            toast("没有符合条件的记录", { tone: "info" });
            return;
          }
          const ok = await confirmDialog({
            title: "执行清理",
            message: `将永久删除${describe(body)}${matched !== null ? `，共 ${formatNumber(matched)} 条` : ""}，无法恢复。`,
            confirmLabel: "永久删除",
            requireText: body.mode === "all" ? "清空" : ""
          });
          if (!ok) return;
          if (body.mode === "all") body.confirm = "PURGE";
          try {
            const result = await api("/admin-api/conversation-logs/cleanup", { method: "POST", body: JSON.stringify(body) });
            toast(`已删除 ${formatNumber(result.data?.deleted || 0)} 条会话记录`, { tone: "success" });
            previewOutput.hidden = true;
            await refresh();
          } catch (error) {
            toast(adminErrorMessage(error, "清理失败"), { tone: "error" });
          }
        }, "danger")
      ]),
      previewOutput
    ])
  ]);

  // ---------------------------------------------------------------- history
  const historyPanel = el("section", { className: "panel settings-form-panel conversation-history" });
  function renderHistory() {
    const rows = data.history || [];
    historyPanel.replaceChildren(
      el("h3", { text: "清理记录" }),
      rows.length === 0
        ? el("p", { className: "conversation-muted", text: "还没有清理记录。" })
        : el("div", { className: "table-wrap" }, [el("table", { className: "conversation-history-table" }, [
          el("thead", {}, [el("tr", {}, ["时间", "方式", "范围 / 结果", "操作人", "删除条数"].map((label) => el("th", { scope: "col", text: label })))]),
          el("tbody", {}, rows.map((row) => el("tr", {}, [
            el("td", { text: formatDateTime(row.at) }),
            el("td", { text: [HISTORY_TRIGGERS[row.trigger] || row.trigger, row.mode && row.trigger === "manual" ? HISTORY_MODES[row.mode] || row.mode : null, row.before ? `${row.before} 之前` : null].filter(Boolean).join(" · ") }),
            el("td", { text: row.trigger === "compress"
              ? `${formatNumber(row.files || 0)} 个文件，${formatBytes(row.bytes_before)} → ${formatBytes(row.bytes_after)}`
              : row.trigger === "cos_sync"
                ? `上传 ${formatNumber(row.files || 0)} 个文件（${formatBytes(row.bytes_after)}）${row.error ? `，出错：${row.error}` : ""}`
                : row.trigger === "cos_pull"
                  ? `迁回 ${formatNumber(row.files || 0)} 个文件`
                  : row.api_key_name || (row.api_key_id ? `#${row.api_key_id}` : "全部密钥") }),
            el("td", { text: row.actor || "系统" }),
            el("td", { text: ["compress", "cos_sync", "cos_pull"].includes(row.trigger) ? "—" : formatNumber(row.deleted || 0) })
          ])))
        ])])
    );
  }

  async function refresh() {
    try {
      data = (await api("/admin-api/conversation-logs/settings", { silent: true })).data || data;
    } catch {
      // keep the last known state
    }
    renderStatus();
    renderHistory();
    renderCos();
  }

  // ---------------------------------------------------------------- 腾讯云 COS
  const cosPanel = el("section", { className: "panel settings-form-panel conversation-cos" });
  function renderCos() {
    const cos = data.cos || {};
    const state = cos.state || {};
    const input = (name, attrs = {}) => el("input", { name, autocomplete: "off", spellcheck: false, ...attrs });
    const secretId = input("secret_id", { type: "text", value: cos.secret_id || "", placeholder: "AKID…", required: true });
    const secretKey = input("secret_key", { type: "password", value: "", placeholder: cos.secret_key_set ? "已保存，留空不修改" : "SecretKey", required: !cos.secret_key_set, autocomplete: "new-password" });
    const bucket = input("bucket", { type: "text", value: cos.bucket || "", placeholder: "tokenhub-logs-1250000000", required: true });
    const region = input("region", { type: "text", value: cos.region || "", placeholder: "ap-guangzhou", required: true });
    region.setAttribute("list", "conversation-cos-regions");
    const regions = el("datalist", { id: "conversation-cos-regions" }, COS_REGIONS.map(([value, label]) => el("option", { value, label: `${label}（${value}）` })));
    const prefix = input("prefix", { type: "text", value: cos.prefix ?? "tokenhub/conversation-logs", placeholder: "tokenhub/conversation-logs" });
    const endpoint = input("endpoint", { type: "url", value: cos.endpoint || "", placeholder: "可选，例如内网或加速域名 https://…" });
    const storageClass = el("select", { name: "storage_class" }, [
      el("option", { value: "STANDARD", text: "标准存储", selected: (cos.storage_class || "STANDARD") === "STANDARD" }),
      el("option", { value: "STANDARD_IA", text: "低频存储（便宜，读取另计费）", selected: cos.storage_class === "STANDARD_IA" })
    ]);
    const keepDays = input("keep_local_days", { type: "number", min: "0", max: "30", step: "1", value: String(cos.keep_local_days ?? 0), required: true });
    const backupIndex = checkboxField("backup_index", "每天把索引数据库备份到 COS", "SQLite 索引每天备份一份到 COS（保留最近 7 份）；使用独立 MySQL 库时请用数据库自身的备份。", cos.backup_index !== false);
    const cosOutput = el("p", { className: "notice", hidden: true, role: "status" });
    const show = (tone, text) => {
      cosOutput.hidden = false;
      cosOutput.className = `notice ${tone}`.trim();
      cosOutput.textContent = text;
    };
    const cosForm = el("form", { className: "conversation-manual-form conversation-cos-form", onSubmit: (event) => event.preventDefault() }, [
      el("label", { className: "field is-required" }, [el("span", { className: "field-label", text: "SecretId" }), secretId]),
      el("label", { className: `field ${cos.secret_key_set ? "" : "is-required"}`.trim() }, [el("span", { className: "field-label", text: "SecretKey" }), secretKey,
        el("small", { className: "field-hint", text: "建议使用只授权这个存储桶读写的子账号密钥。密钥加密保存，不会再显示。" })]),
      el("label", { className: "field is-required" }, [el("span", { className: "field-label", text: "存储桶（Bucket）" }), bucket, el("small", { className: "field-hint", text: "格式：名称-APPID" })]),
      el("label", { className: "field is-required" }, [el("span", { className: "field-label", text: "地域（Region）" }), region, regions]),
      el("label", { className: "field" }, [el("span", { className: "field-label", text: "路径前缀" }), prefix]),
      el("label", { className: "field" }, [el("span", { className: "field-label", text: "自定义 Endpoint" }), endpoint]),
      el("label", { className: "field" }, [el("span", { className: "field-label", text: "存储类型" }), storageClass]),
      el("label", { className: "field is-required" }, [el("span", { className: "field-label", text: "归档文件本地保留天数" }), keepDays,
        el("small", { className: "field-hint", text: "上传到 COS 后在服务器上再保留几天，0 表示上传成功后立即删除本地副本。当天的文件始终先写在本地。" })]),
      backupIndex,
      el("div", { className: "toolbar-actions" }, [
        button("保存 COS 配置", async () => {
          if (!cosForm.reportValidity()) return;
          try {
            const saved = await api("/admin-api/conversation-logs/cos", { method: "PUT", body: JSON.stringify({
              secret_id: secretId.value.trim(), secret_key: secretKey.value, bucket: bucket.value.trim(), region: region.value.trim(),
              prefix: prefix.value.trim(), endpoint: endpoint.value.trim(), storage_class: storageClass.value,
              keep_local_days: Number(keepDays.value), backup_index: checked(backupIndex)
            }) });
            data.cos = saved.data || data.cos;
            renderCos();
            toast("COS 配置已保存，请点「测试连接」确认可用", { tone: "success" });
          } catch (error) {
            show("error", adminErrorMessage(error, "保存失败，请检查输入"));
          }
        }),
        button("测试连接", async () => {
          try {
            const result = (await api("/admin-api/conversation-logs/cos/test", { method: "POST", body: "{}" })).data || {};
            show("success", `连接成功：已在 ${result.host} 写入、读取、列出并删除测试文件（${formatNumber(result.elapsed_ms)} ms）。`);
          } catch (error) {
            show("error", adminErrorMessage(error, "连接失败"));
          }
        }, "secondary", { disabled: !cos.configured }),
        button("立即同步", async () => {
          try {
            const result = (await api("/admin-api/conversation-logs/cos/sync", { method: "POST", body: "{}" })).data || {};
            await refresh();
            toast(result.errors?.length
              ? `部分文件上传失败：${result.errors[0]}`
              : `已上传 ${formatNumber(result.uploaded || 0)} 个文件（${formatBytes(result.bytes)}），本地删除 ${formatNumber(result.removed_local || 0)} 个已归档文件。`, { tone: result.errors?.length ? "error" : "success" });
          } catch (error) {
            show("error", adminErrorMessage(error, "同步失败"));
          }
        }, "secondary", { disabled: !cos.configured }),
        data.storage_driver !== "cos" && cos.configured ? button("从 COS 迁回本地", async () => {
          const ok = await confirmDialog({ title: "从 COS 迁回本地", message: "把 COS 中的会话记录文件全部下载到服务器目录，成功后从 COS 删除（索引备份保留）。文件多时可能需要较长时间。", confirmLabel: "开始迁回", tone: "primary" });
          if (!ok) return;
          try {
            const result = (await api("/admin-api/conversation-logs/cos/pull", { method: "POST", body: "{}" })).data || {};
            await refresh();
            toast(result.errors?.length ? `迁回 ${result.files} 个文件，${result.errors.length} 个失败：${result.errors[0]}` : `已迁回 ${formatNumber(result.files || 0)} 个文件。`, { tone: result.errors?.length ? "error" : "success" });
          } catch (error) {
            show("error", adminErrorMessage(error, "迁回失败"));
          }
        }, "secondary") : null
      ].filter(Boolean)),
      cosOutput
    ]);
    const remote = state.remote || {};
    cosPanel.replaceChildren(...[
      el("h3", { text: "腾讯云 COS" }),
      el("p", { className: "conversation-muted", text: data.storage_driver === "cos"
        ? "当前存储方式：腾讯云 COS。"
        : "当前存储方式：本地存储。配置并测试 COS 后，可在上方「存储方式」中改用 COS。" }),
      state.last_error ? el("p", { className: "notice error", text: `最近一次同步出错：${state.last_error}` }) : null,
      el("dl", { className: "conversation-stats" }, [
        el("dt", { text: "配置状态" }), el("dd", {}, [el("span", { className: `status-pill ${cos.configured ? "is-positive" : "is-muted"}`, text: cos.configured ? "已配置" : "未配置" })]),
        el("dt", { text: "上次同步" }), el("dd", { text: state.last_sync_at ? `${formatDateTime(state.last_sync_at)}（上传 ${formatNumber(state.last_sync?.uploaded || 0)} 个文件）` : "—" }),
        el("dt", { text: "COS 中的文件" }), el("dd", { text: remote.counted_at ? `${formatNumber(remote.files || 0)} 个，${formatBytes(remote.bytes)}（统计于 ${formatDateTime(remote.counted_at)}）` : "—" })
      ]),
      cosForm
    ].filter(Boolean));
  }

  renderStatus();
  renderHistory();
  renderCos();
  content.append(
    pageHead("会话记录设置", "先在这里启用会话记录功能，再到「API 接入 › API 密钥」为需要的密钥开启。可选择本地存储或腾讯云 COS，设置保存规则、自动清理，以及手动清理。仅超级管理员可设置和查看。"),
    el("section", { className: "panel settings-form-panel" }, [form]),
    cosPanel,
    statusPanel,
    manualPanel,
    historyPanel
  );
  markClean(form);
}
