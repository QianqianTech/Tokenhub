import { localize } from "./i18n.js";
import { pageCapability } from "./resources.js";
import { buildHash, parseHash } from "./core/router.js";

// Sidebar domains and their pages. Visibility is derived only from the
// capabilities returned by /admin-api/me (see pageCapability in resources.js).
export const adminDomains = [
  { key: "operations", label: localize("运营中心"), tabs: ["dashboard", "admin-chat-sessions", "dashboard-share-links", "dashboard-share-access-logs"] },
  { key: "organization", label: localize("组织与权限"), tabs: ["departments", "users", "admin-users", "permission-config"] },
  { key: "models", label: localize("模型服务"), tabs: ["providers", "models", "model-prices"] },
  { key: "access", label: localize("API 接入"), tabs: ["api-keys", "conversation-logs", "api-key-model-quotas", "quota-policies", "quota-bindings"] },
  { key: "usage", label: localize("用量中心"), tabs: ["usage-records", "multimodal-tasks", "media-assets", "usage-contents", "content-archive", "export-tasks"] },
  { key: "rag", label: localize("RAG 知识库"), tabs: ["rag-build", "rag-retrieval-lab", "rag-quality", "rag-operations"] },
  { key: "security", label: localize("安全审计"), tabs: ["desensitization-policies", "desensitization-events", "alert-events", "logs", "audit-logs", "log-retention-policies", "log-archive-files"] },
  { key: "system", label: localize("系统设置"), tabs: ["operations-status", "settings-brand-identity", "settings-tls-certificates", "settings-smtp", "settings-alert-thresholds", "content-retention-policy", "conversation-log-settings", "system-settings"], groups: [
    { label: localize("运维"), tabs: ["operations-status"] },
    { label: localize("平台与入口"), tabs: ["settings-brand-identity", "settings-tls-certificates"] },
    { label: localize("通知与告警"), tabs: ["settings-smtp", "settings-alert-thresholds"] },
    { label: localize("数据与高级"), tabs: ["content-retention-policy", "conversation-log-settings", "system-settings"] }
  ] }
];

// Sub-group label shown in the sidebar before `tab`, when the domain is grouped.
export function groupLabelBefore(domain, tab) {
  const groups = adminDomains.find((item) => item.key === domain.key)?.groups;
  if (!groups) {
    return "";
  }
  const visible = new Set(domain.tabs);
  const group = groups.find((item) => item.tabs.filter((key) => visible.has(key))[0] === tab);
  return group?.label || "";
}

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
  let domain = domains.find((item) => item.key === route.domainKey);
  let moved = false;
  if (!domain?.tabs.includes(route.tabKey)) {
    // A page that moved to another domain (e.g. #/system/log-archive-files,
    // now under 安全审计) keeps working from old links and bookmarks.
    const home = domains.find((item) => item.tabs.includes(route.tabKey));
    if (home) {
      domain = home;
      moved = true;
    }
  }
  domain ||= domains[0];
  const tabKey = domain?.tabs.includes(route.tabKey) ? route.tabKey : domain?.tabs[0] || "dashboard";
  const sameTab = tabKey === route.tabKey;
  const exact = domain?.key === route.domainKey && sameTab;
  return { domainKey: domain?.key || "operations", tabKey, filters: exact || (moved && sameTab) ? route.filters : {}, exact };
}

export function adminHash(user, page, filters = {}) {
  const domain = domainForPage(user, page);
  return domain ? buildHash(domain.key, page, filters) : null;
}
