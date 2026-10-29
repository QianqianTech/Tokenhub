// Generic list page for CRUD resources: filters (kept in the URL), server-side
// pagination with totals, sortable columns, column visibility, readable
// references, copyable ids, bulk selection and per-row actions.
import { adminErrorMessage, api, isAbortError } from "../api.js";
import { confirmDialog } from "../components/modal.js";
import { copyButton, emptyState, errorState, loadingState, toast } from "../components/feedback.js";
import { pager, PAGE_SIZES } from "../components/pager.js";
import { button, clear, el, field, readFields, showModal } from "../dom.js";
import { t } from "../i18n.js";
import { showCreateModal, showEditModal } from "./forms.js";
import { formatDateTime, isDateColumn, statusTone } from "./format.js";
import { clearLookupCache, COLUMN_LOOKUPS } from "./lookups.js";
import { openAdminDownload, renderDataDetails, showOperationResult } from "./output.js";
import { adminUserDepartmentLabels, adminUserPermissionLabels, apiKeyDepartmentLabel, apiKeyModelLabels, apiKeyUserLabel, departmentSelectOptions, modelQuotaApiKeyLabel, modelQuotaModelLabel, quotaPolicyLabel, quotaTargetLabel, referenceCellLabel, renderContext } from "./references.js";
import { PAGE_DESCRIPTIONS } from "./page-meta.js";
import { showAdminUserDepartmentGrant, showAdminUserSessionRevoke, showAllowClientIp, showShareLinkPasswordResetResult, showUsageContent, showUserDepartmentMove, showUserPasswordReset, showUserStatusUpdate } from "./row-actions.js";
import { readPreference, writePreference } from "./preferences.js";
import { adminViewerCanSyncCatalog, renderRequestDiagnosticsLookup, showProviderCatalogSync, showRequestDiagnostics } from "./ops-tools.js";
import { showApiKeyDetails } from "../pages/api-keys.js";
import { modelPriceSummary, renderPriceCategoryChips, showModelPriceDetails, showModelProviderImport, showProviderModelImport } from "../pages/catalog.js";
import { renderDepartmentTree } from "../pages/departments.js";
import { columnLabel as baseColumnLabel, filterFields, formatResourceValue, resources } from "../resources.js";

// Reference columns show the resolved name, so the header drops the “ID”.
function columnLabel(column) {
  const label = baseColumnLabel(column);
  return COLUMN_LOOKUPS[column] ? label.replace(/\s*ID$/, "") : label;
}

const DEFAULT_PAGE_SIZE = 20;
const SORTABLE_COLUMNS = ["id", "created_at", "updated_at", "effective_at", "sort_order", "request_count", "total_tokens", "cost_amount"];
const COPYABLE_COLUMNS = new Set(["request_id", "trace_id", "session_id", "export_id", "upload_id", "content_id", "share_code", "retrieval_id", "job_id", "task_id", "run_id", "case_id", "vector_id", "document_id", "kb_id", "dataset_id", "policy_id", "feedback_id", "client_ip"]);
const PAGING_KEYS = ["limit", "offset", "sort"];

const BULK_ACTIONS = {
  models: {
    endpoint: "/admin-api/models/batch",
    noun: "模型",
    actions: [
      ["enable", "批量启用", "secondary"],
      ["disable", "批量暂停", "secondary"],
      ["delete", "批量删除", "danger", "相关价格、API 密钥授权和模型限额也会被删除。"]
    ]
  }
};

function pagingFrom(filters) {
  const limit = PAGE_SIZES.includes(Number(filters.limit)) ? Number(filters.limit) : DEFAULT_PAGE_SIZE;
  const offset = Math.max(0, Number(filters.offset) || 0);
  return { limit, offset, sort: typeof filters.sort === "string" ? filters.sort : "" };
}

function withoutPaging(filters) {
  return Object.fromEntries(Object.entries(filters).filter(([key]) => !PAGING_KEYS.includes(key)));
}

export async function renderGenericResourcePage(content, resourceKey, initialFilters = {}, context = {}) {
  const config = resources[resourceKey];
  clear(content);
  if (!config) {
    content.append(errorState({ code: "NOT_FOUND" }, { fallback: "资源不存在" }));
    return;
  }
  if (config.tree) {
    await renderDepartmentTree(content, config, { filters: initialFilters, setFilters: context.setFilters });
    return;
  }
  const { openCreate = false, tab: _tab, ...filterValues } = initialFilters;
  const paging = pagingFrom(filterValues);
  const state = {
    filters: { ...withoutPaging(filterValues), limit: paging.limit, offset: paging.offset, ...(paging.sort ? { sort: paging.sort } : {}) },
    persist: (filters) => context.setFilters?.(filters)
  };
  const tablePanel = el("section", { className: "panel table-panel", "aria-live": "polite" });
  const refreshRows = async () => renderRows(tablePanel, resourceKey, config, state);
  const toolbar = await renderFilters(resourceKey, state, refreshRows);
  const actions = el("div", { className: "toolbar-actions" });
  if (config.form) {
    actions.append(button("新增", () => showCreateModal(resourceKey, config, refreshRows)));
  }
  if (resourceKey === "models") {
    actions.append(button("拉取模型", () => showModelProviderImport(refreshRows), "secondary"));
  }
  if (config.exportType) {
    actions.append(button("导出", async () => {
      const result = await api(`/admin-api/exports/${config.exportType}`, {
        method: "POST",
        body: JSON.stringify(withoutPaging(state.filters))
      });
      showOperationResult("导出任务", result.data, "导出任务已创建，可在导出任务列表查看");
    }, "secondary"));
  }
  for (const action of config.actions || []) {
    if (!action.bodyField) {
      actions.append(button(action.label, async () => {
        const result = await api(action.path, { method: action.method || "POST" });
        showOperationResult(action.label, result.data);
        await refreshRows();
      }, "secondary"));
    }
  }
  if (resourceKey === "usage-records" || resourceKey === "logs") {
    toolbar.append(renderRequestDiagnosticsLookup());
  }
  const parts = [];
  if (context.pageHead !== false) {
    parts.push(pageHead(config.label || resourceKey, PAGE_DESCRIPTIONS[resourceKey] || config.description || "", actions));
  } else {
    toolbar.append(actions);
  }
  content.append(...parts, toolbar, tablePanel);
  await refreshRows();
  if (openCreate && config.form) {
    await showCreateModal(resourceKey, config, refreshRows);
  }
}

// Filter controls that reference other records become pickers instead of
// raw "ID" inputs; date ranges use the native date-time picker.
async function filterAwareFields(fields) {
  const needsDepartments = fields.some((config) => config.name === "department_id");
  const departmentOptions = needsDepartments ? await departmentSelectOptions() : [];
  const remote = {
    user_id: ["users", "用户"],
    provider_id: ["providers", "厂商"],
    model_id: ["models", "模型"],
    api_key_id: ["api-keys", "API 密钥"]
  };
  return fields.map((config) => {
    if (config.name === "department_id") {
      return { ...config, label: "部门", type: "select", options: [["", "全部部门"], ...departmentOptions] };
    }
    if (remote[config.name]) {
      const [lookup, label] = remote[config.name];
      return { ...config, label, type: "remote-select", lookup, optional: true, emptyOption: `全部${label}`, coerce: "number" };
    }
    if (config.name === "from" || config.name === "to") {
      return { ...config, type: "datetime-local", datetime: true };
    }
    return config;
  });
}

export function toDateTimeLocal(value) {
  const text = String(value || "").trim();
  const match = text.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
  return match ? `${match[1]}T${match[2]}` : text;
}

function fromDateTimeLocal(value) {
  const text = String(value || "").trim();
  const match = text.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2}))?$/);
  return match ? `${match[1]} ${match[2]}:${match[3] || "00"}` : text;
}

export async function renderFilters(resourceKey, state, onApply) {
  const fields = await filterAwareFields(filterFields(resourceKey));
  const form = el("form", { className: "filters", role: "search", "aria-label": "筛选条件" });
  const apply = async () => {
    const values = readFields(form, fields);
    for (const config of fields) {
      if (config.datetime && values[config.name]) {
        values[config.name] = fromDateTimeLocal(values[config.name]);
      }
    }
    const kept = Object.fromEntries(Object.entries(state.filters).filter(([key]) => !fields.some((config) => config.name === key)));
    state.filters = { ...kept, ...values, offset: 0 };
    await onApply();
  };
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    void apply();
  });
  for (const config of fields) {
    const raw = state.filters[config.name] ?? "";
    form.append(field({ ...config, validate: false }, config.datetime ? toDateTimeLocal(raw) : raw));
  }
  form.append(
    button("筛选", apply, "", { type: "submit" }),
    button(t("common.reset"), async () => {
      const kept = Object.fromEntries(Object.entries(state.filters).filter(([key]) => !fields.some((config) => config.name === key)));
      state.filters = { ...kept, offset: 0 };
      for (const control of form.querySelectorAll("input:not([type=hidden]), select, textarea")) {
        control.value = control.tagName === "SELECT" ? control.options[0]?.value ?? "" : "";
      }
      form.querySelectorAll("[data-remote-select]").forEach((picker) => picker.setValue(""));
      await onApply();
    }, "secondary")
  );
  return form;
}

export async function renderRows(parent, resourceKey, config, state) {
  const seq = (parent.__renderSeq = (parent.__renderSeq || 0) + 1);
  parent.setAttribute("aria-busy", "true");
  parent.replaceChildren(loadingState());
  const query = { ...state.filters, with_total: 1 };
  if (!PAGE_SIZES.includes(Number(query.limit))) {
    query.limit = DEFAULT_PAGE_SIZE;
  }
  state.persist?.(withDefaultsRemoved(state.filters));
  let result;
  try {
    result = await api(`/admin-api/${resourceKey}`, { query, silent: true });
  } catch (error) {
    if (isAbortError(error) || seq !== parent.__renderSeq) {
      return;
    }
    parent.removeAttribute("aria-busy");
    parent.replaceChildren(errorState(error, { fallback: "列表加载失败", onRetry: () => renderRows(parent, resourceKey, config, state) }));
    return;
  }
  const rows = Array.isArray(result.data) ? result.data : (Array.isArray(result.data?.items) ? result.data.items : []);
  const total = Number.isFinite(result.total) ? result.total : (Number.isFinite(Number(result.data?.total)) ? Number(result.data.total) : null);
  const limit = Number(query.limit);
  const offset = Number(query.offset) || 0;
  if (rows.length === 0 && offset > 0) {
    // The page emptied (e.g. after deleting its last row): step back.
    state.filters = { ...state.filters, offset: Math.max(0, offset - limit) };
    await renderRows(parent, resourceKey, config, state);
    return;
  }
  const context = await renderContext(resourceKey, rows, config.columns || []);
  if (seq !== parent.__renderSeq) {
    return;
  }
  parent.removeAttribute("aria-busy");
  const onRefresh = async () => {
    clearLookupCache();
    await renderRows(parent, resourceKey, config, state);
  };
  if (rows.length === 0) {
    const hasFilters = Object.keys(withoutPaging(state.filters)).some((key) => state.filters[key] !== "" && state.filters[key] !== undefined);
    parent.replaceChildren(emptyState({
      title: hasFilters ? "没有符合条件的记录" : "暂无数据",
      description: hasFilters ? "可以调整或重置筛选条件后再试。" : config.form ? `点击“新增”创建第一条${config.label}。` : ""
    }));
    return;
  }
  const sort = typeof state.filters.sort === "string" ? state.filters.sort : "";
  parent.replaceChildren(
    renderTable(rows, config, resourceKey, onRefresh, context, {
      sort,
      total,
      onSort: async (nextSort) => {
        state.filters = { ...state.filters, sort: nextSort, offset: 0 };
        await renderRows(parent, resourceKey, config, state);
      }
    }),
    pager({
      total,
      limit,
      offset,
      count: rows.length,
      onChange: async ({ offset: nextOffset, limit: nextLimit }) => {
        state.filters = { ...state.filters, offset: nextOffset, limit: nextLimit };
        await renderRows(parent, resourceKey, config, state);
        parent.scrollIntoView({ block: "start", behavior: "smooth" });
      }
    })
  );
}

function withDefaultsRemoved(filters) {
  const next = { ...filters };
  if (Number(next.limit) === DEFAULT_PAGE_SIZE) delete next.limit;
  if (!Number(next.offset)) delete next.offset;
  if (!next.sort) delete next.sort;
  return next;
}

// Wide tables start with the columns people scan most; the rest stay one
// click away in “显示列”. A saved choice always wins over these defaults.
const DEFAULT_HIDDEN_COLUMNS = {
  "usage-records": ["department_id", "api_key_id", "capability", "input_tokens", "output_tokens", "cache_read_tokens", "cache_write_tokens"],
  logs: ["trace_id", "department_id", "api_key_id", "provider_id"],
  "dashboard-share-links": ["scope_ids", "allowed_metrics", "allow_drilldown", "allowed_ip_cidrs"],
  "admin-users": ["admin_scope", "permissions", "created_at"]
};

function hiddenColumnsFor(resourceKey) {
  const stored = readPreference(`cols_${resourceKey}`);
  const value = stored == null ? (DEFAULT_HIDDEN_COLUMNS[resourceKey] || []).join(",") : String(stored);
  return new Set(value.split(",").filter(Boolean));
}

function visibleColumnsFor(resourceKey, columns) {
  const hidden = hiddenColumnsFor(resourceKey);
  const visible = columns.filter((column) => !hidden.has(column));
  return visible.length > 0 ? visible : columns;
}

function columnMenu(resourceKey, columns, onChange) {
  const hidden = hiddenColumnsFor(resourceKey);
  const panel = el("div", { className: "column-menu-panel", hidden: true, role: "group", "aria-label": t("table.columns") });
  for (const column of columns) {
    const checkbox = el("input", { type: "checkbox", checked: !hidden.has(column) });
    checkbox.addEventListener("change", () => {
      if (checkbox.checked) {
        hidden.delete(column);
      } else if (columns.length - hidden.size > 1) {
        hidden.add(column);
      } else {
        checkbox.checked = true;
        return;
      }
      writePreference(`cols_${resourceKey}`, [...hidden].join(","));
      onChange();
    });
    panel.append(el("label", {}, [checkbox, el("span", { text: columnLabel(column) })]));
  }
  const toggle = el("button", { type: "button", className: "secondary", text: t("table.columns"), "aria-expanded": "false" });
  const wrapper = el("div", { className: "column-menu" }, [toggle, panel]);
  const onOutside = (event) => {
    if (!wrapper.contains(event.target)) {
      setOpen(false);
    }
  };
  const onKey = (event) => {
    if (event.key === "Escape") {
      setOpen(false);
      toggle.focus();
    }
  };
  function setOpen(open) {
    panel.hidden = !open;
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) {
      document.addEventListener("click", onOutside, true);
      document.addEventListener("keydown", onKey);
    } else {
      document.removeEventListener("click", onOutside, true);
      document.removeEventListener("keydown", onKey);
    }
  }
  toggle.addEventListener("click", () => setOpen(panel.hidden));
  return wrapper;
}

function selectionController(rows, label) {
  const selected = new Set();
  const ids = rows.filter((row) => row.id).map((row) => Number(row.id));
  const listeners = [];
  return {
    selected,
    ids,
    onChange(listener) {
      listeners.push(listener);
    },
    toggle(id, checked) {
      if (checked) selected.add(id); else selected.delete(id);
      listeners.forEach((listener) => listener());
    },
    setAll(checked) {
      selected.clear();
      if (checked) ids.forEach((id) => selected.add(id));
      listeners.forEach((listener) => listener());
    },
    summary() {
      return selected.size > 0 ? `${t("table.selection", { count: selected.size })}（${label}）` : t("table.noSelection");
    }
  };
}

async function runBulkAction(resourceKey, action, selection, onRefresh) {
  const bulk = BULK_ACTIONS[resourceKey];
  const [code, label, , warning] = bulk.actions.find(([key]) => key === action) || [];
  const ids = [...selection.selected];
  if (ids.length === 0) {
    toast(`请先选择${bulk.noun}`, { tone: "error" });
    return;
  }
  const ok = await confirmDialog({
    title: `${label}${bulk.noun}`,
    message: `确认对选中的 ${ids.length} 个${bulk.noun}执行“${label}”？${warning ? ` ${warning}` : ""}`,
    confirmLabel: label,
    tone: code === "delete" ? "danger" : "primary"
  });
  if (!ok) {
    return;
  }
  try {
    const result = await api(bulk.endpoint, { method: "POST", body: JSON.stringify({ action: code, ids }) });
    showOperationResult(label, result.data);
    await onRefresh();
  } catch (error) {
    toast(adminErrorMessage(error, `${label}失败，请确认权限或关联配置`), { tone: "error" });
  }
}

// Page title, one-line description and the page's primary actions.
export function pageHead(title, description = "", actions = null) {
  const text = el("div", { className: "page-head-text" }, [el("h2", { text: title })]);
  if (description) {
    text.append(el("p", { text: description }));
  }
  const head = el("header", { className: "page-head" }, [text]);
  if (actions && actions.childElementCount > 0) {
    actions.className = "page-head-actions";
    head.append(actions);
  }
  return head;
}

// Actions that stay visible in a row; everything else moves into “更多”.
const INLINE_ACTION_PRIORITY = ["编辑", "详情", "大屏1", "大屏2", "内容", "诊断", "下载", "价格明细", "拉取模型", "放行 IP"];

export function arrangeRowActions(ops, { maxInline = 3, keep = 2 } = {}) {
  if (ops.length <= maxInline) {
    return ops;
  }
  const label = (node) => (node.textContent || "").trim();
  const ranked = [...ops].sort((a, b) => {
    const ra = INLINE_ACTION_PRIORITY.indexOf(label(a));
    const rb = INLINE_ACTION_PRIORITY.indexOf(label(b));
    return (ra === -1 ? 99 : ra) - (rb === -1 ? 99 : rb);
  });
  const inline = ranked.slice(0, keep).filter((node) => INLINE_ACTION_PRIORITY.includes(label(node)));
  const rest = ops.filter((node) => !inline.includes(node));
  const safe = rest.filter((node) => !node.classList.contains("danger"));
  const risky = rest.filter((node) => node.classList.contains("danger"));
  return [...inline, rowMoreMenu([...safe, ...(safe.length && risky.length ? [el("hr")] : []), ...risky])];
}

let rowMenuSeq = 0;
function rowMoreMenu(items) {
  const id = `row-more-${++rowMenuSeq}`;
  const menu = el("div", { id, className: "row-more-menu", role: "menu", hidden: true }, items);
  const toggle = el("button", {
    type: "button",
    className: "row-more-toggle",
    text: "更多",
    "aria-haspopup": "menu",
    "aria-expanded": "false",
    "aria-controls": id
  });
  const wrap = el("div", { className: "row-more" }, [toggle, menu]);
  const close = () => {
    menu.hidden = true;
    toggle.setAttribute("aria-expanded", "false");
    document.removeEventListener("pointerdown", outside, true);
    document.removeEventListener("keydown", onKey, true);
  };
  const outside = (event) => {
    if (!wrap.contains(event.target)) close();
  };
  const onKey = (event) => {
    if (event.key === "Escape") {
      close();
      toggle.focus();
    }
  };
  toggle.addEventListener("click", () => {
    if (!menu.hidden) {
      close();
      return;
    }
    document.querySelectorAll(".row-more-menu:not([hidden])").forEach((other) => other.parentElement?.querySelector(".row-more-toggle")?.click());
    menu.hidden = false;
    toggle.setAttribute("aria-expanded", "true");
    document.addEventListener("pointerdown", outside, true);
    document.addEventListener("keydown", onKey, true);
    menu.querySelector("button, a")?.focus();
  });
  menu.addEventListener("click", (event) => {
    if (event.target.closest("button, a")) close();
  });
  for (const item of items) {
    if (item.tagName === "BUTTON" || item.tagName === "A") item.setAttribute("role", "menuitem");
  }
  return wrap;
}

export function renderTable(rows, config, resourceKey, onRefresh, context = {}, options = {}) {
  const allColumns = config.columns || [...new Set(rows.flatMap((row) => Object.keys(row)).slice(0, 12))];
  const host = el("div", { className: "resource-table-host" });
  const bulk = BULK_ACTIONS[resourceKey];
  const selection = bulk ? selectionController(rows, bulk.noun) : null;

  const draw = () => {
    const columns = visibleColumnsFor(resourceKey, allColumns);
    const table = el("table", { className: `resource-table resource-table--${resourceKey}` });
    table.append(el("caption", { className: "visually-hidden", text: `${config.label || resourceKey}列表` }));
    const headRow = el("tr");
    let selectAll = null;
    if (selection) {
      selectAll = el("input", {
        type: "checkbox",
        className: "row-select-all",
        "aria-label": t("table.selectPage"),
        title: t("table.selectPage"),
        onChange: (event) => selection.setAll(event.currentTarget.checked)
      });
      headRow.append(el("th", { className: "table-cell table-cell--select", scope: "col" }, [selectAll]));
    }
    for (const column of columns) {
      const sortable = SORTABLE_COLUMNS.includes(column) && options.onSort;
      const current = options.sort === `${column}_asc` ? "ascending" : options.sort === `${column}_desc` ? "descending" : null;
      const th = el("th", { className: `table-cell table-cell--${column}`, scope: "col", "aria-sort": current || (sortable ? "none" : null) });
      if (sortable) {
        th.append(el("button", {
          type: "button",
          className: "sort-button",
          text: columnLabel(column),
          title: `${columnLabel(column)}：${current === "descending" ? t("table.sortAsc") : t("table.sortDesc")}`,
          onClick: () => options.onSort(current === "descending" ? `${column}_asc` : `${column}_desc`)
        }));
      } else {
        th.textContent = columnLabel(column);
      }
      headRow.append(th);
    }
    headRow.append(el("th", { className: "table-cell table-cell--actions", scope: "col", text: "操作" }));
    const body = el("tbody");
    for (const row of rows) {
      const tr = el("tr");
      if (selection) {
        const checkbox = el("input", {
          type: "checkbox",
          className: "row-select",
          value: row.id,
          checked: selection.selected.has(Number(row.id)),
          "aria-label": t("table.selectRow", { id: row.name || row.id }),
          onChange: (event) => selection.toggle(Number(row.id), event.currentTarget.checked)
        });
        tr.append(el("td", { className: "table-cell table-cell--select" }, [checkbox]));
      }
      for (const column of columns) {
        tr.append(renderCell(resourceKey, column, row, context));
      }
      // The flex container lives inside the cell: a flex <td> stops being a
      // table cell and breaks row backgrounds and borders.
      tr.append(el("td", { className: "table-cell table-cell--actions" }, [
        el("div", { className: "row-actions" }, arrangeRowActions(rowActions(resourceKey, config, row, onRefresh, context)))
      ]));
      body.append(tr);
    }
    table.append(el("thead", {}, [headRow]), body);

    const meta = el("span", { className: "table-meta", text: Number.isFinite(options.total) ? `共 ${options.total} 条` : `本页 ${rows.length} 条` });
    const tools = el("div", { className: "table-tools" }, [columnMenu(resourceKey, allColumns, draw)]);
    const header = el("div", { className: "table-toolbar-row" }, [meta, tools]);
    const parts = [header];
    if (selection) {
      const status = el("span", { className: "selection-status", text: selection.summary() });
      const buttons = bulk.actions.map(([code, label, variant]) => button(label, () => runBulkAction(resourceKey, code, selection, onRefresh), variant, { disabled: selection.selected.size === 0 }));
      selection.onChange(() => {
        status.textContent = selection.summary();
        buttons.forEach((item) => {
          item.disabled = selection.selected.size === 0;
        });
        if (selectAll) {
          selectAll.checked = selection.ids.length > 0 && selection.selected.size === selection.ids.length;
          selectAll.indeterminate = selection.selected.size > 0 && selection.selected.size < selection.ids.length;
        }
      });
      parts.push(el("div", { className: `selection-bar ${resourceKey === "models" ? "model-bulk-actions" : ""}`.trim() }, [status, ...buttons]));
    }
    parts.push(el("div", { className: "table-scroll" }, [table]));
    host.replaceChildren(...parts);
  };
  draw();
  return host;
}

function renderCell(resourceKey, column, row, context) {
  const cell = el("td", { className: `table-cell table-cell--${column}` });
  const content = renderTableCell(resourceKey, column, row, context);
  if (content instanceof Node) {
    cell.append(content);
    return cell;
  }
  const text = content == null ? "" : String(content);
  if (column === "status" && row.status) {
    cell.append(el("span", { className: `status-pill ${statusTone(row.status)}`.trim(), text }));
    return cell;
  }
  if (text.length > 48) {
    cell.append(el("span", { className: "cell-truncate", title: text, text }));
  } else {
    cell.textContent = text;
  }
  if (isDateColumn(column) && row[column]) {
    cell.title = String(row[column]);
  }
  if (COPYABLE_COLUMNS.has(column) && text) {
    const copy = copyButton(String(row[column]), "复制");
    copy.className = "cell-copy";
    copy.textContent = "⧉";
    cell.append(copy);
  }
  return cell;
}

async function confirmAndRun({ title, message, details = [], confirmLabel, requireText = "", tone = "danger" }, task) {
  const ok = await confirmDialog({ title, message, details, confirmLabel, requireText, tone });
  if (ok) {
    await task();
  }
}

function rowActions(resourceKey, config, row, onRefresh, context) {
  const ops = [];
  const name = row.name || row.username || row.rule_code || `#${row.id}`;
  if (resourceKey === "api-keys") {
    ops.push(button("详情", () => showApiKeyDetails(row, config, context), "secondary"));
  }
  if (resourceKey === "model-prices") {
    ops.push(button("价格明细", () => showModelPriceDetails(row, context), "secondary"));
  }
  if (resourceKey === "usage-records") {
    ops.push(button("内容", () => showUsageContent(row), "secondary"));
  }
  if ((resourceKey === "usage-records" || resourceKey === "logs") && row.request_id) {
    ops.push(button("诊断", () => showRequestDiagnostics(row.request_id), "secondary request-diagnostics-button"));
  }
  if (resourceKey === "export-tasks" && row.status === "succeeded" && row.export_id) {
    ops.push(button("下载", () => openAdminDownload(`/admin-api/exports/${encodeURIComponent(row.export_id)}/download-url`), "secondary"));
  }
  if (resourceKey === "media-assets" && row.asset_id) {
    ops.push(button("下载", () => openAdminDownload(`/admin-api/media-assets/${encodeURIComponent(row.asset_id)}/download-url`, "POST"), "secondary"));
  }
  if (resourceKey === "log-archive-files" && !row.deleted_at) {
    ops.push(button("下载", () => openAdminDownload(`/admin-api/log-archive-files/${encodeURIComponent(row.id)}/download-url`, "POST"), "secondary"));
  }
  if (resourceKey === "dashboard-share-links") {
    for (const [label, screen] of [["大屏1", "overview"], ["大屏2", "operations"]]) {
      ops.push(el("a", { className: "button-link secondary", href: `/screen/${encodeURIComponent(row.share_code)}/${screen}`, target: "_blank", rel: "noopener", text: label }));
    }
    ops.push(button("重置密码", () => confirmAndRun({
      title: "重置大屏访问密码",
      message: "重置后旧密码立即失效，已登录的大屏需要使用新密码重新登录。",
      details: [["分享链接", name]],
      confirmLabel: "重置密码"
    }, async () => {
      const result = await api(`/admin-api/dashboard-share-links/${row.id}/reset-password`, { method: "POST" });
      showShareLinkPasswordResetResult(result.data || {});
      await onRefresh();
    }), "secondary"));
    ops.push(button("撤销会话", () => confirmAndRun({
      title: "撤销大屏会话",
      message: "所有正在查看该大屏的会话将被强制退出。",
      details: [["分享链接", name]],
      confirmLabel: "撤销会话"
    }, async () => {
      const result = await api(`/admin-api/dashboard-share-links/${row.id}/revoke-sessions`, { method: "POST" });
      showOperationResult("撤销会话", result.data, "已撤销相关会话");
      await onRefresh();
    }), "secondary"));
    ops.push(button("放行 IP", () => showAllowClientIp(row, onRefresh), "secondary"));
  }
  if (resourceKey === "dashboard-share-access-logs" && row.share_link_id && row.client_ip && row.event_type === "ip_denied") {
    ops.push(button("放行 IP", () => showAllowClientIp({ id: row.share_link_id, client_ip: row.client_ip }, onRefresh), "secondary"));
  }
  if (resourceKey === "users") {
    ops.push(button("重置密码", () => showUserPasswordReset(row, onRefresh), "secondary"));
    ops.push(button("调整部门", () => showUserDepartmentMove(row, onRefresh), "secondary"));
    ops.push(button("更新状态", () => showUserStatusUpdate(row, onRefresh), "secondary"));
  }
  if (resourceKey === "admin-users") {
    ops.push(button("重置密码", () => showUserPasswordReset(row, onRefresh, "admin-users"), "secondary"));
    ops.push(button("授权部门", () => showAdminUserDepartmentGrant(row, onRefresh), "secondary"));
    ops.push(button("更新状态", () => showUserStatusUpdate(row, onRefresh, "admin-users"), "secondary"));
    ops.push(button("撤销会话", () => showAdminUserSessionRevoke(row, onRefresh), "danger"));
  }
  if (resourceKey === "providers" && row.id) {
    ops.push(button("拉取模型", () => showProviderModelImport(row, onRefresh), "secondary"));
  }
  if (resourceKey === "providers" && row.id && adminViewerCanSyncCatalog()) {
    ops.push(button("目录同步", () => showProviderCatalogSync(row, onRefresh), "secondary catalog-sync-button"));
  }
  if (config.form && row.id) {
    ops.push(button("编辑", () => showEditModal(resourceKey, config, row, onRefresh), "secondary"));
  }
  for (const action of config.actions || []) {
    if (action.diffField && row[action.diffField]) {
      ops.push(button(action.label, async () => {
        const result = await api(`${action.pathPrefix}${encodeURIComponent(row[action.diffField])}/diff`, {
          method: action.method || "GET",
          query: { base: "previous" }
        });
        showModal(action.label, renderDataDetails(result.data));
      }, "secondary"));
    }
    if (action.bodyField && row[action.bodyField]) {
      ops.push(button(action.label, async () => {
        const result = await api(action.path, {
          method: action.method || "POST",
          body: JSON.stringify({ [action.bodyField]: row[action.bodyField] })
        });
        showOperationResult(action.label, result.data);
        await onRefresh();
      }, "secondary"));
    }
  }
  if ((resourceKey === "providers" || resourceKey === "models") && row.id) {
    const noun = resourceKey === "providers" ? "厂商" : "模型";
    ops.push(button("删除", () => confirmAndRun({
      title: `删除${noun}`,
      message: resourceKey === "providers"
        ? "删除后该厂商下的模型配置也会被删除，且无法恢复。"
        : "删除后相关价格、API 密钥授权和模型限额也会被删除，且无法恢复。",
      details: [[noun, `${name}（#${row.id}）`]],
      confirmLabel: "删除",
      requireText: String(row.name || row.id)
    }, async () => {
      try {
        const result = await api(`/admin-api/${resourceKey}/${row.id}`, { method: "DELETE" });
        showOperationResult(`删除${noun}`, result.data);
        await onRefresh();
      } catch (error) {
        toast(adminErrorMessage(error, `删除${noun}失败，请确认权限或关联配置`), { tone: "error" });
      }
    }), "danger"));
  }
  if (!["providers", "models"].includes(resourceKey) && config.readOnly !== true && row.id && row.status !== "disabled" && row.status !== "deleted") {
    ops.push(button("停用", () => confirmAndRun({
      title: `停用${config.label || ""}`,
      message: "停用后该记录将不再生效，可在编辑中重新启用。",
      details: [[config.label || "记录", `${name}（#${row.id}）`]],
      confirmLabel: "停用"
    }, async () => {
      try {
        await api(`/admin-api/${resourceKey}/${row.id}`, { method: "DELETE" });
        toast(`${config.label || "记录"}已停用`, { tone: "success" });
        await onRefresh();
      } catch (error) {
        toast(adminErrorMessage(error, "停用失败，请确认权限或关联配置"), { tone: "error" });
      }
    }), "danger"));
  }
  return ops;
}

export function renderTableCell(resourceKey, column, row, context) {
  if (resourceKey === "admin-users" && column === "department_id") {
    return apiKeyDepartmentLabel(row[column], context);
  }
  if (resourceKey === "admin-users" && column === "permissions") {
    return adminUserPermissionLabels(row[column]);
  }
  if (resourceKey === "admin-users" && column === "managed_department_ids") {
    return adminUserDepartmentLabels(row[column], context);
  }
  if (resourceKey === "api-keys" && column === "user_id") {
    return apiKeyUserLabel(row[column], context);
  }
  if (resourceKey === "api-keys" && column === "department_id") {
    return apiKeyDepartmentLabel(row[column], context);
  }
  if (resourceKey === "api-keys" && column === "model_ids") {
    return apiKeyModelLabels(row[column], context);
  }
  if (resourceKey === "quota-bindings" && column === "quota_policy_id") {
    return quotaPolicyLabel(row[column], context);
  }
  if (resourceKey === "quota-bindings" && column === "target_id") {
    return quotaTargetLabel(row.target_type, row[column], context);
  }
  if (resourceKey === "api-key-model-quotas" && column === "api_key_id") {
    return modelQuotaApiKeyLabel(row[column], context);
  }
  if (resourceKey === "api-key-model-quotas" && column === "model_id") {
    return modelQuotaModelLabel(row[column], context);
  }
  if (resourceKey === "models" && column === "gateway_support") {
    return row.gateway_supported === false ? `不支持：${row.unsupported_message || row.unsupported_reason || ""}` : "支持";
  }
  if (resourceKey === "models" && column === "provider_name") {
    return row.provider_name || formatResourceValue("provider_id", row.provider_id);
  }
  if (resourceKey === "model-prices" && column === "price_categories") {
    return renderPriceCategoryChips(row, context);
  }
  if (resourceKey === "model-prices" && column === "price_summary") {
    return modelPriceSummary(row);
  }
  const reference = referenceCellLabel(column, row[column], context);
  if (reference) {
    return reference;
  }
  if (isDateColumn(column)) {
    return formatDateTime(row[column]);
  }
  return formatResourceValue(column, row[column]);
}
