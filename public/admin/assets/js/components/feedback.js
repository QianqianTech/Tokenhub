// Shared feedback UI: toasts and the loading / empty / error states that every
// page uses, so users always see what is happening and errors carry a
// copyable request id for troubleshooting.
import { adminErrorMessage, isAbortError } from "../api.js";
import { el } from "../dom.js";
import { t } from "../i18n.js";

let toastHost = null;

function ensureToastHost() {
  if (toastHost?.isConnected) {
    return toastHost;
  }
  toastHost = el("div", { className: "toast-host", role: "region", "aria-label": t("feedback.notifications") });
  (document.getElementById("app") || document.body).append(toastHost);
  return toastHost;
}

export function toast(message, { tone = "info", timeout = tone === "error" ? 8000 : 4000, requestId = "" } = {}) {
  const host = ensureToastHost();
  const item = el("div", {
    className: `toast toast--${tone}`,
    role: tone === "error" ? "alert" : "status"
  }, [
    el("span", { className: "toast-message", text: message }),
    requestId ? copyButton(requestId, t("feedback.copyRequestId")) : null,
    el("button", {
      type: "button",
      className: "toast-close",
      "aria-label": t("common.close"),
      text: "×",
      onClick: () => item.remove()
    })
  ]);
  host.append(item);
  while (host.children.length > 4) {
    host.firstElementChild?.remove();
  }
  if (timeout > 0) {
    window.setTimeout(() => item.remove(), timeout);
  }
  return item;
}

export function toastError(error, fallback = t("feedback.requestFailed")) {
  if (isAbortError(error)) {
    return null;
  }
  return toast(adminErrorMessage({ ...error, requestId: "" }, fallback), { tone: "error", requestId: error?.requestId || "" });
}

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(String(text));
    return true;
  } catch {
    const area = el("textarea", { value: String(text), readOnly: true, className: "visually-hidden" });
    document.body.append(area);
    area.select();
    let copied = false;
    try {
      copied = document.execCommand("copy");
    } catch {
      copied = false;
    }
    area.remove();
    return copied;
  }
}

export function copyButton(text, label = t("common.copy")) {
  return el("button", {
    type: "button",
    className: "copy-button",
    title: `${label}：${text}`,
    "aria-label": `${label}：${text}`,
    text: label,
    onClick: async (event) => {
      event.stopPropagation();
      const ok = await copyText(text);
      toast(ok ? t("feedback.copied") : t("feedback.copyFailed"), { tone: ok ? "success" : "error", timeout: 2000 });
    }
  });
}

export function loadingState(text = t("feedback.loading"), { rows = 3 } = {}) {
  return el("div", { className: "state state--loading", role: "status", "aria-live": "polite", dataset: { loading: "true" } }, [
    el("div", { className: "skeleton-stack", "aria-hidden": "true" }, Array.from({ length: rows }, () => el("span", { className: "skeleton-line" }))),
    el("span", { className: "state-text", text })
  ]);
}

export function emptyState({ title = t("feedback.empty"), description = "", action = null } = {}) {
  return el("div", { className: "state state--empty", role: "status" }, [
    el("strong", { className: "state-title", text: title }),
    description ? el("p", { className: "state-text", text: description }) : null,
    action
  ]);
}

export function errorState(error, { fallback = t("feedback.loadFailed"), onRetry = null } = {}) {
  const requestId = error?.requestId || "";
  return el("div", { className: "state state--error", role: "alert" }, [
    el("strong", { className: "state-title", text: adminErrorMessage({ ...error, requestId: "" }, fallback) }),
    requestId ? el("p", { className: "state-text" }, [
      el("span", { text: `${t("feedback.requestId")}：${requestId}` }),
      copyButton(requestId)
    ]) : null,
    onRetry ? el("button", { type: "button", className: "secondary", text: t("common.retry"), onClick: onRetry }) : null
  ]);
}

// Shows a loading state in `container` while `task` runs; on failure replaces
// it with an error state that can retry the whole task.
export async function withLoading(container, task, { loadingText, fallback } = {}) {
  container.replaceChildren(loadingState(loadingText));
  try {
    return await task();
  } catch (error) {
    if (isAbortError(error)) {
      return undefined;
    }
    container.replaceChildren(errorState(error, { fallback, onRetry: () => withLoading(container, task, { loadingText, fallback }) }));
    return undefined;
  }
}
