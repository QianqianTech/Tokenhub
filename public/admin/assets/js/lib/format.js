// Display formatting shared by tables and detail views.

const DATE_COLUMN = /(_at|_time|^timestamp|^date)$/;

export function isDateColumn(column) {
  return DATE_COLUMN.test(String(column));
}

// Server timestamps arrive as "YYYY-MM-DD HH:MM:SS" (server time) or ISO 8601.
// ISO values carrying a zone are shown in the viewer's local time; plain
// server timestamps are shown as-is without seconds noise.
export function formatDateTime(value) {
  if (value === null || value === undefined || value === "") {
    return "";
  }
  const text = String(value);
  if (/[zZ]|[+-]\d{2}:?\d{2}$/.test(text) && text.includes("T")) {
    const parsed = new Date(text);
    if (!Number.isNaN(parsed.getTime())) {
      return parsed.toLocaleString("zh-CN", { hour12: false });
    }
  }
  const match = text.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/);
  return match ? `${match[1]} ${match[2]}` : text;
}

export function formatNumber(value, { digits = 0 } = {}) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return "0";
  }
  return number.toLocaleString("zh-CN", { maximumFractionDigits: digits, minimumFractionDigits: 0 });
}

const POSITIVE = new Set(["active", "enabled", "succeeded", "success", "published", "approved", "valid", "resolved", "healthy", "verified"]);
const NEGATIVE = new Set(["failed", "error", "blocked", "rejected", "timeout", "unhealthy"]);
// Switched off or ended on purpose: not an error, shown in a neutral tone.
const MUTED = new Set(["disabled", "deleted", "expired", "departed", "revoked", "closed", "archived"]);
const PENDING = new Set(["queued", "processing", "pending", "draft", "waiting_review", "pending_review", "reindexing", "open", "acknowledged", "expiring_soon", "running", "requested", "validating", "deploying"]);

export function statusTone(status) {
  const key = String(status || "").toLowerCase();
  if (POSITIVE.has(key)) return "is-positive";
  if (NEGATIVE.has(key)) return "is-negative";
  if (MUTED.has(key)) return "is-muted";
  if (PENDING.has(key)) return "is-pending";
  return "";
}
