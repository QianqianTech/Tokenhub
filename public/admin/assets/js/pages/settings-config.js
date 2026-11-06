import { localize } from "../i18n.js";
export const SETTINGS_SECTION_PAGES = {
  "settings-smtp": {
    title: localize("SMTP 邮件"),
    section: "smtp",
    fields: [
      { name: "enabled", label: localize("邮件服务"), type: "select", options: [["false", localize("停用")], ["true", localize("启用")]] },
      { name: "host", label: localize("SMTP 服务器"), optional: true, placeholder: "smtp.example.com" },
      { name: "port", label: localize("端口"), type: "number", min: 1, max: 65535 },
      { name: "encryption", label: localize("连接加密"), type: "select", options: [["starttls", localize("STARTTLS（通常为 587）")], ["ssl", localize("SSL/TLS（通常为 465）")], ["none", localize("无加密（内网中继）")]] },
      { name: "auth_enabled", label: localize("SMTP 认证"), type: "select", options: [["true", localize("需要账号和密码")], ["false", localize("无需认证")]] },
      { name: "username", label: localize("SMTP 账号"), optional: true, autocomplete: "off" },
      { name: "password", label: localize("SMTP 密码或授权码"), type: "password", optional: true, autocomplete: "new-password" },
      { name: "clear_password", label: localize("清除已保存的 SMTP 密码"), type: "checkbox", optional: true },
      { name: "from_address", label: localize("发件邮箱"), type: "email", optional: true },
      { name: "from_name", label: localize("发件人名称"), optional: true },
      { name: "public_url", label: localize("平台访问地址（重置邮件中的链接）"), type: "url", optional: true, placeholder: "https://tokenhub.example.com" },
      { name: "timeout_seconds", label: localize("连接超时（秒）"), type: "number", min: 1, max: 30 },
      { name: "reset_ttl_minutes", label: localize("重置链接有效期（分钟）"), type: "number", min: 5, max: 60 }
    ]
  },
  "settings-brand-identity": {
    title: localize("标题与标识"),
    section: "brand_identity",
    fields: [
      { name: "product_name", label: localize("产品名称") },
      { name: "admin_title", label: localize("管理后台标题") },
      { name: "screen_title", label: localize("运营大屏标题") },
      { name: "logo_url", label: localize("Logo 图标地址（同源路径或 data:image）"), optional: true },
      { name: "favicon_url", label: localize("浏览器图标地址（同源路径或 data:image）"), optional: true }
    ]
  },
  "settings-tls-certificates": {
    title: localize("证书与入口"),
    section: "tls_certificates",
    fields: [
      { name: "certificate_mode", label: localize("证书类型"), type: "select", options: [["domain", localize("域名证书")], ["internal_ip", localize("内网 IP 证书")], ["manual", localize("手动证书")]] },
      { name: "domain_names", label: localize("域名证书域名（每行一个）"), type: "textarea", optional: true, rows: 3 },
      { name: "ip_addresses", label: localize("IP 证书地址（内网使用，每行一个）"), type: "textarea", optional: true, rows: 3 },
      { name: "auto_renewal", label: localize("自动续期"), type: "select", options: [["true", localize("启用")], ["false", localize("停用")]] },
      { name: "renewal_provider", label: localize("续期/签发来源"), type: "select", options: [["caddy_acme", localize("Caddy 公共 ACME（域名）")], ["caddy_internal", localize("Caddy 内部 CA（内网 IP）")], ["manual_file", localize("手动证书文件")]] },
      { name: "certificate_paths", label: localize("真实证书扫描路径（每行一个）"), type: "textarea", optional: true, rows: 3 },
      { name: "threshold_days", label: localize("证书到期告警天数"), type: "number" }
    ]
  },
  "settings-entry-security": {
    title: localize("访问安全"),
    section: "entry_security",
    fields: [
      { name: "admin_allowed_cidrs", label: localize("管理端允许网段，每行一个"), type: "textarea", optional: true, rows: 3 },
      { name: "api_allowed_cidrs", label: localize("接口入口允许网段，每行一个"), type: "textarea", optional: true, rows: 3 },
      { name: "api_key_cidr_enforced", label: localize("API 密钥 IP 白名单校验"), type: "select", options: [["true", localize("启用")], ["false", localize("停用")]] },
      { name: "masked_header_fields", label: localize("脱敏请求头字段，每行一个"), type: "textarea", optional: true, rows: 3 },
      { name: "masked_query_fields", label: localize("脱敏查询参数字段，每行一个"), type: "textarea", optional: true, rows: 3 }
    ]
  },
  "settings-retention-export": {
    title: localize("留存与导出"),
    section: "retention_export",
    fields: [
      { name: "export_download_ttl_seconds", label: localize("导出下载链接有效秒数"), type: "number" },
      { name: "export_file_retention_days", label: localize("导出文件保留天数"), type: "number" },
      { name: "backup_retention_days", label: localize("备份文件保留天数"), type: "number" },
      { name: "cleanup_batch_size", label: localize("清理批次大小"), type: "number" }
    ]
  },
  "settings-alert-thresholds": {
    title: localize("告警设置"),
    section: "alert_thresholds",
    fields: [
      { name: "provider_error_rate", label: localize("厂商错误率阈值") },
      { name: "provider_error_window_minutes", label: localize("厂商错误统计窗口分钟"), type: "number" },
      { name: "provider_min_requests", label: localize("厂商错误最小请求数"), type: "number" },
      { name: "queue_backlog_count", label: localize("队列积压告警阈值"), type: "number" },
      { name: "certificate_threshold_days", label: localize("证书到期告警天数"), type: "number" }
    ]
  },
  "settings-rag-upload-review": {
    title: localize("RAG 上传审核"),
    section: "rag_upload_review",
    fields: [
      { name: "enabled", label: localize("启用上传审核"), type: "select", options: [["false", localize("停用")], ["true", localize("启用")]] },
      { name: "reviewer_roles", label: localize("审核角色，每行一个"), type: "textarea", optional: true, rows: 3 },
      { name: "allow_reviewer_own_upload", label: localize("允许审核自己的上传"), type: "select", options: [["false", localize("否")], ["true", localize("是")]] },
      { name: "reject_blocks_vectorization", label: localize("驳回后阻断向量化"), type: "select", options: [["true", localize("是")], ["false", localize("否")]] },
      { name: "instructions", label: localize("审核说明"), type: "textarea", optional: true, rows: 3 }
    ]
  },
  "settings-rag-package-upload": {
    title: localize("RAG 图片包上传"),
    section: "rag_package_upload",
    fields: [
      { name: "max_package_mb", label: localize("最大 ZIP 包大小 MB"), type: "number" },
      { name: "allowed_image_extensions", label: localize("允许图片扩展名，每行一个"), type: "textarea", optional: true, rows: 3 }
    ]
  },
  "settings-rag-ai-image-processing": {
    title: localize("RAG 图片 AI 处理"),
    section: "rag_ai_image_processing",
    fields: [
      { name: "enabled", label: localize("启用 AI 图片标注"), type: "select", options: [["false", localize("停用")], ["true", localize("启用")]] },
      { name: "vision_model_id", label: localize("视觉模型 ID"), type: "number" },
      { name: "prompt_profile", label: localize("提示词配置"), type: "select", options: [["default", localize("默认")], ["ocr_first", localize("OCR 优先")], ["catalog", localize("目录")], ["technical", localize("技术文档")]] },
      { name: "max_retries", label: localize("最大重试次数"), type: "number" },
      { name: "timeout_ms", label: localize("超时时间 ms"), type: "number" }
    ]
  },
  "settings-rag-vectorization-monitoring": {
    title: localize("RAG 向量化监控"),
    section: "rag_vectorization_monitoring",
    fields: [
      { name: "success_rate_threshold", label: localize("成功率阈值"), type: "number" },
      { name: "window_hours", label: localize("指标窗口小时"), type: "number" },
      { name: "consecutive_failure_threshold", label: localize("连续失败阈值"), type: "number" },
      { name: "qdrant_health_check_enabled", label: localize("检查 Qdrant 健康状态"), type: "select", options: [["true", localize("启用")], ["false", localize("停用")]] }
    ]
  }
};


export const SETTINGS_SECTION_CATEGORIES = [
  { key: "base-entry", label: localize("基础入口"), sections: ["settings-brand-identity", "settings-tls-certificates", "settings-smtp"] },
  { key: "security", label: localize("安全防护"), sections: ["settings-entry-security"] },
  { key: "governance", label: localize("治理告警"), sections: ["settings-retention-export", "settings-alert-thresholds"] },
  { key: "rag", label: localize("RAG 设置"), sections: ["settings-rag-upload-review", "settings-rag-package-upload", "settings-rag-ai-image-processing", "settings-rag-vectorization-monitoring"] }
];
