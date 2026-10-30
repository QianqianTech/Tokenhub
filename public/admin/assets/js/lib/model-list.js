// 模型管理 (design): provider status cards above the list, model name with
// its upstream name, context in K, price per thousand tokens and an
// enable switch that uses the batch endpoint.
import { adminErrorMessage, api } from "../api.js";
import { toast } from "../components/feedback.js";
import { el } from "../dom.js";
import { formatResourceValue, valueLabel } from "../resources.js";

const CURRENCY = { CNY: "¥", USD: "$", EUR: "€" };

function hostOf(url) {
  try {
    return new URL(String(url)).host;
  } catch {
    return String(url || "");
  }
}

/** Provider cards: status, endpoint, model count; clicking filters the list. */
export async function providerCards(state, onPick) {
  const host = el("section", { className: "provider-cards", "aria-label": "厂商状态" });
  let providers = [];
  try {
    const result = await api("/admin-api/providers", { query: { limit: 12, offset: 0 }, silent: true });
    providers = Array.isArray(result.data) ? result.data : [];
  } catch {
    return host;
  }
  if (!providers.length) {
    return host;
  }
  const counts = await Promise.all(providers.map((provider) => api("/admin-api/models", {
    query: { provider_id: provider.id, limit: 1, offset: 0, with_total: 1 },
    silent: true
  }).then((result) => Number(result.total), () => null)));
  const cards = providers.map((provider, index) => {
    const active = Number(state.filters.provider_id) === Number(provider.id);
    const disabled = provider.status !== "active";
    const card = el("button", {
      type: "button",
      className: `provider-card ${disabled ? "is-disabled" : ""} ${active ? "is-active" : ""}`.trim(),
      dataset: { providerId: String(provider.id) },
      "aria-pressed": active ? "true" : "false",
      title: active ? "再次点击取消按厂商筛选" : `只看${provider.name}的模型`,
      onClick: () => onPick(card.getAttribute("aria-pressed") === "true" ? "" : provider.id)
    }, [
      el("span", { className: "provider-card-head" }, [
        el("strong", { text: provider.name || `#${provider.id}` }),
        el("span", { className: `provider-card-status ${disabled ? "is-muted" : "is-positive"}`, text: disabled ? valueLabel("status", provider.status) : "启用" })
      ]),
      el("span", { className: "provider-card-endpoint", text: [hostOf(provider.base_url), formatResourceValue("protocol", provider.protocol)].filter(Boolean).join(" · ") }),
      el("span", { className: "provider-card-foot", text: Number.isFinite(counts[index]) ? `${counts[index]} 个模型` : "" })
    ]);
    return card;
  });
  host.append(...cards);
  return host;
}

/** Latest price row per model on this page (one request). */
export async function modelPrices(rows) {
  const ids = new Set(rows.map((row) => Number(row.id)));
  const byModel = new Map();
  if (!ids.size) {
    return byModel;
  }
  try {
    const result = await api("/admin-api/model-prices", { query: { limit: 100, offset: 0, sort: "effective_at_desc" }, silent: true });
    for (const price of Array.isArray(result.data) ? result.data : []) {
      const id = Number(price.model_id);
      if (ids.has(id) && !byModel.has(id)) {
        byModel.set(id, price);
      }
    }
  } catch {
    // Prices are optional decoration on this page.
  }
  return byModel;
}

function perThousand(value) {
  if (value === null || value === undefined || value === "") {
    return "–";
  }
  const number = Number(value) * 1000;
  if (!Number.isFinite(number)) {
    return "–";
  }
  return number >= 1 ? number.toFixed(2) : number.toPrecision(2).replace(/0+$/, "").replace(/\.$/, "");
}

export function priceCell(price) {
  if (!price) {
    return el("span", { className: "cell-muted", text: "未配置" });
  }
  const symbol = CURRENCY[String(price.currency || "CNY").toUpperCase()] || "";
  if (price.input_token_price != null || price.output_token_price != null) {
    return el("span", { className: "cell-mono", title: "每千 Token：输入 / 输出" }, [`${symbol}${perThousand(price.input_token_price)} / ${perThousand(price.output_token_price)}`]);
  }
  const unit = [
    ["image_price", "张"],
    ["video_second_price", "秒视频"],
    ["audio_second_price", "秒音频"],
    ["tts_char_price", "字"],
    ["request_price", "次"]
  ].find(([key]) => price[key] != null && price[key] !== "");
  return unit
    ? el("span", { className: "cell-mono", text: `${symbol}${Number(price[unit[0]])} / ${unit[1]}` })
    : el("span", { className: "cell-muted", text: "未配置" });
}

export function modelNameCell(row) {
  const parts = [el("span", { className: "cell-primary is-mono", text: row.name || `#${row.id}` })];
  if (row.external_name && row.external_name !== row.name) {
    parts.push(el("span", { className: "cell-secondary", text: row.external_name }));
  }
  return el("span", { className: "cell-stack" }, parts);
}

export function contextCell(value) {
  const number = Number(value);
  if (!Number.isFinite(number) || number <= 0) {
    return el("span", { className: "cell-muted", text: "–" });
  }
  const text = number >= 1_000_000 ? `${Math.round(number / 100_000) / 10}M` : number >= 1000 ? `${Math.round(number / 1000)}K` : String(number);
  return el("span", { className: "cell-mono", text, title: `${number.toLocaleString("zh-CN")} Token` });
}

/** Enable switch: flips the model through the batch endpoint. */
export function modelSwitch(row, onChanged) {
  const on = row.status === "active";
  const node = el("button", {
    type: "button",
    className: "switch",
    role: "switch",
    "aria-checked": on ? "true" : "false",
    "aria-label": `${on ? "停用" : "启用"}模型 ${row.name || row.id}`,
    title: on ? "已启用，点击停用" : "已停用，点击启用"
  });
  node.addEventListener("click", async () => {
    node.disabled = true;
    const next = node.getAttribute("aria-checked") !== "true";
    try {
      await api("/admin-api/models/batch", { method: "POST", body: JSON.stringify({ action: next ? "enable" : "disable", ids: [Number(row.id)] }) });
      node.setAttribute("aria-checked", next ? "true" : "false");
      toast(`${row.name || "模型"}已${next ? "启用" : "停用"}`, { tone: "success" });
      await onChanged();
    } catch (error) {
      toast(adminErrorMessage(error, "切换失败，请确认权限"), { tone: "error" });
    } finally {
      node.disabled = false;
    }
  });
  return node;
}
