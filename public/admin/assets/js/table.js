import { adminErrorMessage, api, streamApi } from "./api.js?v=20261028-admin-ops-v1";
import { button, clear, el, field, readFields, setText, showModal, statusMessage } from "./dom.js?v=20260722-modal-close-label-v1";
import { adminPermissionOptions, columnLabel, filterFields, formatResourceValue, resources, valueLabel } from "./resources.js?v=20261028-admin-ops-v1";

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
  video_understanding: "视频",
  audio_understanding: "音频",
  embedding: "文字",
  asr: "ASR",
  tts: "TTS",
  image_generation: "图片",
  video_generation: "视频",
  rerank: "文字",
  moderation: "审核"
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
  "settings-retention-export",
  "settings-alert-thresholds",
  "settings-rag-upload-review",
  "settings-rag-package-upload",
  "settings-rag-ai-image-processing",
  "settings-rag-vectorization-monitoring"
];

const SETTINGS_SECTION_CATEGORIES = [
  { key: "base-entry", label: "基础入口", sections: ["settings-brand-identity", "settings-tls-certificates"] },
  { key: "security", label: "安全防护", sections: ["settings-entry-security"] },
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
  "ollama": "http://localhost:11434/v1",
  "minimax": "https://api.minimaxi.com",
  "bailian": "https://dashscope.aliyuncs.com",
  "gemini_native": "https://generativelanguage.googleapis.com",
  "xai": "https://api.x.ai/v1",
  "kling": "https://api-singapore.klingai.com",
  "vidu": "https://api.vidu.com",
  "bfl": "https://api.bfl.ai",
  "fal": "https://queue.fal.run",
  "replicate": "https://api.replicate.com/v1",
  "elevenlabs": "https://api.elevenlabs.io",
  "runway": "https://api.dev.runwayml.com",
  "luma": "https://agents.lumalabs.ai/v1",
  "deepgram": "https://api.deepgram.com",
  "ideogram": "https://api.ideogram.ai",
  "stability": "https://api.stability.ai",
  "hunyuan_image": "https://hunyuan.tencentcloudapi.com"
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
  ["minimax", "minimax"],
  ["海螺", "minimax"],
  ["百炼原生", "bailian"],
  ["万相", "bailian"],
  ["cosyvoice", "bailian"],
  ["gemini 原生", "gemini_native"],
  ["gemini native", "gemini_native"],
  ["xai", "xai"],
  ["grok", "xai"],
  ["可灵", "kling"],
  ["kling", "kling"],
  ["vidu", "vidu"],
  ["生数", "vidu"],
  ["flux", "bfl"],
  ["black forest", "bfl"],
  ["fal", "fal"],
  ["replicate", "replicate"],
  ["elevenlabs", "elevenlabs"],
  ["runway", "runway"],
  ["luma", "luma"],
  ["deepgram", "deepgram"],
  ["ideogram", "ideogram"],
  ["stability", "stability"],
  ["混元生图", "hunyuan_image"],
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
  { name: "MiniMax（海螺视频/生图/语音）", protocol: "openai", baseUrl: providerBaseUrlPresets.minimax },
  { name: "阿里云百炼原生（万相/CosyVoice）", protocol: "openai", baseUrl: providerBaseUrlPresets.bailian },
  { name: "Google Gemini 原生", protocol: "gemini", baseUrl: providerBaseUrlPresets.gemini_native },
  { name: "可灵 Kling", protocol: "openai", baseUrl: providerBaseUrlPresets.kling },
  { name: "生数 Vidu", protocol: "openai", baseUrl: providerBaseUrlPresets.vidu },
  { name: "xAI Grok", protocol: "openai", baseUrl: providerBaseUrlPresets.xai },
  { name: "OpenRouter", protocol: "openai", baseUrl: providerBaseUrlPresets.openrouter },
  { name: "FLUX（Black Forest Labs）", protocol: "openai", baseUrl: providerBaseUrlPresets.bfl },
  { name: "fal.ai", protocol: "openai", baseUrl: providerBaseUrlPresets.fal },
  { name: "ElevenLabs", protocol: "openai", baseUrl: providerBaseUrlPresets.elevenlabs },
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
    if (row.id) {
      const quotaButton = button("", () => showApiKeyQuota(row), "secondary api-key-icon-button api-key-quota-button");
      quotaButton.title = "额度：查看当前用量（含进行中请求的预占）";
      quotaButton.ariaLabel = "额度";
      quotaButton.append(apiKeyActionIcon([
        "M12 14l4-4",
        "M3.34 19a10 10 0 1 1 17.32 0"
      ]));
      actions.append(quotaButton);
    }
    if (row.id && row.status === "active") {
      const rotateButton = button("", () => showApiKeyRotate(row, onRefresh), "secondary api-key-icon-button api-key-rotate-button");
      rotateButton.title = "轮换：生成新密钥并在宽限期后停用旧密钥";
      rotateButton.ariaLabel = "轮换";
      rotateButton.append(apiKeyActionIcon([
        "M21 12a9 9 0 0 1-15.5 6.2L3 16",
        "M3 21v-5h5",
        "M3 12a9 9 0 0 1 15.5-6.2L21 8",
        "M21 3v5h-5"
      ]));
      actions.append(rotateButton);
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
        const scopeBadge = column === "name" ? apiKeyManagementBadge(row) : null;
        return el("td", {
          className: `table-cell table-cell--${column}`,
          text: scopeBadge ? undefined : cellText,
          title: cellText
        }, scopeBadge ? [scopeBadge, document.createTextNode(cellText)] : []);
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
    return apiKeyStatusText(row.status, row.disabled_by);
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

// disabled_by: "admin" (console) | "management" (agent management API) |
// null (not recorded; the management API treats it as admin).
const API_KEY_DISABLED_BY_LABELS = { admin: "管理员", management: "智能体" };

function apiKeyStatusText(status, disabledBy = null) {
  const labels = {
    active: "生效中",
    disabled: "已停用",
    deleted: "已删除",
    expired: "已过期"
  };
  const text = labels[status] || formatResourceValue("status", status);
  if (status === "disabled" && API_KEY_DISABLED_BY_LABELS[disabledBy]) {
    return `${text}（${API_KEY_DISABLED_BY_LABELS[disabledBy]}）`;
  }
  return text;
}

async function renderAdminChatSessionPage(content) {
  clear(content);
  const state = { messages: [], stickToBottom: true };
  const [modelResult, chatApiKeys] = await Promise.all([
    api("/admin-api/chat-session/models").catch((error) => ({ error, data: [] })),
    lookupRows("api-keys")
  ]);
  const models = Array.isArray(modelResult.data) ? modelResult.data : [];
  const modelOptions = models.map((row) => {
    const suffix = row.provider_name ? ` / ${row.provider_name}` : "";
    return [row.name, `${row.name}${suffix}`];
  });
  const activeApiKeys = chatApiKeys.filter((key) => key.id && key.status === "active");
  const apiKeyOptions = [
    ["", "不使用（直连诊断）"],
    ...activeApiKeys.map((key) => [String(key.id), `${key.name || `API 密钥 #${key.id}`} / ${apiKeyDisplay(key) || `#${key.id}`}`])
  ];
  let gatewayModels = null;

  const fields = [
    { name: "model", label: "模型", type: "select", options: modelOptions },
    { name: "prompt", label: "消息", type: "textarea", rows: 3 },
    { name: "api_key_id", label: "使用 API Key", type: "select", options: apiKeyOptions }
  ];
  const form = el("form", { className: "admin-chat-composer", onSubmit: (event) => event.preventDefault() });
  const output = el("p", { className: "notice", hidden: true });
  const trialMeta = el("div", { className: "admin-chat-trial-meta", hidden: true });
  const modelField = field(fields[0], "");
  modelField.classList.add("admin-chat-model-field");
  const promptField = field(fields[1], "");
  const apiKeyField = field(fields[2], "");
  apiKeyField.classList.add("admin-chat-api-key-field");
  const apiKeySelect = apiKeyField.querySelector('select[name="api_key_id"]');
  const modelSelect = modelField.querySelector('select[name="model"]');
  const modeHint = el("p", { className: "admin-chat-mode-hint" });
  const setModelOptions = (options) => {
    const previous = modelSelect.value;
    modelSelect.replaceChildren(...options.map(([value, label]) => el("option", { value, text: label })));
    if (options.some(([value]) => value === previous)) {
      modelSelect.value = previous;
    }
  };
  const updateChatMode = async () => {
    const apiKeyId = apiKeySelect.value;
    trialMeta.hidden = true;
    if (!apiKeyId) {
      modeHint.className = "admin-chat-mode-hint notice";
      setText(modeHint, "直连诊断模式：直接调用上游，绕过额度、脱敏、RAG 与计费，不产生用量记录，仅用于连通性检查。");
      setModelOptions(modelOptions);
      send.disabled = modelOptions.length === 0;
      return;
    }
    modeHint.className = "admin-chat-mode-hint notice secret-output";
    setText(modeHint, "API Key 试用模式：请求经过真实网关（鉴权、额度、脱敏、RAG、路由与故障转移），按所选密钥计费并产生用量记录。模型使用该密钥已授权的逻辑模型名。");
    gatewayModels ??= await lookupRows("models");
    const apiKey = activeApiKeys.find((key) => String(key.id) === apiKeyId) || {};
    const grantedIds = new Set(normalizeIdList(apiKey.model_ids));
    const grantedNames = [...new Set(gatewayModels
      .filter((model) => grantedIds.has(Number(model.id)) && modelIsGatewaySupported(model) && (!model.status || model.status === "active") && ["text_generation", "vision_understanding"].includes(model.capability))
      .map((model) => model.name)
      .filter(Boolean))];
    if (gatewayModels.length === 0) {
      setModelOptions(modelOptions);
      setText(modeHint, `${modeHint.textContent}（无法读取模型目录，下列模型可能未授权给该密钥。）`);
    } else {
      setModelOptions(grantedNames.map((name) => [name, name]));
      if (grantedNames.length === 0) {
        setText(modeHint, "所选 API 密钥没有已授权的文本模型，请先在 API 密钥中授权模型。");
      }
    }
    send.disabled = modelSelect.options.length === 0;
  };
  const showTrialMeta = (requestId, apiKeyId) => {
    if (!apiKeyId || !requestId) {
      trialMeta.hidden = true;
      return;
    }
    trialMeta.replaceChildren(
      el("span", { text: `请求 ID：${requestId}` }),
      button("诊断此请求", () => showRequestDiagnostics(requestId), "secondary")
    );
    trialMeta.hidden = false;
  };

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
    const apiKeyId = Number(apiKeySelect.value) || 0;
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
    trialMeta.hidden = true;
    try {
      const streamed = await streamApi("/admin-api/chat-session/stream", {
        method: "POST",
        body: JSON.stringify({
          model,
          messages,
          ...(apiKeyId > 0 ? { api_key_id: apiKeyId } : {})
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
      setText(output, apiKeyId > 0 ? `完成：${model}（API Key 试用，已按所选密钥计费）` : `完成：${model}`);
      showTrialMeta(streamed?.requestId, apiKeyId);
      renderTranscript();
    } catch (error) {
      if (!assistant.content) {
        assistant.content = adminErrorMessage(error, "会话请求失败");
      }
      output.className = "notice error";
      setText(output, adminErrorMessage(error, "会话请求失败"));
      showTrialMeta(error.requestId, apiKeyId);
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
        apiKeyField,
        el("div", { className: "toolbar-actions" }, [send, reset])
      ])
    ]),
    modeHint,
    output,
    trialMeta
  );
  apiKeySelect.addEventListener("change", () => {
    void updateChatMode();
  });
  await updateChatMode();
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
  if (resourceKey === "usage-records" || resourceKey === "logs") {
    toolbar.append(renderRequestDiagnosticsLookup());
  }
  content.append(toolbar, tablePanel);
  await refreshRows();
  if (openCreate && config.form) {
    await showCreateModal(resourceKey, config, refreshRows);
  }
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
        button("确认", async () => updateAlertStatus(row, "acknowledged"), "secondary"),
        button("关闭", async () => updateAlertStatus(row, "resolved"), "secondary")
      ])
    ]));
  }
  content.append(el("section", { className: "panel" }, [el("h2", { text: title }), rows.length ? list : statusMessage(`暂无${title}`) ]));
}

async function updateAlertStatus(row, status) {
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
  // Models: fetch all (support=all) so labels of existing references resolve;
  // pickers filter with modelIsGatewaySupported().
  const query = resourceKey === "models" ? { limit: 500, support: "all" } : { limit: 500 };
  const result = await api(lookupResourcePaths[resourceKey] || `/admin-api/${resourceKey}`, { query }).catch(() => ({ data: [] }));
  return Array.isArray(result.data) ? result.data : (Array.isArray(result.data?.items) ? result.data.items : []);
}

// Rows from /admin-api/models carry gateway_supported (ModelSupportPolicy).
// Older payloads without the flag count as supported.
function modelIsGatewaySupported(model) {
  return model?.gateway_supported !== false;
}

// GET /admin-api/providers/{id}/models -> { models, hidden_count, hidden_reasons }
// (an older server returned a bare array).
function providerCatalogModels(data) {
  if (Array.isArray(data)) {
    return { models: data, hiddenNotice: null };
  }
  const models = Array.isArray(data?.models) ? data.models : [];
  const hiddenCount = Number(data?.hidden_count || 0);
  if (hiddenCount <= 0) {
    return { models, hiddenNotice: null };
  }
  const reasons = Object.values(data?.hidden_reasons || {})
    .map((reason) => `${reason.message}（${reason.count} 个${Array.isArray(reason.examples) && reason.examples.length ? `，如 ${reason.examples.slice(0, 3).join("、")}` : ""}）`);
  const notice = el("p", { className: "notice provider-model-import-hidden", text: `已隐藏 ${hiddenCount} 个 TokenHub 无法调用的模型。` });
  notice.title = reasons.join("\n");
  return { models, hiddenNotice: notice };
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
  // Pickers offer only models the gateway can serve (ModelSupportPolicy);
  // labels of existing references still resolve through modelsById.
  return (context.models || [])
    .filter(modelIsGatewaySupported)
    .map((model) => [String(model.id), modelQuotaModelLabel(model.id, context)]);
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
    if ((resourceKey === "usage-records" || resourceKey === "logs") && row.request_id) {
      ops.append(button("诊断", () => showRequestDiagnostics(row.request_id), "secondary request-diagnostics-button"));
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
    if (resourceKey === "providers" && row.id && adminViewerCanSyncCatalog()) {
      ops.append(button("目录同步", () => showProviderCatalogSync(row, onRefresh), "secondary catalog-sync-button"));
    }
    if (config.form && row.id) {
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
  if (resourceKey === "models" && column === "gateway_support") {
    return row.gateway_supported === false ? `不支持：${row.unsupported_message || row.unsupported_reason || ""}` : "支持";
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
    const { models, hiddenNotice } = providerCatalogModels(result.data);
    clear(list);
    if (hiddenNotice) {
      list.append(hiddenNotice);
    }
    if (models.length === 0) {
      list.append(statusMessage(hiddenNotice ? "该厂商没有 TokenHub 可以调用的模型" : "未拉取到模型"));
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
  const scopeEditor = resourceKey === "api-keys" ? createApiKeyManagementScopeEditor([]) : null;
  if (scopeEditor) {
    form.append(scopeEditor.node);
  }
  form.append(button(config.oneTimeSecret ? "创建并显示一次明文" : "保存", async () => {
    try {
      const payload = resourcePayload(resourceKey, readFields(form, formFields));
      if (scopeEditor && scopeEditor.selected().length > 0) {
        payload.management_scopes = scopeEditor.selected();
      }
      const result = await api(`/admin-api/${resourceKey}`, {
        method: "POST",
        body: JSON.stringify(payload)
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
  const capabilityEditor = resourceKey === "models" ? createModelCapabilityEditor(row) : null;
  if (capabilityEditor) {
    form.append(capabilityEditor.node);
  }
  const scopeEditor = resourceKey === "api-keys" ? createApiKeyManagementScopeEditor(row.management_scopes, { editing: true }) : null;
  if (scopeEditor) {
    form.append(scopeEditor.node);
  }
  form.append(button("保存", async () => {
    try {
      const values = readFields(form, formFields);
      const payload = resourcePayload(resourceKey, values);
      if (capabilityEditor) {
        Object.assign(payload, capabilityEditor.payload());
      }
      if (scopeEditor?.changed()) {
        payload.management_scopes = scopeEditor.selected();
      }
      const result = await api(`/admin-api/${resourceKey}/${row.id}`, {
        method: "PUT",
        body: JSON.stringify(payload)
      });
      capabilityEditor?.commit(result.data || {});
      scopeEditor?.commit(Array.isArray(result.data?.management_scopes) ? result.data.management_scopes : scopeEditor.selected());
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
      setErrorOutput(output, error.userMessage || adminErrorMessage(error, "保存失败，请检查输入内容"));
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
    const { models, hiddenNotice } = providerCatalogModels(result.data);
    if (models.length === 0) {
      body.replaceChildren(...[hiddenNotice, statusMessage(hiddenNotice ? "该厂商没有 TokenHub 可以调用的模型" : "未从该厂商拉取到模型", "error")].filter(Boolean));
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
      ...(hiddenNotice ? [hiddenNotice] : []),
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
      // The picked preset wins over the name-alias guess (e.g. Gemini native vs OpenAI-compatible).
      if (baseUrlInput && preset.baseUrl) {
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

function metric(name, value) {
  return el("article", { className: "metric" }, [
    el("span", { text: name }),
    el("strong", { text: value })
  ]);
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

// ---------------------------------------------------------------------------
// Operations tools: provider catalog sync, quota status/adjustment, request
// diagnostics, API key rotation and model capability contract editing.
// ---------------------------------------------------------------------------

let adminViewer = null;

export function setAdminViewer(user) {
  adminViewer = user || null;
}

function adminViewerIsSuperAdmin() {
  return adminViewer?.role === "super_admin";
}

// Server-side authority is route capability + data scope, not a role gate.
function adminViewerCan(capability) {
  return Array.isArray(adminViewer?.permissions) && adminViewer.permissions.includes(capability);
}

// Mirrors PermissionService::usesGlobalScope (catalog sync, operations).
function adminViewerHasGlobalScope() {
  return adminViewerIsSuperAdmin() || (adminViewer?.role === "admin" && adminViewer?.admin_scope === "global");
}

function adminViewerCanSyncCatalog() {
  return adminViewerHasGlobalScope() && adminViewerCan("api_catalog.manage");
}

function opsText(value, fallback = "-") {
  if (value === null || value === undefined || value === "") {
    return fallback;
  }
  return String(value);
}

function opsNumber(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return opsText(value);
  }
  return Number.isInteger(number) ? number.toLocaleString("zh-CN") : number.toLocaleString("zh-CN", { maximumFractionDigits: 6 });
}

function opsUtcTime(value) {
  if (!value) {
    return "-";
  }
  const raw = String(value);
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw) ? `${raw.replace(" ", "T")}Z` : raw;
  const parsed = new Date(iso);
  return Number.isNaN(parsed.getTime()) ? raw : parsed.toLocaleString("zh-CN", { hour12: false });
}

function opsDetailGrid(pairs) {
  return el("div", { className: "ops-detail-grid" }, pairs.map(([label, value]) => el("div", { className: "ops-detail-row" }, [
    el("span", { text: label }),
    el("strong", { text: opsText(value) })
  ])));
}

function opsSection(title, children, className = "") {
  return el("section", { className: `ops-section ${className}`.trim() }, [
    el("h3", { text: title }),
    ...[].concat(children)
  ]);
}

async function copyTextToClipboard(text, feedback, sourceNode = null) {
  try {
    await navigator.clipboard.writeText(text);
    setText(feedback, "已复制到剪贴板");
  } catch {
    if (sourceNode && window.getSelection) {
      const range = document.createRange();
      range.selectNodeContents(sourceNode);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }
    setText(feedback, "浏览器未允许自动复制，已选中密钥，请按 Ctrl+C 手动复制");
  }
  feedback.hidden = false;
}

// --- 1. Provider catalog sync ----------------------------------------------

const CATALOG_SYNC_UPSTREAM_LABELS = {
  input_modalities: "输入模态",
  output_modalities: "输出模态",
  features: "功能",
  context_length: "上下文长度",
  max_output_tokens: "最大输出",
  pricing: "上游价格"
};

function catalogSyncChangedFields(change) {
  const parts = [];
  const before = change.before || {};
  const after = change.after || {};
  if (JSON.stringify(before.context_length ?? null) !== JSON.stringify(after.context_length ?? null)) {
    parts.push(`上下文长度 ${opsText(before.context_length, "未知")} → ${opsText(after.context_length, "未知")}`);
  }
  const beforeUpstream = before.upstream && typeof before.upstream === "object" ? before.upstream : {};
  const afterUpstream = after.upstream && typeof after.upstream === "object" ? after.upstream : {};
  const keys = [...new Set([...Object.keys(beforeUpstream), ...Object.keys(afterUpstream)])]
    .filter((key) => JSON.stringify(beforeUpstream[key] ?? null) !== JSON.stringify(afterUpstream[key] ?? null));
  if (keys.length > 0) {
    parts.push(`上游能力变化：${keys.map((key) => CATALOG_SYNC_UPSTREAM_LABELS[key] || key).join("、")}`);
  }
  return parts.length > 0 ? parts.join("；") : "上游能力层有变化";
}

function catalogSyncList(title, items, describe, emptyText, tone = "") {
  const shown = items.slice(0, 200);
  return el("section", { className: `catalog-sync-group ${tone}`.trim() }, [
    el("header", {}, [el("strong", { text: title }), el("span", { text: `${items.length} 个` })]),
    items.length === 0
      ? el("p", { className: "muted", text: emptyText })
      : el("ul", { className: "catalog-sync-list" }, [
        ...shown.map((item) => {
          const [name, detail] = describe(item);
          return el("li", {}, [el("strong", { text: name }), el("small", { text: detail })]);
        }),
        items.length > shown.length ? el("li", { className: "muted", text: `另有 ${items.length - shown.length} 个未显示` }) : null
      ].filter(Boolean))
  ]);
}

function renderCatalogSyncResult(data, applied) {
  const added = Array.isArray(data.added) ? data.added : [];
  const changed = Array.isArray(data.changed) ? data.changed : [];
  const missing = Array.isArray(data.missing) ? data.missing : [];
  const unchanged = Number(data.unchanged) || 0;
  return [
    el("div", { className: "catalog-sync-summary" }, [
      ["新增", added.length],
      ["变更", changed.length],
      ["上游已下线", missing.length],
      ["无变化", unchanged]
    ].map(([label, value]) => el("article", { className: "catalog-sync-count" }, [el("span", { text: label }), el("strong", { text: String(value) })]))),
    statusMessage(applied
      ? "已应用：变更模型的上下文长度与上游能力层已更新，上游缺失的模型已标记缺失时间（不会自动停用）。"
      : "预览不会修改模型。应用后将更新“变更”模型的上下文长度与上游能力层，并为“上游已下线”模型标记缺失时间；“新增”模型不会自动导入，请使用“拉取模型”导入。"),
    ...(Number(data.hidden_unsupported) > 0 ? [statusMessage(`另有 ${Number(data.hidden_unsupported)} 个上游新模型 TokenHub 无法调用，未列出。`)] : []),
    catalogSyncList("新增（上游有，本地未配置）", added, (model) => [
      opsText(model.external_name || model.name),
      [valueLabel("capability", model.capability || "text_generation"), model.context_length ? `上下文 ${opsNumber(model.context_length)}` : ""].filter(Boolean).join(" / ")
    ], "无新增模型", "catalog-sync-group--added"),
    catalogSyncList("变更", changed, (change) => [
      `${opsText(change.external_name)}${change.model_id ? ` #${change.model_id}` : ""}`,
      catalogSyncChangedFields(change)
    ], "无变更模型", "catalog-sync-group--changed"),
    catalogSyncList("上游已下线（本地有，上游目录缺失）", missing, (model) => [
      `${opsText(model.external_name)}${model.model_id ? ` #${model.model_id}` : ""}`,
      `本地状态：${valueLabel("status", model.status || "") || "-"}`
    ], "无下线模型", "catalog-sync-group--missing")
  ];
}

async function showProviderCatalogSync(row, onSaved) {
  const body = el("div", { className: "catalog-sync" }, [statusMessage("正在对比上游模型目录...")]);
  showModal(`目录同步预览 - ${row.name || row.id}`, body);
  let preview;
  try {
    const result = await api(`/admin-api/providers/${row.id}/catalog-sync`, {
      method: "POST",
      body: JSON.stringify({ apply: false })
    });
    preview = result.data || {};
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "目录同步预览失败，请检查厂商基础地址和密钥"), "error"));
    return;
  }
  const output = el("p", { className: "notice", hidden: true });
  const changedCount = (preview.changed || []).length;
  const missingCount = (preview.missing || []).length;
  const applyButton = button("应用", async () => {
    if (!window.confirm(`确认应用目录同步？将更新 ${changedCount} 个模型，标记 ${missingCount} 个上游缺失模型。`)) {
      return;
    }
    applyButton.disabled = true;
    try {
      const result = await api(`/admin-api/providers/${row.id}/catalog-sync`, {
        method: "POST",
        body: JSON.stringify({ apply: true })
      });
      const heading = body.closest(".modal-dialog")?.querySelector(".modal-header h2");
      if (heading) {
        setText(heading, `目录同步已应用 - ${row.name || row.id}`);
      }
      body.replaceChildren(...renderCatalogSyncResult(result.data || {}, true));
      await onSaved();
    } catch (error) {
      applyButton.disabled = false;
      setErrorOutput(output, adminErrorMessage(error, "应用目录同步失败"));
    }
  }, "catalog-sync-apply");
  body.replaceChildren(
    ...renderCatalogSyncResult(preview, false),
    el("div", { className: "toolbar-actions" }, [applyButton]),
    output
  );
}

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

async function showApiKeyQuota(row) {
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

// --- 3. Request diagnostics -------------------------------------------------

const DIAGNOSTIC_CATEGORY_LABELS = {
  permission: "权限",
  configuration: "配置",
  quota: "额度",
  policy: "策略",
  client: "客户端",
  upstream: "上游",
  rag: "RAG",
  unknown: "未知"
};

const DIAGNOSTIC_CAUSES_ZH = {
  MODEL_FORBIDDEN: ["该 API 密钥没有此模型或通道的授权。", "在 API 密钥中授权该模型，或调用已授权的模型。"],
  MODEL_NOT_FOUND: ["未配置该名称的模型。", "检查模型名称，或从厂商目录导入该模型。"],
  MODEL_UNAVAILABLE: ["该模型的所有通道均已停用或下线。", "启用一个通道，或让密钥改用其他模型。"],
  MODEL_CAPABILITY_UNSUPPORTED: ["没有启用的通道支持该接口或请求的功能（工具、JSON Schema、推理、图片）。", "改用能力契约包含该功能的模型，或在验证后更新能力契约。"],
  CAPABILITY_UNAVAILABLE: ["该能力只有 native 通道，且没有执行后端。", "为该能力配置 OpenAI/Anthropic 兼容通道（如火山方舟）。"],
  QUOTA_EXCEEDED: ["绑定到密钥、用户或部门的额度策略在当前窗口已用尽（进行中请求的预占也计入）。", "等待窗口重置、提高限额，或执行可审计的额度调整。"],
  MODEL_QUOTA_EXCEEDED: ["该密钥的模型限额已用尽。", "提高该密钥的模型限额，或等待重置。"],
  CONCURRENCY_EXCEEDED: ["绑定策略的并发请求过多。", "降低并发，或提高并发限制。"],
  PRICE_UNKNOWN: ["存在成本限额，但模型未配置价格，且未知价格策略为拒绝。", "为模型添加价格，或放宽未知价格策略。"],
  DESENSITIZATION_BLOCKED: ["出站内容（包括注入的 RAG 上下文）命中了阻断型脱敏规则。", "按该请求 ID 查看脱敏事件。"],
  ROUTING_PARAMETER_FORBIDDEN: ["请求试图修改超出通道策略的聚合路由参数（provider/plugins/models）。", "移除该参数，或在厂商上配置客户端路由策略。"],
  ROUTING_AUTO_ROUTER_FORBIDDEN: ["自动路由会选择 TokenHub 授权范围外的模型。", "使用明确的模型，或在厂商上允许自动路由。"],
  TOOL_CALL_MISMATCH: ["工具结果引用了会话中从未发出的工具调用 ID。", "先发送带 tool_calls 的助手消息，再发送对应的工具结果。"],
  TOOL_CHOICE_UNKNOWN: ["tool_choice 指定了未声明的工具。", "在 tools 中声明该工具，或修改 tool_choice。"],
  OUTBOUND_BLOCKED: ["通道地址解析到私有、回环或元数据地址。", "私有推理服务需在厂商上允许私有网络，或配置出站私网白名单。"],
  PROVIDER_ENDPOINT_UNSUPPORTED: ["通道的接口映射标记该接口不受支持。", "将请求路由到支持该接口的通道。"],
  PROVIDER_TIMEOUT: ["上游在超时时间内未响应（或流式输出中断）。", "检查上游状态；长生成任务可提高超时毫秒数。"],
  PROVIDER_RATE_LIMITED: ["所有尝试的通道均返回 429 限流。", "遵循 Retry-After、增加通道或提高上游账号限额。"],
  PROVIDER_REJECTED: ["上游拒绝了请求（4xx）：参数、鉴权或账户状态问题。", "检查厂商密钥、账户余额和请求参数。"],
  PROVIDER_ERROR: ["每次尝试上游都返回 5xx。", "检查上游状态；熔断器会绕开故障通道。"],
  PROVIDER_STREAM_ERROR: ["流式输出开始后上游发送了错误事件。", "查看留存输出中的上游错误，通常是过载或内容策略。"],
  PROVIDER_STREAM_INCOMPLETE: ["流式输出未收到结束事件就中断了。", "按截断处理输出，检查上游与网络稳定性。"],
  CLIENT_CANCELLED: ["客户端断开连接，已按断开前的用量计费。", "若非预期，请检查客户端超时设置。"],
  RAG_REQUIRED_NO_CONTEXT: ["要求使用 RAG，但没有数据集或上下文达到阈值。", "为密钥绑定数据集，或降低检索阈值。"],
  RAG_RETRIEVAL_FAILED: ["必需的检索失败（向量库或向量化服务）。", "检查 Qdrant 与向量化通道的健康状态。"],
  API_KEY_INVALID: ["密钥不存在、已停用或已删除，或其员工/部门已停用。", "签发或重新启用密钥。"],
  API_KEY_EXPIRED: ["密钥已超过过期时间（包括轮换宽限期结束后）。", "让客户端切换到轮换后的新密钥。"]
};

function renderRequestDiagnostics(data) {
  if (!data.found) {
    return [
      statusMessage("尚未找到该请求的用量记录", "error"),
      el("p", { className: "muted", text: "请求可能仍在排队写入（可在“运行状态”查看队列积压），或请求 ID 有误。" }),
      data.hint ? el("p", { className: "ops-original-text", text: data.hint }) : null
    ].filter(Boolean);
  }
  const children = [
    opsDetailGrid([
      ["状态", valueLabel("status", data.status || "") || data.status],
      ["HTTP 状态", data.http_status],
      ["错误码", data.error_code || "无"],
      ["耗时（毫秒）", data.latency_ms],
      ["创建时间", data.created_at],
      ["请求 ID", data.request_id]
    ])
  ];
  const explanation = data.explanation;
  if (data.error_code && explanation) {
    const translated = DIAGNOSTIC_CAUSES_ZH[data.error_code];
    children.push(el("section", { className: `request-diagnostics-explanation request-diagnostics-explanation--${explanation.category || "unknown"}` }, [
      el("header", {}, [
        el("strong", { text: "原因与处理建议" }),
        el("span", { className: "status-badge", text: DIAGNOSTIC_CATEGORY_LABELS[explanation.category] || explanation.category || "未知" })
      ]),
      el("p", {}, [el("b", { text: "原因：" }), document.createTextNode(translated?.[0] || explanation.cause || "-")]),
      el("p", {}, [el("b", { text: "处理建议：" }), document.createTextNode(translated?.[1] || explanation.action || "-")]),
      translated ? el("p", { className: "ops-original-text", text: `${explanation.cause || ""} ${explanation.action || ""}`.trim() }) : null
    ].filter(Boolean)));
  } else if (!data.error_code) {
    children.push(statusMessage("该请求没有记录错误码。"));
  }
  const channel = data.channel;
  if (channel) {
    children.push(opsSection("通道", opsDetailGrid([
      ["模型", `${opsText(channel.name)} #${opsText(channel.id)}`],
      ["上游模型名", channel.external_name],
      ["模型状态", valueLabel("status", channel.status || "") || channel.status],
      ["厂商", `${opsText(channel.provider_name)} #${opsText(channel.provider_id)}`],
      ["厂商状态", valueLabel("status", channel.provider_status || "") || channel.provider_status]
    ])));
  }
  if (Array.isArray(data.attempts) && data.attempts.length > 0) {
    children.push(opsSection("通道尝试", renderDataDetails(data.attempts)));
  }
  const usage = data.usage || {};
  children.push(opsSection("用量与计费", opsDetailGrid([
    ["用量来源", usage.source],
    ["输入 Token", usage.input_tokens],
    ["输出 Token", usage.output_tokens],
    ["缓存读取 Token", usage.cache_read_tokens],
    ["推理 Token", usage.reasoning_tokens],
    ["成本", usage.cost_amount === null || usage.cost_amount === undefined ? null : `${usage.cost_amount} ${usage.cost_currency || ""}`.trim()],
    ["上游成本", usage.upstream_cost],
    ["对账时间", usage.reconciled_at]
  ])));
  const stream = data.stream || {};
  const upstream = data.upstream || {};
  children.push(opsSection("流式与上游", opsDetailGrid([
    ["流式结果", stream.outcome],
    ["首字节耗时（毫秒）", stream.first_byte_ms],
    ["上游请求 ID", upstream.request_id],
    ["上游模型", upstream.model],
    ["上游厂商", upstream.provider],
    ["聚合时间", data.persistence?.aggregated_at],
    ["事件发送时间", data.persistence?.events_emitted_at]
  ])));
  return children;
}

async function showRequestDiagnostics(requestId) {
  const id = String(requestId || "").trim();
  if (id === "") {
    showModal("请求诊断", statusMessage("请输入请求 ID", "error"));
    return;
  }
  const body = el("div", { className: "request-diagnostics" }, [statusMessage("正在诊断请求...")]);
  showModal(`请求诊断 · ${id}`, body);
  try {
    const result = await api(`/admin-api/diagnostics/requests/${encodeURIComponent(id)}`);
    body.replaceChildren(...renderRequestDiagnostics(result.data || {}));
  } catch (error) {
    body.replaceChildren(statusMessage(adminErrorMessage(error, "请求诊断失败"), "error"));
  }
}

function renderRequestDiagnosticsLookup() {
  const input = el("input", {
    type: "search",
    name: "diagnose_request_id",
    className: "request-diagnostics-input",
    placeholder: "按请求 ID 诊断",
    ariaLabel: "按请求 ID 诊断"
  });
  const run = () => showRequestDiagnostics(input.value);
  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      event.stopPropagation();
      void run();
    }
  });
  return el("div", { className: "request-diagnostics-lookup" }, [input, button("诊断", run, "secondary")]);
}

// --- 4. API key rotation ----------------------------------------------------

function showApiKeyRotate(row, onRefresh) {
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

// --- 5. Model capability contract editor -----------------------------------

const MODEL_CAPABILITY_FEATURES = [
  ["tools", "工具调用"],
  ["parallel_tool_calls", "并行工具调用"],
  ["json_object", "JSON 对象输出"],
  ["json_schema", "JSON Schema 结构化输出"],
  ["reasoning", "推理"],
  ["streaming", "流式输出"]
];

const MODEL_CAPABILITY_MODALITIES = {
  input_modalities: { label: "输入模态", options: [["text", "文本"], ["image", "图片"], ["audio", "音频"], ["file", "文件"]] },
  output_modalities: { label: "输出模态", options: [["text", "文本"], ["image", "图片"], ["audio", "音频"], ["embedding", "向量"]] }
};

const MODEL_CAPABILITY_LIMITS = [
  ["context_length", "上下文长度"],
  ["max_output_tokens", "最大输出 Token"]
];

const MODEL_CAPABILITY_TAGS = ["text_generation", "vision_understanding", "embedding", "asr", "tts", "image_generation", "video_generation", "rerank", "moderation"];

function parseJsonValue(value) {
  if (typeof value === "string") {
    const trimmed = value.trim();
    if (trimmed === "") {
      return null;
    }
    try {
      return JSON.parse(trimmed);
    } catch {
      return null;
    }
  }
  return value ?? null;
}

function plainObject(value) {
  return value && typeof value === "object" && !Array.isArray(value) ? value : {};
}

function capabilityFeatureText(value) {
  if (value === true) return "支持";
  if (value === false) return "不支持";
  return "未声明";
}

function capabilityListText(value, options) {
  if (!Array.isArray(value)) {
    return "未声明";
  }
  const labels = new Map(options);
  return value.length === 0 ? "无" : value.map((item) => labels.get(item) || item).join("、");
}

function stableJson(value) {
  if (Array.isArray(value)) {
    return `[${value.map(stableJson).join(",")}]`;
  }
  if (value && typeof value === "object") {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableJson(value[key])}`).join(",")}}`;
  }
  return JSON.stringify(value ?? null);
}

function createModelCapabilityEditor(row) {
  const hasLayers = Object.prototype.hasOwnProperty.call(row, "capabilities");
  const hasTags = Object.prototype.hasOwnProperty.call(row, "capability_tags");
  if (!hasLayers && !hasTags) {
    return null;
  }
  const baseline = { layers: plainObject(parseJsonValue(row.capabilities)), tags: parseJsonValue(row.capability_tags), updatedAt: row.updated_at || "" };
  const node = el("fieldset", { className: "model-capability-editor" }, [el("legend", { text: "能力契约" })]);
  const controls = { features: {}, modalities: {}, limits: {} };
  let tagInputs = [];
  let legacyWarning = null;

  const currentLayers = () => baseline.layers;
  const lowerValue = (key) => {
    const verified = plainObject(currentLayers().verified);
    const upstream = plainObject(currentLayers().upstream);
    return verified[key] !== undefined ? verified[key] : upstream[key];
  };
  const lowerFeature = (feature) => {
    const verified = plainObject(plainObject(currentLayers().verified).features);
    const upstream = plainObject(plainObject(currentLayers().upstream).features);
    if (feature in verified) return verified[feature];
    if (feature in upstream) return upstream[feature];
    return undefined;
  };

  if (hasLayers) {
    const layers = currentLayers();
    const upstream = plainObject(layers.upstream);
    const verified = plainObject(layers.verified);
    const admin = plainObject(layers.admin);
    const upstreamFeatures = plainObject(upstream.features);
    const verifiedFeatures = plainObject(verified.features);
    const adminFeatures = plainObject(admin.features);
    node.append(el("p", { className: "muted", text: "生效值 = 管理员覆盖 > 已验证 > 上游目录。上游与已验证层由目录同步和验收测试维护，此处只读；这里只修改管理员覆盖层。" }));
    legacyWarning = el("p", { className: "notice error", hidden: true, text: "该模型目前没有能力契约（旧版模型，仅按“能力”字段路由）。保存管理员覆盖后将启用能力契约：未声明为“支持”的功能（包括流式输出）将被视为不支持。" });
    node.append(legacyWarning);

    const featureTable = el("table", { className: "resource-table model-capability-table" });
    featureTable.append(el("thead", {}, [el("tr", {}, ["功能", "上游", "已验证", "管理员覆盖", "生效"].map((label) => el("th", { text: label })))]));
    const featureBody = el("tbody");
    for (const [feature, label] of MODEL_CAPABILITY_FEATURES) {
      const select = el("select", { name: `capability_feature_${feature}`, className: "model-capability-override", dataset: { feature } }, [
        el("option", { value: "", text: "继承" }),
        el("option", { value: "true", text: "支持" }),
        el("option", { value: "false", text: "不支持" })
      ]);
      select.value = feature in adminFeatures ? String(Boolean(adminFeatures[feature])) : "";
      const effective = el("td", { className: "model-capability-effective" });
      controls.features[feature] = { select, effective };
      featureBody.append(el("tr", {}, [
        el("td", { text: label }),
        el("td", { text: capabilityFeatureText(upstreamFeatures[feature]) }),
        el("td", { text: capabilityFeatureText(verifiedFeatures[feature]) }),
        el("td", {}, [select]),
        effective
      ]));
    }
    featureTable.append(featureBody);
    node.append(el("div", { className: "model-capability-table-wrap" }, [featureTable]));

    for (const [key, config] of Object.entries(MODEL_CAPABILITY_MODALITIES)) {
      const override = el("input", { type: "checkbox", name: `capability_override_${key}`, checked: Array.isArray(admin[key]) });
      const initial = Array.isArray(admin[key]) ? admin[key] : (Array.isArray(lowerValue(key)) ? lowerValue(key) : ["text"]);
      const checkboxes = config.options.map(([value, label]) => {
        const checkbox = el("input", { type: "checkbox", name: `capability_${key}`, value, checked: initial.includes(value) });
        return { checkbox, wrapper: el("label", { className: "multiselect-item" }, [checkbox, el("span", { text: label })]) };
      });
      const effective = el("small", { className: "model-capability-effective" });
      controls.modalities[key] = { override, checkboxes, effective };
      node.append(el("div", { className: "model-capability-modality" }, [
        el("div", { className: "model-capability-modality-head" }, [
          el("strong", { text: config.label }),
          el("span", { className: "muted", text: `上游：${capabilityListText(upstream[key], config.options)}；已验证：${capabilityListText(verified[key], config.options)}` })
        ]),
        el("label", { className: "model-capability-override-toggle" }, [override, el("span", { text: "管理员覆盖（整组替换）" })]),
        el("div", { className: "model-capability-choices" }, checkboxes.map((item) => item.wrapper)),
        effective
      ]));
    }

    const limitRow = el("div", { className: "model-capability-limits" });
    for (const [key, label] of MODEL_CAPABILITY_LIMITS) {
      const lower = lowerValue(key);
      const input = el("input", {
        type: "number",
        name: `capability_${key}`,
        min: "1",
        step: "1",
        placeholder: lower ? `继承：${lower}` : "继承（未声明）",
        value: admin[key] !== undefined && admin[key] !== null ? String(admin[key]) : ""
      });
      controls.limits[key] = input;
      limitRow.append(el("label", { className: "field" }, [el("span", { text: `${label}覆盖（留空继承）` }), input]));
    }
    node.append(limitRow);
  }

  if (hasTags) {
    const currentTags = new Set(Array.isArray(baseline.tags) ? baseline.tags.map(String) : []);
    tagInputs = MODEL_CAPABILITY_TAGS.map((tag) => el("input", { type: "checkbox", name: "capability_tags", value: tag, checked: currentTags.has(tag) }));
    node.append(el("div", { className: "model-capability-tags" }, [
      el("strong", { text: "附加能力标签（路由匹配）" }),
      el("span", { className: "muted", text: "模型也会响应这些能力的请求。注意：应用厂商目录同步时会按上游重置该标签。" }),
      el("div", { className: "model-capability-choices" }, tagInputs.map((input) => el("label", { className: "multiselect-item" }, [input, el("span", { text: valueLabel("capability", input.value) })])))
    ]));
  }

  const nextAdminLayer = () => {
    const admin = { ...plainObject(currentLayers().admin) };
    const features = { ...plainObject(admin.features) };
    for (const [feature, control] of Object.entries(controls.features)) {
      if (control.select.value === "") {
        delete features[feature];
      } else {
        features[feature] = control.select.value === "true";
      }
    }
    if (Object.keys(features).length > 0) {
      admin.features = features;
    } else {
      delete admin.features;
    }
    for (const [key, control] of Object.entries(controls.modalities)) {
      if (control.override.checked) {
        admin[key] = control.checkboxes.filter((item) => item.checkbox.checked).map((item) => item.checkbox.value);
      } else {
        delete admin[key];
      }
    }
    for (const [key, input] of Object.entries(controls.limits)) {
      const raw = input.value.trim();
      if (raw === "") {
        delete admin[key];
        continue;
      }
      const number = Number(raw);
      if (!Number.isInteger(number) || number <= 0) {
        const error = new Error("invalid capability limit");
        error.userMessage = `${MODEL_CAPABILITY_LIMITS.find(([name]) => name === key)?.[1] || key}覆盖需为正整数`;
        throw error;
      }
      admin[key] = number;
    }
    return admin;
  };

  const refreshEffective = () => {
    if (!hasLayers) {
      return;
    }
    let admin = {};
    try {
      admin = nextAdminLayer();
    } catch {
      admin = plainObject(currentLayers().admin);
    }
    const adminFeatures = plainObject(admin.features);
    for (const [feature, control] of Object.entries(controls.features)) {
      const value = feature in adminFeatures ? adminFeatures[feature] : lowerFeature(feature);
      setText(control.effective, capabilityFeatureText(value));
      control.effective.className = `model-capability-effective ${value === true ? "is-supported" : value === false ? "is-unsupported" : "is-unknown"}`;
    }
    for (const [key, control] of Object.entries(controls.modalities)) {
      for (const item of control.checkboxes) {
        item.checkbox.disabled = !control.override.checked;
      }
      const value = Array.isArray(admin[key]) ? admin[key] : lowerValue(key);
      setText(control.effective, `生效：${capabilityListText(value, MODEL_CAPABILITY_MODALITIES[key].options)}`);
    }
    const isLegacy = Object.keys(currentLayers()).filter((layer) => Object.keys(plainObject(currentLayers()[layer])).length > 0).length === 0;
    if (legacyWarning) {
      legacyWarning.hidden = !(isLegacy && Object.keys(admin).length > 0);
    }
  };
  node.addEventListener("change", refreshEffective);
  node.addEventListener("input", refreshEffective);
  refreshEffective();

  return {
    node,
    payload() {
      const payload = {};
      if (hasLayers) {
        const before = plainObject(currentLayers().admin);
        const admin = nextAdminLayer();
        if (stableJson(admin) !== stableJson(before)) {
          const layers = { ...currentLayers() };
          if (Object.keys(admin).length > 0) {
            layers.admin = admin;
          } else {
            delete layers.admin;
          }
          payload.capabilities = Object.keys(layers).length > 0 ? layers : null;
        }
      }
      if (hasTags) {
        const selected = tagInputs.filter((input) => input.checked).map((input) => input.value);
        const before = Array.isArray(baseline.tags) ? baseline.tags.map(String) : [];
        if (stableJson([...selected].sort()) !== stableJson([...before].sort())) {
          payload.capability_tags = selected;
        }
      }
      if (Object.keys(payload).length > 0 && baseline.updatedAt) {
        payload.expected_updated_at = baseline.updatedAt;
      }
      return payload;
    },
    commit(savedRow = {}) {
      if (Object.prototype.hasOwnProperty.call(savedRow, "capabilities")) {
        baseline.layers = plainObject(parseJsonValue(savedRow.capabilities));
      }
      if (Object.prototype.hasOwnProperty.call(savedRow, "capability_tags")) {
        baseline.tags = parseJsonValue(savedRow.capability_tags);
      }
      if (savedRow.updated_at) {
        baseline.updatedAt = savedRow.updated_at;
      }
      refreshEffective();
    }
  };
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

function apiKeyManagementBadge(row) {
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

function createApiKeyManagementScopeEditor(initialScopes, options = {}) {
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
