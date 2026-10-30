import { pageHead } from "../lib/resource-table.js";
import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, readFields, showModal, statusMessage } from "../dom.js";
import { resourceForm } from "../lib/forms.js";
import { setErrorOutput, setOperationOutput } from "../lib/output.js";
import { departmentSelectOptions } from "../lib/references.js";
import { adminPermissionOptions, valueLabel } from "../resources.js";

export async function renderPermissionConfigPage(content) {
  clear(content);
  const result = await api("/admin-api/admin-users", { query: { limit: 100 } });
  const administrators = Array.isArray(result.data) ? result.data : [];
  const presets = [
    ["普通管理员", "全部业务能力，不包含管理员账号管理", "全局"],
    ["用户管理员", "用户、部门、API Key、配额、用量与内容", "授权部门"],
    ["RAG 管理员", "知识库、数据集、上传、向量化与评测", "授权部门"],
    ["API 管理员", "厂商、模型、价格、API Key、配额与用量", "全局"],
    ["自定义管理员", "仅使用单独勾选的权限", "可选"],
  ];
  const presetPanel = el("section", { className: "panel" }, [
    el("h3", { className: "panel-title", text: "管理员类型与默认能力" }),
    el("table", { className: "resource-table" }, [
      el("thead", {}, [el("tr", {}, [el("th", { text: "管理员类型" }), el("th", { text: "默认能力" }), el("th", { text: "默认范围" })])]),
      el("tbody", {}, presets.map(([name, abilities, scope]) => el("tr", {}, [el("td", { text: name }), el("td", { text: abilities }), el("td", { text: scope })])))
    ])
  ]);
  const capabilityPanel = el("section", { className: "panel" }, [
    el("h3", { className: "panel-title", text: "可授权权限点" }),
    el("div", { className: "permission-config-options" }, adminPermissionOptions.map(([code, label]) => el("p", { text: `${label}（${code}）` })))
  ]);
  const administratorsPanel = el("section", { className: "panel" }, [
    el("h3", { className: "panel-title", text: "管理员权限分配" }),
    administrators.length
      ? el("div", { className: "permission-config-admin-list" }, administrators.map((administrator) => el("article", { className: "permission-config-admin" }, [
        el("h3", { text: administrator.username || `管理员 #${administrator.id}` }),
        el("p", { text: `${valueLabel("admin_type", administrator.admin_type) || administrator.admin_type || "未设置"} / ${valueLabel("admin_scope", administrator.admin_scope) || administrator.admin_scope || "未设置"}` }),
        el("p", { text: `有效权限：${(administrator.permissions || []).join("、") || "无"}` }),
        button("配置权限", () => showPermissionConfigEditor(administrator, () => renderPermissionConfigPage(content)), "secondary")
      ])))
      : statusMessage("暂无管理员账号")
  ]);
  content.append(pageHead("权限配置", "预设类型决定默认能力；勾选单独权限后将覆盖该账号的预设。管理员账号操作始终仅限超级管理员。"), presetPanel, administratorsPanel, capabilityPanel);
}

async function showPermissionConfigEditor(administrator, onSaved) {
  const fields = [
    { name: "admin_type", label: "管理员类型", type: "select", options: [["super_admin", "超级管理员"], ["full_admin", "普通管理员"], ["user_admin", "用户管理员"], ["rag_admin", "RAG 管理员"], ["api_admin", "API 管理员"], ["custom", "自定义管理员"]] },
    { name: "admin_scope", label: "数据范围", type: "select", options: [["global", "全局"], ["departments", "授权部门及子部门"]] },
    { name: "permissions", label: "单独授权（勾选后覆盖预设）", type: "multiselect", options: adminPermissionOptions, optional: true, size: 12 },
    { name: "managed_department_ids", label: "授权管理部门", type: "multiselect", coerce: "number", options: await departmentSelectOptions(), optional: true, size: 8 }
  ];
  const form = resourceForm(fields, administrator, "permission-config");
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("保存权限配置", async () => {
    try {
      const result = await api(`/admin-api/admin-users/${administrator.id}`, {
        method: "PUT",
        body: JSON.stringify(readFields(form, fields))
      });
      setOperationOutput(output, result.data, "权限配置已保存，原登录会话已撤销");
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "权限配置保存失败"));
    }
  }));
  form.append(output);
  showModal(`权限配置 · ${administrator.username || `管理员 #${administrator.id}`}`, form);
}
