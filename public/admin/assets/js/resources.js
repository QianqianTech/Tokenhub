import { columns, values } from "./i18n/zh-CN.js";
import { translate } from "./i18n.js";
export const adminPermissionOptions = [
  ["dashboard.read", "仪表盘查看"],
  ["departments.read", "部门查看"], ["departments.manage", "部门管理"],
  ["users.read", "用户查看"], ["users.manage", "用户管理"],
  ["api_catalog.read", "厂商与模型查看"], ["api_catalog.manage", "厂商与模型管理"],
  ["api_keys.read", "API 密钥查看"], ["api_keys.manage", "API 密钥管理"],
  ["quotas.read", "配额查看"], ["quotas.manage", "配额管理"],
  ["usage.read", "用量查看"], ["usage.manage", "用量管理"],
  ["content.read", "内容查看"], ["content.manage", "内容管理"], ["content.export", "内容导出"],
  ["rag.read", "RAG 查看"], ["rag.manage", "RAG 管理"],
  ["operations.read", "运营查看"], ["operations.manage", "运营管理"],
  ["security.read", "安全查看"], ["security.manage", "安全管理"],
  ["logs.read", "日志查看"], ["logs.manage", "日志管理"], ["logs.export", "日志导出"],
  ["system.read", "系统设置查看"], ["system.manage", "系统设置管理"],
  ["admins.manage", "管理员账号管理"]
];

const resourceCapability = (resourceKey) => {
  if (resourceKey === "dashboard") return "dashboard.read";
  if (resourceKey === "departments") return "departments.read";
  if (resourceKey === "users") return "users.read";
  if (resourceKey === "admin-users") return "admins.manage";
  if (resourceKey === "settings-smtp") return "admins.manage";
  if (resourceKey === "permission-config") return "admins.manage";
  if (["providers", "models", "model-prices"].includes(resourceKey)) return "api_catalog.read";
  if (resourceKey === "api-keys") return "api_keys.read";
  if (["api-key-model-quotas", "quota-policies", "quota-bindings"].includes(resourceKey)) return "quotas.read";
  if (resourceKey.startsWith("rag-")) return "rag.read";
  if (["usage-records", "multimodal-tasks", "media-assets"].includes(resourceKey)) return "usage.read";
  if (["usage-contents", "content-archive", "export-tasks"].includes(resourceKey)) return "content.read";
  if (["admin-chat-sessions", "dashboard-share-links", "dashboard-share-access-logs"].includes(resourceKey)) return "operations.read";
  if (resourceKey.startsWith("desensitization-")) return "security.read";
  if (["logs", "log-retention-policies", "log-archive-files", "alert-events", "audit-logs"].includes(resourceKey)) return "logs.read";
  if (resourceKey.includes("settings") || resourceKey === "content-retention-policy") return "system.read";
  return null;
};

// Enum value labels live in the i18n dictionary (i18n/zh-CN.js).
export const valueLabels = values;

export function valueLabel(column, value) {
  return translate(`value.${column}.${value}`, String(value));
}

export function formatResourceValue(column, value) {
  if (value === null || value === undefined) {
    return "";
  }
  if (typeof value === "object") {
    return JSON.stringify(value);
  }
  const raw = String(value);
  return translate(`value.${column}.${raw}`, raw);
}

const option = (column, value) => [value, valueLabel(column, value)];
const statusOptions = [["", "全部状态"], ...["active", "disabled", "queued", "processing", "succeeded", "failed", "success", "blocked", "timeout"].map((value) => option("status", value))];
// Metrics a shared screen may show; keys match DashboardService::screenMetric.
const SCREEN_METRIC_OPTIONS = [["overview", "总览"], ["trends", "趋势"], ["rankings", "排行"], ["performance", "性能"], ["security", "安全"], ["live", "实时"]];
const capabilityOptions = [["", "全部能力"], ...["text_generation", "vision_understanding", "video_understanding", "audio_understanding", "embedding", "asr", "tts", "image_generation", "video_generation", "rerank", "moderation", "music_generation", "realtime"].map((value) => option("capability", value))];

// Column labels live in the i18n dictionary (i18n/zh-CN.js); this export is
// kept for modules that read the whole map.
export const columnLabels = columns;

export function columnLabel(column) {
  return translate(`column.${column}`, column);
}

export const resources = {
  "dashboard": { label: "智慧分析", group: "运营" },
  "admin-chat-sessions": { label: "会话测试", group: "运营", readOnly: true },
  "departments": {
    label: "部门", group: "组织", tree: true,
    columns: ["id", "name", "parent_id", "path", "level", "sort_order", "status"],
    form: [
      { name: "name", label: "名称" },
      { name: "parent_id", label: "父部门 ID", type: "number", optional: true },
      { name: "sort_order", label: "排序", type: "number", optional: true }
    ]
  },
  "users": {
    label: "用户", group: "组织",
    columns: ["id", "username", "department_id", "role", "status", "last_login_at", "created_at"],
    form: [
      { name: "department_id", label: "部门 ID", type: "number" },
      { name: "username", label: "用户名" },
      { name: "password", label: "初始密码", type: "password", optional: true },
      { name: "role", label: "角色", type: "select", options: ["user", "admin", "super_admin"].map((value) => option("role", value)) },
      { name: "managed_department_ids", label: "管理部门 JSON", type: "json", optional: true }
    ]
  },
  "admin-users": {
    label: "管理员管理", group: "系统设置",
    columns: ["id", "username", "email", "department_id", "role", "admin_type", "admin_scope", "permissions", "managed_department_ids", "status", "last_login_at", "created_at"],
    form: [
      { name: "department_id", label: "所属部门", type: "select", coerce: "number", reference: "departments" },
      { name: "username", label: "管理员账号" },
      { name: "email", label: "找回密码邮箱", type: "email", optional: true, emptyValue: null, hint: "绑定后可在登录页通过此邮箱重置密码，每个邮箱只能绑定一个账号。" },
      { name: "password", label: "初始密码", type: "password" },
      { name: "role", label: "角色", type: "select", options: ["admin", "super_admin"].map((value) => option("role", value)) },
      { name: "admin_type", label: "管理员类型", type: "select", options: [["full_admin", "普通管理员（全部业务能力）"], ["user_admin", "用户管理员"], ["rag_admin", "RAG 管理员"], ["api_admin", "API 管理员"], ["custom", "自定义管理员"]] },
      { name: "admin_scope", label: "数据范围", type: "select", options: [["global", "全局"], ["departments", "授权部门及子部门"]] },
      { name: "permissions", label: "单独授权（勾选后覆盖预设权限）", type: "multiselect", options: adminPermissionOptions, optional: true, size: 12 },
      { name: "managed_department_ids", label: "授权管理部门", type: "multiselect", coerce: "number", reference: "departments", optional: true, size: 8 }
    ]
  },
  "permission-config": { label: "权限配置", group: "系统设置", readOnly: true },
  "providers": {
    label: "厂商", group: "模型",
    columns: ["id", "name", "protocol", "base_url", "secret_status", "status", "timeout_ms", "retry_count", "created_at"],
    form: [
      { name: "name", label: "厂商名称", placeholder: "例如：千问 Token Plan / DeepSeek" },
      { name: "protocol", label: "协议", type: "select", options: ["openai", "anthropic", "gemini", "native"].map((value) => option("protocol", value)) },
      { name: "base_url", label: "API 基础地址", placeholder: "粘贴厂商提供的 API 基础地址" },
      { name: "secret", label: "API Key", type: "password", optional: true, hint: "编辑时留空保留已有 Key；无需鉴权的服务可留空。" },
      { name: "timeout_ms", label: "请求超时（毫秒）", type: "number", optional: true, placeholder: "使用系统默认", hint: "范围 100–900000 毫秒" },
      { name: "retry_count", label: "重试次数", type: "number", optional: true, placeholder: "使用系统默认", hint: "0–5 次，0 表示不重试" },
      { name: "default_headers", label: "自定义请求头（JSON）", type: "json", optional: true, emptyValue: null, hint: "通常无需填写；使用 JSON 对象，例如 {\"X-Org\":\"team\"}。" }
    ]
  },
  "models": {
    label: "模型", group: "模型",
    columns: ["id", "provider_name", "name", "external_name", "capability", "context_length", "status", "route_priority", "gateway_support"],
    form: [
      { name: "provider_id", label: "厂商", type: "select", coerce: "number", reference: "providers" },
      { name: "name", label: "内部模型名" },
      { name: "external_name", label: "上游模型名" },
      { name: "capability", label: "能力", type: "select", options: capabilityOptions.slice(1) },
      { name: "context_length", label: "上下文长度", type: "number", optional: true },
      { name: "route_priority", label: "路由优先级", type: "number", optional: true }
    ]
  },
  "model-prices": {
    label: "价格", group: "模型",
    columns: ["id", "model_id", "price_categories", "price_summary", "currency", "effective_at"],
    form: [
      { name: "model_id", label: "模型", type: "select", coerce: "number", reference: "models" },
      { name: "input_token_price", label: "输入 Token 单价", optional: true },
      { name: "output_token_price", label: "输出 Token 单价", optional: true },
      { name: "audio_second_price", label: "音频秒单价", optional: true },
      { name: "tts_char_price", label: "TTS 字符单价", optional: true },
      { name: "image_price", label: "图片单价", optional: true },
      { name: "video_second_price", label: "视频秒单价", optional: true },
      { name: "request_price", label: "请求单价", optional: true },
      { name: "cache_read_token_price", label: "缓存命中 Token 单价", optional: true },
      { name: "cache_write_token_price", label: "缓存写入 Token 单价", optional: true },
      { name: "reasoning_token_price", label: "推理 Token 单价", optional: true },
      { name: "pricing_rules", label: "计价规则 JSON（分时段/分档/分辨率/上游成本，可选）", type: "json", optional: true },
      { name: "currency", label: "币种", optional: true },
      { name: "effective_at", label: "生效时间", optional: true }
    ]
  },
  "api-keys": {
    label: "API 密钥", group: "访问",
    columns: ["id", "name", "created_at", "key", "status"],
    detailColumns: ["id", "name", "user_id", "department_id", "key_prefix", "key_suffix", "status", "disabled_by", "request_count", "success_count", "failed_count", "input_tokens", "output_tokens", "cache_read_tokens", "cache_write_tokens", "cache_tokens", "total_tokens", "total_cost", "expires_at", "last_used_at", "created_at"],
    oneTimeSecret: true,
    form: [
      { name: "department_id", label: "部门", type: "select", coerce: "number", reference: "departments", emptyOption: "请选择部门" },
      { name: "user_id", label: "员工", type: "select", coerce: "number", reference: "users" },
      { name: "name", label: "名称" },
      { name: "allowed_ip_cidrs", label: "允许 IP 网段（每行一个）", type: "cidr-list", optional: true },
      { name: "model_ids", label: "允许模型", type: "multiselect", coerce: "number", reference: "models", optional: true },
      { name: "expires_at", label: "过期时间", optional: true }
    ]
  },
  "api-key-model-quotas": {
    label: "模型限额", group: "访问",
    columns: ["id", "api_key_id", "model_id", "period", "request_limit", "input_token_limit", "output_token_limit", "total_token_limit", "status", "updated_at"],
    form: [
      { name: "api_key_id", label: "API 密钥 / 用户", type: "select", coerce: "number", reference: "apiKeys" },
      { name: "model_id", label: "模型", type: "select", coerce: "number", reference: "models" },
      { name: "period", label: "周期", type: "select", options: ["minute", "hour", "day", "month"].map((value) => option("period", value)) },
      { name: "request_limit", label: "请求限制，0 或留空表示不限制", type: "number" },
      { name: "input_token_limit", label: "输入 Token 限制，0 或留空表示不限制", type: "number" },
      { name: "output_token_limit", label: "输出 Token 限制，0 或留空表示不限制", type: "number" },
      { name: "total_token_limit", label: "总 Token 限制，0 或留空表示不限制", type: "number" },
      { name: "status", label: "状态", type: "select", options: ["active", "disabled"].map((value) => option("status", value)) }
    ]
  },
  "quota-policies": {
    label: "限额策略", group: "访问",
    columns: ["id", "name", "period", "request_limit", "input_token_limit", "output_token_limit", "total_token_limit", "cost_limit", "status"],
    form: [
      { name: "name", label: "名称" },
      { name: "period", label: "周期", type: "select", options: ["minute", "hour", "day", "month"].map((value) => option("period", value)) },
      { name: "request_limit", label: "请求限制，0 或留空表示不限制", type: "number" },
      { name: "input_token_limit", label: "输入 Token 限制，0 或留空表示不限制", type: "number" },
      { name: "output_token_limit", label: "输出 Token 限制，0 或留空表示不限制", type: "number" },
      { name: "total_token_limit", label: "总 Token 限制，0 或留空表示不限制", type: "number" },
      { name: "cost_limit", label: "成本限制，0 或留空表示不限制", optional: true },
      { name: "concurrency_limit", label: "并发限制，0 或留空表示不限制", type: "number" },
      { name: "status", label: "状态", type: "select", options: ["active", "disabled"].map((value) => option("status", value)) }
    ]
  },
  "quota-bindings": {
    label: "限额绑定", group: "访问",
    columns: ["id", "quota_policy_id", "target_type", "target_id"],
    form: [
      { name: "quota_policy_id", label: "限额策略", type: "select", coerce: "number", reference: "quotaPolicies" },
      { name: "target_ref", label: "绑定目标", type: "select", reference: "quotaTargets" }
    ]
  },
  "usage-records": { label: "用量明细", group: "用量", readOnly: true, columns: ["id", "request_id", "user_id", "department_id", "api_key_id", "model_name", "capability", "status", "input_tokens", "output_tokens", "cache_read_tokens", "cache_write_tokens", "total_tokens", "cost_amount", "latency_ms", "created_at"], exportType: "usage" },
  "multimodal-tasks": { label: "多模态任务", group: "用量", columns: ["id", "task_id", "task_type", "status", "user_id", "department_id", "provider_id", "model_id", "latency_ms", "created_at"] },
  "media-assets": { label: "媒体资产", group: "用量", columns: ["id", "asset_id", "media_type", "owner_user_id", "department_id", "mime_type", "size_bytes", "expires_at", "created_at"] },
  "usage-contents": { label: "内容留存", group: "日志", readOnly: true, columns: ["id", "usage_record_id", "retention_mode", "summary", "expires_at", "cleaned_at", "created_at"], exportType: "session_archive" },
  "content-archive": { label: "会话存档", group: "日志", readOnly: true, columns: ["content_id", "request_id", "session_id", "user_id", "department_id", "model_name", "status", "retention_mode", "created_at", "cleaned_at"], exportType: "session_archive" },
  "export-tasks": { label: "导出任务", group: "日志", readOnly: true, columns: ["id", "export_id", "export_type", "status", "file_path", "error_code", "expires_at", "created_at"] },
  "dashboard-share-links": { label: "大屏分享", group: "运营", oneTimeSecret: true, createLabel: "创建并显示访问密码", columns: ["id", "share_code", "name", "username", "scope_type", "scope_ids", "allowed_metrics", "allow_drilldown", "allowed_ip_cidrs", "expires_at", "status"], form: [{ name: "share_code", label: "链接码", optional: true }, { name: "name", label: "名称" }, { name: "scope_type", label: "范围", type: "select", options: ["global", "department", "user", "api_key"].map((value) => option("scope_type", value)) }, { name: "scope_ids", label: "范围对象", type: "remote-multiselect", lookup: "departments", coerce: "number", optional: true, scopeOf: "scope_type" }, { name: "allowed_metrics", label: "允许查看的指标（不选表示全部）", type: "multiselect", optional: true, options: SCREEN_METRIC_OPTIONS }, { name: "allow_drilldown", label: "排行显示对象 ID", type: "select", options: [[0, "否"], [1, "是"]] }, { name: "allowed_ip_cidrs", label: "允许 IP 网段（每行一个，留空不限）", type: "cidr-list", optional: true }, { name: "expires_at", label: "访问截止时间", optional: true }, { name: "status", label: "状态", type: "select", options: ["active", "disabled"].map((value) => option("status", value)) }] },
  "dashboard-share-access-logs": { label: "大屏访问日志", group: "运营", readOnly: true, columns: ["id", "share_link_id", "event_type", "client_ip", "reason", "created_at"] },
  "desensitization-policies": { label: "脱敏策略", group: "安全", columns: ["id", "name", "scope_type", "scope_id", "action", "status"], form: [{ name: "name", label: "名称" }, { name: "scope_type", label: "范围类型", type: "select", options: ["system", "department", "api_key", "model", "provider"].map((value) => option("scope_type", value)) }, { name: "scope_id", label: "范围 ID", type: "number", optional: true }, { name: "parsers", label: "解析器 JSON", type: "json" }, { name: "action", label: "动作", type: "select", options: ["block", "replace", "summary"].map((value) => option("action", value)) }] },
  "desensitization-events": { label: "脱敏事件", group: "安全", readOnly: true, columns: ["id", "policy_id", "request_id", "target_type", "action", "match_count", "summary", "created_at"] },
  "log-retention-policies": { label: "日志留存", group: "日志", columns: ["id", "log_type", "online_retention_days", "archive_retention_days", "min_retention_days", "compress_enabled", "encrypt_enabled"] },
  "log-archive-files": { label: "日志归档", group: "日志", readOnly: true, columns: ["id", "log_type", "archive_path", "compressed", "encrypted", "size_bytes", "created_at", "deleted_at"] },
  "logs": { label: "日志中心", group: "日志", readOnly: true, columns: ["timestamp", "log_type", "level", "request_id", "trace_id", "user_id", "department_id", "api_key_id", "provider_id", "model_id", "client_ip", "status", "latency_ms", "error_code"], exportType: "logs" },
  "alert-events": { label: "告警事件", group: "日志", readOnly: true, columns: ["id", "event_type", "severity", "message", "status", "created_at"] },
  "audit-logs": { label: "审计日志", group: "日志", readOnly: true, columns: ["id", "actor_user_id", "actor_type", "action", "target_type", "target_id", "channel", "device_id", "request_id", "client_ip", "created_at"], exportType: "logs" },
  "content-retention-policy": { label: "内容留存策略", group: "系统设置", readOnly: true },
  "settings-center": { label: "系统设置", group: "系统设置", readOnly: true },
  "settings-brand-identity": { label: "标题与标识", group: "系统设置", readOnly: true, settingsSection: "brand_identity" },
  "settings-smtp": { label: "SMTP 邮件", group: "系统设置", readOnly: true, settingsSection: "smtp" },
  "settings-tls-certificates": { label: "证书与入口", group: "系统设置", readOnly: true, settingsSection: "tls_certificates" },
  "settings-entry-security": { label: "访问安全", group: "系统设置", readOnly: true, settingsSection: "entry_security" },
  "settings-retention-export": { label: "留存与导出", group: "系统设置", readOnly: true, settingsSection: "retention_export" },
  "settings-alert-thresholds": { label: "告警设置", group: "系统设置", readOnly: true, settingsSection: "alert_thresholds" },
  "settings-rag-upload-review": { label: "RAG上传审核", group: "系统设置", readOnly: true, settingsSection: "rag_upload_review" },
  "settings-rag-package-upload": { label: "RAG图片包上传", group: "系统设置", readOnly: true, settingsSection: "rag_package_upload" },
  "settings-rag-ai-image-processing": { label: "RAG图片AI处理", group: "系统设置", readOnly: true, settingsSection: "rag_ai_image_processing" },
  "settings-rag-vectorization-monitoring": { label: "RAG向量监控", group: "系统设置", readOnly: true, settingsSection: "rag_vectorization_monitoring" },
  "operations-status": { label: "运行状态", group: "系统运维", readOnly: true },
  "rag-settings": { label: "RAG 设置", group: "RAG知识库", readOnly: true },
  "system-settings": { label: "高级设置", group: "系统设置", readOnly: true }
};

const navigationLabels = {
  "dashboard": { label: "智慧分析", group: "运营" },
  "admin-chat-sessions": { label: "会话测试", group: "运营" },
  "departments": { label: "部门管理", group: "组织" },
  "users": { label: "用户管理", group: "组织" },
  "admin-users": { label: "管理员管理", group: "系统设置" },
  "providers": { label: "厂商管理", group: "模型" },
  "models": { label: "模型管理", group: "模型" },
  "model-prices": { label: "价格配置", group: "模型" },
  "api-keys": { label: "API 密钥", group: "访问" },
  "api-key-model-quotas": { label: "模型限额", group: "访问" },
  "quota-policies": { label: "限额策略", group: "访问" },
  "quota-bindings": { label: "限额绑定", group: "访问" },
  "usage-records": { label: "用量明细", group: "用量" },
  "multimodal-tasks": { label: "多模态任务", group: "用量" },
  "media-assets": { label: "媒体资产", group: "用量" },
  "usage-contents": { label: "内容留存", group: "日志" },
  "content-archive": { label: "会话归档", group: "日志" },
  "export-tasks": { label: "导出任务", group: "日志" },
  "dashboard-share-links": { label: "大屏分享", group: "运营" },
  "dashboard-share-access-logs": { label: "大屏访问日志", group: "运营" },
  "desensitization-policies": { label: "脱敏策略", group: "安全" },
  "desensitization-events": { label: "脱敏事件", group: "安全" },
  "log-retention-policies": { label: "日志留存", group: "日志" },
  "log-archive-files": { label: "日志归档", group: "日志" },
  "logs": { label: "日志中心", group: "日志" },
  "alert-events": { label: "告警事件", group: "日志" },
  "audit-logs": { label: "审计日志", group: "日志" },
  "content-retention-policy": { label: "内容留存策略", group: "系统设置" },
  "settings-center": { label: "系统设置", group: "系统设置" },
  "settings-brand-identity": { label: "标题与标识", group: "系统设置" },
  "settings-tls-certificates": { label: "证书与入口", group: "系统设置" },
  "settings-entry-security": { label: "访问安全", group: "系统设置" },
  "settings-retention-export": { label: "留存与导出", group: "系统设置" },
  "settings-alert-thresholds": { label: "告警设置", group: "系统设置" },
  "settings-rag-upload-review": { label: "RAG上传审核", group: "系统设置" },
  "settings-rag-package-upload": { label: "RAG图片包上传", group: "系统设置" },
  "settings-rag-ai-image-processing": { label: "RAG图片AI处理", group: "系统设置" },
  "settings-rag-vectorization-monitoring": { label: "RAG向量监控", group: "系统设置" },
  "system-settings": { label: "高级设置", group: "系统设置" }
};

const ragResourceDefinitions = {
  "rag-console": { label: "RAG 控制台", group: "RAG知识库", readOnly: true },
  "rag-knowledge-management": { label: "知识库管理", group: "RAG知识库", readOnly: true },
  "rag-import-review": { label: "导入与审核", group: "RAG知识库", readOnly: true },
  "rag-image-vector": { label: "图片与向量", group: "RAG知识库", readOnly: true },
  "rag-feedback-correction": { label: "反馈与纠偏", group: "RAG知识库", readOnly: true },
  "rag-advanced-data": { label: "高级数据", group: "RAG知识库", readOnly: true },
  "rag-build": { label: "构建知识", group: "RAG知识库", readOnly: true },
  "rag-access": { label: "知识集权限", group: "RAG知识库", readOnly: true },
  "rag-index-health": { label: "索引健康", group: "RAG知识库", readOnly: true },
  "rag-retrieval-lab": { label: "检索试验台", group: "RAG知识库", readOnly: true },
  "rag-quality": { label: "质量评估", group: "RAG知识库", readOnly: true },
  "rag-operations": { label: "运行维护", group: "RAG知识库", readOnly: true },
  "rag-dashboard": { label: "总览", group: "RAG知识库", readOnly: true },
  "rag-template-center": { label: "模板下载", group: "RAG知识库", readOnly: true },
  "rag-upload-workbench": { label: "上传导入", group: "RAG知识库", readOnly: true },
  "rag-upload-detail": { label: "上传详情", group: "RAG知识库", readOnly: true },
  "rag-knowledge-bases": {
    label: "知识库", group: "RAG知识库",
    columns: ["name", "status", "owner_user_id", "department_id", "created_at"],
    form: [
      { name: "name", label: "名称" },
      { name: "description", label: "说明", type: "textarea", optional: true },
      { name: "department_id", label: "所属部门", type: "number", optional: true },
      { name: "default_text_vector_config_id", label: "默认文本向量配置", type: "select", coerce: "number", reference: "vectorConfigs", optional: true },
      { name: "default_image_vector_config_id", label: "默认图片向量配置", type: "select", coerce: "number", reference: "vectorConfigs", optional: true },
      { name: "default_multimodal_vector_config_id", label: "默认多模态向量配置", type: "select", coerce: "number", reference: "vectorConfigs", optional: true },
      // Stored in chunking_profile.redaction / retrieval_profile (see forms.js knowledgeBasePayload).
      { name: "redaction_mode", label: "入库脱敏", type: "select", optional: true, options: [["", "使用系统默认（RAG_REDACTION_TYPES）"], ["off", "关闭脱敏"], ["custom", "按勾选的类型脱敏"]] },
      { name: "redaction_types", label: "脱敏类型（选择“按勾选的类型脱敏”时生效）", type: "multiselect", optional: true, options: [["phone", "手机号"], ["email", "邮箱"], ["id_card", "身份证号"], ["bank_card", "银行卡号"], ["ipv4", "IPv4 地址"]] },
      { name: "min_vector_score", label: "向量相似度下限（0–1，留空用向量配置里的值，默认 0.35）", type: "number", optional: true },
      { name: "min_lexical_score", label: "关键词得分下限（0–1，留空用默认 0.08）", type: "number", optional: true },
      { name: "min_hybrid_confidence", label: "混合检索置信度下限（0–1，留空用默认 0.20）", type: "number", optional: true },
      { name: "exact_match_discount", label: "精确匹配折扣（0–0.25，留空用默认 0.10）", type: "number", optional: true },
      { name: "rerank_model_id", label: "重排模型（可选）", type: "select", coerce: "number", reference: "models", optional: true },
      { name: "rerank_top_n", label: "重排候选数（可选）", type: "number", optional: true },
      { name: "rerank_min_score", label: "重排得分下限（可选；填写后由重排模型把关，向量阈值只做宽松预筛）", type: "number", optional: true },
      { name: "query_rewrite_model_id", label: "多轮查询改写模型（可选；用对话上下文补全“那个、它”等指代后再检索）", type: "select", coerce: "number", reference: "models", optional: true },
      { name: "query_rewrite_mode", label: "查询改写时机", type: "select", optional: true, options: [["auto", "自动：只改写依赖上文的追问"], ["always", "每次有对话历史都改写"]] }
    ]
  },
  "rag-knowledge-base-bindings": { label: "知识库授权（原始表，只读；请在“知识集权限”中修改）", group: "RAG知识库", readOnly: true, columns: ["id", "kb_id", "subject_type", "subject_id", "permission", "is_default", "include_descendants", "expires_at", "created_at"] },
  "rag-vector-datasets": {
    label: "向量数据集", group: "RAG知识库",
    columns: ["name", "is_default", "status", "chunk_count", "vector_count", "last_indexed_at"],
    form: [
      { name: "kb_id", label: "所属知识库", type: "select", coerce: "number", reference: "knowledgeBases" },
      { name: "name", label: "数据集名称" },
      { name: "description", label: "说明", type: "textarea", optional: true },
      { name: "dataset_type", label: "数据集类型", type: "select", options: [["mixed", "混合"], ["text", "文本"], ["image", "图片"], ["multimodal", "多模态"]] },
      { name: "status", label: "状态", type: "select", options: [["active", "启用"], ["draft", "草稿"], ["reindexing", "重建索引"], ["disabled", "停用"], ["error", "异常"]] },
      { name: "text_vector_config_id", label: "文本向量配置", type: "select", coerce: "number", reference: "vectorConfigs", optional: true },
      { name: "image_vector_config_id", label: "图片向量配置", type: "select", coerce: "number", reference: "vectorConfigs", optional: true },
      { name: "multimodal_vector_config_id", label: "多模态向量配置", type: "select", coerce: "number", reference: "vectorConfigs", optional: true },
      { name: "retrieval_profile", label: "检索配置 JSON", type: "json", optional: true }
    ]
  },
  "rag-dataset-bindings": { label: "数据集授权", group: "RAG知识库", readOnly: true, columns: ["id", "dataset_id", "subject_type", "subject_id", "permission", "is_default", "priority", "include_descendants", "expires_at", "created_at"] },
  "rag-vector-configs": {
    label: "向量配置", group: "RAG知识库",
    columns: ["id", "config_id", "name", "modality", "model_name", "dimension", "vector_store", "collection_name", "enabled"],
    form: [
      { name: "config_id", label: "配置标识" },
      { name: "name", label: "配置名称" },
      { name: "modality", label: "模态", type: "select", options: [["text", "文本"], ["image", "图片"], ["multimodal", "多模态"]] },
      { name: "provider_id", label: "厂商", type: "select", coerce: "number", reference: "providers", optional: true },
      { name: "model_id", label: "向量模型", type: "select", coerce: "number", reference: "models", optional: true },
      { name: "model_name", label: "本地模型名称", optional: true },
      { name: "dimension", label: "向量维度", type: "number" },
      { name: "embedding_api", label: "图片向量接口（图片/多模态配置）", type: "select", optional: true, options: [["text", "不送图片：用图片描述文字做向量"], ["openai_mixed", "Jina 兼容 /v1/embeddings（jina-clip-v2 等）"], ["ark_multimodal", "火山方舟多模态向量（doubao-embedding-vision）"], ["dashscope_multimodal", "阿里云百炼多模态向量"]] },
      { name: "min_vector_score", label: "向量最低分（留空用默认；可用 rag-index.php calibrate 校准）", type: "number", optional: true },
      { name: "distance_metric", label: "距离度量", type: "select", options: [["cosine", "余弦相似度"], ["dot", "点积"], ["euclidean", "欧氏距离"]] },
      { name: "vector_store", label: "向量库", type: "select", options: [["qdrant", "Qdrant"], ["mysql_fallback", "MySQL 回退（仅适合少量数据）"]] },
      { name: "collection_name", label: "集合名称" },
      { name: "batch_size", label: "批处理大小", type: "number", optional: true },
      { name: "config_json", label: "扩展配置 JSON", type: "json", optional: true },
      { name: "enabled", label: "启用", type: "select", coerce: "number", options: [["1", "启用"], ["0", "停用"]] }
    ]
  },
  "rag-import-templates": { label: "导入模板原始表", group: "RAG知识库", columns: ["id", "template_id", "template_type", "name", "version", "enabled", "updated_at"] },
  "rag-uploads": { label: "导入记录", group: "RAG知识库", columns: ["content_type", "status", "stage", "progress", "total_items", "text_items", "image_items", "created_at"] },
  "rag-image-preview": { label: "图片预览", group: "RAG知识库", readOnly: true, columns: ["preview_item_id", "item_type", "title", "purpose", "description", "validation_status"] },
  "rag-vectorization-metrics": { label: "向量化监控", group: "RAG知识库", readOnly: true, columns: ["overall_success_rate", "total_jobs", "failed_jobs", "alert_code"] },
  "rag-retrieval-evaluation": { label: "检索评测", group: "RAG知识库", readOnly: true, columns: ["recall_at_5", "recall_at_10", "recall_at_20", "mrr", "failed_cases"] },
  "rag-upload-preview-items": { label: "上传预览原始数据", group: "RAG知识库", columns: ["id", "preview_item_id", "upload_id", "item_type", "title", "validation_status", "image_asset_id", "created_at"] },
  "rag-documents": { label: "文档", group: "RAG知识库", columns: ["id", "document_id", "kb_id", "dataset_id", "title", "doc_type", "status", "version", "created_at"] },
  "rag-chunks": { label: "内容片段", group: "RAG知识库", columns: ["chunk_type", "title", "enabled", "lifecycle_status", "created_at"] },
  "rag-image-assets": { label: "图片资产", group: "RAG知识库", columns: ["id", "image_id", "kb_id", "upload_id", "mime_type", "width", "height", "purpose", "short_description", "warnings", "created_at"] },
  "rag-embedding-jobs": { label: "向量任务", group: "RAG知识库", columns: ["id", "job_id", "upload_id", "dataset_id", "job_type", "status", "processed_items", "progress_percent", "error_code", "created_at"] },
  "rag-vector-records": { label: "向量记录", group: "RAG知识库", columns: ["id", "vector_id", "collection_name", "dataset_id", "chunk_id", "modality", "dimension", "status", "indexed_at", "deleted_at"] },
  "rag-retrieval-logs": { label: "检索日志", group: "RAG知识库", columns: ["id", "retrieval_id", "request_id", "api_key_id", "hit_count", "fallback_used", "created_at"] },
  "rag-evaluation": { label: "检索评估", group: "RAG知识库", readOnly: true },
  "rag-answer-feedback": { label: "用户反馈", group: "RAG知识库", columns: ["id", "feedback_id", "retrieval_id", "api_key_id", "rating", "status", "created_at"] },
  "rag-correction-tasks": { label: "纠偏任务", group: "RAG知识库", columns: ["id", "task_id", "feedback_id", "retrieval_id", "issue_type", "priority", "status", "created_at"] },
  "rag-retention-policies": { label: "过期策略", group: "RAG知识库", columns: ["id", "policy_id", "target_type", "target_id", "action", "expire_after_days", "enabled", "created_at"] },
  "rag-eval-cases": { label: "检索评估样本", group: "RAG知识库", columns: ["query", "status", "created_at"] },
  "rag-eval-runs": { label: "检索评估运行", group: "RAG知识库", columns: ["id", "run_id", "case_count", "recall_at_5", "recall_at_10", "recall_at_20", "mrr", "failed_cases", "status", "created_at"] },
  "rag-eval-results": { label: "检索评估结果", group: "RAG知识库", columns: ["id", "run_id", "case_id", "hit_rank", "passed", "created_at"] }
};
Object.assign(resources, ragResourceDefinitions);

for (const [key, metadata] of Object.entries(navigationLabels)) {
  if (resources[key]) {
    Object.assign(resources[key], metadata);
  }
}

// Pages that are not CRUD resources but still need a capability.
const specialPageCapabilities = {
  "operations-status": "operations.read"
};

// Single source of truth for "which capability opens this page".
export function pageCapability(key) {
  return specialPageCapabilities[key] || resourceCapability(key);
}

export function resourcesForUser(user) {
  const permissions = new Set(Array.isArray(user?.permissions) ? user.permissions : []);
  return Object.entries(resources).filter(([key]) => {
    const capability = pageCapability(key);
    return capability !== null && permissions.has(capability);
  });
}

export function filterFields(resourceKey) {
  const fields = [
    { name: "status", label: "状态", type: "select", options: statusOptions },
    { name: "department_id", label: "部门 ID", type: "number", optional: true },
    { name: "user_id", label: "用户 ID", type: "number", optional: true },
    { name: "provider_id", label: "厂商 ID", type: "number", optional: true },
    { name: "model_id", label: "模型 ID", type: "number", optional: true },
    { name: "capability", label: "能力", type: "select", options: capabilityOptions },
    { name: "from", label: "开始时间", optional: true },
    { name: "to", label: "结束时间", optional: true }
  ];
  if (resourceKey === "logs") {
    return [
      { name: "log_type", label: "日志类型", optional: true },
      { name: "request_id", label: "请求 ID", optional: true },
      { name: "trace_id", label: "链路 ID", optional: true },
      { name: "user_id", label: "用户 ID", type: "number", optional: true },
      { name: "department_id", label: "部门 ID", type: "number", optional: true },
      { name: "api_key_id", label: "API 密钥 ID", type: "number", optional: true },
      { name: "provider_id", label: "厂商 ID", type: "number", optional: true },
      { name: "model_id", label: "模型 ID", type: "number", optional: true },
      { name: "error_code", label: "错误码", optional: true },
      { name: "from", label: "开始时间", optional: true },
      { name: "to", label: "结束时间", optional: true }
    ];
  }
  if (resourceKey === "audit-logs") {
    return [
      { name: "action", label: "操作", optional: true },
      { name: "actor_user_id", label: "操作人 ID", type: "number", optional: true },
      { name: "channel", label: "渠道", type: "select", options: [["", "全部渠道"], ["web", "Web 后台"], ["app", "TokenHub APP"]] },
      { name: "device_id", label: "APP 设备 ID", type: "number", optional: true },
      { name: "from", label: "开始时间", optional: true },
      { name: "to", label: "结束时间", optional: true }
    ];
  }
  if (["usage-records", "multimodal-tasks", "media-assets", "usage-contents", "export-tasks", "desensitization-events", "audit-logs", "alert-events", "log-archive-files"].includes(resourceKey)) {
    return fields;
  }
  if (resourceKey === "models") {
    // Backend filters models by provider_id, capability and status (CrudService::filterColumns);
    // keyword is a partial match on the model or upstream name; support defaults
    // (empty) to only the models the gateway can serve (ModelSupportPolicy).
    return [
      { name: "keyword", label: "模型名称", optional: true },
      { name: "status", label: "状态", type: "select", options: statusOptions },
      { name: "provider_id", label: "厂商 ID", type: "number", optional: true },
      { name: "capability", label: "能力", type: "select", options: capabilityOptions },
      { name: "support", label: "网关支持", type: "select", options: [["", "仅可调用的模型"], ["unsupported", "不支持的模型"], ["all", "全部模型"]] }
    ];
  }
  if (resourceKey === "api-key-model-quotas") {
    return [
      { name: "status", label: "状态", type: "select", options: statusOptions },
      { name: "api_key_id", label: "API 密钥 ID", type: "number", optional: true },
      { name: "model_id", label: "模型 ID", type: "number", optional: true }
    ];
  }
  if (["users", "api-keys", "departments"].includes(resourceKey)) {
    return fields.slice(0, 3);
  }
  if (resourceKey === "rag-knowledge-bases") {
    return [{ name: "status", label: "知识库状态", type: "select", options: [["", "全部状态"], ["active", "启用"], ["draft", "草稿"], ["disabled", "停用"]] }];
  }
  if (resourceKey === "rag-vector-datasets") {
    return [{ name: "status", label: "数据集状态", type: "select", options: [["", "全部状态"], ["active", "启用"], ["draft", "草稿"], ["reindexing", "重建索引"], ["disabled", "停用"], ["error", "异常"]] }];
  }
  if (resourceKey === "rag-eval-cases") {
    return [{ name: "status", label: "样本状态", type: "select", options: [["", "全部状态"], ["active", "启用"], ["draft", "草稿"], ["disabled", "停用"]] }];
  }
  return fields.slice(0, 1);
}
