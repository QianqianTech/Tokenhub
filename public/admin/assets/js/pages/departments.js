import { adminErrorMessage, api, isAbortError } from "../api.js";
import { button, el, readFields, showModal } from "../dom.js";
import { errorState, loadingState, emptyState, toast } from "../components/feedback.js";
import { confirmDialog } from "../components/modal.js";
import { resourceForm } from "../lib/forms.js";
import { setErrorOutput } from "../lib/output.js";
import { formDialog } from "../lib/row-actions.js";
import { clearLookupCache } from "../lib/lookups.js";
import { valueLabel } from "../resources.js";

// Organisation workspace: a searchable department tree on the left and the
// selected department's details, path and direct children on the right.

const DISABLE_IMPACT = "停用后该部门下的 API 密钥将立即失效，成员无法继续调用。可以稍后重新启用。";

// Parent pickers list the tree with indentation; editing a department hides
// itself and its descendants so a cycle cannot be chosen.
function parentOptions(roots, excludedIds = new Set()) {
  const options = [["", "无（顶级部门）"]];
  const walk = (rows, depth) => {
    for (const row of rows) {
      if (excludedIds.has(Number(row.id))) {
        continue;
      }
      options.push([String(row.id), `${"　".repeat(depth)}${row.name || `#${row.id}`}${row.status === "disabled" ? "（已停用）" : ""}`]);
      walk(row.children || [], depth + 1);
    }
  };
  walk(roots, 0);
  return options;
}

function departmentFields(roots, excludedIds) {
  return [
    { name: "name", label: "部门名称", placeholder: "例如：政务服务数据管理局" },
    { name: "parent_id", label: "上级部门", type: "select", coerce: "number", options: parentOptions(roots, excludedIds), optional: true },
    { name: "sort_order", label: "排序（数字越小越靠前，留空为 0）", type: "number", optional: true }
  ];
}

function subtreeIds(row) {
  return [Number(row.id), ...(row.children || []).flatMap(subtreeIds)];
}

function flattenTree(rows, depth = 0, parent = null) {
  return rows.flatMap((row) => [{ row, depth, parent }, ...flattenTree(row.children || [], depth + 1, row)]);
}

function descendantCount(row) {
  return (row.children || []).reduce((sum, child) => sum + 1 + descendantCount(child), 0);
}

function initialOf(name) {
  const text = String(name || "").trim();
  return text ? Array.from(text)[0] : "部";
}

function highlight(text, query) {
  const value = String(text || "");
  if (!query) {
    return [value];
  }
  const index = value.toLowerCase().indexOf(query.toLowerCase());
  if (index < 0) {
    return [value];
  }
  return [value.slice(0, index), el("mark", { text: value.slice(index, index + query.length) }), value.slice(index + query.length)];
}

export async function renderDepartmentTree(content, _config, context = {}) {
  const state = {
    roots: [],
    index: new Map(),
    selectedId: Number(context.filters?.dept) || null,
    expanded: new Set(),
    query: "",
    hideDisabled: false
  };

  const createButton = button("新增部门", () => showDepartmentCreate(state.selectedId), "", { "aria-haspopup": "dialog" });
  const stats = el("div", { className: "department-stats", role: "list", "aria-label": "部门概况" });
  const searchInput = el("input", {
    type: "search",
    className: "department-search",
    placeholder: "搜索部门名称",
    "aria-label": "搜索部门",
    autocomplete: "off"
  });
  const hideDisabledToggle = el("input", { type: "checkbox", className: "department-hide-disabled" });
  const tree = el("ul", { className: "department-tree", role: "tree", "aria-label": "部门树" });
  const treeBody = el("div", { className: "department-tree-body" }, [tree]);
  const treePanel = el("section", { className: "panel department-tree-panel", "aria-label": "组织架构" }, [
    el("div", { className: "department-tree-toolbar" }, [
      el("label", { className: "department-search-field" }, [
        el("span", { className: "visually-hidden", text: "搜索部门" }),
        searchInput
      ]),
      el("div", { className: "department-tree-tools" }, [
        button("全部展开", () => setAllExpanded(true), "ghost department-tree-tool"),
        button("全部收起", () => setAllExpanded(false), "ghost department-tree-tool"),
        el("label", { className: "department-toggle" }, [hideDisabledToggle, el("span", { text: "隐藏已停用" })])
      ])
    ]),
    treeBody
  ]);
  const detail = el("section", { className: "panel department-detail", "aria-live": "polite" });
  const layout = el("div", { className: "department-layout" }, [treePanel, detail]);
  content.replaceChildren(
    el("header", { className: "page-head" }, [
      el("div", { className: "page-head-text" }, [
        el("h2", { text: "部门管理" }),
        el("p", { text: "维护组织层级。用户归属部门后，其 API 密钥用量计入该部门；停用部门会让部门下的密钥立即失效。" })
      ]),
      el("div", { className: "page-head-actions" }, [createButton])
    ]),
    stats,
    layout
  );

  searchInput.addEventListener("input", () => {
    state.query = searchInput.value.trim();
    drawTree();
  });
  hideDisabledToggle.addEventListener("change", () => {
    state.hideDisabled = hideDisabledToggle.checked;
    drawTree();
  });
  tree.addEventListener("keydown", handleTreeKeydown);

  function select(id, { focus = false } = {}) {
    state.selectedId = Number(id);
    // Selecting reveals the department: every ancestor is expanded.
    let entry = state.index.get(state.selectedId);
    while (entry?.parent) {
      state.expanded.add(Number(entry.parent.id));
      entry = state.index.get(Number(entry.parent.id));
    }
    context.setFilters?.({ dept: state.selectedId });
    drawTree();
    drawDetail();
    if (focus) {
      tree.querySelector(`[data-department-id="${state.selectedId}"]`)?.focus();
    }
  }

  function setAllExpanded(open) {
    state.expanded = open ? new Set([...state.index.values()].filter((entry) => (entry.row.children || []).length).map((entry) => Number(entry.row.id))) : new Set();
    drawTree();
  }

  async function load() {
    clearLookupCache("departments");
    tree.replaceChildren();
    treeBody.replaceChildren(loadingState("正在加载部门树…"));
    detail.replaceChildren();
    try {
      const result = await api("/admin-api/departments/tree");
      state.roots = Array.isArray(result.data) ? result.data : [];
    } catch (error) {
      if (isAbortError(error)) {
        return;
      }
      treeBody.replaceChildren(errorState(error, { fallback: "部门树加载失败", onRetry: () => load() }));
      return;
    }
    treeBody.replaceChildren(tree);
    state.index = new Map(flattenTree(state.roots).map((entry) => [Number(entry.row.id), entry]));
    if (state.expanded.size === 0) {
      // First load: open the top two levels.
      for (const entry of state.index.values()) {
        if (entry.depth < 1) state.expanded.add(Number(entry.row.id));
      }
    }
    if (!state.index.has(state.selectedId)) {
      state.selectedId = state.roots.length ? Number(state.roots[0].id) : null;
    }
    drawStats();
    if (state.selectedId) {
      select(state.selectedId);
    } else {
      drawTree();
      drawDetail();
    }
  }

  function drawStats() {
    const entries = [...state.index.values()];
    const disabled = entries.filter((entry) => entry.row.status === "disabled").length;
    const depth = entries.reduce((max, entry) => Math.max(max, entry.depth + 1), 0);
    const stat = (label, value, tone = "") => el("span", { className: `department-summary-card ${tone}`.trim(), role: "listitem" }, [
      el("strong", { text: String(value) }),
      el("span", { text: label })
    ]);
    stats.replaceChildren(
      stat("个部门", entries.length),
      stat("启用中", entries.length - disabled, "is-positive"),
      stat("已停用", disabled, disabled ? "is-muted" : ""),
      stat("级组织层级", depth)
    );
  }

  // Which departments are visible: search shows matches and their ancestors;
  // "hide disabled" removes disabled departments and their subtrees.
  function visibleSet() {
    const query = state.query.toLowerCase();
    const keep = new Set();
    const walk = (row) => {
      if (state.hideDisabled && row.status === "disabled") {
        return false;
      }
      const childHit = (row.children || []).map(walk).some(Boolean);
      const selfHit = !query || String(row.name || "").toLowerCase().includes(query);
      if (selfHit || childHit) {
        keep.add(Number(row.id));
        return true;
      }
      return false;
    };
    state.roots.forEach(walk);
    return keep;
  }

  function drawTree() {
    const visible = visibleSet();
    const searching = state.query !== "";
    const renderItems = (rows, depth) => rows.filter((row) => visible.has(Number(row.id))).map((row) => {
      const id = Number(row.id);
      const children = (row.children || []).filter((child) => visible.has(Number(child.id)));
      const hasChildren = children.length > 0;
      const open = hasChildren && (searching || state.expanded.has(id));
      const selected = id === state.selectedId;
      const disabled = row.status === "disabled";
      const caret = el("span", {
        className: `department-caret ${hasChildren ? "" : "is-leaf"}`.trim(),
        "aria-hidden": "true",
        onClick: (event) => {
          if (!hasChildren) return;
          event.stopPropagation();
          toggle(id);
        }
      });
      const item = el("li", {
        className: `department-node ${selected ? "is-selected" : ""} ${disabled ? "is-disabled" : ""}`.trim(),
        role: "treeitem",
        tabIndex: selected ? 0 : -1,
        "aria-selected": selected ? "true" : "false",
        "aria-expanded": hasChildren ? (open ? "true" : "false") : null,
        "aria-level": String(depth + 1),
        dataset: { departmentId: String(id) },
        onClick: (event) => {
          event.stopPropagation();
          select(id);
          // Stacked layout: the detail sits under the tree, so bring it into view.
          if (window.matchMedia?.("(max-width: 960px)").matches) {
            detail.scrollIntoView({ block: "start", behavior: "smooth" });
          }
        }
      }, [
        el("div", { className: "department-node-row", style: `--department-depth: ${depth}` }, [
          caret,
          el("span", { className: "department-node-icon", "aria-hidden": "true", text: initialOf(row.name) }),
          el("span", { className: "department-node-name" }, highlight(row.name || `#${id}`, state.query)),
          disabled ? el("span", { className: "department-node-tag", text: "已停用" }) : null,
          (row.children || []).length ? el("span", { className: "department-child-count", title: `${(row.children || []).length} 个直属子部门`, text: String((row.children || []).length) }) : null
        ])
      ]);
      if (hasChildren) {
        const group = el("ul", { className: "department-children", role: "group", hidden: !open }, renderItems(children, depth + 1));
        item.append(group);
      }
      return item;
    });
    const items = renderItems(state.roots, 0);
    if (items.length === 0) {
      tree.replaceChildren(el("li", { className: "department-tree-empty", role: "presentation" }, [
        state.roots.length
          ? emptyState({ title: "没有匹配的部门", description: "换个关键词试试，或取消“隐藏已停用”。" })
          : emptyState({ title: "还没有部门", description: "点击右上角“新增部门”创建第一个部门。" })
      ]));
      return;
    }
    tree.replaceChildren(...items);
    // Keep one tab stop in the tree even when the selection is filtered out.
    if (!tree.querySelector('[tabindex="0"]')) {
      tree.querySelector('[role="treeitem"]')?.setAttribute("tabindex", "0");
    }
  }

  function toggle(id) {
    if (state.expanded.has(id)) {
      state.expanded.delete(id);
    } else {
      state.expanded.add(id);
    }
    drawTree();
    tree.querySelector(`[data-department-id="${id}"]`)?.focus();
  }

  // WAI-ARIA tree keyboard pattern.
  function handleTreeKeydown(event) {
    const items = [...tree.querySelectorAll('[role="treeitem"]')].filter((item) => !item.closest("[hidden]"));
    const current = event.target.closest('[role="treeitem"]');
    if (!current) return;
    const index = items.indexOf(current);
    const id = Number(current.dataset.departmentId);
    const focusAt = (next) => {
      if (!next) return;
      items.forEach((item) => item.setAttribute("tabindex", "-1"));
      next.setAttribute("tabindex", "0");
      next.focus();
    };
    switch (event.key) {
      case "ArrowDown":
        focusAt(items[index + 1]);
        break;
      case "ArrowUp":
        focusAt(items[index - 1]);
        break;
      case "Home":
        focusAt(items[0]);
        break;
      case "End":
        focusAt(items[items.length - 1]);
        break;
      case "ArrowRight":
        if (current.getAttribute("aria-expanded") === "false") toggle(id);
        else if (current.getAttribute("aria-expanded") === "true") focusAt(current.querySelector('[role="treeitem"]'));
        break;
      case "ArrowLeft":
        if (current.getAttribute("aria-expanded") === "true" && state.query === "") toggle(id);
        else focusAt(current.parentElement?.closest('[role="treeitem"]'));
        break;
      case "Enter":
      case " ":
        select(id, { focus: true });
        break;
      default:
        return;
    }
    event.preventDefault();
  }

  function drawDetail() {
    const entry = state.index.get(state.selectedId);
    if (!entry) {
      detail.replaceChildren(emptyState({ title: "选择一个部门", description: "在左侧部门树中点击部门，查看它的层级、子部门并进行维护。" }));
      return;
    }
    const { row, depth } = entry;
    const children = row.children || [];
    const disabled = row.status === "disabled";
    const ancestors = [];
    let cursor = entry.parent;
    while (cursor) {
      ancestors.unshift(cursor);
      cursor = state.index.get(Number(cursor.id))?.parent || null;
    }
    const disabledAncestor = ancestors.find((item) => item.status === "disabled");
    const path = el("nav", { className: "department-path", "aria-label": "部门路径" }, [
      ...ancestors.flatMap((item) => [
        el("button", { type: "button", className: "department-path-link", text: item.name, onClick: () => select(item.id, { focus: true }) }),
        el("span", { className: "department-path-sep", "aria-hidden": "true", text: "›" })
      ]),
      el("span", { className: "department-path-current", "aria-current": "page", text: row.name })
    ]);
    const actions = el("div", { className: "department-detail-actions" }, [
      button("新增子部门", () => showDepartmentCreate(Number(row.id)), "secondary", { disabled }),
      button("编辑", () => showDepartmentEdit(row, { roots: state.roots, refresh: load }), "secondary", { "aria-label": `编辑部门 ${row.name}` }),
      disabled
        ? button("启用", () => enableDepartment(row), "secondary", { "aria-label": `启用部门 ${row.name}` })
        : button("停用", () => disableDepartment(row), "danger", { "aria-label": `停用部门 ${row.name}` })
    ]);
    const fact = (label, value, mono = false) => el("div", { className: "department-fact" }, [
      el("dt", { text: label }),
      el("dd", { className: mono ? "is-mono" : "", text: value })
    ]);
    const childRows = children.length
      ? el("ul", { className: "department-child-list" }, children.map((child) => el("li", {}, [
        el("button", {
          type: "button",
          className: `department-child-row ${child.status === "disabled" ? "is-disabled" : ""}`.trim(),
          onClick: () => select(child.id, { focus: true })
        }, [
          el("span", { className: "department-node-icon", "aria-hidden": "true", text: initialOf(child.name) }),
          el("span", { className: "department-child-name", text: child.name }),
          el("span", { className: "department-child-meta", text: (child.children || []).length ? `${(child.children || []).length} 个子部门` : "无子部门" }),
          el("span", { className: `status-pill ${child.status === "disabled" ? "is-muted" : "is-positive"}`, text: valueLabel("status", child.status) }),
          el("span", { className: "department-child-chevron", "aria-hidden": "true", text: "›" })
        ])
      ])))
      : el("div", { className: "department-child-empty" }, [
        el("p", { text: disabled ? "该部门已停用，启用后才能添加子部门。" : "还没有子部门。" }),
        disabled ? null : button("新增子部门", () => showDepartmentCreate(Number(row.id)), "ghost")
      ]);
    detail.replaceChildren(...[
      el("header", { className: "department-detail-head" }, [
        path,
        el("div", { className: "department-title-row" }, [
          el("span", { className: "department-title-icon", "aria-hidden": "true", text: initialOf(row.name) }),
          el("div", { className: "department-title-text" }, [
            el("h3", { text: row.name }),
            el("span", { className: `status-pill ${disabled ? "is-muted" : "is-positive"}`, text: valueLabel("status", row.status) })
          ]),
          actions
        ])
      ]),
      disabledAncestor
        ? el("p", { className: "department-warning", role: "note", text: `上级部门「${disabledAncestor.name}」已停用，本部门的密钥同样无法调用。` })
        : null,
      el("dl", { className: "department-facts" }, [
        fact("上级部门", entry.parent ? entry.parent.name : "无（顶级部门）"),
        fact("所在层级", `第 ${depth + 1} 级`),
        fact("直属子部门", `${children.length} 个`),
        fact("下属部门合计", `${descendantCount(row)} 个`),
        fact("排序", String(row.sort_order ?? 0), true),
        fact("部门编号", `#${row.id}`, true)
      ]),
      el("section", { className: "department-children-section", "aria-label": "直属子部门" }, [
        el("h4", { text: `直属子部门 · ${children.length}` }),
        childRows
      ])
    ].filter(Boolean));
  }

  async function enableDepartment(row) {
    try {
      await api(`/admin-api/departments/${row.id}`, { method: "PUT", body: JSON.stringify({ status: "active" }) });
      toast(`部门「${row.name}」已启用`, { tone: "success" });
      await load();
    } catch (error) {
      toast(adminErrorMessage(error, "启用部门失败"), { tone: "error" });
    }
  }

  async function disableDepartment(row) {
    const descendants = descendantCount(row);
    const confirmed = await confirmDialog({
      title: "停用部门",
      message: DISABLE_IMPACT,
      details: [["部门", row.name], ["下属部门", `${descendants} 个`]],
      confirmLabel: "停用"
    });
    if (!confirmed) {
      return;
    }
    try {
      await api(`/admin-api/departments/${row.id}`, { method: "DELETE" });
      toast(`部门「${row.name}」已停用`, { tone: "success" });
      await load();
    } catch (error) {
      toast(adminErrorMessage(error, "停用部门失败"), { tone: "error" });
    }
  }

  function showDepartmentCreate(parentId = null) {
    const fields = departmentFields(state.roots, new Set());
    const form = resourceForm(fields, { parent_id: parentId ? String(parentId) : "" }, "", { mode: "create" });
    form.classList.add("department-create-form");
    const output = el("p", { className: "notice", hidden: true });
    let modal = null;
    form.append(el("div", { className: "form-actions" }, [
      button("创建", async () => {
        let payload;
        try {
          payload = readFields(form, fields, { validate: true });
        } catch (error) {
          setErrorOutput(output, adminErrorMessage(error, error.message));
          return;
        }
        let created = null;
        try {
          const result = await api("/admin-api/departments", { method: "POST", body: JSON.stringify(payload) });
          created = result.data || null;
        } catch (error) {
          setErrorOutput(output, adminErrorMessage(error, "新增部门失败，请检查输入内容"));
          return;
        }
        toast(`部门「${payload.name}」已新增`, { tone: "success" });
        modal?.markClean?.();
        await modal?.close?.({ force: true });
        if (created?.id) {
          state.selectedId = Number(created.id);
        }
        if (payload.parent_id) {
          state.expanded.add(Number(payload.parent_id));
        }
        await load();
      }, "", { type: "submit" })
    ]), output);
    const parentName = parentId ? state.index.get(Number(parentId))?.row.name : "";
    modal = showModal(parentName ? `在「${parentName}」下新增部门` : "新增部门", form);
    form.querySelector('[name="name"]')?.focus();
  }

  await load();
}

function showDepartmentEdit(row, context) {
  const fields = [
    ...departmentFields(context.roots, new Set(subtreeIds(row))),
    { name: "status", label: "状态", type: "select", options: ["active", "disabled"].map((value) => [value, valueLabel("status", value)]) }
  ];
  const values = { ...row, parent_id: row.parent_id ? String(row.parent_id) : "" };
  formDialog({
    title: `编辑部门：${row.name}`,
    fields,
    values,
    submitLabel: "保存",
    successText: "部门已保存",
    failText: "保存部门失败，请检查输入内容",
    submit: async (payload) => {
      // An emptied parent picker moves the department to the top level.
      if (!("parent_id" in payload) && row.parent_id) {
        payload.parent_id = null;
      }
      const statusChange = payload.status === "disabled" && row.status !== "disabled";
      if (statusChange && !await confirmDialog({
        title: "停用部门",
        message: DISABLE_IMPACT,
        details: [["部门", row.name]],
        confirmLabel: "停用并保存"
      })) {
        return false;
      }
      await api(`/admin-api/departments/${row.id}`, { method: "PUT", body: JSON.stringify(payload) });
      await context.refresh();
    }
  });
}
