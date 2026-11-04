// PIN prompt for sensitive operations (server-enforced step-up) and the
// optional APP lock. Resolves true when the server accepted the PIN.
import { api } from "/admin/assets/js/api.js";
import { openModal } from "/admin/assets/js/components/modal.js";
import { el } from "/admin/assets/js/dom.js";
import { verifyWithPasskey } from "./passkey.js";

function pinInput(label, autocomplete = "off") {
  return el("input", {
    type: "password",
    inputMode: "numeric",
    pattern: "[0-9]*",
    maxLength: 12,
    autocomplete,
    className: "tha-pin-input",
    "aria-label": label,
    placeholder: label
  });
}

function errorText(error) {
  const details = error?.details || {};
  if (error?.code === "APP_PIN_INVALID") {
    return details.remaining ? `PIN 不正确，还可以再试 ${details.remaining} 次。` : "PIN 不正确。";
  }
  if (error?.code === "APP_PIN_LOCKED") {
    return "PIN 输错次数过多，这台设备已退出登录。";
  }
  if (error?.code === "APP_PIN_FORMAT") {
    return "PIN 需要是 6～12 位数字。";
  }
  return error?.message || "验证失败，请重试。";
}

/**
 * @param {{ operation?: string, pinSet?: boolean, title?: string, dismissible?: boolean }} options
 * @returns {Promise<boolean>}
 */
export function requestPin({ operation = "", pinSet = true, title = "", dismissible = true, passkey = false } = {}) {
  return new Promise((resolve) => {
    let settled = false;
    const finish = (value) => {
      if (!settled) {
        settled = true;
        resolve(value);
      }
    };
    const message = el("p", { className: "tha-pin-message", role: "alert" });
    const first = pinInput(pinSet ? "输入 PIN" : "设置 6～12 位数字 PIN", pinSet ? "current-password" : "new-password");
    const second = pinSet ? null : pinInput("再次输入 PIN", "new-password");
    const submit = el("button", { type: "submit", text: pinSet ? "验证" : "设置并继续" });
    const passkeyButton = pinSet && passkey ? el("button", { type: "button", className: "secondary tha-passkey-button", text: "用 Passkey 验证（面容 / 指纹）" }) : null;
    const form = el("form", { className: "tha-pin-form" }, [
      passkeyButton,
      el("p", { className: "tha-pin-intro", text: pinSet
        ? `${operation ? `「${operation}」` : "该操作"}属于敏感操作，请输入本设备的 PIN。`
        : `${operation ? `「${operation}」` : "敏感操作"}需要先为本设备设置 PIN。PIN 只保存在服务器上，用于确认是你本人在操作。` }),
      first,
      second,
      message,
      submit
    ]);
    const modal = openModal({
      title: title || (pinSet ? "验证 PIN" : "设置 PIN"),
      body: form,
      size: "sm",
      guardDirty: false,
      stack: true,
      dismissible,
      onClose: () => finish(false)
    });
    passkeyButton?.addEventListener("click", async () => {
      message.textContent = "";
      passkeyButton.disabled = true;
      try {
        await verifyWithPasskey();
        finish(true);
        await modal.close({ force: true });
      } catch (error) {
        message.textContent = error?.name === "NotAllowedError" ? "已取消 Passkey 验证，可以改用 PIN。" : (error?.message || "Passkey 验证失败，请改用 PIN。");
      } finally {
        passkeyButton.disabled = false;
      }
    });
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      message.textContent = "";
      const pin = first.value.trim();
      if (!/^\d{6,12}$/.test(pin)) {
        message.textContent = "PIN 需要是 6～12 位数字。";
        first.focus();
        return;
      }
      if (second && second.value.trim() !== pin) {
        message.textContent = "两次输入的 PIN 不一致。";
        second.focus();
        return;
      }
      submit.disabled = true;
      try {
        await api(pinSet ? "/admin-api/app-auth/step-up" : "/admin-api/app-auth/pin", {
          method: "POST",
          body: JSON.stringify({ pin }),
          silent: true
        });
        finish(true);
        await modal.close({ force: true });
      } catch (error) {
        message.textContent = errorText(error);
        first.value = "";
        if (second) {
          second.value = "";
        }
        first.focus();
        if (error?.code === "APP_PIN_LOCKED") {
          finish(false);
          window.setTimeout(() => window.location.replace("/app/#/welcome"), 1500);
        }
      } finally {
        submit.disabled = false;
      }
    });
    window.setTimeout(() => first.focus(), 50);
  });
}

/** Change the PIN: current + new + confirmation. */
export function changePin() {
  return new Promise((resolve) => {
    const message = el("p", { className: "tha-pin-message", role: "alert" });
    const current = pinInput("当前 PIN", "current-password");
    const next = pinInput("新 PIN（6～12 位数字）", "new-password");
    const again = pinInput("再次输入新 PIN", "new-password");
    const submit = el("button", { type: "submit", text: "修改 PIN" });
    const form = el("form", { className: "tha-pin-form" }, [current, next, again, message, submit]);
    const modal = openModal({ title: "修改 PIN", body: form, size: "sm", guardDirty: false, stack: true, onClose: () => resolve(false) });
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      message.textContent = "";
      if (!/^\d{6,12}$/.test(next.value.trim()) || next.value.trim() !== again.value.trim()) {
        message.textContent = "新 PIN 需要是 6～12 位数字，且两次输入一致。";
        return;
      }
      submit.disabled = true;
      try {
        await api("/admin-api/app-auth/pin", { method: "POST", body: JSON.stringify({ pin: next.value.trim(), current_pin: current.value.trim() }), silent: true });
        resolve(true);
        await modal.close({ force: true });
      } catch (error) {
        message.textContent = errorText(error);
      } finally {
        submit.disabled = false;
      }
    });
  });
}
