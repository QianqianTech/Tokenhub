import { pageCapability } from "./resources.js";
import { buildHash, parseHash } from "./core/router.js";

// Sidebar domains and their pages. Visibility is derived only from the
// capabilities returned by /admin-api/me (see pageCapability in resources.js).
export const adminDomains = [
  { key: "operations", label: "运营中心", tabs: ["dashboard", "admin-chat-sessions", "dashboard-share-links", "dashboard-share-access-logs"] },
  { key: "organization", label: "组织与权限", tabs: ["departments", "users", "admin-users", "permission-config"] },
  { key: "models", label: "模型服务", tabs: ["providers", "models", "model-prices"] },
  { key: "access", label: "API 接入", tabs: ["api-keys", "api-key-model-quotas", "quota-policies", "quota-bindings"] },
  { key: "usage", label: "用量中心", tabs: ["usage-records", "multimodal-tasks", "media-assets", "usage-contents", "content-archive", "export-tasks"] },
  { key: "rag", label: "RAG 知识库", tabs: ["rag-build", "rag-retrieval-lab", "rag-quality", "rag-operations"] },
  { key: "security", label: "安全审计", tabs: ["desensitization-policies", "desensitization-events", "alert-events", "logs", "audit-logs"] },
  { key: "system", label: "系统运维", tabs: ["operations-status", "settings-tls-certificates", "settings-brand-identity", "content-retention-policy", "log-retention-policies", "log-archive-files", "settings-alert-thresholds", "system-settings"] }
];

export function pageAllowed(user, page) {
  const capability = pageCapability(page);
  return capability !== null && Array.isArray(user?.permissions) && user.permissions.includes(capability);
}

export function domainsForUser(user) {
  return adminDomains
    .map((domain) => ({ ...domain, tabs: domain.tabs.filter((tab) => pageAllowed(user, tab)) }))
    .filter((domain) => domain.tabs.length > 0);
}

export function domainForPage(user, page) {
  return domainsForUser(user).find((domain) => domain.tabs.includes(page)) || null;
}

// Resolves the current URL to a page the user may open, falling back to the
// first permitted page of the requested domain (or of the first domain).
export function parseAdminHash(user) {
  const domains = domainsForUser(user);
  const route = parseHash();
  const domain = domains.find((item) => item.key === route.domainKey) || domains[0];
  const tabKey = domain?.tabs.includes(route.tabKey) ? route.tabKey : domain?.tabs[0] || "dashboard";
  const exact = domain?.key === route.domainKey && tabKey === route.tabKey;
  return { domainKey: domain?.key || "operations", tabKey, filters: exact ? route.filters : {}, exact };
}

export function adminHash(user, page, filters = {}) {
  const domain = domainForPage(user, page);
  return domain ? buildHash(domain.key, page, filters) : null;
}
