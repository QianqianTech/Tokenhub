import { localize } from "../i18n.js";
// Model capability contract editor (admin layer of ModelCapabilityContract).
import { el, setText } from "../dom.js";
import { valueLabel } from "../resources.js";
import { parseJsonValue } from "./ops-common.js";

// --- 5. Model capability contract editor -----------------------------------

const MODEL_CAPABILITY_FEATURES = [
  ["tools", localize("工具调用")],
  ["parallel_tool_calls", localize("并行工具调用")],
  ["json_object", localize("JSON 对象输出")],
  ["json_schema", localize("JSON Schema 结构化输出")],
  ["reasoning", localize("推理")],
  ["streaming", localize("流式输出")]
];

const MODEL_CAPABILITY_MODALITIES = {
  input_modalities: { label: localize("输入模态"), options: [["text", localize("文本")], ["image", localize("图片")], ["audio", localize("音频")], ["file", localize("文件")]] },
  output_modalities: { label: localize("输出模态"), options: [["text", localize("文本")], ["image", localize("图片")], ["audio", localize("音频")], ["embedding", localize("向量")]] }
};

const MODEL_CAPABILITY_LIMITS = [
  ["context_length", localize("上下文长度")],
  ["max_output_tokens", localize("最大输出 Token")]
];

const MODEL_CAPABILITY_TAGS = ["text_generation", "vision_understanding", "embedding", "asr", "tts", "image_generation", "video_generation", "rerank", "moderation"];

function plainObject(value) {
  return value && typeof value === "object" && !Array.isArray(value) ? value : {};
}

function capabilityFeatureText(value) {
  if (value === true) return localize("支持");
  if (value === false) return localize("不支持");
  return localize("未声明");
}

function capabilityListText(value, options) {
  if (!Array.isArray(value)) {
    return localize("未声明");
  }
  const labels = new Map(options);
  return value.length === 0 ? localize("无") : value.map((item) => labels.get(item) || item).join("、");
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
  const node = el("fieldset", { className: "model-capability-editor" }, [el("legend", { text: localize("能力契约") })]);
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
    node.append(el("p", { className: "muted", text: localize("生效值 = 管理员覆盖 > 已验证 > 上游目录。上游与已验证层由目录同步和验收测试维护，此处只读；这里只修改管理员覆盖层。") }));
    legacyWarning = el("p", { className: "notice error", hidden: true, text: localize("该模型目前没有能力契约（旧版模型，仅按“能力”字段路由）。保存管理员覆盖后将启用能力契约：未声明为“支持”的功能（包括流式输出）将被视为不支持。") });
    node.append(legacyWarning);

    const featureTable = el("table", { className: "resource-table model-capability-table" });
    featureTable.append(el("thead", {}, [el("tr", {}, [localize("功能"), localize("上游"), localize("已验证"), localize("管理员覆盖"), localize("生效")].map((label) => el("th", { text: label })))]));
    const featureBody = el("tbody");
    for (const [feature, label] of MODEL_CAPABILITY_FEATURES) {
      const select = el("select", { name: `capability_feature_${feature}`, className: "model-capability-override", dataset: { feature } }, [
        el("option", { value: "", text: localize("继承") }),
        el("option", { value: "true", text: localize("支持") }),
        el("option", { value: "false", text: localize("不支持") })
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
          el("span", { className: "muted", text: localize`上游：${capabilityListText(upstream[key], config.options)}；已验证：${capabilityListText(verified[key], config.options)}` })
        ]),
        el("label", { className: "model-capability-override-toggle" }, [override, el("span", { text: localize("管理员覆盖（整组替换）") })]),
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
        placeholder: lower ? localize`继承：${lower}` : localize("继承（未声明）"),
        value: admin[key] !== undefined && admin[key] !== null ? String(admin[key]) : ""
      });
      controls.limits[key] = input;
      limitRow.append(el("label", { className: "field" }, [el("span", { text: localize`${label}覆盖（留空继承）` }), input]));
    }
    node.append(limitRow);
  }

  if (hasTags) {
    const currentTags = new Set(Array.isArray(baseline.tags) ? baseline.tags.map(String) : []);
    tagInputs = MODEL_CAPABILITY_TAGS.map((tag) => el("input", { type: "checkbox", name: "capability_tags", value: tag, checked: currentTags.has(tag) }));
    node.append(el("div", { className: "model-capability-tags" }, [
      el("strong", { text: localize("附加能力标签（路由匹配）") }),
      el("span", { className: "muted", text: localize("模型也会响应这些能力的请求。注意：应用厂商目录同步时会按上游重置该标签。") }),
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
        error.userMessage = localize`${MODEL_CAPABILITY_LIMITS.find(([name]) => name === key)?.[1] || key}覆盖需为正整数`;
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
      setText(control.effective, localize`生效：${capabilityListText(value, MODEL_CAPABILITY_MODALITIES[key].options)}`);
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
