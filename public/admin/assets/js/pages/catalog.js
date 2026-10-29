import { adminErrorMessage, api } from "../api.js";
import { button, el, setText, showModal, statusMessage } from "../dom.js";
import { setErrorOutput, setOperationOutput } from "../lib/output.js";
import { columnLabel } from "../resources.js";
import { providerCatalogModels } from "../lib/ops-tools.js";

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
  "qwen": "https://dashscope.aliyuncs.com/compatible-mode/v1",
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
  "deepseek": "https://api.deepseek.com/anthropic"
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
  ["qwen", "qwen"],
  ["通义", "qwen"],
  ["dashscope", "qwen"],
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
  { name: "黔前智算", protocol: "openai", baseUrl: providerBaseUrlPresets.qianqianai },
  { name: "DeepSeek", protocol: "openai", baseUrl: providerBaseUrlPresets.deepseek },
  { name: "火山引擎", protocol: "openai", baseUrl: providerBaseUrlPresets.volcengine },
  { name: "腾讯云", protocol: "openai", baseUrl: providerBaseUrlPresets.tencent },
  { name: "Kimi API / Moonshot", protocol: "openai", baseUrl: providerBaseUrlPresets.moonshot },
  { name: "Kimi Code", protocol: "openai", baseUrl: providerBaseUrlPresets.kimi_code },
  { name: "MiniMax（海螺视频/生图/语音）", protocol: "openai", baseUrl: providerBaseUrlPresets.minimax },
  { name: "阿里云百炼原生（万相/CosyVoice）", protocol: "openai", baseUrl: providerBaseUrlPresets.bailian },
  { name: "Google Gemini 原生", protocol: "gemini", baseUrl: providerBaseUrlPresets.gemini_native },
  { name: "可灵 Kling", protocol: "openai", baseUrl: providerBaseUrlPresets.kling },
  { name: "生数 Vidu", protocol: "openai", baseUrl: providerBaseUrlPresets.vidu },
  { name: "xAI Grok", protocol: "openai", baseUrl: providerBaseUrlPresets.xai },
  { name: "OpenRouter", protocol: "openai", baseUrl: providerBaseUrlPresets.openrouter },
  { name: "FLUX（Black Forest Labs）", protocol: "openai", baseUrl: providerBaseUrlPresets.bfl },
  { name: "fal.ai", protocol: "openai", baseUrl: providerBaseUrlPresets.fal },
  { name: "ElevenLabs", protocol: "openai", baseUrl: providerBaseUrlPresets.elevenlabs },
  { name: "兼容 OpenAI", protocol: "openai", baseUrl: providerBaseUrlPresets.openai }
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
  const picker = el("section", { className: "provider-preset-picker" }, [
    el("span", { className: "provider-preset-title", text: "常用厂商" })
  ]);
  for (const preset of providerDisplayNamePresets) {
    picker.append(button(preset.name, () => {
      if (nameInput) {
        nameInput.value = preset.name;
      }
      if (protocolInput) {
        protocolInput.value = preset.protocol;
      }
      if (baseUrlInput) {
        baseUrlInput.value = "";
      }
      nameInput?.dispatchEvent(new Event("input", { bubbles: true }));
      // The picked preset wins over the name-alias guess (e.g. Gemini native vs OpenAI-compatible).
      if (baseUrlInput && preset.baseUrl) {
        baseUrlInput.value = preset.baseUrl;
      }
    }, "secondary provider-preset-button"));
  }
  return picker;
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
    if (currentBaseUrl === "" || currentBaseUrl === lastSuggestedBaseUrl) {
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
  const match = providerNameAliases.find(([alias]) => normalizedName.includes(alias));
  const providerKey = match?.[1];
  if (!providerKey) {
    return "";
  }
  if (protocol === "anthropic" && providerAnthropicBaseUrlPresets[providerKey]) {
    return providerAnthropicBaseUrlPresets[providerKey];
  }
  return providerBaseUrlPresets[providerKey] || "";
}
