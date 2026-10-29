import { adminErrorMessage, api } from "../api.js";
import { copyButton, toast } from "../components/feedback.js";
import { button, el, field, readFields, showModal } from "../dom.js";
import { clearLookupCache } from "./lookups.js";
import { oneTimeSecretText, setErrorOutput, setOperationOutput } from "./output.js";
import { lookupAwareFields, quotaTargetRef } from "./references.js";
import { bindProviderBaseUrlDefaults, renderProviderPresetPicker } from "../pages/catalog.js";
import { createApiKeyManagementScopeEditor, createModelCapabilityEditor } from "./ops-tools.js";

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
  const output = el("p", { className: `notice ${config.oneTimeSecret ? "secret-output" : ""}`.trim(), hidden: true, role: "status" });
  appendVectorConfigProbe(resourceKey, form, formFields, output);
  const scopeEditor = resourceKey === "api-keys" ? createApiKeyManagementScopeEditor([]) : null;
  if (scopeEditor) {
    form.append(scopeEditor.node);
  }
  let modal = null;
  form.append(el("div", { className: "form-actions" }, [
    submitButton(config.oneTimeSecret ? "创建并显示一次明文" : "保存", async () => {
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
          const secret = oneTimeSecretText(result.data);
          output.replaceChildren(
            el("strong", { text: "请立即复制并妥善保存，关闭后无法再次查看：" }),
            el("code", { className: "secret-value", text: secret }),
            copyButton(secret)
          );
          output.className = "notice secret-output";
          output.hidden = false;
          modal?.markClean();
          form.querySelectorAll("input, select, textarea, button[type=submit]").forEach((control) => {
            control.disabled = true;
          });
        } else {
          toast(`${config.label}已新增`, { tone: "success" });
          modal?.markClean();
          await modal?.close({ force: true });
        }
        await onSaved();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, "新增失败，请检查输入内容"));
      }
    })
  ]), output);
  modal = showModal(`新增${recordNoun(config.label)}`, form);
}

export async function showEditModal(resourceKey, config, row, onSaved) {
  const formFields = await lookupAwareFields(config.form);
  const form = resourceForm(formFields, row, resourceKey, { mode: "edit" });
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
    submitButton("保存", async () => {
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
        const payload = resourcePayload(resourceKey, values);
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
        toast(`${config.label}已保存`, { tone: "success" });
        modal?.markClean();
        await modal?.close({ force: true });
        await onSaved();
      } catch (error) {
        setErrorOutput(output, error.userMessage || adminErrorMessage(error, "保存失败，请检查输入内容"));
      }
    })
  ]), output);
  modal = showModal(`编辑${recordNoun(config.label)}`, form);
}

function appendVectorConfigProbe(resourceKey, form, fields, output) {
  if (resourceKey !== "rag-vector-configs") {
    return;
  }
  form.append(button("测试向量模型", async () => {
    try {
      const result = await api("/admin-api/rag-vector-configs/test", {
        method: "POST",
        body: JSON.stringify(resourcePayload(resourceKey, readFields(form, fields, { validate: true })))
      });
      const probe = result.data || {};
      setOperationOutput(output, probe, `测试成功：${probe.model_name || "向量模型"}，维度 ${probe.dimension || "-"}`);
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, error.code === "FORM_INVALID" ? error.message : "向量模型测试失败"));
    }
  }, "secondary"));
}

export function resourcePayload(resourceKey, values) {
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
  const form = el("form", { className: "edit-form", noValidate: true, onSubmit: (event) => event.preventDefault() });
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
const SHARE_SCOPE_LOOKUPS = { department: ["departments", "部门"], user: ["users", "用户"], api_key: ["api-keys", "API 密钥"] };

function bindShareScopePicker(form, fields, row) {
  const typeSelect = form.querySelector('select[name="scope_type"]');
  const config = fields.find((item) => item.scopeOf === "scope_type");
  if (!typeSelect || !config) {
    return;
  }
  const render = (scopeType, value) => {
    const current = form.querySelector(`[data-field="${config.name}"]`);
    const lookup = SHARE_SCOPE_LOOKUPS[scopeType];
    const next = field({ ...config, lookup: lookup?.[0] || "departments", label: lookup ? `范围对象（${lookup[1]}）` : config.label }, value, { mode: "edit" });
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
    userPicker.input.placeholder = departmentId === "" ? "请先选择部门" : "搜索该部门的员工";
    if (clearValue) {
      userPicker.setValue("");
    }
  };
  departmentSelect.addEventListener("change", () => sync(true));
  sync(false);
}

