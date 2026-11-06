import { localize } from "../i18n.js";
// Small per-row dialogs (reset password, move department, ...). They share one
// form-dialog flow: validate, submit once, close and confirm with a toast, or
// keep the dialog open with an inline error.
import { adminErrorMessage, api } from "../api.js";
import { copyButton, errorState, loadingState, toast } from "../components/feedback.js";
import { confirmDialog } from "../components/modal.js";
import { button, el, readFields, showModal, statusMessage } from "../dom.js";
import { resourceForm } from "./forms.js";
import { renderDataDetails, setErrorOutput } from "./output.js";
import { departmentSelectOptions } from "./references.js";
import { columnLabel, valueLabel } from "../resources.js";

/**
 * Opens a dialog with a form; submit(values) performs the request.
 * Returns the dialog handle.
 */
export function formDialog({ title, fields, values = {}, submitLabel, submit, successText, failText, variant = "", intro = null }) {
  const form = resourceForm(fields, values, "", { mode: "create" });
  const output = el("p", { className: "notice", hidden: true });
  let modal = null;
  form.append(el("div", { className: "form-actions" }, [
    button(submitLabel, async () => {
      let payload;
      try {
        payload = readFields(form, fields, { validate: true });
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, error.message));
        return;
      }
      try {
        // submit() may return false to keep the dialog open (e.g. a cancelled confirmation).
        if (await submit(payload) === false) {
          return;
        }
        toast(successText, { tone: "success" });
        modal?.markClean();
        await modal?.close({ force: true });
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, failText));
      }
    }, variant, { type: "submit" })
  ]), output);
  if (intro) {
    form.prepend(intro);
  }
  modal = showModal(title, form);
  return modal;
}

export async function showUsageContent(row) {
  const body = el("div", {}, [loadingState(localize("正在加载内容…"))]);
  showModal(localize`内容留存 · 用量 #${row.id}`, body, { size: "lg" });
  try {
    const result = await api("/admin-api/usage-contents", { query: { limit: 1, usage_record_id: row.id }, silent: true });
    const rows = Array.isArray(result.data) ? result.data : [];
    body.replaceChildren(rows.length ? renderDataDetails(rows) : statusMessage(localize("该请求没有留存内容（可能未开启留存或已被清理）。")));
  } catch (error) {
    body.replaceChildren(errorState(error, { fallback: localize("内容加载失败"), onRetry: () => showUsageContent(row) }));
  }
}

export function showShareLinkPasswordResetResult(data) {
  const password = data.password || "";
  showModal(localize("重置密码完成"), el("div", { className: "stack" }, [
    statusMessage(password ? localize("密码已重置。新密码只显示这一次，请立即复制并告知使用人。") : localize("接口未返回新密码，请重新重置。"), password ? "" : "error"),
    password ? el("code", { className: "secret-value", text: password }) : null,
    password ? copyButton(password, localize("复制密码")) : null
  ]), { size: "sm" });
}

export function showAllowClientIp(row, onSaved) {
  formDialog({
    title: localize`放行大屏访问 IP · #${row.id}`,
    fields: [{ name: "client_ip", label: localize("允许访问的 IP"), required: true, placeholder: localize("例如 10.0.0.12") }],
    values: { client_ip: row.client_ip || "" },
    submitLabel: localize("放行 IP"),
    successText: localize("已放行该 IP"),
    failText: localize("添加允许 IP 失败"),
    submit: async (values) => {
      await api(`/admin-api/dashboard-share-links/${row.id}/allow-client-ip`, { method: "POST", body: JSON.stringify(values) });
      await onSaved();
    }
  });
}

function userApiBase(resourceKey = "users") {
  return resourceKey === "admin-users" ? "/admin-api/admin-users" : "/admin-api/users";
}

export function showUserPasswordReset(row, onSaved, resourceKey = "users") {
  formDialog({
    title: localize`重置密码 · ${row.username || `#${row.id}`}`,
    fields: [{ name: "password", label: localize("新密码"), type: "password", required: true, hint: localize("保存后原密码立即失效。") }],
    submitLabel: localize("重置密码"),
    successText: localize("密码已重置"),
    failText: localize("重置密码失败"),
    submit: async (values) => {
      await api(`${userApiBase(resourceKey)}/${row.id}/reset-password`, { method: "POST", body: JSON.stringify(values) });
      await onSaved();
    }
  });
}

export async function showAdminUserDepartmentGrant(row, onSaved) {
  formDialog({
    title: localize`授权管理部门 · ${row.username || `#${row.id}`}`,
    fields: [{
      name: "managed_department_ids",
      label: localize("授权管理部门"),
      type: "multiselect",
      coerce: "number",
      options: await departmentSelectOptions(),
      optional: true,
      size: 8
    }],
    values: row,
    submitLabel: localize("保存授权"),
    successText: localize("管理员授权已更新"),
    failText: localize("保存授权失败"),
    submit: async (values) => {
      await api(`/admin-api/admin-users/${row.id}`, { method: "PUT", body: JSON.stringify(values) });
      await onSaved();
    }
  });
}

export async function showAdminUserSessionRevoke(row, onSaved) {
  const ok = await confirmDialog({
    title: localize("撤销管理员会话"),
    message: localize("该管理员所有设备上的登录将立即失效，需要重新登录。"),
    details: [[localize("管理员"), `${row.username || ""}（#${row.id}）`]],
    confirmLabel: localize("撤销会话")
  });
  if (!ok) {
    return;
  }
  await api(`/admin-api/admin-users/${row.id}/revoke-sessions`, { method: "POST" });
  toast(localize("管理员会话已撤销"), { tone: "success" });
  await onSaved();
}

export async function showUserDepartmentMove(row, onSaved) {
  formDialog({
    title: localize`调整部门 · ${row.username || `#${row.id}`}`,
    fields: [{ name: "department_id", label: columnLabel("department_id"), type: "select", coerce: "number", options: await departmentSelectOptions() }],
    values: row,
    submitLabel: localize("移动部门"),
    successText: localize("用户部门已更新"),
    failText: localize("调整部门失败"),
    submit: async (values) => {
      await api(`/admin-api/users/${row.id}/department`, { method: "POST", body: JSON.stringify({ ...values, department_id: Number(values.department_id) }) });
      await onSaved();
    }
  });
}

export function showUserStatusUpdate(row, onSaved, resourceKey = "users") {
  formDialog({
    title: localize`更新状态 · ${row.username || `#${row.id}`}`,
    fields: [{ name: "status", label: localize("状态"), type: "select", options: ["active", "disabled", "departed"].map((value) => [value, valueLabel("status", value)]) }],
    values: row,
    submitLabel: localize("更新状态"),
    successText: localize("用户状态已更新"),
    failText: localize("更新状态失败"),
    submit: async (values) => {
      await api(resourceKey === "admin-users" ? `/admin-api/admin-users/${row.id}` : `/admin-api/users/${row.id}/status`, {
        method: resourceKey === "admin-users" ? "PUT" : "PATCH",
        body: JSON.stringify(values)
      });
      await onSaved();
    }
  });
}
