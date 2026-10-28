import { adminErrorMessage, api, streamApi } from "./api.js?v=20260710-csrf-shared-v1";
import { button, clear, el, field, readFields, setText, showModal, statusMessage } from "./dom.js?v=20260722-modal-close-label-v1";
import { adminPermissionOptions, columnLabel, filterFields, formatResourceValue, resources, valueLabel } from "./resources.js?v=20260710-admin-users-localization-v1";

const MODEL_PRICE_CATEGORIES = [
  { key: "text", label: "文字", fields: ["input_token_price", "output_token_price"] },
  { key: "asr", label: "ASR", fields: ["audio_second_price"] },
  { key: "tts", label: "TTS", fields: ["tts_char_price"] },
  { key: "image", label: "图片", fields: ["image_price"] },
  { key: "video", label: "视频", fields: ["video_second_price"] },
  { key: "request", label: "请求", fields: ["request_price"] }
];

const MODEL_CAPABILITY_CATEGORY_LABELS = {
  text_generation: "文字",
  vision_understanding: "图片",
  embedding: "文字",
  asr: "ASR",
  tts: "TTS",
  image_generation: "图片",
  video_generation: "视频"
};

const SETTINGS_SECTION_PAGES = {
  "settings-brand-identity": {
    title: "标题与标识",
    section: "brand_identity",
    fields: [
      { name: "product_name", label: "产品名称" },
      { name: "admin_title", label: "管理后台标题" },
      { name: "screen_title", label: "运营大屏标题" },
      { name: "logo_url", label: "Logo 图标地址（同源路径或 data:image）", optional: true },
      { name: "favicon_url", label: "浏览器图标地址（同源路径或 data:image）", optional: true }
    ]
  },
  "settings-tls-certificates": {
    title: "证书与入口",
    section: "tls_certificates",
    fields: [
      { name: "certificate_mode", label: "证书类型", type: "select", options: [["domain", "域名证书"], ["internal_ip", "内网 IP 证书"], ["manual", "手动证书"]] },
      { name: "domain_names", label: "域名证书域名（每行一个）", type: "textarea", optional: true, rows: 3 },
      { name: "ip_addresses", label: "IP 证书地址（内网使用，每行一个）", type: "textarea", optional: true, rows: 3 },
      { name: "auto_renewal", label: "自动续期", type: "select", options: [["true", "启用"], ["false", "停用"]] },
      { name: "renewal_provider", label: "续期/签发来源", type: "select", options: [["caddy_acme", "Caddy 公共 ACME（域名）"], ["caddy_internal", "Caddy 内部 CA（内网 IP）"], ["manual_file", "手动证书文件"]] },
      { name: "certificate_paths", label: "真实证书扫描路径（每行一个）", type: "textarea", optional: true, rows: 3 },
      { name: "threshold_days", label: "证书到期告警天数", type: "number" }
    ]
  },
  "settings-entry-security": {
    title: "访问安全",
    section: "entry_security",
    fields: [
      { name: "admin_allowed_cidrs", label: "管理端允许网段，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "api_allowed_cidrs", label: "接口入口允许网段，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "api_key_cidr_enforced", label: "API 密钥 IP 白名单校验", type: "select", options: [["true", "启用"], ["false", "停用"]] },
      { name: "masked_header_fields", label: "脱敏请求头字段，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "masked_query_fields", label: "脱敏查询参数字段，每行一个", type: "textarea", optional: true, rows: 3 }
    ]
  },
  "settings-firewall": {
    title: "WAF 防火墙",
    section: "firewall_settings",
    fields: [
      { name: "enabled", label: "启用防火墙", type: "select", options: [["true", "启用"], ["false", "停用"]] },
      { name: "auto_publish", label: "保存后自动发布", type: "select", options: [["false", "否"], ["true", "是"]] },
      { name: "auto_allow_local_private_ips", label: "自动允许本机内网 IP（推荐）", type: "select", options: [["true", "启用"], ["false", "停用"]] },
      { name: "admin_allowed_cidrs", label: "管理端允许网段，每行一个（留空不限制）", type: "textarea", optional: true, rows: 3 },
      { name: "api_allowed_cidrs", label: "接口入口允许网段，每行一个（留空不限制）", type: "textarea", optional: true, rows: 3 },
      { name: "manual_blocked_cidrs", label: "手动封禁 IP 网段，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "allowed_methods", label: "允许请求方法，每行一个", type: "textarea", optional: true, rows: 4 },
      { name: "blocked_paths", label: "阻断路径前缀，每行一个", type: "textarea", optional: true, rows: 4 },
      { name: "sensitive_query_keys", label: "敏感查询参数，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "max_body_bytes", label: "最大请求体字节数", type: "number" },
      { name: "login_failure_threshold", label: "登录失败封禁阈值", type: "number" }
    ]
  },
  "settings-waf-detection": {
    title: "WAF 核心检测",
    section: "waf_detection_settings",
    fields: [
      { name: "enabled", label: "启用语义检测", type: "select", options: [["true", "启用"], ["false", "停用"]] },
      { name: "mode", label: "处置模式", type: "select", options: [["observe", "观察：仅记录"], ["block", "拦截：达到阈值拒绝"]] },
      { name: "block_threshold", label: "阻断风险阈值", type: "number" },
      { name: "max_inspect_bytes", label: "最大检测字节数", type: "number" },
      { name: "max_field_count", label: "最大检测字段数", type: "number" },
      { name: "max_field_bytes", label: "单字段最大字节数", type: "number" },
      { name: "excluded_paths", label: "跳过检测的路径，每行一个", type: "textarea", optional: true, rows: 3 }
    ]
  },
  "settings-retention-export": {
    title: "留存与导出",
    section: "retention_export",
    fields: [
      { name: "export_download_ttl_seconds", label: "导出下载链接有效秒数", type: "number" },
      { name: "export_file_retention_days", label: "导出文件保留天数", type: "number" },
      { name: "backup_retention_days", label: "备份文件保留天数", type: "number" },
      { name: "cleanup_batch_size", label: "清理批次大小", type: "number" }
    ]
  },
  "settings-alert-thresholds": {
    title: "告警设置",
    section: "alert_thresholds",
    fields: [
      { name: "waf_spike_count", label: "WAF 突增告警次数", type: "number" },
      { name: "waf_spike_window_minutes", label: "WAF 突增统计窗口分钟", type: "number" },
      { name: "provider_error_rate", label: "厂商错误率阈值" },
      { name: "provider_error_window_minutes", label: "厂商错误统计窗口分钟", type: "number" },
      { name: "provider_min_requests", label: "厂商错误最小请求数", type: "number" },
      { name: "queue_backlog_count", label: "队列积压告警阈值", type: "number" },
      { name: "certificate_threshold_days", label: "证书到期告警天数", type: "number" }
    ]
  },
  "settings-rag-upload-review": {
    title: "RAG 上传审核",
    section: "rag_upload_review",
    fields: [
      { name: "enabled", label: "启用上传审核", type: "select", options: [["false", "停用"], ["true", "启用"]] },
      { name: "reviewer_roles", label: "审核角色，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "allow_reviewer_own_upload", label: "允许审核自己的上传", type: "select", options: [["false", "否"], ["true", "是"]] },
      { name: "reject_blocks_vectorization", label: "驳回后阻断向量化", type: "select", options: [["true", "是"], ["false", "否"]] },
      { name: "instructions", label: "审核说明", type: "textarea", optional: true, rows: 3 }
    ]
  },
  "settings-rag-package-upload": {
    title: "RAG 图片包上传",
    section: "rag_package_upload",
    fields: [
      { name: "max_package_mb", label: "最大 ZIP 包大小 MB", type: "number" },
      { name: "allowed_image_extensions", label: "允许图片扩展名，每行一个", type: "textarea", optional: true, rows: 3 }
    ]
  },
  "settings-rag-ai-image-processing": {
    title: "RAG 图片 AI 处理",
    section: "rag_ai_image_processing",
    fields: [
      { name: "enabled", label: "启用 AI 图片标注", type: "select", options: [["false", "停用"], ["true", "启用"]] },
      { name: "vision_model_id", label: "视觉模型 ID", type: "number" },
      { name: "prompt_profile", label: "提示词配置", type: "select", options: [["default", "默认"], ["ocr_first", "OCR 优先"], ["catalog", "目录"], ["technical", "技术文档"]] },
      { name: "max_retries", label: "最大重试次数", type: "number" },
      { name: "timeout_ms", label: "超时时间 ms", type: "number" }
    ]
  },
  "settings-rag-vectorization-monitoring": {
    title: "RAG 向量化监控",
    section: "rag_vectorization_monitoring",
    fields: [
      { name: "success_rate_threshold", label: "成功率阈值", type: "number" },
      { name: "window_hours", label: "指标窗口小时", type: "number" },
      { name: "consecutive_failure_threshold", label: "连续失败阈值", type: "number" },
      { name: "qdrant_health_check_enabled", label: "检查 Qdrant 健康状态", type: "select", options: [["true", "启用"], ["false", "停用"]] }
    ]
  }
};

const SETTINGS_SECTION_PAGE_ORDER = [
  "settings-brand-identity",
  "settings-tls-certificates",
  "settings-entry-security",
  "settings-firewall",
  "settings-waf-detection",
  "settings-retention-export",
  "settings-alert-thresholds",
  "settings-rag-upload-review",
  "settings-rag-package-upload",
  "settings-rag-ai-image-processing",
  "settings-rag-vectorization-monitoring"
];

const SETTINGS_SECTION_CATEGORIES = [
  { key: "base-entry", label: "基础入口", sections: ["settings-brand-identity", "settings-tls-certificates"] },
  { key: "security", label: "安全防护", sections: ["settings-entry-security", "settings-firewall", "settings-waf-detection"] },
  { key: "governance", label: "治理告警", sections: ["settings-retention-export", "settings-alert-thresholds"] },
  { key: "rag", label: "RAG 设置", sections: ["settings-rag-upload-review", "settings-rag-package-upload", "settings-rag-ai-image-processing", "settings-rag-vectorization-monitoring"] }
];

const SYSTEM_SETTING_PRESENTATIONS = {
  rag_ai_image_processing: {
    title: "RAG 图片 AI 处理",
    description: "控制知识库图片的 AI 标注、视觉模型、提示词策略和失败重试。"
  },
  tls_certificates: {
    title: "证书与入口",
    description: "管理域名或内网 IP 证书、自动续期和到期告警。"
  },
  brand_identity: {
    title: "标题与标识",
    description: "设置管理后台和运营大屏的名称、Logo 与浏览器图标。"
  },
  "content_retention.policy": {
    title: "内容留存策略",
    description: "设置会话内容的保存方式、保存期限和查看权限。"
  }
};

const SYSTEM_SETTING_FIELD_LABELS = {
  ...Object.fromEntries(Object.values(SETTINGS_SECTION_PAGES).map((page) => [
    page.section,
    Object.fromEntries(page.fields.map((config) => [config.name, config.label]))
  ])),
  "content_retention.policy": {
    mode: "保存模式",
    days: "保存天数",
    demo_marker: "演示标记",
    default_days: "默认保存天数",
    max_days: "最大保存天数",
    raw_allowed_roles: "可查看原文的角色",
    departments: "部门策略",
    users: "用户策略",
    api_keys: "API 密钥策略",
    demo_marker: "演示标记"
  }
};

function isDemoSystemSetting(row) {
  const key = String(row?.setting_key || "").trim();
  return key.startsWith("demo-content-retention.");
}

function systemSettingPresentation(row) {
  const key = String(row?.setting_key || "").trim();
  const presentation = SYSTEM_SETTING_PRESENTATIONS[key];
  return {
    key,
    title: presentation?.title || key || "未命名设置",
    description: presentation?.description || row?.description || "用于保存应用扩展配置。"
  };
}

function systemSettingSummaryLabel(row, path) {
  return systemSettingFieldLabel(row, { path });
}

function systemSettingFieldLabel(row, descriptor) {
  const key = String(row?.setting_key || "").trim();
  const path = descriptor.path || [];
  const labels = SYSTEM_SETTING_FIELD_LABELS[key] || {};
  const readablePath = path.filter((segment) => !/^\d+$/.test(String(segment)));
  return readablePath.map((segment) => labels[segment] || segment).join(" / ") || "配置值";
}

const providerBaseUrlPresets = {
  "qianqianai": "https://api.qianqianai.com/v1",
  "deepseek": "https://api.deepseek.com",
  "openai": "https://api.openai.com",
  "anthropic": "https://api.anthropic.com",
  "gemini": "https://generativelanguage.googleapis.com/v1beta/openai",
  "moonshot": "https://api.moonshot.cn/v1",
  "kimi_code": "https://api.kimi.com/coding/v1",
  "qwen": "https://dashscope.aliyuncs.com/compatible-mode/v1",
  "siliconflow": "https://api.siliconflow.cn/v1",
  "zhipu": "https://open.bigmodel.cn/api/paas/v4",
  "doubao": "https://ark.cn-beijing.volces.com/api/v3",
  "volcengine": "https://ark.cn-beijing.volces.com/api/v3",
  "tencent": "https://api.hunyuan.cloud.tencent.com/v1",
  "openrouter": "https://openrouter.ai/api/v1",
  "groq": "https://api.groq.com/openai/v1",
  "mistral": "https://api.mistral.ai/v1",
  "ollama": "http://localhost:11434/v1"
};

const providerAnthropicBaseUrlPresets = {
  "anthropic": "https://api.anthropic.com",
  "deepseek": "https://api.deepseek.com/anthropic"
};

const providerNameAliases = [
  ["qianqianai", "qianqianai"],
  ["qianqian", "qianqianai"],
  ["\u9ed4\u524d", "qianqianai"],
  ["\u9ed4\u524d\u667a\u7b97", "qianqianai"],
  ["deepseek", "deepseek"],
  ["deep seek", "deepseek"],
  ["\u517c\u5bb9 openai", "openai"],
  ["\u517c\u5bb9openai", "openai"],
  ["openai", "openai"],
  ["chatgpt", "openai"],
  ["anthropic", "anthropic"],
  ["claude", "anthropic"],
  ["gemini", "gemini"],
  ["google", "gemini"],
  ["moonshot", "moonshot"],
  ["kimi code", "kimi_code"],
  ["kimi-code", "kimi_code"],
  ["kimi for coding", "kimi_code"],
  ["kimi-for-coding", "kimi_code"],
  ["kimi", "moonshot"],
  ["qwen", "qwen"],
  ["通义", "qwen"],
  ["dashscope", "qwen"],
  ["siliconflow", "siliconflow"],
  ["硅基流动", "siliconflow"],
  ["zhipu", "zhipu"],
  ["智谱", "zhipu"],
  ["bigmodel", "zhipu"],
  ["volcengine", "volcengine"],
  ["volces", "volcengine"],
  ["\u706b\u5c71\u5f15\u64ce", "volcengine"],
  ["doubao", "doubao"],
  ["璞嗗寘", "doubao"],
  ["火山", "doubao"],
  ["\u817e\u8baf\u4e91", "tencent"],
  ["\u817e\u8baf", "tencent"],
  ["tencent", "tencent"],
  ["hunyuan", "tencent"],
  ["\u6df7\u5143", "tencent"],
  ["openrouter", "openrouter"],
  ["groq", "groq"],
  ["mistral", "mistral"],
  ["ollama", "ollama"]
];

const providerDisplayNamePresets = [
  { name: "\u9ed4\u524d\u667a\u7b97", protocol: "openai", baseUrl: providerBaseUrlPresets.qianqianai },
  { name: "DeepSeek", protocol: "openai", baseUrl: providerBaseUrlPresets.deepseek },
  { name: "\u706b\u5c71\u5f15\u64ce", protocol: "openai", baseUrl: providerBaseUrlPresets.volcengine },
  { name: "\u817e\u8baf\u4e91", protocol: "openai", baseUrl: providerBaseUrlPresets.tencent },
  { name: "Kimi API / Moonshot", protocol: "openai", baseUrl: providerBaseUrlPresets.moonshot },
  { name: "Kimi Code", protocol: "openai", baseUrl: providerBaseUrlPresets.kimi_code },
  { name: "\u517c\u5bb9 OpenAI", protocol: "openai", baseUrl: providerBaseUrlPresets.openai }
];

const lookupResourcePaths = {
  "api-keys": "/admin-api/api-keys",
  users: "/admin-api/users",
  models: "/admin-api/models"
};

const RAG_CATEGORY_TABS = {
  "rag-build": [
    ["rag-dashboard", "构建总览"],
    ["rag-knowledge-bases", "知识库"],
    ["rag-vector-datasets", "数据集"],
    ["rag-import-task", "导入任务"],
    ["rag-uploads", "任务记录"]
  ],
  "rag-quality": [
    ["rag-evaluation", "检索评估"],
    ["rag-eval-cases", "评估样本"],
    ["rag-answer-feedback", "用户反馈"],
    ["rag-correction-tasks", "纠偏任务"]
  ],
  "rag-operations": [
    ["rag-vectorization-metrics", "向量化健康"],
    ["rag-embedding-jobs", "向量任务"],
    ["rag-retrieval-logs", "检索记录"],
    ["rag-retention-policies", "数据留存"]
  ],
  "rag-console": [
    ["rag-dashboard", "总览"],
    ["rag-retrieval-lab", "检索试验台"],
    ["rag-vectorization-metrics", "向量化监控"],
    ["rag-retrieval-evaluation", "检索评测"]
  ],
  "rag-knowledge-management": [
    ["rag-knowledge-bases", "知识库"],
    ["rag-vector-datasets", "向量数据集"],
    ["rag-dataset-bindings", "数据集授权"],
    ["rag-vector-configs", "向量配置"]
  ],
  "rag-import-review": [
    ["rag-template-center", "模板下载"],
    ["rag-upload-workbench", "上传导入"],
    ["rag-upload-detail", "上传详情"],
    ["rag-uploads", "上传记录"],
    ["rag-image-preview", "图片预览"]
  ],
  "rag-image-vector": [
    ["rag-image-assets", "图片资产"],
    ["rag-embedding-jobs", "向量任务"],
    ["rag-vector-records", "向量记录"]
  ],
  "rag-feedback-correction": [
    ["rag-evaluation", "检索评估"],
    ["rag-retrieval-logs", "检索日志"],
    ["rag-answer-feedback", "用户反馈"],
    ["rag-correction-tasks", "纠偏任务"],
    ["rag-retention-policies", "过期策略"]
  ],
  "rag-advanced-data": [
    ["rag-documents", "文档"],
    ["rag-chunks", "Chunk"],
    ["rag-upload-preview-items", "上传预览原始数据"],
    ["rag-import-templates", "导入模板原始表"],
    ["rag-eval-cases", "评估样本"],
    ["rag-eval-runs", "评估运行"],
    ["rag-eval-results", "评估结果"]
  ]
};
export async function renderResourcePage(content, resourceKey, initialFilters = {}) {
  if (resourceKey === "waf-overview") {
    await renderWafOverviewPage(content);
    return;
  }
  if (resourceKey === "waf-ip-bans") {
    await renderWafIpBansPage(content);
    return;
  }
  if (resourceKey === "waf-rule-versions") {
    await renderWafVersionsPage(content);
    return;
  }
  if (resourceKey === "waf-alerts") {
    await renderWafAlertsPage(content);
    return;
  }
  if (resourceKey === "alert-events") {
    await renderAlertEventsPage(content);
    return;
  }
  if (resourceKey === "operations-status") {
    await renderOperationsStatusPage(content);
    return;
  }
  if (resourceKey === "rag-settings") {
    await renderSettingsCenterPage(content);
    return;
  }
  if (resourceKey === "rag-retrieval-lab") {
    await renderRagRetrievalLabPage(content);
    return;
  }
  if (resourceKey === "content-archive") {
    await renderContentArchivePage(content, initialFilters);
    return;
  }
  if (resourceKey === "content-retention-policy") {
    await renderContentRetentionPolicyPage(content);
    return;
  }
  if (resourceKey === "settings-center") {
    await renderSettingsCenterPage(content);
    return;
  }
  if (resourceKey === "permission-config") {
    await renderPermissionConfigPage(content);
    return;
  }
  if (resourceKey === "system-settings") {
    await renderSystemSettingsPage(content);
    return;
  }
  if (resources[resourceKey]?.settingsSection) {
    await renderSettingsSectionPage(content, resourceKey);
    return;
  }
  if (resourceKey === "waf-rules") {
    await renderWafRulesPage(content, initialFilters);
    return;
  }
  if (resourceKey === "waf-events") {
    await renderWafEventsPage(content, initialFilters);
    return;
  }
  if (resourceKey === "api-keys") {
    await renderApiKeyPage(content, initialFilters);
    return;
  }
  if (resourceKey === "admin-chat-sessions") {
    await renderAdminChatSessionPage(content);
    return;
  }
  if (RAG_CATEGORY_TABS[resourceKey]) {
    await renderRagCategoryPage(content, resourceKey, initialFilters);
    return;
  }
  if (resourceKey === "rag-dashboard") {
    await renderRagDashboardPage(content);
    return;
  }
  if (resourceKey === "rag-template-center") {
    await renderRagTemplateCenterPage(content);
    return;
  }
  if (resourceKey === "rag-upload-workbench") {
    await renderRagUploadWorkbenchPage(content);
    return;
  }
  if (resourceKey === "rag-upload-detail") {
    await renderRagUploadDetailPage(content, initialFilters);
    return;
  }
  if (resourceKey === "rag-image-preview") {
    await renderRagImagePreviewPage(content, initialFilters);
    return;
  }
  if (resourceKey === "rag-vectorization-metrics") {
    await renderRagVectorizationMetricsPage(content);
    return;
  }
  if (resourceKey === "rag-retrieval-evaluation") {
    await renderRagEvaluationPage(content);
    return;
  }
  if (resourceKey === "rag-evaluation") {
    await renderRagEvaluationPage(content);
    return;
  }
  await renderGenericResourcePage(content, resourceKey, initialFilters);
}

const apiKeyPageColumns = [
  ["id", "API ID"],
  ["name", "名称"],
  ["department_name", "部门"],
  ["user_name", "员工"],
  ["created_at", "创建时间"],
  ["key", "Key"],
  ["status", "状态码"]
];

function apiKeyActionIcon(paths) {
  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("width", "18");
  svg.setAttribute("height", "18");
  svg.setAttribute("fill", "none");
  svg.setAttribute("stroke", "currentColor");
  svg.setAttribute("stroke-width", "2");
  svg.setAttribute("stroke-linecap", "round");
  svg.setAttribute("stroke-linejoin", "round");
  svg.setAttribute("aria-hidden", "true");
  for (const d of paths) {
    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", d);
    svg.append(path);
  }
  return svg;
}

async function renderApiKeyPage(content, initialFilters = {}) {
  clear(content);
  const config = resources["api-keys"];
  const state = {
    filters: { sort: "created_at_desc", ...initialFilters },
    pageSize: 20,
    offset: 0
  };
  const departments = await lookupRows("departments");
  const page = el("section", { className: "api-key-page" });
  const tableHost = el("section", { className: "api-key-table-panel" }, [
    statusMessage("正在加载 API 密钥...")
  ]);
  const keywordInput = el("input", {
    type: "search",
    placeholder: "搜索名称、Key 或员工",
    value: state.filters.keyword || ""
  });
  const departmentSelect = el("select", {}, [
    el("option", { value: "", text: "全部部门" }),
    ...departments.map((department) => el("option", { value: department.id, text: department.name || `部门 #${department.id}` }))
  ]);
  departmentSelect.value = state.filters.department_id || "";
  const filterToolbar = el("form", { className: "filters api-key-filter-toolbar" }, [
    el("label", { className: "field" }, [el("span", { text: "搜索" }), keywordInput]),
    el("label", { className: "field" }, [el("span", { text: "部门" }), departmentSelect])
  ]);

  const refresh = async () => {
    const result = await api("/admin-api/api-keys", {
      query: { ...state.filters, limit: state.pageSize, offset: state.offset }
    }).catch((error) => ({
      data: [{ message: adminErrorMessage(error, "API 密钥列表加载失败"), request_id: error.requestId }],
      requestId: error.requestId
    }));
    document.getElementById("request-id").textContent = result.requestId || "";
    const pageData = Array.isArray(result.data)
      ? { items: result.data, total: result.data.length, limit: state.pageSize, offset: state.offset }
      : (result.data || {});
    const rows = Array.isArray(pageData.items) ? pageData.items : [];
    const total = Math.max(0, Number(pageData.total) || 0);
    const pageSize = Math.max(1, Number(pageData.limit) || state.pageSize);
    const offset = Math.max(0, Number(pageData.offset) || 0);
    const lastOffset = Math.max(0, (Math.ceil(total / pageSize) - 1) * pageSize);
    if (rows.length === 0 && total > 0 && offset > lastOffset && state.offset !== lastOffset) {
      state.offset = lastOffset;
      await refresh();
      return;
    }
    const context = { departmentsById: rowsById(departments) };
    const createButton = button("+ 新建 API 密钥", () => showCreateModal("api-keys", config, refresh), "api-key-create-button");
    createButton.title = "新建 API 密钥";

    page.replaceChildren(
      el("header", { className: "api-key-page-header" }, [
        el("h2", { text: "API 密钥" }),
        createButton
      ]),
      el("div", { className: "api-key-safety-notice", text: "API 密钥只会在新建后显示一次，请妥善保存。不要与他人共享 API 密钥，或将其暴露在客户端代码中。为了保护你的账户安全，一旦 API 密钥被检测到泄露风险，我们可能会将其禁用。" }),
      filterToolbar,
      renderApiKeyAnalytics(rows, total),
      tableHost
    );
    tableHost.replaceChildren(
      rows.length === 0
        ? statusMessage("暂无 API 密钥")
        : renderApiKeyTable(rows, config, refresh, context),
      renderApiKeyPager(total, pageSize, offset, async (nextOffset) => {
        state.offset = nextOffset;
        await refresh();
      })
    );
  };

  const applyFilters = async () => {
    const keyword = keywordInput.value.trim();
    const departmentId = departmentSelect.value;
    if (keyword) {
      state.filters.keyword = keyword;
    } else {
      delete state.filters.keyword;
    }
    if (departmentId) {
      state.filters.department_id = departmentId;
    } else {
      delete state.filters.department_id;
    }
    state.offset = 0;
    await refresh();
  };
  filterToolbar.append(button("搜索", applyFilters));
  filterToolbar.addEventListener("submit", (event) => {
    event.preventDefault();
    void applyFilters();
  });
  keywordInput.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      void applyFilters();
    }
  });

  content.append(page);
  await refresh();
}

function renderApiKeyPager(total, pageSize, offset, onPageChange) {
  const totalPages = Math.max(1, Math.ceil(total / pageSize));
  const currentPage = Math.min(totalPages, Math.floor(offset / pageSize) + 1);
  const previous = button("上一页", () => onPageChange(Math.max(0, offset - pageSize)), "secondary");
  const next = button("下一页", () => onPageChange(offset + pageSize), "secondary");
  previous.disabled = offset <= 0;
  next.disabled = offset + pageSize >= total;

  return el("nav", { className: "api-key-pager", ariaLabel: "API 密钥分页" }, [
    el("span", { className: "api-key-pager-summary", text: `共 ${total} 条，第 ${currentPage} / ${totalPages} 页` }),
    el("div", { className: "api-key-pager-actions" }, [previous, next])
  ]);
}

function renderApiKeyAnalytics(rows, total = rows.length) {
  const activeCount = rows.filter((row) => row.status === "active").length;
  const disabledCount = rows.filter((row) => row.status === "disabled" || row.status === "deleted").length;
  const requestCount = sumApiKeyMetric(rows, "request_count");
  const totalTokens = sumApiKeyMetric(rows, "total_tokens");
  const cacheTokens = sumApiKeyMetric(rows, "cache_tokens");
  const totalCost = rows.reduce((sum, row) => sum + apiKeyNumber(row.total_cost), 0);
  const cards = [
    ["API 密钥总数", formatApiKeyNumber(total)],
    ["生效中", formatApiKeyNumber(activeCount)],
    ["停用/删除", formatApiKeyNumber(disabledCount)],
    ["请求数", formatApiKeyNumber(requestCount)],
    ["Token", formatApiKeyNumber(totalTokens)],
    ["缓存 Token", formatApiKeyNumber(cacheTokens)],
    ["成本", formatApiKeyCost(totalCost)]
  ];
  return el("section", { className: "api-key-analysis-grid" }, cards.map(([label, value]) => el("article", { className: "api-key-analysis-card" }, [
    el("span", { text: label }),
    el("strong", { text: value })
  ])));
}

function apiKeyNumber(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number : 0;
}

function sumApiKeyMetric(rows, key) {
  return rows.reduce((sum, row) => sum + apiKeyNumber(row[key]), 0);
}

function formatApiKeyNumber(value) {
  return apiKeyNumber(value).toLocaleString("zh-CN");
}

function formatApiKeyCost(value) {
  const number = apiKeyNumber(value);
  return number > 0 ? number.toFixed(4) : "0";
}

function renderApiKeyTable(rows, config, onRefresh, context = {}) {
  const selectedApiKeyIds = new Set();
  let apiKeySelectAll = null;
  let apiKeySelectionStatus = null;
  const apiKeyBulkButtons = [];
  const selectableRows = rows.filter((row) => row.id);
  const updateApiKeySelection = () => {
    const selectedCount = selectedApiKeyIds.size;
    if (apiKeySelectionStatus) {
      setText(apiKeySelectionStatus, selectedCount > 0 ? `已选择 ${selectedCount} 个密钥` : "未选择密钥");
    }
    for (const actionButton of apiKeyBulkButtons) {
      actionButton.disabled = selectedCount === 0;
    }
    if (apiKeySelectAll) {
      apiKeySelectAll.checked = selectableRows.length > 0 && selectedCount === selectableRows.length;
      apiKeySelectAll.indeterminate = selectedCount > 0 && selectedCount < selectableRows.length;
    }
  };
  const table = el("table", { className: "resource-table resource-table--api-keys" });
  apiKeySelectAll = el("input", {
    type: "checkbox",
    className: "api-key-select-all",
    title: "选择当前页 API 密钥",
    onChange: (event) => {
      const checked = event.currentTarget.checked;
      selectedApiKeyIds.clear();
      for (const row of selectableRows) {
        if (checked) {
          selectedApiKeyIds.add(Number(row.id));
        }
      }
      for (const checkbox of table.querySelectorAll(".api-key-row-select")) {
        checkbox.checked = checked;
      }
      updateApiKeySelection();
    }
  });
  table.append(el("thead", {}, [
    el("tr", {}, [
      el("th", { className: "table-cell table-cell--select" }, [apiKeySelectAll]),
      ...apiKeyPageColumns.map(([column, label]) => el("th", { className: `table-cell table-cell--${column}`, text: label })),
      el("th", { className: "table-cell table-cell--actions", text: "操作" })
    ])
  ]));
  table.append(el("tbody", {}, rows.map((row) => {
    const checkbox = el("input", {
      type: "checkbox",
      className: "api-key-row-select",
      value: row.id,
      title: `选择 API 密钥 ${row.name || row.id}`,
      onChange: (event) => {
        const id = Number(event.currentTarget.value);
        if (event.currentTarget.checked) {
          selectedApiKeyIds.add(id);
        } else {
          selectedApiKeyIds.delete(id);
        }
        updateApiKeySelection();
      }
    });
    const actions = el("td", { className: "table-cell table-cell--actions row-actions api-key-row-actions" });
    if (config.form && row.id) {
      const editButton = button("", () => showEditModal("api-keys", config, row, onRefresh), "secondary api-key-icon-button");
      editButton.title = "编辑";
      editButton.ariaLabel = "编辑 API 密钥";
      editButton.append(apiKeyActionIcon([
        "M12 20h9",
        "M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"
      ]));
      actions.append(editButton);
      const ragButton = button("知识库", () => showApiKeyRagBindings(row, onRefresh), "secondary");
      ragButton.title = "配置此密钥可调用的知识库和数据集";
      actions.append(ragButton);
    }
    if (row.id && row.status !== "disabled" && row.status !== "deleted") {
      const deleteButton = button("", async () => {
        if (!window.confirm(`确认删除 API 密钥 ${row.name || row.id}？`)) {
          return;
        }
        try {
          await api(`/admin-api/api-keys/${row.id}`, { method: "DELETE" });
          await onRefresh();
        } catch (error) {
          showModal("删除 API 密钥", statusMessage(adminErrorMessage(error, "删除 API 密钥失败"), "error"));
        }
      }, "secondary api-key-icon-button api-key-icon-button--danger");
      deleteButton.title = "删除";
      deleteButton.ariaLabel = "删除 API 密钥";
      deleteButton.append(apiKeyActionIcon([
        "M3 6h18",
        "M8 6V4h8v2",
        "M19 6l-1 14H6L5 6",
        "M10 11v5M14 11v5"
      ]));
      actions.append(deleteButton);
    }
    return el("tr", {}, [
      el("td", { className: "table-cell table-cell--select" }, [checkbox]),
      ...apiKeyPageColumns.map(([column]) => {
        const cellText = apiKeyCellText(column, row, context);
        return el("td", {
          className: `table-cell table-cell--${column}`,
          text: cellText,
          title: cellText
        });
      }),
      actions
    ]);
  })));
  const bulkActions = el("div", { className: "api-key-bulk-actions" });
  apiKeySelectionStatus = el("span", { className: "api-key-bulk-selection", text: "未选择密钥" });
  const enableButton = button("批量启用", () => runApiKeyBatchAction("enable", selectedApiKeyIds, onRefresh), "secondary");
  const disableButton = button("批量暂停", () => runApiKeyBatchAction("disable", selectedApiKeyIds, onRefresh), "secondary");
  const deleteButton = button("批量删除", () => runApiKeyBatchAction("delete", selectedApiKeyIds, onRefresh), "danger");
  apiKeyBulkButtons.push(enableButton, disableButton, deleteButton);
  bulkActions.append(apiKeySelectionStatus, enableButton, disableButton, deleteButton);
  updateApiKeySelection();
  return el("div", { className: "api-key-table-wrap api-key-table-panel" }, [bulkActions, table]);
}

async function runApiKeyBatchAction(action, selectedIds, onRefresh) {
  const ids = Array.from(selectedIds);
  if (ids.length === 0) {
    showModal("批量操作 API 密钥", statusMessage("请先选择 API 密钥", "error"));
    return;
  }
  const labels = {
    enable: "批量启用",
    disable: "批量暂停",
    delete: "批量删除"
  };
  const label = labels[action] || "批量操作";
  if (action === "delete" && !window.confirm(`确认删除选中的 ${ids.length} 个 API 密钥？删除后密钥将不能继续调用。`)) {
    return;
  }
  try {
    const result = await api("/admin-api/api-keys/batch", {
      method: "POST",
      body: JSON.stringify({ action, ids })
    });
    showOperationResult(label, result.data);
    await onRefresh();
  } catch (error) {
    showModal(label, statusMessage(adminErrorMessage(error, `${label}失败，请确认权限或状态`), "error"));
  }
}

function apiKeyCellText(column, row, context = {}) {
  if (column === "key") {
    return apiKeyDisplay(row);
  }
  if (column === "status") {
    return apiKeyStatusText(row.status);
  }
  if (column === "user_name") {
    return row.user_name || apiKeyUserLabel(row.user_id, context);
  }
  if (column === "department_name") {
    return row.department_name || apiKeyDepartmentLabel(row.department_id, context);
  }
  if (column === "user_id") {
    return apiKeyUserLabel(row[column], context);
  }
  if (column === "department_id") {
    return apiKeyDepartmentLabel(row[column], context);
  }
  return formatResourceValue(column, row[column]);
}

function apiKeyDisplay(row = {}) {
  if (row.display) {
    return String(row.display).replace("****", "...");
  }
  if (row.key_prefix && row.key_suffix) {
    return `${row.key_prefix}...${row.key_suffix}`;
  }
  if (row.key_suffix) {
    return `sk-...${row.key_suffix}`;
  }
  return "";
}

function apiKeyStatusText(status) {
  const labels = {
    active: "生效中",
    disabled: "已停用",
    deleted: "已删除",
    expired: "已过期"
  };
  return labels[status] || formatResourceValue("status", status);
}

async function renderAdminChatSessionPage(content) {
  clear(content);
  const state = { messages: [], stickToBottom: true };
  const modelResult = await api("/admin-api/chat-session/models").catch((error) => ({ error, data: [] }));
  const models = Array.isArray(modelResult.data) ? modelResult.data : [];
  const modelOptions = models.map((row) => {
    const suffix = row.provider_name ? ` / ${row.provider_name}` : "";
    return [row.name, `${row.name}${suffix}`];
  });

  const fields = [
    { name: "model", label: "模型", type: "select", options: modelOptions },
    { name: "prompt", label: "消息", type: "textarea", rows: 3 }
  ];
  const form = el("form", { className: "admin-chat-composer", onSubmit: (event) => event.preventDefault() });
  const output = el("p", { className: "notice", hidden: true });
  const modelField = field(fields[0], "");
  modelField.classList.add("admin-chat-model-field");
  const promptField = field(fields[1], "");

  const transcript = el("section", { className: "admin-chat-transcript" });
  const scrollToBottom = () => {
    if (state.stickToBottom) {
      transcript.scrollTop = transcript.scrollHeight;
    }
  };
  const renderTranscript = () => {
    clear(transcript);
    if (state.messages.length === 0) {
      transcript.append(el("div", { className: "admin-chat-empty", text: "暂无会话内容" }));
      return;
    }
    for (const message of state.messages) {
      transcript.append(el("article", { className: `admin-chat-message ${message.role}` }, [
        el("div", { className: "admin-chat-role", text: message.role === "user" ? "用户" : "助手" }),
        el("div", { className: "admin-chat-bubble", text: message.content })
      ]));
    }
    scrollToBottom();
  };
  transcript.addEventListener("scroll", () => {
    const distance = transcript.scrollHeight - transcript.scrollTop - transcript.clientHeight;
    state.stickToBottom = distance < 32;
  });

  const send = button("发送", async () => {
    const model = form.querySelector('[name="model"]')?.value || "";
    const prompt = form.querySelector('[name="prompt"]')?.value.trim() || "";
    if (!model || !prompt) {
      output.hidden = false;
      output.className = "notice error";
      setText(output, "请选择模型并填写消息。");
      return;
    }

    const messages = [...state.messages, { role: "user", content: prompt }];

    send.disabled = true;
    state.stickToBottom = true;
    output.hidden = false;
    output.className = "notice";
    setText(output, "发送中...");
    state.messages.push({ role: "user", content: prompt });
    const assistant = { role: "assistant", content: "" };
    state.messages.push(assistant);
    form.querySelector('[name="prompt"]').value = "";
    renderTranscript();
    let reasoningSeen = false;
    try {
      await streamApi("/admin-api/chat-session/stream", {
        method: "POST",
        body: JSON.stringify({
          model,
          messages
        })
      }, (data) => {
        if (data === "[DONE]") {
          return;
        }
        const chunk = JSON.parse(data);
        if (chunk.error) {
          const error = new Error(chunk.error.message || "stream error");
          error.code = chunk.error.code || "REQUEST_FAILED";
          throw error;
        }
        const choice = chunk.choices?.[0] || {};
        const messageDelta = choice.delta || choice.message || {};
        if (messageDelta.reasoning_content) {
          reasoningSeen = true;
        }
        const delta = messageDelta.content || "";
        if (delta) {
          assistant.content += delta;
          renderTranscript();
        }
      });
      if (!assistant.content) {
        assistant.content = reasoningSeen
          ? "（模型只返回了推理过程，未返回最终答案。请重试或切换模型。）"
          : "（模型未返回文本内容）";
      }
      state.messages = state.messages.slice(-20);
      output.className = "notice";
      setText(output, `完成：${model}`);
      renderTranscript();
    } catch (error) {
      if (!assistant.content) {
        assistant.content = adminErrorMessage(error, "会话请求失败");
      }
      output.className = "notice error";
      setText(output, adminErrorMessage(error, "会话请求失败"));
      renderTranscript();
    } finally {
      send.disabled = false;
    }
  });

  const reset = button("清空", () => {
    state.messages = [];
    output.hidden = true;
    renderTranscript();
  }, "secondary");
  form.append(
    el("section", { className: "admin-chat-input-shell" }, [
      promptField,
      el("div", { className: "admin-chat-input-footer" }, [
        modelField,
        el("div", { className: "toolbar-actions" }, [send, reset])
      ])
    ]),
    output
  );
  if (modelOptions.length === 0) {
    send.disabled = true;
    form.prepend(statusMessage(modelResult.error ? adminErrorMessage(modelResult.error, "模型加载失败") : "暂无可用文本模型", modelResult.error ? "error" : ""));
  }

  content.append(el("section", { className: "panel admin-chat-shell" }, [
    el("header", { className: "admin-chat-header" }, [el("h2", { text: "会话测试" })]),
    transcript,
    form
  ]));
  renderTranscript();
}

async function renderGenericResourcePage(content, resourceKey, initialFilters = {}) {
  const config = resources[resourceKey];
  clear(content);
  if (!config) {
    content.append(statusMessage("资源不存在", "error"));
    return;
  }
  if (config.tree) {
    await renderDepartmentTree(content, config);
    return;
  }
  const { openCreate = false, ...filterValues } = initialFilters;
  const state = { filters: { limit: 50, ...filterValues } };
  const tablePanel = el("section", { className: "panel table-panel" });
  const refreshRows = async () => renderRows(tablePanel, resourceKey, config, state);
  const toolbar = renderFilters(resourceKey, state, refreshRows);
  const actions = el("div", { className: "toolbar-actions" });
  if (config.form) {
    actions.append(button("新增", () => showCreateModal(resourceKey, config, refreshRows)));
  }
  if (resourceKey === "models") {
    actions.append(button("拉取模型", () => showModelProviderImport(async () => renderRows(tablePanel, resourceKey, config, state)), "secondary"));
  }
  if (config.exportType) {
    actions.append(button("导出", async () => {
      const result = await api(`/admin-api/exports/${config.exportType}`, {
        method: "POST",
        body: JSON.stringify(state.filters)
      });
      showOperationResult("\u5bfc\u51fa\u4efb\u52a1", result.data, "\u5bfc\u51fa\u4efb\u52a1\u5df2\u521b\u5efa\uff0c\u53ef\u5728\u5bfc\u51fa\u4efb\u52a1\u5217\u8868\u67e5\u770b");
    }, "secondary"));
  }
  for (const action of config.actions || []) {
    if (!action.bodyField) {
      actions.append(button(action.label, async () => {
        const result = await api(action.path, { method: action.method || "POST" });
        showOperationResult(action.label, result.data);
        await renderRows(tablePanel, resourceKey, config, state);
      }, "secondary"));
    }
  }
  toolbar.append(actions);
  content.append(toolbar, tablePanel);
  await refreshRows();
  if (openCreate && config.form) {
    await showCreateModal(resourceKey, config, refreshRows);
  }
}

async function renderWafOverviewPage(content) {
  clear(content);
  const cockpit = el("section", { className: "waf-overview-cockpit stack" });
  content.append(cockpit);
  const load = async () => {
    clear(cockpit);
    try {
      const result = await api("/admin-api/waf/overview");
      document.getElementById("request-id").textContent = result.requestId || "";
      cockpit.append(renderWafOverviewCockpit(result.data || {}));
    } catch (error) {
      cockpit.append(statusMessage(adminErrorMessage(error, "WAF 总览加载失败"), "error"));
      cockpit.append(button("重新加载 WAF 总览", load, "secondary"));
    }
  };
  await load();
}

function renderWafOverviewCockpit(data) {
  const publication = data.publication || {};
  const summary = data.summary || {};
  const publicationCopy = wafPublicationCopy(publication.status);
  const topRules = Array.isArray(data.top_rules) ? data.top_rules.slice(0, 5) : [];
  const recentEvents = Array.isArray(data.recent_events) ? data.recent_events.slice(0, 5) : [];
  const unhandledBlocks = recentEvents.filter((event) => event.action === "block" && (!event.disposition_status || event.disposition_status === "open")).length;
  const maxRuleHits = Math.max(1, ...topRules.map((row) => Number(row.event_count) || 0));

  return el("div", { className: "waf-cockpit-content" }, [
    el("header", { className: `panel waf-cockpit-hero ${publicationCopy.tone}` }, [
      el("div", { className: "waf-cockpit-hero-copy" }, [
        el("span", { className: "waf-cockpit-kicker", text: "WAF 防护态势" }),
        el("h2", { text: publicationCopy.label }),
        el("p", { text: publicationCopy.description }),
        el("small", { text: publication.version_code ? `当前版本：${publication.version_code}` : "当前尚无已发布版本" })
      ]),
      el("div", { className: "waf-cockpit-actions" }, [
        button("查看规则", () => ragNavigate("waf-rules")),
        button("查看攻击记录", () => ragNavigate("waf-events"), "secondary"),
        button("管理 IP 封禁", () => ragNavigate("waf-ip-bans"), "secondary"),
        button("版本管理", () => ragNavigate("waf-rule-versions"), "secondary")
      ])
    ]),
    el("section", { className: "waf-cockpit-metrics" }, [
      wafMetricCard("启用规则", summary.active_rule_count || 0, "当前正在参与边缘防护匹配的规则"),
      wafMetricCard("活跃封禁 IP", summary.active_ban_count || 0, "已被系统拒绝访问的异常来源"),
      wafMetricCard("攻击事件", summary.total_events || 0, "统计窗口内被 WAF 识别的风险请求"),
      wafMetricCard("已拦截", summary.blocked_events || 0, "已在防护链路中阻断的风险请求")
    ]),
    el("section", { className: "waf-cockpit-capabilities panel" }, [
      el("div", { className: "waf-cockpit-section-heading" }, [
        el("div", {}, [el("span", { className: "waf-cockpit-kicker", text: "防护范围" }), el("h3", { text: "防护能力" })]),
        el("p", { text: "WAF 在请求进入业务服务前后提供规则拦截、来源控制和可追溯的安全处置链路。" })
      ]),
      el("div", { className: "waf-capability-grid" }, [
        wafCapability("规则匹配拦截", "识别命中规则的恶意或异常请求并按策略处理。"),
        wafCapability("敏感路径保护", "拦截高风险路径、方法和敏感查询参数访问。"),
        wafCapability("异常来源 IP 封禁", "对重复攻击或人工确认的来源执行系统级拒绝。"),
        wafCapability("攻击留痕与审计", "记录规则、来源、路径和处置状态，支持后续复核。")
      ])
    ]),
    (summary.active_ban_count || unhandledBlocks) ? el("section", { className: "notice warning waf-cockpit-follow-up" }, [
      el("strong", { text: "需要关注的安全处置" }),
      el("span", { text: `${summary.active_ban_count || 0} 个活跃封禁 IP，${unhandledBlocks} 条近期阻断事件尚未处置。` }),
      wafNavigateButton("进入攻击记录处理", "waf-events", "secondary")
    ]) : null,
    el("section", { className: "waf-cockpit-grid" }, [
      el("article", { className: "panel waf-cockpit-panel" }, [
        el("div", { className: "waf-cockpit-section-heading" }, [
          el("div", {}, [el("span", { className: "waf-cockpit-kicker", text: "攻击分析" }), el("h3", { text: "威胁热点" })]),
          el("small", { text: "按规则命中次数排序" })
        ]),
        topRules.length ? el("div", { className: "waf-threat-list" }, topRules.map((row) => el("div", { className: "waf-threat-item" }, [
          el("div", { className: "waf-threat-label" }, [
            el("strong", { text: row.rule_code || "未命名规则" }),
            el("span", { text: `${row.event_count || 0} 次命中` })
          ]),
          el("div", { className: "waf-threat-bar", ariaLabel: `${row.rule_code || "未命名规则"} 命中占比` }, [
            el("div", { className: "waf-threat-bar-fill", style: `width: ${Math.round(((Number(row.event_count) || 0) / maxRuleHits) * 100)}%` })
          ])
        ]))) : statusMessage("当前统计窗口内未发现攻击命中", "success")
      ]),
      el("article", { className: "panel waf-cockpit-panel waf-cockpit-events" }, [
        el("div", { className: "waf-cockpit-section-heading" }, [
          el("div", {}, [el("span", { className: "waf-cockpit-kicker", text: "实时事件" }), el("h3", { text: "近期攻击" })]),
          wafNavigateButton("查看全部攻击记录", "waf-events", "secondary")
        ]),
        recentEvents.length ? el("div", { className: "waf-recent-event-list" }, recentEvents.map(wafRecentEvent)) : statusMessage("暂无近期攻击记录，当前防护态势平稳。", "success")
      ])
    ])
  ]);
}

function wafPublicationCopy(status) {
  if (status === "published") return { label: "边缘防护已发布", description: "当前规则版本已同步至边缘防护层，风险请求可在入口处被拦截。", tone: "is-published" };
  if (status === "unpublished") return { label: "边缘防护待发布", description: "规则变更尚未同步至边缘防护层，请在规则页面完成确认发布。", tone: "is-pending" };
  return { label: "边缘防护状态异常", description: "未能确认边缘规则的发布状态，请检查版本与发布记录。", tone: "is-problem" };
}

function wafMetricCard(label, value, description) {
  return el("article", { className: "waf-cockpit-metric" }, [el("span", { text: label }), el("strong", { text: value }), el("small", { text: description })]);
}

function wafCapability(title, description) {
  return el("article", { className: "waf-capability-item" }, [el("strong", { text: title }), el("span", { text: description })]);
}

function wafNavigateButton(label, page, variant = "") {
  return button(label, () => ragNavigate(page), variant);
}

function wafRecentEvent(event) {
  const action = event.action === "block" ? "已拦截" : event.action === "log" ? "观察中" : "已记录";
  const disposition = event.disposition_status || "待处置";
  return el("article", { className: "waf-recent-event" }, [
    el("div", { className: "waf-recent-event-main" }, [
      el("strong", { text: event.rule_code || "未命名规则" }),
      el("span", { text: `${event.client_ip || "未知来源"} · ${event.path || "/"}` }),
      el("small", { text: event.created_at || "时间未知" })
    ]),
    el("div", { className: "waf-recent-event-status" }, [el("span", { className: `waf-event-action ${event.action === "block" ? "is-blocked" : ""}`, text: action }), el("small", { text: disposition })])
  ]);
}

async function renderWafVersionsPage(content) {
  clear(content);
  const host = el("section", { className: "waf-version-page stack" });
  content.append(host);

  const load = async () => {
    clear(host);
    try {
      const result = await api("/admin-api/waf/versions");
      document.getElementById("request-id").textContent = result.requestId || "";
      const versions = Array.isArray(result.data) ? result.data : [];
      const currentVersion = versions.find((version) => version.status === "published") || null;
      const actions = el("div", { className: "toolbar-actions" }, [
        button("Dry Run 发布", () => publishWafFromVersions({ dry_run: true, reload: false }, load), "secondary"),
        button("发布当前规则", () => {
          if (!window.confirm("确认发布当前启用的 WAF 规则？高危规则可能影响管理端或 API 入口。")) return;
          publishWafFromVersions({ confirm_high_risk: true, reload: true }, load);
        }, "danger")
      ]);
      host.append(el("header", { className: "panel waf-version-header" }, [
        el("div", {}, [
          el("span", { className: "waf-cockpit-kicker", text: "发布控制" }),
          el("h2", { text: "WAF 发布版本" }),
          el("p", { text: currentVersion ? `当前生效版本：${currentVersion.version_code}` : "当前没有已发布版本" })
        ]),
        actions
      ]));
      if (versions.length === 0) {
        host.append(statusMessage("暂无 WAF 发布版本，请先在规则页完成预检与发布。"));
        return;
      }
      host.append(el("div", { className: "waf-version-list" }, versions.map((version) => renderWafVersionCard(version, currentVersion, load))));
    } catch (error) {
      host.append(statusMessage(adminErrorMessage(error, "WAF 发布版本加载失败"), "error"));
      host.append(button("重新加载版本", load, "secondary"));
    }
  };

  await load();
}

function renderWafVersionCard(version, currentVersion, onRefresh) {
  const isCurrent = version.status === "published";
  const actionButtons = [button("查看差异", () => showWafVersionDiff(version.version_code), "secondary")];
  if (!isCurrent) {
    actionButtons.push(button("回滚到此版本", () => confirmWafRollback(version, currentVersion, onRefresh), "danger"));
  }
  return el("article", { className: `panel waf-version-card ${isCurrent ? "is-current" : ""}` }, [
    el("div", { className: "waf-version-main" }, [
      el("div", { className: "waf-version-title" }, [
        el("strong", { text: version.version_code || "未知版本" }),
        el("span", { className: `waf-version-status is-${version.status || "unknown"}`, text: wafVersionStatusLabel(version.status) })
      ]),
      el("small", { text: `发布人：${version.published_by || "系统"} · ${version.created_at || "时间未知"}` })
    ]),
    el("div", { className: "toolbar-actions" }, actionButtons)
  ]);
}

function wafVersionStatusLabel(status) {
  return ({ published: "当前生效", rolled_back: "已回退", failed: "发布失败", draft: "草稿" })[status] || "未知状态";
}

async function showWafVersionDiff(versionCode) {
  try {
    const result = await api(`/admin-api/waf/versions/${encodeURIComponent(versionCode)}/diff`, { query: { base: "previous" } });
    document.getElementById("request-id").textContent = result.requestId || "";
    showModal(`版本差异 · ${versionCode}`, renderWafVersionDiff(result.data || {}));
  } catch (error) {
    showModal("WAF 版本差异", statusMessage(adminErrorMessage(error, "版本差异加载失败"), "error"));
  }
}

function renderWafVersionDiff(data) {
  return el("div", { className: "waf-diff-view stack" }, [
    statusMessage(`对比版本：${data.base_version_code || "初始版本"} → ${data.version_code || "当前版本"}`),
    wafDiffSection("规则快照差异", data.rules || []),
    wafDiffSection("Caddy 片段差异", data.caddyfile_fragment || [])
  ]);
}

function wafDiffSection(title, lines) {
  return el("section", { className: "waf-diff-section" }, [
    el("h3", { text: title }),
    Array.isArray(lines) && lines.length ? el("pre", { className: "waf-diff-lines" }, lines.map(wafDiffLine)) : statusMessage("该部分没有差异", "success")
  ]);
}

function wafDiffLine(line) {
  const text = String(line || "");
  const tone = text.startsWith("+ ") ? "is-added" : text.startsWith("- ") ? "is-removed" : "is-context";
  return el("code", { className: `waf-diff-line ${tone}`, text });
}

function confirmWafRollback(version, currentVersion, onRefresh) {
  const body = el("div", { className: "waf-rollback-dialog stack" }, [
    statusMessage("回滚会替换当前 Caddy WAF 片段并触发配置验证与重载。", "warning"),
    el("div", { className: "data-detail-list" }, [
      ["当前版本", currentVersion?.version_code || "无"],
      ["目标版本", version.version_code || "未知"]
    ].map(([label, value]) => el("div", { className: "data-detail-row" }, [el("span", { text: label }), el("strong", { text: value })]))),
    button("确认回滚", async () => {
      if (!window.confirm(`确认将 WAF 回滚到 ${version.version_code}？`)) return;
      try {
        const result = await api("/admin-api/waf/rollback", {
          method: "POST",
          body: JSON.stringify({ version_code: version.version_code, reload: true })
        });
        showModal("WAF 回滚结果", renderWafOperationResult(result.data || {}));
        await onRefresh();
      } catch (error) {
        showModal("WAF 回滚失败", statusMessage(adminErrorMessage(error, "WAF 回滚失败"), "error"));
      }
    }, "danger")
  ]);
  showModal("回滚 WAF 版本", body);
}

async function publishWafFromVersions(payload, onRefresh) {
  try {
    const result = await api("/admin-api/waf/publish", { method: "POST", body: JSON.stringify(payload) });
    document.getElementById("request-id").textContent = result.requestId || "";
    showModal(payload.dry_run ? "WAF Dry Run 结果" : "WAF 发布结果", payload.dry_run ? renderWafPreviewResult(result.data || {}) : renderWafOperationResult(result.data || {}));
    await onRefresh();
  } catch (error) {
    showModal("WAF 发布失败", statusMessage(adminErrorMessage(error, "WAF 发布失败"), "error"));
  }
}

function renderWafOperationResult(data) {
  return el("div", { className: "data-detail-list" }, [
    ["状态", data.status || "unknown"],
    ["版本", data.version_code || "-"],
    ["配置验证", data.validated === false ? "失败" : "通过"],
    ["Caddy 重载", data.reloaded ? "已重载" : "未重载"],
    ["错误", data.error || "无"]
  ].map(([label, value]) => el("div", { className: "data-detail-row" }, [el("span", { text: label }), el("strong", { text: value })])));
}

async function renderWafIpBansPage(content) {
  clear(content);
  const form = resourceForm([{ name: "client_ip", label: "IP 地址" }, { name: "reason", label: "封禁原因" }]);
  const feedback = el("div", { className: "stack" });
  const tablePanel = el("section", { className: "panel table-panel" });
  const load = async () => {
    const result = await api("/admin-api/waf/ip-bans");
    clear(tablePanel);
    const rows = result.data?.records || [];
    const list = el("div", { className: "stack" });
    for (const row of rows) {
      list.append(el("article", { className: "panel" }, [
        renderDataDetails(row),
        button("解封", async () => { await api(`/admin-api/waf/ip-bans/${encodeURIComponent(row.client_ip)}`, { method: "DELETE" }); await load(); }, "secondary")
      ]));
    }
    tablePanel.append(el("h2", { text: "系统 IP 封禁" }), rows.length ? list : statusMessage("暂无封禁 IP"));
  };
  form.append(button("封禁 IP", async () => {
    await api("/admin-api/waf/ip-bans", { method: "POST", body: JSON.stringify(readFields(form, [{ name: "client_ip" }, { name: "reason" }])) });
    const overview = await api("/admin-api/waf/overview");
    const publicationStatus = overview.data?.publication?.status;
    const edgeMessage = publicationStatus === "published" ? "边缘 WAF 已发布" : "边缘 WAF 等待发布";
    clear(feedback);
    feedback.append(statusMessage(`应用层封禁已生效；${edgeMessage}`, "success"));
    await load();
  }));
  content.append(el("section", { className: "panel" }, [el("h2", { text: "新增封禁" }), form, feedback]), tablePanel);
  await load();
}

async function renderWafAlertsPage(content) {
  await renderAlertEventsPage(content, { event_type: "waf_hit_spike" }, "WAF 告警");
}

async function renderAlertEventsPage(content, query = {}, title = "告警中心") {
  clear(content);
  const result = await api("/admin-api/alert-events", { query: { limit: 100, ...query } });
  const rows = Array.isArray(result.data) ? result.data : [];
  const list = el("div", { className: "stack" });
  for (const row of rows) {
    list.append(el("article", { className: "panel" }, [
      renderDataDetails(row),
      el("div", { className: "toolbar-actions" }, [
        button("确认", async () => updateWafAlertStatus(row, "acknowledged"), "secondary"),
        button("关闭", async () => updateWafAlertStatus(row, "resolved"), "secondary")
      ])
    ]));
  }
  content.append(el("section", { className: "panel" }, [el("h2", { text: title }), rows.length ? list : statusMessage(`暂无${title}`) ]));
}

async function updateWafAlertStatus(row, status) {
  const note = window.prompt("填写处置说明");
  if (!note) return;
  await api(`/admin-api/alerts/${row.id}/status`, { method: "POST", body: JSON.stringify({ status, note }) });
}

async function renderOperationsStatusPage(content) {
  clear(content);
  const result = await api("/admin-api/operations/status");
  const status = result.data || {};
  const health = status.health || {};
  const services = health.services || {};
  const queues = status.queues || {};
  const logs = Array.isArray(status.recent_worker_logs) ? status.recent_worker_logs : [];
  const serviceLabels = { php: "PHP", mysql: "MySQL", redis: "Redis", qdrant: "Qdrant", "queue-worker": "队列 Worker", "rag-worker": "RAG Worker" };
  const queueMetrics = [
    ["pending", "待处理"],
    ["processing", "处理中"],
    ["failed", "失败任务"]
  ];
  const healthCards = Object.entries(serviceLabels).map(([service, label]) => {
    const healthy = services[service]?.healthy === true;
    return el("article", { className: `operations-health-card ${healthy ? "is-healthy" : "is-unhealthy"}` }, [
      el("span", { className: "operations-card-label", text: label }),
      el("strong", { text: healthy ? "正常" : "异常" })
    ]);
  });
  const queueCards = queueMetrics.map(([key, label]) => el("article", { className: `operations-queue-card ${key === "failed" && Number(queues[key] || 0) > 0 ? "has-failures" : ""}` }, [
    el("span", { className: "operations-card-label", text: label }),
    el("strong", { text: String(Number(queues[key] || 0)) })
  ]));
  const workerLogList = el("div", { className: "operations-worker-log-list" }, logs.length
    ? logs.map((row) => renderOperationsWorkerLog(row))
    : [statusMessage("暂无最近任务记录")]
  );

  content.append(el("section", { className: "panel operations-status-overview" }, [
    el("div", { className: "operations-status-heading" }, [
      el("div", {}, [el("h2", { text: "系统运行状态" }), el("p", { className: "notice", text: health.healthy ? "核心服务运行正常" : "发现需要处理的服务异常" })]),
      el("span", { className: `operations-overall-status ${health.healthy ? "is-healthy" : "is-unhealthy"}`, text: health.healthy ? "运行正常" : "需要关注" })
    ]),
    el("div", { className: "operations-health-grid" }, healthCards),
    el("div", { className: "operations-queue-section" }, [el("h3", { text: "任务队列" }), el("div", { className: "operations-queue-grid" }, queueCards)]),
    el("div", { className: "operations-worker-log-section" }, [el("h3", { text: "最近任务" }), workerLogList])
  ]));
}

function renderOperationsWorkerLog(row) {
  const level = String(row.level || "info").toLowerCase();
  const metadata = [
    row.request_id ? `请求 ${row.request_id}` : "",
    row.status !== null && row.status !== undefined && row.status !== "" ? `状态 ${row.status}` : "",
    row.latency_ms !== null && row.latency_ms !== undefined && row.latency_ms !== "" ? `${row.latency_ms} ms` : ""
  ].filter(Boolean);
  return el("article", { className: "operations-worker-log" }, [
    el("div", { className: "operations-worker-log-heading" }, [
      el("span", { className: `operations-log-level is-${level}`, text: level.toUpperCase() }),
      el("time", { text: formatOperationsTimestamp(row.timestamp) })
    ]),
    el("strong", { text: String(row.message || "未提供任务消息") }),
    metadata.length ? el("div", { className: "operations-worker-log-meta" }, metadata.map((item) => el("span", { text: item }))) : null
  ].filter(Boolean));
}

function formatOperationsTimestamp(value) {
  const parsed = new Date(String(value || ""));
  return Number.isNaN(parsed.getTime()) ? "时间未知" : parsed.toLocaleString("zh-CN", { hour12: false });
}

async function renderWafRulesPage(content, initialFilters = {}) {
  const resourceKey = "waf-rules";
  const config = resources[resourceKey];
  clear(content);
  const state = { filters: { limit: 50, ...initialFilters } };
  const tablePanel = el("section", { className: "panel table-panel" });
  const toolbar = renderFilters(resourceKey, state, async () => renderRows(tablePanel, resourceKey, config, state));
  const actions = el("div", { className: "toolbar-actions" });
  actions.append(button("新增", () => showWafRuleEditor(null, async () => renderRows(tablePanel, resourceKey, config, state))));
  actions.append(button("规则模板", () => showWafTemplates(async () => renderRows(tablePanel, resourceKey, config, state)), "secondary"));
  actions.append(button("预览片段", () => showWafPreview(), "secondary"));
  actions.append(button("Dry Run 发布", () => publishWafRules({ dry_run: true, reload: false }, tablePanel, config, state), "secondary"));
  actions.append(button("确认高危并发布", () => {
    if (!window.confirm("确认发布当前 WAF 规则？高危规则可能影响管理端或 API 入口访问。")) {
      return;
    }
    publishWafRules({ confirm_high_risk: true, reload: true }, tablePanel, config, state);
  }, "secondary"));
  toolbar.append(actions);
  content.append(toolbar, tablePanel);
  await renderRows(tablePanel, resourceKey, config, state);
}

async function showWafTemplates(onSaved = async () => {}) {
  try {
    const result = await api("/admin-api/waf/templates");
    document.getElementById("request-id").textContent = result.requestId || "";
    const templates = Array.isArray(result.data) ? result.data : [];
    const list = el("div", { className: "stack" });
    for (const template of templates) {
      list.append(el("article", { className: "panel" }, [
        renderDataDetails(template),
        button("预填规则", () => showWafTemplatePrefill(template, onSaved), "secondary")
      ]));
    }
    showModal("WAF 规则模板", templates.length ? list : statusMessage("暂无可用模板"));
  } catch (error) {
    showModal("WAF 规则模板", statusMessage(adminErrorMessage(error, "规则模板加载失败"), "error"));
  }
}

async function showWafPreview() {
  try {
    const result = await api("/admin-api/waf/preview", {
      method: "POST",
      body: JSON.stringify({})
    });
    document.getElementById("request-id").textContent = result.requestId || "";
    showModal("WAF 片段预览", renderWafPreviewResult(result.data || {}));
  } catch (error) {
    showModal("WAF 片段预览", statusMessage(adminErrorMessage(error, "WAF 片段预览失败"), "error"));
  }
}

async function publishWafRules(payload, tablePanel, config, state) {
  try {
    const result = await api("/admin-api/waf/publish", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    document.getElementById("request-id").textContent = result.requestId || "";
    showModal("WAF 发布结果", renderWafPreviewResult(result.data || {}));
    await renderRows(tablePanel, "waf-rules", config, state);
  } catch (error) {
    showModal("WAF 发布结果", statusMessage(adminErrorMessage(error, "WAF 发布失败"), "error"));
  }
}

function renderWafPreviewResult(data) {
  const summary = [
    ["状态", data.status || "-"],
    ["规则数", Array.isArray(data.rules) ? data.rules.length : 0],
    ["错误数", Array.isArray(data.findings?.errors) ? data.findings.errors.length : 0],
    ["高危项", Array.isArray(data.high_risk) ? data.high_risk.length : 0]
  ];
  const children = [
    el("div", { className: "data-detail-list" }, summary.map(([label, value]) => el("div", { className: "data-detail-row" }, [
      el("span", { text: label }),
      el("strong", { text: value })
    ])))
  ];
  if (Array.isArray(data.findings?.errors) && data.findings.errors.length > 0) {
    children.push(el("h3", { text: "校验错误" }));
    children.push(renderDataDetails(data.findings.errors));
  }
  if (Array.isArray(data.high_risk) && data.high_risk.length > 0) {
    children.push(el("h3", { text: "高危变更" }));
    children.push(renderDataDetails(data.high_risk));
  }
  if (data.fragment) {
    children.push(el("h3", { text: "Caddy 片段" }));
    children.push(el("textarea", { className: "waf-fragment-preview", readOnly: true, rows: 16, value: data.fragment }));
  }
  return el("div", { className: "waf-preview-result" }, children);
}

function renderFilters(resourceKey, state, onApply) {
  const fields = filterFields(resourceKey);
  const form = el("form", { className: "filters", onSubmit: (event) => {
    event.preventDefault();
    state.filters = { ...state.filters, ...readFields(form, fields) };
    onApply();
  } });
  for (const config of fields) {
    form.append(field(config, state.filters[config.name] || ""));
  }
  form.append(button("筛选", () => {
    state.filters = { ...state.filters, ...readFields(form, fields) };
    onApply();
  }));
  return form;
}

async function renderRows(parent, resourceKey, config, state) {
  clear(parent);
  const result = await api(`/admin-api/${resourceKey}`, { query: state.filters }).catch((error) => ({
    data: [{ message: adminErrorMessage(error, "列表加载失败"), request_id: error.requestId }],
    requestId: error.requestId
  }));
  document.getElementById("request-id").textContent = result.requestId || "";
  const rows = Array.isArray(result.data) ? result.data : [];
  if (rows.length === 0) {
    parent.append(statusMessage("暂无数据"));
    return;
  }
  const context = await renderContext(resourceKey);
  parent.append(renderTable(rows, config, resourceKey, async () => renderRows(parent, resourceKey, config, state), context));
}

async function renderContext(resourceKey) {
  if (resourceKey === "model-prices") {
    const models = await lookupRows("models");
    return { modelsById: rowsById(models) };
  }
  if (resourceKey === "api-keys") {
    return apiKeyReferenceContext();
  }
  if (resourceKey === "admin-users") {
    return apiKeyReferenceContext();
  }
  if (resourceKey === "api-key-model-quotas") {
    return modelQuotaReferenceContext();
  }
  if (resourceKey === "quota-bindings") {
    return quotaBindingReferenceContext();
  }
  return {};
}

async function lookupRows(resourceKey) {
  const result = await api(lookupResourcePaths[resourceKey] || `/admin-api/${resourceKey}`, { query: { limit: 500 } }).catch(() => ({ data: [] }));
  return Array.isArray(result.data) ? result.data : (Array.isArray(result.data?.items) ? result.data.items : []);
}

function rowsById(rows) {
  const byId = new Map();
  for (const row of rows) {
    byId.set(Number(row.id), row);
  }
  return byId;
}

async function modelQuotaReferenceContext() {
  const [apiKeys, users, models] = await Promise.all([
    lookupRows("api-keys"),
    lookupRows("users"),
    lookupRows("models")
  ]);
  return {
    apiKeys,
    models,
    apiKeysById: rowsById(apiKeys),
    usersById: rowsById(users),
    modelsById: rowsById(models)
  };
}

async function apiKeyReferenceContext() {
  const [users, departments, models] = await Promise.all([
    lookupRows("users"),
    lookupRows("departments"),
    lookupRows("models")
  ]);
  return {
    users,
    departments,
    models,
    usersById: rowsById(users),
    departmentsById: rowsById(departments),
    modelsById: rowsById(models)
  };
}

async function quotaBindingReferenceContext() {
  const [quotaPolicies, apiKeys, apiKeyContext] = await Promise.all([
    lookupRows("quota-policies"),
    lookupRows("api-keys"),
    apiKeyReferenceContext()
  ]);
  return {
    ...apiKeyContext,
    apiKeys,
    apiKeysById: rowsById(apiKeys),
    quotaPolicies,
    quotaPoliciesById: rowsById(quotaPolicies)
  };
}

function userOptionLabel(user) {
  return [user.username || `用户 #${user.id}`, valueLabel("role", user.role), valueLabel("status", user.status)]
    .filter(Boolean)
    .join(" / ");
}

function apiKeyUserLabel(value, context = {}) {
  const user = context.usersById?.get(Number(value));
  return user ? userOptionLabel(user) : formatResourceValue("user_id", value);
}

function apiKeyDepartmentLabel(value, context = {}) {
  const department = context.departmentsById?.get(Number(value));
  return department?.name || formatResourceValue("department_id", value);
}

function normalizeIdList(value) {
  if (Array.isArray(value)) {
    return value.map(Number).filter((item) => Number.isFinite(item));
  }
  if (typeof value === "string" && value.trim() !== "") {
    try {
      const parsed = JSON.parse(value);
      if (Array.isArray(parsed)) {
        return parsed.map(Number).filter((item) => Number.isFinite(item));
      }
    } catch {
      return value.split(",").map((item) => Number(item.trim())).filter((item) => Number.isFinite(item));
    }
  }
  return [];
}

function apiKeyModelLabels(value, context = {}) {
  const ids = normalizeIdList(value);
  if (ids.length === 0) {
    return "";
  }
  return ids.map((id) => modelQuotaModelLabel(id, context)).join(", ");
}

function adminUserPermissionLabels(value) {
  let permissions = value;
  if (typeof value === "string") {
    try {
      permissions = JSON.parse(value);
    } catch {
      permissions = value.split(",");
    }
  }
  if (!Array.isArray(permissions)) {
    return "";
  }
  const labels = new Map(adminPermissionOptions);
  return permissions.map((permission) => labels.get(String(permission)) || String(permission)).join("、");
}

function adminUserDepartmentLabels(value, context = {}) {
  const ids = normalizeIdList(value);
  return ids.map((id) => apiKeyDepartmentLabel(id, context)).join("、");
}

function quotaPolicyLabel(value, context = {}) {
  const policy = context.quotaPoliciesById?.get(Number(value));
  if (!policy) {
    return formatResourceValue("quota_policy_id", value);
  }
  return [policy.name || `策略 #${policy.id}`, valueLabel("period", policy.period), valueLabel("status", policy.status)]
    .filter(Boolean)
    .join(" / ");
}

function quotaTargetRef(rowOrType, targetId) {
  if (typeof rowOrType === "object" && rowOrType !== null) {
    return `${rowOrType.target_type || ""}:${rowOrType.target_id || ""}`;
  }
  return `${rowOrType || ""}:${targetId || ""}`;
}

function quotaTargetLabel(targetType, targetId, context = {}) {
  if (targetType === "api_key") {
    return modelQuotaApiKeyLabel(targetId, { ...context, apiKeysById: context.apiKeysById || rowsById(context.apiKeys || []) });
  }
  if (targetType === "user") {
    return apiKeyUserLabel(targetId, context);
  }
  if (targetType === "department") {
    return apiKeyDepartmentLabel(targetId, context);
  }
  return formatResourceValue("target_id", targetId);
}

function modelQuotaApiKeyLabel(value, context = {}) {
  const apiKey = context.apiKeysById?.get(Number(value));
  if (!apiKey) {
    return formatResourceValue("api_key_id", value);
  }
  const name = apiKey.name || `API 密钥 #${apiKey.id}`;
  const user = context.usersById?.get(Number(apiKey.user_id));
  const userLabel = user?.username || (apiKey.user_id ? `用户 #${apiKey.user_id}` : "");
  const keyDisplay = apiKey.display || (apiKey.key_suffix ? `****${apiKey.key_suffix}` : "");
  return [name, userLabel, keyDisplay].filter(Boolean).join(" / ");
}

function modelQuotaModelLabel(value, context = {}) {
  const model = context.modelsById?.get(Number(value));
  if (!model) {
    return formatResourceValue("model_id", value);
  }
  const externalName = model.external_name && model.external_name !== model.name ? ` / ${model.external_name}` : "";
  return `${model.name || `模型 #${model.id}`}${externalName}`;
}

function modelQuotaApiKeyOptions(context) {
  return (context.apiKeys || []).map((apiKey) => [String(apiKey.id), modelQuotaApiKeyLabel(apiKey.id, context)]);
}

function modelQuotaModelOptions(context) {
  return (context.models || []).map((model) => [String(model.id), modelQuotaModelLabel(model.id, context)]);
}

function userOptions(context) {
  return (context.users || []).map((user) => [String(user.id), userOptionLabel(user)]);
}

function quotaPolicyOptions(context) {
  return (context.quotaPolicies || []).map((policy) => [String(policy.id), quotaPolicyLabel(policy.id, context)]);
}

function quotaTargetOptions(context) {
  const apiKeys = (context.apiKeys || []).map((apiKey) => [quotaTargetRef("api_key", apiKey.id), `API 密钥 / ${modelQuotaApiKeyLabel(apiKey.id, context)}`]);
  const users = (context.users || []).map((user) => [quotaTargetRef("user", user.id), `用户 / ${userOptionLabel(user)}`]);
  const departments = (context.departments || []).map((department) => [quotaTargetRef("department", department.id), `部门 / ${department.name || `#${department.id}`}`]);
  return [...apiKeys, ...users, ...departments];
}

function knowledgeBaseOptions(context) {
  return (context.knowledgeBases || []).map((kb) => {
    const suffix = kb.kb_id ? ` (${kb.kb_id})` : "";
    return [String(kb.id), `${kb.name || `知识库 #${kb.id}`}${suffix}`];
  });
}

function referenceOptions(reference, context) {
  if (reference === "apiKeys") {
    return modelQuotaApiKeyOptions(context);
  }
  if (reference === "users") {
    return userOptions(context);
  }
  if (reference === "departments") {
    return (context.departments || []).map((department) => [String(department.id), department.name || `#${department.id}`]);
  }
  if (reference === "quotaPolicies") {
    return quotaPolicyOptions(context);
  }
  if (reference === "quotaTargets") {
    return quotaTargetOptions(context);
  }
  if (reference === "models") {
    return modelQuotaModelOptions(context);
  }
  if (reference === "providers") {
    return (context.providers || []).map((provider) => [String(provider.id), provider.name || `厂商 #${provider.id}`]);
  }
  if (reference === "knowledgeBases") {
    return knowledgeBaseOptions(context);
  }
  if (reference === "vectorConfigs") {
    return (context.vectorConfigs || []).map((config) => [
      String(config.id),
      [config.name || `向量配置 #${config.id}`, config.modality, config.model_name].filter(Boolean).join(" / ")
    ]);
  }
  return [];
}

function renderTable(rows, config, resourceKey, onRefresh, context = {}) {
  const columns = config.columns || [...new Set(rows.flatMap((row) => Object.keys(row)).slice(0, 12))];
  const isModelTable = resourceKey === "models";
  const selectedModelIds = new Set();
  let modelSelectAll = null;
  let modelSelectionStatus = null;
  const modelBulkButtons = [];
  const updateModelSelection = () => {
    const selectedCount = selectedModelIds.size;
    if (modelSelectionStatus) {
      setText(modelSelectionStatus, selectedCount > 0 ? `已选择 ${selectedCount} 个模型` : "未选择模型");
    }
    for (const actionButton of modelBulkButtons) {
      actionButton.disabled = selectedCount === 0;
    }
    if (modelSelectAll) {
      const rowCount = rows.filter((row) => row.id).length;
      modelSelectAll.checked = rowCount > 0 && selectedCount === rowCount;
      modelSelectAll.indeterminate = selectedCount > 0 && selectedCount < rowCount;
    }
  };
  const table = el("table", { className: `resource-table resource-table--${resourceKey}` });
  const headRow = el("tr");
  if (isModelTable) {
    modelSelectAll = el("input", {
      type: "checkbox",
      className: "model-select-all",
      title: "选择当前页模型",
      onChange: (event) => {
        const checked = event.currentTarget.checked;
        selectedModelIds.clear();
        for (const row of rows) {
          if (row.id && checked) {
            selectedModelIds.add(Number(row.id));
          }
        }
        for (const checkbox of table.querySelectorAll(".model-row-select")) {
          checkbox.checked = checked;
        }
        updateModelSelection();
      }
    });
    headRow.append(el("th", { className: "table-cell table-cell--select" }, [modelSelectAll]));
  }
  for (const column of columns) {
    headRow.append(el("th", { className: `table-cell table-cell--${column}`, text: columnLabel(column) }));
  }
  headRow.append(el("th", { className: "table-cell table-cell--actions", text: "操作" }));
  const body = el("tbody");
  for (const row of rows) {
    const tr = el("tr");
    if (isModelTable) {
      const checkbox = el("input", {
        type: "checkbox",
        className: "model-row-select",
        value: row.id,
        title: `选择模型 ${row.name || row.id}`,
        onChange: (event) => {
          const id = Number(event.currentTarget.value);
          if (event.currentTarget.checked) {
            selectedModelIds.add(id);
          } else {
            selectedModelIds.delete(id);
          }
          updateModelSelection();
        }
      });
      tr.append(el("td", { className: "table-cell table-cell--select" }, [checkbox]));
    }
    for (const column of columns) {
      const cell = el("td", { className: `table-cell table-cell--${column}` });
      const content = renderTableCell(resourceKey, column, row, context);
      if (content instanceof Node) {
        cell.append(content);
      } else {
        setText(cell, content);
      }
      tr.append(cell);
    }
    const ops = el("td", { className: "table-cell table-cell--actions row-actions" });
    if (resourceKey === "api-keys") {
      ops.append(button("详情", () => showApiKeyDetails(row, config, context), "secondary"));
    }
    if (resourceKey === "model-prices") {
      ops.append(button("价格明细", () => showModelPriceDetails(row, context), "secondary"));
    }
    if (resourceKey === "usage-records") {
      ops.append(button("内容", () => showUsageContent(row), "secondary"));
    }
    if (resourceKey === "export-tasks" && row.status === "succeeded" && row.export_id) {
      ops.append(button("下载", () => openAdminDownload(`/admin-api/exports/${encodeURIComponent(row.export_id)}/download-url`), "secondary"));
    }
    if (resourceKey === "media-assets" && row.asset_id) {
      ops.append(button("下载", () => openAdminDownload(`/admin-api/media-assets/${encodeURIComponent(row.asset_id)}/download-url`, "POST"), "secondary"));
    }
    if (resourceKey === "log-archive-files" && !row.deleted_at) {
      ops.append(button("下载", () => openAdminDownload(`/admin-api/log-archive-files/${encodeURIComponent(row.id)}/download-url`, "POST"), "secondary"));
    }
    if (resourceKey === "dashboard-share-links") {
      ops.append(button("大屏1", () => {
        window.open(`/screen/${encodeURIComponent(row.share_code)}/overview`, "_blank", "noopener");
      }, "secondary"));
      ops.append(button("大屏2", () => {
        window.open(`/screen/${encodeURIComponent(row.share_code)}/operations`, "_blank", "noopener");
      }, "secondary"));
      ops.append(button("大屏3", () => {
        window.open(`/screen/${encodeURIComponent(row.share_code)}/firewall`, "_blank", "noopener");
      }, "secondary"));
      ops.append(button("重置密码", async () => {
        const result = await api(`/admin-api/dashboard-share-links/${row.id}/reset-password`, { method: "POST" });
        showShareLinkPasswordResetResult(result.data || {});
        await onRefresh();
      }, "secondary"));
      ops.append(button("撤销会话", async () => {
        const result = await api(`/admin-api/dashboard-share-links/${row.id}/revoke-sessions`, { method: "POST" });
        showOperationResult("\u64a4\u9500\u4f1a\u8bdd", result.data, "\u5df2\u64a4\u9500\u76f8\u5173\u4f1a\u8bdd");
        await onRefresh();
      }, "secondary"));
      ops.append(button("放行 IP", () => showAllowClientIp(row, onRefresh), "secondary"));
    }
    if (resourceKey === "dashboard-share-access-logs" && row.share_link_id && row.client_ip && row.event_type === "ip_denied") {
      ops.append(button("放行 IP", () => showAllowClientIp({ id: row.share_link_id, client_ip: row.client_ip }, onRefresh), "secondary"));
    }
    if (resourceKey === "users") {
      ops.append(button("重置密码", () => showUserPasswordReset(row, onRefresh), "secondary"));
      ops.append(button("调整部门", () => showUserDepartmentMove(row, onRefresh), "secondary"));
      ops.append(button("更新状态", () => showUserStatusUpdate(row, onRefresh), "secondary"));
    }
    if (resourceKey === "admin-users") {
      ops.append(button("重置密码", () => showUserPasswordReset(row, onRefresh, "admin-users"), "secondary"));
      ops.append(button("授权部门", () => showAdminUserDepartmentGrant(row, onRefresh), "secondary"));
      ops.append(button("更新状态", () => showUserStatusUpdate(row, onRefresh, "admin-users"), "secondary"));
      ops.append(button("撤销会话", () => showAdminUserSessionRevoke(row, onRefresh), "danger"));
    }
    if (resourceKey === "providers" && row.id) {
      ops.append(button("拉取模型", () => showProviderModelImport(row, onRefresh), "secondary"));
    }
    if (resourceKey === "waf-rules" && row.id) {
      ops.append(button("编辑", () => showWafRuleEditor(row, onRefresh), "secondary"));
    } else if (config.form && row.id) {
      ops.append(button("编辑", () => showEditModal(resourceKey, config, row, onRefresh), "secondary"));
    }
    for (const action of config.actions || []) {
      if (action.diffField && row[action.diffField]) {
        ops.append(button(action.label, async () => {
          const result = await api(`${action.pathPrefix}${encodeURIComponent(row[action.diffField])}/diff`, {
            method: action.method || "GET",
            query: { base: "previous" }
          });
          showModal(action.label, renderDataDetails(result.data));
        }, "secondary"));
      }
      if (action.bodyField && row[action.bodyField]) {
        ops.append(button(action.label, async () => {
          const result = await api(action.path, {
            method: action.method || "POST",
            body: JSON.stringify({ [action.bodyField]: row[action.bodyField] })
          });
          showOperationResult(action.label, result.data);
          await onRefresh();
        }, "secondary"));
      }
    }
    if (resourceKey === "providers" && row.id) {
      ops.append(button("删除", async () => {
        if (!window.confirm(`确认删除厂商 #${row.id} ${row.name || ""}？该厂商下的模型配置也会被删除。`)) {
          return;
        }
        try {
          const result = await api(`/admin-api/${resourceKey}/${row.id}`, { method: "DELETE" });
          showOperationResult("删除厂商", result.data);
          await onRefresh();
        } catch (error) {
          showModal("删除厂商", statusMessage(adminErrorMessage(error, "删除厂商失败，请确认权限或关联配置"), "error"));
        }
      }, "danger"));
    }
    if (resourceKey === "models" && row.id) {
      ops.append(button("删除", async () => {
        if (!window.confirm(`确认删除模型 #${row.id} ${row.name || ""}？`)) {
          return;
        }
        try {
          const result = await api(`/admin-api/${resourceKey}/${row.id}`, { method: "DELETE" });
          showOperationResult("删除模型", result.data);
          await onRefresh();
        } catch (error) {
          showModal("删除模型", statusMessage(adminErrorMessage(error, "删除模型失败，请确认权限或关联配置"), "error"));
        }
      }, "danger"));
    }
    if (!["providers", "models"].includes(resourceKey) && config.readOnly !== true && row.id && row.status !== "disabled" && row.status !== "deleted") {
      ops.append(button("停用", async () => {
        await api(`/admin-api/${resourceKey}/${row.id}`, { method: "DELETE" });
        await onRefresh();
      }, "danger"));
    }
    tr.append(ops);
    body.append(tr);
  }
  table.append(el("thead", {}, [headRow]), body);
  if (isModelTable) {
    const bulkActions = el("div", { className: "model-bulk-actions" });
    modelSelectionStatus = el("span", { className: "model-bulk-selection", text: "未选择模型" });
    const enableButton = button("批量启用", () => runModelBatchAction("enable", selectedModelIds, onRefresh), "secondary");
    const disableButton = button("批量暂停", () => runModelBatchAction("disable", selectedModelIds, onRefresh), "secondary");
    const deleteButton = button("批量删除", () => runModelBatchAction("delete", selectedModelIds, onRefresh), "danger");
    modelBulkButtons.push(enableButton, disableButton, deleteButton);
    bulkActions.append(modelSelectionStatus, enableButton, disableButton, deleteButton);
    updateModelSelection();
    return el("div", { className: "model-table-wrap" }, [bulkActions, table]);
  }
  return table;
}

async function runModelBatchAction(action, selectedIds, onRefresh) {
  const ids = Array.from(selectedIds);
  if (ids.length === 0) {
    showModal("批量操作模型", statusMessage("请先选择模型", "error"));
    return;
  }
  const labels = {
    enable: "批量启用",
    disable: "批量暂停",
    delete: "批量删除"
  };
  const label = labels[action] || "批量操作";
    if (action === "delete" && !window.confirm(`确认删除选中的 ${ids.length} 个模型？相关价格、API 密钥授权和模型限额也会被删除。`)) {
    return;
  }
  try {
    const result = await api("/admin-api/models/batch", {
      method: "POST",
      body: JSON.stringify({ action, ids })
    });
    showOperationResult(label, result.data);
    await onRefresh();
  } catch (error) {
    showModal(label, statusMessage(adminErrorMessage(error, `${label}失败，请确认权限或关联配置`), "error"));
  }
}

async function openAdminDownload(path, method = "GET") {
  const result = await api(path, { method });
  const url = result.data?.download_url;
  if (!url) throw new Error("下载链接生成失败");
  window.open(url, "_blank", "noopener");
}

function templateRuleValues(template) {
  return {
    rule_code: `template_${template.key || "rule"}`,
    scope: "global",
    matcher: template.matcher || {},
    action: template.action || "block",
    priority: template.priority || 100,
    description: template.description || template.label || ""
  };
}

function showWafTemplatePrefill(template, onSaved) {
  showWafRuleEditor(templateRuleValues(template), onSaved);
}

function showWafRuleEditor(rule, onSaved) {
  const existing = rule || {};
  const matcherValues = wafMatcherEditorValues(existing);
  const basicFields = [
    { name: "rule_code", label: "规则编码" },
    { name: "scope", label: "作用范围" },
    { name: "action", label: "执行动作", type: "select", options: [["block", "拦截"], ["redact", "脱敏"], ["remove_header", "移除请求头"], ["allow", "放行"]] },
    { name: "priority", label: "优先级", type: "number" },
    { name: "status", label: "规则状态", type: "select", options: [["active", "启用"], ["disabled", "停用"]] },
    { name: "description", label: "规则说明", optional: true }
  ];
  const values = {
    rule_code: existing.rule_code || "",
    scope: existing.scope || "global",
    action: existing.action || "block",
    priority: existing.priority ?? 100,
    status: existing.status || "active",
    description: existing.description || ""
  };
  const form = el("form", { className: "edit-form waf-rule-editor", onSubmit: (event) => event.preventDefault() });
  for (const config of basicFields) form.append(field(config, values[config.name]));

  const matcherType = field({
    name: "matcher_type",
    label: "Matcher 类型",
    type: "select",
    options: [
      ["ip", "IP 与网段"], ["path", "访问路径"], ["method", "HTTP 方法"], ["query", "查询参数"],
      ["header", "请求头"], ["body_size", "请求体大小"], ["security_headers", "安全响应头"]
    ]
  }, matcherValues.type);
  const matcherHost = el("section", { className: "waf-matcher-fields" });
  const matcherPreview = el("textarea", { className: "waf-matcher-preview", readOnly: true, rows: 9, ariaLabel: "Matcher JSON 预览" });
  const preflightPanel = el("section", { className: "waf-rule-preflight" }, [statusMessage("保存前必须完成候选规则预检。")]);
  const output = el("p", { className: "notice", hidden: true });
  let preflightPassed = false;

  const refreshPreview = () => {
    matcherPreview.value = JSON.stringify(wafMatcherFromEditor(form), null, 2);
  };
  const invalidatePreflight = () => {
    preflightPassed = false;
    preflightPanel.replaceChildren(statusMessage("规则已修改，请重新预检。", "warning"));
    refreshPreview();
  };
  const renderMatcherFields = () => {
    const type = form.querySelector('[name="matcher_type"]')?.value || "path";
    clear(matcherHost);
    for (const config of wafMatcherFieldConfigs(type)) {
      matcherHost.append(field(config, matcherValues[config.name] ?? ""));
    }
    matcherHost.querySelectorAll("input,select,textarea").forEach((control) => control.addEventListener("input", invalidatePreflight));
    refreshPreview();
  };

  form.append(matcherType, matcherHost, el("label", { className: "field waf-matcher-json" }, [
    el("span", { text: "高级 JSON 预览" }), matcherPreview
  ]), preflightPanel);
  matcherType.querySelector("select")?.addEventListener("change", () => {
    matcherValues.type = form.querySelector('[name="matcher_type"]')?.value || "path";
    renderMatcherFields();
    invalidatePreflight();
  });
  form.querySelectorAll("input,select,textarea").forEach((control) => {
    if (control.name !== "matcher_type" && !control.readOnly) control.addEventListener("input", invalidatePreflight);
  });
  renderMatcherFields();

  const actions = el("div", { className: "toolbar-actions" }, [
    button("预检当前规则", async () => {
      try {
        const candidate = wafRulePayloadFromEditor(form, basicFields);
        const result = await api("/admin-api/waf/preview", {
          method: "POST",
          body: JSON.stringify({ rules: [candidate] })
        });
        document.getElementById("request-id").textContent = result.requestId || "";
        preflightPassed = result.data?.status === "valid";
        preflightPanel.replaceChildren(renderWafPreviewResult(result.data || {}));
      } catch (error) {
        preflightPassed = false;
        preflightPanel.replaceChildren(statusMessage(adminErrorMessage(error, "规则预检失败"), "error"));
      }
    }, "secondary"),
    button(existing.id ? "保存规则" : "创建规则", async () => {
      if (!preflightPassed) {
        setText(output, "请先完成规则预检，并确保校验通过");
        output.className = "notice error";
        output.hidden = false;
        return;
      }
      try {
        const payload = wafRulePayloadFromEditor(form, basicFields);
        const result = await api(existing.id ? `/admin-api/waf-rules/${existing.id}` : "/admin-api/waf-rules", {
          method: existing.id ? "PUT" : "POST",
          body: JSON.stringify(payload)
        });
        setOperationOutput(output, result.data, existing.id ? "规则已保存" : "规则已创建");
        await onSaved();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, "WAF 规则保存失败"));
      }
    })
  ]);
  form.append(actions, output);
  showModal(existing.id ? `编辑 WAF 规则 · ${existing.rule_code}` : "新增 WAF 规则", form);
}

function wafMatcherEditorValues(rule) {
  let matcher = rule?.matcher || {};
  if (typeof matcher === "string") {
    try { matcher = JSON.parse(matcher); } catch { matcher = {}; }
  }
  const headers = matcher.headers || {};
  return {
    type: matcher.type || "path",
    matcher_paths: wafListText(matcher.paths),
    matcher_allow: wafListText(matcher.allow),
    matcher_deny: wafListText(matcher.deny),
    matcher_values: wafListText(matcher.values),
    matcher_keys: wafListText(matcher.keys),
    matcher_patterns: wafListText(matcher.patterns),
    matcher_headers: wafHeaderText(headers),
    matcher_max_bytes: matcher.max_bytes || 10485760
  };
}

function wafMatcherFieldConfigs(type) {
  return {
    ip: [
      { name: "matcher_paths", label: "生效路径，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "matcher_allow", label: "允许 IP 网段，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "matcher_deny", label: "拒绝 IP 网段，每行一个", type: "textarea", optional: true, rows: 3 }
    ],
    path: [{ name: "matcher_values", label: "路径或通配模式，每行一个", type: "textarea", rows: 5 }],
    method: [{ name: "matcher_deny", label: "拒绝的 HTTP 方法，每行一个", type: "textarea", rows: 4 }],
    query: [
      { name: "matcher_keys", label: "敏感参数名，每行一个", type: "textarea", optional: true, rows: 3 },
      { name: "matcher_patterns", label: "匹配表达式，每行一个", type: "textarea", optional: true, rows: 4 }
    ],
    header: [{ name: "matcher_headers", label: "请求头规则，每行“名称: 值”或仅名称", type: "textarea", rows: 5 }],
    body_size: [{ name: "matcher_max_bytes", label: "最大请求体字节数", type: "number" }],
    security_headers: [{ name: "matcher_headers", label: "响应头规则，每行“名称: 值”", type: "textarea", rows: 5 }]
  }[type] || [];
}

function wafMatcherFromEditor(form) {
  const type = form.querySelector('[name="matcher_type"]')?.value || "path";
  const lines = (name) => wafTextLines(form.querySelector(`[name="${name}"]`)?.value || "");
  if (type === "ip") return { type, paths: lines("matcher_paths"), allow: lines("matcher_allow"), deny: lines("matcher_deny") };
  if (type === "path") return { type, values: lines("matcher_values") };
  if (type === "method") return { type, deny: lines("matcher_deny").map((value) => value.toUpperCase()) };
  if (type === "query") return { type, keys: lines("matcher_keys"), patterns: lines("matcher_patterns") };
  if (type === "header") {
    const action = form.querySelector('[name="action"]')?.value || "block";
    return { type, headers: action === "remove_header" ? lines("matcher_headers").map((value) => value.split(":")[0].trim()) : wafHeaderMap(lines("matcher_headers")) };
  }
  if (type === "body_size") return { type, max_bytes: Number(form.querySelector('[name="matcher_max_bytes"]')?.value || 0) };
  return { type: "security_headers", headers: wafHeaderMap(lines("matcher_headers")) };
}

function wafRulePayloadFromEditor(form, basicFields) {
  return { ...readFields(form, basicFields), matcher: wafMatcherFromEditor(form) };
}

function wafTextLines(value) {
  return String(value || "").split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
}

function wafListText(value) {
  return Array.isArray(value) ? value.join("\n") : "";
}

function wafHeaderText(headers) {
  if (Array.isArray(headers)) return headers.join("\n");
  if (!headers || typeof headers !== "object") return "";
  return Object.entries(headers).map(([name, value]) => `${name}: ${value}`).join("\n");
}

function wafHeaderMap(lines) {
  const headers = {};
  for (const line of lines) {
    const separator = line.indexOf(":");
    if (separator < 1) headers[line] = "*";
    else headers[line.slice(0, separator).trim()] = line.slice(separator + 1).trim();
  }
  return headers;
}

function renderTableCell(resourceKey, column, row, context) {
  if (resourceKey === "admin-users" && column === "department_id") {
    return apiKeyDepartmentLabel(row[column], context);
  }
  if (resourceKey === "admin-users" && column === "permissions") {
    return adminUserPermissionLabels(row[column]);
  }
  if (resourceKey === "admin-users" && column === "managed_department_ids") {
    return adminUserDepartmentLabels(row[column], context);
  }
  if (resourceKey === "api-keys" && column === "user_id") {
    return apiKeyUserLabel(row[column], context);
  }
  if (resourceKey === "api-keys" && column === "department_id") {
    return apiKeyDepartmentLabel(row[column], context);
  }
  if (resourceKey === "api-keys" && column === "model_ids") {
    return apiKeyModelLabels(row[column], context);
  }
  if (resourceKey === "quota-bindings" && column === "quota_policy_id") {
    return quotaPolicyLabel(row[column], context);
  }
  if (resourceKey === "quota-bindings" && column === "target_id") {
    return quotaTargetLabel(row.target_type, row[column], context);
  }
  if (resourceKey === "api-key-model-quotas" && column === "api_key_id") {
    return modelQuotaApiKeyLabel(row[column], context);
  }
  if (resourceKey === "api-key-model-quotas" && column === "model_id") {
    return modelQuotaModelLabel(row[column], context);
  }
  if (resourceKey === "models" && column === "provider_name") {
    return row.provider_name || formatResourceValue("provider_id", row.provider_id);
  }
  if (resourceKey === "model-prices" && column === "price_categories") {
    return renderPriceCategoryChips(row, context);
  }
  if (resourceKey === "model-prices" && column === "price_summary") {
    return modelPriceSummary(row);
  }
  return formatResourceValue(column, row[column]);
}

function operationSuccessText(data = {}, fallback = "\u64cd\u4f5c\u5df2\u5b8c\u6210") {
  const parts = [];
  for (const [key, label] of [
    ["saved", "\u4fdd\u5b58"],
    ["created", "\u65b0\u589e"],
    ["updated", "\u66f4\u65b0"],
    ["deleted", "\u5220\u9664"],
    ["revoked", "\u64a4\u9500"],
    ["queued", "\u5df2\u5165\u961f"]
  ]) {
    if (Number.isFinite(Number(data?.[key]))) {
      parts.push(`${label} ${Number(data[key])} \u9879`);
    }
  }
  if (parts.length > 0) {
    return parts.join("\uff0c");
  }
  if (data?.export_id) {
    return `\u5bfc\u51fa\u4efb\u52a1\u5df2\u521b\u5efa\uff1a${data.export_id}`;
  }
  if (data?.status && typeof data.status === "string") {
    return `\u64cd\u4f5c\u5df2\u5b8c\u6210\uff0c\u72b6\u6001\uff1a${valueLabel("status", data.status) || data.status}`;
  }
  return fallback;
}

function showOperationResult(title, data, fallback) {
  showModal(title, statusMessage(operationSuccessText(data, fallback)));
}

function setOperationOutput(output, data, fallback) {
  setText(output, operationSuccessText(data, fallback));
  output.hidden = false;
}

function setErrorOutput(output, text) {
  setText(output, text);
  output.hidden = false;
}

function oneTimeSecretText(data = {}) {
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

function renderDataDetails(data) {
  const rows = Array.isArray(data) ? data : [data];
  if (rows.length === 0) {
    return statusMessage("\u6682\u65e0\u8be6\u60c5");
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

function showApiKeyDetails(row, config, context = {}) {
  const columns = config.detailColumns || config.columns || Object.keys(row);
  const detailRows = columns.map((column) => el("div", { className: "api-key-detail-row" }, [
    el("span", { text: columnLabel(column) }),
    el("strong", { text: renderTableCell("api-keys", column, row, context) || "-" })
  ]));
  showModal(`API 密钥 #${row.id || ""}`, el("div", { className: "api-key-detail-modal" }, [
    el("div", { className: "api-key-detail-summary" }, [
      el("strong", { text: row.name || `ID ${row.id || ""}` }),
      el("span", { text: `用户 ${apiKeyUserLabel(row.user_id, context) || "-"} / 部门 ${apiKeyDepartmentLabel(row.department_id, context) || "-"}` }),
      el("span", { text: `状态${formatResourceValue("status", row.status) || "-"}` })
    ]),
    el("div", { className: "api-key-detail-grid" }, detailRows)
  ]));
}

function renderPriceCategoryChips(row, context) {
  return el("div", { className: "price-category-chips" }, modelPriceCategoryLabels(row, context)
    .map((label) => el("span", { className: "price-category-chip", text: label })));
}

function modelPriceCategoryLabels(row, context) {
  const labels = new Set();
  const model = context.modelsById?.get(Number(row.model_id));
  const capabilityLabel = MODEL_CAPABILITY_CATEGORY_LABELS[model?.capability];
  if (capabilityLabel) {
    labels.add(capabilityLabel);
  }
  for (const category of MODEL_PRICE_CATEGORIES) {
    if (configuredPriceFields(row, category).length > 0) {
      labels.add(category.label);
    }
  }
  return labels.size > 0 ? [...labels] : ["未配置"];
}

function modelPriceSummary(row) {
  const parts = MODEL_PRICE_CATEGORIES
    .map((category) => {
      const count = configuredPriceFields(row, category).length;
      return count > 0 ? `${category.label} ${count} 项` : null;
    })
    .filter(Boolean);
  return parts.length > 0 ? parts.join(" / ") : "未配置价格";
}

function configuredPriceFields(row, category) {
  return category.fields.filter((fieldName) => hasPriceValue(row[fieldName]));
}

function hasPriceValue(value) {
  return value !== null && value !== undefined && String(value) !== "";
}

function showModelPriceDetails(row, context) {
  const model = context.modelsById?.get(Number(row.model_id));
  const modelTitle = model?.name ? `${model.name} / ID ${row.model_id}` : `模型 ID ${row.model_id}`;
  const cards = MODEL_PRICE_CATEGORIES.map((category) => {
    const activeFields = configuredPriceFields(row, category);
    const rows = category.fields.map((fieldName) => el("div", { className: "price-detail-row" }, [
      el("span", { text: columnLabel(fieldName) }),
      el("strong", { text: hasPriceValue(row[fieldName]) ? row[fieldName] : "未配置" })
    ]));
    return el("section", { className: `price-category-card ${activeFields.length > 0 ? "active" : ""}` }, [
      el("header", {}, [
        el("h3", { text: category.label }),
        el("span", { text: activeFields.length > 0 ? `${activeFields.length} 项` : "未配置" })
      ]),
      el("div", { className: "price-detail-list" }, rows)
    ]);
  });
  showModal("价格明细", el("div", { className: "price-detail-modal" }, [
    el("div", { className: "price-detail-summary" }, [
      el("strong", { text: modelTitle }),
      el("span", { text: `模态类型：${modelPriceCategoryLabels(row, context).join("、")}` }),
      el("span", { text: `币种：${row.currency || ""}` }),
      el("span", { text: `生效时间：${row.effective_at || ""}` })
    ]),
    el("div", { className: "price-category-grid" }, cards)
  ]));
}

async function showProviderModelImportLegacy(row, onRefresh) {
  const output = el("p", { className: "notice", hidden: true });
  const list = el("div", { className: "provider-model-import-list" }, [
    statusMessage("正在拉取模型...")
  ]);
  const actions = el("div", { className: "toolbar-actions" });
  showModal("拉取模型", el("div", { className: "provider-model-import-modal" }, [
    list,
    actions,
    output
  ]));

  try {
    const result = await api(`/admin-api/providers/${row.id}/models`);
    const models = Array.isArray(result.data) ? result.data : [];
    clear(list);
    if (models.length === 0) {
      list.append(statusMessage("未拉取到模型"));
      return;
    }
    for (const model of models) {
      const checkbox = el("input", { type: "checkbox", checked: true });
      const item = el("label", { className: "provider-model-import-item" }, [
        checkbox,
        el("span", { text: model.name || model.external_name || "" }),
        el("small", { text: `${model.capability || "text_generation"} / ${model.context_length || "-"}` })
      ]);
      item.__model = model;
      list.append(item);
    }
    actions.append(button("导入已选模型", async () => {
      const selected = [...list.querySelectorAll(".provider-model-import-item")]
        .filter((item) => item.querySelector("input")?.checked)
        .map((item) => item.__model)
        .filter(Boolean);
      const saved = await api(`/admin-api/providers/${row.id}/models`, {
        method: "POST",
        body: JSON.stringify({ models: selected })
      });
      setOperationOutput(output, saved.data, "\u6a21\u578b\u5df2\u4fdd\u5b58\u5230\u6a21\u578b\u7ba1\u7406");
      await onRefresh();
    }, "secondary"));
  } catch (error) {
    clear(list);
    list.append(statusMessage(adminErrorMessage(error, "拉取模型失败"), "error"));
  }
}

async function showCreateModal(resourceKey, config, onSaved, initialValues = {}) {
  const formFields = await lookupAwareFields(config.form);
  const form = resourceForm(formFields, initialValues, resourceKey);
  const output = el("p", { className: `notice ${config.oneTimeSecret ? "secret-output" : ""}`.trim(), hidden: true });
  appendVectorConfigProbe(resourceKey, form, formFields, output);
  form.append(button(config.oneTimeSecret ? "创建并显示一次明文" : "保存", async () => {
    try {
      const result = await api(`/admin-api/${resourceKey}`, {
        method: "POST",
        body: JSON.stringify(resourcePayload(resourceKey, readFields(form, formFields)))
      });
      if (config.oneTimeSecret) {
        setText(output, oneTimeSecretText(result.data));
        output.hidden = false;
      } else {
        setOperationOutput(output, result.data, "\u65b0\u589e\u5df2\u4fdd\u5b58");
      }
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "新增失败，请检查输入内容"));
    }
  }));
  form.append(output);
  showModal(`新增 ${config.label}`, form);
}

async function showEditModal(resourceKey, config, row, onSaved) {
  const formFields = await lookupAwareFields(config.form);
  const form = resourceForm(formFields, row, resourceKey);
  const output = el("p", { className: "notice", hidden: true });
  appendVectorConfigProbe(resourceKey, form, formFields, output);
  form.append(button("保存", async () => {
    try {
      const values = readFields(form, formFields);
      const result = await api(`/admin-api/${resourceKey}/${row.id}`, {
        method: "PUT",
        body: JSON.stringify(resourcePayload(resourceKey, values))
      });
      if (resourceKey === "api-keys") {
        const modelIds = Array.isArray(values.model_ids)
          ? values.model_ids.map(Number).filter((id) => Number.isFinite(id) && id > 0)
          : [];
        await api(`/admin-api/api-keys/${row.id}/models`, {
          method: "PUT",
          body: JSON.stringify({ model_ids: modelIds })
        });
      }
      setOperationOutput(output, result.data, "\u4fdd\u5b58\u6210\u529f");
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "保存失败，请检查输入内容"));
    }
  }));
  form.append(output);
  showModal(`编辑 ${config.label}`, form);
}

async function showApiKeyRagBindings(row, onSaved) {
  const output = el("p", { className: "notice", hidden: true });
  const body = el("div", { className: "rag-api-key-bindings" }, [statusMessage("正在加载知识库权限..."), output]);
  showModal(`知识库权限 · ${row.name || row.id}`, body);
  try {
    const [knowledgeBasesResult, datasetsResult, bindingsResult] = await Promise.all([
      api("/admin-api/rag-knowledge-bases", { query: { limit: 500 } }),
      api("/admin-api/rag-vector-datasets", { query: { limit: 500 } }),
      api(`/admin-api/api-keys/${row.id}/rag-bindings`)
    ]);
    const knowledgeBases = Array.isArray(knowledgeBasesResult.data) ? knowledgeBasesResult.data : knowledgeBasesResult.data?.items || [];
    const datasets = Array.isArray(datasetsResult.data) ? datasetsResult.data : datasetsResult.data?.items || [];
    const current = bindingsResult.data || {};
    const selectedKnowledgeBases = new Set((current.knowledge_base_ids || []).map(Number));
    const selectedDatasets = new Set((current.dataset_ids || []).map(Number));
    const section = (title, items, selected, itemLabel) => el("section", { className: "rag-api-key-binding-section" }, [
      el("h3", { text: title }),
      el("div", { className: "rag-api-key-binding-list" }, items.map((item) => {
        const input = el("input", { type: "checkbox", value: String(item.id), checked: selected.has(Number(item.id)) });
        return el("label", { className: "rag-api-key-binding-item" }, [input, el("span", { text: itemLabel(item) })]);
      }))
    ]);
    const knowledgeBaseSection = section("知识库（授予后同步所有数据集）", knowledgeBases, selectedKnowledgeBases, (item) => item.name || item.kb_id || `知识库 #${item.id}`);
    const datasetSection = section("数据集（会同时授予所属知识库）", datasets, selectedDatasets, (item) => item.name || item.dataset_id || `数据集 #${item.id}`);
    const save = button("保存知识库权限", async () => {
      const values = (sectionElement) => [...sectionElement.querySelectorAll('input[type="checkbox"]:checked')].map((input) => Number(input.value)).filter(Number.isFinite);
      try {
        await api(`/admin-api/api-keys/${row.id}/rag-bindings`, {
          method: "PUT",
          body: JSON.stringify({ knowledge_base_ids: values(knowledgeBaseSection), dataset_ids: values(datasetSection) })
        });
        setText(output, "知识库权限已保存");
        output.hidden = false;
        await onSaved();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, "知识库权限保存失败"));
      }
    });
    body.replaceChildren(knowledgeBaseSection, datasetSection, save, output);
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "知识库权限加载失败"), "error"));
  }
}

function appendVectorConfigProbe(resourceKey, form, fields, output) {
  if (resourceKey !== "rag-vector-configs") {
    return;
  }
  form.append(button("测试向量模型", async () => {
    try {
      const result = await api("/admin-api/rag-vector-configs/test", {
        method: "POST",
        body: JSON.stringify(resourcePayload(resourceKey, readFields(form, fields)))
      });
      const probe = result.data || {};
      setOperationOutput(output, probe, `测试成功：${probe.model_name || "向量模型"}，维度 ${probe.dimension || "-"}`);
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "向量模型测试失败"));
    }
  }, "secondary"));
}

function resourcePayload(resourceKey, values) {
  if (resourceKey !== "quota-bindings" || !values.target_ref) {
    return values;
  }
  const [targetType, rawTargetId] = String(values.target_ref).split(":");
  return {
    ...values,
    target_type: targetType,
    target_id: Number(rawTargetId),
    target_ref: undefined
  };
}

async function showProviderModelImport(row, onSaved) {
  const output = el("p", { className: "notice", hidden: true });
  const body = el("div", { className: "provider-model-import" }, [
    statusMessage("正在拉取模型列表...")
  ]);
  showModal(`拉取模型 - ${row.name || row.id}`, body);

  try {
    const result = await api(`/admin-api/providers/${row.id}/models`);
    const models = Array.isArray(result.data) ? result.data : [];
    if (models.length === 0) {
      body.replaceChildren(statusMessage("未从该厂商拉取到模型", "error"));
      return;
    }

    const list = el("div", { className: "provider-model-import-list" });
    models.forEach((model, index) => {
      const checkbox = el("input", { type: "checkbox", value: String(index), checked: true });
      list.append(el("label", { className: "provider-model-import-item" }, [
        checkbox,
        el("span", { text: model.name || model.external_name }),
        el("small", { text: model.capability || "text_generation" })
      ]));
    });

    body.replaceChildren(
      el("div", { className: "provider-model-import-header" }, [
        el("strong", { text: "选择模型" }),
        el("span", { text: `${models.length} 个可导入模型` })
      ]),
      list,
      el("div", { className: "toolbar-actions" }, [
        button("全选", () => {
          list.querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.checked = true;
          });
        }, "secondary"),
        button("保存到模型管理", async () => {
          const selected = [...list.querySelectorAll('input[type="checkbox"]:checked')]
            .map((input) => models[Number(input.value)])
            .filter(Boolean);
          if (selected.length === 0) {
            setText(output, "请选择至少一个模型");
            output.hidden = false;
            return;
          }
          try {
            const saved = await api(`/admin-api/providers/${row.id}/models`, {
              method: "POST",
              body: JSON.stringify({ models: selected })
            });
            setOperationOutput(output, saved.data, "\u6a21\u578b\u5df2\u4fdd\u5b58\u5230\u6a21\u578b\u7ba1\u7406");
            await onSaved();
          } catch (error) {
            setErrorOutput(output, adminErrorMessage(error, "保存模型失败，请检查厂商配置"));
          }
        })
      ]),
      output
    );
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "拉取模型失败，请检查厂商基础地址和密钥"), "error"));
  }
}

async function showModelProviderImport(onSaved) {
  const body = el("div", { className: "provider-model-import" }, [
    statusMessage("正在加载厂商列表...")
  ]);
  showModal("拉取模型", body);

  try {
    const result = await api("/admin-api/providers", { query: { limit: 500 } });
    const providers = (Array.isArray(result.data) ? result.data : []).filter((provider) => provider.id);
    if (providers.length === 0) {
      body.replaceChildren(statusMessage("请先添加可用厂商", "error"));
      return;
    }

    const selector = el("select", { className: "provider-model-import-provider", name: "provider_id" });
    providers.forEach((provider) => {
      const status = provider.status ? ` (${provider.status})` : "";
      selector.append(el("option", {
        value: String(provider.id),
        text: `${provider.name || provider.code || provider.id}${status}`
      }));
    });

    body.replaceChildren(
      el("label", { className: "field" }, [
        el("span", { text: "选择厂商" }),
        selector
      ]),
      el("div", { className: "toolbar-actions" }, [
        button("拉取模型", () => {
          const provider = providers.find((item) => String(item.id) === selector.value);
          if (provider) {
            showProviderModelImport(provider, onSaved);
          }
        })
      ])
    );
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "加载厂商失败"), "error"));
  }
}

function resourceForm(fields, row = {}, resourceKey = "") {
  const form = el("form", { className: "edit-form", onSubmit: (event) => event.preventDefault() });
  for (const config of fields) {
    let value = row[config.name] ?? "";
    if (resourceKey === "quota-bindings" && config.name === "target_ref") {
      value = quotaTargetRef(row);
    }
    if (config.type === "cidr-list" && value) {
      if (typeof value === "string") {
        const trimmed = value.trim();
        if (trimmed.startsWith("[")) {
          try {
            const parsed = JSON.parse(trimmed);
            value = Array.isArray(parsed) ? parsed.join("\n") : String(value);
          } catch (_error) {
            // keep raw string when not valid JSON
          }
        }
      } else {
        value = Array.isArray(value) ? value.join("\n") : String(value);
      }
    } else if (config.type === "json" && value && typeof value !== "string") {
      value = JSON.stringify(value);
    }
    form.append(field(config, value));
  }
  if (resourceKey === "providers") {
    form.prepend(renderProviderPresetPicker(form));
    bindProviderBaseUrlDefaults(form);
  }
  if (resourceKey === "api-keys") {
    bindApiKeyDepartmentUserPicker(form, fields);
  }
  return form;
}

function bindApiKeyDepartmentUserPicker(form, fields) {
  const departmentSelect = form.querySelector('select[name="department_id"]');
  const userSelect = form.querySelector('select[name="user_id"]');
  const userField = userSelect?.closest("label");
  if (!departmentSelect || !userSelect || !userField) {
    return;
  }
  const userDepartmentIds = fields.find((config) => config.name === "user_id")?.userDepartmentIds || {};
  const searchInput = el("input", {
    type: "search",
    className: "api-key-user-picker-input",
    placeholder: "请先选择部门",
    disabled: true,
    autocomplete: "off"
  });
  const results = el("div", { className: "api-key-user-picker-results", hidden: true });
  const picker = el("div", { className: "api-key-user-picker" }, [
    searchInput,
    results
  ]);
  userSelect.hidden = true;
  userField.append(picker);

  const matchingUsers = () => {
    const departmentId = departmentSelect.value;
    const query = searchInput.value.trim().toLowerCase();
    return [...userSelect.options].filter((option) => {
      const matchesDepartment = departmentId !== "" && String(userDepartmentIds[option.value] ?? "") === departmentId;
      const matchesQuery = query === "" || (option.textContent || "").toLowerCase().includes(query);
      return matchesDepartment && matchesQuery;
    });
  };
  const renderResults = () => {
    clear(results);
    if (departmentSelect.value === "") {
      results.hidden = true;
      return;
    }
    const matches = matchingUsers();
    if (matches.length === 0) {
      results.append(el("p", { className: "api-key-user-picker-empty", text: "该部门暂无匹配员工" }));
    } else {
      for (const option of matches) {
        results.append(button(option.textContent || `员工 #${option.value}`, () => {
          userSelect.value = option.value;
          searchInput.value = option.textContent || "";
          results.hidden = true;
        }, "secondary api-key-user-picker-option"));
      }
    }
    results.hidden = false;
  };
  const clearEmployee = () => {
    userSelect.value = "";
    searchInput.value = "";
    searchInput.disabled = departmentSelect.value === "";
    searchInput.placeholder = departmentSelect.value === "" ? "请先选择部门" : "搜索用户名、角色或状态";
    results.hidden = true;
  };
  departmentSelect.addEventListener("change", clearEmployee);
  searchInput.addEventListener("input", () => {
    userSelect.value = "";
    renderResults();
  });
  searchInput.addEventListener("focus", renderResults);

  const selected = userSelect.selectedOptions[0];
  if (departmentSelect.value !== "" && selected && String(userDepartmentIds[selected.value] ?? "") === departmentSelect.value) {
    searchInput.value = selected.textContent || "";
  }
  searchInput.disabled = departmentSelect.value === "";
  searchInput.placeholder = departmentSelect.value === "" ? "请先选择部门" : "搜索用户名、角色或状态";
}

function renderProviderPresetPicker(form) {
  const nameInput = form.querySelector('[name="name"]');
  const protocolInput = form.querySelector('[name="protocol"]');
  const baseUrlInput = form.querySelector('[name="base_url"]');
  const picker = el("section", { className: "provider-preset-picker" }, [
    el("span", { className: "provider-preset-title", text: "\u5e38\u7528\u5382\u5546" })
  ]);
  for (const preset of providerDisplayNamePresets) {
    picker.append(button(preset.name, () => {
      if (nameInput) {
        nameInput.value = preset.name;
      }
      if (protocolInput) {
        protocolInput.value = preset.protocol;
      }
      if (baseUrlInput) {
        baseUrlInput.value = "";
      }
      nameInput?.dispatchEvent(new Event("input", { bubbles: true }));
      if (baseUrlInput && baseUrlInput.value === "") {
        baseUrlInput.value = preset.baseUrl;
      }
    }, "secondary provider-preset-button"));
  }
  return picker;
}

function bindProviderBaseUrlDefaults(form) {
  const nameInput = form.querySelector('[name="name"]');
  const protocolInput = form.querySelector('[name="protocol"]');
  const baseUrlInput = form.querySelector('[name="base_url"]');
  if (!nameInput || !baseUrlInput) {
    return;
  }
  if (baseUrlInput.value.trim() !== "" && !providerLooksLikeUrl(baseUrlInput.value)) {
    baseUrlInput.value = "";
  }
  let lastSuggestedBaseUrl = providerBaseUrlForName(nameInput.value, protocolInput?.value) || "";
  const applyPreset = () => {
    const nextBaseUrl = providerBaseUrlForName(nameInput.value, protocolInput?.value);
    if (!nextBaseUrl) {
      return;
    }
    const currentBaseUrl = baseUrlInput.value.trim();
    if (currentBaseUrl === "" || currentBaseUrl === lastSuggestedBaseUrl) {
      baseUrlInput.value = nextBaseUrl;
      lastSuggestedBaseUrl = nextBaseUrl;
    }
  };
  nameInput.addEventListener("input", applyPreset);
  protocolInput?.addEventListener("change", applyPreset);
}

function providerLooksLikeUrl(value) {
  return /^https?:\/\//i.test(String(value || "").trim());
}

function providerBaseUrlForName(name, protocol = "openai") {
  const normalizedName = String(name || "").trim().toLowerCase();
  if (normalizedName === "") {
    return "";
  }
  const match = providerNameAliases.find(([alias]) => normalizedName.includes(alias));
  const providerKey = match?.[1];
  if (!providerKey) {
    return "";
  }
  if (protocol === "anthropic" && providerAnthropicBaseUrlPresets[providerKey]) {
    return providerAnthropicBaseUrlPresets[providerKey];
  }
  return providerBaseUrlPresets[providerKey] || "";
}

async function showUsageContent(row) {
  const result = await api("/admin-api/usage-contents", { query: { limit: 1, usage_record_id: row.id } }).catch((error) => ({ data: [{ error: adminErrorMessage(error, "内容加载失败") }] }));
  showModal("内容留存", renderDataDetails(result.data));
}

function showShareLinkPasswordResetResult(data) {
  const password = data.password || "";
  showModal("重置密码完成", el("div", {}, [
    statusMessage("密码已重置，随机密码如下。"),
    el("pre", { className: "secret-output", text: password || "接口未返回新密码，请重新重置。" })
  ]));
}

function showAllowClientIp(row, onSaved) {
  const fields = [{ name: "client_ip", label: "允许访问的 IP" }];
  const form = resourceForm(fields, { client_ip: row.client_ip || "" });
  form.append(button("放行 IP", async () => {
    try {
      await api(`/admin-api/dashboard-share-links/${row.id}/allow-client-ip`, {
        method: "POST",
        body: JSON.stringify(readFields(form, fields))
      });
      await onSaved();
    } catch (error) {
      window.alert(adminErrorMessage(error, "添加允许 IP 失败"));
    }
  }));
  showModal(`放行大屏访问 IP #${row.id}`, form);
}

function userApiBase(resourceKey = "users") {
  return resourceKey === "admin-users" ? "/admin-api/admin-users" : "/admin-api/users";
}

function showUserPasswordReset(row, onSaved, resourceKey = "users") {
  const fields = [{ name: "password", label: "新密码", type: "password" }];
  const form = resourceForm(fields);
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("重置密码", async () => {
    try {
      const result = await api(`${userApiBase(resourceKey)}/${row.id}/reset-password`, {
        method: "POST",
        body: JSON.stringify(readFields(form, fields))
      });
      setOperationOutput(output, result.data, "\u5bc6\u7801\u5df2\u91cd\u7f6e");
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "移动部门失败"));
    }
  }));
  form.append(output);
  showModal(`重置用户密码 #${row.id}`, form);
}

async function showAdminUserDepartmentGrant(row, onSaved) {
  const fields = [{
    name: "managed_department_ids",
    label: "授权管理部门",
    type: "multiselect",
    coerce: "number",
    options: await departmentSelectOptions(),
    optional: true,
    size: 8
  }];
  const form = resourceForm(fields, row);
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("保存授权", async () => {
    try {
      const result = await api(`/admin-api/admin-users/${row.id}`, {
        method: "PUT",
        body: JSON.stringify(readFields(form, fields))
      });
      setOperationOutput(output, result.data, "\u7ba1\u7406\u5458\u6388\u6743\u5df2\u66f4\u65b0");
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "保存授权失败"));
    }
  }));
  form.append(output);
  showModal(`管理员授权部门 #${row.id}`, form);
}

function showAdminUserSessionRevoke(row, onSaved) {
  const body = el("div", {}, [
    statusMessage(`确认撤销管理员 #${row.id} 的所有登录会话？`),
    button("撤销会话", async () => {
      const result = await api(`/admin-api/admin-users/${row.id}/revoke-sessions`, { method: "POST" });
      showOperationResult("撤销管理员会话", result.data, "\u4f1a\u8bdd\u5df2\u64a4\u9500");
      await onSaved();
    }, "danger")
  ]);
  showModal(`撤销管理员会话 #${row.id}`, body);
}

function flattenDepartmentOptions(rows, depth = 0) {
  return rows.flatMap((department) => {
    const label = `${"  ".repeat(depth)}${department.name || `#${department.id}`} (#${department.id})`;
    return [[String(department.id), label], ...flattenDepartmentOptions(department.children || [], depth + 1)];
  });
}

async function departmentSelectOptions() {
  const result = await api("/admin-api/departments/tree");
  return flattenDepartmentOptions(result.data || []);
}

async function departmentAwareFields(fields) {
  const needsDepartmentOptions = fields.some((config) => config.name === "department_id" && config.type === "number");
  if (!needsDepartmentOptions) {
    return fields;
  }
  const options = await departmentSelectOptions();
  return fields.map((config) => {
    if (config.name !== "department_id" || config.type !== "number") {
      return config;
    }
    return {
      ...config,
      type: "select",
      coerce: "number",
      options: config.optional ? [["", "不选择部门"], ...options] : options
    };
  });
}

async function lookupAwareFields(fields) {
  const departmentFields = await departmentAwareFields(fields);
  const references = new Set(departmentFields.map((config) => config.reference).filter(Boolean));
  if (references.size === 0) {
    return departmentFields;
  }
  let context;
  if (references.has("departments") || references.has("users")) {
    context = await apiKeyReferenceContext();
  } else if (references.has("quotaPolicies") || references.has("quotaTargets")) {
    context = await quotaBindingReferenceContext();
  } else if (references.has("knowledgeBases")) {
    context = { knowledgeBases: await lookupRows("rag-knowledge-bases") };
  } else {
    context = await modelQuotaReferenceContext();
  }
  if (references.has("providers")) {
    context.providers = await lookupRows("providers");
  }
  if (references.has("vectorConfigs")) {
    context.vectorConfigs = await lookupRows("rag-vector-configs");
  }
  return departmentFields.map((config) => {
    if (!config.reference) {
      return config;
    }
    const options = referenceOptions(config.reference, context);
    const emptyOptions = config.emptyOption
      ? [["", config.emptyOption]]
      : config.optional && config.type !== "multiselect" ? [["", ""]] : [];
    const resolved = {
      ...config,
      type: config.type === "multiselect" ? "multiselect" : "select",
      coerce: config.coerce,
      options: [...emptyOptions, ...options]
    };
    if (config.reference === "users") {
      resolved.userDepartmentIds = Object.fromEntries((context.users || []).map((user) => [String(user.id), String(user.department_id ?? "")]));
    }
    return resolved;
  });
}

async function showUserDepartmentMove(row, onSaved) {
  const fields = [{ name: "department_id", label: columnLabel("department_id"), type: "select", coerce: "number", options: await departmentSelectOptions() }];
  const form = resourceForm(fields, row);
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("移动部门", async () => {
    try {
      const values = readFields(form, fields);
      const payload = { ...values, department_id: Number(values.department_id) };
      const result = await api(`/admin-api/users/${row.id}/department`, {
        method: "POST",
        body: JSON.stringify(payload)
      });
      setOperationOutput(output, result.data, "\u7528\u6237\u90e8\u95e8\u5df2\u66f4\u65b0");
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "更新状态失败"));
    }
  }));
  form.append(output);
  showModal(`移动用户部门 #${row.id}`, form);
}

function showUserStatusUpdate(row, onSaved, resourceKey = "users") {
  const fields = [{ name: "status", label: "状态", type: "select", options: ["active", "disabled", "departed"].map((value) => [value, valueLabel("status", value)]) }];
  const form = resourceForm(fields, row);
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("更新状态", async () => {
    try {
      const result = await api(resourceKey === "admin-users" ? `/admin-api/admin-users/${row.id}` : `/admin-api/users/${row.id}/status`, {
        method: resourceKey === "admin-users" ? "PUT" : "PATCH",
        body: JSON.stringify(readFields(form, fields))
      });
      setOperationOutput(output, result.data, "\u7528\u6237\u72b6\u6001\u5df2\u66f4\u65b0");
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "添加允许 IP 失败"));
    }
  }));
  form.append(output);
  showModal(`更新用户状态 #${row.id}`, form);
}

export async function renderContentArchivePage(content, initialFilters = {}) {
  clear(content);
  const filters = [
    { name: "user_id", label: "用户 ID", type: "number", optional: true },
    { name: "department_id", label: "部门 ID", type: "number", optional: true },
    { name: "api_key_id", label: "API 密钥 ID", type: "number", optional: true },
    { name: "model_id", label: "模型 ID", type: "number", optional: true },
    { name: "session_id", label: "会话 ID", optional: true },
    { name: "from", label: "开始时间", optional: true },
    { name: "to", label: "结束时间", optional: true }
  ];
  const state = { includeRaw: false };
  const form = el("form", { className: "filters", onSubmit: (event) => {
    event.preventDefault();
    load();
  } });
  for (const config of filters) {
    form.append(field(config, initialFilters[config.name] || ""));
  }
  form.append(el("label", { className: "field" }, [
    el("span", { text: "包含原文" }),
    el("select", { name: "include_raw" }, [
      el("option", { value: "false", text: "否" }),
      el("option", { value: "true", text: "是" })
    ])
  ]));
  const tablePanel = el("section", { className: "panel table-panel" });
  form.append(button("查询", () => load()));
  form.append(button("导出", async () => {
    const result = await api("/admin-api/exports/session_archive", {
      method: "POST",
      body: JSON.stringify(contentArchiveQuery(form, filters, state))
    });
    showOperationResult("\u5bfc\u51fa\u4efb\u52a1", result.data, "\u5bfc\u51fa\u4efb\u52a1\u5df2\u521b\u5efa\uff0c\u53ef\u5728\u5bfc\u51fa\u4efb\u52a1\u5217\u8868\u67e5\u770b");
  }, "secondary"));
  content.append(form, tablePanel);
  await load();

  async function load() {
    clear(tablePanel);
    const result = await api("/admin-api/content-archive", { query: contentArchiveQuery(form, filters, state) }).catch((error) => ({
      data: [{ message: adminErrorMessage(error, "会话存档加载失败"), request_id: error.requestId }],
      requestId: error.requestId
    }));
    document.getElementById("request-id").textContent = result.requestId || "";
    const rows = Array.isArray(result.data) ? result.data : [];
    if (rows.length === 0) {
      tablePanel.append(statusMessage("暂无会话存档"));
      return;
    }
    tablePanel.append(renderContentArchiveTable(rows, state.includeRaw));
  }
}

export async function renderWafEventsPage(content, initialFilters = {}) {
  clear(content);
  const filters = [
    { name: "from", label: "开始时间", optional: true },
    { name: "to", label: "结束时间", optional: true },
    { name: "rule_code", label: "规则编码", optional: true },
    { name: "client_ip", label: "来源 IP", optional: true },
    { name: "action", label: "动作", type: "select", optional: true, options: [["", "全部"], ...["block", "log", "allow", "redact", "remove_header"].map((value) => [value, valueLabel("action", value)])] },
    { name: "method", label: "方法", optional: true },
    { name: "path", label: "路径关键字", optional: true },
    { name: "bucket", label: "趋势粒度", type: "select", options: [["hour", "小时"], ["day", "天"]] }
  ];
  const form = el("form", { className: "filters", onSubmit: (event) => {
    event.preventDefault();
    load();
  } });
  for (const config of filters) {
    form.append(field(config, initialFilters[config.name] || (config.name === "bucket" ? "hour" : "")));
  }
  const statisticsPanel = el("section", { className: "waf-overview stack" });
  const recordsPanel = el("section", { className: "panel table-panel waf-attack-list" });
  form.append(button("筛选", () => load()));
  form.append(button("重置", () => {
    for (const control of form.querySelectorAll("input,select,textarea")) {
      control.value = control.name === "bucket" ? "hour" : "";
    }
    load();
  }, "secondary"));
  form.append(button("自动处置", () => showWafAutoDispositionDialog(load), "secondary"));
  content.append(form, statisticsPanel, recordsPanel);
  await load();

  async function load() {
    clear(statisticsPanel);
    clear(recordsPanel);
    const query = readFields(form, filters);
    query.limit = 50;
    const [statisticsResult, eventsResult] = await Promise.all([
      api("/admin-api/waf/statistics", { query }),
      api("/admin-api/waf/events", { query })
    ]).catch((error) => {
      statisticsPanel.append(statusMessage(adminErrorMessage(error, "防火墙统计加载失败"), "error"));
      return [null, null];
    });
    if (!statisticsResult || !eventsResult) {
      return;
    }
    document.getElementById("request-id").textContent = eventsResult.requestId || statisticsResult.requestId || "";
    renderWafStatistics(statisticsPanel, statisticsResult.data || {});
    renderWafRecords(recordsPanel, eventsResult.data || {}, load);
  }
}

function renderWafStatistics(parent, data) {
  const summary = data.summary || {};
  parent.append(el("h2", { text: "WAF 概述" }));
  parent.append(el("section", { className: "metric-grid" }, [
    metric("攻击事件", summary.total_events || 0),
    metric("拦截事件", summary.blocked_events || 0),
    metric("来源 IP", summary.unique_ips || 0),
    metric("命中规则", summary.unique_rules || 0)
  ]));
  parent.append(el("div", { className: "waf-statistics-grid" }, [
    wafStatsPanel("趋势", data.trends || [], ["bucket_at", "event_count", "blocked_count"]),
    wafStatsPanel("规则排行", data.top_rules || [], ["rule_code", "event_count", "blocked_count", "last_seen_at"]),
    wafStatsPanel("来源 IP 排行", data.top_ips || [], ["client_ip", "event_count", "blocked_count", "last_seen_at"]),
    wafStatsPanel("路径排行", data.top_paths || [], ["path", "event_count", "blocked_count", "last_seen_at"])
  ]));
}

function renderWafRecords(parent, data, onRefresh) {
  const rows = data.records || [];
  const pagination = data.pagination || {};
  parent.append(el("h2", { text: `攻击列表 (${pagination.total || rows.length})` }));
  if (rows.length === 0) {
    parent.append(statusMessage("暂无防火墙攻击记录"));
    return;
  }
  parent.append(wafRecordsTable(rows, [
    "created_at",
    "rule_code",
    "client_ip",
    "method",
    "path",
    "action",
    "disposition_status",
    "false_positive",
    "risk_score",
    "request_id",
    "summary"
  ], onRefresh));
}

function wafRecordsTable(rows, columns, onRefresh) {
  const table = el("table");
  const headRow = el("tr");
  for (const label of [...columns.map(columnLabel), "操作"]) {
    headRow.append(el("th", { text: label }));
  }
  const body = el("tbody");
  for (const row of rows) {
    const tr = el("tr");
    for (const column of columns) {
      tr.append(el("td", { text: formatResourceValue(column, row[column]) }));
    }
    tr.append(el("td", { className: "row-actions" }, [
      button("已复核", () => showWafDispositionDialog(row, "review", onRefresh), "secondary"),
      button("误报", () => showWafDispositionDialog(row, "false_positive", onRefresh), "secondary"),
      button("封禁 IP", () => showWafDispositionDialog(row, "ban_ip", onRefresh), "danger"),
      button("解封 IP", () => showWafDispositionDialog(row, "unban_ip", onRefresh), "secondary")
    ]));
    body.append(tr);
  }
  table.append(el("thead", {}, [headRow]), body);
  return table;
}

async function showWafAutoDispositionDialog(onRefresh) {
  let threshold = 10;
  let windowMinutes = 5;
  try {
    const settings = await api("/admin-api/system/settings-center");
    const alertThresholds = settings.data?.sections?.alert_thresholds || {};
    threshold = Number(alertThresholds.waf_spike_count) || threshold;
    windowMinutes = Number(alertThresholds.waf_spike_window_minutes) || windowMinutes;
  } catch {
    // Keep safe defaults when settings cannot be loaded.
  }

  const form = el("form", { className: "edit-form waf-auto-disposition-dialog", onSubmit: (event) => event.preventDefault() });
  form.append(
    statusMessage("将扫描指定窗口内重复触发拦截的来源，并对达到阈值的 IP 执行系统级封禁。", "warning"),
    field({ name: "threshold", label: "触发封禁次数", type: "number" }, threshold),
    field({ name: "window_minutes", label: "统计窗口（分钟）", type: "number" }, windowMinutes)
  );
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("确认执行自动处置", async () => {
    const nextThreshold = Number(form.querySelector('[name="threshold"]')?.value || 0);
    const nextWindow = Number(form.querySelector('[name="window_minutes"]')?.value || 0);
    if (nextThreshold <= 0 || nextWindow <= 0) {
      setText(output, "封禁次数和统计窗口必须大于 0");
      output.className = "notice error";
      output.hidden = false;
      return;
    }
    if (!window.confirm(`确认扫描最近 ${nextWindow} 分钟，并封禁命中至少 ${nextThreshold} 次的来源 IP？`)) {
      return;
    }
    try {
      const result = await api("/admin-api/waf/auto-disposition", {
        method: "POST",
        body: JSON.stringify({ threshold: nextThreshold, window_minutes: nextWindow })
      });
      showModal("WAF 自动处置结果", renderWafAutoDispositionResult(result.data || {}));
      await onRefresh();
    } catch (error) {
      setText(output, adminErrorMessage(error, "WAF 自动处置失败"));
      output.className = "notice error";
      output.hidden = false;
    }
  }, "danger"), output);
  showModal("WAF 自动处置", form);
}

function renderWafAutoDispositionResult(data) {
  const banned = Array.isArray(data.banned) ? data.banned : [];
  return el("div", { className: "waf-auto-disposition-result stack" }, [
    el("div", { className: "data-detail-list" }, [
      ["检测阈值", data.threshold || 0],
      ["统计窗口", `${data.window_minutes || 0} 分钟`],
      ["封禁数量", data.banned_count || 0]
    ].map(([label, value]) => el("div", { className: "data-detail-row" }, [el("span", { text: label }), el("strong", { text: value })]))),
    banned.length ? el("div", { className: "stack" }, banned.map((row) => el("article", { className: "panel" }, [
      el("strong", { text: row.client_ip || "未知 IP" }),
      el("span", { text: `${row.hit_count || 0} 次命中` })
    ]))) : statusMessage("没有来源达到自动封禁阈值", "success")
  ]);
}

function showWafDispositionDialog(row, action, onRefresh) {
  const labels = {
    review: ["人工复核", "确认事件已完成安全复核。"],
    false_positive: ["标记误报", "将事件标记为误报并把风险分归零。"],
    ban_ip: ["封禁来源 IP", "来源 IP 将被系统级拒绝访问。"],
    unban_ip: ["解封来源 IP", "来源 IP 将恢复访问权限。"]
  };
  const [title, description] = labels[action] || ["处置 WAF 事件", "记录本次安全处置。"];
  const form = el("form", { className: "edit-form waf-disposition-dialog", onSubmit: (event) => event.preventDefault() });
  form.append(
    el("div", { className: "waf-disposition-summary" }, [
      el("strong", { text: row.rule_code || "未命名规则" }),
      el("span", { text: `${row.client_ip || "未知来源"} · ${row.path || "/"}` }),
      el("small", { text: description })
    ]),
    field({ name: "note", label: "处置说明", type: "textarea", rows: 4 }, row.disposition_note || "")
  );
  const output = el("p", { className: "notice", hidden: true });
  form.append(button(`确认${title}`, async () => {
    const note = form.querySelector('[name="note"]')?.value.trim() || "";
    if (!note) {
      setText(output, "请填写处置说明");
      output.className = "notice error";
      output.hidden = false;
      return;
    }
    if (["ban_ip", "unban_ip"].includes(action) && !window.confirm(`${description} 确认继续？`)) {
      return;
    }
    try {
      await api(`/admin-api/waf/events/${row.id}/handle`, {
        method: "POST",
        body: JSON.stringify({ action, note })
      });
      setText(output, `${title}已完成`);
      output.className = "notice success";
      output.hidden = false;
      await onRefresh();
    } catch (error) {
      setText(output, adminErrorMessage(error, "WAF 事件处置失败"));
      output.className = "notice error";
      output.hidden = false;
    }
  }, action === "ban_ip" ? "danger" : "secondary"), output);
  showModal(title, form);
}

function metric(name, value) {
  return el("article", { className: "metric" }, [
    el("span", { text: name }),
    el("strong", { text: value })
  ]);
}

function wafStatsPanel(title, rows, columns) {
  return el("section", { className: "panel dashboard-panel waf-statistics-panel" }, [
    el("h3", { text: title }),
    rows.length ? wafSimpleTable(rows, columns) : statusMessage("暂无数据")
  ]);
}

function wafSimpleTable(rows, columns) {
  const table = el("table");
  const headRow = el("tr");
  for (const label of columns.map(columnLabel)) {
    headRow.append(el("th", { text: label }));
  }
  const body = el("tbody");
  for (const row of rows) {
    const tr = el("tr");
    for (const column of columns) {
      tr.append(el("td", { text: formatResourceValue(column, row[column]) }));
    }
    body.append(tr);
  }
  table.append(el("thead", {}, [headRow]), body);
  return table;
}

export async function renderContentRetentionPolicyPage(content) {
  clear(content);
  const result = await api("/admin-api/system/content-retention");
  document.getElementById("request-id").textContent = result.requestId || "";
  const data = result.data || {};
  const fields = [
    { name: "mode", label: "默认模式", type: "select", options: [["summary", "只保存摘要"], ["redacted", "保存脱敏内容"], ["raw", "保存原文"], ["none", "不保存正文"]] },
    { name: "default_days", label: "默认保存天数", type: "number" },
    { name: "max_days", label: "最大保存天数", type: "number" },
    { name: "raw_allowed_roles", label: "原文角色，逗号分隔" },
    { name: "departments", label: "部门策略 JSON", type: "json", optional: true },
    { name: "users", label: "用户策略 JSON", type: "json", optional: true },
    { name: "api_keys", label: "API 密钥策略 JSON", type: "json", optional: true }
  ];
  const form = el("form", { className: "edit-form", onSubmit: (event) => event.preventDefault() });
  const values = {
    ...data,
    raw_allowed_roles: (data.raw_allowed_roles || ["super_admin"]).join(","),
    departments: JSON.stringify(data.departments || {}, null, 2),
    users: JSON.stringify(data.users || {}, null, 2),
    api_keys: JSON.stringify(data.api_keys || {}, null, 2)
  };
  for (const config of fields) {
    form.append(field(config, values[config.name]));
  }
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("保存策略", async () => {
    try {
      const payload = readFields(form, fields);
      payload.raw_allowed_roles = String(payload.raw_allowed_roles || "")
        .split(",")
        .map((role) => role.trim())
        .filter(Boolean);
      const saved = await api("/admin-api/system/content-retention", {
        method: "PUT",
        body: JSON.stringify(payload)
      });
      setOperationOutput(output, saved.data, "\u7b56\u7565\u5df2\u4fdd\u5b58");
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "保存策略失败，请检查输入内容"));
    }
  }));
  form.append(output);
  content.append(el("section", { className: "panel" }, [
    el("h2", { text: "内容留存策略" }),
    form
  ]));
}

async function renderSystemSettingsPage(content) {
  clear(content);
  const page = el("div", { className: "system-settings-page" });
  const list = el("div", { className: "system-settings-list" });
  const refresh = async (expandedSettingId = null) => {
    try {
      const result = await api("/admin-api/system-settings", { query: { limit: 100 } });
      document.getElementById("request-id").textContent = result.requestId || "";
      const rows = Array.isArray(result.data) ? result.data : [];
      const visibleRows = rows.filter((row) => !isDemoSystemSetting(row));
      clear(list);
      if (visibleRows.length === 0) {
        list.append(statusMessage("暂无高级设置，可新增一项自定义配置。"));
        return;
      }
      for (const row of visibleRows) {
        list.append(systemSettingCard(row, refresh, String(row.id) === String(expandedSettingId)));
      }
    } catch (error) {
      clear(list);
      list.append(statusMessage(adminErrorMessage(error, "高级设置加载失败"), "error"));
    }
  };
  page.append(el("section", { className: "panel system-settings-panel" }, [
    el("header", { className: "table-toolbar" }, [
      el("div", {}, [
        el("h2", { text: "高级设置" }),
        el("p", { className: "notice", text: "集中查看和编辑系统功能配置。每项配置均可按字段直接修改；开关、数字和列表无需填写 JSON。" })
      ]),
      button("新增自定义配置", () => showCreateSystemSettingModal(refresh))
    ]),
    list
  ]));
  content.append(page);
  await refresh();
}

function systemSettingCard(row, onRefresh, isExpanded = false) {
  const presentation = systemSettingPresentation(row);
  const value = systemSettingStoredValue(row);
  const summary = systemSettingEditableFields(value)
    .slice(0, 4)
    .map((descriptor) => `${systemSettingSummaryLabel(row, descriptor.path)}：${systemSettingDisplayValue(descriptor.value)}`)
    .filter(Boolean);
  const accordion = el("div", { className: "system-setting-accordion", hidden: !isExpanded }, [
    summary.length > 0
      ? el("div", { className: "system-setting-summary" }, summary.map((item) => el("span", { text: item })))
      : el("p", { className: "notice", text: "此设置没有可编辑字段。" }),
    createSystemSettingEditor(row, () => onRefresh(row.id))
  ]);
  return el("article", { className: `system-setting-card${isExpanded ? " expanded" : ""}` }, [
    el("div", { className: "system-setting-card-heading" }, [
      el("div", {}, [
        el("strong", { text: presentation.title }),
        el("p", { className: "notice", text: presentation.description })
      ]),
      button(isExpanded ? "收起" : "展开", () => onRefresh(isExpanded ? null : row.id), "secondary")
    ]),
    accordion
  ]);
}

function systemSettingStoredValue(row) {
  const raw = row?.setting_value;
  if (raw && typeof raw === "object") {
    return cloneSystemSettingValue(raw);
  }
  try {
    return JSON.parse(String(raw || "{}"));
  } catch {
    return { value: String(raw || "") };
  }
}

function systemSettingEditableFields(value, path = [], fields = []) {
  if (Array.isArray(value)) {
    if (value.every((item) => item === null || ["string", "number", "boolean"].includes(typeof item))) {
      fields.push({ name: systemSettingFieldName(path), path, kind: "list", itemType: typeof value.find((item) => item !== null) || "string", value });
    }
    return fields;
  }
  if (value && typeof value === "object") {
    for (const [key, child] of Object.entries(value)) {
      systemSettingEditableFields(child, [...path, key], fields);
    }
    return fields;
  }
  const kind = typeof value === "boolean" ? "boolean" : typeof value === "number" ? "number" : "text";
  fields.push({ name: systemSettingFieldName(path), path, kind, value });
  return fields;
}

function systemSettingFieldName(path) {
  return `system_setting_${path.length ? path.join("_") : "value"}`;
}

function systemSettingFieldConfig(row, descriptor) {
  const label = systemSettingFieldLabel(row, descriptor);
  if (descriptor.kind === "boolean") {
    return { name: descriptor.name, label, type: "select", options: [["true", "启用"], ["false", "停用"]] };
  }
  if (descriptor.kind === "number") {
    return { name: descriptor.name, label, type: "number" };
  }
  if (descriptor.kind === "list") {
    return { name: descriptor.name, label: `${label}（每行一项）`, type: "textarea", rows: 4 };
  }
  return { name: descriptor.name, label };
}

function systemSettingFormValue(descriptor) {
  if (descriptor.kind === "list") {
    return descriptor.value.map((item) => item == null ? "" : String(item)).join("\n");
  }
  if (descriptor.kind === "boolean") {
    return descriptor.value ? "true" : "false";
  }
  return descriptor.value == null ? "" : descriptor.value;
}

function systemSettingDisplayValue(value) {
  if (Array.isArray(value)) {
    return value.join("、");
  }
  if (typeof value === "boolean") {
    return value ? "已启用" : "已停用";
  }
  return value == null ? "未设置" : String(value);
}

function createSystemSettingEditor(row, onSaved) {
  const storedValue = systemSettingStoredValue(row);
  const descriptors = systemSettingEditableFields(storedValue);
  const form = el("form", { className: "edit-form system-setting-editor", onSubmit: (event) => event.preventDefault() });
  const baseFields = [
    { name: "setting_key", label: "配置标识" },
    { name: "description", label: "说明", optional: true }
  ];
  for (const config of baseFields) {
    form.append(field(config, row[config.name] || ""));
  }
  for (const descriptor of descriptors) {
    form.append(field(systemSettingFieldConfig(row, descriptor), systemSettingFormValue(descriptor)));
  }
  if (descriptors.length === 0) {
    form.append(statusMessage("此配置包含的复杂数据保持不变；请通过对应的系统设置分区进行管理。"));
  }
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("保存", async () => {
    try {
      const values = readFields(form, baseFields);
      let settingValue = cloneSystemSettingValue(storedValue);
      for (const descriptor of descriptors) {
        const control = form.elements.namedItem(descriptor.name);
        settingValue = setSystemSettingFieldValue(settingValue, descriptor.path, systemSettingControlValue(descriptor, control?.value || ""));
      }
      await api(`/admin-api/system-settings/${row.id}`, {
        method: "PUT",
        body: JSON.stringify({
          setting_key: values.setting_key,
          description: values.description || "",
          setting_value: JSON.stringify(settingValue)
        })
      });
      setText(output, "保存成功");
      output.hidden = false;
      await onSaved();
    } catch (error) {
      setText(output, adminErrorMessage(error, "保存失败，请检查输入内容"));
      output.hidden = false;
    }
  }));
  form.append(output);
  return form;
}

function showSystemSettingEditor(row, onSaved) {
  showModal(`编辑 ${systemSettingPresentation(row).title}`, createSystemSettingEditor(row, onSaved));
}

function cloneSystemSettingValue(value) {
  if (Array.isArray(value)) {
    return value.map(cloneSystemSettingValue);
  }
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([key, child]) => [key, cloneSystemSettingValue(child)]));
  }
  return value;
}

function systemSettingControlValue(descriptor, raw) {
  if (descriptor.kind === "boolean") {
    return raw === "true";
  }
  if (descriptor.kind === "number") {
    const value = Number(raw);
    return Number.isFinite(value) ? value : 0;
  }
  if (descriptor.kind === "list") {
    return raw.split(/\r?\n/).map((item) => item.trim()).filter(Boolean).map((item) => {
      if (descriptor.itemType === "number") return Number(item);
      if (descriptor.itemType === "boolean") return item === "true" || item === "1" || item === "是";
      return item;
    });
  }
  return raw;
}

function setSystemSettingFieldValue(value, path, nextValue) {
  if (path.length === 0) {
    return nextValue;
  }
  const root = cloneSystemSettingValue(value);
  let target = root;
  for (const key of path.slice(0, -1)) {
    target[key] ??= {};
    target = target[key];
  }
  target[path[path.length - 1]] = nextValue;
  return root;
}

function showCreateSystemSettingModal(onSaved) {
  const fields = [
    { name: "setting_key", label: "配置标识" },
    { name: "description", label: "说明", optional: true },
    { name: "field_name", label: "首个配置项名称" },
    { name: "field_type", label: "配置项类型", type: "select", options: [["text", "文本"], ["number", "数字"], ["boolean", "开关"], ["list", "列表（每行一项）"]] },
    { name: "field_value", label: "初始值（列表每行一项）", type: "textarea", rows: 4, optional: true }
  ];
  const form = el("form", { className: "edit-form system-setting-editor", onSubmit: (event) => event.preventDefault() });
  for (const config of fields) {
    form.append(field(config));
  }
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("新增", async () => {
    try {
      const values = readFields(form, fields);
      const value = systemSettingNewValue(values.field_type, values.field_value || "");
      await api("/admin-api/system-settings", {
        method: "POST",
        body: JSON.stringify({
          setting_key: values.setting_key,
          description: values.description || "",
          setting_value: JSON.stringify({ [values.field_name]: value })
        })
      });
      setText(output, "新增成功");
      output.hidden = false;
      await onSaved();
    } catch (error) {
      setText(output, adminErrorMessage(error, "新增失败，请检查输入内容"));
      output.hidden = false;
    }
  }));
  form.append(output);
  showModal("新增自定义配置", form);
}

function systemSettingNewValue(type, raw) {
  if (type === "number") {
    const value = Number(raw);
    return Number.isFinite(value) ? value : 0;
  }
  if (type === "boolean") {
    return raw === "true" || raw === "1" || raw === "是" || raw === "启用";
  }
  if (type === "list") {
    return raw.split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
  }
  return raw;
}

async function renderPermissionConfigPage(content) {
  clear(content);
  const result = await api("/admin-api/admin-users", { query: { limit: 100 } });
  const administrators = Array.isArray(result.data) ? result.data : [];
  const presets = [
    ["普通管理员", "全部业务能力，不包含管理员账号管理", "全局"],
    ["用户管理员", "用户、部门、API Key、配额、用量与内容", "授权部门"],
    ["RAG 管理员", "知识库、数据集、上传、向量化与评测", "授权部门"],
    ["API 管理员", "厂商、模型、价格、API Key、配额与用量", "全局"],
    ["自定义管理员", "仅使用单独勾选的权限", "可选"],
  ];
  const presetPanel = el("section", { className: "panel" }, [
    el("h2", { text: "权限配置" }),
    el("p", { text: "预设类型决定默认能力；勾选单独权限后将覆盖该账号的预设。管理员账号操作始终仅限超级管理员。" }),
    el("table", { className: "resource-table" }, [
      el("thead", {}, [el("tr", {}, [el("th", { text: "管理员类型" }), el("th", { text: "默认能力" }), el("th", { text: "默认范围" })])]),
      el("tbody", {}, presets.map(([name, abilities, scope]) => el("tr", {}, [el("td", { text: name }), el("td", { text: abilities }), el("td", { text: scope })])))
    ])
  ]);
  const capabilityPanel = el("section", { className: "panel" }, [
    el("h2", { text: "可授权权限点" }),
    el("div", { className: "permission-config-options" }, adminPermissionOptions.map(([code, label]) => el("p", { text: `${label}（${code}）` })))
  ]);
  const administratorsPanel = el("section", { className: "panel" }, [
    el("h2", { text: "管理员权限分配" }),
    administrators.length
      ? el("div", { className: "permission-config-admin-list" }, administrators.map((administrator) => el("article", { className: "permission-config-admin" }, [
        el("h3", { text: administrator.username || `管理员 #${administrator.id}` }),
        el("p", { text: `${valueLabel("admin_type", administrator.admin_type) || administrator.admin_type || "未设置"} / ${valueLabel("admin_scope", administrator.admin_scope) || administrator.admin_scope || "未设置"}` }),
        el("p", { text: `有效权限：${(administrator.permissions || []).join("、") || "无"}` }),
        button("配置权限", () => showPermissionConfigEditor(administrator, () => renderPermissionConfigPage(content)), "secondary")
      ])))
      : statusMessage("暂无管理员账号")
  ]);
  content.append(presetPanel, capabilityPanel, administratorsPanel);
}

async function showPermissionConfigEditor(administrator, onSaved) {
  const fields = [
    { name: "admin_type", label: "管理员类型", type: "select", options: [["super_admin", "超级管理员"], ["full_admin", "普通管理员"], ["user_admin", "用户管理员"], ["rag_admin", "RAG 管理员"], ["api_admin", "API 管理员"], ["custom", "自定义管理员"]] },
    { name: "admin_scope", label: "数据范围", type: "select", options: [["global", "全局"], ["departments", "授权部门及子部门"]] },
    { name: "permissions", label: "单独授权（勾选后覆盖预设）", type: "multiselect", options: adminPermissionOptions, optional: true, size: 12 },
    { name: "managed_department_ids", label: "授权管理部门", type: "multiselect", coerce: "number", options: await departmentSelectOptions(), optional: true, size: 8 }
  ];
  const form = resourceForm(fields, administrator, "permission-config");
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("保存权限配置", async () => {
    try {
      const result = await api(`/admin-api/admin-users/${administrator.id}`, {
        method: "PUT",
        body: JSON.stringify(readFields(form, fields))
      });
      setOperationOutput(output, result.data, "权限配置已保存，原登录会话已撤销");
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "权限配置保存失败"));
    }
  }));
  form.append(output);
  showModal(`权限配置 · ${administrator.username || `管理员 #${administrator.id}`}`, form);
}

export async function renderSettingsCenterPage(content) {
  clear(content);
  const result = await api("/admin-api/system/settings-center");
  document.getElementById("request-id").textContent = result.requestId || "";
  const sections = result.data?.sections || {};
  const categoryTabs = [];
  const categoryPanels = [];
  const certificateStatusPanels = [];

  SETTINGS_SECTION_CATEGORIES.forEach((category, categoryIndex) => {
    const categoryActive = categoryIndex === 0;
    const sectionTabs = [];
    const sectionPanels = [];
    const categoryPanel = el("section", {
      className: categoryActive ? "settings-category-panel active" : "settings-category-panel",
      hidden: !categoryActive,
      "data-settings-category-panel": category.key
    });
    const categoryTab = el("button", {
      type: "button",
      className: categoryActive ? "settings-category-tab active" : "settings-category-tab",
      text: category.label,
      "data-settings-category": category.key,
      onClick: () => {
        for (const tab of categoryTabs) {
          tab.classList.toggle("active", tab.dataset.settingsCategory === category.key);
        }
        for (const panel of categoryPanels) {
          const active = panel.dataset.settingsCategoryPanel === category.key;
          panel.hidden = !active;
          panel.classList.toggle("active", active);
        }
      }
    });
    categoryTabs.push(categoryTab);
    categoryPanels.push(categoryPanel);

    category.sections.forEach((resourceKey, sectionIndex) => {
      const page = SETTINGS_SECTION_PAGES[resourceKey];
      if (!page) {
        return;
      }
      const sectionActive = categoryActive && sectionIndex === 0;
      const values = settingsFormValues(sections[page.section] || {});
      const sectionTab = el("button", {
        type: "button",
        className: sectionActive ? "settings-section-tab active" : "settings-section-tab",
        text: page.title,
        "data-settings-section": resourceKey,
        onClick: () => {
          for (const tab of sectionTabs) {
            tab.classList.toggle("active", tab.dataset.settingsSection === resourceKey);
          }
          for (const panel of sectionPanels) {
            const active = panel.dataset.settingsSectionPanel === resourceKey;
            panel.hidden = !active;
            panel.classList.toggle("active", active);
          }
        }
      });
      const rendered = page.section === "tls_certificates"
        ? createCertificateSettingsPage(page, values)
        : page.section === "firewall_settings"
          ? createFirewallSettingsPage(page, values)
          : { element: settingsFormPanel(page.title, page.section, page.fields, values), statusPanel: null };
      const sectionPanel = el("div", {
        className: sectionActive ? "settings-section-panel active" : "settings-section-panel",
        hidden: !sectionActive,
        "data-settings-section-panel": resourceKey
      }, [rendered.element]);
      if (rendered.statusPanel) {
        certificateStatusPanels.push(rendered.statusPanel);
      }
      sectionTabs.push(sectionTab);
      sectionPanels.push(sectionPanel);
    });

    categoryPanel.append(
      el("div", { className: "settings-section-tabs" }, sectionTabs),
      el("div", { className: "settings-section-panels" }, sectionPanels)
    );
  });

  content.append(el("div", { className: "settings-center-page" }, [
    el("div", { className: "settings-category-tabs" }, categoryTabs),
    el("div", { className: "settings-category-panels" }, categoryPanels)
  ]));

  for (const statusPanel of certificateStatusPanels) {
    await renderCertificateStatus(statusPanel);
  }
}

export async function renderSettingsSectionPage(content, resourceKey) {
  clear(content);
  const page = SETTINGS_SECTION_PAGES[resourceKey];
  if (!page) {
    content.append(statusMessage("设置分区不存在", "error"));
    return;
  }
  const result = await api("/admin-api/system/settings-center");
  document.getElementById("request-id").textContent = result.requestId || "";
  const sections = result.data?.sections || {};
  if (page.section === "tls_certificates") {
    await renderCertificateSettingsPage(content, page, settingsFormValues(sections[page.section] || {}));
    return;
  }
  if (page.section === "firewall_settings") {
    content.append(createFirewallSettingsPage(page, settingsFormValues(sections[page.section] || {})).element);
    return;
  }
  content.append(settingsFormPanel(page.title, page.section, page.fields, settingsFormValues(sections[page.section] || {})));
}

function settingsFormValues(values) {
  const out = {};
  for (const [key, value] of Object.entries(values || {})) {
    if (Array.isArray(value)) {
      out[key] = value.every((item) => typeof item === "string") ? value.join("\n") : JSON.stringify(value, null, 2);
    } else if (typeof value === "boolean") {
      out[key] = value ? "true" : "false";
    } else {
      out[key] = value ?? "";
    }
  }
  return out;
}

function createFirewallSettingsPage(page, values) {
  const panel = settingsFormPanel(page.title, page.section, page.fields, values);
  panel.classList.add("waf-firewall-settings");
  const localIpStatus = el("section", { className: "waf-local-access-summary" }, [
    el("strong", { text: "本机访问保护" }),
    el("p", { className: "notice", text: "正在检测本机内网 IP…" })
  ]);
  panel.querySelector("h2")?.after(localIpStatus);
  void renderFirewallLocalIpStatus(localIpStatus);
  return { element: panel, statusPanel: null };
}

async function renderFirewallLocalIpStatus(container) {
  try {
    const result = await api("/admin-api/system/network-interface-ips");
    const ips = Array.isArray(result.data?.local_access_ips)
      ? result.data.local_access_ips
      : Array.isArray(result.data?.ips) ? result.data.ips : [];
    const entries = ips.map((ip) => `${ip}/32`);
    container.replaceChildren(
      el("div", { className: "waf-local-access-heading" }, [
        el("strong", { text: "本机访问保护" }),
        el("span", { text: entries.length > 0 ? `已检测 ${entries.length} 个地址` : "未检测到地址" })
      ]),
      el("p", { className: "notice", text: "自动允许仅在对应允许网段非空时生效；每个本机地址按精确 /32 放行，不会扩大到整个内网。" }),
      entries.length > 0
        ? el("div", { className: "waf-local-ip-list" }, entries.map((cidr) => el("code", { className: "waf-local-ip-chip", text: cidr })))
        : el("p", { className: "notice", text: "未发现本机访问地址。启用允许网段前，请先确认手动允许网段能覆盖本机。" })
    );
  } catch (error) {
    container.replaceChildren(
      el("strong", { text: "本机访问保护" }),
      statusMessage(adminErrorMessage(error, "本机内网 IP 检测失败，请确认手动允许网段"), "error")
    );
  }
}

async function renderCertificateSettingsPage(content, page, values) {
  const rendered = createCertificateSettingsPage(page, values);
  content.append(rendered.element);
  await renderCertificateStatus(rendered.statusPanel);
}

function createCertificateSettingsPage(page, values) {
  const statusPanel = el("section", { className: "certificate-inventory" });
  const element = el("section", { className: "panel certificate-center-page" }, [
    el("header", { className: "certificate-center-heading" }, [
      el("div", {}, [
        el("h2", { text: "证书中心" }),
        el("p", { text: "申请、验证、部署和续签必须以真实证书状态闭环。" })
      ]),
      button("检查证书告警", async () => {
        await api("/admin-api/system/settings-center/certificate-alert-check", { method: "POST" });
      }, "secondary")
    ]),
    el("div", { className: "certificate-summary-grid" }, [
      certificateSummaryCard("当前状态", "正在读取", "真实证书状态"),
      certificateSummaryCard("自动续签", values.auto_renewal === "false" ? "未启用" : "已启用", "公网 IP 每 12 小时检查"),
      certificateSummaryCard("签发类型", certificateRequestTypeLabel(values.certificate_mode), "请选择申请目标")
    ]),
    el("div", { className: "certificate-request-workspace" }, [
      renderCertificateRequestForm(values),
      renderCertificateRequestProgress(values.certificate_request || {})
    ]),
    statusPanel,
    el("section", { className: "certificate-center-alerts" }, [renderCertificateAlertCheck()])
  ]);
  return { element, statusPanel };
}

function renderCertificateRequestForm(values) {
  return renderCertificateCenterRequestForm(values);

  const fields = [
    { name: "certificate_request_type", label: "申请类型", type: "select", options: [["domain", "域名证书"], ["internal_ip", "内网 IP 证书"]] },
    { name: "request_domain_names", label: "申请域名（每行一个）", type: "textarea", optional: true, rows: 3 },
    { name: "request_ip_addresses", label: "申请 IP（内网使用，每行一个）", type: "textarea", optional: true, rows: 3 }
  ];
  const form = el("form", { className: "edit-form certificate-request-form", onSubmit: (event) => event.preventDefault() });
  const initialValues = {
    certificate_request_type: values.certificate_mode === "internal_ip" ? "internal_ip" : "domain",
    request_domain_names: values.domain_names || "",
    request_ip_addresses: values.ip_addresses || ""
  };
  for (const config of fields) {
    form.append(field(config, initialValues[config.name]));
  }
  const output = el("p", { className: "notice", hidden: true });
  const showOutput = (text) => {
    setText(output, text);
    output.hidden = false;
  };
  const requestTypeControl = form.querySelector('[name="certificate_request_type"]');
  if (requestTypeControl) {
    requestTypeControl.addEventListener("change", () => autofillCertificateRequestIps(form, showOutput));
    autofillCertificateRequestIps(form, showOutput);
  }
  form.append(el("div", { className: "toolbar-actions" }, [
    button("申请证书", async () => {
      try {
        const payload = readFields(form, fields);
        const result = await api("/admin-api/system/certificate-request", {
          method: "POST",
          body: JSON.stringify({
            request_type: payload.certificate_request_type,
            domain_names: payload.request_domain_names,
            ip_addresses: payload.request_ip_addresses
          })
        });
        showOutput(operationSuccessText(result.data, "\u8bc1\u4e66\u7533\u8bf7\u5df2\u63d0\u4ea4"));
      } catch (error) {
        showOutput(adminErrorMessage(error, "证书申请失败，请检查域名或 IP 地址"));
      }
    })
  ]));
  form.append(output);

  return el("div", { className: "certificate-request" }, [
    el("p", { className: "notice", text: "首次没有证书时，选择域名证书或内网 IP 证书并提交申请。" }),
    form
  ]);
}

function renderCertificateCenterRequestForm(values) {
  const fields = [
    { name: "certificate_request_type", label: "申请类型", type: "select", options: [["domain", "域名证书"], ["public_ip", "公网 IP 证书"], ["internal_ip", "内网 IP 证书"]] },
    { name: "request_domain_names", label: "申请域名（每行一个）", type: "textarea", optional: true, rows: 3 },
    { name: "request_ip_addresses", label: "申请 IP（每行一个）", type: "textarea", optional: true, rows: 3 }
  ];
  const initialType = ["domain", "public_ip", "internal_ip"].includes(values.certificate_mode) ? values.certificate_mode : "domain";
  const form = el("form", { className: "edit-form certificate-request-form", onSubmit: (event) => event.preventDefault() });
  const typeField = field(fields[0], initialType);
  const domainField = field(fields[1], values.domain_names || "");
  const ipField = field(fields[2], values.ip_addresses || "");
  typeField.classList.add("certificate-request-type-select");
  const typeHelp = el("p", { className: "notice certificate-type-help" });
  const internalChoices = el("div", { className: "internal-ip-choices", hidden: true });
  const output = el("p", { className: "notice", hidden: true });
  const cards = [
    certificateTypeCard("domain", "域名证书", "公网域名 · Caddy ACME 自动续签"),
    certificateTypeCard("public_ip", "公网 IP 证书", "Let’s Encrypt · 约 6 天 · 每 12 小时检查"),
    certificateTypeCard("internal_ip", "内网 IP 证书", "内部 CA / 自签名 · 信任内部根证书")
  ];
  form.append(el("div", { className: "certificate-type-grid" }, cards), typeField, typeHelp, internalChoices, domainField, ipField);
  const typeControl = form.querySelector('[name="certificate_request_type"]');
  const showOutput = (text) => {
    setText(output, text);
    output.hidden = false;
  };
  const syncType = () => {
    const type = typeControl?.value || "domain";
    domainField.hidden = type !== "domain";
    ipField.hidden = type === "domain";
    setText(typeHelp, certificateRequestTypeHelp(type));
    for (const card of cards) {
      card.classList.toggle("active", card.dataset.certificateType === type);
    }
    internalChoices.hidden = type !== "internal_ip";
    if (type === "internal_ip") {
      void renderInternalIpChoices(form, internalChoices, showOutput);
    }
  };
  for (const card of cards) {
    card.addEventListener("click", () => {
      if (typeControl) {
        typeControl.value = card.dataset.certificateType || "domain";
        syncType();
      }
    });
  }
  typeControl?.addEventListener("change", syncType);
  syncType();
  form.append(el("div", { className: "toolbar-actions" }, [
    button("提交申请并开始验证", async () => {
      try {
        const payload = readFields(form, fields);
        const result = await api("/admin-api/system/certificate-request", {
          method: "POST",
          body: JSON.stringify({
            request_type: payload.certificate_request_type,
            domain_names: payload.request_domain_names,
            ip_addresses: payload.request_ip_addresses
          })
        });
        const verified = await certificateRequestIsDeployed(payload);
        showOutput(verified ? "证书已部署，真实状态已回读。" : "申请已受理，正在验证与部署；只有真实证书回读成功后才视为完成。");
        document.getElementById("request-id").textContent = result.requestId || "";
      } catch (error) {
        showOutput(adminErrorMessage(error, "证书申请失败，请检查域名或 IP 地址"));
      }
    })
  ]));
  form.append(output);
  return el("section", { className: "certificate-request" }, [el("h3", { text: "申请或更换证书" }), form]);
}

async function renderInternalIpChoices(form, container, showOutput) {
  const ipControl = form.querySelector('[name="request_ip_addresses"]');
  if (!ipControl) {
    return;
  }
  clear(container);
  container.append(el("strong", { text: "选择一个内网 IP" }), el("small", { text: "仅会为当前选中的一个 IP 申请内部 CA 证书。" }));
  try {
    const result = await api("/admin-api/system/network-interface-ips");
    const ips = Array.isArray(result.data?.ips) ? result.data.ips : [];
    if (ips.length === 0) {
      container.append(el("p", { className: "notice", text: "未发现可选内网 IP，请在下方手工输入一个 IP。" }));
      return;
    }
    const choices = el("div", { className: "internal-ip-choice-list" });
    for (const ip of ips) {
      const choice = el("button", {
        type: "button",
        className: "internal-ip-choice",
        "data-internal-ip-choice": ip,
        text: ip,
        onClick: () => {
          ipControl.value = ip;
          for (const item of choices.querySelectorAll(".internal-ip-choice")) {
            item.classList.toggle("active", item.dataset.internalIpChoice === ip);
          }
        }
      });
      choice.classList.toggle("active", ipControl.value.trim() === ip);
      choices.append(choice);
    }
    container.append(choices);
  } catch (error) {
    showOutput(adminErrorMessage(error, "获取内网 IP 候选失败，请手工输入一个 IP"));
  }
}

function certificateTypeCard(type, title, detail) {
  return el("button", {
    type: "button",
    className: "certificate-type-card",
    dataset: {
      certificateType: type,
      certificateIssuer: type === "public_ip" ? "letsencrypt_shortlived" : type === "internal_ip" ? "internal_ca" : "caddy_acme"
    }
  }, [
    el("strong", { text: title }),
    el("span", { text: detail })
  ]);
}

function certificateSummaryCard(label, value, detail) {
  return el("article", { className: "certificate-summary-card" }, [
    el("span", { text: label }), el("strong", { text: value }), el("small", { text: detail })
  ]);
}

function certificateRequestTypeLabel(type) {
  return { domain: "域名", public_ip: "公网 IP", internal_ip: "内网 IP" }[type] || "域名";
}

function certificateRequestTypeHelp(type) {
  return {
    domain: "域名证书会使用公网 ACME 验证。请确保域名解析和 80/443 端口可达。",
    public_ip: "公网 IP 证书使用 Let’s Encrypt short-lived profile；仅支持公网 IP 的 HTTP-01 或 TLS-ALPN-01 验证。",
    internal_ip: "内网 IP 只使用内部 CA，不会向公网 CA 发起申请。客户端需要信任内部根证书。"
  }[type] || "";
}

function renderCertificateRequestProgress(request) {
  const status = request.status || "requested";
  const stages = [["requested", "已受理", "已保存目标与签发策略"], ["validating", "验证中", "检查挑战条件与 CA 验证"], ["deploying", "部署中", "Caddy 正在加载证书"], ["verified", "已部署", "真实证书状态已回读"]];
  const activeIndex = Math.max(0, stages.findIndex(([key]) => key === status));
  return el("aside", { className: "certificate-request-progress" }, [
    el("h3", { text: "申请进度" }),
    ...stages.map(([key, title, detail], index) => el("div", { className: `certificate-progress-step ${index <= activeIndex ? "active" : ""}`.trim() }, [
      el("span", { text: index < activeIndex ? "✓" : String(index + 1) }),
      el("div", {}, [el("strong", { text: title }), el("small", { text: detail })])
    ]))
  ]);
}

async function certificateRequestIsDeployed(payload) {
  const result = await api("/admin-api/system/certificate-status");
  const certificates = Array.isArray(result.data?.certificates) ? result.data.certificates : [];
  const source = payload.certificate_request_type === "domain" ? payload.request_domain_names : payload.request_ip_addresses;
  const targets = String(source || "").split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean);
  return targets.length > 0 && certificates.some((certificate) => {
    const names = [...(certificate.domains || []), ...(certificate.ip_addresses || [])];
    return targets.every((target) => names.includes(target));
  });
}

function renderCertificateInventory(data) {
  const certificates = Array.isArray(data?.certificates) ? data.certificates : [];
  return el("section", { className: "certificate-inventory-results" }, [
    el("header", { className: "table-toolbar" }, [el("h3", { text: "受管证书" }), el("span", { text: `共 ${certificates.length} 张` })]),
    certificates.length > 0 ? renderCertificateStatusTable(certificates) : statusMessage("暂无可读取的真实证书。申请完成后会在此处显示已部署状态。")
  ]);
}

async function autofillCertificateRequestIps(form, showOutput) {
  const typeControl = form.querySelector('[name="certificate_request_type"]');
  const ipControl = form.querySelector('[name="request_ip_addresses"]');
  if (!typeControl || !ipControl || typeControl.value !== "internal_ip" || ipControl.value.trim() !== "") {
    return;
  }

  try {
    const result = await api("/admin-api/system/network-interface-ips");
    const data = result.data || {};
    const ips = Array.isArray(data.ips) ? data.ips : [];
    if (ips.length > 0) {
      ipControl.value = ips.join("\n");
      return;
    }
    showOutput("\u672a\u81ea\u52a8\u53d1\u73b0\u670d\u52a1\u5668\u7f51\u5361 IP\uff0c\u8bf7\u624b\u52a8\u586b\u5199\u3002");
  } catch (error) {
    showOutput(adminErrorMessage(error, "\u81ea\u52a8\u83b7\u53d6\u670d\u52a1\u5668\u7f51\u5361 IP \u5931\u8d25\uff0c\u8bf7\u624b\u52a8\u586b\u5199"));
  }
}

function certificateSettingsForm(section, fields, values) {
  const form = el("form", { className: "edit-form", onSubmit: (event) => event.preventDefault() });
  for (const config of fields) {
    form.append(field(config, values[config.name]));
  }
  const output = el("p", { className: "notice", hidden: true });
  const showOutput = (text) => {
    setText(output, text);
    output.hidden = false;
  };
  const hideOutput = () => {
    setText(output, "");
    output.hidden = true;
  };
  form.append(el("div", { className: "toolbar-actions" }, [
    button("保存", async () => {
      try {
        const payload = readFields(form, fields);
        if (section === "waf_detection_settings" && payload.mode === "block" && !window.confirm("确认启用 WAF 拦截模式？达到风险阈值的请求将被拒绝。")) {
          return;
        }
        await api(`/admin-api/system/settings-center/${section}`, {
          method: "PUT",
          body: JSON.stringify(payload)
        });
        hideOutput();
      } catch (error) {
        showOutput(adminErrorMessage(error, "保存证书配置失败，请检查输入内容"));
      }
    })
  ]));
  form.append(output);
  return form;
}

function renderCertificateAlertCheck() {
  const output = el("p", { className: "notice", hidden: true });
  const showOutput = (text) => {
    setText(output, text);
    output.hidden = false;
  };
  return el("div", { className: "certificate-alert-check" }, [
    el("p", { className: "notice", text: "根据真实证书状态和到期阈值生成证书告警。" }),
    button("检查证书告警", async () => {
      try {
        const checked = await api("/admin-api/system/settings-center/certificate-alert-check", { method: "POST" });
        showOutput(operationSuccessText(checked.data, "\u8bc1\u4e66\u544a\u8b66\u68c0\u67e5\u5df2\u5b8c\u6210"));
      } catch (error) {
        showOutput(adminErrorMessage(error, "证书告警检查失败"));
      }
    }, "secondary"),
    output
  ]);
}

function settingsFormPanel(title, section, fields, values) {
  const form = el("form", { className: "edit-form", onSubmit: (event) => event.preventDefault() });
  for (const config of fields) {
    form.append(field(config, values[config.name]));
  }
  const output = el("p", { className: "notice", hidden: true });
  const showOutput = (text) => {
    setText(output, text);
    output.hidden = false;
  };
  const hideOutput = () => {
    setText(output, "");
    output.hidden = true;
  };
  form.append(el("div", { className: "toolbar-actions" }, [
    button("保存", async () => {
      try {
        const payload = readFields(form, fields);
        await api(`/admin-api/system/settings-center/${section}`, {
          method: "PUT",
          body: JSON.stringify(payload)
        });
        hideOutput();
      } catch (error) {
        showOutput(adminErrorMessage(error, "保存设置失败，请检查输入内容"));
      }
    }),
    section === "tls_certificates" ? button("检查证书告警", async () => {
      try {
        const checked = await api("/admin-api/system/settings-center/certificate-alert-check", { method: "POST" });
        showOutput(operationSuccessText(checked.data, "\u8bc1\u4e66\u544a\u8b66\u68c0\u67e5\u5df2\u5b8c\u6210"));
      } catch (error) {
        showOutput(adminErrorMessage(error, "证书告警检查失败"));
      }
    }, "secondary") : null
  ].filter(Boolean)));
  form.append(output);
  return el("section", { className: "panel" }, [
    el("h2", { text: title }),
    form
  ]);
}

function ragNavigate(page, filters = {}) {
  window.dispatchEvent(new CustomEvent("tokenhub-admin-navigate", { detail: { page, filters } }));
}

function renderRagOnboardingPage(summary = {}) {
  const totals = summary.totals || {};
  const hasKnowledgeBase = Number(totals.active_knowledge_bases || 0) > 0;
  const hasDataset = Number(totals.active_datasets || 0) > 0;
  const nextPage = hasKnowledgeBase ? "rag-vector-datasets" : "rag-knowledge-bases";
  const nextLabel = hasKnowledgeBase ? "创建第一个数据集" : "创建第一个知识库";
  return el("section", { className: "panel rag-onboarding" }, [
    el("span", { className: "eyebrow", text: "从第一条可验证知识开始" }),
    el("h2", { text: "RAG 还没有可检索的知识" }),
    el("p", { text: "完成知识库、数据集和一次导入后，系统会带你进入检索试验台验证结果。" }),
    el("ol", { className: "rag-onboarding-steps" }, [
      el("li", { text: `1. ${hasKnowledgeBase ? "知识库已创建" : "创建知识库"}` }),
      el("li", { text: `2. ${hasDataset ? "数据集已创建" : "创建数据集"}` }),
      el("li", { text: "3. 上传文件并预览审核" }),
      el("li", { text: "4. 向量化后在检索试验台提问" })
    ]),
    button(nextLabel, () => ragNavigate(nextPage, { openCreate: true }), "primary")
  ]);
}

function renderRagTrialSource(source, index, context = {}) {
  const signals = source?.rank_signals && typeof source.rank_signals === "object" ? source.rank_signals : {};
  const signalText = Object.entries(signals)
    .filter(([, value]) => value !== null && value !== undefined && value !== "")
    .map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(", ") : value}`)
    .join(" · ");
  return el("article", { className: "rag-trial-source" }, [
    el("header", {}, [
      el("strong", { text: `${index + 1}. ${source.title || source.chunk_id || "未命名片段"}` }),
      el("span", { className: "status-badge", text: source.retrieval_strategy || "未知策略" })
    ]),
    el("p", { text: source.content || source.text || source.description || "无可展示正文" }),
    source.chunk_id ? button("加入评估样本", () => ragNavigate("rag-quality", {
      tab: "rag-eval-cases",
      dataset_id: context.dataset_id || source.dataset_id || "",
      query: context.query || "",
      expected_chunk_ids: [String(source.chunk_id)]
    }), "secondary") : null,
    el("small", { text: signalText || "暂无排序信号" })
  ].filter(Boolean));
}

async function renderRagRetrievalLabPage(content) {
  clear(content);
  const [knowledgeBases, datasets] = await Promise.all([
    lookupRows("rag-knowledge-bases"),
    lookupRows("rag-vector-datasets")
  ]);
  if (knowledgeBases.length === 0 || datasets.length === 0) {
    content.append(renderRagOnboardingPage({ totals: {
      active_knowledge_bases: knowledgeBases.length,
      active_datasets: datasets.length
    } }));
    return;
  }

  const form = el("form", { className: "edit-form rag-retrieval-lab", onSubmit: (event) => event.preventDefault() });
  const resultPanel = el("section", { className: "rag-trial-results" }, [statusMessage("输入问题后开始真实检索。")]);
  const datasetOptions = datasets.map((dataset) => [dataset.dataset_id, `${dataset.name || dataset.dataset_id}`]);
  form.append(
    field({ name: "dataset_id", label: "数据集", type: "select", options: datasetOptions }),
    field({ name: "query", label: "真实问题", type: "textarea", rows: 3 }),
    field({ name: "top_k", label: "返回条数", type: "select", options: [["3", "3 条"], ["5", "5 条"], ["10", "10 条"]] }, "5")
  );
  form.append(el("div", { className: "toolbar-actions" }, [button("开始检索", async () => {
    const values = readFields(form, [{ name: "dataset_id" }, { name: "query" }, { name: "top_k", coerce: "number" }]);
    if (!values.query) {
      resultPanel.replaceChildren(statusMessage("请输入要验证的真实问题。", "error"));
      return;
    }
    resultPanel.replaceChildren(statusMessage("正在执行真实检索..."));
    try {
      const result = await api("/admin-api/rag/trial-retrieval", { method: "POST", body: JSON.stringify(values) });
      const data = result.data || {};
      const sources = Array.isArray(data.sources) ? data.sources : [];
      resultPanel.replaceChildren(
        el("header", { className: "rag-trial-result-header" }, [
          el("h2", { text: `命中 ${sources.length} 条` }),
          el("span", { text: `${data.latency_ms || 0} ms · ${data.fallback_used ? "已降级" : "未降级"}` })
        ]),
        el("p", { className: "notice", text: `数据集：${data.dataset?.name || data.dataset?.dataset_id || values.dataset_id}` }),
        ...(sources.length > 0 ? sources.map((source, index) => renderRagTrialSource(source, index, values)) : [statusMessage("没有命中内容。可调整问题或回到构建知识检查导入。", "error")])
      );
    } catch (error) {
      resultPanel.replaceChildren(statusMessage(adminErrorMessage(error, "检索试验失败"), "error"));
    }
  }, "primary")]));
  content.append(el("section", { className: "panel" }, [
    el("span", { className: "eyebrow", text: "真实检索验证" }),
    el("h2", { text: "检索试验台" }),
    el("p", { text: "选择数据集，输入实际用户问题，查看命中证据和排序原因。" }),
    form
  ]), resultPanel);
}

async function renderRagBuildPage(content) {
  clear(content);
  try {
    const result = await api("/admin-api/rag/summary", { query: { window_hours: 24 } });
    content.append(renderRagOnboardingPage(result.data || {}));
  } catch (error) {
    content.append(statusMessage(adminErrorMessage(error, "RAG 构建状态加载失败"), "error"));
  }
}

async function renderRagDashboardPage(content) {
  clear(content);
  const metricsPanel = el("section", { className: "panel rag-dashboard-panel" }, [
    el("h2", { text: "RAG 运营总览" }),
    statusMessage("正在从数据库加载 RAG 指标...")
  ]);
  const grid = el("section", { className: "rag-dashboard-grid" }, [metricsPanel]);
  content.append(grid);

  try {
    const result = await api("/admin-api/rag/summary", { query: { window_hours: 24 } });
    document.getElementById("request-id").textContent = result.requestId || "";
    const data = result.data || {};
    const totals = data.totals || {};
    const retrieval = data.retrieval || {};
    if (Number(totals.active_knowledge_bases || 0) === 0 || Number(totals.active_datasets || 0) === 0) {
      metricsPanel.replaceChildren(renderRagOnboardingPage(data));
      return;
    }
    metricsPanel.replaceChildren(
      el("h2", { text: "RAG 运营总览" }),
      el("p", { className: "notice", text: `数据来源: ${data.source || "database"} / 统计窗口: ${data.window_hours || 24}h` }),
      el("div", { className: "rag-dashboard-cards" }, [
        ragMetricCard("知识库", totals.active_knowledge_bases || 0),
        ragMetricCard("数据集", totals.active_datasets || 0),
        ragMetricCard("上传记录", totals.uploads || 0),
        ragMetricCard("预览条目", totals.preview_items || 0),
        ragMetricCard("有效 Chunk", totals.enabled_chunks || 0),
        ragMetricCard("已索引向量", totals.indexed_vectors || 0),
        ragMetricCard("检索次数", retrieval.total || 0),
        ragMetricCard("降级检索", retrieval.fallback_count || 0, Number(retrieval.fallback_count || 0) > 0 ? "warning" : ""),
        ragMetricCard("待处理纠偏", totals.open_corrections || 0, Number(totals.open_corrections || 0) > 0 ? "warning" : "")
      ]),
      renderRagPipeline(data.pipeline || []),
      renderRagStatusBuckets(data.status || {})
    );

    const recent = data.recent || {};
    grid.append(
      ragSummaryTable("最近上传", recent.uploads || [], ["upload_id", "content_type", "status", "review_status", "vectorize_status", "stage", "progress", "total_items", "created_at"]),
      ragSummaryTable("数据集实况", recent.datasets || [], ["dataset_id", "name", "knowledge_base_name", "status", "chunk_count", "vector_count", "last_indexed_at"]),
      ragSummaryTable("向量任务", recent.jobs || [], ["job_id", "job_type", "status", "processed_items", "total_items", "progress_percent", "error_code", "created_at"]),
      ragSummaryTable("检索日志", recent.retrievals || [], ["retrieval_id", "api_key_id", "query_modality", "hit_count", "latency_ms", "fallback_used", "created_at"]),
      ragSummaryTable("反馈", recent.feedback || [], ["feedback_id", "retrieval_id", "rating", "status", "created_at"]),
      ragSummaryTable("纠偏任务", recent.corrections || [], ["task_id", "issue_type", "priority", "status", "action", "created_at"])
    );
  } catch (error) {
    metricsPanel.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 数据库总览加载失败"), "error"));
  }
}

function ragMetricCard(label, value, tone = "") {
  return el("article", { className: `rag-metric-card ${tone}`.trim() }, [
    el("span", { text: label }),
    el("strong", { text: value })
  ]);
}

function renderRagPipeline(rows) {
  return el("section", { className: "rag-pipeline" }, [
    el("h3", { text: "真实写入链路" }),
    el("div", { className: "rag-pipeline-steps" }, (rows || []).map((row) => el("article", { className: Number(row.blocked || 0) > 0 ? "rag-pipeline-step warning" : "rag-pipeline-step" }, [
      el("span", { text: row.label || row.step || "" }),
      el("strong", { text: formatResourceValue("count", row.count || 0) }),
      el("small", { text: `异常/阻塞 ${row.blocked || 0}` })
    ])))
  ]);
}

function renderRagStatusBuckets(status) {
  const groups = Object.entries(status || {});
  return el("section", { className: "rag-status-buckets" }, [
    el("h3", { text: "状态分布" }),
    el("div", { className: "rag-status-grid" }, groups.map(([title, values]) => renderRagMetricsList(title, values || {})))
  ]);
}

function ragSummaryTable(title, rows, columns) {
  return el("section", { className: "panel table-panel rag-summary-panel" }, [
    el("h2", { text: title }),
    Array.isArray(rows) && rows.length > 0 ? renderRagSummaryList(rows, columns) : statusMessage("暂无数据")
  ]);
}

function renderRagSummaryList(rows, columns) {
  return el("div", { className: "rag-summary-list" }, rows.slice(0, 8).map((row) => renderRagSummaryItem(row, columns)));
}

function renderRagSummaryItem(row, columns) {
  const primaryColumn = columns[0] || "id";
  const title = formatResourceValue(primaryColumn, row[primaryColumn]) || "-";
  const statusColumn = columns.find((column) => ["status", "review_status", "vectorize_status", "fallback_used"].includes(column));
  const status = statusColumn ? formatResourceValue(statusColumn, row[statusColumn]) : "";
  const chips = columns
    .slice(1)
    .filter((column) => row[column] !== null && row[column] !== undefined && String(row[column]) !== "")
    .map((column) => el("span", { className: "rag-summary-chip" }, [
      el("small", { text: columnLabel(column) }),
      el("b", { text: formatResourceValue(column, row[column]) })
    ]));

  return el("article", { className: "rag-summary-item" }, [
    el("header", { className: "rag-summary-item-header" }, [
      el("strong", { text: title }),
      status ? el("span", { className: `status-badge ${String(row[statusColumn] || "")}`, text: status }) : null
    ].filter(Boolean)),
    el("div", { className: "rag-summary-chip-row" }, chips.length > 0 ? chips : [el("span", { className: "muted", text: "无更多字段" })])
  ]);
}

async function renderRagCategoryPage(content, resourceKey, initialFilters = {}) {
  clear(content);
  const tabs = RAG_CATEGORY_TABS[resourceKey] || [];
  if (tabs.length === 0) {
    content.append(statusMessage("RAG 分类不存在", "error"));
    return;
  }

  let activeKey = String(initialFilters.tab || tabs[0][0]);
  if (!tabs.some(([key]) => key === activeKey)) {
    activeKey = tabs[0][0];
  }

  const tabBar = el("div", { className: "rag-category-tabs" });
  const panel = el("section", { className: "rag-category-tab-panel" });
  const pageTitle = resources[resourceKey]?.label || "RAG";

  const renderActive = async (nextKey = activeKey) => {
    activeKey = nextKey;
    tabBar.replaceChildren(...tabs.map(([key, label]) => button(label, async () => {
      await renderActive(key);
    }, key === activeKey ? "primary rag-category-tab active" : "secondary rag-category-tab")));
    await renderRagTabContent(panel, activeKey, activeKey === String(initialFilters.tab || "") ? initialFilters : {});
  };

  content.append(el("section", { className: "panel rag-category-page" }, [
    el("h2", { text: pageTitle }),
    tabBar
  ]), panel);

  await renderActive(activeKey);
}

async function renderRagTabContent(panel, resourceKey, filters = {}) {
  clear(panel);
  if (resourceKey === "rag-retrieval-lab") {
    await renderRagRetrievalLabPage(panel);
    return;
  }
  if (resourceKey === "rag-dashboard") {
    await renderRagDashboardPage(panel);
    return;
  }
  if (resourceKey === "rag-vectorization-metrics") {
    await renderRagVectorizationMetricsPage(panel);
    return;
  }
  if (resourceKey === "rag-retrieval-evaluation") {
    await renderRagEvaluationPage(panel);
    return;
  }
  if (resourceKey === "rag-evaluation") {
    await renderRagEvaluationPage(panel);
    return;
  }
  if (resourceKey === "rag-eval-cases") {
    await renderRagEvaluationCasesPage(panel, filters);
    return;
  }
  if (resourceKey === "rag-template-center") {
    await renderRagTemplateCenterPage(panel);
    return;
  }
  if (resourceKey === "rag-upload-workbench") {
    await renderRagUploadWorkbenchPage(panel);
    return;
  }
  if (resourceKey === "rag-import-task") {
    await renderRagImportTaskPage(panel, filters);
    return;
  }
  if (resourceKey === "rag-upload-detail") {
    await renderRagUploadDetailPage(panel, filters);
    return;
  }
  if (resourceKey === "rag-image-preview") {
    await renderRagImagePreviewPage(panel, filters);
    return;
  }
  await renderGenericResourcePage(panel, resourceKey, filters);
}

async function renderRagTemplateCenterPage(content) {
  clear(content);
  const panel = el("section", { className: "panel rag-template-center" }, [
    el("h2", { text: "RAG 模板下载" }),
    statusMessage("正在加载模板...")
  ]);
  content.append(panel);
  try {
    const result = await api("/admin-api/rag/import-templates");
    const templates = Array.isArray(result.data) ? result.data : [];
    panel.replaceChildren(
      el("h2", { text: "RAG 模板下载" }),
      el("div", { className: "rag-template-grid" }, templates.map((template) => {
        const templateId = String(template.template_id || "");
        return el("article", { className: "rag-template-card" }, [
          el("h3", { text: template.name || templateId }),
          el("p", { text: `类型: ${template.template_type || ""}` }),
          el("p", { text: `版本: ${template.version || ""}` }),
          button("下载模板", () => {
            window.location.href = `/admin-api/rag/import-templates/${encodeURIComponent(templateId)}/download`;
          }, "secondary")
        ]);
      }))
    );
  } catch (error) {
    panel.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 模板加载失败"), "error"));
  }
}

async function renderRagUploadWorkbenchPage(content, options = {}) {
  clear(content);
  const [knowledgeBases, datasets, departmentOptions] = await Promise.all([
    lookupRows("rag-knowledge-bases"),
    lookupRows("rag-vector-datasets"),
    departmentSelectOptions()
  ]);
  const form = el("form", { className: "edit-form rag-upload-workbench", onSubmit: (event) => event.preventDefault() });
  const kbOptions = knowledgeBases.map((row) => [row.kb_id, `${row.name || row.kb_id} (${row.kb_id})`]);
  const datasetOptions = datasets.map((row) => [row.dataset_id, `${row.name || row.dataset_id} (${row.dataset_id})`]);
  const fileInput = el("input", { type: "file", name: "rag_file", accept: ".xlsx,.csv,.txt,.zip" });
  const output = el("p", { className: "notice", hidden: true });
  const uploadResult = el("section", { className: "rag-upload-result" });

  form.append(
    field({ name: "kb_id", label: "知识库", type: "select", options: kbOptions }),
    field({ name: "target_dataset_id", label: "向量数据集", type: "select", options: datasetOptions }),
    field({ name: "department_id", label: "部门", type: "select", coerce: "number", options: departmentOptions }),
    field({ name: "template", label: "导入类型", type: "select", options: [["text", "文本"], ["qa", "问答"], ["image", "图片清单"], ["image_package", "ZIP 图片包"]] }),
    field({ name: "confirm_required", label: "上传后确认", type: "select", options: [["true", "需要预览确认"], ["false", "允许自动向量化"]] }),
    el("label", { className: "field" }, [
      el("span", { text: "上传文件" }),
      fileInput
    ]),
    el("div", { className: "toolbar-actions" }, [
      button("上传并生成预览", async () => {
        await submitRagUploadWorkbench(form, fileInput, output, uploadResult, options.onUploaded);
      }, "primary", { disabled: kbOptions.length === 0 || datasetOptions.length === 0 })
    ]),
    output
  );

  content.append(el("section", { className: "panel" }, [
    el("h2", { text: "RAG 上传工作台" }),
    form
  ]), uploadResult);
}

async function submitRagUploadWorkbench(form, fileInput, output, uploadResult, onUploaded = null) {
  const values = readFields(form, [
    { name: "kb_id" },
    { name: "target_dataset_id" },
    { name: "department_id", coerce: "number" },
    { name: "template" },
    { name: "confirm_required" }
  ]);
  const file = fileInput.files?.[0];
  if (!file) {
    setErrorOutput(output, "请选择要上传的 Excel、CSV、文本或 ZIP 文件");
    return;
  }
  const template = String(values.template || "text");
  const contentBase64 = await readFileAsBase64(file);
  const processing = {
    target_dataset_id: values.target_dataset_id,
    template,
    confirm_required: values.confirm_required !== "false",
    auto_vectorize: values.confirm_required === "false"
  };
  if (template === "image_package") {
    processing.package_mode = "manifest_zip";
  }
  try {
    const kbId = String(values.kb_id || "");
    const result = await api(`/admin-api/rag/knowledge-bases/${encodeURIComponent(kbId)}/uploads`, {
      method: "POST",
      body: JSON.stringify({
        department_id: values.department_id,
        filename: file.name,
        mime_type: file.type || "application/octet-stream",
        content_type: template === "image_package" ? "zip" : template,
        content_base64: contentBase64,
        processing
      })
    });
    const uploadId = String(result.data?.upload_id || "");
    if (uploadId && typeof onUploaded === "function") {
      await onUploaded(uploadId);
      return;
    }
    setOperationOutput(output, result.data, "上传已解析，请在导入任务中预览确认");
    uploadResult.replaceChildren(renderRagUploadStatusCard(result.data));
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, "RAG 上传失败"));
  }
}

function readFileAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || "").split(",").pop() || "");
    reader.onerror = () => reject(reader.error || new Error("file read failed"));
    reader.readAsDataURL(file);
  });
}

async function renderRagUploadDetailPage(content, initialFilters = {}) {
  clear(content);
  const taskMode = initialFilters.import_task === true;
  const state = {
    uploadId: String(initialFilters.upload_id || ""),
    previewType: "text",
    page: 1,
    pageSize: 20,
    uploadLoaded: false
  };
  const uploadField = taskMode ? null : field({ name: "upload_id", label: "Upload ID" }, state.uploadId);
  const statusPanel = el("section", { className: "panel rag-upload-status-panel" }, [statusMessage(taskMode ? "正在加载导入任务…" : "请输入 upload_id 查询上传详情。")]);
  const previewPanel = el("section", { className: "panel rag-upload-preview-panel" });
  const output = el("p", { className: "notice", hidden: true });
  const approveButton = button("审核通过", async () => reviewRagUpload(state.uploadId, "approve", output, refresh), "secondary", { disabled: !state.uploadLoaded });
  const rejectButton = button("审核拒绝", async () => reviewRagUpload(state.uploadId, "reject", output, refresh), "secondary", { disabled: !state.uploadLoaded });
  const vectorizeButton = button("确认向量化", async () => confirmRagUpload(state.uploadId, output, refresh), "primary", { disabled: !state.uploadLoaded });

  const refresh = async () => {
    if (uploadField) {
      state.uploadId = String(uploadField.querySelector("[name=\"upload_id\"]")?.value || "").trim();
    }
    if (state.uploadId === "") {
      statusPanel.replaceChildren(statusMessage(taskMode ? "尚未创建导入任务，请先上传文件。" : "请输入 upload_id 查询上传详情。"));
      previewPanel.replaceChildren();
      return;
    }
    await refreshRagUploadDetail(state, statusPanel, previewPanel, output, refresh, { approveButton, rejectButton, vectorizeButton });
  };

  content.append(el("section", { className: "panel rag-upload-detail" }, [
    el("h2", { text: taskMode ? "导入任务" : "RAG 上传详情" }),
    el("div", { className: "toolbar-actions" }, [
      uploadField,
      uploadField ? button("查询", refresh) : null,
      approveButton,
      rejectButton,
      vectorizeButton
    ].filter(Boolean)),
    output
  ]), statusPanel, renderRagPreviewTabs(state, previewPanel, refresh), previewPanel);

  await refresh();
}

function readFileAsText(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ""));
    reader.onerror = () => reject(reader.error || new Error("file read failed"));
    reader.readAsText(file);
  });
}

async function renderRagImportTaskPage(content, initialFilters = {}) {
  const uploadId = String(initialFilters.upload_id || "");
  if (uploadId !== "") {
    await renderRagUploadDetailPage(content, { ...initialFilters, upload_id: uploadId, import_task: true });
    return;
  }
  await renderRagUploadWorkbenchPage(content, {
    onUploaded: async (uploadId) => {
      ragNavigate("rag-build", { tab: "rag-import-task", upload_id: uploadId });
    }
  });
}

async function refreshRagUploadDetail(state, statusPanel, previewPanel, output, refresh, actions = {}) {
  try {
    const uploadId = state.uploadId;
    const [status, preview] = await Promise.all([
      api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}`),
      api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/preview`, {
        query: { type: state.previewType, page: state.page, page_size: state.pageSize }
      })
    ]);
    statusPanel.replaceChildren(renderRagUploadStatusCard(status.data));
    previewPanel.replaceChildren(renderRagPreviewPage(preview.data, refresh));
    state.uploadLoaded = true;
    const reviewStatus = String(status.data?.review_status || "");
    const vectorizeStatus = String(status.data?.vectorize_status || "");
    if (actions.approveButton) actions.approveButton.disabled = !["waiting_review", "pending_review"].includes(reviewStatus);
    if (actions.rejectButton) actions.rejectButton.disabled = !["waiting_review", "pending_review"].includes(reviewStatus);
    if (actions.vectorizeButton) actions.vectorizeButton.disabled = reviewStatus !== "approved" || ["queued", "processing", "succeeded"].includes(vectorizeStatus);
    output.hidden = true;
  } catch (error) {
    state.uploadLoaded = false;
    if (actions.approveButton) actions.approveButton.disabled = true;
    if (actions.rejectButton) actions.rejectButton.disabled = true;
    if (actions.vectorizeButton) actions.vectorizeButton.disabled = true;
    statusPanel.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 上传详情加载失败"), "error"));
  }
}

function renderRagUploadStatusCard(upload) {
  const progress = Number(upload?.progress || upload?.progress_percent || 0);
  return el("section", { className: "rag-upload-status-card" }, [
    el("h3", { text: upload?.upload_id || "upload" }),
    el("div", { className: "rag-upload-progress" }, [
      el("div", { className: "rag-upload-progress-bar", style: `width: ${Math.max(0, Math.min(100, progress))}%` })
    ]),
    el("p", { text: `状态 ${upload?.status || ""} / ${upload?.stage || upload?.current_stage || ""}` }),
    el("p", { text: `审核: ${upload?.review_status || "not_required"}，waiting_review 表示需要审核后才能确认向量化` }),
    el("p", { text: `进度: ${progress}%` }),
    renderDataDetails(upload || {})
  ]);
}

function renderRagPreviewTabs(state, previewPanel, refresh) {
  const tabs = [["text", "文本"], ["image", "图片"], ["error", "错误行"]];
  return el("section", { className: "rag-preview-tabs" }, tabs.map(([type, label]) => button(label, async () => {
    state.previewType = type;
    state.page = 1;
    await refresh();
  }, type === state.previewType ? "primary" : "secondary")));
}

function renderRagPreviewPage(data, refresh) {
  const items = Array.isArray(data?.items) ? data.items : [];
  const body = items.map((item) => item.item_type === "image"
    ? renderRagPreviewImageItem(item, refresh)
    : renderRagPreviewTextItem(item));
  return el("section", { className: "rag-preview-page" }, [
    el("header", { className: "table-toolbar" }, [
      el("h2", { text: `预览 ${data?.type || "all"}` }),
      el("span", { text: `page ${data?.page || 1}, page_size: 20, total ${data?.total || 0}` })
    ]),
    ...(body.length > 0 ? body : [statusMessage("暂无预览数据")])
  ]);
}

function renderRagPreviewTextItem(item) {
  return el("article", { className: "rag-preview-item" }, [
    el("h3", { text: item.title || item.question || item.preview_item_id || "text" }),
    el("p", { text: item.content || item.answer || item.description || "" }),
    el("p", { text: `status: ${item.validation_status || ""}` }),
    el("p", { text: `errors: ${JSON.stringify(item.validation_errors || [])}` })
  ]);
}

function renderRagPreviewImageItem(item, refresh) {
  const image = item.image_asset || {};
  const ai = image.metadata?.ai_annotation || {};
  return el("article", { className: "rag-image-preview-item" }, [
    image.thumbnail_path ? el("img", { className: "rag-image-preview-thumb", alt: "thumbnail", src: image.thumbnail_path }) : el("div", { className: "rag-image-preview-thumb", text: "thumbnail" }),
    el("div", { className: "rag-image-preview-body" }, [
      el("h3", { text: item.title || image.original_filename || item.preview_item_id || "image" }),
      el("p", { text: `人工标注: ${JSON.stringify({ purpose: item.purpose || image.purpose, description: item.description || image.short_description, detailed_description: item.detailed_description || image.detailed_description })}` }),
      el("p", { text: `AI 标注: ${JSON.stringify(ai)}` }),
      el("p", { text: `OCR: ${image.ocr_text || ai.ocr_text || ""}` }),
      el("p", { text: `warning: ${JSON.stringify(image.warnings || item.validation_errors || [])}` }),
      image.image_id ? button("重新 AI 标注", async () => {
        await api(`/admin-api/rag/image-assets/${encodeURIComponent(image.image_id)}/reprocess-ai`, { method: "POST" });
        await refresh();
      }, "secondary") : null
    ].filter(Boolean))
  ]);
}

async function reviewRagUpload(uploadId, decision, output, refresh) {
  if (!uploadId) {
    setErrorOutput(output, "请先输入 upload_id");
    return;
  }
  try {
    const result = await api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/review`, {
      method: "POST",
      body: JSON.stringify({ decision, comment: decision === "approve" ? "web approve" : "web reject" })
    });
    setOperationOutput(output, result.data, "审核状态已更新");
    await refresh();
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, "审核操作失败"));
  }
}

async function confirmRagUpload(uploadId, output, refresh) {
  if (!uploadId) {
    setErrorOutput(output, "请先输入 upload_id");
    return;
  }
  try {
    const result = await api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/confirm-vectorization`, { method: "POST" });
    setOperationOutput(output, result.data, "确认向量化已提交");
    await refresh();
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, "确认向量化失败"));
  }
}

async function renderRagImagePreviewPage(content, initialFilters = {}) {
  clear(content);
  const state = {
    uploadId: String(initialFilters.upload_id || ""),
    page: Math.max(1, Number(initialFilters.page || 1) || 1),
    pageSize: 20
  };
  const uploadField = field({ name: "upload_id", label: "Upload ID" }, state.uploadId);
  const output = el("p", { className: "notice", hidden: true });
  const list = el("section", { className: "rag-image-preview-list" }, [
    statusMessage("请输入 upload_id 查询图片预览。")
  ]);
  const refresh = async () => {
    state.uploadId = String(uploadField.querySelector("[name=\"upload_id\"]")?.value || "").trim();
    if (state.uploadId === "") {
      list.replaceChildren(statusMessage("请输入 upload_id 查询图片预览。"));
      return;
    }
    try {
      const result = await api(`/admin-api/rag/uploads/${encodeURIComponent(state.uploadId)}/preview`, {
        query: { type: "image", page: state.page, page_size: state.pageSize }
      });
      document.getElementById("request-id").textContent = result.requestId || "";
      list.replaceChildren(renderRagImagePreviewItems(result.data, refresh));
      output.hidden = true;
    } catch (error) {
      list.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 图片预览加载失败"), "error"));
    }
  };
  content.append(el("section", { className: "panel rag-image-preview-page" }, [
    el("h2", { text: "RAG 图片预览" }),
    el("div", { className: "toolbar-actions" }, [
      uploadField,
      button("查询", refresh)
    ]),
    statusMessage("请在上传详情页审核预览后提交向量化。"),
    output
  ]));
  content.append(list);
  await refresh();
}

function renderRagImagePreviewItems(data, refresh) {
  const items = Array.isArray(data?.items) ? data.items : [];
  const rows = items.map((item) => {
    const metadata = item.metadata || {};
    const image = metadata.image_asset || metadata.image || {};
    const thumbnail = image.thumbnail_url || image.thumbnail_path || item.thumbnail_url || "";
    const human = metadata.human_annotation || item.human_annotation || {
      title: item.title,
      purpose: item.purpose,
      description: item.description,
      detailed_description: metadata.detailed_description
    };
    const ai = metadata.ai_annotation || image.ai_annotation || {};
    const warnings = item.validation_errors || image.quality_warnings || metadata.quality_warnings || [];
    return el("article", { className: "rag-image-preview-item" }, [
      thumbnail ? el("img", { className: "rag-image-preview-thumb", alt: "thumbnail", src: thumbnail }) : el("div", { className: "rag-image-preview-thumb", text: "thumbnail" }),
      el("div", { className: "rag-image-preview-body" }, [
        el("h3", { text: String(human.title || item.title || item.item_id || item.id || "image") }),
        el("p", { text: `human_annotation: ${JSON.stringify(human)}` }),
        el("p", { text: `ai_annotation: ${JSON.stringify(ai)}` }),
        el("p", { text: `ocr_text: ${String(ai.ocr_text || image.ocr_text || metadata.ocr_text || "")}` }),
        el("p", { text: `quality_warnings: ${Array.isArray(warnings) ? warnings.join(", ") : String(warnings || "")}` }),
        el("p", { text: `status: ${String(item.validation_status || item.status || "")}` }),
        image.image_id || item.image_id ? button("重新 AI 标注", async () => {
          await api(`/admin-api/rag/image-assets/${encodeURIComponent(image.image_id || item.image_id)}/reprocess-ai`, { method: "POST" });
          await refresh();
        }, "secondary") : null
      ].filter(Boolean))
    ]);
  });
  if (rows.length === 0) {
    rows.push(statusMessage("暂无图片预览数据。"));
  }
  return el("section", { className: "panel rag-image-preview-results" }, [
    el("header", { className: "table-toolbar" }, [
      el("h2", { text: `图片预览 ${Number(data?.page || 1)} / ${Math.max(1, Math.ceil(Number(data?.total || 0) / Number(data?.page_size || 20)))}` }),
      el("span", { text: `每页 ${Number(data?.page_size || 20)} 条，总计 ${Number(data?.total || 0)} 条` })
    ]),
    ...rows
  ]);
}

async function renderRagEvaluationPage(content) {
  clear(content);
  const host = el("section", { className: "panel rag-evaluation" }, [
    el("h2", { text: "RAG 检索评估" }),
    statusMessage("正在加载 RAG 检索评估...")
  ]);
  const run = async () => {
    try {
      const result = await api("/admin-api/rag/evaluation-runs", { method: "POST", body: JSON.stringify({ top_k_values: [1, 5, 10, 20] }) });
      document.getElementById("request-id").textContent = result.requestId || "";
      await refresh();
    } catch (error) {
      host.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 检索评估运行失败"), "error"));
    }
  };
  const refresh = async () => {
    try {
      const result = await api("/admin-api/rag/evaluation-summary", { query: { window_hours: 24 } });
      document.getElementById("request-id").textContent = result.requestId || "";
      const data = result.data || {};
      const source = data.source || "rag_eval_runs";
      const latest = data.latest_run || {};
      const activeCases = Number(data.active_cases || 0);
      const caseCount = Number(latest.case_count || 0);
      const failedCases = Number(latest.failed_cases || 0);
      const qualityStatus = caseCount > 0 && failedCases === caseCount ? "运行完成，质量未达标" : "运行完成";
      host.replaceChildren(...[
        el("header", { className: "table-toolbar" }, [
          el("h2", { text: "RAG 检索评估" }),
          el("div", { className: "toolbar-actions" }, [
            button("创建评估样本", () => ragNavigate("rag-quality", { tab: "rag-eval-cases" }), "secondary"),
            button("运行评估", run, "primary", { disabled: activeCases === 0 })
          ])
        ]),
        el("p", { className: "notice", text: activeCases === 0 ? "暂无启用评估样本，请先创建并启用样本。" : `数据来源：${source}，启用样本 ${activeCases}，草稿样本 ${data.draft_cases || 0}` }),
        latest.run_id ? el("p", { className: failedCases > 0 ? "notice error" : "notice", text: qualityStatus }) : null,
        el("div", { className: "rag-dashboard-cards" }, [
          ragMetricCard("Recall@5", latest.recall_at_5 || 0),
          ragMetricCard("Recall@10", latest.recall_at_10 || 0),
          ragMetricCard("Recall@20", latest.recall_at_20 || 0),
          ragMetricCard("MRR", latest.mrr || 0),
          ragMetricCard("Cases", latest.case_count || 0),
          ragMetricCard("Failures", latest.failed_cases || 0, Number(latest.failed_cases || 0) > 0 ? "warning" : "")
        ]),
        renderDataDetails(data),
        ragSummaryTable("最近评估", data.latest_runs || [], ["run_id", "case_count", "recall_at_1", "recall_at_5", "recall_at_10", "recall_at_20", "mrr", "failed_cases", "created_at"]),
        renderRagEvaluationFailureCards(data.recent_failures || [], refresh)
      ].filter(Boolean));
    } catch (error) {
      host.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 检索评估加载失败"), "error"));
    }
  };
  content.append(host);
  await refresh();
}

function renderRagEvaluationFailureCards(failures, refresh) {
  const rows = Array.isArray(failures) ? failures : [];
  return el("section", { className: "rag-evaluation-failures" }, [
    el("h3", { text: "失败样本与纠偏" }),
    ...(rows.length > 0 ? rows.map((failure) => {
      const runId = String(failure.run_id || "");
      const caseId = String(failure.case_id || "");
      return el("article", { className: "rag-evaluation-failure" }, [
        el("strong", { text: String(failure.query || "未命名查询") }),
        el("p", { text: failure.hit_rank === null || failure.hit_rank === undefined ? "未命中期望内容" : `命中排序：${failure.hit_rank}` }),
        button("转为纠偏任务", async () => {
          await api(`/admin-api/rag/eval-runs/${encodeURIComponent(runId)}/failures/${encodeURIComponent(caseId)}/correction`, { method: "POST" });
          await refresh();
        }, "secondary", { disabled: !runId || !caseId })
      ]);
    }) : [statusMessage("暂无失败样本。")])
  ]);
}

async function renderRagEvaluationCasesPage(content, initialFilters = {}) {
  clear(content);
  const [datasets, cases] = await Promise.all([
    lookupRows("rag-vector-datasets"),
    lookupRows("rag-eval-cases")
  ]);
  const datasetOptions = datasets.map((dataset) => [dataset.dataset_id, dataset.name || dataset.dataset_id]);
  const initialExpected = Array.isArray(initialFilters.expected_chunk_ids) ? initialFilters.expected_chunk_ids.join(", ") : "";
  const form = el("form", { className: "edit-form rag-evaluation-case-form", onSubmit: (event) => event.preventDefault() });
  const output = el("p", { className: "notice", hidden: true });
  const importInput = el("input", { type: "file", accept: ".json,.jsonl,application/json" });
  const refresh = async () => renderRagEvaluationCasesPage(content, initialFilters);

  form.append(
    field({ name: "dataset_id", label: "评估数据集", type: "select", options: datasetOptions }, String(initialFilters.dataset_id || "")),
    field({ name: "query", label: "检索问题", type: "textarea" }, String(initialFilters.query || "")),
    field({ name: "expected_chunk_ids", label: "期望命中的内容片段", type: "textarea" }, initialExpected),
    field({ name: "status", label: "创建后状态", type: "select", options: [["draft", "草稿"], ["active", "启用"]] }, "draft"),
    el("p", { className: "field-hint", text: "从检索试验台加入样本会自动填写期望片段；手工创建时多个片段以逗号分隔。" }),
    el("div", { className: "toolbar-actions" }, [
      button("创建评估样本", async () => {
        const values = readFields(form, [{ name: "dataset_id" }, { name: "query" }, { name: "expected_chunk_ids" }, { name: "status" }]);
        try {
          const result = await api("/admin-api/rag/eval-cases", {
            method: "POST",
            body: JSON.stringify({ ...values, expected_chunk_ids: String(values.expected_chunk_ids || "").split(/[，,\n]/).map((item) => item.trim()).filter(Boolean) })
          });
          setOperationOutput(output, result.data, "评估样本已创建");
          await refresh();
        } catch (error) {
          setErrorOutput(output, adminErrorMessage(error, "评估样本创建失败"));
        }
      }, "primary", { disabled: datasetOptions.length === 0 })
    ]),
    output
  );

  const importPanel = el("section", { className: "rag-evaluation-bulk-import" }, [
    el("h3", { text: "批量导入样本" }),
    el("p", { text: "上传 JSON 或 JSONL 文件。每行需包含 dataset_id、query、expected_chunk_ids，可选 status。" }),
    importInput,
    button("批量导入样本", async () => {
      const file = importInput.files?.[0];
      if (!file) {
        setErrorOutput(output, "请选择 JSON 或 JSONL 样本文件");
        return;
      }
      try {
        const text = await readFileAsText(file);
        const trimmed = text.trim();
        const rows = trimmed.startsWith("[") ? JSON.parse(trimmed) : trimmed.split(/\r?\n/).filter(Boolean).map((line) => JSON.parse(line));
        const result = await api("/admin-api/rag/eval-cases/import", { method: "POST", body: JSON.stringify({ rows }) });
        setOperationOutput(output, result.data, "样本导入已完成");
        await refresh();
      } catch (error) {
        setErrorOutput(output, adminErrorMessage(error, "样本导入失败，请检查文件格式"));
      }
    }, "secondary")
  ]);

  const cards = cases.map((item) => el("article", { className: "rag-evaluation-case-card" }, [
    el("strong", { text: String(item.query || "未命名问题") }),
    el("p", { text: `状态：${valueLabel("status", item.status) || item.status || "草稿"}` }),
    el("div", { className: "toolbar-actions" }, [
      item.status !== "active" ? button("启用", async () => {
        await api(`/admin-api/rag/eval-cases/${encodeURIComponent(item.case_id)}/status`, { method: "PATCH", body: JSON.stringify({ status: "active" }) });
        await refresh();
      }, "primary") : null,
      item.status === "active" ? button("停用", async () => {
        await api(`/admin-api/rag/eval-cases/${encodeURIComponent(item.case_id)}/status`, { method: "PATCH", body: JSON.stringify({ status: "disabled" }) });
        await refresh();
      }, "secondary") : null
    ].filter(Boolean))
  ]));

  content.append(el("section", { className: "panel rag-evaluation-cases" }, [
    el("header", { className: "table-toolbar" }, [el("h2", { text: "评估样本" }), el("span", { text: `已有 ${cases.length} 个样本` })]),
    form,
    importPanel,
    el("section", { className: "rag-evaluation-case-list" }, cards.length > 0 ? cards : [statusMessage("暂无评估样本。先从检索试验台创建第一条可验证样本。")])
  ]));
}

async function renderRagVectorizationMetricsPage(content) {
  clear(content);
  const host = el("section", { className: "panel rag-vectorization-metrics" }, [
    el("h2", { text: "RAG 向量化成功率" }),
    statusMessage("正在加载 RAG 向量化监控...")
  ]);
  content.append(host);
  try {
    const result = await api("/admin-api/rag/vectorization-metrics");
    document.getElementById("request-id").textContent = result.requestId || "";
    const data = result.data || {};
    host.replaceChildren(
      el("h2", { text: "RAG 向量化成功率" }),
      el("p", { className: data.below_threshold ? "notice error" : "notice", text: Number(data.total_jobs || 0) === 0 ? "暂无可计算任务" : `最近 ${data.window_hours || 24}h 总体成功率 ${data.overall_success_rate || 0}%` }),
      el("p", { text: `上传任务 ${data.total_jobs || 0}，失败 ${data.failed_jobs || 0}，阈值 ${data.threshold || 95}%` }),
      el("p", { text: `告警: ${data.alert_code || "none"}` }),
      renderRagMetricsList("任务类型", data.by_job_type || {}),
      renderRagMetricsList("Top 失败原因", data.top_failures || {})
    );
  } catch (error) {
    host.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 向量化监控加载失败"), "error"));
  }
}

function renderRagMetricsList(title, data) {
  const rows = Object.entries(data || {}).map(([key, value]) => el("li", { text: `${key}: ${JSON.stringify(value)}` }));
  return el("section", { className: "rag-metrics-list" }, [
    el("h3", { text: title }),
    el("ul", {}, rows.length > 0 ? rows : [el("li", { text: "暂无数据" })])
  ]);
}

async function renderCertificateStatus(content) {
  const panel = el("section", { className: "panel table-panel" }, [
    el("h2", { text: "真实证书状态" }),
    statusMessage("正在读取真实证书信息")
  ]);
  content.append(panel);
  try {
    const result = await api("/admin-api/system/certificate-status");
    document.getElementById("request-id").textContent = result.requestId || "";
    const data = result.data || {};
    const certificates = Array.isArray(data.certificates) ? data.certificates : [];
    clear(panel);
    panel.append(renderCertificateSummary(data.summary || {}));
    panel.append(renderCertificateInventory(data));
    return;
    panel.append(el("h2", { text: "真实证书状态" }));
    panel.append(renderCertificateSummary(data.summary || {}));
    if (certificates.length === 0) {
      panel.append(statusMessage("暂无可读取的真实证书，请确认 Caddy 数据目录或 TOKENHUB_CERTIFICATE_PATHS 已配置"));
      return;
    }
    panel.append(renderCertificateStatusTable(certificates));
  } catch (error) {
    clear(panel);
    panel.append(el("h2", { text: "真实证书状态" }));
    panel.append(statusMessage(adminErrorMessage(error, "证书状态加载失败"), "error"));
  }
}

function renderCertificateSummary(summary) {
  return el("section", { className: "metric-grid" }, [
    metric("证书总数", summary.total || 0),
    metric("域名证书", summary.domain_certificates || 0),
    metric("IP 证书", summary.ip_certificates || 0),
    metric("即将到期", summary.expiring_soon || 0),
    metric("已过期", summary.expired || 0),
    metric("自动续期", summary.auto_renewal_managed || 0)
  ]);
}

function renderCertificateStatusTable(certificates) {
  const columns = ["name", "certificate_type", "targets", "issuer", "status", "days_remaining", "expires_at", "renewal_source", "source_type"];
  const table = el("table", { className: "resource-table resource-table--certificate-status" });
  table.append(el("thead", {}, [
    el("tr", {}, columns.map((column) => el("th", { text: certificateColumnLabel(column) })))
  ]));
  const body = el("tbody");
  for (const certificate of certificates) {
    body.append(el("tr", {}, columns.map((column) => el("td", {
      text: certificateStatusValue(certificate, column)
    }))));
  }
  table.append(body);
  return table;
}

function certificateStatusValue(certificate, column) {
  if (column === "certificate_type") {
    return certificate.certificate_type === "ip" ? "IP 证书" : "域名证书";
  }
  if (column === "targets") {
    return [...(certificate.domains || []), ...(certificate.ip_addresses || [])].join(", ");
  }
  if (column === "status") {
    return certificateStatusLabel(certificate.status);
  }
  if (column === "renewal_source") {
    return certificate.renewal_source === "caddy" ? "Caddy 自动续期" : "证书文件";
  }
  if (column === "source_type") {
    return certificate.source_type === "caddy" ? "Caddy 存储" : "文件路径";
  }
  return formatResourceValue(column, certificate[column]);
}

function certificateColumnLabel(column) {
  return {
    name: "证书文件",
    certificate_type: "类型",
    targets: "域名或 IP",
    issuer: "签发者",
    status: "状态",
    days_remaining: "剩余天数",
    expires_at: "到期时间",
    renewal_source: "续期来源",
    source_type: "来源"
  }[column] || columnLabel(column);
}

function certificateStatusLabel(status) {
  return {
    valid: "正常",
    expiring_soon: "即将到期",
    expired: "已过期"
  }[status] || status || "";
}

function contentArchiveQuery(form, filters, state) {
  const query = readFields(form, filters);
  state.includeRaw = form.querySelector('[name="include_raw"]')?.value === "true";
  if (state.includeRaw) {
    query.include_raw = true;
  }
  query.limit = 50;
  return query;
}

function renderContentArchiveTable(rows, includeRaw) {
  const columns = ["content_id", "request_id", "session_id", "user_id", "department_id", "model_name", "status", "retention_mode", "created_at", "cleaned_at"];
  const table = el("table");
  const headRow = el("tr");
  for (const column of columns) {
    headRow.append(el("th", { text: columnLabel(column) }));
  }
  headRow.append(el("th", { text: "操作" }));
  const body = el("tbody");
  for (const row of rows) {
    const tr = el("tr");
    for (const column of columns) {
      tr.append(el("td", { text: formatResourceValue(column, row[column]) }));
    }
    tr.append(el("td", {}, [button("查看", async () => {
      const result = await api(`/admin-api/content-archive/${row.content_id}`, { query: { include_raw: includeRaw ? "true" : "false" } });
      showModal("\u4f1a\u8bdd\u5b58\u6863\u8be6\u60c5", renderDataDetails(result.data));
    }, "secondary")]));
    body.append(tr);
  }
  table.append(el("thead", {}, [headRow]), body);
  return table;
}

async function renderDepartmentTree(content, config) {
  const layout = el("div", { className: "department-layout" });
  const createPanel = el("section", { className: "panel department-create-panel" });
  const form = resourceForm(config.form);
  form.classList.add("department-create-form");
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("新增部门", async () => {
    try {
      const result = await api("/admin-api/departments", {
        method: "POST",
        body: JSON.stringify(readFields(form, config.form))
      });
      setOperationOutput(output, result.data, "\u90e8\u95e8\u5df2\u65b0\u589e");
      await drawTree(treePanel);
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "新增部门失败，请检查输入内容"));
    }
  }));
  form.append(output);
  createPanel.append(
    el("div", { className: "department-create-hero" }, [
      el("div", { className: "department-hero-mark" }, [
        el("span"),
        el("span"),
        el("span")
      ]),
      el("div", {}, [
        el("h2", { text: "部门维护" }),
        el("p", { text: "组织层级、父子关系和排序统一维护" })
      ])
    ]),
    form
  );
  const treePanel = el("section", { className: "panel department-tree-panel" });
  layout.append(createPanel, treePanel);
  content.append(layout);
  await drawTree(treePanel);
}

async function drawTree(parent) {
  clear(parent);
  const result = await api("/admin-api/departments/tree");
  const list = el("div", { className: "tree-list" });
  const roots = result.data || [];
  const rows = flattenTree(roots);
  const activeCount = rows.filter((row) => row.status === "active").length;
  const disabledCount = rows.filter((row) => row.status === "disabled").length;
  const maxDepth = rows.reduce((depth, row) => Math.max(depth, row.depth), 0);
  const summary = el("div", { className: "department-summary-grid" }, [
    departmentSummaryCard("部门总数", rows.length, "组织节点"),
    departmentSummaryCard("启用中", activeCount, "可用部门"),
    departmentSummaryCard("已停用", disabledCount, "冻结节点"),
    departmentSummaryCard("层级深度", maxDepth + 1, "当前结构")
  ]);
  for (const root of roots) {
    list.append(renderDepartmentBranch(root, parent, 0));
  }
  parent.append(
    el("div", { className: "department-tree-heading" }, [
      el("div", {}, [
        el("h2", { text: "部门树" }),
        el("p", { text: "按组织层级展示当前部门关系" })
      ])
    ]),
    summary,
    list
  );
}

function flattenTree(rows, depth = 0) {
  return rows.flatMap((row) => [{ ...row, depth }, ...flattenTree(row.children || [], depth + 1)]);
}

function renderDepartmentBranch(row, treePanel, depth) {
  const children = row.children || [];
  const statusText = valueLabel("status", row.status);
  const branch = el("div", {
    className: `department-branch ${depth === 0 ? "department-branch-root" : ""}`.trim()
  });
  branch.style.setProperty("--department-depth", String(depth));
  const meta = [
    `ID ${row.id}`,
    `层级 ${Number(row.level ?? depth) + 1}`,
    `排序 ${row.sort_order ?? 0}`
  ];
  if (row.path) {
    meta.push(row.path);
  }
  const actions = el("div", { className: "department-node-actions" }, [
    button("编辑", () => showDepartmentEdit(row, () => drawTree(treePanel)), "secondary"),
    button("停用", async () => {
      await api(`/admin-api/departments/${row.id}`, { method: "DELETE" });
      await drawTree(treePanel);
    }, "danger")
  ]);
  const node = el("article", { className: "tree-row department-node" }, [
    el("span", { className: "department-node-connector" }),
    el("div", { className: "department-node-card" }, [
      el("div", { className: "department-node-main" }, [
        el("span", { className: "department-node-icon", text: depth === 0 ? "总" : String(depth) }),
        el("div", { className: "department-node-copy" }, [
          el("strong", { text: row.name }),
          el("span", { text: meta.join(" / ") })
        ])
      ]),
      el("div", { className: "department-node-side" }, [
        el("span", { className: "department-child-count", text: `${children.length} 个子部门` }),
        el("span", { className: `status-badge ${row.status || ""}`, text: statusText }),
        actions
      ])
    ])
  ]);
  branch.append(node);
  if (children.length > 0) {
    const childList = el("div", { className: "department-children" });
    for (const child of children) {
      childList.append(renderDepartmentBranch(child, treePanel, depth + 1));
    }
    branch.append(childList);
  }
  return branch;
}

function departmentSummaryCard(label, value, caption) {
  return el("article", { className: "department-summary-card" }, [
    el("span", { text: label }),
    el("strong", { text: value }),
    el("small", { text: caption })
  ]);
}

function showDepartmentEdit(row, onSaved) {
  const fields = [
    { name: "name", label: "部门名称" },
    { name: "parent_id", label: "父部门 ID", type: "number", optional: true },
    { name: "sort_order", label: "排序", type: "number", optional: true },
    { name: "status", label: "状态", type: "select", options: ["active", "disabled"].map((value) => [value, valueLabel("status", value)]) }
  ];
  const form = resourceForm(fields, row);
  const output = el("p", { className: "notice", hidden: true });
  form.append(button("保存", async () => {
    try {
      const result = await api(`/admin-api/departments/${row.id}`, {
        method: "PUT",
        body: JSON.stringify(readFields(form, fields))
      });
      setOperationOutput(output, result.data, "\u90e8\u95e8\u5df2\u4fdd\u5b58");
      await onSaved();
    } catch (error) {
      setErrorOutput(output, adminErrorMessage(error, "保存部门失败，请检查输入内容"));
    }
  }));
  form.append(output);
  showModal("编辑部门", form);
}
