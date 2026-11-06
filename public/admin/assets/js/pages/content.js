import { localize } from "../i18n.js";
import { pageHead } from "../lib/resource-table.js";
import { adminErrorMessage, api, isAbortError } from "../api.js";
import { button, clear, el, field, readFields, showModal } from "../dom.js";
import { emptyState, errorState, loadingState } from "../components/feedback.js";
import { pager } from "../components/pager.js";
import { COLUMN_LOOKUPS, canLookup, lookupLabel, resolveRowReferences } from "../lib/lookups.js";
import { renderDataDetails, setErrorOutput, setOperationOutput, showOperationResult } from "../lib/output.js";
import { columnLabel, formatResourceValue } from "../resources.js";
import { fromLocalInputValue, toLocalInputValue } from "../lib/format.js";
import { guardForm, markClean } from "../lib/dirty-guard.js";
import { departmentSelectOptions } from "../lib/references.js";

const ARCHIVE_LIMIT = 50;

// Session archive: searchable pickers instead of raw ids, filters kept in the
// URL, explicit loading/error states and offset paging.
export async function renderContentArchivePage(content, initialFilters = {}, context = {}) {
  content.replaceChildren();
  const pickers = [
    { name: "user_id", label: localize("用户"), lookup: "users" },
    { name: "department_id", label: localize("部门"), lookup: "departments" },
    { name: "api_key_id", label: localize("API 密钥"), lookup: "api-keys" },
    { name: "model_id", label: localize("模型"), lookup: "models" }
  ];
  const filters = [
    ...pickers.map((picker) => canLookup(picker.lookup)
      ? { ...picker, type: "remote-select", optional: true, emptyOption: localize("全部") }
      : { name: picker.name, label: `${picker.label} ID`, type: "number", optional: true }),
    { name: "session_id", label: localize("会话 ID"), optional: true },
    { name: "from", label: localize("开始时间"), type: "datetime-local", optional: true },
    { name: "to", label: localize("结束时间"), type: "datetime-local", optional: true }
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
    el("span", { text: localize("包含原文") }),
    el("select", { name: "include_raw" }, [
      el("option", { value: "false", text: localize("否") }),
      el("option", { value: "true", text: localize("是"), selected: state.includeRaw })
    ])
  ]));
  const tablePanel = el("section", { className: "panel table-panel", "aria-live": "polite" });
  form.append(el("div", { className: "archive-filter-actions" }, [
    button(localize("查询"), () => {
      state.offset = 0;
      return load();
    }),
    button(localize("重置"), () => {
      context.setFilters?.({});
      return renderContentArchivePage(content, {}, context);
    }, "secondary")
  ]));
  const exportButton = button(localize("导出"), async () => {
    const query = contentArchiveQuery(form, filters, state);
    delete query.limit;
    delete query.offset;
    const result = await api("/admin-api/exports/session_archive", {
      method: "POST",
      body: JSON.stringify(query)
    });
    showOperationResult(localize("导出任务"), result.data, localize("导出任务已创建，可在导出任务列表查看"));
  }, "secondary");
  form.classList.add("archive-filters");
  content.append(
    pageHead(localize("会话归档"), localize("按用户、部门、密钥或会话查找已留存的调用内容；查看原文需要相应权限并会记入审计。"), el("div", {}, [exportButton])),
    form,
    tablePanel
  );
  await load();

  async function load() {
    let query;
    try {
      query = contentArchiveQuery(form, filters, state);
    } catch (error) {
      tablePanel.replaceChildren(errorState(error, { fallback: localize("筛选条件无效") }));
      return;
    }
    context.setFilters?.(urlFilters(query));
    tablePanel.replaceChildren(loadingState(localize("正在加载会话存档…")));
    let rows;
    try {
      const result = await api("/admin-api/content-archive", { query });
      rows = Array.isArray(result.data) ? result.data : [];
    } catch (error) {
      if (isAbortError(error)) {
        return;
      }
      tablePanel.replaceChildren(errorState(error, { fallback: localize("会话存档加载失败"), onRetry: () => load() }));
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
        title: state.offset > 0 ? localize("没有更多会话存档") : localize("暂无会话存档"),
        description: localize("调整筛选条件或时间范围后重试。")
      }), ...(state.offset > 0 ? [navigation] : []));
      return;
    }
    const references = await resolveRowReferences(rows, ARCHIVE_COLUMNS);
    tablePanel.replaceChildren(renderContentArchiveTable(rows, state.includeRaw, references), navigation);
  }
}

const RETENTION_MODES = [["summary", localize("只保存摘要")], ["redacted", localize("保存脱敏内容")], ["raw", localize("保存原文")], ["none", localize("不保存正文")]];
const RAW_ROLE_OPTIONS = [["super_admin", localize("超级管理员")], ["admin", localize("管理员")]];
// Per-target overrides (ContentRetentionService::normalizeScopePolicies):
// { "<id>": { mode, days } }. API key beats user beats department.
const RETENTION_SCOPES = [
  { key: "departments", title: localize("部门策略"), hint: localize("对部门及其下级部门生效。"), picker: "department" },
  { key: "users", title: localize("用户策略"), hint: localize("覆盖该用户所在部门的策略。"), picker: "users" },
  { key: "api_keys", title: localize("API 密钥策略"), hint: localize("优先级最高，覆盖用户和部门策略。"), picker: "api-keys" }
];

function retentionScopeEditor(scope, entries, departmentOptions) {
  const list = el("div", { className: "retention-scope-rows" });
  let seq = 0;
  const addRow = (targetId = "", rule = {}) => {
    seq += 1;
    const targetName = `${scope.key}_target_${seq}`;
    const target = scope.picker === "department"
      ? field({ name: targetName, label: localize("部门"), type: "select", options: [["", localize("请选择部门")], ...departmentOptions] }, String(targetId))
      : field({ name: targetName, label: scope.picker === "users" ? localize("用户") : localize("API 密钥"), type: "remote-select", lookup: scope.picker }, targetId === "" ? "" : String(targetId));
    if (scope.picker === "department") {
      target.querySelector("select").value = String(targetId);
    }
    const mode = field({ name: `${scope.key}_mode_${seq}`, label: localize("留存方式"), type: "select", options: RETENTION_MODES }, rule.mode || "summary");
    mode.querySelector("select").value = rule.mode || "summary";
    const days = field({ name: `${scope.key}_days_${seq}`, label: localize("保存天数"), type: "number", optional: true }, rule.days ?? "");
    const row = el("div", { className: "retention-scope-row", dataset: { scopeRow: scope.key } }, [target, mode, days]);
    row.append(button(localize("移除"), () => {
      row.remove();
      list.closest("form")?.dispatchEvent(new Event("input", { bubbles: true }));
    }, "secondary retention-scope-remove", { "aria-label": localize`移除这条${scope.title}` }));
    list.append(row);
  };
  for (const [targetId, rule] of Object.entries(entries || {})) {
    addRow(targetId, rule || {});
  }
  return el("fieldset", { className: "retention-scope", dataset: { scope: scope.key } }, [
    el("legend", { text: scope.title }),
    el("p", { className: "field-hint", text: scope.hint }),
    list,
    button(localize`添加${scope.title}`, () => addRow(), "secondary retention-scope-add")
  ]);
}

function readRetentionScope(form, scope) {
  const result = {};
  for (const row of form.querySelectorAll(`[data-scope-row="${scope.key}"]`)) {
    const target = row.querySelector(`[name^="${scope.key}_target_"]`)?.value.trim() || "";
    if (!target) continue;
    const mode = row.querySelector(`[name^="${scope.key}_mode_"]`)?.value || "summary";
    const daysRaw = row.querySelector(`[name^="${scope.key}_days_"]`)?.value.trim() || "";
    result[target] = { mode, ...(daysRaw !== "" ? { days: Number(daysRaw) } : {}) };
  }
  return result;
}

export async function renderContentRetentionPolicyPage(content) {
  clear(content);
  const [result, departmentOptions] = await Promise.all([
    api("/admin-api/system/content-retention"),
    departmentSelectOptions()
  ]);
  const data = result.data || {};
  const fields = [
    { name: "mode", label: localize("默认模式"), type: "select", options: RETENTION_MODES },
    { name: "default_days", label: localize("默认保存天数"), type: "number" },
    { name: "max_days", label: localize("最大保存天数"), type: "number" },
    { name: "raw_allowed_roles", label: localize("可查看原文的角色"), type: "multiselect", options: RAW_ROLE_OPTIONS, optional: true }
  ];
  const form = guardForm(el("form", { className: "edit-form retention-policy-form", onSubmit: (event) => event.preventDefault() }));
  const values = { ...data, raw_allowed_roles: data.raw_allowed_roles || ["super_admin"] };
  for (const config of fields) {
    form.append(field(config, values[config.name]));
  }
  form.querySelector('select[name="mode"]').value = values.mode || "summary";
  for (const scope of RETENTION_SCOPES) {
    form.append(retentionScopeEditor(scope, data[scope.key], departmentOptions));
  }
  const output = el("p", { className: "notice", hidden: true });
  form.append(el("div", { className: "toolbar-actions" }, [button(localize("保存策略"), async () => {
    try {
      const payload = readFields(form, fields, { validate: true });
      payload.raw_allowed_roles = Array.isArray(payload.raw_allowed_roles) ? payload.raw_allowed_roles : [];
      for (const scope of RETENTION_SCOPES) {
        payload[scope.key] = readRetentionScope(form, scope);
      }
      const saved = await api("/admin-api/system/content-retention", {
        method: "PUT",
        body: JSON.stringify(payload)
      });
      markClean(form);
      setOperationOutput(output, saved.data, localize("策略已保存"));
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, error.code === "FORM_INVALID" ? error.message : localize("保存策略失败，请检查输入内容")));
    }
  })]));
  form.append(output);
  content.append(
    pageHead(localize("内容留存策略"), localize("调用内容默认保存摘要、脱敏内容还是原文，以及保存多久；可按部门、用户或密钥单独设置。")),
    el("section", { className: "panel settings-form-panel" }, [form])
  );
}

function contentArchiveQuery(form, filters, state) {
  const query = readFields(form, filters, { validate: true });
  for (const key of ["from", "to"]) {
    if (query[key]) {
      query[key] = utcTimestamp(query[key]);
    }
  }
  if (query.from && query.to && query.from >= query.to) {
    throw new Error(localize("开始时间必须早于结束时间"));
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
const utcTimestamp = fromLocalInputValue;
const toLocalInput = toLocalInputValue;

const ARCHIVE_COLUMNS = ["content_id", "request_id", "session_id", "user_id", "department_id", "api_key_id", "model_name", "status", "retention_mode", "created_at", "cleaned_at"];

const REFERENCE_HEADERS = { user_id: localize("用户"), department_id: localize("部门"), api_key_id: localize("API 密钥") };

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
  headRow.append(el("th", { scope: "col", className: "table-cell--actions", text: localize("操作") }));
  const body = el("tbody");
  for (const row of rows) {
    const tr = el("tr");
    for (const column of ARCHIVE_COLUMNS) {
      tr.append(el("td", { text: archiveCell(column, row, references) }));
    }
    tr.append(el("td", { className: "table-cell--actions" }, [button(localize("查看"), async () => {
      const result = await api(`/admin-api/content-archive/${row.content_id}`, { query: { include_raw: includeRaw ? "true" : "false" } });
      showModal(localize("会话存档详情"), renderDataDetails(result.data));
    }, "secondary", { "aria-label": localize`查看存档 ${row.content_id} 详情` })]));
    body.append(tr);
  }
  table.append(el("thead", {}, [headRow]), body);
  return el("div", { className: "table-scroll" }, [table]);
}
