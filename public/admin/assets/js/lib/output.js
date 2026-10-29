import { api } from "../api.js";
import { toast } from "../components/feedback.js";
import { el, setText, statusMessage } from "../dom.js";
import { columnLabel, valueLabel } from "../resources.js";

export async function openAdminDownload(path, method = "GET") {
  const result = await api(path, { method });
  const url = result.data?.download_url;
  if (!url) throw new Error("下载链接生成失败");
  window.open(url, "_blank", "noopener");
}

export function operationSuccessText(data = {}, fallback = "操作已完成") {
  const parts = [];
  for (const [key, label] of [
    ["saved", "保存"],
    ["created", "新增"],
    ["updated", "更新"],
    ["deleted", "删除"],
    ["revoked", "撤销"],
    ["queued", "已入队"]
  ]) {
    if (Number.isFinite(Number(data?.[key]))) {
      parts.push(`${label} ${Number(data[key])} 项`);
    }
  }
  if (parts.length > 0) {
    return parts.join("，");
  }
  if (data?.export_id) {
    return `导出任务已创建：${data.export_id}`;
  }
  if (data?.status && typeof data.status === "string") {
    return `操作已完成，状态：${valueLabel("status", data.status) || data.status}`;
  }
  return fallback;
}

export function showOperationResult(title, data, fallback) {
  toast(`${title}：${operationSuccessText(data, fallback)}`, { tone: "success" });
}

export function setOperationOutput(output, data, fallback) {
  setText(output, operationSuccessText(data, fallback));
  output.classList.remove("error");
  output.classList.add("success");
  output.setAttribute("role", "status");
  output.hidden = false;
}

export function setErrorOutput(output, text) {
  setText(output, text);
  output.classList.remove("success");
  output.classList.add("error");
  output.setAttribute("role", "alert");
  output.hidden = false;
}

export function oneTimeSecretText(data = {}) {
  return data.key || data.api_key || data.secret || data.token || data.password || operationSuccessText(data);
}

function detailValue(value) {
  if (value === null || value === undefined || value === "") {
    return "-";
  }
  if (Array.isArray(value)) {
    return value.map((item) => detailValue(item)).join(", ");
  }
  if (typeof value === "object") {
    return Object.entries(value)
      .map(([key, nestedValue]) => `${columnLabel(key)}: ${detailValue(nestedValue)}`)
      .join("; ");
  }
  return String(value);
}

export function renderDataDetails(data) {
  const rows = Array.isArray(data) ? data : [data];
  if (rows.length === 0) {
    return statusMessage("暂无详情");
  }
  if (rows.every((row) => row === null || typeof row !== "object")) {
    return el("div", { className: "data-detail-list" }, rows.map((row) => statusMessage(detailValue(row))));
  }
  const columns = [...new Set(rows.flatMap((row) => Object.keys(row || {})))];
  const table = el("table", { className: "resource-table data-detail-table" });
  table.append(el("thead", {}, [
    el("tr", {}, columns.map((column) => el("th", { text: columnLabel(column) })))
  ]));
  table.append(el("tbody", {}, rows.map((row) => el("tr", {}, columns.map((column) => el("td", {
    text: detailValue(row?.[column])
  }))))));
  return table;
}

export function metric(name, value) {
  return el("article", { className: "metric" }, [
    el("span", { text: name }),
    el("strong", { text: value })
  ]);
}
