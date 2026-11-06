import { getLocale, localize } from "../i18n.js";
import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, setText, showModal, statusMessage } from "../dom.js";
import { showCreateModal, showEditModal } from "../lib/forms.js";
import { setErrorOutput, showOperationResult } from "../lib/output.js";
import { apiKeyDepartmentLabel, apiKeyModelLabels, apiKeyUserLabel, departmentSelectOptions, renderContext } from "../lib/references.js";
import { emptyState, errorState, loadingState } from "../components/feedback.js";
import { pager, PAGE_SIZES } from "../components/pager.js";
import { arrangeRowActions, editButton, friendlyTime, pageHead, renderTableCell } from "../lib/resource-table.js";
import { columnLabel, formatResourceValue, resources } from "../resources.js";
import { formatDateTime, utcStamp } from "../lib/format.js";
import { navigateTo } from "../lib/navigation.js";
import { hasCapability } from "../core/session.js";
import { confirmDialog } from "../components/modal.js";
import { API_KEY_DISABLED_BY_LABELS, apiKeyManagementBadge, showApiKeyMcpGrants, showApiKeyQuota, showApiKeyRotate } from "../lib/api-key-tools.js";


function apiKeyActionIcon(paths) {
  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("width", "18");
  svg.setAttribute("height", "18");
  svg.setAttribute("fill", "none");
  svg.setAttribute("stroke", "currentColor");
  svg.setAttribute("stroke-width", "2");
  svg.setAttribute("stroke-linecap", "round");
  svg.setAttribute("stroke-linejoin", "round");
  svg.setAttribute("aria-hidden", "true");
  for (const d of paths) {
    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", d);
    svg.append(path);
  }
  return svg;
}

const STATUS_SEGMENTS = [["", localize("全部")], ["active", localize("启用")], ["disabled", localize("停用")]];

function pad2(value) {
  return String(value).padStart(2, "0");
}

// Local midnight converted to the UTC timestamps the API compares against.
const serverStamp = utcStamp;

function compactTokens(value) {
  const number = apiKeyNumber(value);
  if (number >= 1e6) return `${(number / 1e6).toFixed(number >= 1e7 ? 0 : 1)}M`;
  if (number >= 1e3) return `${(number / 1e3).toFixed(number >= 1e4 ? 0 : 1)}K`;
  return number.toLocaleString(getLocale());
}

// Today's tokens per key (one request for the page; the ranking covers the
// 20 busiest keys, the rest show “—”).
async function todayUsageByKey() {
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  try {
    const result = await api("/admin-api/dashboard/rankings", { query: { dimension: "api_key", sort: "total_tokens", from: serverStamp(start) }, silent: true });
    return new Map((Array.isArray(result.data) ? result.data : []).filter((row) => row.id).map((row) => [Number(row.id), row]));
  } catch {
    return new Map();
  }
}

export async function renderApiKeyPage(content, initialFilters = {}, context = {}) {
  clear(content);
  const config = resources["api-keys"];
  const { offset: initialOffset = 0, limit: initialLimit = 20, key: initialKey = "", ...initialRest } = initialFilters;
  const state = {
    filters: { sort: "created_at_desc", ...initialRest },
    pageSize: PAGE_SIZES.includes(Number(initialLimit)) ? Number(initialLimit) : 20,
    offset: Math.max(0, Number(initialOffset) || 0),
    openKey: initialKey ? Number(initialKey) : 0
  };
  const departmentOptions = await departmentSelectOptions();
  const page = el("section", { className: "api-key-page api-key-workspace" });
  const tableHost = el("section", { className: "panel table-panel api-key-table-panel", "aria-live": "polite" }, [loadingState(localize("正在加载 API 密钥…"))]);
  const drawer = el("aside", { className: "api-key-drawer", "aria-label": localize("密钥详情"), hidden: true });

  const keywordInput = el("input", {
    type: "search",
    name: "keyword",
    placeholder: localize("按名称、员工或密钥尾号搜索"),
    value: state.filters.keyword || "",
    "aria-label": localize("搜索 API 密钥"),
    autocomplete: "off"
  });
  let keywordTimer = 0;
  keywordInput.addEventListener("input", () => {
    window.clearTimeout(keywordTimer);
    keywordTimer = window.setTimeout(() => void applyFilters(), 350);
  });
  const statusButtons = STATUS_SEGMENTS.map(([value, label]) => el("button", {
    type: "button",
    className: "segmented-option",
    dataset: { value },
    "aria-pressed": String(state.filters.status || "") === value ? "true" : "false",
    onClick: () => {
      if (value) state.filters.status = value; else delete state.filters.status;
      statusButtons.forEach((node) => node.setAttribute("aria-pressed", node.dataset.value === value ? "true" : "false"));
      void applyFilters();
    }
  }, [el("span", { text: label }), el("span", { className: "segmented-count", "aria-hidden": "true" })]));
  const departmentSelect = el("select", { name: "department_id", "aria-label": localize("部门") }, [
    el("option", { value: "", text: localize("全部部门") }),
    ...departmentOptions.map(([value, label]) => el("option", { value, text: label }))
  ]);
  departmentSelect.value = state.filters.department_id || "";
  departmentSelect.addEventListener("change", () => void applyFilters());
  const filterToolbar = el("form", { className: "filters list-toolbar api-key-filter-toolbar", role: "search", "aria-label": localize("筛选 API 密钥"), onSubmit: (event) => {
    event.preventDefault();
    void applyFilters();
  } }, [
    el("div", { className: "list-toolbar-main" }, [
      el("label", { className: "list-search" }, [searchIcon(), keywordInput]),
      el("div", { className: "segmented list-segmented", role: "group", "aria-label": localize("状态") }, statusButtons),
      el("label", { className: "inline-select" }, [el("span", { text: localize("部门") }), departmentSelect])
    ])
  ]);
  const createButton = button("", () => showCreateModal("api-keys", config, refresh), "api-key-create-button primary-action");
  createButton.append(apiKeyActionIcon(["M12 5v14", "M5 12h14"]), el("span", { text: localize("新建密钥") }));
  createButton.title = localize("新建 API 密钥");
  page.append(
    pageHead(localize("API 密钥"), localize("为应用和员工签发访问网关的密钥，用量按密钥计量。密钥明文只在新建或轮换后显示一次。"), el("div", {}, [createButton])),
    filterToolbar,
    el("div", { className: "api-key-layout" }, [tableHost, drawer])
  );

  async function loadCounts() {
    const base = { ...state.filters };
    delete base.status;
    delete base.sort;
    const totals = await Promise.all(STATUS_SEGMENTS.map(async ([value]) => {
      try {
        const result = await api("/admin-api/api-keys", { query: { ...base, ...(value ? { status: value } : {}), limit: 1, offset: 0 }, silent: true });
        const total = Number(result.total ?? result.data?.total);
        return Number.isFinite(total) ? total : null;
      } catch {
        return null;
      }
    }));
    statusButtons.forEach((node, index) => {
      node.querySelector(".segmented-count").textContent = totals[index] === null ? "" : totals[index].toLocaleString(getLocale());
    });
  }

  function persist() {
    context.setFilters?.({
      ...state.filters,
      ...(state.offset ? { offset: state.offset } : {}),
      ...(state.pageSize !== 20 ? { limit: state.pageSize } : {}),
      ...(state.openKey ? { key: state.openKey } : {})
    });
  }

  async function refresh() {
    persist();
    tableHost.setAttribute("aria-busy", "true");
    let result;
    let usage;
    try {
      [result, usage] = await Promise.all([
        api("/admin-api/api-keys", { query: { ...state.filters, limit: state.pageSize, offset: state.offset }, silent: true }),
        todayUsageByKey()
      ]);
    } catch (error) {
      tableHost.removeAttribute("aria-busy");
      tableHost.replaceChildren(errorState(error, { fallback: localize("API 密钥列表加载失败"), onRetry: refresh }));
      return;
    }
    tableHost.removeAttribute("aria-busy");
    const pageData = Array.isArray(result.data)
      ? { items: result.data, total: result.total ?? result.data.length, limit: state.pageSize, offset: state.offset }
      : (result.data || {});
    const rows = Array.isArray(pageData.items) ? pageData.items : [];
    const total = Math.max(0, Number(pageData.total) || 0);
    const pageSize = Math.max(1, Number(pageData.limit) || state.pageSize);
    const offset = Math.max(0, Number(pageData.offset) || 0);
    const lastOffset = Math.max(0, (Math.ceil(total / pageSize) - 1) * pageSize);
    if (rows.length === 0 && total > 0 && offset > lastOffset && state.offset !== lastOffset) {
      state.offset = lastOffset;
      await refresh();
      return;
    }
    const lookups = await renderContext("api-keys", rows, ["user_id", "department_id"]);
    lookups.todayUsage = usage;
    const hasFilters = Boolean(state.filters.keyword || state.filters.department_id || state.filters.status);
    tableHost.replaceChildren(
      rows.length === 0
        ? emptyState({
          title: hasFilters ? localize("没有符合条件的 API 密钥") : localize("暂无 API 密钥"),
          description: hasFilters ? localize("可以调整搜索词、状态或部门后重试。") : localize("点击右上角“新建密钥”为员工创建第一把密钥。")
        })
        : renderApiKeyTable(rows, config, refresh, lookups, { onOpen: openDrawer, activeId: state.openKey }),
      pager({
        total,
        limit: pageSize,
        offset,
        count: rows.length,
        label: localize("API 密钥分页"),
        onChange: async ({ offset: nextOffset, limit: nextLimit }) => {
          state.offset = nextOffset;
          state.pageSize = nextLimit;
          await refresh();
        }
      })
    );
    if (state.openKey) {
      const row = rows.find((item) => Number(item.id) === state.openKey);
      if (row) {
        void renderApiKeyDrawer(drawer, row, config, lookups, { onClose: closeDrawer, onRefresh: refresh });
      } else {
        closeDrawer();
      }
    }
  }

  function openDrawer(row) {
    state.openKey = Number(row.id);
    persist();
    tableHost.querySelectorAll("tbody tr").forEach((tr) => tr.classList.toggle("is-active", Number(tr.dataset.keyId) === state.openKey));
    void renderApiKeyDrawer(drawer, row, config, null, { onClose: closeDrawer, onRefresh: refresh });
  }

  function closeDrawer() {
    state.openKey = 0;
    drawer.hidden = true;
    page.classList.remove("has-drawer");
    tableHost.querySelectorAll("tbody tr.is-active").forEach((tr) => tr.classList.remove("is-active"));
    persist();
  }
  page.addEventListener("api-key-drawer-open", () => page.classList.add("has-drawer"));

  async function applyFilters() {
    const keyword = keywordInput.value.trim();
    const departmentId = departmentSelect.value;
    if (keyword) state.filters.keyword = keyword; else delete state.filters.keyword;
    if (departmentId) state.filters.department_id = departmentId; else delete state.filters.department_id;
    state.offset = 0;
    void loadCounts();
    await refresh();
  }

  content.append(page);
  await refresh();
  void loadCounts();
}

function searchIcon() {
  return apiKeyActionIcon(["M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14Z", "M20 20l-3.5-3.5"]);
}

// Model names only (the upstream names stay in the edit form).
function apiKeyModelNames(value, context = {}) {
  const full = apiKeyModelLabels(value, context);
  if (!full) return "";
  return full.split(", ").map((label) => label.split(" / ")[0]).join("、");
}

function apiKeyNumber(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number : 0;
}

function renderApiKeyTable(rows, config, onRefresh, context = {}, { onOpen = null, activeId = 0 } = {}) {
  const selectedApiKeyIds = new Set();
  let apiKeySelectAll = null;
  let apiKeySelectionStatus = null;
  const apiKeyBulkButtons = [];
  const selectableRows = rows.filter((row) => row.id);
  const usageMax = Math.max(1, ...rows.map((row) => apiKeyNumber(context.todayUsage?.get(Number(row.id))?.total_tokens)));
  const bulkActions = el("div", { className: "selection-bar api-key-bulk-actions", role: "region", "aria-label": localize("批量操作"), hidden: true });
  const updateApiKeySelection = () => {
    const selectedCount = selectedApiKeyIds.size;
    if (apiKeySelectionStatus) {
      setText(apiKeySelectionStatus, selectedCount > 0 ? localize`已选择 ${selectedCount} 个密钥` : localize("未选择密钥"));
    }
    bulkActions.hidden = selectedCount === 0;
    for (const actionButton of apiKeyBulkButtons) {
      actionButton.disabled = selectedCount === 0;
    }
    if (apiKeySelectAll) {
      apiKeySelectAll.checked = selectableRows.length > 0 && selectedCount === selectableRows.length;
      apiKeySelectAll.indeterminate = selectedCount > 0 && selectedCount < selectableRows.length;
    }
  };
  const table = el("table", { className: "resource-table resource-table--api-keys" });
  apiKeySelectAll = el("input", {
    type: "checkbox",
    className: "api-key-select-all",
    title: localize("选择当前页 API 密钥"),
    "aria-label": localize("选择当前页 API 密钥"),
    onChange: (event) => {
      const checked = event.currentTarget.checked;
      selectedApiKeyIds.clear();
      for (const row of selectableRows) {
        if (checked) selectedApiKeyIds.add(Number(row.id));
      }
      for (const checkbox of table.querySelectorAll(".api-key-row-select")) {
        checkbox.checked = checked;
      }
      updateApiKeySelection();
    }
  });
  table.append(el("thead", {}, [
    el("tr", {}, [
      el("th", { className: "table-cell table-cell--select", scope: "col" }, [apiKeySelectAll]),
      el("th", { className: "table-cell table-cell--name", scope: "col", text: localize("名称") }),
      el("th", { className: "table-cell table-cell--key", scope: "col", text: localize("密钥") }),
      el("th", { className: "table-cell table-cell--owner", scope: "col", text: localize("归属") }),
      el("th", { className: "table-cell table-cell--today", scope: "col", text: localize("今日 Token") }),
      el("th", { className: "table-cell table-cell--status", scope: "col", text: localize("状态") }),
      el("th", { className: "table-cell table-cell--created_at", scope: "col", text: localize("创建") }),
      el("th", { className: "table-cell table-cell--actions", scope: "col" }, [el("span", { className: "visually-hidden", text: localize("操作") })])
    ])
  ]));
  table.append(el("tbody", {}, rows.map((row) => {
    const checkbox = el("input", {
      type: "checkbox",
      className: "api-key-row-select",
      value: row.id,
      title: localize`选择 API 密钥 ${row.name || row.id}`,
      "aria-label": localize`选择 API 密钥 ${row.name || row.id}`,
      onChange: (event) => {
        const id = Number(event.currentTarget.value);
        if (event.currentTarget.checked) selectedApiKeyIds.add(id); else selectedApiKeyIds.delete(id);
        updateApiKeySelection();
      }
    });
    const ops = [];
    if (config.form && row.id) {
      ops.push(editButton(row.name || `#${row.id}`, () => showEditModal("api-keys", config, row, onRefresh)));
      ops.push(button(localize("知识库"), () => showApiKeyRagBindings(row, onRefresh), "secondary"));
    }
    if (row.id) {
      ops.push(button(localize("额度"), () => showApiKeyQuota(row), "secondary api-key-quota-button"));
      ops.push(button(localize("MCP 授权"), () => showApiKeyMcpGrants(row), "secondary api-key-mcp-button"));
    }
    if (row.id && hasCapability("conversation_logs.read")) {
      // Super administrators only: per-key conversation log switch.
      ops.push(button(localize("会话记录"), () => showApiKeyConversationLog(row, onRefresh), "secondary api-key-conversation-button"));
    }
    if (row.id && row.status === "active") {
      ops.push(button(localize("轮换"), () => showApiKeyRotate(row, onRefresh), "secondary api-key-rotate-button"));
      if (hasCapability("operations.read")) {
        // Opens 会话测试 with this key preselected (gateway trial mode).
        ops.push(button(localize("测试"), () => navigateTo("admin-chat-sessions", { api_key_id: row.id }), "secondary api-key-test-button"));
      }
    }
    if (row.id && row.status !== "disabled" && row.status !== "deleted") {
      ops.push(button(localize("删除"), () => deleteApiKey(row, onRefresh), "danger"));
    }
    const models = apiKeyModelNames(row.model_ids, context);
    const badge = apiKeyManagementBadge(row);
    const today = context.todayUsage?.get(Number(row.id));
    const todayTokens = apiKeyNumber(today?.total_tokens);
    const owner = [apiKeyCellText("department_name", row, context), apiKeyCellText("user_name", row, context)].filter(Boolean);
    const disabled = row.status !== "active";
    const nameCell = el("td", { className: "table-cell table-cell--name" }, [
      el("span", { className: "cell-stack" }, [
        el("span", { className: "api-key-name-line" }, [
          badge,
          row.conversation_log_active && hasCapability("conversation_logs.read")
            ? el("span", { className: "status-pill is-pending api-key-conversation-badge", text: localize("记录对话"), title: localize("这个密钥的对话正在被记录（会话记录）") })
            : null,
          el("button", { type: "button", className: "link-button api-key-open", text: row.name || `#${row.id}`, title: localize("查看密钥详情"), onClick: () => onOpen?.(row) })
        ].filter(Boolean)),
        models ? el("span", { className: "cell-secondary", text: models, title: models }) : null
      ].filter(Boolean))
    ]);
    const tr = el("tr", { className: `${disabled ? "is-muted" : ""} ${Number(row.id) === activeId ? "is-active" : ""}`.trim(), dataset: { keyId: String(row.id) } }, [
      el("td", { className: "table-cell table-cell--select" }, [checkbox]),
      nameCell,
      el("td", { className: "table-cell table-cell--key cell-mono", text: apiKeyDisplay(row) || "—" }),
      el("td", { className: "table-cell table-cell--owner" }, [
        el("span", { className: "cell-stack" }, [
          el("span", { text: owner[0] || "—" }),
          owner[1] ? el("span", { className: "cell-secondary", text: owner[1] }) : null
        ].filter(Boolean))
      ]),
      el("td", { className: "table-cell table-cell--today" }, [
        today
          ? el("span", { className: "usage-cell", title: localize`${todayTokens.toLocaleString(getLocale())} Token · ${apiKeyNumber(today.request_count).toLocaleString(getLocale())} 次调用` }, [
            el("span", { className: "cell-mono", text: compactTokens(todayTokens) }),
            el("span", { className: "usage-cell-bar", "aria-hidden": "true" }, [el("span", { style: `width: ${Math.max(2, Math.round(todayTokens / usageMax * 100))}%` })])
          ])
          : el("span", { className: "cell-muted", text: "—" })
      ]),
      el("td", { className: "table-cell table-cell--status" }, [
        el("span", { className: `status-pill ${row.status === "active" ? "is-positive" : "is-muted"}`, text: apiKeyStatusText(row.status, row.disabled_by) })
      ]),
      el("td", { className: "table-cell table-cell--created_at cell-mono", text: friendlyTime(row.created_at), title: formatDateTime(row.created_at) }),
      el("td", { className: "table-cell table-cell--actions" }, [el("div", { className: "row-actions api-key-row-actions" }, arrangeRowActions(ops))])
    ]);
    tr.addEventListener("click", (event) => {
      if (event.target.closest("button, a, input, label, .row-more")) return;
      onOpen?.(row);
    });
    return tr;
  })));
  apiKeySelectionStatus = el("span", { className: "selection-status api-key-bulk-selection", text: localize("未选择密钥") });
  const enableButton = button(localize("批量启用"), () => runApiKeyBatchAction("enable", selectedApiKeyIds, onRefresh), "on-inverse");
  const disableButton = button(localize("批量暂停"), () => runApiKeyBatchAction("disable", selectedApiKeyIds, onRefresh), "on-inverse");
  const deleteButton = button(localize("批量删除"), () => runApiKeyBatchAction("delete", selectedApiKeyIds, onRefresh), "danger");
  apiKeyBulkButtons.push(enableButton, disableButton, deleteButton);
  bulkActions.append(apiKeySelectionStatus, el("div", { className: "selection-actions" }, [enableButton, disableButton, deleteButton]));
  updateApiKeySelection();
  return el("div", { className: "api-key-table-wrap resource-table-host" }, [bulkActions, el("div", { className: "table-scroll" }, [table])]);
}

async function deleteApiKey(row, onRefresh) {
  if (!await confirmDialog({ title: localize("删除 API 密钥"), message: localize("删除后该密钥立即无法继续调用，且无法恢复。"), details: [[localize("API 密钥"), `${row.name || row.id}`], [localize("密钥"), apiKeyDisplay(row) || "-"]], confirmLabel: localize("删除"), requireText: String(row.name || row.id) })) {
    return;
  }
  try {
    await api(`/admin-api/api-keys/${row.id}`, { method: "DELETE" });
    await onRefresh();
  } catch (error) {
    showModal(localize("删除 API 密钥"), statusMessage(adminErrorMessage(error, localize("删除 API 密钥失败")), "error"));
  }
}

// Right-hand drawer (design): who owns the key, quota use and 14-day tokens.
async function renderApiKeyDrawer(drawer, row, config, context, { onClose, onRefresh }) {
  const seq = (drawer.__seq = (drawer.__seq || 0) + 1);
  const lookups = context || await renderContext("api-keys", [row], ["user_id", "department_id"]);
  if (seq !== drawer.__seq) return;
  const closeButton = button("", () => onClose(), "icon-action api-key-drawer-close", { "aria-label": localize("关闭详情"), title: localize("关闭详情") });
  closeButton.append(apiKeyActionIcon(["M6 6l12 12", "M18 6L6 18"]));
  const quotaHost = el("section", { className: "api-key-drawer-section" }, [el("h3", { text: localize("额度") }), loadingState(localize("正在读取额度…"), { rows: 1 })]);
  const trendHost = el("section", { className: "api-key-drawer-section" }, [el("h3", { text: localize("近 14 天 Token") }), loadingState(localize("正在读取用量…"), { rows: 1 })]);
  const facts = [
    [localize("归属部门"), apiKeyCellText("department_name", row, lookups) || "—"],
    [localize("负责人"), apiKeyCellText("user_name", row, lookups) || "—"],
    [localize("授权模型"), apiKeyModelNames(row.model_ids, lookups) || localize("全部可用模型")],
    [localize("创建时间"), row.created_at || "—"],
    [localize("过期时间"), row.expires_at || localize("不过期")]
  ];
  if (hasCapability("conversation_logs.read")) {
    facts.push([localize("会话记录"), row.conversation_log_active ? localize("记录中") : (Number(row.conversation_log_enabled) === 1 ? localize("已开启（功能未启用，暂不记录）") : localize("未开启"))]);
  }
  const actions = [];
  if (config.form) actions.push(button(localize("编辑"), () => showEditModal("api-keys", config, row, onRefresh), "secondary"));
  if (row.status === "active") actions.push(button(localize("轮换密钥"), () => showApiKeyRotate(row, onRefresh), "secondary"));
  if (hasCapability("conversation_logs.read")) actions.push(button(localize("会话记录"), () => showApiKeyConversationLog(row, onRefresh), "secondary"));
  if (row.status !== "disabled" && row.status !== "deleted") actions.push(button(localize("删除"), () => deleteApiKey(row, onRefresh), "danger"));
  drawer.replaceChildren(
    el("header", { className: "api-key-drawer-head" }, [
      el("div", {}, [
        el("span", { className: "api-key-drawer-kicker", text: localize("密钥详情") }),
        el("h2", { text: row.name || `#${row.id}` }),
        el("span", { className: `status-pill ${row.status === "active" ? "is-positive" : "is-muted"}`, text: apiKeyStatusText(row.status, row.disabled_by) })
      ]),
      closeButton
    ]),
    el("div", { className: "api-key-drawer-key cell-mono", text: apiKeyDisplay(row) || "—", title: localize("完整密钥只在新建或轮换后显示一次") }),
    el("dl", { className: "api-key-drawer-facts" }, facts.flatMap(([label, value]) => [el("dt", { text: label }), el("dd", { text: value })])),
    quotaHost,
    trendHost,
    el("footer", { className: "api-key-drawer-actions" }, actions)
  );
  drawer.hidden = false;
  drawer.dispatchEvent(new CustomEvent("api-key-drawer-open", { bubbles: true }));

  const [quota, trend] = await Promise.all([
    api("/admin-api/quota-status", { query: { api_key_id: row.id }, silent: true }).then((result) => result.data || {}, () => null),
    (async () => {
      const start = new Date();
      start.setHours(0, 0, 0, 0);
      start.setDate(start.getDate() - 13);
      try {
        const result = await api("/admin-api/dashboard/trends", { query: { bucket: "day", api_key_id: row.id, from: serverStamp(start) }, silent: true });
        return Array.isArray(result.data) ? result.data : [];
      } catch {
        return null;
      }
    })()
  ]);
  if (seq !== drawer.__seq) return;
  quotaHost.replaceChildren(el("h3", { text: localize("额度") }), drawerQuota(quota, row));
  trendHost.replaceChildren(...drawerTrend(trend));
}

function drawerQuota(data, row) {
  if (!data) {
    return statusMessage(localize("额度读取失败，可在“额度”中重试。"));
  }
  const items = [
    ...(Array.isArray(data.per_model) ? data.per_model.map((item) => ({ label: item.model_name || localize`模型 #${item.model_id}`, item })) : []),
    ...(Array.isArray(data.policies) ? data.policies.map((item) => ({ label: item.name || localize`策略 #${item.policy_id}`, item })) : [])
  ];
  const meters = [];
  for (const { label, item } of items) {
    const limits = item.limits || {};
    const usage = item.usage || {};
    const metric = ["total_tokens", "request_count", "cost_amount", "input_tokens", "output_tokens"].find((name) => limits[name] !== null && limits[name] !== undefined);
    if (!metric) continue;
    const used = apiKeyNumber(usage[metric]);
    const limit = apiKeyNumber(limits[metric]);
    const percent = limit > 0 ? Math.min(100, Math.round(used / limit * 100)) : 0;
    const unit = metric === "request_count" ? localize("次") : metric === "cost_amount" ? localize("元") : "";
    meters.push(el("div", { className: "api-key-quota" }, [
      el("div", { className: "api-key-quota-top" }, [
        el("span", { text: label }),
        el("span", { className: "cell-mono", text: `${compactTokens(used)} / ${compactTokens(limit)}${unit}` })
      ]),
      el("span", { className: `usage-cell-bar ${percent >= 90 ? "is-high" : ""}`.trim(), title: `${percent}%` }, [el("span", { style: `width: ${Math.max(2, percent)}%` })])
    ]));
  }
  if (!meters.length) {
    return el("p", { className: "cell-muted api-key-drawer-empty", text: localize("这个密钥没有设置额度限制。") });
  }
  return el("div", { className: "api-key-quota-list" }, [
    ...meters,
    button(localize("查看额度明细与调整"), () => showApiKeyQuota(row), "link-button is-accent")
  ]);
}

function drawerTrend(rows) {
  const head = el("div", { className: "api-key-trend-head" }, [el("h3", { text: localize("近 14 天 Token") })]);
  if (rows === null) {
    return [head, statusMessage(localize("用量读取失败"))];
  }
  const byDay = new Map();
  for (const row of rows) {
    const day = String(row.bucket_at || "").slice(0, 10);
    byDay.set(day, (byDay.get(day) || 0) + apiKeyNumber(row.input_tokens) + apiKeyNumber(row.output_tokens));
  }
  const days = [];
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  start.setDate(start.getDate() - 13);
  for (let index = 0; index < 14; index += 1) {
    const day = new Date(start.getTime() + index * 86_400_000);
    const key = `${day.getFullYear()}-${pad2(day.getMonth() + 1)}-${pad2(day.getDate())}`;
    days.push([key, byDay.get(key) || 0]);
  }
  const total = days.reduce((sum, [, value]) => sum + value, 0);
  const max = Math.max(1, ...days.map(([, value]) => value));
  head.append(el("span", { className: "cell-mono", text: localize`合计 ${compactTokens(total)}` }));
  return [
    head,
    el("div", { className: "api-key-trend", role: "img", "aria-label": localize`近 14 天合计 ${total.toLocaleString(getLocale())} Token` }, days.map(([day, value], index) => el("span", {
      className: index === days.length - 1 ? "is-latest" : "",
      title: `${day.slice(5)} · ${value.toLocaleString(getLocale())} Token`,
      style: `height: ${value > 0 ? Math.max(6, Math.round(value / max * 100)) : 3}%`
    })))
  ];
}

async function runApiKeyBatchAction(action, selectedIds, onRefresh) {
  const ids = Array.from(selectedIds);
  if (ids.length === 0) {
    showModal(localize("批量操作 API 密钥"), statusMessage(localize("请先选择 API 密钥"), "error"));
    return;
  }
  const labels = {
    enable: localize("批量启用"),
    disable: localize("批量暂停"),
    delete: localize("批量删除")
  };
  const label = labels[action] || localize("批量操作");
  if (!await confirmDialog({
    title: label,
    message: action === "delete" ? localize`确认删除选中的 ${ids.length} 个 API 密钥？删除后密钥将不能继续调用。` : localize`确认对选中的 ${ids.length} 个 API 密钥执行“${label}”？`,
    confirmLabel: label,
    tone: action === "delete" ? "danger" : "primary"
  })) {
    return;
  }
  try {
    const result = await api("/admin-api/api-keys/batch", {
      method: "POST",
      body: JSON.stringify({ action, ids })
    });
    showOperationResult(label, result.data);
    await onRefresh();
  } catch (error) {
    showModal(label, statusMessage(adminErrorMessage(error, localize`${label}失败，请确认权限或状态`), "error"));
  }
}

function apiKeyCellText(column, row, context = {}) {
  if (column === "key") {
    return apiKeyDisplay(row);
  }
  if (column === "status") {
    return apiKeyStatusText(row.status, row.disabled_by);
  }
  if (column === "user_name") {
    return row.user_name || apiKeyUserLabel(row.user_id, context);
  }
  if (column === "department_name") {
    return row.department_name || apiKeyDepartmentLabel(row.department_id, context);
  }
  if (column === "user_id") {
    return apiKeyUserLabel(row[column], context);
  }
  if (column === "department_id") {
    return apiKeyDepartmentLabel(row[column], context);
  }
  return formatResourceValue(column, row[column]);
}

function apiKeyDisplay(row = {}) {
  if (row.display) {
    return String(row.display).replace("****", "...");
  }
  if (row.key_prefix && row.key_suffix) {
    return `${row.key_prefix}...${row.key_suffix}`;
  }
  if (row.key_suffix) {
    return `sk-...${row.key_suffix}`;
  }
  return "";
}

function apiKeyStatusText(status, disabledBy = null) {
  const labels = {
    active: localize("生效中"),
    disabled: localize("已停用"),
    deleted: localize("已删除"),
    expired: localize("已过期")
  };
  const text = labels[status] || formatResourceValue("status", status);
  if (status === "disabled" && API_KEY_DISABLED_BY_LABELS[disabledBy]) {
    return `${text}（${API_KEY_DISABLED_BY_LABELS[disabledBy]}）`;
  }
  return text;
}

export function showApiKeyDetails(row, config, context = {}) {
  const columns = config.detailColumns || config.columns || Object.keys(row);
  const detailRows = columns.map((column) => el("div", { className: "api-key-detail-row" }, [
    el("span", { text: columnLabel(column) }),
    el("strong", { text: renderTableCell("api-keys", column, row, context) || "-" })
  ]));
  showModal(localize`API 密钥 #${row.id || ""}`, el("div", { className: "api-key-detail-modal" }, [
    el("div", { className: "api-key-detail-summary" }, [
      el("strong", { text: row.name || `ID ${row.id || ""}` }),
      el("span", { text: localize`用户 ${apiKeyUserLabel(row.user_id, context) || "-"} / 部门 ${apiKeyDepartmentLabel(row.department_id, context) || "-"}` }),
      el("span", { text: localize`状态${formatResourceValue("status", row.status) || "-"}` })
    ]),
    el("div", { className: "api-key-detail-grid" }, detailRows)
  ]));
}

async function showApiKeyRagBindings(row, onSaved) {
  const output = el("p", { className: "notice", hidden: true });
  const body = el("div", { className: "rag-api-key-bindings" }, [statusMessage(localize("正在加载知识库权限...")), output]);
  showModal(localize`知识库权限 · ${row.name || row.id}`, body);
  try {
    const [knowledgeBasesResult, datasetsResult, bindingsResult] = await Promise.all([
      api("/admin-api/rag-knowledge-bases", { query: { limit: 500 } }),
      api("/admin-api/rag-vector-datasets", { query: { limit: 500 } }),
      api(`/admin-api/api-keys/${row.id}/rag-bindings`)
    ]);
    const knowledgeBases = Array.isArray(knowledgeBasesResult.data) ? knowledgeBasesResult.data : knowledgeBasesResult.data?.items || [];
    const datasets = Array.isArray(datasetsResult.data) ? datasetsResult.data : datasetsResult.data?.items || [];
    const current = bindingsResult.data || {};
    const selectedKnowledgeBases = new Set((current.knowledge_base_ids || []).map(Number));
    const selectedDatasets = new Set((current.dataset_ids || []).map(Number));
    const section = (title, items, selected, itemLabel) => el("section", { className: "rag-api-key-binding-section" }, [
      el("h3", { text: title }),
      el("div", { className: "rag-api-key-binding-list" }, items.map((item) => {
        const input = el("input", { type: "checkbox", value: String(item.id), checked: selected.has(Number(item.id)) });
        return el("label", { className: "rag-api-key-binding-item" }, [input, el("span", { text: itemLabel(item) })]);
      }))
    ]);
    const knowledgeBaseSection = section(localize("知识库（授予后同步所有数据集）"), knowledgeBases, selectedKnowledgeBases, (item) => item.name || item.kb_id || localize`知识库 #${item.id}`);
    const datasetSection = section(localize("数据集（会同时授予所属知识库）"), datasets, selectedDatasets, (item) => item.name || item.dataset_id || localize`数据集 #${item.id}`);
    const save = button(localize("保存知识库权限"), async () => {
      const values = (sectionElement) => [...sectionElement.querySelectorAll('input[type="checkbox"]:checked')].map((input) => Number(input.value)).filter(Number.isFinite);
      try {
        await api(`/admin-api/api-keys/${row.id}/rag-bindings`, {
          method: "PUT",
          body: JSON.stringify({ knowledge_base_ids: values(knowledgeBaseSection), dataset_ids: values(datasetSection) })
        });
        setText(output, localize("知识库权限已保存"));
        output.hidden = false;
        await onSaved();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, localize("知识库权限保存失败")));
      }
    });
    body.replaceChildren(knowledgeBaseSection, datasetSection, save, output);
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, localize("知识库权限加载失败")), "error"));
  }
}

// 会话记录: switch conversation logging on or off for one key (super administrators).
async function showApiKeyConversationLog(row, onRefresh) {
  const body = el("div", { className: "api-key-conversation-dialog" }, [loadingState(localize("正在读取…"), { rows: 1 })]);
  const handle = showModal(localize`会话记录 · ${row.name || `#${row.id}`}`, body, { size: "sm" });
  try {
    const result = await api(`/admin-api/conversation-logs/api-keys/${row.id}`, { silent: true });
    const { conversationKeySettings } = await import("./conversation-logs.js");
    body.replaceChildren(conversationKeySettings(result.data || {}, {
      onSaved: (key) => {
        row.conversation_log_enabled = key.enabled ? 1 : 0;
        row.conversation_log_active = Boolean(key.enabled && key.feature_enabled !== false);
        onRefresh?.();
      },
      onView: () => {
        void handle?.close?.({ force: true });
        navigateTo("conversation-logs", { api_key_id: row.id });
      },
      onLeave: () => void handle?.close?.({ force: true })
    }));
  } catch (error) {
    body.replaceChildren(errorState(error, { fallback: adminErrorMessage(error, localize("读取会话记录设置失败")) }));
  }
}
