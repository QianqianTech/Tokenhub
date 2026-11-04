// Display formatting shared by tables and detail views.

const DATE_COLUMN = /(_at|_time|^timestamp|^date)$/;

export function isDateColumn(column) {
  return DATE_COLUMN.test(String(column));
}

function pad(value) {
  return String(value).padStart(2, "0");
}

const ZONED = /([zZ]|[+-]\d{2}:?\d{2})$/;
const SERVER_STAMP = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?$/;

/**
 * Parses a server timestamp into a Date. The API stores and returns UTC:
 * "YYYY-MM-DD HH:MM:SS" (no zone) is read as UTC, ISO 8601 values with a zone
 * keep their zone. Returns null for anything else (plain dates, free text).
 */
export function parseServerTime(value) {
  if (value === null || value === undefined || value === "") {
    return null;
  }
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value;
  }
  const text = String(value).trim();
  if (text.includes("T") && ZONED.test(text)) {
    const parsed = new Date(text);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  }
  const match = text.match(SERVER_STAMP);
  if (!match) {
    return null;
  }
  const [, year, month, day, hour, minute, second = "0"] = match;
  const parsed = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second)));
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/** "YYYY-MM-DD HH:MM:SS" in the viewer's local time zone. */
export function localStamp(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

/** "YYYY-MM-DD HH:MM:SS" in UTC: the format list filters compare against. */
export function utcStamp(date) {
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}`;
}

// Server timestamps (UTC, with or without a zone suffix) are shown in the
// viewer's local time. Values that are not timestamps are returned unchanged.
export function formatDateTime(value) {
  if (value === null || value === undefined || value === "") {
    return "";
  }
  const parsed = parseServerTime(value);
  return parsed ? localStamp(parsed) : String(value);
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

/** UTC server timestamp -> value for an <input type="datetime-local"> (local wall clock). */
export function toLocalInputValue(value) {
  const date = parseServerTime(value);
  if (!date) {
    return "";
  }
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** <input type="datetime-local"> value (local wall clock) -> UTC "YYYY-MM-DD HH:MM:SS". */
export function fromLocalInputValue(value) {
  const text = String(value || "").trim();
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!match) {
    return text;
  }
  const [, year, month, day, hour, minute, second = "0"] = match;
  const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second));
  return Number.isNaN(date.getTime()) ? text : utcStamp(date);
}
