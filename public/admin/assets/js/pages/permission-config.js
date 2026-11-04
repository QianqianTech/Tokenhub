import { pageHead } from "../lib/resource-table.js";
import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, field, showModal, statusMessage } from "../dom.js";
import { setErrorOutput } from "../lib/output.js";
import { toast } from "../components/feedback.js";
import { departmentSelectOptions } from "../lib/references.js";
import { adminPermissionOptions, valueLabel } from "../resources.js";

// Capability codes are "<module>.<action>". The page groups them by module so
// that 28 raw codes read as a 14-row matrix instead of a wall of text.
const MODULES = [
  ["dashboard", "仪表盘"],
  ["departments", "部门"],
  ["users", "用户"],
  ["api_catalog", "厂商与模型"],
  ["api_keys", "API 密钥"],
  ["quotas", "配额"],
  ["usage", "用量"],
  ["content", "内容留存"],
  ["rag", "RAG 知识库"],
  ["operations", "运营"],
  ["security", "安全"],
  ["logs", "日志"],
  ["system", "系统设置"],
  ["admins", "管理员账号"]
];
const ACTIONS = [["read", "查看"], ["manage", "管理"], ["export", "导出"]];
const ALL_CODES = adminPermissionOptions.map(([code]) => code);
const CODE_LABELS = new Map(adminPermissionOptions);
const BUSINESS_CODES = ALL_CODES.filter((code) => code !== "admins.manage");

// Mirrors PermissionService::presetPermissions() on the server.
const ADMIN_TYPES = [
  { key: "full_admin", label: "普通管理员", scope: "全局", summary: "全部业务能力，不含管理员账号管理", codes: BUSINESS_CODES },
  {
    key: "user_admin", label: "用户管理员", scope: "授权部门", summary: "用户、部门、API 密钥、配额、用量与内容",
    codes: ["dashboard.read", "departments.read", "departments.manage", "users.read", "users.manage", "api_keys.read", "api_keys.manage", "quotas.read", "quotas.manage", "usage.read", "content.read", "content.export"]
  },
  { key: "rag_admin", label: "RAG 管理员", scope: "授权部门", summary: "知识库、数据集、上传、向量化与评测", codes: ["dashboard.read", "rag.read", "rag.manage", "usage.read"] },
  {
    key: "api_admin", label: "API 管理员", scope: "全局", summary: "厂商、模型、价格、API 密钥、配额与用量",
    codes: ["dashboard.read", "api_catalog.read", "api_catalog.manage", "api_keys.read", "api_keys.manage", "quotas.read", "quotas.manage", "usage.read"]
  },
  { key: "custom", label: "自定义管理员", scope: "可选", summary: "不带预设，仅使用单独勾选的权限", codes: [] }
];
const TYPE_BY_KEY = new Map(ADMIN_TYPES.map((type) => [type.key, type]));

const moduleCodes = (moduleKey) => ACTIONS
  .map(([action]) => `${moduleKey}.${action}`)
  .filter((code) => CODE_LABELS.has(code));

function moduleLevel(moduleKey, codes) {
  const owned = new Set(codes);
  if (owned.has(`${moduleKey}.manage`)) return "manage";
  if (owned.has(`${moduleKey}.read`) || owned.has(`${moduleKey}.export`)) return "read";
  return "";
}

function levelCell(level, extra = "") {
  if (!level) {
    return el("span", { className: "perm-level is-none", text: "—", title: "无权限" });
  }
  const text = level === "manage" ? "管理" : "查看";
  return el("span", { className: `perm-level is-${level}`, text: extra ? `${text}·${extra}` : text });
}

function isSuperAdmin(administrator) {
  return administrator.role === "super_admin" || administrator.admin_type === "super_admin";
}

function typeLabel(administrator) {
  if (isSuperAdmin(administrator)) return "超级管理员";
  return TYPE_BY_KEY.get(administrator.admin_type)?.label || valueLabel("admin_type", administrator.admin_type) || "未设置";
}

function scopeLabel(administrator) {
  if (isSuperAdmin(administrator) || administrator.admin_scope !== "departments") return "全局";
  const count = Array.isArray(administrator.managed_department_ids) ? administrator.managed_department_ids.length : 0;
  return count > 0 ? `授权部门 · ${count} 个` : "授权部门";
}

function effectiveCodes(administrator) {
  if (isSuperAdmin(administrator)) return ALL_CODES;
  return Array.isArray(administrator.permissions) ? administrator.permissions.filter((code) => CODE_LABELS.has(code)) : [];
}

export async function renderPermissionConfigPage(content) {
  clear(content);
  const result = await api("/admin-api/admin-users", { query: { limit: 100 } });
  const administrators = Array.isArray(result.data) ? result.data : [];
  const rerender = () => renderPermissionConfigPage(content);

  content.append(
    pageHead("权限配置", "管理员类型决定默认能力；切换为自定义后只使用单独勾选的权限。管理员账号操作始终仅限超级管理员。"),
    renderAdministrators(administrators, rerender),
    renderPresetMatrix()
  );
}

function renderAdministrators(administrators, onSaved) {
  const panel = el("section", { className: "panel permission-admins" }, [
    el("header", { className: "permission-panel-head" }, [
      el("div", {}, [
        el("h3", { className: "panel-title", text: "管理员权限分配" }),
        el("p", { className: "permission-panel-note", text: `共 ${administrators.length} 个管理员账号。点“配置权限”调整类型、数据范围或单独授权；保存后该账号需要重新登录。` })
      ]),
      el("a", { className: "button-link secondary", href: "#/organization/admin-users", text: "管理员管理" })
    ])
  ]);
  if (!administrators.length) {
    panel.append(statusMessage("暂无管理员账号"));
    return panel;
  }
  const rows = administrators.map((administrator) => {
    const codes = effectiveCodes(administrator);
    const superAdmin = isSuperAdmin(administrator);
    const custom = administrator.admin_permission_mode === "custom" && !superAdmin;
    const modules = MODULES.filter(([key]) => moduleLevel(key, codes));
    const visible = modules.slice(0, 6);
    const name = administrator.username || `管理员 #${administrator.id}`;
    return el("tr", {}, [
      el("td", {}, [el("div", { className: "permission-admin-cell" }, [
        el("span", { className: "permission-admin-avatar", "aria-hidden": "true", text: name.slice(0, 1).toUpperCase() }),
        el("span", { className: "permission-admin-name", text: name })
      ])]),
      el("td", {}, [
        el("span", { className: `permission-type-badge ${superAdmin ? "is-super" : ""}`.trim(), text: typeLabel(administrator) }),
        custom ? el("span", { className: "permission-mode-tag", text: "已自定义", title: "单独勾选的权限覆盖了类型预设" }) : null
      ]),
      el("td", { className: "permission-scope-cell", text: scopeLabel(administrator) }),
      el("td", {}, [
        el("div", { className: "permission-summary" }, [
          el("span", { className: "permission-count", text: superAdmin ? "全部权限" : `${codes.length} / ${ALL_CODES.length} 项` }),
          superAdmin ? null : el("span", { className: "permission-chip-row" }, [
            ...visible.map(([key, label]) => el("span", {
              className: `permission-chip is-${moduleLevel(key, codes)}`,
              text: label,
              title: moduleCodes(key).filter((code) => codes.includes(code)).map((code) => CODE_LABELS.get(code)).join("、")
            })),
            modules.length > visible.length ? el("span", { className: "permission-chip is-more", text: `+${modules.length - visible.length}`, title: modules.slice(visible.length).map(([, label]) => label).join("、") }) : null
          ])
        ])
      ]),
      el("td", { className: "permission-actions-cell" }, [
        superAdmin
          ? el("span", { className: "permission-locked", text: "不可修改", title: "超级管理员始终拥有全部权限" })
          : button("配置权限", () => void showPermissionConfigEditor(administrator, onSaved), "secondary")
      ])
    ]);
  });
  panel.append(el("div", { className: "table-wrap permission-table-wrap" }, [
    el("table", { className: "resource-table permission-admin-table" }, [
      el("thead", {}, [el("tr", {}, ["管理员", "类型", "数据范围", "有效权限", ""].map((text) => el("th", { text, scope: "col" })))]),
      el("tbody", {}, rows)
    ])
  ]));
  panel.append(el("p", { className: "permission-legend" }, [
    el("span", { className: "permission-chip is-manage", text: "管理" }), el("span", { text: "可查看并修改" }),
    el("span", { className: "permission-chip is-read", text: "查看" }), el("span", { text: "只读（或可导出）" })
  ]));
  return panel;
}

function renderPresetMatrix() {
  const head = el("tr", {}, [
    el("th", { scope: "col", text: "功能模块" }),
    ...ADMIN_TYPES.filter((type) => type.key !== "custom").map((type) => el("th", { scope: "col", className: "perm-matrix-type" }, [
      el("span", { className: "perm-matrix-type-name", text: type.label }),
      el("span", { className: "perm-matrix-type-scope", text: `范围：${type.scope}` })
    ])),
    el("th", { scope: "col", text: "权限点" })
  ]);
  const body = MODULES.map(([key, label]) => el("tr", {}, [
    el("th", { scope: "row", className: "perm-matrix-module", text: label }),
    ...ADMIN_TYPES.filter((type) => type.key !== "custom").map((type) => {
      const level = moduleLevel(key, type.codes);
      const exports = type.codes.includes(`${key}.export`) && level ? "导出" : "";
      return el("td", { className: "perm-matrix-cell" }, [levelCell(level, exports)]);
    }),
    el("td", { className: "perm-matrix-codes" }, moduleCodes(key).map((code) => el("code", { text: code, title: CODE_LABELS.get(code) })))
  ]));
  return el("section", { className: "panel permission-matrix-panel" }, [
    el("header", { className: "permission-panel-head" }, [
      el("div", {}, [
        el("h3", { className: "panel-title", text: "管理员类型默认权限对照" }),
        el("p", { className: "permission-panel-note", text: "「自定义管理员」不带预设，只使用单独勾选的权限；「授权部门」范围包含其子部门。" })
      ])
    ]),
    el("div", { className: "permission-type-cards" }, ADMIN_TYPES.map((type) => el("article", { className: "permission-type-card" }, [
      el("div", { className: "permission-type-card-head" }, [
        el("strong", { text: type.label }),
        el("span", { className: "permission-scope-tag", text: type.scope })
      ]),
      el("p", { text: type.summary }),
      el("span", { className: "permission-type-card-count", text: type.codes.length ? `${type.codes.length} 项权限` : "按需勾选" })
    ]))),
    el("div", { className: "table-wrap permission-table-wrap" }, [
      el("table", { className: "resource-table perm-matrix" }, [el("thead", {}, [head]), el("tbody", {}, body)])
    ])
  ]);
}

async function showPermissionConfigEditor(administrator, onSaved) {
  const departmentOptions = await departmentSelectOptions();
  const initialType = TYPE_BY_KEY.has(administrator.admin_type) ? administrator.admin_type : "full_admin";
  const state = {
    type: initialType,
    custom: administrator.admin_permission_mode === "custom" || initialType === "custom",
    codes: new Set(effectiveCodes(administrator))
  };

  const typeField = field({ name: "admin_type", label: "管理员类型", type: "select", options: ADMIN_TYPES.map((type) => [type.key, type.label]) }, initialType);
  const typeSelect = typeField.querySelector("select");
  typeSelect.value = initialType;
  const scopeField = field({ name: "admin_scope", label: "数据范围", type: "select", options: [["global", "全局"], ["departments", "授权部门及子部门"]] }, administrator.admin_scope || "global");
  const scopeSelect = scopeField.querySelector("select");
  scopeSelect.value = administrator.admin_scope === "departments" ? "departments" : "global";
  const departmentField = field({ name: "managed_department_ids", label: "授权管理部门", type: "multiselect", options: departmentOptions, optional: true, size: 6 }, (administrator.managed_department_ids || []).map(String));

  const modePreset = el("input", { type: "radio", name: "permission_mode", value: "preset", checked: !state.custom });
  const modeCustom = el("input", { type: "radio", name: "permission_mode", value: "custom", checked: state.custom });
  const modeGroup = el("div", { className: "permission-mode-switch", role: "radiogroup", "aria-label": "权限来源" }, [
    el("label", { className: "permission-mode-option" }, [modePreset, el("span", {}, [el("strong", { text: "使用类型预设" }), el("small", { text: "权限随管理员类型变化" })])]),
    el("label", { className: "permission-mode-option" }, [modeCustom, el("span", {}, [el("strong", { text: "自定义勾选" }), el("small", { text: "只使用下方勾选的权限" })])])
  ]);

  const checkboxes = new Map();
  const matrix = el("table", { className: "permission-edit-matrix" }, [
    el("thead", {}, [el("tr", {}, [el("th", { scope: "col", text: "功能模块" }), ...ACTIONS.map(([, label]) => el("th", { scope: "col", text: label }))])]),
    el("tbody", {}, MODULES.map(([key, label]) => el("tr", {}, [
      el("th", { scope: "row", text: label }),
      ...ACTIONS.map(([action, actionLabel]) => {
        const code = `${key}.${action}`;
        if (!CODE_LABELS.has(code)) {
          return el("td", { className: "is-empty" });
        }
        const input = el("input", { type: "checkbox", name: "permissions", value: code, "aria-label": `${label}${actionLabel}（${code}）` });
        checkboxes.set(code, input);
        return el("td", {}, [el("label", { className: "permission-edit-check", title: `${CODE_LABELS.get(code)}（${code}）` }, [input])]);
      })
    ])))
  ]);
  const matrixNote = el("p", { className: "permission-edit-note" });

  const sync = () => {
    state.type = typeSelect.value;
    state.custom = modeCustom.checked || state.type === "custom";
    if (state.type === "custom") {
      modeCustom.checked = true;
    }
    modePreset.disabled = state.type === "custom";
    const preset = new Set(TYPE_BY_KEY.get(state.type)?.codes || []);
    for (const [code, input] of checkboxes) {
      input.disabled = !state.custom;
      input.checked = state.custom ? state.codes.has(code) : preset.has(code);
    }
    matrix.classList.toggle("is-readonly", !state.custom);
    matrixNote.textContent = state.custom
      ? `已勾选 ${state.codes.size} 项。保存后只使用这些权限。`
      : `当前为「${TYPE_BY_KEY.get(state.type)?.label}」预设的 ${preset.size} 项权限（只读）。如需调整，请选择“自定义勾选”。`;
    departmentField.hidden = scopeSelect.value !== "departments";
  };
  for (const [code, input] of checkboxes) {
    input.addEventListener("change", () => {
      if (input.checked) state.codes.add(code); else state.codes.delete(code);
      sync();
    });
  }
  modeCustom.addEventListener("change", () => {
    if (modeCustom.checked && administrator.admin_permission_mode !== "custom") {
      // Start the custom selection from the current preset rather than empty.
      state.codes = new Set(TYPE_BY_KEY.get(typeSelect.value)?.codes || []);
    }
    sync();
  });
  modePreset.addEventListener("change", sync);
  typeSelect.addEventListener("change", sync);
  scopeSelect.addEventListener("change", sync);

  const output = el("p", { className: "notice", hidden: true });
  const save = button("保存权限配置", async () => {
    const payload = { admin_type: typeSelect.value, admin_scope: scopeSelect.value };
    payload.managed_department_ids = scopeSelect.value === "departments"
      ? [...departmentField.querySelectorAll('input[type="checkbox"]:checked')].map((input) => Number(input.value)).filter(Boolean)
      : [];
    if (state.custom) {
      payload.permissions = ALL_CODES.filter((code) => state.codes.has(code));
    }
    if (state.custom && payload.permissions.length === 0) {
      setErrorOutput(output, "自定义模式下至少勾选一项权限。");
      return;
    }
    try {
      await api(`/admin-api/admin-users/${administrator.id}`, { method: "PUT", body: JSON.stringify(payload) });
      modal?.markClean?.();
      await modal?.close?.({ force: true });
      toast(`已保存 ${administrator.username || `管理员 #${administrator.id}`} 的权限配置，原登录会话已撤销`, { tone: "success" });
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "权限配置保存失败"));
    }
  });

  const form = el("form", { className: "permission-edit-form", onSubmit: (event) => event.preventDefault() }, [
    el("div", { className: "permission-edit-grid" }, [typeField, scopeField]),
    departmentField,
    el("fieldset", { className: "permission-edit-fieldset" }, [
      el("legend", { text: "权限来源" }),
      modeGroup
    ]),
    el("div", { className: "permission-edit-matrix-wrap" }, [matrix]),
    matrixNote,
    el("div", { className: "permission-edit-actions" }, [save]),
    output
  ]);
  sync();
  const modal = showModal(`配置权限 · ${administrator.username || `管理员 #${administrator.id}`}`, form);
}
