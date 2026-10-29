import { adminErrorMessage, api, isAbortError } from "../api.js";
import { button, clear, el, field, readFields, setText, showModal, statusMessage } from "../dom.js";
import { SETTINGS_SECTION_PAGES } from "./settings-config.js";
import { errorState } from "../components/feedback.js";

const SYSTEM_SETTING_PRESENTATIONS = {
  rag_ai_image_processing: {
    title: "RAG 图片 AI 处理",
    description: "控制知识库图片的 AI 标注、视觉模型、提示词策略和失败重试。"
  },
  tls_certificates: {
    title: "证书与入口",
    description: "管理域名或内网 IP 证书、自动续期和到期告警。"
  },
  brand_identity: {
    title: "标题与标识",
    description: "设置管理后台和运营大屏的名称、Logo 与浏览器图标。"
  },
  "content_retention.policy": {
    title: "内容留存策略",
    description: "设置会话内容的保存方式、保存期限和查看权限。"
  }
};

const SYSTEM_SETTING_FIELD_LABELS = {
  ...Object.fromEntries(Object.values(SETTINGS_SECTION_PAGES).map((page) => [
    page.section,
    Object.fromEntries(page.fields.map((config) => [config.name, config.label]))
  ])),
  "content_retention.policy": {
    mode: "保存模式",
    days: "保存天数",
    demo_marker: "演示标记",
    default_days: "默认保存天数",
    max_days: "最大保存天数",
    raw_allowed_roles: "可查看原文的角色",
    departments: "部门策略",
    users: "用户策略",
    api_keys: "API 密钥策略"
  }
};

function isDemoSystemSetting(row) {
  const key = String(row?.setting_key || "").trim();
  return key.startsWith("demo-content-retention.");
}

function systemSettingPresentation(row) {
  const key = String(row?.setting_key || "").trim();
  const presentation = SYSTEM_SETTING_PRESENTATIONS[key];
  return {
    key,
    title: presentation?.title || key || "未命名设置",
    description: presentation?.description || row?.description || ""
  };
}

function systemSettingSummaryLabel(row, path) {
  return systemSettingFieldLabel(row, { path });
}

function systemSettingFieldLabel(row, descriptor) {
  const key = String(row?.setting_key || "").trim();
  const path = descriptor.path || [];
  const labels = SYSTEM_SETTING_FIELD_LABELS[key] || {};
  const readablePath = path.filter((segment) => !/^\d+$/.test(String(segment)));
  return readablePath.map((segment) => labels[segment] || segment).join(" / ") || "配置值";
}

export async function renderSystemSettingsPage(content) {
  clear(content);
  const page = el("div", { className: "system-settings-page" });
  const list = el("div", { className: "system-settings-list" });
  const refresh = async (expandedSettingId = null) => {
    try {
      const result = await api("/admin-api/system-settings", { query: { limit: 100 } });
      const rows = Array.isArray(result.data) ? result.data : [];
      const visibleRows = rows.filter((row) => !isDemoSystemSetting(row));
      clear(list);
      if (visibleRows.length === 0) {
        list.append(statusMessage("暂无高级设置，可新增一项自定义配置。"));
        return;
      }
      for (const row of visibleRows) {
        list.append(systemSettingCard(row, refresh, String(row.id) === String(expandedSettingId)));
      }
    } catch (error) {
      if (isAbortError(error)) {
        return;
      }
      list.replaceChildren(errorState(error, { fallback: "高级设置加载失败", onRetry: () => refresh(expandedSettingId) }));
    }
  };
  page.append(el("section", { className: "panel system-settings-panel" }, [
    el("header", { className: "table-toolbar" }, [
      el("div", {}, [
        el("h2", { text: "高级设置" }),
        el("p", { className: "notice", text: "集中查看和编辑系统功能配置。每项配置均可按字段直接修改；开关、数字和列表无需填写 JSON。" })
      ]),
      button("新增自定义配置", () => showCreateSystemSettingModal(refresh))
    ]),
    list
  ]));
  content.append(page);
  await refresh();
}

function systemSettingCard(row, onRefresh, isExpanded = false) {
  const presentation = systemSettingPresentation(row);
  const value = systemSettingStoredValue(row);
  const summary = systemSettingEditableFields(value)
    .slice(0, 4)
    .map((descriptor) => `${systemSettingSummaryLabel(row, descriptor.path)}：${systemSettingDisplayValue(descriptor.value)}`)
    .filter(Boolean);
  const accordion = el("div", { className: "system-setting-accordion", hidden: !isExpanded }, [
    summary.length > 0
      ? el("div", { className: "system-setting-summary" }, summary.map((item) => el("span", { text: item })))
      : el("p", { className: "notice", text: "此设置没有可编辑字段。" }),
    createSystemSettingEditor(row, () => onRefresh(row.id))
  ]);
  return el("article", { className: `system-setting-card${isExpanded ? " expanded" : ""}` }, [
    el("div", { className: "system-setting-card-heading" }, [
      el("div", {}, [
        el("strong", { text: presentation.title }),
        el("p", { className: "notice", text: presentation.description })
      ]),
      button(isExpanded ? "收起" : "展开", () => onRefresh(isExpanded ? null : row.id), "secondary")
    ]),
    accordion
  ]);
}

function systemSettingStoredValue(row) {
  const raw = row?.setting_value;
  if (raw && typeof raw === "object") {
    return cloneSystemSettingValue(raw);
  }
  try {
    return JSON.parse(String(raw || "{}"));
  } catch {
    return { value: String(raw || "") };
  }
}

function systemSettingEditableFields(value, path = [], fields = []) {
  if (Array.isArray(value)) {
    if (value.every((item) => item === null || ["string", "number", "boolean"].includes(typeof item))) {
      fields.push({ name: systemSettingFieldName(path), path, kind: "list", itemType: typeof value.find((item) => item !== null) || "string", value });
    }
    return fields;
  }
  if (value && typeof value === "object") {
    for (const [key, child] of Object.entries(value)) {
      systemSettingEditableFields(child, [...path, key], fields);
    }
    return fields;
  }
  const kind = typeof value === "boolean" ? "boolean" : typeof value === "number" ? "number" : "text";
  fields.push({ name: systemSettingFieldName(path), path, kind, value });
  return fields;
}

function systemSettingFieldName(path) {
  return `system_setting_${path.length ? path.join("_") : "value"}`;
}

function systemSettingFieldConfig(row, descriptor) {
  const label = systemSettingFieldLabel(row, descriptor);
  if (descriptor.kind === "boolean") {
    return { name: descriptor.name, label, type: "select", options: [["true", "启用"], ["false", "停用"]] };
  }
  if (descriptor.kind === "number") {
    return { name: descriptor.name, label, type: "number" };
  }
  if (descriptor.kind === "list") {
    return { name: descriptor.name, label: `${label}（每行一项）`, type: "textarea", rows: 4 };
  }
  return { name: descriptor.name, label };
}

function systemSettingFormValue(descriptor) {
  if (descriptor.kind === "list") {
    return descriptor.value.map((item) => item == null ? "" : String(item)).join("\n");
  }
  if (descriptor.kind === "boolean") {
    return descriptor.value ? "true" : "false";
  }
  return descriptor.value == null ? "" : descriptor.value;
}

function systemSettingDisplayValue(value) {
  if (Array.isArray(value)) {
    return value.join("、");
  }
  if (typeof value === "boolean") {
    return value ? "已启用" : "已停用";
  }
  return value == null ? "未设置" : String(value);
}

function createSystemSettingEditor(row, onSaved) {
  const storedValue = systemSettingStoredValue(row);
  const descriptors = systemSettingEditableFields(storedValue);
  const form = el("form", { className: "edit-form system-setting-editor", onSubmit: (event) => event.preventDefault() });
  const baseFields = [
    { name: "setting_key", label: "配置标识" },
    { name: "description", label: "说明", optional: true }
  ];
  for (const config of baseFields) {
    form.append(field(config, row[config.name] || ""));
  }
  for (const descriptor of descriptors) {
    form.append(field(systemSettingFieldConfig(row, descriptor), systemSettingFormValue(descriptor)));
  }
  if (descriptors.length === 0) {
    form.append(statusMessage("此配置包含的复杂数据保持不变；请通过对应的系统设置分区进行管理。"));
  }
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("保存", async () => {
    try {
      const values = readFields(form, baseFields);
      let settingValue = cloneSystemSettingValue(storedValue);
      for (const descriptor of descriptors) {
        const control = form.elements.namedItem(descriptor.name);
        settingValue = setSystemSettingFieldValue(settingValue, descriptor.path, systemSettingControlValue(descriptor, control?.value || ""));
      }
      await api(`/admin-api/system-settings/${row.id}`, {
        method: "PUT",
        body: JSON.stringify({
          setting_key: values.setting_key,
          description: values.description || "",
          setting_value: JSON.stringify(settingValue)
        })
      });
      setText(output, "保存成功");
      output.hidden = false;
      await onSaved();
    } catch (error) {
      setText(output, adminErrorMessage(error, "保存失败，请检查输入内容"));
      output.hidden = false;
    }
  }));
  form.append(output);
  return form;
}


function cloneSystemSettingValue(value) {
  if (Array.isArray(value)) {
    return value.map(cloneSystemSettingValue);
  }
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([key, child]) => [key, cloneSystemSettingValue(child)]));
  }
  return value;
}

function systemSettingControlValue(descriptor, raw) {
  if (descriptor.kind === "boolean") {
    return raw === "true";
  }
  if (descriptor.kind === "number") {
    const value = Number(raw);
    return Number.isFinite(value) ? value : 0;
  }
  if (descriptor.kind === "list") {
    return raw.split(/\r?\n/).map((item) => item.trim()).filter(Boolean).map((item) => {
      if (descriptor.itemType === "number") return Number(item);
      if (descriptor.itemType === "boolean") return item === "true" || item === "1" || item === "是";
      return item;
    });
  }
  return raw;
}

function setSystemSettingFieldValue(value, path, nextValue) {
  if (path.length === 0) {
    return nextValue;
  }
  const root = cloneSystemSettingValue(value);
  let target = root;
  for (const key of path.slice(0, -1)) {
    target[key] ??= {};
    target = target[key];
  }
  target[path[path.length - 1]] = nextValue;
  return root;
}

function showCreateSystemSettingModal(onSaved) {
  const fields = [
    { name: "setting_key", label: "配置标识" },
    { name: "description", label: "说明", optional: true },
    { name: "field_name", label: "首个配置项名称" },
    { name: "field_type", label: "配置项类型", type: "select", options: [["text", "文本"], ["number", "数字"], ["boolean", "开关"], ["list", "列表（每行一项）"]] },
    { name: "field_value", label: "初始值（列表每行一项）", type: "textarea", rows: 4, optional: true }
  ];
  const form = el("form", { className: "edit-form system-setting-editor", onSubmit: (event) => event.preventDefault() });
  for (const config of fields) {
    form.append(field(config));
  }
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("新增", async () => {
    try {
      const values = readFields(form, fields);
      const value = systemSettingNewValue(values.field_type, values.field_value || "");
      await api("/admin-api/system-settings", {
        method: "POST",
        body: JSON.stringify({
          setting_key: values.setting_key,
          description: values.description || "",
          setting_value: JSON.stringify({ [values.field_name]: value })
        })
      });
      setText(output, "新增成功");
      output.hidden = false;
      await onSaved();
    } catch (error) {
      setText(output, adminErrorMessage(error, "新增失败，请检查输入内容"));
      output.hidden = false;
    }
  }));
  form.append(output);
  showModal("新增自定义配置", form);
}

function systemSettingNewValue(type, raw) {
  if (type === "number") {
    const value = Number(raw);
    return Number.isFinite(value) ? value : 0;
  }
  if (type === "boolean") {
    return raw === "true" || raw === "1" || raw === "是" || raw === "启用";
  }
  if (type === "list") {
    return raw.split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
  }
  return raw;
}
