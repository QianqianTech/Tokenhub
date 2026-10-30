// Knowledge-set access control page (RAG-013).
// Rendered as a tab of “构建知识” (pages/rag.js) and as a standalone page (core/registry.js).
import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, statusMessage } from "../dom.js";

const PERMISSIONS = [["read", "只读（检索）"], ["write", "可写（上传/更正）"], ["admin", "管理（授权/配置）"]];
const SUBJECTS = [["department", "部门"], ["user", "用户"], ["api_key", "API Key"]];
const VISIBILITY = [["private", "私有：仅授权对象"], ["department", "部门：所属部门及下级部门可读"], ["shared", "共享：全组织可读"]];
const ACL_MODES = [["inherit", "继承知识库授权（可追加）"], ["custom", "自定义：只按本数据集授权"]];

async function rows(path) {
  const result = await api(path, { query: { limit: 500 } }).catch(() => ({ data: [] }));
  return Array.isArray(result.data) ? result.data : (Array.isArray(result.data?.items) ? result.data.items : []);
}

function flattenDepartments(nodes, depth = 0, out = []) {
  for (const node of nodes || []) {
    out.push([String(node.id), `${"　".repeat(depth)}${node.name}`]);
    flattenDepartments(node.children, depth + 1, out);
  }
  return out;
}

function select(name, options, value, attrs = {}) {
  const control = el("select", { name, ...attrs });
  for (const [optionValue, label] of options) {
    control.append(el("option", { value: optionValue, text: label, selected: String(optionValue) === String(value ?? "") }));
  }
  return control;
}

function toLocalInput(value) {
  if (!value) return "";
  const date = new Date(String(value).replace(" ", "T") + "Z");
  if (Number.isNaN(date.getTime())) return "";
  const pad = (n) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fromLocalInput(value) {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function grantEditor(grants, lookups, { withPriority = false } = {}) {
  const body = el("tbody");
  const wrap = el("div", { className: "resource-table-wrap" }, [
    el("table", { className: "resource-table rag-access-grants" }, [
      el("thead", {}, el("tr", {}, [
        el("th", { text: "对象类型" }), el("th", { text: "对象" }), el("th", { text: "权限" }),
        el("th", { text: "含下级部门" }), el("th", { text: "默认知识集" }),
        withPriority ? el("th", { text: "优先级" }) : null,
        el("th", { text: "到期时间" }), el("th", { text: "" })
      ]))
    , body])
  ]);

  const subjectOptions = (type) => lookups[type] || [];
  const addRow = (grant = { subject_type: "department", permission: "read", include_descendants: true, editable: true }) => {
    const editable = grant.editable !== false;
    const row = el("tr", { className: grant.expired ? "muted" : "" });
    const type = select("subject_type", SUBJECTS, grant.subject_type, { disabled: !editable });
    const subject = select("subject_id", subjectOptions(grant.subject_type), grant.subject_id, { disabled: !editable });
    if (grant.subject_id && !subjectOptions(grant.subject_type).some(([id]) => String(id) === String(grant.subject_id))) {
      subject.append(el("option", { value: String(grant.subject_id), text: grant.subject_name || `#${grant.subject_id}`, selected: true }));
    }
    const descendants = el("input", { type: "checkbox", name: "include_descendants", checked: grant.include_descendants !== false, disabled: !editable || grant.subject_type !== "department" });
    type.addEventListener("change", () => {
      clear(subject);
      for (const [id, label] of subjectOptions(type.value)) subject.append(el("option", { value: id, text: label }));
      descendants.disabled = type.value !== "department";
    });
    row.dataset.editable = editable ? "1" : "0";
    row.append(...[
      el("td", {}, type),
      el("td", {}, subject),
      el("td", {}, select("permission", PERMISSIONS, grant.permission, { disabled: !editable })),
      el("td", {}, descendants),
      el("td", {}, el("input", { type: "checkbox", name: "is_default", checked: Boolean(grant.is_default), disabled: !editable })),
      withPriority ? el("td", {}, el("input", { type: "number", name: "priority", min: "0", value: String(grant.priority ?? 100), disabled: !editable, style: "width:6em" })) : null,
      el("td", {}, [
        el("input", { type: "datetime-local", name: "expires_at", value: toLocalInput(grant.expires_at), disabled: !editable }),
        grant.expired ? el("span", { className: "muted", text: " 已过期" }) : null
      ]),
      el("td", {}, editable ? button("移除", () => row.remove(), "ghost") : el("span", { className: "muted", text: "超出管理范围" }))
    ].filter(Boolean));
    body.append(row);
  };
  for (const grant of grants || []) addRow(grant);

  const read = () => [...body.querySelectorAll("tr")]
    .filter((row) => row.dataset.editable === "1")
    .map((row) => {
      const value = (name) => row.querySelector(`[name="${name}"]`);
      const grant = {
        subject_type: value("subject_type").value,
        subject_id: Number(value("subject_id").value),
        permission: value("permission").value,
        include_descendants: value("subject_type").value === "department" && value("include_descendants").checked,
        is_default: value("is_default").checked,
        expires_at: fromLocalInput(value("expires_at").value)
      };
      if (withPriority) grant.priority = Number(value("priority").value || 100);
      return grant;
    })
    .filter((grant) => grant.subject_id > 0);

  return { node: el("div", {}, [wrap, el("div", { className: "toolbar-actions" }, [button("添加授权", () => addRow(), "secondary")])]), read };
}

async function renderAccessCheck(panel, lookups) {
  const result = el("div");
  const type = select("check_type", [["user", "用户"], ["api_key", "API Key"]], "user");
  const subject = select("check_subject", lookups.user, "");
  type.addEventListener("change", () => {
    clear(subject);
    for (const [id, label] of lookups[type.value]) subject.append(el("option", { value: id, text: label }));
  });
  const run = async () => {
    clear(result).append(statusMessage("正在计算有效权限…"));
    try {
      const query = type.value === "user" ? { user_id: subject.value } : { api_key_id: subject.value };
      const { data } = await api("/admin-api/rag/access-check", { query });
      const chain = (data.department_chain || []).map((d) => d.name).join(" › ") || "无";
      clear(result).append(
        el("p", { className: "muted", text: `部门链：${chain}${data.restricted ? "；该 Key 有显式授权，处于限制模式（只看 Key 自身的授权）" : ""}` }),
        data.knowledge_bases.length === 0 ? el("p", { text: "没有可访问的知识集。" }) : el("table", { className: "resource-table" }, [
          el("thead", {}, el("tr", {}, [el("th", { text: "知识库" }), el("th", { text: "可见性" }), el("th", { text: "知识库权限" }), el("th", { text: "可访问数据集" })])),
          el("tbody", {}, data.knowledge_bases.map((kb) => el("tr", {}, [
            el("td", { text: `${kb.name}（${kb.kb_id}）` }),
            el("td", { text: kb.visibility }),
            el("td", { text: kb.permission || "仅部分数据集" }),
            el("td", { text: kb.datasets.map((ds) => `${ds.name}:${ds.permission}`).join("，") || "—" })
          ])))
        ])
      );
    } catch (error) {
      clear(result).append(statusMessage(adminErrorMessage(error), "error"));
    }
  };
  panel.append(el("h3", { text: "有效权限检查" }), el("div", { className: "toolbar-actions" }, [type, subject, button("检查", run, "primary")]), result);
}

export async function renderRagAccessPage(content, initialFilters = {}) {
  clear(content);
  const status = el("div");
  const panel = el("section", { className: "panel rag-access" });
  content.append(panel);
  panel.append(el("h2", { text: "知识集权限" }), el("p", { className: "muted", text: "按部门、用户或 API Key 授权。部门授权默认对下级部门生效；API Key 一旦有显式授权，就只能访问这些授权。" }), status);

  const [kbs, tree, users, keys] = await Promise.all([
    rows("/admin-api/rag-knowledge-bases"),
    api("/admin-api/departments/tree").then((r) => r.data || []).catch(() => []),
    rows("/admin-api/users"),
    rows("/admin-api/api-keys")
  ]);
  const lookups = {
    department: flattenDepartments(Array.isArray(tree) ? tree : (tree.items || [])),
    user: users.map((u) => [String(u.id), u.username || `#${u.id}`]),
    api_key: keys.map((k) => [String(k.id), `${k.name || "key"} #${k.id}`])
  };
  if (kbs.length === 0) {
    panel.append(el("p", { text: "当前管理范围内没有知识库。" }));
    await renderAccessCheck(panel, lookups);
    return;
  }
  const kbSelect = select("kb", kbs.map((kb) => [String(kb.id), `${kb.name}（${kb.kb_id}）`]), initialFilters.kb_id || kbs[0].id);
  const editor = el("div");
  panel.append(el("div", { className: "toolbar-actions" }, [el("span", { text: "知识库：" }), kbSelect]), editor);

  const load = async () => {
    clear(editor).append(statusMessage("正在加载…"));
    try {
      const { data } = await api(`/admin-api/rag/knowledge-bases/${kbSelect.value}/acl`);
      clear(editor);
      const kb = data.knowledge_base;
      const visibility = select("visibility", VISIBILITY, kb.visibility, { disabled: !kb.can_manage });
      const kbGrants = grantEditor(data.grants, lookups);
      const saveKb = async () => {
        try {
          await api(`/admin-api/rag/knowledge-bases/${kb.id}/acl`, { method: "PUT", body: JSON.stringify({ visibility: visibility.value, grants: kbGrants.read() }) });
          clear(status).append(statusMessage("知识库授权已保存", "success"));
          await load();
        } catch (error) {
          clear(status).append(statusMessage(adminErrorMessage(error), "error"));
        }
      };
      editor.append(
        el("h3", { text: `知识库授权 · 所属部门：${kb.department_name || "未设置"}` }),
        el("div", { className: "toolbar-actions" }, [el("span", { text: "可见性：" }), visibility]),
        kbGrants.node,
        kb.can_manage ? el("div", { className: "toolbar-actions" }, [button("保存知识库授权", saveKb, "primary")]) : el("p", { className: "muted", text: "该知识库不在你的管理范围内，只能查看。" })
      );
      for (const dataset of data.datasets) {
        const mode = select("acl_mode", ACL_MODES, dataset.acl_mode, { disabled: !kb.can_manage });
        const dsGrants = grantEditor(dataset.grants, lookups, { withPriority: true });
        const saveDs = async () => {
          try {
            await api(`/admin-api/rag/datasets/${dataset.id}/acl`, { method: "PUT", body: JSON.stringify({ acl_mode: mode.value, grants: dsGrants.read() }) });
            clear(status).append(statusMessage(`数据集「${dataset.name}」授权已保存`, "success"));
            await load();
          } catch (error) {
            clear(status).append(statusMessage(adminErrorMessage(error), "error"));
          }
        };
        editor.append(el("details", { className: "rag-access-dataset", open: dataset.acl_mode === "custom" }, [
          el("summary", { text: `数据集：${dataset.name}（${dataset.dataset_id}） · ${dataset.acl_mode === "custom" ? "自定义" : "继承"}` }),
          el("div", { className: "toolbar-actions" }, [el("span", { text: "授权方式：" }), mode]),
          dsGrants.node,
          kb.can_manage ? el("div", { className: "toolbar-actions" }, [button("保存数据集授权", saveDs, "primary")]) : null
        ]));
      }
    } catch (error) {
      clear(editor).append(statusMessage(adminErrorMessage(error), "error"));
    }
  };
  kbSelect.addEventListener("change", load);
  await load();
  await renderAccessCheck(panel, lookups);
}
