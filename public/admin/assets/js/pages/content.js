import { adminErrorMessage, api, isAbortError } from "../api.js";
import { button, clear, el, field, readFields, showModal } from "../dom.js";
import { emptyState, errorState, loadingState } from "../components/feedback.js";
import { pager } from "../components/pager.js";
import { COLUMN_LOOKUPS, canLookup, lookupLabel, resolveRowReferences } from "../lib/lookups.js";
import { renderDataDetails, setErrorOutput, setOperationOutput, showOperationResult } from "../lib/output.js";
import { columnLabel, formatResourceValue } from "../resources.js";

const ARCHIVE_LIMIT = 50;

// Session archive: searchable pickers instead of raw ids, filters kept in the
// URL, explicit loading/error states and offset paging.
export async function renderContentArchivePage(content, initialFilters = {}, context = {}) {
  content.replaceChildren();
  const pickers = [
    { name: "user_id", label: "用户", lookup: "users" },
    { name: "department_id", label: "部门", lookup: "departments" },
    { name: "api_key_id", label: "API 密钥", lookup: "api-keys" },
    { name: "model_id", label: "模型", lookup: "models" }
  ];
  const filters = [
    ...pickers.map((picker) => canLookup(picker.lookup)
      ? { ...picker, type: "remote-select", optional: true, emptyOption: "全部" }
      : { name: picker.name, label: `${picker.label} ID`, type: "number", optional: true }),
    { name: "session_id", label: "会话 ID", optional: true },
    { name: "from", label: "开始时间", type: "datetime-local", optional: true },
    { name: "to", label: "结束时间", type: "datetime-local", optional: true }
  ];
  const state = {
    includeRaw: initialFilters.include_raw === "true",
    offset: Math.max(0, Number(initialFilters.offset) || 0)
  };
  const form = el("form", { className: "filters", onSubmit: (event) => {
    event.preventDefault();
    state.offset = 0;
    load();
  } });
  for (const config of filters) {
    const value = config.type === "datetime-local" ? toLocalInput(initialFilters[config.name]) : initialFilters[config.name] || "";
    form.append(field(config, value));
  }
  form.append(el("label", { className: "field" }, [
    el("span", { text: "包含原文" }),
    el("select", { name: "include_raw" }, [
      el("option", { value: "false", text: "否" }),
      el("option", { value: "true", text: "是", selected: state.includeRaw })
    ])
  ]));
  const tablePanel = el("section", { className: "panel table-panel", "aria-live": "polite" });
  form.append(button("查询", () => {
    state.offset = 0;
    return load();
  }));
  form.append(button("重置", () => {
    context.setFilters?.({});
    return renderContentArchivePage(content, {}, context);
  }, "secondary"));
  form.append(button("导出", async () => {
    const query = contentArchiveQuery(form, filters, state);
    delete query.limit;
    delete query.offset;
    const result = await api("/admin-api/exports/session_archive", {
      method: "POST",
      body: JSON.stringify(query)
    });
    showOperationResult("导出任务", result.data, "导出任务已创建，可在导出任务列表查看");
  }, "secondary"));
  content.append(form, tablePanel);
  await load();

  async function load() {
    let query;
    try {
      query = contentArchiveQuery(form, filters, state);
    } catch (error) {
      tablePanel.replaceChildren(errorState(error, { fallback: "筛选条件无效" }));
      return;
    }
    context.setFilters?.(urlFilters(query));
    tablePanel.replaceChildren(loadingState("正在加载会话存档…"));
    let rows;
    try {
      const result = await api("/admin-api/content-archive", { query });
      rows = Array.isArray(result.data) ? result.data : [];
    } catch (error) {
      if (isAbortError(error)) {
        return;
      }
      tablePanel.replaceChildren(errorState(error, { fallback: "会话存档加载失败", onRetry: () => load() }));
      return;
    }
    const navigation = pager({
      limit: ARCHIVE_LIMIT,
      offset: state.offset,
      count: rows.length,
      pageSizes: [ARCHIVE_LIMIT],
      onChange: ({ offset }) => {
        state.offset = offset;
        load();
      }
    });
    if (rows.length === 0) {
      tablePanel.replaceChildren(emptyState({
        title: state.offset > 0 ? "没有更多会话存档" : "暂无会话存档",
        description: "调整筛选条件或时间范围后重试。"
      }), ...(state.offset > 0 ? [navigation] : []));
      return;
    }
    const references = await resolveRowReferences(rows, ARCHIVE_COLUMNS);
    tablePanel.replaceChildren(renderContentArchiveTable(rows, state.includeRaw, references), navigation);
  }
}

export async function renderContentRetentionPolicyPage(content) {
  clear(content);
  const result = await api("/admin-api/system/content-retention");
  const data = result.data || {};
  const fields = [
    { name: "mode", label: "默认模式", type: "select", options: [["summary", "只保存摘要"], ["redacted", "保存脱敏内容"], ["raw", "保存原文"], ["none", "不保存正文"]] },
    { name: "default_days", label: "默认保存天数", type: "number" },
    { name: "max_days", label: "最大保存天数", type: "number" },
    { name: "raw_allowed_roles", label: "原文角色，逗号分隔" },
    { name: "departments", label: "部门策略 JSON", type: "json", optional: true },
    { name: "users", label: "用户策略 JSON", type: "json", optional: true },
    { name: "api_keys", label: "API 密钥策略 JSON", type: "json", optional: true }
  ];
  const form = el("form", { className: "edit-form", onSubmit: (event) => event.preventDefault() });
  const values = {
    ...data,
    raw_allowed_roles: (data.raw_allowed_roles || ["super_admin"]).join(","),
    departments: JSON.stringify(data.departments || {}, null, 2),
    users: JSON.stringify(data.users || {}, null, 2),
    api_keys: JSON.stringify(data.api_keys || {}, null, 2)
  };
  for (const config of fields) {
    form.append(field(config, values[config.name]));
  }
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("保存策略", async () => {
    try {
      const payload = readFields(form, fields);
      payload.raw_allowed_roles = String(payload.raw_allowed_roles || "")
        .split(",")
        .map((role) => role.trim())
        .filter(Boolean);
      const saved = await api("/admin-api/system/content-retention", {
        method: "PUT",
        body: JSON.stringify(payload)
      });
      setOperationOutput(output, saved.data, "策略已保存");
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "保存策略失败，请检查输入内容"));
    }
  }));
  form.append(output);
  content.append(el("section", { className: "panel" }, [
    el("h2", { text: "内容留存策略" }),
    form
  ]));
}

function contentArchiveQuery(form, filters, state) {
  const query = readFields(form, filters, { validate: true });
  for (const key of ["from", "to"]) {
    if (query[key]) {
      query[key] = utcTimestamp(query[key]);
    }
  }
  if (query.from && query.to && query.from >= query.to) {
    throw new Error("开始时间必须早于结束时间");
  }
  state.includeRaw = form.querySelector('[name="include_raw"]')?.value === "true";
  if (state.includeRaw) {
    query.include_raw = true;
  }
  query.limit = ARCHIVE_LIMIT;
  query.offset = state.offset;
  return query;
}

function urlFilters(query) {
  const filters = {};
  for (const [key, value] of Object.entries(query)) {
    if (key === "limit" || value === "" || value === null || value === undefined || (key === "offset" && !value)) {
      continue;
    }
    filters[key] = String(value);
  }
  return filters;
}

// datetime-local values are local wall-clock time; the API compares UTC.
function utcTimestamp(localValue) {
  const date = new Date(localValue);
  return Number.isFinite(date.getTime()) ? date.toISOString().slice(0, 19).replace("T", " ") : localValue;
}

function toLocalInput(utcValue) {
  if (!utcValue) {
    return "";
  }
  const date = new Date(`${String(utcValue).replace(" ", "T")}Z`);
  if (!Number.isFinite(date.getTime())) {
    return "";
  }
  const pad = (value) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const ARCHIVE_COLUMNS = ["content_id", "request_id", "session_id", "user_id", "department_id", "api_key_id", "model_name", "status", "retention_mode", "created_at", "cleaned_at"];

const REFERENCE_HEADERS = { user_id: "用户", department_id: "部门", api_key_id: "API 密钥" };

function archiveCell(column, row, references) {
  const type = COLUMN_LOOKUPS[column];
  const referenced = type ? references[type]?.get(Number(row[column])) : null;
  return referenced ? lookupLabel(type, referenced) : formatResourceValue(column, row[column]);
}

function renderContentArchiveTable(rows, includeRaw, references = {}) {
  const table = el("table");
  const headRow = el("tr");
  for (const column of ARCHIVE_COLUMNS) {
    headRow.append(el("th", { scope: "col", text: REFERENCE_HEADERS[column] || columnLabel(column) }));
  }
  headRow.append(el("th", { scope: "col", className: "table-cell--actions", text: "操作" }));
  const body = el("tbody");
  for (const row of rows) {
    const tr = el("tr");
    for (const column of ARCHIVE_COLUMNS) {
      tr.append(el("td", { text: archiveCell(column, row, references) }));
    }
    tr.append(el("td", { className: "table-cell--actions" }, [button("查看", async () => {
      const result = await api(`/admin-api/content-archive/${row.content_id}`, { query: { include_raw: includeRaw ? "true" : "false" } });
      showModal("会话存档详情", renderDataDetails(result.data));
    }, "secondary", { "aria-label": `查看存档 ${row.content_id} 详情` })]));
    body.append(tr);
  }
  table.append(el("thead", {}, [headRow]), body);
  return el("div", { className: "table-scroll" }, [table]);
}
