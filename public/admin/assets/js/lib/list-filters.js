// Filters each list page really supports (CrudService::filterColumns and the
// dedicated endpoints). The generic "every page gets every filter" list showed
// controls that silently did nothing, and named some wrongly: for example the
// `status` parameter filters by action on 脱敏事件 and by log type on 日志归档.
//
// kinds:
//   search     – free-text box (debounced), e.g. keyword
//   segmented  – a few mutually exclusive values, shown with counts
//   select     – an inline select
//   department – department tree select
//   remote     – searchable picker backed by /admin-api/lookups
//   range      – time range presets plus custom from/to (params `from`, `to`)
import { valueLabel } from "../resources.js";

const values = (column, list) => list.map((value) => [value, valueLabel(column, value)]);
const ACTIVE = values("status", ["active", "disabled"]);

const status = (options = ACTIVE, label = "状态") => ({ name: "status", label, kind: "segmented", options });
const department = { name: "department_id", label: "部门", kind: "department" };
const user = (label = "用户") => ({ name: "user_id", label, kind: "remote", lookup: "users" });
const model = { name: "model_id", label: "模型", kind: "remote", lookup: "models" };
const provider = { name: "provider_id", label: "厂商", kind: "remote", lookup: "providers" };
const apiKey = { name: "api_key_id", label: "API 密钥", kind: "remote", lookup: "api-keys" };
const range = { name: "range", label: "时间", kind: "range" };
const capability = {
  name: "capability",
  label: "能力",
  kind: "select",
  options: values("capability", ["text_generation", "vision_understanding", "embedding", "asr", "tts", "image_generation", "video_generation"])
};

export const LIST_FILTERS = {
  users: [status(values("status", ["active", "disabled", "departed"])), department, user("用户名")],
  "admin-users": [status()],
  providers: [status(), { name: "protocol", label: "协议", kind: "select", options: values("protocol", ["openai", "anthropic", "native"]) }],
  models: [
    { name: "keyword", label: "模型名称", kind: "search", placeholder: "按模型名或上游模型名搜索" },
    status(),
    provider,
    // Empty means "only models the gateway can serve" (ModelSupportPolicy).
    { name: "support", label: "网关支持", kind: "select", emptyLabel: "仅可调用", options: [["unsupported", "不支持的模型"], ["all", "全部模型"]] },
    // Seven capabilities: counting each would cost seven requests per change.
    { ...capability, kind: "segmented", counts: false }
  ],
  "model-prices": [model],
  "api-key-model-quotas": [status(), apiKey, model],
  "quota-policies": [status()],
  // quota_bindings has no status column: the old 状态 filter did nothing.
  "quota-bindings": [],
  "usage-records": [
    range,
    status(values("status", ["success", "failed", "blocked", "timeout"]), "结果"),
    department,
    user(),
    model,
    capability,
    provider,
    apiKey
  ],
  "multimodal-tasks": [
    range,
    status(values("status", ["queued", "processing", "succeeded", "failed", "expired"])),
    { ...capability, label: "任务类型" },
    department,
    user(),
    model,
    provider,
    apiKey
  ],
  "media-assets": [range, department, user("所有者")],
  "usage-contents": [range, { name: "status", label: "留存方式", kind: "select", options: values("retention_mode", ["summary", "redacted", "raw", "none"]) }],
  "export-tasks": [
    range,
    status(values("status", ["queued", "processing", "succeeded", "failed", "expired"])),
    { name: "capability", label: "导出类型", kind: "select", options: values("export_type", ["usage", "session_archive", "logs"]) },
    user("申请人")
  ],
  "dashboard-share-links": [status()],
  "dashboard-share-access-logs": [range],
  "desensitization-policies": [status()],
  "desensitization-events": [range, { name: "status", label: "处置方式", kind: "select", options: values("action", ["redact", "block", "replace", "summary", "log", "allow", "remove_header"]) }],
  "log-retention-policies": [],
  "log-archive-files": [range, { name: "status", label: "日志类型", kind: "select", options: values("log_type", ["app", "audit", "security", "worker"]) }],
  "audit-logs": [range],
  logs: [
    { name: "request_id", label: "请求 ID", kind: "search", placeholder: "按请求 ID 查找" },
    range,
    { name: "log_type", label: "日志类型", kind: "select", options: values("log_type", ["app", "audit", "security", "worker"]) },
    { name: "error_code", label: "错误码", kind: "search", placeholder: "错误码" },
    user(),
    department,
    apiKey,
    model,
    provider,
    { name: "trace_id", label: "链路 ID", kind: "search", placeholder: "链路 ID" }
  ]
};

// Filters shown directly; the rest wait behind “更多筛选”.
export const VISIBLE_FILTERS = 5;

export const RANGE_PRESETS = [
  ["", "全部时间"],
  ["today", "今天"],
  ["7d", "近 7 天"],
  ["30d", "近 30 天"],
  ["custom", "自定义"]
];

function pad(value) {
  return String(value).padStart(2, "0");
}

// Server list filters compare against stored timestamps ("YYYY-MM-DD HH:MM:SS").
export function serverStamp(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

export function presetRange(preset, now = new Date()) {
  const days = { today: 0, "7d": 6, "30d": 29 }[preset];
  if (days === undefined) {
    return null;
  }
  const start = new Date(now);
  start.setHours(0, 0, 0, 0);
  start.setDate(start.getDate() - days);
  return { from: serverStamp(start), to: "" };
}

// Which preset a stored from/to pair corresponds to (presets leave `to` open).
export function presetFor(from, to) {
  if (!from && !to) {
    return "";
  }
  if (!to) {
    for (const [preset] of RANGE_PRESETS) {
      if (preset && preset !== "custom" && presetRange(preset)?.from === from) {
        return preset;
      }
    }
  }
  return "custom";
}
