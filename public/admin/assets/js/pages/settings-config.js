export const SETTINGS_SECTION_PAGES = {
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


export const SETTINGS_SECTION_CATEGORIES = [
  { key: "base-entry", label: "基础入口", sections: ["settings-brand-identity", "settings-tls-certificates"] },
  { key: "security", label: "安全防护", sections: ["settings-entry-security"] },
  { key: "governance", label: "治理告警", sections: ["settings-retention-export", "settings-alert-thresholds"] },
  { key: "rag", label: "RAG 设置", sections: ["settings-rag-upload-review", "settings-rag-package-upload", "settings-rag-ai-image-processing", "settings-rag-vectorization-monitoring"] }
];
