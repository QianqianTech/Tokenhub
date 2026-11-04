// API key tools: quota status and adjustment, key rotation, agent management
// scopes and MCP OAuth sign-ins bound to a key.
import { adminErrorMessage, api } from "../api.js";
import { button, el, readFields, showModal, statusMessage } from "../dom.js";
import { confirmDialog } from "../components/modal.js";
import { valueLabel } from "../resources.js";
import { resourceForm } from "./forms.js";
import { oneTimeSecretText, setErrorOutput } from "./output.js";
import { adminViewerCan, copyTextToClipboard, opsDetailGrid, opsNumber, opsSection, opsText, opsUtcTime, parseJsonValue } from "./ops-common.js";

// disabled_by: "admin" (console) | "management" (agent management API) |
// null (not recorded; the management API treats it as admin).
export const API_KEY_DISABLED_BY_LABELS = { admin: "管理员", management: "智能体" };

// --- 2. Quota status & adjustment ------------------------------------------

const QUOTA_METRICS = [
  ["request_count", "requests", "请求次数"],
  ["input_tokens", "input_tokens", "输入 Token"],
  ["output_tokens", "output_tokens", "输出 Token"],
  ["total_tokens", "total_tokens", "总 Token"],
  ["audio_seconds", "audio_seconds", "音频秒数"],
  ["tts_chars", "tts_chars", "TTS 字符"],
  ["image_count", "image_count", "图片数"],
  ["video_seconds", "video_seconds", "视频秒数"],
  ["cost_amount", "cost_amount", "成本"]
];

const QUOTA_TARGET_LABELS = { api_key: "API 密钥", user: "用户", department: "部门" };

function quotaTargetText(targetType, targetId) {
  return `${QUOTA_TARGET_LABELS[targetType] || targetType || "-"} #${opsText(targetId)}`;
}

function quotaMetricTable(item) {
  const limits = item.limits || {};
  const usage = item.usage || {};
  const reserved = usage.reserved_in_flight && typeof usage.reserved_in_flight === "object" ? usage.reserved_in_flight : {};
  const remaining = item.remaining || {};
  const rows = QUOTA_METRICS.filter(([name]) => name in limits || name in usage)
    .filter(([name]) => limits[name] !== null && limits[name] !== undefined || Number(usage[name]) > 0 || Number(reserved[name]) > 0);
  if (rows.length === 0) {
    return el("p", { className: "muted", text: "未设置限额，当前窗口暂无用量" });
  }
  const table = el("table", { className: "resource-table quota-status-table" });
  table.append(el("thead", {}, [el("tr", {}, ["指标", "限额", "已用（含预占）", "进行中预占", "剩余", "使用率"].map((label) => el("th", { text: label })))]));
  table.append(el("tbody", {}, rows.map(([name, , label]) => {
    const limit = limits[name];
    const used = Number(usage[name]) || 0;
    const hasLimit = limit !== null && limit !== undefined;
    const percent = hasLimit && Number(limit) > 0 ? Math.min(100, Math.round(used / Number(limit) * 100)) : null;
    const meter = el("div", { className: `quota-meter ${percent !== null && percent >= 90 ? "quota-meter--high" : ""}`.trim(), title: percent === null ? "不限" : `${percent}%` }, [
      el("span", { style: `width:${percent ?? 0}%` })
    ]);
    return el("tr", {}, [
      el("td", { text: label }),
      el("td", { text: hasLimit ? opsNumber(limit) : "不限" }),
      el("td", { text: opsNumber(used) }),
      el("td", { text: reserved[name] ? opsNumber(reserved[name]) : "0" }),
      el("td", { text: hasLimit ? opsNumber(remaining[name]) : "不限" }),
      el("td", {}, [meter, el("small", { text: percent === null ? "不限" : `${percent}%` })])
    ]);
  })));
  return el("div", { className: "quota-status-table-wrap" }, [table]);
}

function quotaPolicyCard(policy) {
  return el("article", { className: "quota-status-card" }, [
    el("header", {}, [
      el("strong", { text: policy.name || `策略 #${policy.policy_id}` }),
      el("span", { text: `${quotaTargetText(policy.target_type, policy.target_id)} · 周期 ${valueLabel("period", policy.period || "")} · 重置于 ${opsUtcTime(policy.reset_at)}` })
    ]),
    quotaMetricTable(policy)
  ]);
}

function quotaModelCard(quota) {
  return el("article", { className: "quota-status-card" }, [
    el("header", {}, [
      el("strong", { text: quota.model_name || `模型 #${quota.model_id}` }),
      el("span", { text: `模型限额 #${opsText(quota.quota_id)} · 周期 ${valueLabel("period", quota.period || "")} · 重置于 ${opsUtcTime(quota.reset_at)}` })
    ]),
    quotaMetricTable(quota)
  ]);
}

function quotaAdjustmentForm(policies, onAdjusted) {
  const fields = [
    {
      name: "policy_ref",
      label: "额度策略",
      type: "select",
      options: policies.map((policy) => [
        `${policy.policy_id}:${policy.target_type}:${policy.target_id}`,
        `${policy.name || `策略 #${policy.policy_id}`} / ${quotaTargetText(policy.target_type, policy.target_id)}`
      ])
    },
    { name: "metric", label: "指标", type: "select", options: QUOTA_METRICS.map(([, metric, label]) => [metric, label]) },
    { name: "amount", label: "调整量（正数增加已用量，负数返还额度）", type: "number" },
    { name: "reason", label: "原因（必填，写入审计日志）", type: "textarea", rows: 2 }
  ];
  const form = resourceForm(fields, { metric: "total_tokens", policy_ref: fields[0].options[0]?.[0] || "" });
  form.classList.add("quota-adjust-form");
  const amountInput = form.querySelector('[name="amount"]');
  amountInput.step = "any";
  const output = el("p", { className: "notice", hidden: true });
  const submit = button("提交调整", async () => {
    const values = readFields(form, fields);
    const [policyId, targetType, targetId] = String(values.policy_ref || "").split(":");
    const amount = Number(values.amount);
    const reason = String(values.reason || "").trim();
    if (!policyId || !targetType || !targetId) {
      setErrorOutput(output, "请选择额度策略");
      return;
    }
    if (!Number.isFinite(amount) || amount === 0) {
      setErrorOutput(output, "调整量需为非零数字");
      return;
    }
    if (reason === "") {
      setErrorOutput(output, "请填写调整原因");
      return;
    }
    submit.disabled = true;
    try {
      const result = await api("/admin-api/quota-adjustments", {
        method: "POST",
        body: JSON.stringify({
          policy_id: Number(policyId),
          target_type: targetType,
          target_id: Number(targetId),
          metric: values.metric,
          amount,
          reason
        })
      });
      const data = result.data || {};
      const metricLabel = QUOTA_METRICS.find(([, metric]) => metric === data.metric)?.[2] || data.metric;
      await onAdjusted(`已调整 ${metricLabel}：${opsNumber(data.before)} → ${opsNumber(data.after)}（窗口 ${opsText(data.window)}）`);
    } catch (error) {
      submit.disabled = false;
      setErrorOutput(output, adminErrorMessage(error, "额度调整失败"));
    }
  });
  form.append(submit, output);
  return opsSection("额度调整", [
    el("p", { className: "muted", text: "仅修正当前窗口计数（例如上游故障后返还额度），不会修改历史用量记录。" }),
    form
  ], "quota-adjust");
}

function renderQuotaStatus(body, data, reload, flash = "") {
  const policies = Array.isArray(data.policies) ? data.policies : [];
  const perModel = Array.isArray(data.per_model) ? data.per_model : [];
  const children = [];
  if (flash) {
    children.push(statusMessage(flash));
  }
  children.push(el("p", { className: "muted", text: "已用量包含进行中请求的预占额度；请求结束后预占会按实际用量结算。" }));
  if (policies.length === 0 && perModel.length === 0) {
    children.push(statusMessage("该密钥未绑定生效中的额度策略或模型限额"));
  }
  if (policies.length > 0) {
    children.push(opsSection("额度策略", policies.map(quotaPolicyCard)));
  }
  if (perModel.length > 0) {
    children.push(opsSection("模型限额", perModel.map(quotaModelCard)));
  }
  children.push(el("div", { className: "toolbar-actions" }, [button("刷新", () => reload(), "secondary")]));
  if (adminViewerCan("quotas.manage") && policies.length > 0) {
    children.push(quotaAdjustmentForm(policies, (message) => reload(message)));
  }
  body.replaceChildren(...children);
}

export async function showApiKeyQuota(row) {
  const body = el("div", { className: "quota-status-modal" }, [statusMessage("正在加载额度...")]);
  showModal(`额度 · ${row.name || row.id}`, body);
  const load = async (flash = "") => {
    try {
      const result = await api("/admin-api/quota-status", { query: { api_key_id: row.id } });
      renderQuotaStatus(body, result.data || {}, load, flash);
    } catch (error) {
      body.replaceChildren(statusMessage(adminErrorMessage(error, "额度加载失败"), "error"));
    }
  };
  await load();
}

// --- 4. API key rotation ----------------------------------------------------

export function showApiKeyRotate(row, onRefresh) {
  const fields = [{ name: "grace_minutes", label: "旧密钥宽限期（分钟，0–10080）", type: "number" }];
  const form = resourceForm(fields, { grace_minutes: 60 });
  form.classList.add("api-key-rotate-form");
  const graceInput = form.querySelector('[name="grace_minutes"]');
  graceInput.min = "0";
  graceInput.max = "10080";
  graceInput.step = "1";
  const output = el("p", { className: "notice", hidden: true });
  form.prepend(statusMessage(`将为 API 密钥 ${row.name || row.id} 生成新的明文密钥，并复制原密钥的模型授权、模型限额与知识库绑定。旧密钥在宽限期结束后失效，0 表示立即失效。`));
  const submit = button("确认轮换", async () => {
    const raw = graceInput.value.trim();
    const graceMinutes = raw === "" ? Number.NaN : Number(raw);
    if (!Number.isInteger(graceMinutes) || graceMinutes < 0 || graceMinutes > 10080) {
      setErrorOutput(output, "宽限期需为 0 到 10080 之间的整数分钟");
      return;
    }
    submit.disabled = true;
    try {
      const result = await api(`/admin-api/api-keys/${row.id}/rotate`, {
        method: "POST",
        body: JSON.stringify({ grace_minutes: graceMinutes })
      });
      showApiKeyRotationResult(row, result.data || {}, graceMinutes);
      await onRefresh();
    } catch (error) {
      submit.disabled = false;
      setErrorOutput(output, adminErrorMessage(error, "轮换失败，请确认密钥状态和权限"));
    }
  }, "danger");
  form.append(submit, output);
  showModal(`轮换 API 密钥 #${row.id}`, form);
}

function showApiKeyRotationResult(row, data, graceMinutes) {
  const secret = el("p", { className: "notice secret-output api-key-rotate-secret", text: oneTimeSecretText(data) });
  const feedback = el("p", { className: "notice", hidden: true });
  const copyButton = button("复制新密钥", () => copyTextToClipboard(data.key || "", feedback, secret), "secondary");
  copyButton.disabled = !data.key;
  const expiry = graceMinutes === 0
    ? `旧密钥 #${opsText(data.replaces_api_key_id, row.id)} 已立即失效。`
    : `旧密钥 #${opsText(data.replaces_api_key_id, row.id)} 将于 ${opsUtcTime(data.old_key_expires_at)}（UTC ${opsText(data.old_key_expires_at)}）失效，请在此之前把客户端切换到新密钥。`;
  showModal("API 密钥已轮换", el("div", { className: "api-key-rotate-result" }, [
    statusMessage("新密钥明文只显示这一次，关闭后无法再次查看，请立即复制保存。", "error"),
    secret,
    el("div", { className: "toolbar-actions" }, [copyButton]),
    feedback,
    opsDetailGrid([
      ["新密钥 ID", data.id],
      ["新密钥显示", data.display],
      ["替换的旧密钥", data.replaces_api_key_id],
      ["旧密钥失效时间", data.old_key_expires_at ? `${opsUtcTime(data.old_key_expires_at)}` : null]
    ]),
    statusMessage(expiry)
  ]));
}

// --- Agent management scopes on API keys -----------------------------------

const API_KEY_MANAGEMENT_SCOPES = [
  ["users:read", "用户（只读）"],
  ["users:write", "用户（读写）"],
  ["api_keys:read", "API Key（只读）"],
  ["api_keys:write", "API Key（读写）"],
  ["rag:read", "知识库（只读）"],
  ["rag:write", "知识库（读写）"],
  ["usage:read", "用量（只读）"]
];

function apiKeyManagementScopeList(value) {
  const parsed = parseJsonValue(value);
  const list = Array.isArray(parsed) ? parsed : (typeof value === "string" && value.trim() !== "" ? value.split(",") : []);
  const known = new Set(API_KEY_MANAGEMENT_SCOPES.map(([scope]) => scope));
  return [...new Set(list.map((scope) => String(scope).trim()).filter((scope) => known.has(scope)))];
}

// "*:write" implies "*:read" server-side; compare sets without the implied reads.
function normalizeManagementScopes(scopes) {
  const set = new Set(scopes);
  return API_KEY_MANAGEMENT_SCOPES.map(([scope]) => scope)
    .filter((scope) => set.has(scope))
    .filter((scope) => !(scope.endsWith(":read") && set.has(scope.replace(/:read$/, ":write"))));
}

function apiKeyManagementScopeLabels(scopes) {
  const labels = new Map(API_KEY_MANAGEMENT_SCOPES);
  return scopes.map((scope) => labels.get(scope) || scope);
}

export function apiKeyManagementBadge(row) {
  const scopes = apiKeyManagementScopeList(row.management_scopes);
  if (scopes.length === 0) {
    return null;
  }
  return el("span", {
    className: "api-key-management-badge",
    text: "管理",
    title: `智能体管理权限：${apiKeyManagementScopeLabels(scopes).join("、")}`
  });
}

export function createApiKeyManagementScopeEditor(initialScopes, options = {}) {
  let baseline = normalizeManagementScopes(apiKeyManagementScopeList(initialScopes));
  const initial = new Set(apiKeyManagementScopeList(initialScopes));
  const inputs = new Map();
  const list = el("div", { className: "api-key-management-scope-list" });
  for (const [scope, label] of API_KEY_MANAGEMENT_SCOPES) {
    const input = el("input", { type: "checkbox", name: "management_scopes", value: scope, checked: initial.has(scope) });
    inputs.set(scope, input);
    list.append(el("label", { className: "multiselect-item" }, [input, el("span", { text: label })]));
  }
  const syncImpliedReads = () => {
    for (const [scope, input] of inputs) {
      if (!scope.endsWith(":write")) {
        continue;
      }
      const read = inputs.get(scope.replace(/:write$/, ":read"));
      if (!read) {
        continue;
      }
      if (input.checked) {
        if (!read.disabled) {
          read.dataset.explicit = read.checked ? "1" : "0";
        }
        read.checked = true;
        read.disabled = true;
        read.title = "已由读写权限包含";
      } else if (read.disabled) {
        read.disabled = false;
        read.checked = read.dataset.explicit === "1";
        read.title = "";
      }
    }
  };
  list.addEventListener("change", syncImpliedReads);
  syncImpliedReads();
  const selected = () => [...inputs.entries()]
    .filter(([, input]) => input.checked && !input.disabled)
    .map(([scope]) => scope);
  const summary = el("summary", { text: initial.size > 0 ? `智能体管理权限（已授予 ${initial.size} 项）` : "智能体管理权限" });
  const node = el("details", { className: "api-key-management-scopes", open: baseline.length > 0 }, [
    summary,
    el("p", { className: "muted", text: "授予后该 Key 可调用 /v1/management/* 管理本部门的用户、Key、知识库和用量；不要放在浏览器或前端代码中。" }),
    options.editing ? el("p", { className: "muted", text: "收窄权限会同时收回该 Key 下发给其他 Key 的对应权限。读写权限已包含只读权限。" }) : el("p", { className: "muted", text: "读写权限已包含只读权限。" }),
    list
  ]);
  return {
    node,
    selected,
    changed() {
      return normalizeManagementScopes(selected()).join(",") !== baseline.join(",");
    },
    commit(scopes) {
      baseline = normalizeManagementScopes(apiKeyManagementScopeList(scopes));
    }
  };
}

// --- MCP OAuth sign-ins bound to an API key ---------------------------------

const MCP_GRANT_LOGIN_LABELS = { password: "账号密码", api_key: "粘贴 API Key" };
const MCP_GRANT_REVOKE_REASONS = {
  admin: "管理员撤销",
  password_changed: "用户改了密码",
  refresh_replay: "检测到令牌重放",
  code_replay: "检测到授权码重放",
  client_revoked: "客户端注销"
};

function mcpGrantClientLabel(grant) {
  if (grant.client_name) {
    return grant.client_name;
  }
  const clientId = String(grant.client_id || "");
  if (clientId.startsWith("https://")) {
    try {
      return new URL(clientId).host;
    } catch {
      return clientId;
    }
  }
  return clientId || "-";
}

export async function showApiKeyMcpGrants(row) {
  const body = el("div", { className: "mcp-grants" }, [statusMessage("正在加载 MCP 授权...")]);
  showModal(`MCP 授权 · ${row.name || row.id}`, body);
  const load = async (flash = "") => {
    let grants;
    try {
      const result = await api("/admin-api/api-keys/mcp-oauth-grants", { query: { api_key_id: row.id, status: "all", limit: 100 } });
      grants = Array.isArray(result.data) ? result.data : [];
    } catch (error) {
      body.replaceChildren(statusMessage(adminErrorMessage(error, "MCP 授权加载失败"), "error"));
      return;
    }
    const children = [];
    if (flash) {
      children.push(statusMessage(flash));
    }
    children.push(el("p", { className: "muted", text: "MCP 客户端（Claude、ChatGPT、Codex、Cursor 等）通过 OAuth 登录后，以这个 API 密钥的权限调用 TokenHub 的 MCP 工具。撤销后客户端需要重新登录。" }));
    if (grants.length === 0) {
      children.push(statusMessage("还没有 MCP 客户端用这个密钥登录"));
      body.replaceChildren(...children);
      return;
    }
    const table = el("table", { className: "resource-table mcp-grants-table" });
    table.append(el("thead", {}, [el("tr", {}, ["客户端", "登录方式", "登录用户", "授权时间", "最近使用", "状态", "操作"].map((label) => el("th", { text: label })))]));
    table.append(el("tbody", {}, grants.map((grant) => {
      const actions = el("td", {});
      if (grant.status === "active" && adminViewerCan("api_keys.manage")) {
        actions.append(button("撤销", async () => {
          if (!await confirmDialog({
            title: "撤销 MCP 授权",
            message: "撤销后该客户端的访问令牌和刷新令牌立即失效，需要重新登录。",
            details: [["客户端", mcpGrantClientLabel(grant)], ["API 密钥", row.name || `#${row.id}`]],
            confirmLabel: "撤销"
          })) {
            return;
          }
          try {
            await api(`/admin-api/api-keys/mcp-oauth-grants/${encodeURIComponent(grant.grant_id)}/revoke`, { method: "POST" });
            await load("已撤销授权");
          } catch (error) {
            await load();
            body.prepend(statusMessage(adminErrorMessage(error, "撤销失败"), "error"));
          }
        }, "danger mcp-grant-revoke"));
      }
      const statusText = grant.status === "active" ? "有效" : `已撤销${MCP_GRANT_REVOKE_REASONS[grant.revoked_reason] ? `（${MCP_GRANT_REVOKE_REASONS[grant.revoked_reason]}）` : ""}`;
      return el("tr", {}, [
        el("td", { text: mcpGrantClientLabel(grant), title: String(grant.client_id || "") }),
        el("td", { text: MCP_GRANT_LOGIN_LABELS[grant.login_method] || opsText(grant.login_method) }),
        el("td", { text: opsText(grant.username) }),
        el("td", { text: opsUtcTime(grant.created_at) }),
        el("td", { text: grant.last_used_at ? opsUtcTime(grant.last_used_at) : "未使用" }),
        el("td", { text: statusText }),
        actions
      ]);
    })));
    children.push(el("div", { className: "quota-status-table-wrap" }, [table]));
    body.replaceChildren(...children);
  };
  await load();
}
