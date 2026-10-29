// Client for GET /admin-api/lookups/{type}: searchable reference data for
// pickers and id -> label resolution for tables. Results are cached briefly so a
// page refresh does not re-download the same references.
import { api } from "../api.js";
import { hasCapability } from "../core/session.js";
import { valueLabel } from "../resources.js";

const CACHE_TTL_MS = 60_000;
const rowCache = new Map(); // `${type}:${id}` -> { row, at }
const searchCache = new Map(); // `${type}?${query}` -> { rows, at }

export const LOOKUP_CAPABILITIES = {
  users: "users.read",
  departments: "departments.read",
  "api-keys": "api_keys.read",
  models: "api_catalog.read",
  providers: "api_catalog.read",
  "quota-policies": "quotas.read",
  "rag-knowledge-bases": "rag.read",
  "rag-vector-datasets": "rag.read",
  "rag-vector-configs": "rag.read"
};

// Foreign-key columns that tables resolve to readable names.
export const COLUMN_LOOKUPS = {
  user_id: "users",
  owner_user_id: "users",
  department_id: "departments",
  model_id: "models",
  provider_id: "providers",
  api_key_id: "api-keys",
  quota_policy_id: "quota-policies"
};

export function canLookup(type) {
  return hasCapability(LOOKUP_CAPABILITIES[type]);
}

export function lookupLabel(type, row) {
  if (!row) {
    return "";
  }
  switch (type) {
    case "users":
      return [row.username || `用户 #${row.id}`, row.role ? valueLabel("role", row.role) : "", row.status && row.status !== "active" ? valueLabel("status", row.status) : ""].filter(Boolean).join(" / ");
    case "departments":
      return row.name || `部门 #${row.id}`;
    case "api-keys":
      return [row.name || `API 密钥 #${row.id}`, row.key_suffix ? `${row.key_prefix || "sk"}...${row.key_suffix}` : ""].filter(Boolean).join(" / ");
    case "models":
      return `${row.name || `模型 #${row.id}`}${row.external_name && row.external_name !== row.name ? ` / ${row.external_name}` : ""}`;
    case "quota-policies":
      return [row.name || `策略 #${row.id}`, row.period ? valueLabel("period", row.period) : ""].filter(Boolean).join(" / ");
    case "rag-knowledge-bases":
      return `${row.name || `知识库 #${row.id}`}${row.kb_id ? ` (${row.kb_id})` : ""}`;
    case "rag-vector-datasets":
      return row.name || row.dataset_id || `数据集 #${row.id}`;
    case "rag-vector-configs":
      return [row.name || `向量配置 #${row.id}`, row.modality, row.model_name].filter(Boolean).join(" / ");
    default:
      return row.name || row.username || `#${row.id}`;
  }
}

function fresh(entry) {
  return entry && Date.now() - entry.at < CACHE_TTL_MS;
}

export function clearLookupCache(type = null) {
  for (const cache of [rowCache, searchCache]) {
    for (const key of [...cache.keys()]) {
      if (type === null || key.startsWith(`${type}:`) || key.startsWith(`${type}?`)) {
        cache.delete(key);
      }
    }
  }
}

/** Keyword search (empty keyword lists the most recent rows). */
export async function searchLookup(type, { q = "", limit = 20, ...filters } = {}) {
  if (!canLookup(type)) {
    return [];
  }
  const query = { q, limit, ...filters };
  const key = `${type}?${JSON.stringify(query)}`;
  const cached = searchCache.get(key);
  if (fresh(cached)) {
    return cached.rows;
  }
  const result = await api(`/admin-api/lookups/${type}`, { query, silent: true });
  const rows = Array.isArray(result.data) ? result.data : [];
  const at = Date.now();
  searchCache.set(key, { rows, at });
  for (const row of rows) {
    rowCache.set(`${type}:${row.id}`, { row, at });
  }
  return rows;
}

/** Resolves ids to rows, fetching only those not cached. Returns Map<number, row>. */
export async function resolveLookup(type, ids) {
  const wanted = [...new Set([...ids].map(Number).filter((id) => Number.isFinite(id) && id > 0))];
  const byId = new Map();
  const missing = [];
  for (const id of wanted) {
    const cached = rowCache.get(`${type}:${id}`);
    if (fresh(cached)) {
      byId.set(id, cached.row);
    } else {
      missing.push(id);
    }
  }
  if (missing.length > 0 && canLookup(type)) {
    for (let index = 0; index < missing.length; index += 200) {
      const chunk = missing.slice(index, index + 200);
      try {
        const result = await api(`/admin-api/lookups/${type}`, { query: { ids: chunk.join(",") }, silent: true });
        const at = Date.now();
        for (const row of Array.isArray(result.data) ? result.data : []) {
          rowCache.set(`${type}:${row.id}`, { row, at });
          byId.set(Number(row.id), row);
        }
      } catch {
        // Labels are a convenience: fall back to raw ids when a lookup fails.
      }
    }
  }
  return byId;
}

function idsFrom(value) {
  if (Array.isArray(value)) {
    return value;
  }
  if (typeof value === "string" && value.trim().startsWith("[")) {
    try {
      const parsed = JSON.parse(value);
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }
  return value === null || value === undefined || value === "" ? [] : [value];
}

/**
 * For table rows: resolves every foreign-key column to rows, keyed by lookup
 * type. extra: [[type, ids]] for references not expressed as plain columns.
 */
export async function resolveRowReferences(rows, columns = [], extra = []) {
  const wanted = new Map();
  const add = (type, values) => {
    if (!type || !canLookup(type)) {
      return;
    }
    if (!wanted.has(type)) {
      wanted.set(type, new Set());
    }
    for (const value of values) {
      wanted.get(type).add(value);
    }
  };
  for (const column of columns) {
    const type = COLUMN_LOOKUPS[column];
    if (type) {
      add(type, rows.flatMap((row) => idsFrom(row[column])));
    }
  }
  for (const [type, ids] of extra) {
    add(type, ids);
  }
  const entries = await Promise.all([...wanted.entries()].map(async ([type, ids]) => [type, await resolveLookup(type, ids)]));
  return Object.fromEntries(entries);
}
