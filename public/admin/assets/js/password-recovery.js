import { adminErrorMessage, api } from "./api.js";
import { $ } from "./dom.js";

let resetToken = "";

export function isPasswordRecoveryVisible() {
  return !$("forgot-password").hidden || !$("reset-password").hidden;
}

export function hidePasswordRecovery() {
  $("forgot-password").hidden = true;
  $("reset-password").hidden = true;
  $("reset-new-password").value = "";
  $("reset-confirm-password").value = "";
  resetToken = "";
}

function showRecovery(mode) {
  $("login").hidden = true;
  $("forgot-password").hidden = mode !== "forgot";
  $("reset-password").hidden = mode !== "reset";
  $("login-status").hidden = true;
  (mode === "forgot" ? $("recovery-email") : $("reset-new-password")).focus();
}

function setBusy(button, busy) {
  button.disabled = busy;
  button.setAttribute("aria-busy", String(busy));
}

// "忘记密码" only makes sense when SMTP can deliver the reset mail; otherwise
// the login page says to ask a super administrator. If the options cannot be
// read (older server, network), the link is shown as before.
async function applyRecoveryOptions() {
  const link = $("forgot-password-link");
  const contact = $("forgot-password-contact");
  let enabled = true;
  try {
    const result = await api("/admin-api/auth/options", { silent: true });
    enabled = result.data?.recovery_enabled !== false;
  } catch {
    enabled = true;
  }
  link.hidden = !enabled;
  if (contact) contact.hidden = enabled;
}

export function initPasswordRecovery(returnToLogin) {
  void applyRecoveryOptions();
  $("forgot-password-link").addEventListener("click", () => showRecovery("forgot"));
  document.querySelectorAll("[data-return-login]").forEach((button) => {
    button.addEventListener("click", () => {
      $("login-status").hidden = true;
      returnToLogin();
    });
  });
  $("recovery-request-again").addEventListener("click", () => {
    resetToken = "";
    $("reset-new-password").value = "";
    $("reset-confirm-password").value = "";
    showRecovery("forgot");
  });
  $("forgot-password").addEventListener("submit", async (event) => {
    event.preventDefault();
    const submit = $("recovery-send");
    if (submit.disabled) return;
    const email = $("recovery-email");
    email.value = email.value.trim();
    $("recovery-error").textContent = "";
    $("recovery-status").hidden = true;
    if (!email.value || !email.checkValidity()) {
      $("recovery-error").textContent = "请输入有效的邮箱地址";
      email.focus();
      return;
    }
    setBusy(submit, true);
    try {
      const result = await api("/admin-api/forgot-password", { method: "POST", body: JSON.stringify({ email: email.value }), silent: true });
      $("recovery-status").textContent = result.data.message;
      $("recovery-status").hidden = false;
    } catch (error) {
      $("recovery-error").textContent = adminErrorMessage(error, "发送重置邮件失败，请稍后重试");
    } finally {
      setBusy(submit, false);
    }
  });
  $("reset-password").addEventListener("submit", async (event) => {
    event.preventDefault();
    const submit = $("recovery-reset");
    if (submit.disabled) return;
    const password = $("reset-new-password").value;
    const confirmation = $("reset-confirm-password").value;
    const output = $("reset-error");
    if (Array.from(password).length < 12 || new TextEncoder().encode(password).length > 72) {
      output.textContent = "新密码至少 12 个字符，最多 72 字节";
      return;
    }
    if (password !== confirmation) {
      output.textContent = "两次输入的新密码不一致";
      return;
    }
    output.textContent = "";
    setBusy(submit, true);
    try {
      await api("/admin-api/reset-password", { method: "POST", body: JSON.stringify({ token: resetToken, password, password_confirmation: confirmation }), silent: true });
      returnToLogin();
      $("login-status").textContent = "密码已重置，请使用新密码登录。";
      $("login-status").hidden = false;
    } catch (error) {
      output.textContent = adminErrorMessage(error, "密码重置失败，请稍后重试");
      if (error.code === "ADMIN_RESET_LINK_INVALID") resetToken = "";
    } finally {
      setBusy(submit, false);
      submit.disabled = !resetToken && !$("reset-password").hidden;
    }
  });
  const hash = window.location.hash;
  if (!hash.startsWith("#reset-password=")) return false;
  resetToken = new URLSearchParams(hash.slice(1)).get("reset-password") || "";
  // Keep the credential only in memory, away from history, storage and navigation.
  window.history.replaceState(null, "", window.location.pathname + window.location.search);
  showRecovery("reset");
  if (!/^apr_[a-f0-9]{64}$/.test(resetToken)) {
    resetToken = "";
    $("reset-error").textContent = "重置链接无效或已过期，请重新申请";
    $("recovery-reset").disabled = true;
  }
  return true;
}
