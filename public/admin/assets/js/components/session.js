// Session lifetime UX: warn before the admin session expires (with a one-click
// renewal) and, when it has expired or the server rejects it, ask for the
// password in a dialog on top of the current page so unsaved work survives.
import { adminErrorMessage, api, login } from "../api.js";
import { el } from "../dom.js";
import { t } from "../i18n.js";
import { toast } from "./feedback.js";
import { confirmDialog, openModal } from "./modal.js";

const WARN_BEFORE_MS = 5 * 60 * 1000;

let warnTimer = 0;
let expireTimer = 0;
let expiresAtMs = 0;
let reloginPromise = null;
let options = { username: "", onRelogin: () => {} };

// Server timestamps are "YYYY-MM-DD HH:MM:SS" in the server's zone. Parsing
// both expiry and server time the same way and applying the difference to the
// local clock makes the countdown independent of zones and clock skew.
function parseServerTime(value) {
  const match = String(value || "").match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
  if (!match) {
    return NaN;
  }
  const [, y, m, d, h, i, s] = match.map(Number);
  return Date.UTC(y, m - 1, d, h, i, s);
}

export function stopSessionMonitor() {
  window.clearTimeout(warnTimer);
  window.clearTimeout(expireTimer);
  warnTimer = 0;
  expireTimer = 0;
}

export function startSessionMonitor({ expiresAt, serverTime, username = "", onRelogin = () => {} }) {
  options = { username, onRelogin };
  stopSessionMonitor();
  const expires = parseServerTime(expiresAt);
  const now = parseServerTime(serverTime);
  if (!Number.isFinite(expires) || !Number.isFinite(now)) {
    return;
  }
  expiresAtMs = Date.now() + (expires - now);
  schedule();
}

function schedule() {
  const remaining = expiresAtMs - Date.now();
  if (remaining <= 0) {
    void requestRelogin();
    return;
  }
  warnTimer = window.setTimeout(warn, Math.max(0, remaining - WARN_BEFORE_MS));
  expireTimer = window.setTimeout(() => void requestRelogin(), remaining);
}

async function warn() {
  const minutes = Math.max(1, Math.round((expiresAtMs - Date.now()) / 60000));
  const renew = await confirmDialog({
    title: t("session.expiringTitle"),
    message: t("session.expiringMessage", { minutes }),
    confirmLabel: t("session.renew"),
    cancelLabel: t("common.close"),
    tone: "primary"
  });
  if (!renew) {
    return;
  }
  try {
    const result = await api("/admin-api/session/renew", { method: "POST", silent: true });
    startSessionMonitor({ expiresAt: result.data.session_expires_at, serverTime: result.data.server_time, ...options });
    toast(t("session.renewed"), { tone: "success" });
  } catch (error) {
    if (error.status !== 401) {
      toast(adminErrorMessage(error), { tone: "error" });
    }
  }
}

/** Shows the re-login dialog once; resolves when the user has signed in again. */
export function requestRelogin() {
  if (reloginPromise) {
    return reloginPromise;
  }
  stopSessionMonitor();
  reloginPromise = new Promise((resolve) => {
    const username = el("input", { name: "username", autocomplete: "username", value: options.username, required: true });
    const password = el("input", { name: "password", type: "password", autocomplete: "current-password", required: true });
    const error = el("p", { className: "field-error", role: "alert", hidden: true });
    const submit = el("button", { type: "submit", text: t("session.login") });
    const form = el("form", { className: "edit-form relogin-form", noValidate: true }, [
      el("p", { className: "notice", text: t("session.expiredMessage") }),
      el("label", { className: "field" }, [el("span", { text: "用户名" }), username]),
      el("label", { className: "field" }, [el("span", { text: "密码" }), password]),
      error,
      el("div", { className: "form-actions" }, [submit])
    ]);
    // The dialog cannot be dismissed without signing in again.
    const handle = openModal({ title: t("session.expiredTitle"), body: form, size: "sm", guardDirty: false, stack: true, dismissible: false, className: "modal-dialog--relogin" });
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!username.value.trim() || !password.value) {
        error.hidden = false;
        error.textContent = "请输入用户名和密码";
        return;
      }
      submit.disabled = true;
      submit.setAttribute("aria-busy", "true");
      try {
        const result = await login(username.value.trim(), password.value);
        error.hidden = true;
        await handle.close({ force: true });
        reloginPromise = null;
        options.onRelogin(result.data);
        toast("已重新登录，可以继续之前的操作", { tone: "success" });
        resolve(result.data);
      } catch (cause) {
        error.hidden = false;
        error.textContent = adminErrorMessage(cause, "登录失败，请检查账号或密码");
        password.value = "";
        password.focus();
      } finally {
        submit.disabled = false;
        submit.removeAttribute("aria-busy");
      }
    });
    (options.username ? password : username).focus();
  });
  return reloginPromise;
}
