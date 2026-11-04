import { adminErrorMessage, api } from "../api.js";
import { button, el, setText, showModal, statusMessage } from "../dom.js";
import { setErrorOutput, setOperationOutput } from "../lib/output.js";
import { columnLabel } from "../resources.js";
import { providerCatalogModels } from "../lib/provider-catalog.js";

const MODEL_PRICE_CATEGORIES = [
  { key: "text", label: "文字", fields: ["input_token_price", "output_token_price"] },
  { key: "asr", label: "ASR", fields: ["audio_second_price"] },
  { key: "tts", label: "TTS", fields: ["tts_char_price"] },
  { key: "image", label: "图片", fields: ["image_price"] },
  { key: "video", label: "视频", fields: ["video_second_price"] },
  { key: "request", label: "请求", fields: ["request_price"] }
];

const MODEL_CAPABILITY_CATEGORY_LABELS = {
  text_generation: "文字",
  vision_understanding: "图片",
  video_understanding: "视频",
  audio_understanding: "音频",
  embedding: "文字",
  asr: "ASR",
  tts: "TTS",
  image_generation: "图片",
  video_generation: "视频",
  rerank: "文字",
  moderation: "审核"
};

const providerBaseUrlPresets = {
  "qianqianai": "https://api.qianqianai.com/v1",
  "deepseek": "https://api.deepseek.com",
  "openai": "https://api.openai.com",
  "anthropic": "https://api.anthropic.com",
  "gemini": "https://generativelanguage.googleapis.com/v1beta/openai",
  "moonshot": "https://api.moonshot.cn/v1",
  "kimi_code": "https://api.kimi.com/coding/v1",
  "qwen": "https://token-plan.maas.qianwenaiapi.com/compatible-mode/v1",
  "qwen_payg": "https://maas.qianwenaiapi.com/compatible-mode/v1",
  "bailian_compatible": "https://dashscope.aliyuncs.com/compatible-mode/v1",
  "siliconflow": "https://api.siliconflow.cn/v1",
  "zhipu": "https://open.bigmodel.cn/api/paas/v4",
  "doubao": "https://ark.cn-beijing.volces.com/api/v3",
  "volcengine": "https://ark.cn-beijing.volces.com/api/v3",
  "tencent": "https://api.hunyuan.cloud.tencent.com/v1",
  "openrouter": "https://openrouter.ai/api/v1",
  "groq": "https://api.groq.com/openai/v1",
  "mistral": "https://api.mistral.ai/v1",
  "ollama": "http://localhost:11434/v1",
  "minimax": "https://api.minimaxi.com",
  "bailian": "https://dashscope.aliyuncs.com",
  "gemini_native": "https://generativelanguage.googleapis.com",
  "xai": "https://api.x.ai/v1",
  "kling": "https://api-singapore.klingai.com",
  "vidu": "https://api.vidu.com",
  "bfl": "https://api.bfl.ai",
  "fal": "https://queue.fal.run",
  "replicate": "https://api.replicate.com/v1",
  "elevenlabs": "https://api.elevenlabs.io",
  "runway": "https://api.dev.runwayml.com",
  "luma": "https://agents.lumalabs.ai/v1",
  "deepgram": "https://api.deepgram.com",
  "ideogram": "https://api.ideogram.ai",
  "stability": "https://api.stability.ai",
  "hunyuan_image": "https://hunyuan.tencentcloudapi.com"
};

const providerAnthropicBaseUrlPresets = {
  "anthropic": "https://api.anthropic.com",
  "deepseek": "https://api.deepseek.com/anthropic",
  "qwen": "https://token-plan.maas.qianwenaiapi.com/apps/anthropic",
  "qwen_payg": "https://maas.qianwenaiapi.com/apps/anthropic"
};

const providerNameAliases = [
  ["qianqianai", "qianqianai"],
  ["qianqian", "qianqianai"],
  ["黔前", "qianqianai"],
  ["黔前智算", "qianqianai"],
  ["deepseek", "deepseek"],
  ["deep seek", "deepseek"],
  ["兼容 openai", "openai"],
  ["兼容openai", "openai"],
  ["openai", "openai"],
  ["chatgpt", "openai"],
  ["anthropic", "anthropic"],
  ["claude", "anthropic"],
  ["gemini", "gemini"],
  ["google", "gemini"],
  ["moonshot", "moonshot"],
  ["kimi code", "kimi_code"],
  ["kimi-code", "kimi_code"],
  ["kimi for coding", "kimi_code"],
  ["kimi-for-coding", "kimi_code"],
  ["kimi", "moonshot"],
  ["千问按量", "qwen_payg"],
  ["千问（按量", "qwen_payg"],
  ["qwen payg", "qwen_payg"],
  ["百炼兼容", "bailian_compatible"],
  ["dashscope", "bailian_compatible"],
  ["qwen", "qwen"],
  ["qianwen", "qwen"],
  ["千问", "qwen"],
  ["通义", "qwen"],
  ["siliconflow", "siliconflow"],
  ["硅基流动", "siliconflow"],
  ["zhipu", "zhipu"],
  ["智谱", "zhipu"],
  ["bigmodel", "zhipu"],
  ["volcengine", "volcengine"],
  ["volces", "volcengine"],
  ["火山引擎", "volcengine"],
  ["doubao", "doubao"],
  ["璞嗗寘", "doubao"],
  ["火山", "doubao"],
  ["腾讯云", "tencent"],
  ["腾讯", "tencent"],
  ["tencent", "tencent"],
  ["hunyuan", "tencent"],
  ["混元", "tencent"],
  ["openrouter", "openrouter"],
  ["minimax", "minimax"],
  ["海螺", "minimax"],
  ["百炼原生", "bailian"],
  ["万相", "bailian"],
  ["cosyvoice", "bailian"],
  ["gemini 原生", "gemini_native"],
  ["gemini native", "gemini_native"],
  ["xai", "xai"],
  ["grok", "xai"],
  ["可灵", "kling"],
  ["kling", "kling"],
  ["vidu", "vidu"],
  ["生数", "vidu"],
  ["flux", "bfl"],
  ["black forest", "bfl"],
  ["fal", "fal"],
  ["replicate", "replicate"],
  ["elevenlabs", "elevenlabs"],
  ["runway", "runway"],
  ["luma", "luma"],
  ["deepgram", "deepgram"],
  ["ideogram", "ideogram"],
  ["stability", "stability"],
  ["混元生图", "hunyuan_image"],
  ["groq", "groq"],
  ["mistral", "mistral"],
  ["ollama", "ollama"]
];

const providerDisplayNamePresets = [
  { name: "千问 Token Plan", protocol: "openai", baseUrl: providerBaseUrlPresets.qwen },
  { name: "千问（按量计费）", protocol: "openai", baseUrl: providerBaseUrlPresets.qwen_payg },
  { name: "黔前智算", protocol: "openai", baseUrl: providerBaseUrlPresets.qianqianai },
  { name: "DeepSeek", protocol: "openai", baseUrl: providerBaseUrlPresets.deepseek },
  { name: "OpenAI", protocol: "openai", baseUrl: providerBaseUrlPresets.openai },
  { name: "Anthropic Claude", protocol: "anthropic", baseUrl: providerBaseUrlPresets.anthropic },
  { name: "Google Gemini 原生", protocol: "gemini", baseUrl: providerBaseUrlPresets.gemini_native },
  { name: "兼容 OpenAI", protocol: "openai", baseUrl: providerBaseUrlPresets.openai },
  { name: "火山引擎", protocol: "openai", baseUrl: providerBaseUrlPresets.volcengine },
  { name: "腾讯云", protocol: "openai", baseUrl: providerBaseUrlPresets.tencent },
  { name: "Kimi API / Moonshot", protocol: "openai", baseUrl: providerBaseUrlPresets.moonshot },
  { name: "Kimi Code", protocol: "openai", baseUrl: providerBaseUrlPresets.kimi_code },
  { name: "MiniMax（海螺视频/生图/语音）", protocol: "openai", baseUrl: providerBaseUrlPresets.minimax },
  { name: "阿里云百炼原生（万相/CosyVoice）", protocol: "openai", baseUrl: providerBaseUrlPresets.bailian },
  { name: "阿里云百炼兼容", protocol: "openai", baseUrl: providerBaseUrlPresets.bailian_compatible },
  { name: "可灵 Kling", protocol: "openai", baseUrl: providerBaseUrlPresets.kling },
  { name: "生数 Vidu", protocol: "openai", baseUrl: providerBaseUrlPresets.vidu },
  { name: "xAI Grok", protocol: "openai", baseUrl: providerBaseUrlPresets.xai },
  { name: "OpenRouter", protocol: "openai", baseUrl: providerBaseUrlPresets.openrouter },
  { name: "FLUX（Black Forest Labs）", protocol: "openai", baseUrl: providerBaseUrlPresets.bfl },
  { name: "fal.ai", protocol: "openai", baseUrl: providerBaseUrlPresets.fal },
  { name: "ElevenLabs", protocol: "openai", baseUrl: providerBaseUrlPresets.elevenlabs }
];

export function renderPriceCategoryChips(row, context) {
  return el("div", { className: "price-category-chips" }, modelPriceCategoryLabels(row, context)
    .map((label) => el("span", { className: "price-category-chip", text: label })));
}

function modelPriceCategoryLabels(row, context) {
  const labels = new Set();
  const model = context.modelsById?.get(Number(row.model_id));
  const capabilityLabel = MODEL_CAPABILITY_CATEGORY_LABELS[model?.capability];
  if (capabilityLabel) {
    labels.add(capabilityLabel);
  }
  for (const category of MODEL_PRICE_CATEGORIES) {
    if (configuredPriceFields(row, category).length > 0) {
      labels.add(category.label);
    }
  }
  return labels.size > 0 ? [...labels] : ["未配置"];
}

export function modelPriceSummary(row) {
  const parts = MODEL_PRICE_CATEGORIES
    .map((category) => {
      const count = configuredPriceFields(row, category).length;
      return count > 0 ? `${category.label} ${count} 项` : null;
    })
    .filter(Boolean);
  return parts.length > 0 ? parts.join(" / ") : "未配置价格";
}

function configuredPriceFields(row, category) {
  return category.fields.filter((fieldName) => hasPriceValue(row[fieldName]));
}

function hasPriceValue(value) {
  return value !== null && value !== undefined && String(value) !== "";
}

export function showModelPriceDetails(row, context) {
  const model = context.modelsById?.get(Number(row.model_id));
  const modelTitle = model?.name ? `${model.name} / ID ${row.model_id}` : `模型 ID ${row.model_id}`;
  const cards = MODEL_PRICE_CATEGORIES.map((category) => {
    const activeFields = configuredPriceFields(row, category);
    const rows = category.fields.map((fieldName) => el("div", { className: "price-detail-row" }, [
      el("span", { text: columnLabel(fieldName) }),
      el("strong", { text: hasPriceValue(row[fieldName]) ? row[fieldName] : "未配置" })
    ]));
    return el("section", { className: `price-category-card ${activeFields.length > 0 ? "active" : ""}` }, [
      el("header", {}, [
        el("h3", { text: category.label }),
        el("span", { text: activeFields.length > 0 ? `${activeFields.length} 项` : "未配置" })
      ]),
      el("div", { className: "price-detail-list" }, rows)
    ]);
  });
  showModal("价格明细", el("div", { className: "price-detail-modal" }, [
    el("div", { className: "price-detail-summary" }, [
      el("strong", { text: modelTitle }),
      el("span", { text: `模态类型：${modelPriceCategoryLabels(row, context).join("、")}` }),
      el("span", { text: `币种：${row.currency || ""}` }),
      el("span", { text: `生效时间：${row.effective_at || ""}` })
    ]),
    el("div", { className: "price-category-grid" }, cards)
  ]));
}

export async function showProviderModelImport(row, onSaved) {
  const output = el("p", { className: "notice", hidden: true });
  const body = el("div", { className: "provider-model-import" }, [
    statusMessage("正在拉取模型列表...")
  ]);
  showModal(`拉取模型 - ${row.name || row.id}`, body);

  try {
    const result = await api(`/admin-api/providers/${row.id}/models`);
    const { models, hiddenNotice } = providerCatalogModels(result.data);
    if (models.length === 0) {
      body.replaceChildren(...[hiddenNotice, statusMessage(hiddenNotice ? "该厂商没有 TokenHub 可以调用的模型" : "未从该厂商拉取到模型", "error")].filter(Boolean));
      return;
    }

    const list = el("div", { className: "provider-model-import-list" });
    models.forEach((model, index) => {
      const checkbox = el("input", { type: "checkbox", value: String(index), checked: true });
      list.append(el("label", { className: "provider-model-import-item" }, [
        checkbox,
        el("span", { text: model.name || model.external_name }),
        el("small", { text: model.capability || "text_generation" })
      ]));
    });

    body.replaceChildren(
      el("div", { className: "provider-model-import-header" }, [
        el("strong", { text: "选择模型" }),
        el("span", { text: `${models.length} 个可导入模型` })
      ]),
      ...(hiddenNotice ? [hiddenNotice] : []),
      list,
      el("div", { className: "toolbar-actions" }, [
        button("全选", () => {
          list.querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.checked = true;
          });
        }, "secondary"),
        button("保存到模型管理", async () => {
          const selected = [...list.querySelectorAll('input[type="checkbox"]:checked')]
            .map((input) => models[Number(input.value)])
            .filter(Boolean);
          if (selected.length === 0) {
            setText(output, "请选择至少一个模型");
            output.hidden = false;
            return;
          }
          try {
            const saved = await api(`/admin-api/providers/${row.id}/models`, {
              method: "POST",
              body: JSON.stringify({ models: selected })
            });
            setOperationOutput(output, saved.data, "模型已保存到模型管理");
            await onSaved();
          } catch (error) {
            setErrorOutput(output, adminErrorMessage(error, "保存模型失败，请检查厂商配置"));
          }
        })
      ]),
      output
    );
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "拉取模型失败，请检查厂商基础地址和密钥"), "error"));
  }
}

export async function showModelProviderImport(onSaved) {
  const body = el("div", { className: "provider-model-import" }, [
    statusMessage("正在加载厂商列表...")
  ]);
  showModal("拉取模型", body);

  try {
    const result = await api("/admin-api/providers", { query: { limit: 500 } });
    const providers = (Array.isArray(result.data) ? result.data : []).filter((provider) => provider.id);
    if (providers.length === 0) {
      body.replaceChildren(statusMessage("请先添加可用厂商", "error"));
      return;
    }

    const selector = el("select", { className: "provider-model-import-provider", name: "provider_id" });
    providers.forEach((provider) => {
      const status = provider.status ? ` (${provider.status})` : "";
      selector.append(el("option", {
        value: String(provider.id),
        text: `${provider.name || provider.code || provider.id}${status}`
      }));
    });

    body.replaceChildren(
      el("label", { className: "field" }, [
        el("span", { text: "选择厂商" }),
        selector
      ]),
      el("div", { className: "toolbar-actions" }, [
        button("拉取模型", () => {
          const provider = providers.find((item) => String(item.id) === selector.value);
          if (provider) {
            showProviderModelImport(provider, onSaved);
          }
        })
      ])
    );
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "加载厂商失败"), "error"));
  }
}

export function renderProviderPresetPicker(form) {
  const nameInput = form.querySelector('[name="name"]');
  const protocolInput = form.querySelector('[name="protocol"]');
  const baseUrlInput = form.querySelector('[name="base_url"]');
  const editing = form.dataset.mode === "edit";
  const picker = el(editing ? "details" : "section", { className: "provider-preset-picker" }, [
    el(editing ? "summary" : "strong", { className: "provider-preset-title", text: editing ? "切换厂商预设" : "选择厂商，自动填写协议与地址" })
  ]);
  const common = el("div", { className: "provider-preset-grid" });
  const more = el("details", { className: "provider-preset-more" }, [el("summary", { text: "更多厂商" })]);
  const others = el("div", { className: "provider-preset-grid" });
  providerDisplayNamePresets.forEach((preset, index) => {
    const pick = button(preset.name, () => {
      if (nameInput) {
        nameInput.value = preset.name;
      }
      if (protocolInput) {
        protocolInput.value = preset.protocol;
      }
      if (baseUrlInput && preset.baseUrl) {
        baseUrlInput.value = preset.baseUrl;
      }
      nameInput?.dispatchEvent(new Event("input", { bubbles: true }));
      protocolInput?.dispatchEvent(new Event("change", { bubbles: true }));
      picker.querySelectorAll(".provider-preset-button").forEach((item) => item.setAttribute("aria-pressed", String(item === pick)));
    }, "secondary provider-preset-button", { "aria-pressed": "false" });
    (index < 8 ? common : others).append(pick);
  });
  more.append(others);
  picker.append(common, more);
  return picker;
}

export function providerSecretStatus(row) {
  if (row.secret_status === "configured") return "已配置";
  if (row.secret_status === "empty") return "未配置";
  return "状态未知";
}

export function bindProviderSecretVisibility(form) {
  const input = form.querySelector('[name="secret"]');
  if (!input) return;
  const wrapper = input.closest(".field");
  input.id = `${input.getAttribute("aria-describedby")}-control`;
  wrapper.htmlFor = input.id;
  input.setAttribute("aria-labelledby", wrapper.querySelector(".field-label").id);
  const hint = wrapper.querySelector(".field-hint");
  if (hint) {
    hint.id = `${input.id}-hint`;
    input.setAttribute("aria-describedby", `${input.getAttribute("aria-describedby")} ${hint.id}`);
  }
  input.spellcheck = false;
  input.autocapitalize = "off";
  const toggle = button("显示", () => {
    const visible = input.type === "password";
    input.type = visible ? "text" : "password";
    setText(toggle, visible ? "隐藏" : "显示");
    toggle.setAttribute("aria-label", visible ? "隐藏 API Key" : "显示 API Key");
    toggle.setAttribute("aria-pressed", String(visible));
  }, "secondary provider-secret-toggle", { "aria-label": "显示 API Key", "aria-pressed": "false" });
  wrapper.classList.add("provider-secret-field");
  input.after(toggle);
}

export function organizeProviderForm(form, row, { mode }) {
  form.classList.add("provider-config-form");
  const moveFields = (names) => names.map((name) => form.querySelector(`[data-field="${name}"]`)).filter(Boolean);
  const basic = el("fieldset", { className: "provider-form-section" }, [
    el("legend", { text: "接入信息" }),
    el("div", { className: "provider-form-grid" }, moveFields(["name", "protocol", "base_url"]))
  ]);
  const secret = form.querySelector('[name="secret"]');
  // A stored credential is never used as a form value, including older API payloads.
  if (secret) {
    secret.value = "";
    secret.placeholder = mode === "edit" ? "输入新 Key 可替换；留空保留原 Key" : "粘贴厂商提供的 API Key";
  }
  const credentials = el("fieldset", { className: "provider-form-section" }, [
    el("legend", { text: "访问凭证" }),
    ...(mode === "edit" ? [el("p", { className: "provider-secret-status", text: `当前 API Key：${providerSecretStatus(row)}。填写新 Key 后，保存即生效。` })] : []),
    ...moveFields(["secret"])
  ]);
  const advanced = el("details", { className: "provider-form-advanced" }, [
    el("summary", { text: "高级设置 · 超时、重试与请求头" }),
    el("div", { className: "provider-form-grid" }, moveFields(["timeout_ms", "retry_count", "default_headers"]))
  ]);
  const guide = el("p", { className: "provider-endpoint-guide", role: "status" });
  const updateGuide = () => {
    const url = form.querySelector('[name="base_url"]')?.value.trim() || "";
    if (/^https:\/\/token-plan\.maas\.qianwenaiapi\.com\//i.test(url)) {
      setText(guide, "千问 Token Plan：请使用套餐专属 API Key（sk-sp- 开头）。保存后可在列表中拉取模型。");
    } else if (/^https:\/\/maas\.qianwenaiapi\.com\//i.test(url)) {
      setText(guide, "千问按量计费：请使用按量计费 API Key，与 Token Plan 套餐 Key 分开配置。");
    } else {
      setText(guide, "填写厂商的 API 基础地址，无需追加 /chat/completions 等接口路径。保存后可拉取模型。");
    }
  };
  form.querySelector('[name="base_url"]')?.addEventListener("input", updateGuide);
  form.addEventListener("input", updateGuide);
  form.addEventListener("change", updateGuide);
  form.append(basic, credentials, guide, advanced);
  bindProviderSecretVisibility(form);
  updateGuide();
}

export function bindProviderBaseUrlDefaults(form) {
  const nameInput = form.querySelector('[name="name"]');
  const protocolInput = form.querySelector('[name="protocol"]');
  const baseUrlInput = form.querySelector('[name="base_url"]');
  if (!nameInput || !baseUrlInput) {
    return;
  }
  if (baseUrlInput.value.trim() !== "" && !providerLooksLikeUrl(baseUrlInput.value)) {
    baseUrlInput.value = "";
  }
  let lastSuggestedBaseUrl = providerBaseUrlForName(nameInput.value, protocolInput?.value) || "";
  const applyPreset = () => {
    const nextBaseUrl = providerBaseUrlForName(nameInput.value, protocolInput?.value);
    if (!nextBaseUrl) {
      return;
    }
    const currentBaseUrl = baseUrlInput.value.trim();
    if (currentBaseUrl === "" || currentBaseUrl === lastSuggestedBaseUrl || currentBaseUrl === nextBaseUrl) {
      baseUrlInput.value = nextBaseUrl;
      lastSuggestedBaseUrl = nextBaseUrl;
    }
  };
  nameInput.addEventListener("input", applyPreset);
  protocolInput?.addEventListener("change", applyPreset);
}

function providerLooksLikeUrl(value) {
  return /^https?:\/\//i.test(String(value || "").trim());
}

function providerBaseUrlForName(name, protocol = "openai") {
  const normalizedName = String(name || "").trim().toLowerCase();
  if (normalizedName === "") {
    return "";
  }
  const match = providerNameAliases.filter(([alias]) => normalizedName.includes(alias)).sort((a, b) => b[0].length - a[0].length)[0];
  const providerKey = match?.[1];
  if (!providerKey) {
    return "";
  }
  if (protocol === "anthropic" && providerAnthropicBaseUrlPresets[providerKey]) {
    return providerAnthropicBaseUrlPresets[providerKey];
  }
  return providerBaseUrlPresets[providerKey] || "";
}
