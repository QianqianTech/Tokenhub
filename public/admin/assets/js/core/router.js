// Hash routing for the admin SPA.
// URL format: #/<domain>/<page>?<filters>
// The hash is the single source of truth: navigation only writes the hash and
// one hashchange handler renders, so a click never renders a page twice and
// filters survive refresh, back/forward and sharing a link.

// Filters that only make sense for the navigation that set them (e.g. "open the
// create dialog"); they are handed to the page once and then dropped from the URL.
const TRANSIENT_FILTERS = new Set(["openCreate"]);

function encodeValue(value) {
  if (Array.isArray(value) || (value && typeof value === "object")) {
    return JSON.stringify(value);
  }
  return String(value);
}

function decodeValue(raw) {
  if ((raw.startsWith("[") && raw.endsWith("]")) || (raw.startsWith("{") && raw.endsWith("}"))) {
    try {
      return JSON.parse(raw);
    } catch {
      return raw;
    }
  }
  if (raw === "true") return true;
  if (raw === "false") return false;
  return raw;
}

export function encodeFilters(filters = {}) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === null || value === "" || (Array.isArray(value) && value.length === 0)) {
      continue;
    }
    search.set(key, encodeValue(value));
  }
  return search.toString();
}

export function decodeFilters(query = "") {
  const filters = {};
  for (const [key, value] of new URLSearchParams(query)) {
    filters[key] = decodeValue(value);
  }
  return filters;
}

export function parseHash(hash = window.location.hash) {
  const raw = String(hash || "").replace(/^#\/?/, "");
  const queryIndex = raw.indexOf("?");
  const path = queryIndex >= 0 ? raw.slice(0, queryIndex) : raw;
  const query = queryIndex >= 0 ? raw.slice(queryIndex + 1) : "";
  const [domainKey = "", tabKey = ""] = path.split("/").map((part) => decodeURIComponent(part));
  return { domainKey, tabKey, filters: decodeFilters(query) };
}

export function buildHash(domainKey, tabKey, filters = {}) {
  const query = encodeFilters(filters);
  return `#/${encodeURIComponent(domainKey)}/${encodeURIComponent(tabKey)}${query ? `?${query}` : ""}`;
}

export function withoutTransientFilters(filters = {}) {
  return Object.fromEntries(Object.entries(filters).filter(([key]) => !TRANSIENT_FILTERS.has(key)));
}

export function hasTransientFilters(filters = {}) {
  return Object.keys(filters).some((key) => TRANSIENT_FILTERS.has(key));
}

// Rewrites the current URL without triggering hashchange (no re-render).
export function replaceHash(hash) {
  if (window.location.hash !== hash) {
    window.history.replaceState(window.history.state, "", hash);
  }
}
