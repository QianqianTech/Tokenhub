import { getLocale, localize } from "../i18n.js";
// Shared helpers of the operations tools (provider catalog, API key tools,
// diagnostics, capability editor): viewer checks and detail-grid rendering.
import { el, setText } from "../dom.js";
import { getCurrentUser, hasCapability } from "../core/session.js";
import { formatDateTime } from "./format.js";

// The signed-in administrator comes from core/session.js (set by the app shell).
// Server-side authority is route capability + data scope; these checks only
// hide actions the server would refuse anyway.
export function adminViewerIsSuperAdmin() {
  return getCurrentUser()?.role === "super_admin";
}

export function adminViewerCan(capability) {
  return hasCapability(capability);
}

// Mirrors PermissionService::usesGlobalScope (catalog sync, operations).
export function adminViewerHasGlobalScope() {
  const viewer = getCurrentUser();
  return adminViewerIsSuperAdmin() || (viewer?.role === "admin" && viewer?.admin_scope === "global");
}

export function adminViewerCanSyncCatalog() {
  return adminViewerHasGlobalScope() && adminViewerCan("api_catalog.manage");
}

export function opsText(value, fallback = "-") {
  if (value === null || value === undefined || value === "") {
    return fallback;
  }
  return String(value);
}

export function opsNumber(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return opsText(value);
  }
  return Number.isInteger(number) ? number.toLocaleString(getLocale()) : number.toLocaleString(getLocale(), { maximumFractionDigits: 6 });
}

export function opsUtcTime(value) {
  if (!value) {
    return "-";
  }
  // Server timestamps are UTC; shown in the viewer's local time.
  return formatDateTime(value) || String(value);
}

export function opsDetailGrid(pairs) {
  return el("div", { className: "ops-detail-grid" }, pairs.map(([label, value]) => el("div", { className: "ops-detail-row" }, [
    el("span", { text: label }),
    el("strong", { text: opsText(value) })
  ])));
}

export function opsSection(title, children, className = "") {
  return el("section", { className: `ops-section ${className}`.trim() }, [
    el("h3", { text: title }),
    ...[].concat(children)
  ]);
}

export async function copyTextToClipboard(text, feedback, sourceNode = null) {
  try {
    await navigator.clipboard.writeText(text);
    setText(feedback, localize("已复制到剪贴板"));
  } catch {
    if (sourceNode && window.getSelection) {
      const range = document.createRange();
      range.selectNodeContents(sourceNode);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }
    setText(feedback, localize("浏览器未允许自动复制，已选中密钥，请按 Ctrl+C 手动复制"));
  }
  feedback.hidden = false;
}

export function parseJsonValue(value) {
  if (typeof value === "string") {
    const trimmed = value.trim();
    if (trimmed === "") {
      return null;
    }
    try {
      return JSON.parse(trimmed);
    } catch {
      return null;
    }
  }
  return value ?? null;
}
