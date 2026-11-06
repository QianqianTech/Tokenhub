import { localize } from "../i18n.js";
import { adminErrorMessage, api } from "../api.js";
import { copyButton, toast } from "../components/feedback.js";
import { button, el, field, readFields, showModal } from "../dom.js";
import { clearLookupCache } from "./lookups.js";
import { oneTimeSecretText, setErrorOutput, setOperationOutput } from "./output.js";
import { lookupAwareFields, quotaTargetRef } from "./references.js";
import { bindProviderBaseUrlDefaults, bindProviderSecretVisibility, organizeProviderForm, providerSecretStatus, renderProviderPresetPicker } from "../pages/catalog.js";
import { createApiKeyManagementScopeEditor } from "./api-key-tools.js";
import { createModelCapabilityEditor } from "./capability-editor.js";

// "用户管理" → "用户": dialog titles name the record, not the page.
function recordNoun(label = "") {
  return String(label).replace(/(管理|配置|列表)$/, "") || label;
}

// Lookup types whose cached labels change when a resource is written.
const RESOURCE_LOOKUP = {
  users: "users",
  departments: "departments",
  "api-keys": "api-keys",
  models: "models",
  providers: "providers",
  "quota-policies": "quota-policies",
  "rag-knowledge-bases": "rag-knowledge-bases",
  "rag-vector-datasets": "rag-vector-datasets",
  "rag-vector-configs": "rag-vector-configs"
};

function invalidateLookups(resourceKey) {
  if (RESOURCE_LOOKUP[resourceKey]) {
    clearLookupCache(RESOURCE_LOOKUP[resourceKey]);
  }
}

function submitButton(label, handler, variant = "") {
  // type=submit so Enter in a field submits; resourceForm() prevents the navigation.
  return button(label, handler, variant, { type: "submit" });
}

export async function showCreateModal(resourceKey, config, onSaved, initialValues = {}) {
  const formFields = await lookupAwareFields(config.form);
  const form = resourceForm(formFields, initialValues, resourceKey, { mode: "create" });
  const output = el(config.oneTimeSecret ? "div" : "p", { className: `notice ${config.oneTimeSecret ? "secret-output" : ""}`.trim(), hidden: true, role: "status" });
  appendVectorConfigProbe(resourceKey, form, formFields, output);
  const scopeEditor = resourceKey === "api-keys" ? createApiKeyManagementScopeEditor([]) : null;
  if (scopeEditor) {
    form.append(scopeEditor.node);
  }
  let modal = null;
  form.append(el("div", { className: "form-actions" }, [
    submitButton(config.oneTimeSecret ? (config.createLabel || localize("创建并显示一次明文")) : localize("保存"), async () => {
      let values;
      try {
        values = readFields(form, formFields, { validate: true, mode: "create" });
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, error.message));
        return;
      }
      try {
        const payload = resourcePayload(resourceKey, values);
        if (scopeEditor && scopeEditor.selected().length > 0) {
          payload.management_scopes = scopeEditor.selected();
        }
        const result = await api(`/admin-api/${resourceKey}`, {
          method: "POST",
          body: JSON.stringify(payload)
        });
        invalidateLookups(resourceKey);
        if (config.oneTimeSecret) {
          output.replaceChildren(
            el("strong", { text: localize("请立即复制并妥善保存，关闭后无法再次查看：") }),
            ...oneTimeSecretEntries(resourceKey, result.data || {}).map(([label, value, copyLabel]) => el("div", { className: "secret-entry" }, [
              label ? el("span", { className: "secret-label", text: label }) : null,
              el("code", { className: "secret-value", text: value }),
              copyButton(value, copyLabel)
            ].filter(Boolean)))
          );
          output.className = "notice secret-output";
          output.hidden = false;
          modal?.markClean();
          form.querySelectorAll("input, select, textarea, button[type=submit]").forEach((control) => {
            control.disabled = true;
          });
        } else {
          toast(localize`${config.label}已新增`, { tone: "success" });
          modal?.markClean();
          await modal?.close({ force: true });
        }
        await onSaved();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, localize("新增失败，请检查输入内容")));
      }
    })
  ]), output);
  modal = showModal(localize`新增${recordNoun(config.label)}`, form);
}

export async function showEditModal(resourceKey, config, row, onSaved) {
  const formFields = await lookupAwareFields(config.form);
  const formValues = resourceKey === "rag-knowledge-bases" ? knowledgeBaseFormValues(row) : resourceKey === "rag-vector-configs" ? vectorConfigFormValues(row) : row;
  const form = resourceForm(formFields, formValues, resourceKey, { mode: "edit" });
  const output = el("p", { className: "notice", hidden: true, role: "status" });
  appendVectorConfigProbe(resourceKey, form, formFields, output);
  const capabilityEditor = resourceKey === "models" ? createModelCapabilityEditor(row) : null;
  if (capabilityEditor) {
    form.append(capabilityEditor.node);
  }
  const scopeEditor = resourceKey === "api-keys" ? createApiKeyManagementScopeEditor(row.management_scopes, { editing: true }) : null;
  if (scopeEditor) {
    form.append(scopeEditor.node);
  }
  let modal = null;
  form.append(el("div", { className: "form-actions" }, [
    submitButton(localize("保存"), async () => {
      let values;
      try {
        values = readFields(form, formFields, { validate: true, mode: "edit" });
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, error.message));
        return;
      }
      if (resourceKey === "api-keys") {
        // Saved atomically with the key attributes; an empty list removes all models.
        values.model_ids = Array.isArray(values.model_ids) ? values.model_ids.filter((id) => Number.isFinite(id) && id > 0) : [];
      }
      try {
        const payload = resourcePayload(resourceKey, values, row);
        if (capabilityEditor) {
          Object.assign(payload, capabilityEditor.payload());
        }
        if (scopeEditor?.changed()) {
          payload.management_scopes = scopeEditor.selected();
        }
        const result = await api(`/admin-api/${resourceKey}/${row.id}`, {
          method: "PUT",
          body: JSON.stringify(payload)
        });
        capabilityEditor?.commit(result.data || {});
        scopeEditor?.commit(Array.isArray(result.data?.management_scopes) ? result.data.management_scopes : scopeEditor.selected());
        invalidateLookups(resourceKey);
        toast(localize`${config.label}已保存`, { tone: "success" });
        modal?.markClean();
        await modal?.close({ force: true });
        await onSaved();
      } catch (error) {
        setErrorOutput(output, error.userMessage || adminErrorMessage(error, localize("保存失败，请检查输入内容")));
      }
    })
  ]), output);
  modal = showModal(localize`编辑${recordNoun(config.label)}`, form);
}

export function showProviderSecretModal(row, onSaved) {
  const fields = [{ name: "secret", label: localize("新 API Key"), type: "password", required: true, placeholder: localize("粘贴新的完整 API Key"), hint: localize("保存后立即替换当前 Key；原 Key 不会回显。") }];
  const form = resourceForm(fields, {}, "", { mode: "edit" });
  form.classList.add("provider-key-form");
  form.prepend(el("p", { className: "provider-secret-status", text: localize`${row.name || localize`厂商 #${row.id}`} · 当前 API Key：${providerSecretStatus(row)}` }));
  bindProviderSecretVisibility(form);
  const output = el("p", { className: "notice", hidden: true, role: "status" });
  let modal;
  form.append(el("div", { className: "form-actions" }, [
    submitButton(localize("保存 Key"), async () => {
      try {
        const payload = readFields(form, fields, { validate: true, mode: "edit" });
        await api(`/admin-api/providers/${row.id}`, { method: "PUT", body: JSON.stringify(payload) });
        form.querySelector('[name="secret"]').value = "";
        toast(localize("厂商 API Key 已更新"), { tone: "success" });
        modal.markClean();
        await modal.close({ force: true });
        await onSaved();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, error.code === "FORM_INVALID" ? error.message : localize("Key 保存失败，请重试")));
      }
    })
  ]), output);
  modal = showModal(row.secret_status === "empty" ? localize("配置厂商 API Key") : localize("修改厂商 API Key"), form, { size: "sm" });
}

function appendVectorConfigProbe(resourceKey, form, fields, output) {
  if (resourceKey !== "rag-vector-configs") {
    return;
  }
  form.append(button(localize("测试向量模型"), async () => {
    try {
      const result = await api("/admin-api/rag-vector-configs/test", {
        method: "POST",
        body: JSON.stringify(resourcePayload(resourceKey, readFields(form, fields, { validate: true })))
      });
      const probe = result.data || {};
      if (probe.dimension_matches === false) {
        setErrorOutput(output, localize`模型可用，但实际维度是 ${probe.dimension}，与填写的 ${probe.configured_dimension || "-"} 不一致，请把“向量维度”改为 ${probe.dimension}`);
        return;
      }
      setOperationOutput(output, probe, localize`测试成功：${probe.model_name || localize("向量模型")}，维度 ${probe.dimension || "-"}`);
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, error.code === "FORM_INVALID" ? error.message : localize("向量模型测试失败")));
    }
  }, "secondary"));
}

// What a create response reveals once: the API key, or for a screen share the
// login, password and link (ScreenService::createShareLink).
export function oneTimeSecretEntries(resourceKey, data = {}) {
  if (resourceKey === "dashboard-share-links") {
    const url = data.share_url ? new URL(String(data.share_url), window.location.origin).href : "";
    return [
      data.username ? [localize("登录账号"), String(data.username), localize("复制账号")] : null,
      data.password ? [localize("访问密码"), String(data.password), localize("复制密码")] : null,
      url ? [localize("分享链接"), url, localize("复制链接")] : null
    ].filter(Boolean);
  }
  return [["", oneTimeSecretText(data), undefined]];
}

// Knowledge base: structured fields <-> chunking_profile.redaction and
// retrieval_profile (RagRedactionService, RagRetrievalProfileService,
// RagRerankService). Keys the form does not know are kept as they were.
const KB_RETRIEVAL_KEYS = ["min_vector_score", "min_lexical_score", "min_hybrid_confidence", "exact_match_discount"];
const KB_FORM_KEYS = ["redaction_mode", "redaction_types", ...KB_RETRIEVAL_KEYS, "rerank_model_id", "rerank_top_n", "rerank_min_score", "query_rewrite_model_id", "query_rewrite_mode"];

function jsonObject(value) {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    return value;
  }
  if (typeof value === "string" && value.trim() !== "") {
    try {
      const parsed = JSON.parse(value);
      return parsed && typeof parsed === "object" && !Array.isArray(parsed) ? parsed : {};
    } catch {
      return {};
    }
  }
  return {};
}

// Vector config fields kept in config_json (RagMultimodalEmbedding, RagVectorConfigService).
const VECTOR_CONFIG_JSON_KEYS = ["embedding_api", "min_vector_score"];

export function vectorConfigFormValues(row = {}) {
  const settings = jsonObject(row.config_json);
  const values = { ...row };
  values.embedding_api = settings.embedding_api || "text";
  if (settings.min_vector_score !== undefined && settings.min_vector_score !== null) values.min_vector_score = settings.min_vector_score;
  return values;
}

function vectorConfigPayload(values, row = {}) {
  const payload = Object.fromEntries(Object.entries(values).filter(([key]) => !VECTOR_CONFIG_JSON_KEYS.includes(key)));
  const settings = { ...jsonObject(row.config_json), ...jsonObject(values.config_json) };
  if (values.embedding_api && values.embedding_api !== "text") settings.embedding_api = values.embedding_api;
  else delete settings.embedding_api;
  if (values.min_vector_score === undefined || values.min_vector_score === null || values.min_vector_score === "") delete settings.min_vector_score;
  else settings.min_vector_score = Number(values.min_vector_score);
  if (Object.keys(settings).length > 0) payload.config_json = settings;
  else if (Object.keys(jsonObject(row.config_json)).length > 0) payload.config_json = {};
  else delete payload.config_json;
  return payload;
}

export function knowledgeBaseFormValues(row = {}) {
  const chunking = jsonObject(row.chunking_profile);
  const retrieval = jsonObject(row.retrieval_profile);
  const values = { ...row };
  if (Object.hasOwn(chunking, "redaction")) {
    const redaction = chunking.redaction;
    const types = Array.isArray(redaction) ? redaction : Array.isArray(redaction?.types) ? redaction.types : [];
    values.redaction_mode = redaction === false || redaction?.enabled === false ? "off" : "custom";
    values.redaction_types = types;
  }
  for (const key of KB_RETRIEVAL_KEYS) {
    if (retrieval[key] !== undefined && retrieval[key] !== null) values[key] = retrieval[key];
  }
  const rerank = jsonObject(retrieval.rerank);
  if (rerank.model_id) {
    values.rerank_model_id = rerank.model_id;
    if (rerank.top_n !== undefined) values.rerank_top_n = rerank.top_n;
    if (rerank.min_score !== undefined) values.rerank_min_score = rerank.min_score;
  }
  const rewrite = jsonObject(retrieval.query_rewrite);
  if (rewrite.model_id) {
    values.query_rewrite_model_id = rewrite.model_id;
    values.query_rewrite_mode = rewrite.mode || "auto";
  }
  return values;
}

function knowledgeBasePayload(values, row = {}) {
  const payload = Object.fromEntries(Object.entries(values).filter(([key]) => !KB_FORM_KEYS.includes(key)));
  const chunking = { ...jsonObject(row.chunking_profile) };
  const mode = values.redaction_mode || "";
  if (mode === "off") {
    chunking.redaction = false;
  } else if (mode === "custom") {
    chunking.redaction = { types: Array.isArray(values.redaction_types) ? values.redaction_types : [] };
  } else {
    delete chunking.redaction;
  }
  const retrieval = { ...jsonObject(row.retrieval_profile) };
  for (const key of KB_RETRIEVAL_KEYS) {
    if (values[key] === undefined || values[key] === null || values[key] === "") delete retrieval[key];
    else retrieval[key] = Number(values[key]);
  }
  if (values.rerank_model_id) {
    retrieval.rerank = {
      ...jsonObject(retrieval.rerank),
      enabled: true,
      model_id: Number(values.rerank_model_id),
      ...(values.rerank_top_n ? { top_n: Number(values.rerank_top_n) } : {}),
      ...(values.rerank_min_score !== undefined && values.rerank_min_score !== null ? { min_score: Number(values.rerank_min_score) } : {})
    };
  } else {
    delete retrieval.rerank;
  }
  if (values.query_rewrite_model_id) {
    retrieval.query_rewrite = {
      ...jsonObject(retrieval.query_rewrite),
      enabled: true,
      model_id: Number(values.query_rewrite_model_id),
      mode: values.query_rewrite_mode === "always" ? "always" : "auto"
    };
  } else {
    delete retrieval.query_rewrite;
  }
  const hadChunking = Object.keys(jsonObject(row.chunking_profile)).length > 0;
  const hadRetrieval = Object.keys(jsonObject(row.retrieval_profile)).length > 0;
  if (Object.keys(chunking).length > 0 || hadChunking) payload.chunking_profile = chunking;
  if (Object.keys(retrieval).length > 0 || hadRetrieval) payload.retrieval_profile = retrieval;
  return payload;
}

export function resourcePayload(resourceKey, values, row = {}) {
  if (resourceKey === "rag-knowledge-bases") {
    return knowledgeBasePayload(values, row);
  }
  if (resourceKey === "rag-vector-configs") {
    return vectorConfigPayload(values, row);
  }
  if (resourceKey === "dashboard-share-links") {
    // A global share has no scope objects; unselected metrics mean "all".
    return {
      ...values,
      scope_ids: values.scope_type === "global" ? [] : (values.scope_ids || []),
      allowed_metrics: values.allowed_metrics || []
    };
  }
  if (resourceKey !== "quota-bindings" || !values.target_ref) {
    return values;
  }
  const [targetType, rawTargetId] = String(values.target_ref).split(":");
  return {
    ...values,
    target_type: targetType,
    target_id: Number(rawTargetId),
    target_ref: undefined
  };
}

export function resourceForm(fields, row = {}, resourceKey = "", { mode = "edit" } = {}) {
  const form = el("form", { className: "edit-form", dataset: { mode }, noValidate: true, onSubmit: (event) => event.preventDefault() });
  for (const config of fields) {
    let value = row[config.name] ?? "";
    if (resourceKey === "quota-bindings" && config.name === "target_ref") {
      value = quotaTargetRef(row);
    }
    if (config.type === "cidr-list" && value) {
      if (typeof value === "string") {
        const trimmed = value.trim();
        if (trimmed.startsWith("[")) {
          try {
            const parsed = JSON.parse(trimmed);
            value = Array.isArray(parsed) ? parsed.join("\n") : String(value);
          } catch (_error) {
            // keep raw string when not valid JSON
          }
        }
      } else {
        value = Array.isArray(value) ? value.join("\n") : String(value);
      }
    } else if (config.type === "json" && value && typeof value !== "string") {
      value = JSON.stringify(value, null, 2);
    }
    form.append(field(config, value, { mode }));
  }
  if (resourceKey === "providers") {
    form.prepend(renderProviderPresetPicker(form));
    bindProviderBaseUrlDefaults(form);
    organizeProviderForm(form, row, { mode });
  }
  if (resourceKey === "api-keys") {
    bindApiKeyDepartmentUserPicker(form);
  }
  if (resourceKey === "dashboard-share-links") {
    bindShareScopePicker(form, fields, row);
  }
  return form;
}

// The scope objects picker follows the scope type: departments, users or
// API keys; a global share has no objects.
const SHARE_SCOPE_LOOKUPS = { department: ["departments", localize("部门")], user: ["users", localize("用户")], api_key: ["api-keys", localize("API 密钥")] };

function bindShareScopePicker(form, fields, row) {
  const typeSelect = form.querySelector('select[name="scope_type"]');
  const config = fields.find((item) => item.scopeOf === "scope_type");
  if (!typeSelect || !config) {
    return;
  }
  const render = (scopeType, value) => {
    const current = form.querySelector(`[data-field="${config.name}"]`);
    const lookup = SHARE_SCOPE_LOOKUPS[scopeType];
    const next = field({ ...config, lookup: lookup?.[0] || "departments", label: lookup ? localize`范围对象（${lookup[1]}）` : config.label }, value, { mode: "edit" });
    next.hidden = !lookup;
    current?.replaceWith(next);
  };
  const initial = Array.isArray(row?.scope_ids) ? row.scope_ids : (() => {
    try { return JSON.parse(row?.scope_ids || "[]"); } catch { return []; }
  })();
  render(typeSelect.value, Array.isArray(initial) ? initial.join(",") : "");
  typeSelect.addEventListener("change", () => render(typeSelect.value, ""));
}

// The employee picker only lists users of the selected department.
function bindApiKeyDepartmentUserPicker(form) {
  const departmentSelect = form.querySelector('select[name="department_id"]');
  const userPicker = form.querySelector('[data-remote-select="user_id"]');
  if (!departmentSelect || !userPicker) {
    return;
  }
  const sync = (clearValue) => {
    const departmentId = departmentSelect.value;
    userPicker.setQuery(departmentId ? { department_id: departmentId } : {});
    userPicker.setDisabled(departmentId === "");
    userPicker.input.placeholder = departmentId === "" ? localize("请先选择部门") : localize("搜索该部门的员工");
    if (clearValue) {
      userPicker.setValue("");
    }
  };
  departmentSelect.addEventListener("change", () => sync(true));
  sync(false);
}

