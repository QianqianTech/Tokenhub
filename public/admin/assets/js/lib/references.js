// Reference data for tables and forms: id -> label resolution through
// /admin-api/lookups (only the ids on screen are fetched) and conversion of
// form fields that reference other records into searchable pickers.
import { api } from "../api.js";
import { adminPermissionOptions, formatResourceValue, valueLabel } from "../resources.js";
import { COLUMN_LOOKUPS, lookupLabel, resolveLookup, resolveRowReferences } from "./lookups.js";

const LIST_PAGE_SIZE = 100;

/**
 * Loads every row of a (small) resource list by paging through it. The list
 * endpoints cap a page at 100 rows, so a single "limit: 500" request silently
 * truncated larger lists.
 */
export async function lookupRows(resourceKey, { max = 1000, query = {} } = {}) {
  const rows = [];
  for (let offset = 0; offset < max; offset += LIST_PAGE_SIZE) {
    let result;
    try {
      result = await api(`/admin-api/${resourceKey}`, { query: { ...query, limit: LIST_PAGE_SIZE, offset }, silent: true });
    } catch {
      break;
    }
    const page = Array.isArray(result.data) ? result.data : (Array.isArray(result.data?.items) ? result.data.items : []);
    rows.push(...page);
    if (page.length < LIST_PAGE_SIZE) {
      break;
    }
  }
  return rows;
}


function contextFrom(lookups) {
  return {
    lookups,
    usersById: lookups.users || new Map(),
    departmentsById: lookups.departments || new Map(),
    modelsById: lookups.models || new Map(),
    providersById: lookups.providers || new Map(),
    apiKeysById: lookups["api-keys"] || new Map(),
    quotaPoliciesById: lookups["quota-policies"] || new Map()
  };
}

/** Resolves the references shown in a table page into label maps. */
export async function renderContext(resourceKey, rows = [], columns = []) {
  const extra = [];
  if (resourceKey === "admin-users") {
    extra.push(["departments", rows.flatMap((row) => normalizeIdList(row.managed_department_ids))]);
  }
  if (resourceKey === "api-keys") {
    extra.push(["models", rows.flatMap((row) => normalizeIdList(row.model_ids))]);
  }
  if (resourceKey === "quota-bindings") {
    for (const [targetType, lookup] of [["api_key", "api-keys"], ["user", "users"], ["department", "departments"]]) {
      extra.push([lookup, rows.filter((row) => row.target_type === targetType).map((row) => row.target_id)]);
    }
  }
  const lookups = await resolveRowReferences(rows, [...new Set([...columns, ...Object.keys(COLUMN_LOOKUPS)])], extra);
  if (resourceKey === "api-key-model-quotas" || resourceKey === "quota-bindings") {
    // API key labels include the owning user.
    const apiKeys = [...(lookups["api-keys"]?.values() || [])];
    const users = await resolveLookup("users", apiKeys.map((key) => key.user_id));
    lookups.users = new Map([...(lookups.users || new Map()), ...users]);
  }
  return contextFrom(lookups);
}

export function userOptionLabel(user) {
  return lookupLabel("users", user);
}

export function apiKeyUserLabel(value, context = {}) {
  const user = context.usersById?.get(Number(value));
  return user ? userOptionLabel(user) : formatResourceValue("user_id", value);
}

export function apiKeyDepartmentLabel(value, context = {}) {
  const department = context.departmentsById?.get(Number(value));
  return department?.name || formatResourceValue("department_id", value);
}

export function normalizeIdList(value) {
  if (Array.isArray(value)) {
    return value.map(Number).filter((item) => Number.isFinite(item));
  }
  if (typeof value === "string" && value.trim() !== "") {
    try {
      const parsed = JSON.parse(value);
      if (Array.isArray(parsed)) {
        return parsed.map(Number).filter((item) => Number.isFinite(item));
      }
    } catch {
      return value.split(",").map((item) => Number(item.trim())).filter((item) => Number.isFinite(item));
    }
  }
  return [];
}

export function apiKeyModelLabels(value, context = {}) {
  const ids = normalizeIdList(value);
  if (ids.length === 0) {
    return "";
  }
  return ids.map((id) => modelQuotaModelLabel(id, context)).join(", ");
}

export function adminUserPermissionLabels(value) {
  let permissions = value;
  if (typeof value === "string") {
    try {
      permissions = JSON.parse(value);
    } catch {
      permissions = value.split(",");
    }
  }
  if (!Array.isArray(permissions)) {
    return "";
  }
  const labels = new Map(adminPermissionOptions);
  return permissions.map((permission) => labels.get(String(permission)) || String(permission)).join("、");
}

export function adminUserDepartmentLabels(value, context = {}) {
  const ids = normalizeIdList(value);
  return ids.map((id) => apiKeyDepartmentLabel(id, context)).join("、");
}

export function quotaPolicyLabel(value, context = {}) {
  const policy = context.quotaPoliciesById?.get(Number(value));
  if (!policy) {
    return formatResourceValue("quota_policy_id", value);
  }
  return [policy.name || `策略 #${policy.id}`, valueLabel("period", policy.period), policy.status ? valueLabel("status", policy.status) : ""]
    .filter(Boolean)
    .join(" / ");
}

export function quotaTargetRef(rowOrType, targetId) {
  if (typeof rowOrType === "object" && rowOrType !== null) {
    return rowOrType.target_type ? `${rowOrType.target_type || ""}:${rowOrType.target_id || ""}` : "";
  }
  return `${rowOrType || ""}:${targetId || ""}`;
}

export function quotaTargetLabel(targetType, targetId, context = {}) {
  if (targetType === "api_key") {
    return modelQuotaApiKeyLabel(targetId, context);
  }
  if (targetType === "user") {
    return apiKeyUserLabel(targetId, context);
  }
  if (targetType === "department") {
    return apiKeyDepartmentLabel(targetId, context);
  }
  return formatResourceValue("target_id", targetId);
}

export function modelQuotaApiKeyLabel(value, context = {}) {
  const apiKey = context.apiKeysById?.get(Number(value));
  if (!apiKey) {
    return formatResourceValue("api_key_id", value);
  }
  const name = apiKey.name || `API 密钥 #${apiKey.id}`;
  const user = context.usersById?.get(Number(apiKey.user_id));
  const userLabel = user?.username || (apiKey.user_id ? `用户 #${apiKey.user_id}` : "");
  const keyDisplay = apiKey.display || (apiKey.key_suffix ? `****${apiKey.key_suffix}` : "");
  return [name, userLabel, keyDisplay].filter(Boolean).join(" / ");
}

export function modelQuotaModelLabel(value, context = {}) {
  const model = context.modelsById?.get(Number(value));
  if (!model) {
    return formatResourceValue("model_id", value);
  }
  return lookupLabel("models", model);
}

/** Generic label for a foreign-key cell, or null when the column is not a reference. */
export function referenceCellLabel(column, value, context = {}) {
  const type = COLUMN_LOOKUPS[column];
  const row = type ? context.lookups?.[type]?.get(Number(value)) : null;
  return row ? `${lookupLabel(type, row)} · #${value}` : null;
}

function flattenDepartmentOptions(rows, depth = 0) {
  return rows.flatMap((department) => {
    const label = `${"　".repeat(depth)}${department.name || `#${department.id}`} (#${department.id})`;
    return [[String(department.id), label], ...flattenDepartmentOptions(department.children || [], depth + 1)];
  });
}

export async function departmentSelectOptions() {
  const result = await api("/admin-api/departments/tree", { silent: true }).catch(() => ({ data: [] }));
  return flattenDepartmentOptions(Array.isArray(result.data) ? result.data : []);
}

// form reference -> lookup type for searchable pickers
const REFERENCE_LOOKUPS = {
  users: "users",
  apiKeys: "api-keys",
  models: "models",
  providers: "providers",
  quotaPolicies: "quota-policies",
  knowledgeBases: "rag-knowledge-bases",
  vectorConfigs: "rag-vector-configs"
};

const QUOTA_TARGET_SOURCES = [
  { lookup: "api-keys", prefix: "api_key", label: "API 密钥" },
  { lookup: "users", prefix: "user", label: "用户" },
  { lookup: "departments", prefix: "department", label: "部门" }
];

/**
 * Turns fields that reference other records into pickers:
 * departments -> tree select (hierarchy matters, the tree is small),
 * everything else -> searchable remote select backed by /admin-api/lookups.
 */
export async function lookupAwareFields(fields) {
  const needsDepartments = fields.some((config) => config.reference === "departments" || (config.name === "department_id" && config.type === "number"));
  const departmentOptions = needsDepartments ? await departmentSelectOptions() : [];
  return fields.map((config) => {
    const isDepartment = config.reference === "departments" || (config.name === "department_id" && config.type === "number");
    if (isDepartment) {
      const multiple = config.type === "multiselect";
      const empty = config.emptyOption ? [["", config.emptyOption]] : config.optional && !multiple ? [["", "不选择部门"]] : [];
      return { ...config, type: multiple ? "multiselect" : "select", coerce: "number", options: [...empty, ...departmentOptions] };
    }
    if (config.reference === "quotaTargets") {
      return { ...config, type: "remote-select", sources: QUOTA_TARGET_SOURCES };
    }
    const lookup = REFERENCE_LOOKUPS[config.reference];
    if (!lookup) {
      return config;
    }
    return {
      ...config,
      type: config.type === "multiselect" ? "remote-multiselect" : "remote-select",
      lookup
    };
  });
}
