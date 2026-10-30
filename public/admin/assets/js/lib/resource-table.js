// Generic list page for CRUD resources: filters (kept in the URL), server-side
// pagination with totals, sortable columns, column visibility, readable
// references, copyable ids, bulk selection and per-row actions.
import { adminErrorMessage, api, isAbortError } from "../api.js";
import { confirmDialog } from "../components/modal.js";
import { copyButton, emptyState, errorState, loadingState, toast } from "../components/feedback.js";
import { pager, PAGE_SIZES } from "../components/pager.js";
import { button, clear, el, field, showModal } from "../dom.js";
import { t } from "../i18n.js";
import { showCreateModal, showEditModal } from "./forms.js";
import { formatDateTime, isDateColumn, statusTone } from "./format.js";
import { clearLookupCache, COLUMN_LOOKUPS } from "./lookups.js";
import { openAdminDownload, renderDataDetails, showOperationResult } from "./output.js";
import { adminUserDepartmentLabels, adminUserPermissionLabels, apiKeyDepartmentLabel, apiKeyModelLabels, apiKeyUserLabel, departmentSelectOptions, modelQuotaApiKeyLabel, modelQuotaModelLabel, quotaPolicyLabel, quotaTargetLabel, referenceCellLabel, renderContext } from "./references.js";
import { PAGE_DESCRIPTIONS } from "./page-meta.js";
import { LIST_FILTERS, presetFor, presetRange, RANGE_PRESETS, VISIBLE_FILTERS } from "./list-filters.js";
import { showAdminUserDepartmentGrant, showAdminUserSessionRevoke, showAllowClientIp, showShareLinkPasswordResetResult, showUsageContent, showUserDepartmentMove, showUserPasswordReset, showUserStatusUpdate } from "./row-actions.js";
import { readPreference, writePreference } from "./preferences.js";
import { adminViewerCanSyncCatalog, renderRequestDiagnosticsLookup, showProviderCatalogSync, showRequestDiagnostics } from "./ops-tools.js";
import { showApiKeyDetails } from "../pages/api-keys.js";
import { modelPriceSummary, renderPriceCategoryChips, showModelPriceDetails, showModelProviderImport, showProviderModelImport } from "../pages/catalog.js";
import { renderDepartmentTree } from "../pages/departments.js";
import { contextCell, modelNameCell, modelPrices, modelSwitch, priceCell, providerCards } from "./model-list.js";
import { compact, costText, latencyText, usageFailureDetails, usageSummary } from "./usage-list.js";
import { columnLabel as baseColumnLabel, filterFields, formatResourceValue, resources } from "../resources.js";

// Columns some pages show instead of their raw resource columns (design).
const COLUMN_OVERRIDES = {
  models: ["name", "provider_name", "capability", "context_length", "route_priority", "price", "gateway_support", "status"],
  "usage-records": ["created_at", "request_id", "user_id", "department_id", "api_key_id", "model_name", "capability", "input_tokens", "output_tokens", "cache_read_tokens", "total_tokens", "cost_amount", "latency_ms", "status", "id"]
};
const LOCAL_LABELS = {
  price: "价格（每千 Token 输入 / 输出）",
  name: "名称"
};

// Shorter headers where the page context already says what is measured.
const HEADER_LABELS = {
  "usage-records": { created_at: "时间", request_id: "请求 ID", model_name: "模型", input_tokens: "输入", output_tokens: "输出", cache_read_tokens: "缓存读", total_tokens: "总 Token", cost_amount: "成本", latency_ms: "耗时", status: "结果" },
  models: { provider_name: "厂商", context_length: "上下文", route_priority: "优先级", gateway_support: "网关", status: "启用" },
  users: { username: "用户", last_login_at: "最近登录" }
};

function headerLabel(resourceKey, column) {
  return HEADER_LABELS[resourceKey]?.[column] || columnLabel(column);
}

function columnsFor(resourceKey, config) {
  return COLUMN_OVERRIDES[resourceKey] || config.columns;
}

// Reference columns show the resolved name, so the header drops the “ID”.
function columnLabel(column) {
  if (LOCAL_LABELS[column] && column === "price") {
    return LOCAL_LABELS[column];
  }
  const label = baseColumnLabel(column);
  return COLUMN_LOOKUPS[column] ? label.replace(/\s*ID$/, "") : label;
}

const DEFAULT_PAGE_SIZE = 20;
const SORTABLE_COLUMNS = ["id", "created_at", "updated_at", "effective_at", "sort_order", "request_count", "total_tokens", "cost_amount"];
const COPYABLE_COLUMNS = new Set(["request_id", "trace_id", "session_id", "export_id", "upload_id", "content_id", "share_code", "retrieval_id", "job_id", "task_id", "run_id", "case_id", "vector_id", "document_id", "kb_id", "dataset_id", "policy_id", "feedback_id", "client_ip"]);
const PAGING_KEYS = ["limit", "offset", "sort"];

const BULK_ACTIONS = {
  users: {
    noun: "用户",
    actions: [
      ["move", "调整部门", "secondary"],
      ["enable", "启用", "secondary"],
      ["disable", "停用", "danger"]
    ],
    run: runUserBulkAction
  },
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
  const summary = resourceKey === "usage-records" ? usageSummary() : null;
  const refreshRows = async () => {
    void summary?.refresh(state.filters);
    await renderRows(tablePanel, resourceKey, config, state);
  };
  // 用户管理: departments are a tree on the left instead of a select (design).
  const withTree = DEPARTMENT_TREE_PAGES.has(resourceKey);
  const specs = withTree ? filterSpecs(resourceKey).filter((spec) => spec.kind !== "department") : filterSpecs(resourceKey);
  const toolbar = await renderFilters(resourceKey, state, refreshRows, { specs });
  const actions = el("div", { className: "toolbar-actions" });
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
  if (config.form) {
    // The primary action goes last, after the secondary ones (design).
    actions.append(createButton(config, () => showCreateModal(resourceKey, config, refreshRows)));
  }
  const parts = [];
  if (context.pageHead !== false) {
    parts.push(pageHead(config.label || resourceKey, PAGE_DESCRIPTIONS[resourceKey] || config.description || "", actions));
  } else {
    toolbar.append(actions);
  }
  if (resourceKey === "models" && context.pageHead !== false) {
    const cards = await providerCards(state, async (providerId) => {
      const picker = toolbar.querySelector('[data-remote-select="provider_id"]');
      if (picker) {
        picker.setValue(providerId ? String(providerId) : "");
        toolbar.requestSubmit();
      }
      cards.querySelectorAll(".provider-card").forEach((card) => {
        const active = Boolean(providerId) && card.dataset.providerId === String(providerId);
        card.classList.toggle("is-active", active);
        card.setAttribute("aria-pressed", active ? "true" : "false");
      });
    });
    parts.push(cards);
  }
  if (withTree) {
    const tree = await departmentNav(state, async () => {
      toolbar.refreshCounts?.();
      await refreshRows();
    });
    content.append(...parts, el("div", { className: "list-with-tree" }, [tree, el("div", { className: "list-with-tree-main" }, [toolbar, tablePanel])]));
  } else {
    content.append(...parts, toolbar, ...(summary ? [summary] : []), tablePanel);
  }
  await refreshRows();
  toolbar.refreshCounts?.();
  if (openCreate && config.form) {
    await showCreateModal(resourceKey, config, refreshRows);
  }
}

const DEPARTMENT_TREE_PAGES = new Set(["users"]);

// Department list on the left of a page: picking one filters by department_id.
async function departmentNav(state, onChange) {
  const nav = el("nav", { className: "list-tree", "aria-label": "按部门筛选" });
  const result = await api("/admin-api/departments/tree", { silent: true }).catch(() => ({ data: [] }));
  const roots = Array.isArray(result.data) ? result.data : [];
  const items = [];
  const add = (node, depth) => {
    items.push({ id: Number(node.id), name: node.name || `#${node.id}`, depth, disabled: node.status === "disabled" });
    (node.children || []).forEach((child) => add(child, depth + 1));
  };
  roots.forEach((root) => add(root, 0));
  const buttons = [];
  const select = async (id) => {
    const next = { ...state.filters, offset: 0 };
    if (id) {
      next.department_id = id;
    } else {
      delete next.department_id;
    }
    state.filters = next;
    mark();
    await onChange();
  };
  const mark = () => {
    const current = Number(state.filters.department_id) || 0;
    buttons.forEach((node) => {
      const active = Number(node.dataset.departmentId || 0) === current;
      node.classList.toggle("is-active", active);
      node.setAttribute("aria-current", active ? "true" : "false");
    });
  };
  const allButton = el("button", { type: "button", className: "list-tree-item is-root", dataset: { departmentId: "" }, text: "全部部门", onClick: () => select(0) });
  buttons.push(allButton);
  const list = el("ul", { className: "list-tree-items" }, [
    el("li", {}, [allButton]),
    ...items.map((item) => {
      const node = el("button", {
        type: "button",
        className: `list-tree-item ${item.disabled ? "is-disabled" : ""}`.trim(),
        style: `--tree-depth: ${item.depth}`,
        dataset: { departmentId: String(item.id) },
        title: item.disabled ? `${item.name}（已停用）` : item.name,
        onClick: () => select(item.id)
      }, [el("span", { className: "list-tree-name", text: item.name })]);
      buttons.push(node);
      return el("li", {}, [node]);
    })
  ]);
  nav.append(el("span", { className: "list-tree-title", text: "部门" }), list);
  if (!items.length) {
    nav.append(el("p", { className: "list-tree-empty", text: "没有可查看的部门" }));
  }
  mark();
  return nav;
}

// “用户管理” → “用户”: buttons and dialogs name the record, not the page.
export function recordNoun(label = "") {
  return String(label).replace(/(管理|配置|列表)$/, "") || label;
}

const PLUS_ICON = "M12 5v14M5 12h14";

export function createButton(config, onClick) {
  const node = button("", onClick, "primary-action");
  node.append(svgIcon(PLUS_ICON), el("span", { text: `新建${recordNoun(config.label || "")}` }));
  return node;
}

// Old-style filter definitions (resources.js filterFields) for pages without
// an entry in LIST_FILTERS: ids become pickers, from/to become a time range.
function legacyFilterSpecs(resourceKey) {
  const remote = { user_id: ["users", "用户"], provider_id: ["providers", "厂商"], model_id: ["models", "模型"], api_key_id: ["api-keys", "API 密钥"] };
  const specs = [];
  for (const config of filterFields(resourceKey)) {
    if (config.name === "from") {
      specs.push({ name: "range", label: "时间", kind: "range" });
    } else if (config.name === "to") {
      continue;
    } else if (config.name === "department_id") {
      specs.push({ name: "department_id", label: "部门", kind: "department" });
    } else if (remote[config.name]) {
      specs.push({ name: config.name, label: remote[config.name][1], kind: "remote", lookup: remote[config.name][0] });
    } else if (config.type === "select") {
      const options = (config.options || []).filter(([value]) => value !== "");
      specs.push({ name: config.name, label: config.label, kind: config.name === "status" && options.length <= 5 ? "segmented" : "select", options });
    } else {
      specs.push({ name: config.name, label: config.label, kind: "search", placeholder: `搜索${config.label}` });
    }
  }
  return specs;
}

export function filterSpecs(resourceKey) {
  return LIST_FILTERS[resourceKey] ? LIST_FILTERS[resourceKey] : legacyFilterSpecs(resourceKey);
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

const SEARCH_ICON = "M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14ZM20 20l-3.5-3.5";

function svgIcon(path, size = 16) {
  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("width", String(size));
  svg.setAttribute("height", String(size));
  svg.setAttribute("fill", "none");
  svg.setAttribute("stroke", "currentColor");
  svg.setAttribute("stroke-width", "2");
  svg.setAttribute("stroke-linecap", "round");
  svg.setAttribute("stroke-linejoin", "round");
  svg.setAttribute("aria-hidden", "true");
  const node = document.createElementNS("http://www.w3.org/2000/svg", "path");
  node.setAttribute("d", path);
  svg.append(node);
  return svg;
}

export { svgIcon };

/**
 * Compact filter toolbar (design: search box, segmented status with counts,
 * inline selects, time presets). Filters apply as soon as they change and
 * stay in the URL; “清除筛选” resets them.
 */
export async function renderFilters(resourceKey, state, onApply, { specs = filterSpecs(resourceKey), counts = true, extra = [] } = {}) {
  const needsDepartments = specs.some((spec) => spec.kind === "department");
  const departmentOptions = needsDepartments ? await departmentSelectOptions() : [];
  const form = el("form", { className: "filters list-toolbar", role: "search", "aria-label": "筛选条件", noValidate: true });
  const main = el("div", { className: "list-toolbar-main" });
  const more = el("div", { className: "list-toolbar-more", hidden: true });
  const readers = [];
  const segmentedRefreshers = [];
  let applyTimer = 0;

  const currentValues = () => Object.assign({}, ...readers.map((read) => read()));
  const filterNames = () => specs.flatMap((spec) => (spec.kind === "range" ? ["from", "to"] : [spec.name]));
  const apply = async () => {
    window.clearTimeout(applyTimer);
    const values = currentValues();
    const names = filterNames();
    const kept = Object.fromEntries(Object.entries(state.filters).filter(([key]) => !names.includes(key)));
    state.filters = { ...kept, ...Object.fromEntries(Object.entries(values).filter(([, value]) => value !== "" && value !== null && value !== undefined)), offset: 0 };
    updateClear();
    refreshCounts();
    await onApply();
  };
  const applySoon = (delay = 0) => {
    window.clearTimeout(applyTimer);
    applyTimer = window.setTimeout(() => void apply(), delay);
  };
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    void apply();
  });

  const controls = [];
  for (const spec of specs) {
    const raw = state.filters[spec.name] ?? "";
    if (spec.kind === "search") {
      const input = el("input", {
        type: "search",
        name: spec.name,
        value: String(raw),
        placeholder: spec.placeholder || `搜索${spec.label}`,
        "aria-label": spec.label,
        autocomplete: "off",
        spellcheck: false
      });
      input.addEventListener("input", () => applySoon(350));
      readers.push(() => ({ [spec.name]: input.value.trim() }));
      controls.push(el("label", { className: "list-search", dataset: { field: spec.name } }, [svgIcon(SEARCH_ICON), input]));
    } else if (spec.kind === "segmented") {
      const hidden = el("input", { type: "hidden", name: spec.name, value: String(raw) });
      const options = [["", "全部"], ...spec.options];
      const buttons = options.map(([value, label]) => el("button", {
        type: "button",
        className: "segmented-option",
        dataset: { value },
        "aria-pressed": String(raw) === value ? "true" : "false",
        onClick: () => {
          hidden.value = value;
          buttons.forEach((node) => node.setAttribute("aria-pressed", node.dataset.value === value ? "true" : "false"));
          void apply();
        }
      }, [el("span", { text: label }), el("span", { className: "segmented-count", "aria-hidden": "true" })]));
      readers.push(() => ({ [spec.name]: hidden.value }));
      controls.push(el("div", { className: "segmented list-segmented", role: "group", "aria-label": spec.label, dataset: { field: spec.name } }, [hidden, ...buttons]));
      if (counts && spec.counts !== false) {
        segmentedRefreshers.push(() => loadSegmentCounts(resourceKey, spec, buttons, state));
      }
    } else if (spec.kind === "select" || spec.kind === "department") {
      const options = spec.kind === "department" ? departmentOptions : spec.options || [];
      const select = el("select", { name: spec.name }, [
        el("option", { value: "", text: spec.emptyLabel || (spec.kind === "department" ? "全部部门" : "全部") }),
        ...options.map(([value, label]) => el("option", { value, text: label }))
      ]);
      select.value = String(raw);
      select.addEventListener("change", () => void apply());
      readers.push(() => ({ [spec.name]: select.value === "" ? "" : (spec.kind === "department" ? Number(select.value) : select.value) }));
      controls.push(el("label", { className: "inline-select", dataset: { field: spec.name } }, [el("span", { text: spec.label }), select]));
    } else if (spec.kind === "remote") {
      const wrapper = field({ name: spec.name, label: spec.label, type: "remote-select", lookup: spec.lookup, optional: true, emptyOption: `全部${spec.label}`, validate: false }, raw);
      wrapper.classList.add("list-remote");
      const pickerInput = wrapper.querySelector(".remote-select-input");
      if (pickerInput) {
        pickerInput.placeholder = `全部${spec.label}`;
      }
      wrapper.addEventListener("change", () => void apply());
      readers.push(() => {
        const value = wrapper.querySelector(`input[name="${spec.name}"]`)?.value || "";
        return { [spec.name]: value === "" ? "" : Number(value) };
      });
      controls.push(wrapper);
    } else if (spec.kind === "range") {
      controls.push(rangeControl(state, readers, apply));
    }
  }
  // Long filter sets keep the first few visible; the rest are one click away.
  const visibleCount = specs.some((spec) => spec.kind === "search") ? VISIBLE_FILTERS : VISIBLE_FILTERS - 1;
  const hiddenControls = controls.slice(visibleCount);
  main.append(...controls.slice(0, visibleCount));
  more.append(...hiddenControls);
  let moreToggle = null;
  if (hiddenControls.length) {
    const activeHidden = specs.slice(visibleCount).some((spec) => spec.kind === "range" ? state.filters.from || state.filters.to : state.filters[spec.name]);
    more.hidden = !activeHidden;
    moreToggle = el("button", {
      type: "button",
      className: "ghost list-more-toggle",
      "aria-expanded": more.hidden ? "false" : "true",
      text: `更多筛选（${hiddenControls.length}）`,
      onClick: () => {
        more.hidden = !more.hidden;
        moreToggle.setAttribute("aria-expanded", more.hidden ? "false" : "true");
      }
    });
    main.append(moreToggle);
  }
  const clearButton = el("button", {
    type: "button",
    className: "ghost list-clear",
    text: "清除筛选",
    onClick: async () => {
      const names = filterNames();
      state.filters = { ...Object.fromEntries(Object.entries(state.filters).filter(([key]) => !names.includes(key))), offset: 0 };
      form.querySelectorAll("input[type=search]").forEach((input) => { input.value = ""; });
      form.querySelectorAll("select").forEach((select) => { select.value = select.options[0]?.value ?? ""; });
      form.querySelectorAll(".list-segmented").forEach((group) => {
        group.querySelector("input[type=hidden]").value = "";
        group.querySelectorAll(".segmented-option").forEach((node) => node.setAttribute("aria-pressed", node.dataset.value === "" ? "true" : "false"));
      });
      form.querySelectorAll(".list-range").forEach((group) => group.reset?.());
      form.querySelectorAll("[data-remote-select]").forEach((picker) => picker.setValue(""));
      updateClear();
      refreshCounts();
      await onApply();
    }
  });
  function updateClear() {
    const names = filterNames();
    clearButton.hidden = !names.some((name) => state.filters[name] !== undefined && state.filters[name] !== "");
  }
  function refreshCounts() {
    segmentedRefreshers.forEach((refresh) => void refresh());
  }
  main.append(clearButton, ...extra);
  form.refreshCounts = refreshCounts;
  // Enter in a text box applies at once.
  form.append(main, more, el("button", { type: "submit", hidden: true, tabIndex: -1, text: "筛选" }));
  updateClear();
  // Counts load after the list itself (the caller triggers them), so the
  // rows are never queued behind them.
  if (specs.length === 0 && extra.length === 0) {
    form.hidden = true;
  }
  return form;
}

// Counts per segment (“全部 128 · 启用 117 · 停用 11”) under the other filters.
async function loadSegmentCounts(resourceKey, spec, buttons, state) {
  const base = Object.fromEntries(Object.entries(withoutPaging(state.filters)).filter(([key]) => key !== spec.name));
  const seq = (buttons[0].__countSeq = (buttons[0].__countSeq || 0) + 1);
  const results = await Promise.all(buttons.map(async (node) => {
    const query = { ...base, limit: 1, offset: 0, with_total: 1 };
    if (node.dataset.value) {
      query[spec.name] = node.dataset.value;
    }
    try {
      const result = await api(`/admin-api/${resourceKey}`, { query, silent: true });
      const total = Number(result.total ?? result.data?.total);
      return Number.isFinite(total) ? total : null;
    } catch {
      return null;
    }
  }));
  if (seq !== buttons[0].__countSeq) {
    return;
  }
  buttons.forEach((node, index) => {
    const count = node.querySelector(".segmented-count");
    if (count) {
      count.textContent = results[index] === null ? "" : formatCount(results[index]);
    }
  });
}

function formatCount(value) {
  return Number(value).toLocaleString("zh-CN");
}

function rangeControl(state, readers, apply) {
  const preset = el("select", { name: "range_preset", "aria-label": "时间范围" }, RANGE_PRESETS.map(([value, label]) => el("option", { value, text: label })));
  const from = el("input", { type: "datetime-local", "aria-label": "开始时间", value: toDateTimeLocal(state.filters.from) });
  const to = el("input", { type: "datetime-local", "aria-label": "结束时间", value: toDateTimeLocal(state.filters.to) });
  const custom = el("span", { className: "list-range-custom" }, [from, el("span", { className: "list-range-sep", text: "至", "aria-hidden": "true" }), to]);
  preset.value = presetFor(state.filters.from || "", state.filters.to || "");
  custom.hidden = preset.value !== "custom";
  preset.addEventListener("change", () => {
    custom.hidden = preset.value !== "custom";
    if (preset.value === "custom") {
      from.focus();
      return;
    }
    void apply();
  });
  from.addEventListener("change", () => void apply());
  to.addEventListener("change", () => void apply());
  readers.push(() => {
    if (preset.value === "custom") {
      return { from: from.value ? fromDateTimeLocal(from.value) : "", to: to.value ? fromDateTimeLocal(to.value) : "" };
    }
    const range = presetRange(preset.value);
    return { from: range?.from || "", to: "" };
  });
  const group = el("div", { className: "list-range", role: "group", "aria-label": "时间" }, [
    el("label", { className: "inline-select" }, [el("span", { text: "时间" }), preset]),
    custom
  ]);
  group.reset = () => {
    preset.value = "";
    from.value = "";
    to.value = "";
    custom.hidden = true;
  };
  return group;
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
  const context = await renderContext(resourceKey, rows, columnsFor(resourceKey, config) || []);
  if (resourceKey === "models") {
    context.pricesByModel = await modelPrices(rows);
  }
  if (seq !== parent.__renderSeq) {
    return;
  }
  parent.removeAttribute("aria-busy");
  const onRefresh = async () => {
    clearLookupCache();
    await renderRows(parent, resourceKey, config, state);
  };
  context.refresh = onRefresh;
  if (rows.length === 0) {
    const hasFilters = Object.keys(withoutPaging(state.filters)).some((key) => state.filters[key] !== "" && state.filters[key] !== undefined);
    parent.replaceChildren(emptyState({
      title: hasFilters ? "没有符合条件的记录" : "暂无数据",
      description: hasFilters ? "可以调整或清除筛选条件后再试。" : config.form ? `点击右上角“新建${recordNoun(config.label || "")}”创建第一条。` : ""
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
  users: ["id", "created_at"],
  "usage-records": ["department_id", "api_key_id", "capability", "cache_read_tokens", "id"],
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
    panel.append(el("label", {}, [checkbox, el("span", { text: headerLabel(resourceKey, column) })]));
  }
  const toggle = el("button", { type: "button", className: "icon-action column-menu-toggle", "aria-label": t("table.columns"), title: t("table.columns"), "aria-expanded": "false" });
  toggle.append(svgIcon(COLUMNS_ICON));
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
      placeFloating(panel, toggle);
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

// Users have no batch endpoint: the selected users are updated one by one
// through the same endpoints as the row actions, and failures are reported.
async function runUserBulkAction(action, ids, onRefresh) {
  const label = { move: "调整部门", enable: "启用", disable: "停用" }[action];
  let body = null;
  let path = (id) => `/admin-api/users/${id}/status`;
  let method = "PATCH";
  if (action === "move") {
    const options = await departmentSelectOptions();
    const select = el("select", { name: "department_id" }, options.map(([value, text]) => el("option", { value, text })));
    const ok = await confirmDialog({
      title: `调整部门 · ${ids.length} 个用户`,
      message: "选中的用户将移动到下面的部门，之后的用量计入新部门。",
      body: el("label", { className: "field" }, [el("span", { className: "field-label", text: "目标部门" }), select]),
      confirmLabel: "调整部门",
      tone: "primary"
    });
    if (!ok) {
      return;
    }
    body = { department_id: Number(select.value) };
    path = (id) => `/admin-api/users/${id}/department`;
    method = "POST";
  } else {
    const ok = await confirmDialog({
      title: `${label} · ${ids.length} 个用户`,
      message: action === "disable" ? "停用后这些用户的 API 密钥将无法调用，可以随时重新启用。" : "启用后这些用户可以继续使用其 API 密钥。",
      confirmLabel: label,
      tone: action === "disable" ? "danger" : "primary"
    });
    if (!ok) {
      return;
    }
    body = { status: action === "disable" ? "disabled" : "active" };
  }
  const outcomes = await Promise.allSettled(ids.map((id) => api(path(id), { method, body: JSON.stringify(body), silent: true })));
  const failed = outcomes.filter((outcome) => outcome.status === "rejected");
  if (failed.length) {
    toast(`${label}：${ids.length - failed.length} 个成功，${failed.length} 个失败（${adminErrorMessage(failed[0].reason, "请确认权限")}）`, { tone: "error" });
  } else {
    toast(`已${label} ${ids.length} 个用户`, { tone: "success" });
  }
  await onRefresh();
}

async function runBulkAction(resourceKey, action, selection, onRefresh) {
  const bulk = BULK_ACTIONS[resourceKey];
  if (bulk.run) {
    const ids = [...selection.selected];
    if (ids.length) {
      await bulk.run(action, ids, onRefresh);
    }
    return;
  }
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
const INLINE_ACTION_PRIORITY = ["展开失败原因", "编辑", "详情", "大屏1", "大屏2", "内容", "诊断", "下载", "价格明细", "拉取模型", "放行 IP"];

export function arrangeRowActions(ops, { maxInline = 3, keep = 2 } = {}) {
  if (ops.length <= maxInline) {
    return ops;
  }
  const label = (node) => (node.getAttribute?.("aria-label") || node.textContent || "").trim();
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

const COLUMNS_ICON = "M4 6h16M4 12h16M4 18h16M9 4v4M15 10v4M7 16v4";
const PENCIL_ICON = "M4 20h4L19 9l-4-4L4 16v4ZM13.5 6.5l4 4";
const MORE_ICON = "M5 12h.01M12 12h.01M19 12h.01";

// Row edit is an icon button; its accessible name stays “编辑”.
export function editButton(name, onClick) {
  const node = button("", onClick, "icon-action row-edit", { "aria-label": "编辑", title: `编辑 ${name}` });
  node.append(svgIcon(PENCIL_ICON));
  return node;
}

// Menus inside the scrolling table are positioned against the viewport so the
// table's overflow never clips them.
function openLogsFor(row) {
  if (row.request_id) {
    window.location.hash = `#/security/logs?request_id=${encodeURIComponent(row.request_id)}`;
  }
}

function placeFloating(panel, anchor) {
  const rect = anchor.getBoundingClientRect();
  panel.style.position = "fixed";
  panel.style.right = "auto";
  panel.style.top = `${Math.round(rect.bottom + 4)}px`;
  const width = panel.offsetWidth || 180;
  panel.style.left = `${Math.round(Math.max(8, Math.min(window.innerWidth - width - 8, rect.right - width)))}px`;
  const height = panel.offsetHeight;
  if (rect.bottom + 4 + height > window.innerHeight - 8 && rect.top - 4 - height > 8) {
    panel.style.top = `${Math.round(rect.top - 4 - height)}px`;
  }
  const close = () => {
    if (!panel.hidden) {
      panel.hidden = true;
      anchor.setAttribute("aria-expanded", "false");
    }
    window.removeEventListener("scroll", close, true);
    window.removeEventListener("resize", close);
  };
  window.addEventListener("scroll", close, true);
  window.addEventListener("resize", close);
}

let rowMenuSeq = 0;
function rowMoreMenu(items) {
  const id = `row-more-${++rowMenuSeq}`;
  const menu = el("div", { id, className: "row-more-menu", role: "menu", hidden: true }, items);
  const toggle = el("button", {
    type: "button",
    className: "row-more-toggle icon-action",
    "aria-label": "更多",
    title: "更多操作",
    "aria-haspopup": "menu",
    "aria-expanded": "false",
    "aria-controls": id
  });
  toggle.append(svgIcon(MORE_ICON, 18));
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
    placeFloating(menu, toggle);
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
  const allColumns = columnsFor(resourceKey, config) || [...new Set(rows.flatMap((row) => Object.keys(row)).slice(0, 12))];
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
          text: headerLabel(resourceKey, column),
          title: `${headerLabel(resourceKey, column)}：${current === "descending" ? t("table.sortAsc") : t("table.sortDesc")}`,
          onClick: () => options.onSort(current === "descending" ? `${column}_asc` : `${column}_desc`)
        }));
      } else {
        th.textContent = headerLabel(resourceKey, column);
      }
      headRow.append(th);
    }
    headRow.append(el("th", { className: "table-cell table-cell--actions", scope: "col" }, [
      el("span", { className: "visually-hidden", text: "操作" }),
      columnMenu(resourceKey, allColumns, draw)
    ]));
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
      const ops = rowActions(resourceKey, config, row, onRefresh, context);
      let detailRow = null;
      if (resourceKey === "usage-records" && row.status && row.status !== "success") {
        tr.classList.add("is-failed");
        const span = columns.length + (selection ? 2 : 1);
        detailRow = el("tr", { className: "row-detail", hidden: true }, [
          el("td", { colSpan: span }, [usageFailureDetails(row, {
            apiKeyLabel: referenceCellLabel("api_key_id", row.api_key_id, context) || "",
            onContent: () => showUsageContent(row),
            onLogs: () => openLogsFor(row)
          })])
        ]);
        const toggle = el("button", {
          type: "button",
          className: "icon-action row-expand",
          "aria-expanded": "false",
          "aria-label": "展开失败原因",
          title: "展开失败原因",
          onClick: () => {
            const open = detailRow.hidden;
            detailRow.hidden = !open;
            toggle.setAttribute("aria-expanded", open ? "true" : "false");
            tr.classList.toggle("is-expanded", open);
          }
        });
        toggle.append(svgIcon("M6 9l6 6 6-6"));
        ops.unshift(toggle);
      }
      tr.append(el("td", { className: "table-cell table-cell--actions" }, [
        el("div", { className: "row-actions" }, arrangeRowActions(ops, resourceKey === "usage-records" ? { maxInline: 4, keep: 3 } : {}))
      ]));
      body.append(tr);
      if (detailRow) {
        body.append(detailRow);
      }
    }
    table.append(el("thead", {}, [headRow]), body);

    const parts = [];
    if (selection) {
      // Design: an inverse bar that appears once rows are selected.
      const status = el("span", { className: "selection-status", text: selection.summary() });
      const buttons = bulk.actions.map(([code, label, variant]) => button(label, () => runBulkAction(resourceKey, code, selection, onRefresh), variant === "danger" ? "danger" : "on-inverse", { disabled: selection.selected.size === 0 }));
      const bar = el("div", { className: `selection-bar ${resourceKey === "models" ? "model-bulk-actions" : ""}`.trim(), role: "region", "aria-label": "批量操作", hidden: selection.selected.size === 0 }, [
        status,
        el("div", { className: "selection-actions" }, [
          ...buttons,
          el("button", { type: "button", className: "on-inverse selection-clear", text: "取消选择", onClick: () => {
            selection.setAll(false);
            body.querySelectorAll(".row-select").forEach((box) => { box.checked = false; });
          } })
        ])
      ]);
      selection.onChange(() => {
        status.textContent = selection.summary();
        bar.hidden = selection.selected.size === 0;
        buttons.forEach((item) => {
          item.disabled = selection.selected.size === 0;
        });
        if (selectAll) {
          selectAll.checked = selection.ids.length > 0 && selection.selected.size === selection.ids.length;
          selectAll.indeterminate = selection.selected.size > 0 && selection.selected.size < selection.ids.length;
        }
        body.querySelectorAll("tr").forEach((tr) => {
          const box = tr.querySelector(".row-select");
          tr.classList.toggle("is-selected", Boolean(box?.checked));
        });
      });
      parts.push(bar);
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
    ops.push(editButton(name, () => showEditModal(resourceKey, config, row, onRefresh)));
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

const RELATIVE_TIME_COLUMNS = new Set(["last_login_at", "last_used_at", "last_called_at", "last_seen_at"]);

// “今天 09:41 / 昨天 17:26 / 10-25 15:10 / 2025-12-01”: activity columns read
// at a glance; the full timestamp stays in the tooltip.
export function friendlyTime(value) {
  const text = String(value || "");
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  if (!match) {
    return formatDateTime(value);
  }
  const [, year, month, day, hour, minute] = match;
  const date = new Date(Number(year), Number(month) - 1, Number(day));
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const days = Math.round((today - date) / 86_400_000);
  if (days === 0) return `今天 ${hour}:${minute}`;
  if (days === 1) return `昨天 ${hour}:${minute}`;
  if (Number(year) === today.getFullYear()) return `${month}-${day} ${hour}:${minute}`;
  return `${year}-${month}-${day}`;
}

function initialAvatar(name) {
  return el("span", { className: "cell-avatar", "aria-hidden": "true", text: String(name || "?").slice(0, 1).toUpperCase() });
}

export function renderTableCell(resourceKey, column, row, context) {
  if (resourceKey === "models") {
    if (column === "name") return modelNameCell(row);
    if (column === "price") return priceCell(context.pricesByModel?.get(Number(row.id)));
    if (column === "context_length") return contextCell(row.context_length);
    if (column === "capability") return el("span", { className: "cell-tag", text: formatResourceValue("capability", row.capability) });
    if (column === "status" && context.refresh) return modelSwitch(row, context.refresh);
    if (column === "gateway_support") {
      return row.gateway_supported === false
        ? el("span", { className: "cell-tag is-warn", text: "不支持", title: row.unsupported_message || row.unsupported_reason || "" })
        : el("span", { className: "cell-muted", text: "支持" });
    }
  }
  if ((resourceKey === "users" || resourceKey === "admin-users") && column === "username" && row.username) {
    return el("span", { className: "cell-person" }, [initialAvatar(row.username), el("span", { className: "cell-primary", text: row.username })]);
  }
  if (column === "role" && row.role) {
    return el("span", { className: "cell-tag", text: formatResourceValue("role", row.role) });
  }
  if (resourceKey === "usage-records") {
    if (column === "created_at") return el("span", { className: "cell-mono", text: friendlyTime(row.created_at), title: formatDateTime(row.created_at) });
    if (column === "latency_ms") return el("span", { text: latencyText(row.latency_ms), title: row.latency_ms ? `${row.latency_ms} ms` : null });
    if (column === "cost_amount") return el("span", { text: costText(row.cost_amount), title: row.cost_amount ?? null });
    if (["input_tokens", "output_tokens", "cache_read_tokens", "total_tokens"].includes(column)) {
      const value = Number(row[column] || 0);
      return el("span", { className: column === "total_tokens" ? "cell-strong" : "", text: value >= 100000 ? compact(value) : value.toLocaleString("zh-CN"), title: value.toLocaleString("zh-CN") });
    }
  }
  if (RELATIVE_TIME_COLUMNS.has(column)) {
    return row[column] ? el("span", { text: friendlyTime(row[column]), title: formatDateTime(row[column]) }) : el("span", { className: "cell-muted", text: "从未" });
  }
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
  if (resourceKey === "api-key-model-quotas" && (column === "api_key_id" || column === "model_id")) {
    // Short name in the cell; the full label (owner, upstream name) as tooltip.
    const full = column === "api_key_id" ? modelQuotaApiKeyLabel(row[column], context) : modelQuotaModelLabel(row[column], context);
    const target = context.lookups?.[column === "api_key_id" ? "api-keys" : "models"]?.get(Number(row[column]));
    return target?.name ? el("span", { className: "ref-name", text: target.name, title: full }) : full;
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
    // Tables show the plain name; role, owner and id stay in the tooltip.
    const type = COLUMN_LOOKUPS[column];
    const target = context.lookups?.[type]?.get(Number(row[column]));
    const short = target ? (target.name || target.username || target.external_name || "") : "";
    return short ? el("span", { className: "ref-name", text: short, title: reference }) : reference;
  }
  if (isDateColumn(column)) {
    return formatDateTime(row[column]);
  }
  return formatResourceValue(column, row[column]);
}
