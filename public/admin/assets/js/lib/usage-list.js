// 用量明细 (design): totals for the current filters above the list, compact
// cells (time, tokens, cost, latency) and expandable details for requests
// that did not succeed (error, route attempts, key).
import { api } from "../api.js";
import { el } from "../dom.js";
import { formatResourceValue } from "../resources.js";

const ALL_TIME_FROM = "2000-01-01 00:00:00";

function number(value) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

export function compact(value) {
  const amount = number(value);
  if (Math.abs(amount) >= 1e8) return `${(amount / 1e8).toFixed(2)} 亿`;
  if (Math.abs(amount) >= 1e6) return `${(amount / 1e6).toFixed(1)}M`;
  if (Math.abs(amount) >= 1e4) return `${(amount / 1e3).toFixed(1)}K`;
  return amount.toLocaleString("zh-CN");
}

/** Summary strip: requests, tokens, token mix and cost for the current filters. */
export function usageSummary() {
  const host = el("section", { className: "usage-summary", "aria-label": "筛选结果汇总", hidden: true });
  let seq = 0;
  host.refresh = async (filters) => {
    const current = ++seq;
    const query = {};
    for (const key of ["department_id", "user_id", "api_key_id", "provider_id", "model_id", "capability"]) {
      if (filters[key] !== undefined && filters[key] !== "") {
        query[key] = filters[key];
      }
    }
    query.from = filters.from || ALL_TIME_FROM;
    if (filters.to) {
      query.to = filters.to;
    }
    let today = null;
    try {
      const result = await api("/admin-api/dashboard/realtime", { query, silent: true });
      today = result?.data?.today || null;
    } catch {
      today = null;
    }
    if (current !== seq) {
      return;
    }
    if (!today) {
      host.hidden = true;
      return;
    }
    const input = number(today.input_tokens);
    const output = number(today.output_tokens);
    const cache = number(today.cache_read_tokens);
    const totalTokens = number(today.total_tokens) || input + output;
    const mixTotal = Math.max(1, input + output);
    const plainInput = Math.max(0, input - cache);
    const share = (value) => Math.round(value / mixTotal * 100);
    const cost = number(today.total_cost);
    const statusNote = filters.status ? "汇总按天统计，不区分请求结果" : "汇总按天统计";
    host.replaceChildren(
      el("div", { className: "usage-summary-item" }, [el("span", { text: "请求" }), el("strong", { text: number(today.request_count).toLocaleString("zh-CN") })]),
      el("div", { className: "usage-summary-item" }, [el("span", { text: "总 Token" }), el("strong", { text: compact(totalTokens), title: totalTokens.toLocaleString("zh-CN") })]),
      el("div", { className: "usage-summary-item usage-summary-mix" }, [
        el("span", { text: "Token 构成" }),
        el("span", { className: "usage-mix-bar", role: "img", "aria-label": `输入 ${share(plainInput)}%，输出 ${share(output)}%，缓存读 ${share(cache)}%` }, [
          el("span", { className: "is-input", style: `width: ${share(plainInput)}%` }),
          el("span", { className: "is-output", style: `width: ${share(output)}%` }),
          el("span", { className: "is-cache", style: `width: ${share(cache)}%` })
        ]),
        el("span", { className: "usage-mix-legend" }, [
          el("span", { text: `输入 ${share(plainInput)}%` }),
          el("span", { text: `输出 ${share(output)}%` }),
          el("span", { text: `缓存读 ${share(cache)}%` })
        ])
      ]),
      el("div", { className: "usage-summary-item" }, [
        el("span", { text: "成本" }),
        el("strong", { text: cost > 0 ? `¥${cost.toLocaleString("zh-CN", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : "未配置价格" })
      ]),
      el("p", { className: "usage-summary-note", text: statusNote })
    );
    host.hidden = false;
  };
  return host;
}

export function latencyText(value) {
  const ms = number(value);
  if (!ms) return "–";
  return ms >= 1000 ? `${(ms / 1000).toFixed(1)}s` : `${Math.round(ms)}ms`;
}

export function costText(value) {
  if (value === null || value === undefined || value === "") return "–";
  const amount = number(value);
  return `¥${amount < 0.01 && amount > 0 ? amount.toFixed(4) : amount.toFixed(3)}`;
}

function attemptsOf(row) {
  let attempts = row.attempts;
  if (typeof attempts === "string") {
    try {
      attempts = JSON.parse(attempts);
    } catch {
      attempts = null;
    }
  }
  return Array.isArray(attempts) ? attempts : [];
}

/** Details shown under a request that did not succeed. */
export function usageFailureDetails(row, { apiKeyLabel = "", onContent = null, onLogs = null } = {}) {
  const attempts = attemptsOf(row);
  const route = attempts.length
    ? attempts.map((attempt) => {
      const target = attempt.model || attempt.model_name || attempt.upstream_model || attempt.provider || "";
      const outcome = attempt.outcome || attempt.status || attempt.error_code || "";
      return [target, outcome].filter(Boolean).join(" · ");
    }).join(" → ")
    : (row.upstream_provider || row.upstream_model ? [row.upstream_provider, row.upstream_model].filter(Boolean).join(" / ") : "—");
  const error = [row.error_code || formatResourceValue("status", row.status), row.http_status ? `HTTP ${row.http_status}` : ""].filter(Boolean).join(" · ");
  const links = [];
  if (onContent) {
    links.push(el("button", { type: "button", className: "link-button is-accent", text: "留存内容", onClick: onContent }));
  }
  if (onLogs) {
    links.push(el("button", { type: "button", className: "link-button is-accent", text: "相关日志", onClick: onLogs }));
  }
  return el("div", { className: "usage-failure" }, [
    el("div", {}, [el("span", { text: "错误" }), el("strong", { className: "is-danger", text: error })]),
    el("div", {}, [el("span", { text: "路由" }), el("strong", { text: route })]),
    el("div", {}, [el("span", { text: "密钥" }), el("strong", { className: "is-mono", text: apiKeyLabel || (row.api_key_id ? `#${row.api_key_id}` : "—") })]),
    el("div", { className: "usage-failure-links" }, links)
  ]);
}
