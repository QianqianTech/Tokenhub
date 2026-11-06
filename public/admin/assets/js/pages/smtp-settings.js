import { localize } from "../i18n.js";
import { adminErrorMessage, api } from "../api.js";
import { button, el, field } from "../dom.js";
import { getCurrentUser } from "../core/session.js";
import { guardForm, markClean } from "../lib/dirty-guard.js";

export function smtpSettingsPanel(fields, values) {
  const editable = getCurrentUser()?.role === "super_admin";
  const form = guardForm(el("form", { className: "edit-form settings-form smtp-settings-form", onSubmit: (event) => event.preventDefault() }));
  const secretStatus = el("p", { className: "notice", text: values.password_set === true || values.password_set === "true" ? localize("已保存 SMTP 密码；留空会保留原密码。") : localize("尚未保存 SMTP 密码或授权码。") });
  const output = el("p", { className: "notice", role: "status", "aria-live": "polite" });
  for (const config of fields) {
    form.append(field(config, config.name === "password" ? "" : values[config.name]));
  }
  const recipient = field({ name: "test_recipient", label: localize("测试收件邮箱"), type: "email", optional: true }, getCurrentUser()?.email || "");
  const save = button(localize("保存 SMTP 配置"), async () => {
    const payload = {};
    for (const config of fields) {
      const input = form.elements.namedItem(config.name);
      payload[config.name] = config.type === "checkbox" ? input.checked : config.type === "number" ? Number(input.value) : input.value;
    }
    payload.enabled = payload.enabled === "true";
    payload.auth_enabled = payload.auth_enabled === "true";
    try {
      const result = await api("/admin-api/system/settings-center/smtp", { method: "PUT", body: JSON.stringify(payload), silent: true });
      secretStatus.textContent = result.data.password_set ? localize("已保存 SMTP 密码；留空会保留原密码。") : localize("尚未保存 SMTP 密码或授权码。");
      form.elements.namedItem("password").value = "";
      form.elements.namedItem("clear_password").checked = false;
      markClean(form);
      output.textContent = localize("SMTP 配置已保存，可发送测试邮件。");
    } catch (error) {
      output.textContent = adminErrorMessage(error, localize("保存 SMTP 配置失败，请检查输入内容"));
    } finally {
      payload.password = "";
    }
  });
  const send = button(localize("发送测试邮件"), async () => {
    const input = recipient.querySelector("input");
    if (!input.value.trim() || !input.checkValidity()) {
      output.textContent = localize("请输入有效的测试收件邮箱");
      input.focus();
      return;
    }
    try {
      await api("/admin-api/system/settings-center/smtp/test", { method: "POST", body: JSON.stringify({ recipient: input.value.trim() }), silent: true });
      output.textContent = localize("测试邮件已提交给 SMTP 服务器，请检查收件箱和垃圾邮件。");
    } catch (error) {
      output.textContent = adminErrorMessage(error, localize("测试邮件发送失败"));
    }
  }, "secondary");
  form.append(secretStatus, el("div", { className: "toolbar-actions" }, [save]));
  const testArea = el("section", { className: "smtp-test-area" }, [
    el("h3", { text: localize("测试邮件") }),
    el("p", { className: "notice", text: localize("使用已保存的 SMTP 配置发送。修改配置后请先保存。") }), recipient, send, output
  ]);
  const panel = el("section", { className: "panel settings-form-panel" }, [
    el("p", { className: "notice", text: localize("管理员在“管理员管理”中绑定邮箱后，即可通过登录页的“忘记密码”找回密码。") }), form, testArea
  ]);
  if (!editable) {
    panel.querySelectorAll("input, select, button").forEach((control) => { control.disabled = true; });
  }
  return panel;
}
