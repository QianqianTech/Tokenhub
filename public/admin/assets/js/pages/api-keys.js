import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, setText, showModal, statusMessage } from "../dom.js";
import { showCreateModal, showEditModal } from "../lib/forms.js";
import { setErrorOutput, showOperationResult } from "../lib/output.js";
import { apiKeyDepartmentLabel, apiKeyUserLabel, departmentSelectOptions, renderContext } from "../lib/references.js";
import { emptyState, errorState, loadingState } from "../components/feedback.js";
import { pager, PAGE_SIZES } from "../components/pager.js";
import { renderTableCell } from "../lib/resource-table.js";
import { columnLabel, formatResourceValue, resources } from "../resources.js";
import { confirmDialog } from "../components/modal.js";
import { API_KEY_DISABLED_BY_LABELS, apiKeyManagementBadge, showApiKeyMcpGrants, showApiKeyQuota, showApiKeyRotate } from "../lib/ops-tools.js";

const apiKeyPageColumns = [
  ["id", "编号"],
  ["name", "名称"],
  ["department_name", "部门"],
  ["user_name", "员工"],
  ["created_at", "创建时间"],
  ["key", "密钥"],
  ["status", "状态"]
];

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

export async function renderApiKeyPage(content, initialFilters = {}, context = {}) {
  clear(content);
  const config = resources["api-keys"];
  const { offset: initialOffset = 0, limit: initialLimit = 20, ...initialRest } = initialFilters;
  const state = {
    filters: { sort: "created_at_desc", ...initialRest },
    pageSize: PAGE_SIZES.includes(Number(initialLimit)) ? Number(initialLimit) : 20,
    offset: Math.max(0, Number(initialOffset) || 0)
  };
  const departmentOptions = await departmentSelectOptions();
  const page = el("section", { className: "api-key-page" });
  const tableHost = el("section", { className: "api-key-table-panel", "aria-live": "polite" }, [loadingState("正在加载 API 密钥…")]);
  const keywordInput = el("input", {
    type: "search",
    placeholder: "搜索名称、密钥或员工",
    value: state.filters.keyword || "",
    "aria-label": "搜索 API 密钥"
  });
  const departmentSelect = el("select", { "aria-label": "部门" }, [
    el("option", { value: "", text: "全部部门" }),
    ...departmentOptions.map(([value, label]) => el("option", { value, text: label }))
  ]);
  departmentSelect.value = state.filters.department_id || "";
  const filterToolbar = el("form", { className: "filters api-key-filter-toolbar", role: "search" }, [
    el("label", { className: "field" }, [el("span", { text: "搜索" }), keywordInput]),
    el("label", { className: "field" }, [el("span", { text: "部门" }), departmentSelect])
  ]);
  const createButton = button("+ 新建 API 密钥", () => showCreateModal("api-keys", config, refresh), "api-key-create-button");
  createButton.title = "新建 API 密钥";
  const analyticsHost = el("div");
  page.append(
    el("header", { className: "api-key-page-header" }, [
      el("h2", { text: "API 密钥" }),
      createButton
    ]),
    el("div", { className: "api-key-safety-notice", text: "API 密钥只会在新建后显示一次，请立即复制保存；不要写进前端代码或发给他人。" }),
    filterToolbar,
    analyticsHost,
    tableHost
  );

  async function refresh() {
    context.setFilters?.({
      ...state.filters,
      ...(state.offset ? { offset: state.offset } : {}),
      ...(state.pageSize !== 20 ? { limit: state.pageSize } : {})
    });
    tableHost.setAttribute("aria-busy", "true");
    let result;
    try {
      result = await api("/admin-api/api-keys", {
        query: { ...state.filters, limit: state.pageSize, offset: state.offset },
        silent: true
      });
    } catch (error) {
      tableHost.removeAttribute("aria-busy");
      tableHost.replaceChildren(errorState(error, { fallback: "API 密钥列表加载失败", onRetry: refresh }));
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
    analyticsHost.replaceChildren(renderApiKeyAnalytics(rows, total));
    const hasFilters = Boolean(state.filters.keyword || state.filters.department_id);
    tableHost.replaceChildren(
      rows.length === 0
        ? emptyState({
          title: hasFilters ? "没有符合条件的 API 密钥" : "暂无 API 密钥",
          description: hasFilters ? "可以调整搜索词或部门后重试。" : "点击“新建 API 密钥”为员工创建第一把密钥。"
        })
        : renderApiKeyTable(rows, config, refresh, lookups),
      pager({
        total,
        limit: pageSize,
        offset,
        count: rows.length,
        label: "API 密钥分页",
        onChange: async ({ offset: nextOffset, limit: nextLimit }) => {
          state.offset = nextOffset;
          state.pageSize = nextLimit;
          await refresh();
        }
      })
    );
  }

  const applyFilters = async () => {
    const keyword = keywordInput.value.trim();
    const departmentId = departmentSelect.value;
    if (keyword) {
      state.filters.keyword = keyword;
    } else {
      delete state.filters.keyword;
    }
    if (departmentId) {
      state.filters.department_id = departmentId;
    } else {
      delete state.filters.department_id;
    }
    state.offset = 0;
    await refresh();
  };
  filterToolbar.append(button("搜索", applyFilters, "", { type: "submit" }));
  filterToolbar.addEventListener("submit", (event) => event.preventDefault());
  departmentSelect.addEventListener("change", () => void applyFilters());

  content.append(page);
  await refresh();
}

function renderApiKeyAnalytics(rows, total = rows.length) {
  const activeCount = rows.filter((row) => row.status === "active").length;
  const disabledCount = rows.filter((row) => row.status === "disabled" || row.status === "deleted").length;
  const requestCount = sumApiKeyMetric(rows, "request_count");
  const totalTokens = sumApiKeyMetric(rows, "total_tokens");
  const cacheTokens = sumApiKeyMetric(rows, "cache_tokens");
  const totalCost = rows.reduce((sum, row) => sum + apiKeyNumber(row.total_cost), 0);
  const cards = [
    ["API 密钥总数", formatApiKeyNumber(total)],
    ["本页生效中", formatApiKeyNumber(activeCount)],
    ["本页停用/删除", formatApiKeyNumber(disabledCount)],
    ["本页请求数", formatApiKeyNumber(requestCount)],
    ["本页 Token", formatApiKeyNumber(totalTokens)],
    ["本页缓存 Token", formatApiKeyNumber(cacheTokens)],
    ["本页成本", formatApiKeyCost(totalCost)]
  ];
  return el("section", { className: "api-key-analysis-grid" }, cards.map(([label, value]) => el("article", { className: "api-key-analysis-card" }, [
    el("span", { text: label }),
    el("strong", { text: value })
  ])));
}

function apiKeyNumber(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number : 0;
}

function sumApiKeyMetric(rows, key) {
  return rows.reduce((sum, row) => sum + apiKeyNumber(row[key]), 0);
}

function formatApiKeyNumber(value) {
  return apiKeyNumber(value).toLocaleString("zh-CN");
}

function formatApiKeyCost(value) {
  const number = apiKeyNumber(value);
  return number > 0 ? number.toFixed(4) : "0";
}

function renderApiKeyTable(rows, config, onRefresh, context = {}) {
  const selectedApiKeyIds = new Set();
  let apiKeySelectAll = null;
  let apiKeySelectionStatus = null;
  const apiKeyBulkButtons = [];
  const selectableRows = rows.filter((row) => row.id);
  const updateApiKeySelection = () => {
    const selectedCount = selectedApiKeyIds.size;
    if (apiKeySelectionStatus) {
      setText(apiKeySelectionStatus, selectedCount > 0 ? `已选择 ${selectedCount} 个密钥` : "未选择密钥");
    }
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
    title: "选择当前页 API 密钥",
    onChange: (event) => {
      const checked = event.currentTarget.checked;
      selectedApiKeyIds.clear();
      for (const row of selectableRows) {
        if (checked) {
          selectedApiKeyIds.add(Number(row.id));
        }
      }
      for (const checkbox of table.querySelectorAll(".api-key-row-select")) {
        checkbox.checked = checked;
      }
      updateApiKeySelection();
    }
  });
  table.append(el("thead", {}, [
    el("tr", {}, [
      el("th", { className: "table-cell table-cell--select" }, [apiKeySelectAll]),
      ...apiKeyPageColumns.map(([column, label]) => el("th", { className: `table-cell table-cell--${column}`, text: label })),
      el("th", { className: "table-cell table-cell--actions", text: "操作" })
    ])
  ]));
  table.append(el("tbody", {}, rows.map((row) => {
    const checkbox = el("input", {
      type: "checkbox",
      className: "api-key-row-select",
      value: row.id,
      title: `选择 API 密钥 ${row.name || row.id}`,
      onChange: (event) => {
        const id = Number(event.currentTarget.value);
        if (event.currentTarget.checked) {
          selectedApiKeyIds.add(id);
        } else {
          selectedApiKeyIds.delete(id);
        }
        updateApiKeySelection();
      }
    });
    const actions = el("div", { className: "row-actions api-key-row-actions" });
    if (config.form && row.id) {
      const editButton = button("", () => showEditModal("api-keys", config, row, onRefresh), "secondary api-key-icon-button");
      editButton.title = "编辑";
      editButton.ariaLabel = "编辑 API 密钥";
      editButton.append(apiKeyActionIcon([
        "M12 20h9",
        "M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"
      ]));
      actions.append(editButton);
      const ragButton = button("知识库", () => showApiKeyRagBindings(row, onRefresh), "secondary");
      ragButton.title = "配置此密钥可调用的知识库和数据集";
      actions.append(ragButton);
    }
    if (row.id) {
      const quotaButton = button("", () => showApiKeyQuota(row), "secondary api-key-icon-button api-key-quota-button");
      quotaButton.title = "额度：查看当前用量（含进行中请求的预占）";
      quotaButton.ariaLabel = "额度";
      quotaButton.append(apiKeyActionIcon([
        "M12 14l4-4",
        "M3.34 19a10 10 0 1 1 17.32 0"
      ]));
      actions.append(quotaButton);
    }
    if (row.id) {
      const mcpButton = button("", () => showApiKeyMcpGrants(row), "secondary api-key-icon-button api-key-mcp-button");
      mcpButton.title = "MCP 授权：查看并撤销用这个密钥登录的 MCP 客户端";
      mcpButton.ariaLabel = "MCP 授权";
      mcpButton.append(apiKeyActionIcon([
        "M9 7V3M15 7V3",
        "M6 7h12v4a6 6 0 0 1-12 0Z",
        "M12 17v4"
      ]));
      actions.append(mcpButton);
    }
    if (row.id && row.status === "active") {
      const rotateButton = button("", () => showApiKeyRotate(row, onRefresh), "secondary api-key-icon-button api-key-rotate-button");
      rotateButton.title = "轮换：生成新密钥并在宽限期后停用旧密钥";
      rotateButton.ariaLabel = "轮换";
      rotateButton.append(apiKeyActionIcon([
        "M21 12a9 9 0 0 1-15.5 6.2L3 16",
        "M3 21v-5h5",
        "M3 12a9 9 0 0 1 15.5-6.2L21 8",
        "M21 3v5h-5"
      ]));
      actions.append(rotateButton);
    }
    if (row.id && row.status !== "disabled" && row.status !== "deleted") {
      const deleteButton = button("", async () => {
        if (!await confirmDialog({ title: "删除 API 密钥", message: "删除后该密钥立即无法继续调用，且无法恢复。", details: [["API 密钥", `${row.name || row.id}`], ["密钥", apiKeyDisplay(row) || "-"]], confirmLabel: "删除", requireText: String(row.name || row.id) })) {
          return;
        }
        try {
          await api(`/admin-api/api-keys/${row.id}`, { method: "DELETE" });
          await onRefresh();
        } catch (error) {
          showModal("删除 API 密钥", statusMessage(adminErrorMessage(error, "删除 API 密钥失败"), "error"));
        }
      }, "secondary api-key-icon-button api-key-icon-button--danger");
      deleteButton.title = "删除";
      deleteButton.ariaLabel = "删除 API 密钥";
      deleteButton.append(apiKeyActionIcon([
        "M3 6h18",
        "M8 6V4h8v2",
        "M19 6l-1 14H6L5 6",
        "M10 11v5M14 11v5"
      ]));
      actions.append(deleteButton);
    }
    return el("tr", {}, [
      el("td", { className: "table-cell table-cell--select" }, [checkbox]),
      ...apiKeyPageColumns.map(([column]) => {
        const cellText = apiKeyCellText(column, row, context);
        const scopeBadge = column === "name" ? apiKeyManagementBadge(row) : null;
        return el("td", {
          className: `table-cell table-cell--${column}`,
          text: scopeBadge ? undefined : cellText,
          title: cellText
        }, scopeBadge ? [scopeBadge, document.createTextNode(cellText)] : []);
      }),
      el("td", { className: "table-cell table-cell--actions" }, [actions])
    ]);
  })));
  const bulkActions = el("div", { className: "api-key-bulk-actions" });
  apiKeySelectionStatus = el("span", { className: "api-key-bulk-selection", text: "未选择密钥" });
  const enableButton = button("批量启用", () => runApiKeyBatchAction("enable", selectedApiKeyIds, onRefresh), "secondary");
  const disableButton = button("批量暂停", () => runApiKeyBatchAction("disable", selectedApiKeyIds, onRefresh), "secondary");
  const deleteButton = button("批量删除", () => runApiKeyBatchAction("delete", selectedApiKeyIds, onRefresh), "danger");
  apiKeyBulkButtons.push(enableButton, disableButton, deleteButton);
  bulkActions.append(apiKeySelectionStatus, enableButton, disableButton, deleteButton);
  updateApiKeySelection();
  return el("div", { className: "api-key-table-wrap api-key-table-panel" }, [bulkActions, table]);
}

async function runApiKeyBatchAction(action, selectedIds, onRefresh) {
  const ids = Array.from(selectedIds);
  if (ids.length === 0) {
    showModal("批量操作 API 密钥", statusMessage("请先选择 API 密钥", "error"));
    return;
  }
  const labels = {
    enable: "批量启用",
    disable: "批量暂停",
    delete: "批量删除"
  };
  const label = labels[action] || "批量操作";
  if (!await confirmDialog({
    title: label,
    message: action === "delete" ? `确认删除选中的 ${ids.length} 个 API 密钥？删除后密钥将不能继续调用。` : `确认对选中的 ${ids.length} 个 API 密钥执行“${label}”？`,
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
    showModal(label, statusMessage(adminErrorMessage(error, `${label}失败，请确认权限或状态`), "error"));
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
    active: "生效中",
    disabled: "已停用",
    deleted: "已删除",
    expired: "已过期"
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
  showModal(`API 密钥 #${row.id || ""}`, el("div", { className: "api-key-detail-modal" }, [
    el("div", { className: "api-key-detail-summary" }, [
      el("strong", { text: row.name || `ID ${row.id || ""}` }),
      el("span", { text: `用户 ${apiKeyUserLabel(row.user_id, context) || "-"} / 部门 ${apiKeyDepartmentLabel(row.department_id, context) || "-"}` }),
      el("span", { text: `状态${formatResourceValue("status", row.status) || "-"}` })
    ]),
    el("div", { className: "api-key-detail-grid" }, detailRows)
  ]));
}

async function showApiKeyRagBindings(row, onSaved) {
  const output = el("p", { className: "notice", hidden: true });
  const body = el("div", { className: "rag-api-key-bindings" }, [statusMessage("正在加载知识库权限..."), output]);
  showModal(`知识库权限 · ${row.name || row.id}`, body);
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
    const knowledgeBaseSection = section("知识库（授予后同步所有数据集）", knowledgeBases, selectedKnowledgeBases, (item) => item.name || item.kb_id || `知识库 #${item.id}`);
    const datasetSection = section("数据集（会同时授予所属知识库）", datasets, selectedDatasets, (item) => item.name || item.dataset_id || `数据集 #${item.id}`);
    const save = button("保存知识库权限", async () => {
      const values = (sectionElement) => [...sectionElement.querySelectorAll('input[type="checkbox"]:checked')].map((input) => Number(input.value)).filter(Number.isFinite);
      try {
        await api(`/admin-api/api-keys/${row.id}/rag-bindings`, {
          method: "PUT",
          body: JSON.stringify({ knowledge_base_ids: values(knowledgeBaseSection), dataset_ids: values(datasetSection) })
        });
        setText(output, "知识库权限已保存");
        output.hidden = false;
        await onSaved();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, "知识库权限保存失败"));
      }
    });
    body.replaceChildren(knowledgeBaseSection, datasetSection, save, output);
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "知识库权限加载失败"), "error"));
  }
}
