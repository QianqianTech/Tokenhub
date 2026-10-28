import { resources, resourcesForUser } from "./resources.js?v=20261028-admin-ops-v1";

export const adminDomains = [
  { key: "permission", label: "权限配置", tabs: ["permission-config"] },
  { key: "operations", label: "运营中心", tabs: ["dashboard", "admin-chat-sessions", "dashboard-share-links", "dashboard-share-access-logs"] },
  { key: "organization", label: "组织与权限", tabs: ["departments", "users", "admin-users"] },
  { key: "models", label: "模型服务", tabs: ["providers", "models", "model-prices"] },
  { key: "access", label: "API 接入", tabs: ["api-keys", "api-key-model-quotas", "quota-policies", "quota-bindings"] },
  { key: "usage", label: "用量中心", tabs: ["usage-records", "multimodal-tasks", "media-assets", "usage-contents", "content-archive", "export-tasks"] },
  { key: "rag", label: "RAG 知识库", tabs: ["rag-build", "rag-retrieval-lab", "rag-quality", "rag-operations"] },
  { key: "security", label: "安全审计", tabs: ["desensitization-policies", "desensitization-events", "alert-events", "logs", "audit-logs"] },
  { key: "system", label: "系统运维", superAdmin: true, tabs: ["operations-status", "settings-tls-certificates", "settings-brand-identity", "content-retention-policy", "log-retention-policies", "log-archive-files", "settings-alert-thresholds", "system-settings"] }
];

const permissionDomain = adminDomains.shift();
const organizationDomain = adminDomains.find((domain) => domain.key === "organization");
if (permissionDomain && organizationDomain) {
  organizationDomain.tabs.push(...permissionDomain.tabs);
}

export function domainsForRole(role) {
  return adminDomains.filter((domain) => !domain.superAdmin || role === "super_admin").map((domain) => ({
    ...domain,
    tabs: domain.tabs.filter((tab) => tab === "dashboard" || tab === "operations-status" || resources[tab])
  }));
}

export function domainsForUser(user) {
  const allowedResources = new Set(resourcesForUser(user).map(([key]) => key));
  const permissions = new Set(user?.permissions || []);
  const specialCapabilities = {
    dashboard: "dashboard.read",
    "operations-status": "operations.read",
    "rag-settings": "rag.read"
  };
  return adminDomains.map((domain) => ({
    ...domain,
    tabs: domain.tabs.filter((tab) => allowedResources.has(tab) || permissions.has(specialCapabilities[tab]))
  })).filter((domain) => domain.tabs.length > 0);
}

export function parseAdminHash(userOrRole) {
  const domains = typeof userOrRole === "string" ? domainsForRole(userOrRole) : domainsForUser(userOrRole);
  const [domainKey, tabKey] = window.location.hash.replace(/^#\/?/, "").split("/");
  const domain = domains.find((item) => item.key === domainKey) || domains[0];
  return { domainKey: domain?.key || "operations", tabKey: domain?.tabs.includes(tabKey) ? tabKey : domain?.tabs[0] || "dashboard" };
}

export function writeAdminHash(domainKey, tabKey) {
  const next = `#/${domainKey}/${tabKey}`;
  if (window.location.hash !== next) window.location.hash = next;
}
