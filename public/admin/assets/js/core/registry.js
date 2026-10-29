// Page registry: maps a page key to the module that renders it.
// Modules are loaded on demand with dynamic import(), so the entry bundle
// only contains the shell, router and shared helpers.
import { resources } from "../resources.js";

const RAG_CATEGORY_PAGES = new Set([
  "rag-build", "rag-quality", "rag-operations", "rag-console", "rag-knowledge-management",
  "rag-import-review", "rag-image-vector", "rag-feedback-correction", "rag-advanced-data"
]);

const loaders = {
  dashboard: () => import("../dashboard.js"),
  apiKeys: () => import("../pages/api-keys.js"),
  chat: () => import("../pages/chat.js"),
  alerts: () => import("../pages/alerts.js"),
  operations: () => import("../pages/operations.js"),
  settings: () => import("../pages/settings.js"),
  systemSettings: () => import("../pages/system-settings.js"),
  permissionConfig: () => import("../pages/permission-config.js"),
  content: () => import("../pages/content.js"),
  rag: () => import("../pages/rag.js"),
  ragUpload: () => import("../pages/rag-upload.js"),
  ragQuality: () => import("../pages/rag-quality.js"),
  generic: () => import("../lib/resource-table.js")
};

// key -> [loader, (module, content, context) => Promise]
const pages = {
  dashboard: ["dashboard", (m, content, ctx) => m.renderDashboard(content, ctx.navigate)],
  "alert-events": ["alerts", (m, content) => m.renderAlertEventsPage(content)],
  "operations-status": ["operations", (m, content) => m.renderOperationsStatusPage(content)],
  "rag-settings": ["settings", (m, content) => m.renderSettingsCenterPage(content)],
  "settings-center": ["settings", (m, content) => m.renderSettingsCenterPage(content)],
  "rag-retrieval-lab": ["rag", (m, content) => m.renderRagRetrievalLabPage(content)],
  "rag-dashboard": ["rag", (m, content) => m.renderRagDashboardPage(content)],
  "rag-template-center": ["rag", (m, content) => m.renderRagTemplateCenterPage(content)],
  "rag-vectorization-metrics": ["rag", (m, content) => m.renderRagVectorizationMetricsPage(content)],
  "rag-upload-workbench": ["ragUpload", (m, content) => m.renderRagUploadWorkbenchPage(content)],
  "rag-upload-detail": ["ragUpload", (m, content, ctx) => m.renderRagUploadDetailPage(content, ctx.filters)],
  "rag-image-preview": ["ragUpload", (m, content, ctx) => m.renderRagImagePreviewPage(content, ctx.filters)],
  "rag-retrieval-evaluation": ["ragQuality", (m, content) => m.renderRagEvaluationPage(content)],
  "rag-evaluation": ["ragQuality", (m, content) => m.renderRagEvaluationPage(content)],
  "content-archive": ["content", (m, content, ctx) => m.renderContentArchivePage(content, ctx.filters, ctx)],
  "content-retention-policy": ["content", (m, content) => m.renderContentRetentionPolicyPage(content)],
  "permission-config": ["permissionConfig", (m, content) => m.renderPermissionConfigPage(content)],
  "system-settings": ["systemSettings", (m, content) => m.renderSystemSettingsPage(content)],
  "api-keys": ["apiKeys", (m, content, ctx) => m.renderApiKeyPage(content, ctx.filters, ctx)],
  "admin-chat-sessions": ["chat", (m, content) => m.renderAdminChatSessionPage(content)]
};

export function pageDefinition(key) {
  if (pages[key]) {
    const [loader, render] = pages[key];
    return { load: loaders[loader], render };
  }
  if (resources[key]?.settingsSection) {
    return { load: loaders.settings, render: (m, content) => m.renderSettingsSectionPage(content, key) };
  }
  if (RAG_CATEGORY_PAGES.has(key)) {
    return { load: loaders.rag, render: (m, content, ctx) => m.renderRagCategoryPage(content, key, ctx.filters) };
  }
  return { load: loaders.generic, render: (m, content, ctx) => m.renderGenericResourcePage(content, key, ctx.filters, ctx) };
}

export async function renderPage(key, content, context) {
  const definition = pageDefinition(key);
  const module = await definition.load();
  await definition.render(module, content, context);
}

// Warm the module cache for a page (e.g. on menu hover) without rendering it.
export function preloadPage(key) {
  pageDefinition(key).load().catch(() => {});
}
